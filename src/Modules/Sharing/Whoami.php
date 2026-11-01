<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * #285: the install's proof-of-identity for the preview-host reachability
 * check. Each install keeps a random id (an option, created once); the share
 * route answers `GET /share/_whoami` on the preview host with a short-lived
 * HMAC of the current timestamp keyed by that id. A settings save or a mint
 * fetches that answer and compares it here, so a host that routes to a
 * different install — even one running this plugin — can never verify. The
 * signature reveals nothing without the id and expires after a few minutes.
 */
final class Whoami {

	public const OPTION = 'ab_install_id';

	public const PATH = '/share/_whoami';

	private const MAX_SKEW_SECONDS = 600;

	public static function installId(): string {
		$id = (string) get_option( self::OPTION, '' );
		if ( $id === '' ) {
			add_option( self::OPTION, bin2hex( random_bytes( 32 ) ), '', false );
			$id = (string) get_option( self::OPTION, '' );
		}

		return $id;
	}

	/**
	 * `{unix timestamp}.{hmac}` — valid only within MAX_SKEW_SECONDS of now.
	 */
	public static function payload( ?int $now = null ): string {
		$ts = (string) ( $now ?? time() );

		return $ts . '.' . hash_hmac( 'sha256', $ts, self::installId() );
	}

	public static function valid( string $value, ?int $now = null ): bool {
		$parts = explode( '.', trim( $value ) );
		if ( count( $parts ) !== 2 || ! ctype_digit( $parts[0] ) ) {
			return false;
		}

		$ts  = (int) $parts[0];
		$now = $now ?? time();
		if ( abs( $now - $ts ) > self::MAX_SKEW_SECONDS ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', (string) $ts, self::installId() ), $parts[1] );
	}
}
