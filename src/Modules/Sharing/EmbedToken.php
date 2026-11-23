<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * Short-lived embed session token (pure, no WordPress): HMAC-SHA256 over a
 * JSON claim set, carrying a key id so signing keys can rotate.
 *
 * Format: v1.<kid>.<base64url(claims json)>.<base64url(hmac)>
 * Claims: share_link_id, artifact_id, origin, exp (unix, at most 20 minutes
 * ahead), nonce. The token only ever travels in a cookie, never in a URL.
 */
final class EmbedToken {

	public const TTL     = 1200; // 20 minutes.
	public const VERSION = 'v1';

	/**
	 * @return array{share_link_id:int,artifact_id:int,origin:string,exp:int,nonce:string}
	 */
	public static function claims( int $shareLinkId, int $artifactId, string $origin, int $now, int $ttl = self::TTL ): array {
		return [
			'share_link_id' => $shareLinkId,
			'artifact_id'   => $artifactId,
			'origin'        => $origin,
			'exp'           => $now + max( 1, min( $ttl, self::TTL ) ),
			'nonce'         => bin2hex( random_bytes( 12 ) ),
		];
	}

	/**
	 * @param array<string, mixed> $claims
	 */
	public static function sign( array $claims, string $kid, string $key ): string {
		$payload = self::b64( (string) json_encode( $claims ) );
		$signed  = self::VERSION . '.' . $kid . '.' . $payload;

		return $signed . '.' . self::b64( hash_hmac( 'sha256', $signed, $key, true ) );
	}

	/**
	 * Verifies signature (constant time), expiry and the 20 minute ceiling.
	 *
	 * @param array<string, string> $keys kid => key.
	 * @return array{share_link_id:int,artifact_id:int,origin:string,exp:int,nonce:string}|null
	 */
	public static function verify( string $token, array $keys, int $now ): ?array {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 4 || $parts[0] !== self::VERSION || ! isset( $keys[ $parts[1] ] ) ) {
			return null;
		}

		$signed   = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
		$expected = self::b64( hash_hmac( 'sha256', $signed, (string) $keys[ $parts[1] ], true ) );
		if ( ! hash_equals( $expected, $parts[3] ) ) {
			return null;
		}

		$json   = base64_decode( strtr( $parts[2], '-_', '+/' ), true );
		$claims = $json !== false ? json_decode( $json, true ) : null;
		if ( ! is_array( $claims ) ) {
			return null;
		}

		foreach ( [ 'share_link_id', 'artifact_id', 'exp' ] as $int ) {
			if ( ! isset( $claims[ $int ] ) || ! is_int( $claims[ $int ] ) ) {
				return null;
			}
		}
		foreach ( [ 'origin', 'nonce' ] as $string ) {
			if ( ! isset( $claims[ $string ] ) || ! is_string( $claims[ $string ] ) || $claims[ $string ] === '' ) {
				return null;
			}
		}

		if ( $claims['exp'] <= $now || $claims['exp'] - $now > self::TTL ) {
			return null;
		}

		return [
			'share_link_id' => $claims['share_link_id'],
			'artifact_id'   => $claims['artifact_id'],
			'origin'        => $claims['origin'],
			'exp'           => $claims['exp'],
			'nonce'         => $claims['nonce'],
		];
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
