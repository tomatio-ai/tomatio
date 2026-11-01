<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * GET|POST https://{ab_preview_host}/share/{artifactId}/{token}/viewer.json
 * (ART-24, #164)
 *
 * The viewer endpoint a sandboxed artifact page calls (connect-src 'self'
 * already allows it). It is reached only after ShareRoute validated the
 * share link (token, IP binding, expiry, revocation, approval), so this
 * class only decides identity:
 *
 * - viewer identity off for the artifact, an anonymous link, or no code
 *   presented: {"viewer":{"anonymous":true}}
 * - a code presented: consumed exactly once (60 s, single use); on success
 *   the response carries the signed short-lived ViewerToken and the viewer
 *   descriptor; a presented-but-invalid code is the uniform 404
 *
 * The page receives the code out of band — the issuing member appends it to
 * the share URL as the fragment #ab_viewer_code=..., which no server and no
 * access log ever sees — and POSTs it here in the request body. A GET with
 * ?code= works too (mirroring the embed exchange) for clients that cannot
 * POST. The response is readable from the page's opaque origin
 * (Access-Control-Allow-Origin: *, no credentials).
 *
 * The token travels in the page's memory only: never a cookie, never shared
 * with the main site. Sibling endpoints (ART-23 data store, ART-25
 * brain-read, ART-27 uploads) verify it with ViewerToken::verify() +
 * ViewerKeys::all() and read the viewer with the claims directly.
 */
final class ViewerRoute {

	public const PATH = 'viewer.json';

	public const FRAGMENT_PARAM = 'ab_viewer_code';

	/**
	 * #219: the fragment parameter the ticket exchange redirects with — the
	 * signed ViewerToken, read from location.hash by the page's scripts
	 * (never sent to any server, never in an access log).
	 */
	public const TOKEN_FRAGMENT_PARAM = 'ab_viewer_token';

	/**
	 * @return array{artifact_id:int,token:string}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/share/(\d+)/([^/]+)/' . preg_quote( self::PATH, '#' ) . '$#', $path, $m ) !== 1 ) {
			return null;
		}

		return [
			'artifact_id' => (int) $m[1],
			'token'       => rawurldecode( $m[2] ),
		];
	}

	/**
	 * @param array{artifact_id:int,token:string} $route Validated route context.
	 * @param array<string, mixed>                $link  The validated share link row.
	 * @param string|null                         $body  Raw request body (POST), when already read.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respond( array $route, array $link, string $method, string $uri, ?string $body = null, ?int $now = null ): array {
		$now = $now ?? time();

		if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD', 'POST' ], true ) ) {
			return ShareRoute::notFound();
		}

		$artifactId = $route['artifact_id'];
		$headers    = self::headers();

		// Identity is available only for members links of an artifact with
		// viewer identity enabled; everything else answers anonymous.
		if ( ! ViewerMode::isActive( $artifactId )
			|| ShareLinks::normalizeViewerMode( (string) ( $link['viewer_mode'] ?? '' ) ) !== ShareLinks::VIEWER_MEMBERS
		) {
			return self::json( 200, [ 'viewer' => ViewerIdentity::anonymous() ], $headers );
		}

		$code = self::presentedCode( $method, $uri, $body );
		if ( $code === null ) {
			return self::json( 200, [ 'viewer' => ViewerIdentity::anonymous() ], $headers );
		}

		$row = ViewerExchange::consume( $code, $now );
		if ( $row === null || $row['artifact_id'] !== $artifactId || $row['share_link_id'] !== (int) $link['id'] ) {
			return ShareRoute::notFound();
		}

		$viewer = ViewerIdentity::describe( $row['user_id'], $artifactId );
		if ( $viewer === null ) {
			return ShareRoute::notFound();
		}

		[ $kid, $key ] = ViewerKeys::current();
		$claims        = ViewerToken::claims( (int) $link['id'], $artifactId, $viewer['principal_id'], $viewer['principal_type'], $viewer['name'], $viewer['roles'], $now );
		$token         = ViewerToken::sign( $claims, $kid, $key );

		ShareLinks::audit( $viewer['principal_id'], 'viewer_token_issued', 'ok', (int) $link['id'], [ 'artifact_id' => $artifactId, 'principal_type' => $viewer['principal_type'] ] );

		return self::json(
			200,
			[
				'viewer'       => $viewer,
				'viewer_token' => $token,
				'expires_in'   => ViewerToken::TTL,
			],
			$headers
		);
	}

	/**
	 * The code from the POST body ({"code":"..."}) or the GET query string.
	 */
	private static function presentedCode( string $method, string $uri, ?string $body ): ?string {
		if ( strtoupper( $method ) === 'POST' ) {
			$raw = $body;
			if ( $raw === null ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- no WP filesystem API for php://input; read-only.
				$raw = file_get_contents( 'php://input' );
			}
			$decoded = json_decode( is_string( $raw ) ? $raw : '', true );
			if ( is_array( $decoded ) && isset( $decoded['code'] ) && is_string( $decoded['code'] ) ) {
				return $decoded['code'];
			}
		}

		$query = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );

		return isset( $query['code'] ) && is_string( $query['code'] ) ? $query['code'] : null;
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
	 * @param array<string, mixed> $data
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
