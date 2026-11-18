<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Preview;

/**
 * #867: the share address knows the signed-in member.
 *
 * When share links live on their own address (a preview host: every brain
 * TomaTío hosts, or a team's own subdomain, #800), the brain's WordPress
 * cookies never reach it, and must not: sharing them across the whole
 * domain would hand them to every other host under it. So the brain tells
 * the share address who someone is:
 *
 * 1. While someone is signed in, the brain's own pages (wp-admin, at most
 *    every 12 hours per browser, and the members address) load a 1x1 image
 *    from `{share address}/share/_member?t={claim}`. The claim is signed
 *    with this install's secret, names the share host and lives 60 seconds.
 * 2. The share address answers it with a host-only, HttpOnly, Secure,
 *    SameSite=Lax cookie on `/share/`: a second signed claim holding the user
 *    id, a hash of the person's WordPress session token and that session's
 *    expiry (at most 14 days).
 * 3. On a share page, the cookie is checked against the live WordPress
 *    session every time, so signing out of the brain ends it at once. It
 *    never makes the request signed in: share pages still render logged
 *    out. It only tells ShareRoute who the member is, for the Edit button
 *    (ShareEditor) and to open a private link without the sign-in round trip.
 *
 * Browsers only let the brain's page set this cookie when the share address
 * is under the same site as the brain (share.example.com next to
 * brain.example.com, or *.tomatio.ai). Anywhere else nothing changes: share
 * pages stay signed out, as before.
 */
final class ShareMember {

	public const PATH = '/share/_member';

	public const COOKIE = 'ab_share_member';

	/** The main-site cookie that spaces out the wp-admin beacon. */
	public const BEACON_COOKIE = 'ab_share_member_beacon';

	public const BEACON_EVERY = 43200; // 12 hours.

	public const TICKET_TTL = 60;

	public const MAX_AGE = 1209600; // 14 days.

	private const VERSION = 1;

	private const TICKET = 't';

	private const SESSION = 'c';

	/** A transparent 1x1 GIF. */
	private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'planBeacon' ] );
		add_action( 'admin_footer', [ self::class, 'printBeacon' ] );
	}

	/** Whether share links live on an address of their own, where the brain's session does not reach. */
	public static function applies(): bool {
		$host = Preview::configuredHost();

		return $host !== '' && ! Preview::isSiteHost( $host );
	}

	/**
	 * The beacon image URL for this person and session, or '' when there is
	 * nothing to tell the share address.
	 */
	public static function beaconUrl( int $userId, string $sessionToken, int $now ): string {
		if ( $userId <= 0 || $sessionToken === '' || ! self::applies() ) {
			return '';
		}

		$expiration = self::sessionExpiration( $userId, $sessionToken, $now );
		if ( $expiration === 0 ) {
			return '';
		}

		$claim = self::sign( self::TICKET, $userId, hash( 'sha256', $sessionToken ), strtolower( Preview::configuredHost() ), $now + self::TICKET_TTL );

		return ShareLinks::baseUrl() . self::PATH . '?t=' . rawurlencode( $claim );
	}

	/** The beacon as an image tag for the signed-in person of this request, or ''. */
	public static function beaconTag( int $userId ): string {
		$url = self::beaconUrl( $userId, (string) wp_get_session_token(), time() );
		if ( $url === '' ) {
			return '';
		}

		return '<img src="' . esc_url( $url ) . '" alt="" width="1" height="1" referrerpolicy="no-referrer" style="position:absolute;width:1px;height:1px;left:-9999px;top:0" />';
	}

	/** The share origin a page with the beacon must allow in img-src, or ''. */
	public static function beaconOrigin(): string {
		return self::applies() ? ShareLinks::baseUrl() : '';
	}

	/** @var string The beacon for this wp-admin request, decided on admin_init. */
	private static string $planned = '';

	/**
	 * wp-admin: at most every 12 hours per browser and session, tell the share
	 * address who is signed in. A main-site cookie remembers the last one.
	 */
	public static function planBeacon(): void {
		self::$planned = '';
		$userId        = get_current_user_id();
		$token         = (string) wp_get_session_token();
		if ( $userId <= 0 || $token === '' || ! self::applies() || wp_doing_ajax() ) {
			return;
		}

		$mark = substr( hash( 'sha256', hash( 'sha256', $token ) . '|' . strtolower( Preview::configuredHost() ) ), 0, 20 );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with hash_equals only.
		$seen = isset( $_COOKIE[ self::BEACON_COOKIE ] ) && is_string( $_COOKIE[ self::BEACON_COOKIE ] ) ? (string) $_COOKIE[ self::BEACON_COOKIE ] : '';
		if ( $seen !== '' && hash_equals( $mark, $seen ) ) {
			return;
		}

		self::$planned = self::beaconTag( $userId );
		if ( self::$planned !== '' && ! headers_sent() ) {
			setcookie(
				self::BEACON_COOKIE,
				$mark,
				[
					'expires'  => time() + self::BEACON_EVERY,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}
	}

	public static function printBeacon(): void {
		echo self::$planned; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by beaconTag().
	}

	/**
	 * The share address's answer to the beacon: the member cookie, or the
	 * uniform 404 for anything else. Null when this is not that request.
	 *
	 * @return array{status:int,headers:array<string,string>,body:string}|null
	 */
	public static function respond( string $host, string $uri, string $method, int $now ): ?array {
		if ( (string) wp_parse_url( $uri, PHP_URL_PATH ) !== self::PATH ) {
			return null;
		}

		if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) || ! Preview::isPreviewHost( $host ) ) {
			return ShareRoute::notFound();
		}

		$query = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		$claim = isset( $query['t'] ) && is_string( $query['t'] ) ? $query['t'] : '';
		$data  = self::verify( $claim, self::TICKET, $host, $now );
		if ( $data === null ) {
			return ShareRoute::notFound();
		}

		$expires = min( $data['session_expires'], $now + self::MAX_AGE );
		$value   = self::sign( self::SESSION, $data['user_id'], $data['session'], $data['host'], $expires );
		$cookie  = self::COOKIE . '=' . rawurlencode( $value )
			. '; Path=/share/; Max-Age=' . max( 1, $expires - $now ) . '; HttpOnly; SameSite=Lax';
		if ( wp_parse_url( ShareLinks::baseUrl(), PHP_URL_SCHEME ) === 'https' ) {
			$cookie .= '; Secure';
		}

		$headers                  = ShareRoute::securityHeaders();
		$headers['Content-Type']  = 'image/gif';
		$headers['Cache-Control'] = 'private, no-store';
		$headers['Set-Cookie']    = $cookie;

		return [
			'status'  => 200,
			'headers' => $headers,
			'body'    => (string) base64_decode( self::PIXEL, true ),
		];
	}

	public static function serve(): void {
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed and verified, never echoed.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';

		$response = self::respond( $host, $uri, $method, time() );
		if ( $response === null ) {
			return;
		}

		status_header( $response['status'] );
		if ( ! headers_sent() ) {
			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value, true );
			}
		}
		if ( strtoupper( $method ) !== 'HEAD' ) {
			echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- image bytes or the fixed not-found page.
		}
		exit;
	}

	/**
	 * The member a share-address request carries, or 0: the cookie, verified
	 * against the live WordPress session.
	 *
	 * @param array<string, mixed>|null $cookies $_COOKIE-shaped (tests); null reads $_COOKIE.
	 */
	public static function fromCookie( string $host, ?array $cookies, int $now ): int {
		if ( ! Preview::isPreviewHost( $host ) ) {
			return 0;
		}

		$cookies = $cookies ?? $_COOKIE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a passive presentation, verified below.
		$value   = isset( $cookies[ self::COOKIE ] ) && is_string( $cookies[ self::COOKIE ] ) ? wp_unslash( $cookies[ self::COOKIE ] ) : '';
		$data    = self::verify( (string) $value, self::SESSION, $host, $now );

		return $data === null ? 0 : $data['user_id'];
	}

	/**
	 * Whether two hosts look like the same site to a browser (the same last
	 * two labels). A hint for the settings screen, not a security check.
	 */
	public static function sameSite( string $a, string $b ): bool {
		$site = static function ( string $host ): string {
			$labels = explode( '.', strtolower( trim( Preview::withoutPort( $host ), '.' ) ) );

			return implode( '.', array_slice( $labels, -2 ) );
		};

		return $a !== '' && $b !== '' && $site( $a ) === $site( $b );
	}

	private static function key(): string {
		return hash_hmac( 'sha256', 'tomatio-share-member|' . Whoami::installId(), wp_salt( 'auth' ) );
	}

	private static function sign( string $purpose, int $userId, string $session, string $host, int $expires ): string {
		$payload = rtrim( strtr( base64_encode( (string) wp_json_encode( [ 'v' => self::VERSION, 'p' => $purpose, 'u' => $userId, 's' => $session, 'h' => $host, 'e' => $expires ] ) ), '+/', '-_' ), '=' );

		return $payload . '.' . hash_hmac( 'sha256', $payload, self::key() );
	}

	/**
	 * @return array{user_id:int,session:string,host:string,session_expires:int}|null
	 */
	private static function verify( string $value, string $purpose, string $host, int $now ): ?array {
		$parts = explode( '.', $value );
		if ( count( $parts ) !== 2 || $parts[0] === '' || ! hash_equals( hash_hmac( 'sha256', $parts[0], self::key() ), $parts[1] ) ) {
			return null;
		}

		$data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
		if ( ! is_array( $data )
			|| ( $data['v'] ?? null ) !== self::VERSION
			|| ( $data['p'] ?? null ) !== $purpose
			|| ! is_int( $data['u'] ?? null ) || $data['u'] <= 0
			|| ! is_string( $data['s'] ?? null ) || preg_match( '/^[0-9a-f]{64}$/', $data['s'] ) !== 1
			|| ! is_string( $data['h'] ?? null ) || ! hash_equals( $data['h'], strtolower( Preview::withoutPort( trim( $host ) ) ) )
			|| ! is_int( $data['e'] ?? null ) || $data['e'] <= $now
			|| ( $purpose === self::TICKET && $data['e'] - $now > self::TICKET_TTL )
		) {
			return null;
		}

		$expiration = self::liveSession( $data['u'], $data['s'], $now );
		if ( $expiration === 0 ) {
			return null;
		}

		return [ 'user_id' => $data['u'], 'session' => $data['s'], 'host' => $data['h'], 'session_expires' => $expiration ];
	}

	/** The session's expiry for a raw session token, 0 when it is not live. */
	private static function sessionExpiration( int $userId, string $sessionToken, int $now ): int {
		return self::liveSession( $userId, hash( 'sha256', $sessionToken ), $now );
	}

	/**
	 * The expiry of the person's WordPress session with this verifier (the
	 * sha256 of the session token, as core stores it), 0 when it is gone:
	 * signed out, expired, or the person was removed.
	 */
	private static function liveSession( int $userId, string $verifier, int $now ): int {
		if ( get_userdata( $userId ) === false ) {
			return 0;
		}

		$manager = \WP_Session_Tokens::get_instance( $userId );
		if ( $manager instanceof \WP_User_Meta_Session_Tokens ) {
			$sessions = get_user_meta( $userId, 'session_tokens', true );
			$expires  = is_array( $sessions ) && isset( $sessions[ $verifier ]['expiration'] ) ? (int) $sessions[ $verifier ]['expiration'] : 0;

			return $expires > $now ? $expires : 0;
		}

		// A custom session store cannot be asked about one session by its
		// hash: the person must still have a live session, and the claim's
		// own expiry (at most MAX_AGE) bounds the rest.
		return $manager->get_all() !== [] ? $now + self::MAX_AGE : 0;
	}
}
