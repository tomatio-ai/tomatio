<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * #391: where the private-link sign-in round trip (#219) comes back to,
 * without the share token ever travelling to the main site.
 *
 * The share address (ShareRoute) stores the page path of the link under a
 * random handle and sends the viewer to the main site with only the link
 * id and the handle; ShareAuth looks the path up after sign-in and uses the
 * handle once, when it mints the ticket. Both hosts are the same install
 * and database, so the path never has to be in a URL the main site
 * receives — its address bar, access log and wp-login redirect_to see a
 * meaningless handle. Stored as a transient keyed by the handle's sha256,
 * for TTL seconds (long enough for a sign-in, a password reset included).
 */
final class ShareReturn {

	public const QUERY_PARAM = 'ab_return';

	public const TTL = 1800;

	private const PREFIX = 'ab_share_return_';

	/**
	 * A new handle for returning to $back on link $linkId.
	 */
	public static function create( int $linkId, string $back ): string {
		$handle = rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' );
		set_transient( self::key( $handle ), [ 'link_id' => $linkId, 'back' => $back ], self::TTL );

		return $handle;
	}

	/**
	 * The path stored under $handle for link $linkId, or null (unknown,
	 * expired, used, or another link's handle).
	 */
	public static function peek( string $handle, int $linkId ): ?string {
		if ( preg_match( '/^[A-Za-z0-9_-]{32}$/', $handle ) !== 1 ) {
			return null;
		}

		$stored = get_transient( self::key( $handle ) );
		if ( ! is_array( $stored ) || (int) ( $stored['link_id'] ?? 0 ) !== $linkId || ! is_string( $stored['back'] ?? null ) ) {
			return null;
		}

		return $stored['back'];
	}

	/**
	 * Uses the handle up: one sign-in, one return.
	 */
	public static function consume( string $handle ): void {
		if ( preg_match( '/^[A-Za-z0-9_-]{32}$/', $handle ) === 1 ) {
			delete_transient( self::key( $handle ) );
		}
	}

	private static function key( string $handle ): string {
		return self::PREFIX . hash( 'sha256', $handle );
	}
}
