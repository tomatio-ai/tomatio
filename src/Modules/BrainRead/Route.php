<?php

declare(strict_types=1);

namespace AgentBrain\Modules\BrainRead;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Sharing\Config;
use AgentBrain\Modules\Sharing\RateLimiter;
use AgentBrain\Modules\Sharing\ShareRoute;
use AgentBrain\Modules\Sharing\ViewerIdentity;
use AgentBrain\Modules\Sharing\ViewerKeys;
use AgentBrain\Modules\Sharing\ViewerToken;
use Throwable;

/**
 * GET https://{ab_preview_host}/share/{artifactId}/{token}/brain/search?q=&limit=&viewer_token=
 * GET https://{ab_preview_host}/share/{artifactId}/{token}/brain/get?id=&kind=&viewer_token=
 * (ART-25, #165)
 *
 * The read-only brain-data endpoints a sandboxed artifact page calls
 * (connect-src 'self' already allows them). Reached from ShareRoute only
 * after the share link passed token/IP/expiry/revocation/approval
 * validation, so this class only decides the grant and the read:
 *
 * - feature off for the artifact, or no scope on the artifact: the uniform
 *   404 (the path is intercepted either way, so it can never be used to
 *   fetch the artifact's HTML);
 * - a viewer_token presented: verified with ViewerToken::verify() and
 *   matched to this link + artifact; a presented-but-invalid token is the
 *   uniform 404 (ART-24's invalid-code rule). The viewer's read grants then
 *   apply, intersected with the artifact's scope (Grants::resolve) — a
 *   member without a read grant on the artifact's scope gets empty results,
 *   never an existence-probing error;
 * - no token: anonymous, and the artifact's share policy applies — the
 *   artifact's scope, read-only (docs/design-art-25-brain-read.md).
 *
 * The response shape mirrors ViewerRoute: JSON, CORS * without credentials,
 * no-store, nosniff. Content goes out wrapped as DATA (Items/ Untrusted::
 * wrap) so stored brain content can never prompt-inject the page or a
 * downstream consumer. Rate limit: the outer share limiter already ran in
 * ShareRoute; this class applies its own check on the same fixed-window
 * counter (each brain request also consumed one outer hit, so the shared
 * budget caps brain reads at ~30/min/link/IP).
 */
final class Route {

	public const PATH_SEARCH = 'brain/search';

	public const PATH_GET = 'brain/get';

	private const VALID_PATHS = [ self::PATH_SEARCH, self::PATH_GET ];

	/**
	 * @return array{artifact_id:int,token:string,path:string}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/share/(\d+)/([^/]+)/(' . implode( '|', array_map( static fn ( string $p ): string => preg_quote( $p, '#' ), self::VALID_PATHS ) ) . ')$#', $path, $m ) !== 1 ) {
			return null;
		}

		return [
			'artifact_id' => (int) $m[1],
			'token'       => rawurldecode( $m[2] ),
			'path'        => $m[3],
		];
	}

	/**
	 * @param array{artifact_id:int,token:string,path:string} $route Validated route context.
	 * @param array<string, mixed>                            $link  The validated share link row.
	 * @param array<string, mixed>|null                       $server $_SERVER-shaped, for the rate-limit IP.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respond( array $route, array $link, string $method, string $uri, ?string $body = null, ?int $now = null, ?array $server = null ): array {
		$now = $now ?? time();

		if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD', 'POST' ], true ) ) {
			return ShareRoute::notFound();
		}

		$ip = Config::clientIp( $server );
		if ( ! RateLimiter::allow( $ip !== '' ? Config::ipHash( $ip ) : str_repeat( '0', 64 ), (int) ( $link['id'] ?? 0 ), $now, RateLimiter::PER_MINUTE ) ) {
			$response                           = ShareRoute::notFound();
			$response['status']                 = 429;
			$response['headers']['Retry-After'] = '60';

			return $response;
		}

		$artifactId = $route['artifact_id'];
		$headers    = self::headers();

		// Intercepted either way: with the feature off the path is still a
		// 404, never a way to render the artifact's HTML.
		if ( ! Mode::isActive( $artifactId ) ) {
			return ShareRoute::notFound();
		}

		$scopeId = (int) get_post_meta( $artifactId, Cpts::META_SCOPE_ID, true );
		if ( $scopeId <= 0 ) {
			return ShareRoute::notFound();
		}

		[ 'grants' => $grants, 'viewer' => $viewer ] = self::resolveViewer( $method, $uri, $body, $link, $artifactId, $scopeId, $now );
		if ( $grants === null ) {
			// A presented-but-invalid viewer token: the uniform 404.
			return ShareRoute::notFound();
		}

		$search  = new Search();
		$allowed = $grants['allowed_scope_ids'];

		if ( $route['path'] === self::PATH_SEARCH ) {
			$params  = self::params( $method, $uri, $body );
			$results = $search->search( (string) ( $params['q'] ?? '' ), $allowed, (int) ( $params['limit'] ?? Search::DEFAULT_LIMIT ) );

			return self::json(
				200,
				[
					'viewer'        => $viewer,
					'scope_granted' => $grants['scope_granted'],
					'results'       => $results,
				],
				$headers
			);
		}

		$params = self::params( $method, $uri, $body );
		$kind   = (string) ( $params['kind'] ?? Search::KIND_MEMORY );
		$item   = $search->get( (int) ( $params['id'] ?? 0 ), $kind, $allowed );
		if ( $item === null ) {
			// Unknown id, wrong kind, not active/approved, or out of scope —
			// one uniform 404 so ids can never be probed.
			return ShareRoute::notFound();
		}

		return self::json(
			200,
			[
				'viewer'        => $viewer,
				'scope_granted' => $grants['scope_granted'],
				'item'          => $item,
			],
			$headers
		);
	}

	/**
	 * The presented viewer, or anonymous. Returns grants === null when a
	 * viewer_token was presented but does not verify for this link+artifact.
	 *
	 * @param array<string, mixed> $link
	 * @return array{grants:array{allowed_scope_ids:array<int,int>,scope_granted:bool,anonymous:bool}|null, viewer:array<string, mixed>}
	 */
	private static function resolveViewer( string $method, string $uri, ?string $body, array $link, int $artifactId, int $scopeId, int $now ): array {
		$presented = self::presentedToken( $method, $uri, $body );

		if ( $presented === null ) {
			return [
				'grants' => Grants::resolve( null, $scopeId ),
				'viewer' => ViewerIdentity::anonymous(),
			];
		}

		$claims = ViewerToken::verify( $presented, ViewerKeys::all(), $now );
		if ( $claims === null
			|| $claims['artifact_id'] !== $artifactId
			|| $claims['share_link_id'] !== (int) ( $link['id'] ?? 0 )
		) {
			return [ 'grants' => null, 'viewer' => ViewerIdentity::anonymous() ];
		}

		// Fail closed exactly like Recall: any error in scope resolution, or
		// no read scopes at all, resolves to "no access".
		try {
			$readScopeIds = ( new Scopes() )->readScopeIds( $claims['principal_id'] );
		} catch ( Throwable $e ) {
			$readScopeIds = [];
		}

		return [
			'grants' => Grants::resolve( $readScopeIds, $scopeId ),
			'viewer' => [
				'principal_id'   => $claims['principal_id'],
				'principal_type' => $claims['principal_type'],
				'name'           => $claims['name'],
				'roles'          => $claims['roles'],
			],
		];
	}

	/**
	 * The viewer token from the POST body ({"viewer_token":"..."}) or the
	 * GET query string — the same two channels ViewerRoute offers for the
	 * exchange code.
	 */
	private static function presentedToken( string $method, string $uri, ?string $body ): ?string {
		$params = self::params( $method, $uri, $body );

		return isset( $params['viewer_token'] ) && is_string( $params['viewer_token'] ) && $params['viewer_token'] !== '' ? $params['viewer_token'] : null;
	}

	/**
	 * Query params, merged with the POST JSON body (body wins) when present.
	 *
	 * @return array<string, mixed>
	 */
	private static function params( string $method, string $uri, ?string $body ): array {
		$query = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );

		if ( strtoupper( $method ) === 'POST' ) {
			$raw = $body;
			if ( $raw === null ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- no WP filesystem API for php://input; read-only.
				$raw = file_get_contents( 'php://input' );
			}
			$decoded = json_decode( is_string( $raw ) ? $raw : '', true );
			if ( is_array( $decoded ) ) {
				$query = array_merge( $query, $decoded );
			}
		}

		return $query;
	}

	/**
	 * @return array<string, string>
	 */
	private static function headers(): array {
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
}
