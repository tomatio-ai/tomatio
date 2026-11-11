<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * GET https://{share address}/share/{artifactId}/embed[?code=...] (#59).
 *
 * With ?code= : consume the single-use exchange code, set the session cookie
 * (HttpOnly, Secure, SameSite=None, Partitioned, Path=/share/{id}/, host-only)
 * and 302 to the clean URL. Without a code: authenticate by that cookie and
 * serve the artifact. Every render re-checks the link (revoked, expired,
 * approved, IP binding), the artifact, and the embedding origin. Every
 * failure is the uniform 404. Plain (non-embed) links never take this path.
 */
final class EmbedRoute {

	public const COOKIE_PREFIX = 'ab_embed_';

	/**
	 * @return int|null The artifact id when the path is the embed route.
	 */
	public static function parsePath( string $uri ): ?int {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		return preg_match( '#^/share/(\d+)/embed/?$#', $path, $m ) === 1 ? (int) $m[1] : null;
	}

	/**
	 * @param array<string, mixed> $server $_SERVER-shaped.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function handle( int $artifactId, string $uri, string $method, array $server, ?int $now = null ): array {
		$now = $now ?? time();

		if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) || Config::disabled() ) {
			return ShareRoute::notFound();
		}

		$ip       = Config::clientIp( $server );
		$clientIp = $ip !== '' ? Config::ipHash( $ip ) : '';
		if ( ! RateLimiter::allow( $clientIp !== '' ? $clientIp : str_repeat( '0', 64 ), 0 ) ) {
			$response                           = ShareRoute::notFound();
			$response['status']                 = 429;
			$response['headers']['Retry-After'] = '60';

			return $response;
		}

		$query = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );

		if ( isset( $query['code'] ) ) {
			return is_string( $query['code'] ) ? self::exchange( $artifactId, $query['code'], $clientIp, $server, $now ) : ShareRoute::notFound();
		}

		return self::render( $artifactId, $clientIp, $server, $now );
	}

	/**
	 * @param array<string, mixed> $server
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function exchange( int $artifactId, string $code, string $clientIp, array $server, int $now ): array {
		$row = EmbedExchange::consume( $code, $now );
		if ( $row === null || $row['artifact_id'] !== $artifactId ) {
			return ShareRoute::notFound();
		}

		$link = self::liveEmbedLink( $row['share_link_id'], $artifactId, $clientIp, $now );
		if ( $link === null || ! self::originOk( $row['origin'], $server ) ) {
			ShareLinks::audit( 0, 'embed_exchange_denied', 'denied', $row['share_link_id'], [] );

			return ShareRoute::notFound();
		}

		[ $kid, $key ] = EmbedKeys::current();
		$claims        = EmbedToken::claims( (int) $link['id'], $artifactId, $row['origin'], $now );
		$token         = EmbedToken::sign( $claims, $kid, $key );

		ShareLinks::audit( 0, 'embed_exchanged', 'ok', (int) $link['id'], [ 'artifact_id' => $artifactId ] );

		$headers                = ShareRoute::securityHeaders( false, EmbedSettings::allowedOrigins() );
		$headers['Set-Cookie']  = self::cookie( $artifactId, $token, EmbedToken::TTL );
		$headers['Location']    = sprintf( '%s/share/%d/embed', ShareLinks::baseUrl(), $artifactId );

		return [ 'status' => 302, 'headers' => $headers, 'body' => '' ];
	}

	/**
	 * @param array<string, mixed> $server
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function render( int $artifactId, string $clientIp, array $server, int $now ): array {
		$cookies = self::parseCookies( (string) ( $server['HTTP_COOKIE'] ?? '' ) );
		$token   = $cookies[ self::COOKIE_PREFIX . $artifactId ] ?? '';
		$claims  = $token !== '' ? EmbedToken::verify( $token, EmbedKeys::all(), $now ) : null;

		if ( $claims === null || $claims['artifact_id'] !== $artifactId ) {
			return ShareRoute::notFound();
		}

		// Re-check on every render: link state, artifact visibility, embedding origin.
		$link = self::liveEmbedLink( $claims['share_link_id'], $artifactId, $clientIp, $now );
		if ( $link === null || ! self::originOk( $claims['origin'], $server ) ) {
			return ShareRoute::notFound();
		}

		$interactive = Interactive::isActive( $artifactId );
		$body        = ShareRoute::renderArtifact( $artifactId, $interactive );
		if ( $body === null ) {
			return ShareRoute::notFound();
		}

		ShareLinks::recordOpen( (int) $link['id'] );

		return [
			'status'  => 200,
			'headers' => ShareRoute::securityHeaders( $interactive, EmbedSettings::allowedOrigins() ),
			'body'    => $body,
		];
	}

	/**
	 * The link when it is an embed link of this artifact and still usable.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function liveEmbedLink( int $linkId, int $artifactId, string $clientIp, int $now ): ?array {
		$link = ShareLinks::find( $linkId );
		if ( $link === null || (int) $link['artifact_id'] !== $artifactId || EmbedPolicy::normalizeKind( (string) ( $link['kind'] ?? '' ) ) !== EmbedPolicy::KIND_EMBED ) {
			return null;
		}

		if ( ! ShareLinks::isServable( get_post( $artifactId ) ) ) {
			return null;
		}

		$result = ShareLinks::evaluate( $link, $clientIp, $now );

		return $result['status'] === ShareLinks::STATUS_OK ? $link : null;
	}

	/**
	 * @param array<string, mixed> $server
	 */
	private static function originOk( string $boundOrigin, array $server ): bool {
		return EmbedPolicy::embeddingOriginOk(
			$boundOrigin,
			EmbedSettings::allowedOrigins(),
			(string) ( $server['HTTP_ORIGIN'] ?? '' ),
			(string) ( $server['HTTP_REFERER'] ?? '' ),
			(string) ( $server['HTTP_SEC_FETCH_DEST'] ?? '' )
		);
	}

	public static function cookie( int $artifactId, string $token, int $maxAge ): string {
		return sprintf(
			'%s%d=%s; Max-Age=%d; Path=/share/%d/; Secure; HttpOnly; SameSite=None; Partitioned',
			self::COOKIE_PREFIX,
			$artifactId,
			$token,
			$maxAge,
			$artifactId
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function parseCookies( string $header ): array {
		$out = [];
		foreach ( explode( ';', $header ) as $pair ) {
			$parts = explode( '=', $pair, 2 );
			if ( count( $parts ) === 2 && trim( $parts[0] ) !== '' ) {
				$out[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}

		return $out;
	}
}
