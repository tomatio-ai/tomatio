<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Clipper;

use AgentBrain\Core\AuthorizedBy;
use AgentBrain\Core\ClientContext;
use AgentBrain\Core\WriteLimiter;
use AgentBrain\Modules\Artifacts\ArtifactIndex;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDayDefault;
use AgentBrain\Modules\Extensions\Package;
use AgentBrain\Modules\Extensions\PageMaker;
use AgentBrain\Modules\Extensions\PageRefresh;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\InsertFailed;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Provenance\OutsideSources;
use AgentBrain\Modules\Sharing\MembersAddress;
use AgentBrain\Modules\Voice\Access;
use WP_Error;

/**
 * B228: the web clipper's save and "already saved" lookup, and the
 * person's Read later page.
 *
 * A clip is the page (readable Markdown with its title, address, author
 * and date), a selection from it, or a bookmark with a note. It is saved
 * the way any outside reading is: an outside source record (kind url,
 * OutsideSources, the source-register path) linked to an episode memory
 * row that holds the Markdown, in the scope the person picks (their
 * private scope by default), with the tags in its search keys. So every
 * connected AI that reads that scope can find and cite it. A clip is
 * never knowledge: knowledge needs a proposal and someone's approval.
 *
 * The Read later list is a page made from the bundled readlater package
 * for each person, in their private scope, the first time they clip. Its
 * `clips` collection holds one document per address (keyFor()): title,
 * address, site, tags, state (unread, read, archived) and the memory rows
 * it was saved as. The page marks read and archives; this class only
 * adds and refreshes documents. An address clipped again keeps its state
 * and gains the new memory row.
 */
final class Clips {

	public const PACKAGE      = 'readlater';
	public const EXTENSION_ID = 'tomatio.readlater';
	public const COLLECTION   = 'clips';

	/** Post meta on a Read later page: the person it belongs to. */
	public const META_OWNER = 'ab_readlater_owner';

	public const MODES  = [ 'page', 'selection', 'bookmark' ];
	public const STATES = [ 'unread', 'read', 'archived' ];

	public const MAX_MARKDOWN  = 200000;
	public const MAX_NOTE      = 2000;
	public const MAX_TITLE     = 300;
	public const MAX_URL       = 2000;
	public const MAX_SHORT     = 200;
	public const MAX_TAGS      = 20;
	public const MAX_TAG       = 40;
	public const MAX_MEMORIES  = 20;
	public const EXCERPT_CHARS = 280;

	/** Query arguments that only track the click, dropped from the address a clip is known by. */
	private const TRACKING = [ 'fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'igshid', 'ref_src', '_hsenc', '_hsmi' ];

	// -----------------------------------------------------------------
	// Addresses, keys and tags
	// -----------------------------------------------------------------

	/**
	 * The address a clip is known by: http or https only, scheme and host
	 * in lower case, no fragment, no tracking arguments (utm_* and
	 * TRACKING), no default port. null when it is not such an address.
	 */
	public static function normalizeUrl( string $url ): ?string {
		$url = trim( $url );
		if ( $url === '' || strlen( $url ) > self::MAX_URL || preg_match( '/[\x00-\x20\x7f]/', $url ) === 1 ) {
			return null;
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing, no WordPress needed.
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || $parts['host'] === '' ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( ( $scheme === 'http' && $port === 80 ) || ( $scheme === 'https' && $port === 443 ) ) {
			$port = 0;
		}

		$query = '';
		if ( isset( $parts['query'] ) && $parts['query'] !== '' ) {
			$keep = [];
			foreach ( explode( '&', (string) $parts['query'] ) as $pair ) {
				if ( $pair === '' ) {
					continue;
				}
				$name = strtolower( rawurldecode( explode( '=', $pair, 2 )[0] ) );
				if ( str_starts_with( $name, 'utm_' ) || in_array( $name, self::TRACKING, true ) ) {
					continue;
				}
				$keep[] = $pair;
			}
			$query = $keep === [] ? '' : '?' . implode( '&', $keep );
		}

		$path = isset( $parts['path'] ) && $parts['path'] !== '' ? (string) $parts['path'] : '/';

		return $scheme . '://' . $host . ( $port > 0 ? ':' . $port : '' ) . $path . $query;
	}

	/** The `clips` document key of an address (already normalized). */
	public static function keyFor( string $normalizedUrl ): string {
		return 'c' . substr( hash( 'sha256', $normalizedUrl ), 0, 32 );
	}

	/**
	 * Tags as given: strings, trimmed, a leading # dropped, lower case,
	 * unique, at most MAX_TAGS of at most MAX_TAG characters.
	 *
	 * @param mixed $raw An array of strings, or one comma-separated string.
	 * @return array<int, string>|WP_Error
	 */
	public static function tags( $raw ) {
		if ( $raw === null || $raw === '' || $raw === [] ) {
			return [];
		}
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return self::invalid( 'tags must be an array of strings' );
		}
		$out = [];
		foreach ( array_values( $raw ) as $i => $tag ) {
			if ( ! is_string( $tag ) ) {
				return self::invalid( sprintf( 'tags[%d] must be a string', $i ) );
			}
			$tag = mb_strtolower( trim( ltrim( trim( $tag ), '#' ) ) );
			$tag = (string) preg_replace( '/\s+/u', ' ', $tag );
			if ( $tag === '' ) {
				continue;
			}
			if ( mb_strlen( $tag ) > self::MAX_TAG ) {
				return self::invalid( sprintf( 'tags[%d] is longer than %d characters', $i, self::MAX_TAG ) );
			}
			$out[ $tag ] = $tag;
		}
		if ( count( $out ) > self::MAX_TAGS ) {
			return self::invalid( sprintf( 'at most %d tags', self::MAX_TAGS ) );
		}

		return array_values( $out );
	}

	// -----------------------------------------------------------------
	// The clip as stored
	// -----------------------------------------------------------------

	/**
	 * Check and tidy a clip-save input. Returns the clip (url normalized,
	 * texts trimmed and capped) or the first problem.
	 *
	 * @param array<string, mixed> $input
	 * @return array{url:string, original_url:string, title:string, mode:string, markdown:string, note:string, author:string, published:string, site:string, tags:array<int, string>, scope_id:int}|WP_Error
	 */
	public static function clean( array $input ) {
		$original = is_string( $input['url'] ?? null ) ? trim( (string) $input['url'] ) : '';
		$url      = self::normalizeUrl( $original );
		if ( $url === null ) {
			return self::invalid( 'url must be an http or https address (at most ' . self::MAX_URL . ' characters, no user name or password)' );
		}

		$mode = is_string( $input['mode'] ?? null ) && $input['mode'] !== '' ? (string) $input['mode'] : 'page';
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return self::invalid( 'mode must be one of: ' . implode( ', ', self::MODES ) );
		}

		foreach ( [ 'title', 'markdown', 'note', 'author', 'published', 'site' ] as $field ) {
			if ( array_key_exists( $field, $input ) && $input[ $field ] !== null && ! is_string( $input[ $field ] ) ) {
				return self::invalid( $field . ' must be a string' );
			}
		}

		$markdown = trim( str_replace( "\r\n", "\n", (string) ( $input['markdown'] ?? '' ) ) );
		if ( mb_strlen( $markdown ) > self::MAX_MARKDOWN ) {
			return self::invalid( sprintf( 'markdown is longer than %d characters: clip a selection instead', self::MAX_MARKDOWN ) );
		}
		$note = trim( str_replace( "\r\n", "\n", (string) ( $input['note'] ?? '' ) ) );
		if ( mb_strlen( $note ) > self::MAX_NOTE ) {
			return self::invalid( sprintf( 'note is longer than %d characters', self::MAX_NOTE ) );
		}
		if ( $mode !== 'bookmark' && $markdown === '' ) {
			return self::invalid( 'markdown must not be empty for a ' . $mode . ' clip (a bookmark needs none)' );
		}
		if ( $mode === 'bookmark' ) {
			$markdown = '';
		}

		$tags = self::tags( $input['tags'] ?? [] );
		if ( $tags instanceof WP_Error ) {
			return $tags;
		}

		$title = self::line( (string) ( $input['title'] ?? '' ), self::MAX_TITLE );
		$host  = (string) parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain parsing.

		return [
			'url'          => $url,
			'original_url' => $original,
			'title'        => $title !== '' ? $title : $url,
			'mode'         => $mode,
			'markdown'     => $markdown,
			'note'         => $note,
			'author'       => self::line( (string) ( $input['author'] ?? '' ), self::MAX_SHORT ),
			'published'    => self::line( (string) ( $input['published'] ?? '' ), 40 ),
			'site'         => self::line( (string) ( $input['site'] ?? '' ), self::MAX_SHORT ) ?: (string) preg_replace( '/^www\./', '', $host ),
			'tags'         => $tags,
			'scope_id'     => max( 0, (int) ( $input['scope_id'] ?? 0 ) ),
		];
	}

	/**
	 * The memory row's content: a short header any AI can read (what this
	 * is, where it came from, when it was clipped, the tags and the note),
	 * then the clipped Markdown.
	 *
	 * @param array<string, mixed> $clip From clean().
	 */
	public static function render( array $clip, string $clippedAt ): string {
		$what = [
			'page'      => 'Web clip (the whole page)',
			'selection' => 'Web clip (a selection from the page)',
			'bookmark'  => 'Bookmark',
		][ $clip['mode'] ] ?? 'Web clip';

		$lines   = [ '# ' . $clip['title'], '' ];
		$lines[] = '> ' . $what . ', saved with the TomaTío web clipper. Outside reading: not approved knowledge.';
		$lines[] = '';
		$lines[] = '- Address: ' . $clip['url'];
		if ( $clip['site'] !== '' ) {
			$lines[] = '- Site: ' . $clip['site'];
		}
		if ( $clip['author'] !== '' ) {
			$lines[] = '- Author: ' . $clip['author'];
		}
		if ( $clip['published'] !== '' ) {
			$lines[] = '- Published: ' . $clip['published'];
		}
		$lines[] = '- Clipped: ' . $clippedAt;
		if ( $clip['tags'] !== [] ) {
			$lines[] = '- Tags: ' . implode( ', ', $clip['tags'] );
		}
		if ( $clip['note'] !== '' ) {
			$lines[] = '';
			$lines[] = '## Note';
			$lines[] = '';
			$lines[] = $clip['note'];
		}
		if ( $clip['markdown'] !== '' ) {
			$lines[] = '';
			$lines[] = $clip['mode'] === 'selection' ? '## Selection' : '## Page';
			$lines[] = '';
			$lines[] = $clip['markdown'];
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The memory row's search keys: "clip", the mode, the site and the tags.
	 *
	 * @param array<string, mixed> $clip From clean().
	 */
	public static function searchKeys( array $clip ): string {
		return implode( ' ', array_values( array_unique( array_filter( array_merge( [ 'clip', 'web-clip', $clip['mode'], (string) $clip['site'] ], array_map( static fn ( string $t ): string => str_replace( ' ', '-', $t ), $clip['tags'] ) ) ) ) ) );
	}

	/** The first EXCERPT_CHARS characters of the clip's text, as one plain line. */
	public static function excerpt( string $markdown, string $note ): string {
		$text = $note !== '' ? $note : $markdown;
		$text = (string) preg_replace( '/!\[[^\]]*\]\([^)]*\)/u', '', $text );
		$text = (string) preg_replace( '/\[([^\]]*)\]\([^)]*\)/u', '$1', $text );
		$text = (string) preg_replace( '/^[#>*\-\s]+/mu', '', $text );
		$text = (string) preg_replace( '/[*_`]+/u', '', $text );

		return self::line( $text, self::EXCERPT_CHARS );
	}

	// -----------------------------------------------------------------
	// Save and look up
	// -----------------------------------------------------------------

	/**
	 * Save a clip as $actorId (the agent writing), for $personId (the person
	 * behind the connection, 0 for none).
	 *
	 * @param array<string, mixed> $input clip-save's input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save( array $input, int $actorId, int $personId ) {
		$clip = self::clean( $input );
		if ( $clip instanceof WP_Error ) {
			return $clip;
		}

		$scopes   = new Scopes();
		$writable = array_values( array_map( 'intval', $scopes->writeScopeIds( $actorId ) ) );
		$scopeId  = $clip['scope_id'];
		if ( $scopeId <= 0 ) {
			$scopeId = self::defaultScope( $actorId, $personId );
		}
		if ( $scopeId <= 0 || ! $scopes->canWrite( $actorId, $scopeId ) ) {
			return new WP_Error( 'ab_forbidden', self::scopesHint( $writable ), [ 'status' => 403 ] );
		}

		$now     = gmdate( 'Y-m-d\TH:i:s\Z' );
		$content = self::render( $clip, $now );
		$via     = $personId > 0 && $personId !== $actorId ? $personId : ( AuthorizedBy::id() > 0 && AuthorizedBy::id() !== $actorId ? AuthorizedBy::id() : 0 );

		$limiter  = new WriteLimiter();
		$acquired = $limiter->acquire();
		if ( $acquired instanceof WP_Error ) {
			return $acquired;
		}
		try {
			$sources  = new OutsideSources();
			$sourceId = $sources->register(
				[
					'kind'         => 'url',
					'title'        => $clip['title'],
					'locator'      => $clip['url'],
					'publisher'    => $clip['site'] !== '' ? $clip['site'] : null,
					'retrieved_at' => $now,
					'agent_id'     => $actorId,
				]
			);
			$memoryId = ( new MemoryRepository() )->insert(
				[
					'agent_id'      => $actorId,
					'scope_id'      => $scopeId,
					'type'          => 'episode',
					'content'       => $content,
					'search_keys'   => self::searchKeys( $clip ),
					'importance'    => 50,
					'status'        => 'active',
					'agent_written' => Access::isAgent( $actorId ) ? 1 : 0,
					'client'        => ClientContext::current(),
					'via'           => $via > 0 ? $via : null,
				]
			);
			$sources->link( 'memory', $memoryId, [ $sourceId ] );
		} catch ( \InvalidArgumentException $e ) {
			return self::invalid( $e->getMessage() );
		} catch ( InsertFailed $e ) {
			return new WP_Error( 'ab_write_failed', __( 'The clip was not saved: the database did not store it. Nothing was written; try again.', 'tomatio' ), [ 'status' => 500 ] );
		} finally {
			$limiter->release();
		}

		$readLater = $personId > 0 ? self::remember( $clip, $personId, $actorId, $scopeId, $memoryId, $sourceId, $now ) : null;

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'clip.saved',
				'status'      => 'ok',
				'object_type' => 'ab_memory',
				'object_id'   => (string) $memoryId,
				'meta'        => [ 'scope_id' => $scopeId, 'mode' => $clip['mode'], 'source_id' => $sourceId, 'read_later' => is_array( $readLater ) ? $readLater['artifact_id'] : 0 ],
			]
		);

		$out = [
			'saved'         => true,
			'memory_id'     => $memoryId,
			'source_id'     => $sourceId,
			'scope_id'      => $scopeId,
			'url'           => $clip['url'],
			'title'         => $clip['title'],
			'mode'          => $clip['mode'],
			'tags'          => $clip['tags'],
			'already_saved' => is_array( $readLater ) && $readLater['already_saved'],
			'knowledge'     => false,
		];
		if ( is_array( $readLater ) ) {
			$out['read_later'] = [ 'artifact_id' => $readLater['artifact_id'], 'url' => $readLater['url'], 'key' => $readLater['key'], 'state' => $readLater['state'] ];
		} else {
			$out['note'] = is_string( $readLater ) ? $readLater : __( 'Saved. There is no Read later list for a connection with no person behind it.', 'tomatio' );
		}

		return $out;
	}

	/**
	 * Whether $url is saved: in the person's Read later list, else among
	 * the url sources $actorId registered. Never says anything about
	 * another person's clips or another agent's sources.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function lookup( string $url, int $actorId, int $personId, int $writerId = 0 ) {
		$normal = self::normalizeUrl( $url );
		if ( $normal === null ) {
			return self::invalid( 'url must be an http or https address' );
		}

		$out = self::lookupOnly( $normal, $actorId, $personId );
		if ( $writerId > 0 ) {
			$out['scopes']           = self::writableScopes( $writerId, $personId );
			$out['default_scope_id'] = self::defaultScope( $writerId, $personId );
		}

		return $out;
	}

	/**
	 * The scopes a clip may go to: the ones $writerId writes, each with
	 * its name and whether it is the person's private scope.
	 *
	 * @return array<int, array{id:int, name:string, private:bool}>
	 */
	public static function writableScopes( int $writerId, int $personId ): array {
		$home = $personId > 0 ? Access::homeScope( $personId ) : 0;
		$out  = [];
		foreach ( ( new Scopes() )->writeScopeIds( $writerId ) as $scopeId ) {
			$term  = get_term( (int) $scopeId, Scopes::TAXONOMY );
			$out[] = [
				'id'      => (int) $scopeId,
				'name'    => $term instanceof \WP_Term ? (string) $term->name : 'Scope ' . (int) $scopeId,
				'private' => (int) $scopeId === $home,
			];
		}

		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function lookupOnly( string $normal, int $actorId, int $personId ): array {

		$key = self::keyFor( $normal );
		$id  = $personId > 0 ? self::readLaterFor( $personId ) : 0;
		if ( $id > 0 ) {
			$doc = StoreData::get( $id, self::COLLECTION, $key );
			if ( is_array( $doc ) && is_array( $doc['value'] ?? null ) ) {
				$v = $doc['value'];

				return [
					'saved'      => true,
					'url'        => $normal,
					'title'      => (string) ( $v['title'] ?? '' ),
					'state'      => (string) ( $v['state'] ?? 'unread' ),
					'saved_at'   => (string) ( $v['saved_at'] ?? '' ),
					'tags'       => array_values( array_filter( (array) ( $v['tags'] ?? [] ), 'is_string' ) ),
					'scope_id'   => (int) ( $v['scope_id'] ?? 0 ),
					'memory_ids' => array_values( array_map( 'intval', (array) ( $v['memory_ids'] ?? [] ) ) ),
					'read_later' => [ 'artifact_id' => $id, 'url' => MembersAddress::url( $id ), 'key' => $key ],
				];
			}
		}

		if ( $actorId > 0 && ( new OutsideSources() )->find( 'url', $normal, null, $actorId ) !== null ) {
			return [ 'saved' => true, 'url' => $normal ];
		}

		return [ 'saved' => false, 'url' => $normal ];
	}

	// -----------------------------------------------------------------
	// Read later
	// -----------------------------------------------------------------

	/** The person's Read later page: their newest live one. 0 when they have none. */
	public static function readLaterFor( int $personId ): int {
		if ( $personId <= 0 ) {
			return 0;
		}
		$ids = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 10,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one exact lookup per clip.
				'meta_query'     => [
					[ 'key' => PageMaker::META_FROM, 'value' => self::EXTENSION_ID ],
					[ 'key' => self::META_OWNER, 'value' => (string) $personId ],
				],
			]
		);
		foreach ( $ids as $id ) {
			if ( ! ArtifactIndex::isArchived( (int) $id ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * The person's Read later page, made now when they have none: in their
	 * private scope, led by $actorId when it may write there, else by their
	 * default agent. A string says why there is none.
	 *
	 * @return int|string
	 */
	public static function ensureReadLater( int $personId, int $actorId ) {
		$package = Package::bundled( self::PACKAGE );
		if ( is_string( $package ) ) {
			return 'The bundled Read later package cannot be used: ' . $package;
		}

		$id = self::readLaterFor( $personId );
		if ( $id > 0 ) {
			if ( (string) get_post_meta( $id, PageMaker::META_VERSION, true ) !== $package->version() ) {
				PageRefresh::one( $id, $package, 'readlater' );
			}

			return $id;
		}

		$scopeId = Access::homeScope( $personId );
		if ( $scopeId <= 0 ) {
			return __( 'Saved. Your Read later list needs a private scope of your own; ask a curator for one.', 'tomatio' );
		}
		$scopes = new Scopes();
		$lead   = $scopes->canWrite( $actorId, $scopeId ) ? $actorId : TioDayDefault::lead( $personId );
		if ( $lead <= 0 || ! $scopes->canWrite( $lead, $scopeId ) ) {
			return __( 'Saved. No agent of yours can write to your private scope, so there is no Read later list yet.', 'tomatio' );
		}

		$made = PageMaker::make( $package, __( 'Read later', 'tomatio' ), $lead, $scopeId );
		if ( $made instanceof WP_Error ) {
			return __( 'Saved. The Read later list could not be made: ', 'tomatio' ) . $made->get_error_message();
		}
		update_post_meta( (int) $made, self::META_OWNER, (string) $personId );

		return (int) $made;
	}

	/**
	 * Add the clip to the person's Read later list, or refresh its
	 * document there. A string says why it is not on a list.
	 *
	 * @param array<string, mixed> $clip From clean().
	 * @return array{artifact_id:int, url:string, key:string, state:string, already_saved:bool}|string
	 */
	private static function remember( array $clip, int $personId, int $actorId, int $scopeId, int $memoryId, int $sourceId, string $now ) {
		$id = self::ensureReadLater( $personId, $actorId );
		if ( is_string( $id ) ) {
			return $id;
		}

		$key      = self::keyFor( $clip['url'] );
		$existing = StoreData::get( $id, self::COLLECTION, $key );
		$before   = is_array( $existing ) && is_array( $existing['value'] ?? null ) ? $existing['value'] : null;

		$memories = $before !== null ? array_values( array_map( 'intval', (array) ( $before['memory_ids'] ?? [] ) ) ) : [];
		$memories = array_slice( array_values( array_unique( array_merge( [ $memoryId ], $memories ) ) ), 0, self::MAX_MEMORIES );
		$tags     = $before !== null ? array_values( array_filter( (array) ( $before['tags'] ?? [] ), 'is_string' ) ) : [];
		$tags     = array_slice( array_values( array_unique( array_merge( $clip['tags'], $tags ) ) ), 0, self::MAX_TAGS );
		$state    = $before !== null && in_array( $before['state'] ?? '', self::STATES, true ) ? (string) $before['state'] : 'unread';
		// Clipping an archived page again brings it back to the list.
		$state = $state === 'archived' ? 'unread' : $state;

		$value = [
			'url'        => $clip['url'],
			'title'      => $clip['title'],
			'site'       => $clip['site'],
			'author'     => $clip['author'],
			'published'  => $clip['published'],
			'mode'       => $clip['mode'],
			'note'       => $clip['note'] !== '' ? $clip['note'] : (string) ( $before['note'] ?? '' ),
			'excerpt'    => $clip['markdown'] !== '' || $clip['note'] !== '' ? self::excerpt( $clip['markdown'], $clip['note'] ) : (string) ( $before['excerpt'] ?? '' ),
			'tags'       => $tags,
			'scope_id'   => $scopeId,
			'state'      => $state,
			'saved_at'   => (string) ( $before['saved_at'] ?? $now ),
			'clipped_at' => $now,
			'clips'      => (int) ( $before['clips'] ?? 0 ) + 1,
			'memory_ids' => $memories,
			'source_id'  => $sourceId,
		];
		if ( $state === 'read' && isset( $before['read_at'] ) && is_string( $before['read_at'] ) ) {
			$value['read_at'] = $before['read_at'];
		}

		$done = StoreData::set( $id, self::COLLECTION, $key, $value, StoreData::attributionFromUser( $actorId, $id ) );
		if ( $done instanceof WP_Error ) {
			return __( 'Saved, but not added to your Read later list: ', 'tomatio' ) . $done->get_error_message();
		}

		return [ 'artifact_id' => $id, 'url' => MembersAddress::url( $id ), 'key' => $key, 'state' => $state, 'already_saved' => $before !== null ];
	}

	/**
	 * Where a clip goes when no scope is named: the person's private scope
	 * when the writer may write it, else the writer's only writable scope.
	 */
	public static function defaultScope( int $actorId, int $personId ): int {
		$scopes = new Scopes();
		foreach ( array_filter( [ $personId, $actorId ] ) as $owner ) {
			$home = Access::homeScope( (int) $owner );
			if ( $home > 0 && $scopes->canWrite( $actorId, $home ) ) {
				return $home;
			}
		}
		$writable = $scopes->writeScopeIds( $actorId );

		return count( $writable ) === 1 ? (int) $writable[0] : 0;
	}

	/** @param array<int, int> $writable */
	private static function scopesHint( array $writable ): string {
		if ( $writable === [] ) {
			return __( 'This connection cannot write to any scope, so it cannot save clips.', 'tomatio' );
		}
		$names = [];
		foreach ( $writable as $scopeId ) {
			$term    = get_term( (int) $scopeId );
			$names[] = ( $term instanceof \WP_Term ? $term->name : 'scope' ) . ' (' . (int) $scopeId . ')';
		}

		return sprintf( __( 'Pick a scope_id you can write to: %s.', 'tomatio' ), implode( ', ', $names ) );
	}

	private static function line( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'ab_invalid_input', $message, [ 'status' => 400 ] );
	}
}
