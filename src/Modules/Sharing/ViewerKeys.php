<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * Signing keys for ViewerToken (ART-24, #164). Same construction as
 * EmbedKeys, deliberately a separate ring: each key is derived from
 * wp_salt() plus a random secret stored in a non-autoloaded option,
 * addressed by key id so a rotation keeps tokens signed with the previous
 * key valid until they expire (at most 20 minutes); the next rotation
 * drops it. The derivation context differs so a viewer token can never
 * verify as an embed token or vice versa.
 */
final class ViewerKeys {

	public const OPT_SECRETS = 'ab_viewer_secrets';
	public const OPT_CURRENT = 'ab_viewer_key_id';

	/**
	 * @return array{0:string,1:string} [kid, derived key] used for signing.
	 */
	public static function current(): array {
		$secrets = self::secrets();
		$kid     = (string) get_option( self::OPT_CURRENT, '' );

		if ( $kid === '' || ! isset( $secrets[ $kid ] ) ) {
			$kid     = self::rotate();
			$secrets = self::secrets();
		}

		return [ $kid, self::derive( $secrets[ $kid ] ) ];
	}

	/**
	 * @return array<string, string> kid => derived key, every key accepted for verification.
	 */
	public static function all(): array {
		$out = [];
		foreach ( self::secrets() as $kid => $secret ) {
			$out[ $kid ] = self::derive( $secret );
		}

		return $out;
	}

	/**
	 * Adds a fresh key, makes it current and keeps only the previous one.
	 *
	 * @return string The new key id.
	 */
	public static function rotate(): string {
		$secrets = self::secrets();
		$old     = (string) get_option( self::OPT_CURRENT, '' );
		$kid     = bin2hex( random_bytes( 4 ) );

		$keep = [];
		if ( $old !== '' && isset( $secrets[ $old ] ) ) {
			$keep[ $old ] = $secrets[ $old ];
		}
		$keep[ $kid ] = bin2hex( random_bytes( 32 ) );

		update_option( self::OPT_SECRETS, $keep, false );
		update_option( self::OPT_CURRENT, $kid, false );

		return $kid;
	}

	/**
	 * @return array<string, string>
	 */
	private static function secrets(): array {
		$stored = get_option( self::OPT_SECRETS, [] );
		$out    = [];
		foreach ( is_array( $stored ) ? $stored : [] as $kid => $secret ) {
			if ( is_string( $secret ) && $secret !== '' ) {
				$out[ (string) $kid ] = $secret;
			}
		}

		return $out;
	}

	private static function derive( string $secret ): string {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) . '|ab_viewer_token', true );
	}
}
