<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Sharing\MembersAddress;
use AgentBrain\Modules\Sharing\ShareRoute;
use AgentBrain\Modules\Sharing\ViewerKeys;
use AgentBrain\Modules\Sharing\ViewerMode;
use AgentBrain\Modules\Sharing\ViewerToken;
use WP_Error;

/**
 * The store endpoints of a validated share link (ART-23, #163; rules #384):
 *
 *   GET    /share/{id}/{token}/store                                -> the collections this page may use
 *   GET    /share/{id}/{token}/store/{collection}                   -> list documents
 *   POST   /share/{id}/{token}/store/{collection}                   -> create or replace {key?, value, expected_version?}
 *   GET    /share/{id}/{token}/store/{collection}/_rules            -> the collection's rules
 *   POST   /share/{id}/{token}/store/{collection}/_query            -> query {where, limit, offset}
 *   GET    /share/{id}/{token}/store/{collection}/{key}             -> one document
 *   PUT    /share/{id}/{token}/store/{collection}/{key}             -> update (merge {patch, expected_version?})
 *   DELETE /share/{id}/{token}/store/{collection}/{key}             -> delete ({expected_version?})
 *   POST   /share/{id}/{token}/store/{collection}/{key}/_increment  -> increment {field, by?}
 *   POST   /share/{id}/{token}/store/{collection}/{key}/_append     -> append {field, item}
 *   OPTIONS (any of the above)                                      -> CORS preflight (#375)
 *
 * Reached only after ShareRoute validated the share link (token, IP binding,
 * expiry, revocation, approval) and only while StoreMode is active for the
 * artifact; everything else is the uniform 404. Readable from the page's
 * opaque origin (CORS *, no cookies, no-store), with its own coarse rate
 * limit (StoreRate) so store traffic never consumes the page-render budget.
 *
 * #384: every request is checked against its collection's rules
 * (StoreRules), default deny: the op, the writer (a viewer token for
 * member-only collections), the owner of the document, the visibility, the
 * key, version checks, the value schema and the per-key, per-identity and
 * per-IP limits. The identity is "member:{type}:{id}" with a verified viewer
 * token, else "link:{id}". With owner "self" a page's keys are its own: the
 * stored key carries a short identity tag the page never sees. Refusals use
 * op_not_allowed, not_owner, schema, rate_limited (with retry_after),
 * version_required, key_not_allowed, exists and one_per_identity.
 *
 * A signed ART-24 viewer token may ride along (?viewer_token= or a
 * viewer_token body field); it is verified against ViewerKeys and matched
 * to this link and artifact. Presented but invalid: the uniform 404.
 *
 * #469: with seats on, a seat secret in the X-Tomatio-Seat header makes the
 * identity "seat:{NAME}" (a member's viewer token still wins). A secret that
 * does not verify (a released seat) is no seat. The secret is only ever
 * hashed: never logged, audited or echoed.
 */
final class StoreRoute {

	public const PATH = 'store';

	private const ACTIONS = [ '_query', '_rules', '_increment', '_append' ];

	/** What follows /store: [/{collection}[/{key}[/{action}]]], an optional trailing slash. */
	private const TAIL = '(?:/([^/]+)(?:/([^/]+)(?:/([^/]+))?)?)?/?';

	/**
	 * @return array{artifact_id:int,token:string,collection:string|null,key:string|null,action:string|null,query:bool}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/share/(\d+)/([^/]+)/' . preg_quote( self::PATH, '#' ) . self::TAIL . '$#', $path, $m ) !== 1 ) {
			return null;
		}

		return self::route( (int) $m[1], rawurldecode( $m[2] ), $m[3] ?? null, $m[4] ?? null, $m[5] ?? null );
	}

	/**
	 * #610: the same endpoints on an artifact's members address,
	 * /a/{slug}/store[/{collection}[/{key}[/{action}]]] (an id or an old
	 * slug names the artifact too). The token is empty: the members
	 * address has none, the viewer token is the whole credential.
	 *
	 * @return array{artifact_id:int,token:string,collection:string|null,key:string|null,action:string|null,query:bool}|null
	 */
	public static function parseMembersPath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^' . preg_quote( MembersAddress::PATH_PREFIX, '#' ) . '([a-z0-9][a-z0-9-]*)/' . preg_quote( self::PATH, '#' ) . self::TAIL . '$#', $path, $m ) !== 1 ) {
			return null;
		}

		return self::route( MembersAddress::resolve( $m[1] )['artifact_id'], '', $m[2] ?? null, $m[3] ?? null, $m[4] ?? null );
	}

	/**
	 * @return array{artifact_id:int,token:string,collection:string|null,key:string|null,action:string|null,query:bool}|null
	 */
	private static function route( int $artifactId, string $token, ?string $collection, ?string $key, ?string $action ): ?array {
		$key    = $key !== null ? rawurldecode( $key ) : null;
		$action = $action !== null ? rawurldecode( $action ) : null;
		if ( $action === null && in_array( $key, [ '_query', '_rules' ], true ) ) {
			[ $action, $key ] = [ $key, null ];
		}

		if ( $action !== null && ( ! in_array( $action, self::ACTIONS, true ) || ( $key === null ) !== in_array( $action, [ '_query', '_rules' ], true ) ) ) {
			return null;
		}

		return [
			'artifact_id' => $artifactId,
			'token'       => $token,
			'collection'  => $collection !== null ? rawurldecode( $collection ) : null,
			'key'         => $key,
			'action'      => $action,
			'query'       => $action === '_query',
		];
	}

	/**
	 * @param array{artifact_id:int,token:string,collection:string|null,key:string|null,action:string|null,query:bool} $route Validated route context.
	 * @param array<string, mixed>                                                                                       $link  The validated share link row.
	 * @param string|null                                                                                                $body  Raw request body (POST/PUT), when already read.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respond( array $route, array $link, string $method, string $uri, ?string $body = null, ?int $now = null, string $clientIpHash = '', ?string $seatSecret = null ): array {
		return self::serve( $route, (int) $link['id'], false, $method, $uri, $body, $now, $clientIpHash, $seatSecret );
	}

	/**
	 * #610: the store of a page opened on its members address (/a/{slug}),
	 * where a signed-in member sees the page as themselves. The frame there
	 * runs in an opaque origin and sends no cookie, so the viewer token the
	 * frame document was served with (MembersRoute, share link id 0) is the
	 * whole credential: required on every request, preflights included
	 * (it rides in the query), for this artifact and link id 0 only, and
	 * the member must still be allowed to see the address
	 * (MembersAddress::canSeeAddress), checked again each time. Anything
	 * else is the uniform 404. Past that, the same rules, limits and
	 * attribution as a members link (identity member:{type}:{id}, link 0).
	 *
	 * @param array{artifact_id:int,token:string,collection:string|null,key:string|null,action:string|null,query:bool} $route From parseMembersPath().
	 * @param string|null                                                                                                $body  Raw request body (POST/PUT), when already read.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respondMembers( array $route, string $method, string $uri, ?string $body = null, ?int $now = null, string $clientIpHash = '' ): array {
		return self::serve( $route, 0, true, $method, $uri, $body, $now, $clientIpHash, null );
	}

	/**
	 * Both entry points: a share link ($linkId > 0) or the members address
	 * ($members, link id 0).
	 *
	 * @param array{artifact_id:int,token:string,collection:string|null,key:string|null,action:string|null,query:bool} $route
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function serve( array $route, int $linkId, bool $members, string $method, string $uri, ?string $body, ?int $now, string $clientIpHash, ?string $seatSecret ): array {
		$now    = $now ?? time();
		$method = strtoupper( $method );
		$method = $method === 'HEAD' ? 'GET' : ( $method === 'PATCH' ? 'PUT' : $method );

		if ( ! in_array( $method, [ 'GET', 'POST', 'PUT', 'DELETE', 'OPTIONS' ], true ) ) {
			return ShareRoute::notFound();
		}

		$artifactId = $route['artifact_id'];
		$headers    = self::headers();

		// Off by default: no flag, kill switch or disabled sharing is the
		// same 404 as a wrong token.
		if ( ! StoreMode::isActive( $artifactId ) ) {
			return ShareRoute::notFound();
		}

		// #610: on the members address the viewer token comes first, even
		// for a preflight: without it nobody learns the store is on.
		$viewer = null;
		if ( $members ) {
			$viewer = ViewerMode::killSwitchOn() ? null : self::presentedViewer( $method, $uri, $body, $artifactId, 0, $now );
			if ( ! is_array( $viewer ) || ! MembersAddress::canSeeAddress( $viewer['principal_id'], $artifactId ) ) {
				return ShareRoute::notFound();
			}
		}

		// #375: the CORS preflight. The page's origin is opaque, so every
		// PUT, PATCH and DELETE it sends is cross-origin and preflighted.
		// No credentials are allowed, so the wildcard origin stays safe.
		if ( $method === 'OPTIONS' ) {
			$headers['Access-Control-Allow-Methods'] = 'GET, HEAD, POST, PUT, PATCH, DELETE';
			$headers['Access-Control-Allow-Headers'] = 'Content-Type, ' . StoreSeats::HEADER;
			$headers['Access-Control-Max-Age']       = '600';

			return [ 'status' => 204, 'headers' => $headers, 'body' => '' ];
		}

		$ipHash = $clientIpHash !== '' ? $clientIpHash : str_repeat( '0', 64 );
		$write  = $method !== 'GET' && ! $route['query'];
		if ( ! StoreRate::allow( $ipHash, $linkId, $write ? StoreRate::KIND_WRITE : StoreRate::KIND_READ, $now ) ) {
			return self::rateLimited( 60, $headers );
		}

		if ( ! $members ) {
			$viewer = self::presentedViewer( $method, $uri, $body, $artifactId, $linkId, $now );
			if ( $viewer instanceof WP_Error ) {
				return ShareRoute::notFound();
			}
		}

		$seat = $viewer === null && $seatSecret !== null && $seatSecret !== '' && StoreMode::seatsOn( $artifactId ) ? StoreSeats::verify( $artifactId, $seatSecret ) : null;

		if ( $viewer !== null ) {
			$by       = StoreData::attributionFromClaims( $viewer );
			$identity = 'member:' . $viewer['principal_type'] . ':' . $viewer['principal_id'];
		} elseif ( $seat !== null ) {
			$by       = [ 'name' => $seat['name'] . ' (seat)' ] + StoreData::anonymousAttribution();
			$identity = 'seat:' . $seat['name_key'];
		} else {
			$by       = StoreData::anonymousAttribution();
			$identity = 'link:' . $linkId;
		}

		$request = [
			'method'   => $method,
			'body'     => self::decodedBody( $body ),
			'by'       => StoreData::viaLink( $by, $linkId ),
			'identity' => $identity,
			'member'   => $viewer !== null,
			'seat'     => $seat,
			'ip'       => $ipHash,
			'now'      => $now,
		];

		return self::dispatch( $route, $artifactId, $request, $headers );
	}

	/**
	 * @param array{artifact_id:int,token:string,collection:string|null,key:string|null,action:string|null,query:bool} $route
	 * @param array<string, mixed>                                                                                       $req
	 * @param array<string, string>                                                                                      $headers
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function dispatch( array $route, int $artifactId, array $req, array $headers ): array {
		$method = $req['method'];

		if ( $route['collection'] === null ) {
			if ( $method !== 'GET' ) {
				return self::json( 405, [ 'error' => 'method_not_allowed' ], self::withAllow( $headers, 'GET' ) );
			}

			return self::json( 200, [ 'collections' => self::visibleCollections( $artifactId, $req ) ], $headers );
		}

		$collection = StoreData::normalizeCollection( $route['collection'] );
		if ( $collection === null ) {
			return self::json( 400, [ 'error' => 'invalid_collection' ], $headers );
		}

		$rules = StoreMode::rules( $artifactId, $collection );
		if ( $rules === null ) {
			return self::json( 403, [ 'error' => 'collection_not_allowed' ], $headers );
		}

		if ( $route['action'] === '_rules' ) {
			return $method === 'GET'
				? self::json( 200, [ 'rules' => StoreRules::describe( $collection, $rules, $req['member'], $req['seat']['name'] ?? null ) ], $headers )
				: self::json( 405, [ 'error' => 'method_not_allowed' ], self::withAllow( $headers, 'GET' ) );
		}

		if ( $route['action'] === '_query' || ( $method === 'GET' ) ) {
			if ( $route['action'] === '_query' && $method !== 'POST' ) {
				return self::json( 405, [ 'error' => 'method_not_allowed' ], self::withAllow( $headers, 'POST' ) );
			}
			if ( $route['action'] === '_increment' || $route['action'] === '_append' ) {
				return self::json( 405, [ 'error' => 'method_not_allowed' ], self::withAllow( $headers, 'POST' ) );
			}

			return self::read( $route, $artifactId, $collection, $rules, $req, $headers );
		}

		return self::writeRequest( $route, $artifactId, $collection, $rules, $req, $headers );
	}

	/**
	 * GET a document or a collection, or POST _query.
	 *
	 * @param array{artifact_id:int,token:string,collection:string|null,key:string|null,action:string|null,query:bool} $route
	 * @param array<string, mixed>                                                                                       $rules
	 * @param array<string, mixed>                                                                                       $req
	 * @param array<string, string>                                                                                      $headers
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function read( array $route, int $artifactId, string $collection, array $rules, array $req, array $headers ): array {
		if ( ! in_array( 'read', $rules['ops'], true ) || $rules['visibility'] === 'none' ) {
			return self::json( 403, [ 'error' => 'op_not_allowed', 'op' => 'read' ], $headers );
		}

		$identity = $req['identity'];
		$visible  = static fn ( array $doc ): bool => $rules['visibility'] === 'all' || $doc['owner'] === $identity;
		$page     = static fn ( array $doc ): array => self::forPage( $doc, $rules, $identity );

		if ( $route['query'] ) {
			$body   = $req['body'];
			$where  = isset( $body['where'] ) && is_array( $body['where'] ) ? $body['where'] : [];
			$limit  = isset( $body['limit'] ) && is_numeric( $body['limit'] ) ? (int) $body['limit'] : StoreData::QUERY_MAX_LIMIT;
			$offset = isset( $body['offset'] ) && is_numeric( $body['offset'] ) ? (int) $body['offset'] : 0;
			$docs   = array_values( array_filter( StoreData::query( $artifactId, $collection, $where, StoreData::MAX_DOCS, 0 ), $visible ) );

			return self::json( 200, [ 'docs' => array_map( $page, array_slice( $docs, max( 0, $offset ), max( 1, min( $limit, StoreData::QUERY_MAX_LIMIT ) ) ) ) ], $headers );
		}

		if ( $route['key'] === null ) {
			return self::json( 200, [ 'docs' => array_map( $page, array_values( array_filter( StoreData::list( $artifactId, $collection ), $visible ) ) ) ], $headers );
		}

		$key = self::storedKey( $route['key'], $rules, $identity );
		$doc = $key === null ? null : StoreData::get( $artifactId, $collection, $key );

		// Someone else's document under visibility "own" is a plain 404:
		// its existence is not the page's business.
		return $doc === null || ! $visible( $doc )
			? self::json( 404, [ 'error' => 'not_found' ], $headers )
			: self::json( 200, [ 'doc' => $page( $doc ) ], $headers );
	}

	/**
	 * Every page write: create, replace, update, delete, increment, append.
	 *
	 * @param array{artifact_id:int,token:string,collection:string|null,key:string|null,action:string|null,query:bool} $route
	 * @param array<string, mixed>                                                                                       $rules
	 * @param array<string, mixed>                                                                                       $req
	 * @param array<string, string>                                                                                      $headers
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function writeRequest( array $route, int $artifactId, string $collection, array $rules, array $req, array $headers ): array {
		$method    = $req['method'];
		$body      = $req['body'];
		$identity  = $req['identity'];
		$generated = $rules['key'] === StoreRules::KEY_GENERATED;

		// Which operation, and on which page key.
		if ( $route['key'] === null ) {
			if ( $method !== 'POST' ) {
				return self::json( 405, [ 'error' => 'method_not_allowed' ], self::withAllow( $headers, 'GET, POST' ) );
			}
			$pageKey = isset( $body['key'] ) && is_string( $body['key'] ) ? $body['key'] : null;
			if ( $generated && $pageKey !== null ) {
				return self::json( 400, [ 'error' => 'key_generated', 'message' => 'This collection picks the keys; send only the value.' ], $headers );
			}
			$op = 'set';
		} elseif ( $route['action'] === '_increment' || $route['action'] === '_append' ) {
			if ( $method !== 'POST' ) {
				return self::json( 405, [ 'error' => 'method_not_allowed' ], self::withAllow( $headers, 'POST' ) );
			}
			$pageKey = $route['key'];
			$op      = substr( $route['action'], 1 );
		} else {
			$pageKey = $route['key'];
			$op      = [ 'PUT' => 'update', 'DELETE' => 'delete' ][ $method ] ?? null;
			if ( $op === null ) {
				return self::json( 405, [ 'error' => 'method_not_allowed' ], self::withAllow( $headers, 'GET, PUT, DELETE' ) );
			}
		}

		if ( ! StoreRules::writerAllowed( $rules, $req['member'] ) ) {
			return self::json( 403, [ 'error' => 'op_not_allowed', 'op' => $op === 'set' ? 'create' : $op, 'message' => 'Only named members may write here.' ], $headers );
		}

		// The key: generated, or the page's, checked against the rules.
		if ( $generated && $pageKey === null ) {
			$key = self::storedKey( StoreData::generateKey(), $rules, $identity );
		} else {
			$normal = $pageKey === null ? null : StoreData::normalizeKey( $pageKey );
			if ( $normal === null ) {
				return self::json( 400, [ 'error' => 'invalid_key' ], $headers );
			}
			if ( ! $generated && $rules['key'] !== StoreRules::KEY_ANY && preg_match( StoreRules::delimit( (string) $rules['key'] ), $normal ) !== 1 ) {
				return self::json( 400, [ 'error' => 'key_not_allowed', 'message' => 'The key does not match this collection\'s key pattern.' ], $headers );
			}
			$key = self::storedKey( $normal, $rules, $identity );
		}
		if ( $key === null ) {
			return self::json( 400, [ 'error' => 'invalid_key' ], $headers );
		}

		// #469: under owner "seat" the key is a seat name, and only that seat writes it.
		if ( $rules['owner'] === 'seat' ) {
			if ( $req['seat'] === null ) {
				return self::json( 403, [ 'error' => 'not_owner', 'message' => 'Claim a seat (tomatio.seat.claim) to write here.' ], $headers );
			}
			if ( StoreSeats::nameKey( $key ) !== $req['seat']['name_key'] ) {
				return self::json( 403, [ 'error' => 'not_owner', 'message' => 'Only the seat named by the key may write it.' ], $headers );
			}
		}

		$expected = null;
		if ( isset( $body['expected_version'] ) ) {
			if ( ! is_int( $body['expected_version'] ) || $body['expected_version'] < 0 ) {
				return self::json( 400, [ 'error' => 'invalid_expected_version' ], $headers );
			}
			$expected = $body['expected_version'];
		}

		// Ops that land on a known document need its version when the rules
		// say so; increments and appends commute and never do.
		if ( ! empty( $rules['require_version'] ) && $expected === null && in_array( $op, [ 'set', 'update', 'delete' ], true ) && ! ( $generated && $op === 'set' ) ) {
			return self::json( 400, [ 'error' => 'version_required', 'message' => 'Send expected_version (0 to create).' ], $headers );
		}

		// The cheap refusals first, so a refused write never uses the budget.
		if ( $op !== 'set' && ! in_array( $op, $rules['ops'], true ) ) {
			return self::json( 403, [ 'error' => 'op_not_allowed', 'op' => $op ], $headers );
		}

		// #386: a dry run against the current document, so a write that is
		// wrong anyway (op, owner, field, step, schema) says why rather than
		// "rate_limited", and never touches the budget.
		$dry = self::attempt( $op, $artifactId, $collection, $key, $rules, $req, $expected, true );
		if ( is_array( $dry ) && isset( $dry['status'] ) ) {
			return self::json( (int) $dry['status'], $dry['body'], $headers );
		}

		$buckets = StoreLimits::buckets( $artifactId, $collection, $rules, $key, $identity, $req['ip'] );
		$wait    = StoreLimits::retryAfter( $buckets, $req['now'] );
		if ( $wait > 0 ) {
			return self::rateLimited( $wait, $headers );
		}

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$result = self::attempt( $op, $artifactId, $collection, $key, $rules, $req, $expected );
			// A lost race is retried only when the page did not pin a version.
			if ( ! ( $result instanceof WP_Error && $result->get_error_code() === 'ab_store_version_conflict' && $expected === null ) ) {
				break;
			}
		}

		if ( is_array( $result ) && isset( $result['status'] ) ) {
			return self::json( (int) $result['status'], $result['body'], $headers );
		}
		if ( $result instanceof WP_Error ) {
			if ( $result->get_error_code() === 'ab_store_version_conflict' ) {
				// The current document as the page sees it, to merge and retry.
				$data = $result->get_error_data();
				$doc  = is_array( $data ) && is_array( $data['doc'] ?? null ) ? self::forPage( $data['doc'], $rules, $identity ) : null;

				return self::json( 409, [ 'error' => 'version_conflict', 'doc' => $doc ], $headers );
			}

			return self::error( $result, $headers );
		}

		StoreLimits::record( $buckets, $req['now'] );

		if ( $result === true ) {
			self::auditWrite( 'datastore_delete', $req['by'], $artifactId, $collection, $key );

			return self::json( 200, [ 'deleted' => true ], $headers );
		}

		self::auditWrite( 'datastore_write', $req['by'], $artifactId, $collection, $key );

		return self::json( 200, [ 'doc' => self::forPage( $result, $rules, $identity ) ], $headers );
	}

	/**
	 * One try of a write against the current document: the stored document
	 * (array with 'key'), true for a delete, a refusal ['status', 'body'],
	 * or a WP_Error (conflicts, limits). With $dry nothing is written: null
	 * when the write would go ahead.
	 *
	 * @param array<string, mixed> $rules
	 * @param array<string, mixed> $req
	 * @return array<string, mixed>|true|WP_Error|null
	 */
	private static function attempt( string $op, int $artifactId, string $collection, string $key, array $rules, array $req, ?int $expected, bool $dry = false ) {
		$body     = $req['body'];
		$identity = $req['identity'];
		$existing = StoreData::get( $artifactId, $collection, $key );
		$refuse   = static fn ( int $status, string $error, array $extra = [] ): array => [ 'status' => $status, 'body' => [ 'error' => $error ] + $extra ];

		if ( $op === 'set' ) {
			$op = $existing === null ? 'create' : 'replace';
			if ( ! in_array( $op, $rules['ops'], true ) ) {
				return $op === 'replace' && in_array( 'create', $rules['ops'], true )
					? $refuse( 409, 'exists', [ 'message' => 'This collection only takes new documents.' ] )
					: $refuse( 403, 'op_not_allowed', [ 'op' => $op ] );
			}
		}

		if ( $existing !== null ) {
			// Someone else's document under visibility "own" does not exist
			// for this identity; under owner "writer" or "self" it is theirs.
			if ( $rules['visibility'] !== 'all' && $existing['owner'] !== $identity ) {
				return $op === 'create' ? $refuse( 409, 'exists' ) : $refuse( 404, 'not_found' );
			}
			// Owner "seat" goes by the key (checked before), not by who wrote first.
			if ( ! in_array( $rules['owner'], [ 'anyone', 'seat' ], true ) && $existing['owner'] !== $identity ) {
				return $refuse( 403, 'not_owner' );
			}
		} elseif ( in_array( $op, [ 'replace', 'update', 'delete' ], true ) ) {
			return $refuse( 404, 'not_found' );
		}

		if ( $existing === null && ! empty( $rules['one_per_identity'] ) && self::ownsAny( $artifactId, $collection, $identity ) ) {
			return $refuse( 409, 'one_per_identity', [ 'message' => 'You already have a document here; update it instead.' ] );
		}

		$version = $expected ?? ( $existing === null ? 0 : (int) $existing['version'] );
		$old     = $existing['value'] ?? null;
		$delta   = null;

		switch ( $op ) {
			case 'delete':
				if ( $dry ) {
					return null;
				}
				if ( ! StoreData::delete( $artifactId, $collection, $key, $version, $req['by'], 'delete', $req['now'] ) ) {
					return new WP_Error( 'ab_store_version_conflict', 'conflict', [ 'status' => 409, 'doc' => StoreData::get( $artifactId, $collection, $key ) ] );
				}

				return true;

			case 'create':
			case 'replace':
				$value = $body['value'] ?? null;
				break;

			case 'update':
				if ( ! array_key_exists( 'patch', $body ) ) {
					return $refuse( 400, 'missing_patch' );
				}
				$value = StoreData::merge( $old, $body['patch'] );
				$delta = [ 'patch' => $body['patch'] ];
				break;

			case 'increment':
				$field = isset( $body['field'] ) && is_string( $body['field'] ) ? $body['field'] : '';
				$step  = $rules['increment']['step'] ?? null;
				$by    = $body['by'] ?? $step ?? 1;
				$fields = $rules['increment']['fields'] ?? [];
				if ( $field === '' || ( $fields !== [] && ! in_array( $field, $fields, true ) ) ) {
					return $refuse( 400, 'field_not_allowed', [ 'fields' => $fields ] );
				}
				if ( ! is_int( $by ) || $by === 0 || ( $step !== null && $by !== $step ) ) {
					return $refuse( 400, 'step_not_allowed', [ 'step' => $step ] );
				}
				$value = $old ?? [];
				if ( ! is_array( $value ) || ( isset( $value[ $field ] ) && ! is_int( $value[ $field ] ) ) ) {
					return $refuse( 400, 'schema', [ 'detail' => 'value.' . $field . ' must be a whole number to increment' ] );
				}
				$value[ $field ] = ( $value[ $field ] ?? 0 ) + $by;
				$delta           = [ 'field' => $field, 'by' => $by ];
				break;

			case 'append':
				$field  = isset( $body['field'] ) && is_string( $body['field'] ) ? $body['field'] : '';
				$fields = $rules['append']['fields'] ?? [];
				if ( $field === '' || ( $fields !== [] && ! in_array( $field, $fields, true ) ) ) {
					return $refuse( 400, 'field_not_allowed', [ 'fields' => $fields ] );
				}
				if ( ! array_key_exists( 'item', $body ) ) {
					return $refuse( 400, 'missing_item' );
				}
				$value = $old ?? [];
				if ( ! is_array( $value ) || ( isset( $value[ $field ] ) && ( ! is_array( $value[ $field ] ) || ( $value[ $field ] !== [] && array_keys( $value[ $field ] ) !== range( 0, count( $value[ $field ] ) - 1 ) ) ) ) ) {
					return $refuse( 400, 'schema', [ 'detail' => 'value.' . $field . ' must be a list to append to' ] );
				}
				$value[ $field ]   = $value[ $field ] ?? [];
				$value[ $field ][] = $body['item'];
				$delta             = [ 'field' => $field, 'item' => $body['item'] ];
				break;

			default:
				return $refuse( 403, 'op_not_allowed', [ 'op' => $op ] );
		}

		if ( isset( $rules['schema'] ) ) {
			$problem = StoreSchema::check( $value, $rules['schema'], $old, $existing !== null );
			if ( $problem !== null ) {
				return $refuse( 400, 'schema', [ 'detail' => $problem ] );
			}
		}

		if ( $dry ) {
			return null;
		}

		return StoreData::write( $artifactId, $collection, $key, $value, $req['by'], $op, $delta, $version, $identity, $req['now'] );
	}

	/**
	 * The stored key for a page key: the page's own under owner "self"
	 * (a short identity tag in front), else the key itself.
	 *
	 * @param array<string, mixed> $rules
	 */
	private static function storedKey( string $pageKey, array $rules, string $identity ): ?string {
		$key = StoreData::normalizeKey( $pageKey );
		if ( $key === null || $rules['owner'] !== 'self' ) {
			return $key;
		}

		return StoreData::normalizeKey( self::tag( $identity ) . '.' . $key );
	}

	private static function tag( string $identity ): string {
		return 'o' . substr( hash( 'sha256', $identity ), 0, 10 );
	}

	/**
	 * A stored document as the page sees it, with its page key.
	 *
	 * @param array<string, mixed> $doc
	 * @param array<string, mixed> $rules
	 * @return array<string, mixed>
	 */
	private static function forPage( array $doc, array $rules, string $identity ): array {
		$out = StoreData::pageDoc( $doc, $identity );
		if ( $rules['owner'] === 'self' && preg_match( '/^o[0-9a-f]{10}\.(.+)$/', (string) $out['key'], $m ) === 1 ) {
			$out['key'] = $m[1];
		}

		return $out;
	}

	private static function ownsAny( int $artifactId, string $collection, string $identity ): bool {
		foreach ( StoreData::list( $artifactId, $collection ) as $doc ) {
			if ( $doc['owner'] === $identity ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The index a page sees: the listed collections, each with its rules and
	 * what this identity may do; counts only where every document is visible.
	 *
	 * @param array<string, mixed> $req
	 * @return array<int, array<string, mixed>>
	 */
	private static function visibleCollections( int $artifactId, array $req ): array {
		$stats = array_column( StoreData::collections( $artifactId ), null, 'collection' );
		$out   = [];
		foreach ( StoreMode::collections( $artifactId ) as $name => $rules ) {
			$row = StoreRules::describe( $name, $rules, $req['member'], $req['seat']['name'] ?? null );
			if ( $rules['visibility'] === 'all' && in_array( 'read', $rules['ops'], true ) ) {
				$row['docs']       = $stats[ $name ]['docs'] ?? 0;
				$row['updated_at'] = $stats[ $name ]['updated_at'] ?? null;
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function rateLimited( int $seconds, array $headers ): array {
		$response                           = self::json( 429, [ 'error' => 'rate_limited', 'retry_after' => $seconds ], $headers );
		$response['headers']['Retry-After'] = (string) $seconds;

		return $response;
	}

	/**
	 * The verified ART-24 viewer, null when none was presented, or an error
	 * when one was presented but does not verify for this link/artifact.
	 *
	 * @param string|null                                          $body
	 * @return array{principal_id:int,principal_type:string,name:string,roles:array<int,string>}|null|WP_Error
	 */
	private static function presentedViewer( string $method, string $uri, ?string $body, int $artifactId, int $linkId, int $now ) {
		$token = null;

		$query = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		if ( isset( $query['viewer_token'] ) && is_string( $query['viewer_token'] ) ) {
			$token = $query['viewer_token'];
		}

		if ( $token === null && in_array( strtoupper( $method ), [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
			$decoded = self::decodedBody( $body );
			if ( isset( $decoded['viewer_token'] ) && is_string( $decoded['viewer_token'] ) ) {
				$token = $decoded['viewer_token'];
			}
		}

		if ( $token === null ) {
			return null;
		}

		$claims = ViewerToken::verify( $token, ViewerKeys::all(), $now );
		if ( $claims === null || $claims['artifact_id'] !== $artifactId || $claims['share_link_id'] !== $linkId ) {
			return new WP_Error( 'ab_store_viewer_invalid', 'invalid viewer token' );
		}

		return $claims;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decodedBody( ?string $body ): array {
		if ( $body === null ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- no WP filesystem API for php://input; read-only.
			$body = file_get_contents( 'php://input' );
		}

		$decoded = json_decode( is_string( $body ) ? $body : '', true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null,link:int} $by
	 */
	private static function auditWrite( string $event, array $by, int $artifactId, string $collection, string $key ): void {
		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $by['id'] ?? 0,
				'event'       => $event,
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'collection' => $collection, 'key' => $key, 'viewer' => $by['type'], 'share_link_id' => $by['link'] ],
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return [
			'Content-Type'                 => 'application/json; charset=utf-8',
			'X-Robots-Tag'                 => 'noindex, nofollow',
			'Cache-Control'                => 'private, no-store',
			'Pragma'                       => 'no-cache',
			'X-Content-Type-Options'       => 'nosniff',
			// The page runs in an opaque origin; this endpoint takes no
			// cookies and sets none.
			'Access-Control-Allow-Origin'  => '*',
			'Cross-Origin-Resource-Policy' => 'cross-origin',
		];
	}

	/**
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	private static function withAllow( array $headers, string $allow ): array {
		$headers['Allow'] = $allow;

		return $headers;
	}

	/**
	 * @param array<string, mixed>  $data
	 * @param array<string, string> $headers
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function json( int $status, array $data, array $headers ): array {
		return [
			'status'  => $status,
			'headers' => $headers,
			'body'    => (string) wp_json_encode( $data ),
		];
	}

	/**
	 * @param array<string, string> $headers
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function error( WP_Error $error, array $headers ): array {
		$data = $error->get_error_data();

		return self::json(
			is_array( $data ) && isset( $data['status'] ) && is_int( $data['status'] ) ? $data['status'] : 400,
			[ 'error' => $error->get_error_code(), 'message' => $error->get_error_message() ],
			$headers
		);
	}
}
