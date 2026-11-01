<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

/**
 * Sandboxed artifact previews (#55) and share-link serving (#286).
 *
 * Two modes, preview host takes precedence:
 *
 * - Preview-host mode (a saved `ab_preview_host`): share pages are served
 *   only on that exact host, exactly as before #286.
 * - Same-address mode (the default, no preview host configured): share
 *   pages are served at this site's own address under /share/{id}/{token}/,
 *   so sharing works on a fresh install with no DNS, no extra certificate
 *   and no settings. The same strict sandbox CSP is applied per response;
 *   the render path also forces the request logged-out so a signed-in
 *   wp-admin session is never honoured while serving a share page.
 */
class Preview {

	public const OPTION_HOST = 'ab_preview_host';

	public const CSP = "default-src 'none'; img-src data: https:; style-src 'unsafe-inline'; sandbox";

	public const MODE_PREVIEW_HOST = 'preview_host';
	public const MODE_SITE_ADDRESS = 'site_address';

	/**
	 * Whether share pages can be served at all: an effective host exists.
	 * Since #286 this is effectively always true (the site address is the
	 * fallback); the sharing kill switch lives in Sharing\Config.
	 */
	public static function isEnabled(): bool {
		return self::effectiveHost()['host'] !== '';
	}

	public static function configuredHost(): string {
		return trim( (string) get_option( self::OPTION_HOST, '' ) );
	}

	/**
	 * The address share links are served on, and where it came from.
	 *
	 * @return array{host:string,mode:string,source:string} mode is one of the
	 *         MODE_* constants; source is a human-readable origin of the value.
	 */
	public static function effectiveHost(): array {
		$configured = self::configuredHost();
		if ( $configured !== '' ) {
			// wp_load_alloptions() is read before any option_ /
			// default_option_ filter, so this tells a saved row apart from a
			// value a filter or the environment supplies at runtime.
			$saved = function_exists( 'wp_load_alloptions' ) && array_key_exists( self::OPTION_HOST, wp_load_alloptions() );

			return [
				'host'   => $configured,
				'mode'   => self::MODE_PREVIEW_HOST,
				'source' => $saved ? __( 'preview host (saved setting)', 'agent-brain' ) : __( 'preview host (a filter or the environment)', 'agent-brain' ),
			];
		}

		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return [
			'host'   => $host,
			'mode'   => self::MODE_SITE_ADDRESS,
			'source' => __( 'this site\'s own address (default: no preview host configured)', 'agent-brain' ),
		];
	}

	public static function isPreviewHost( string $requestHost ): bool {
		$configured = self::configuredHost();

		return $configured !== '' && hash_equals( strtolower( $configured ), strtolower( trim( $requestHost ) ) );
	}

	/**
	 * The hosts this WordPress install answers to (home and site URLs),
	 * compared without ports and case-insensitively.
	 *
	 * @return string[]
	 */
	public static function siteHosts(): array {
		$hosts = [];
		foreach ( [ home_url(), site_url() ] as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( $host !== '' ) {
				$hosts[ $host ] = true;
			}
		}

		return array_keys( $hosts );
	}

	/**
	 * #286: whether this request's host may be served share pages. The
	 * preview host, when configured, is the ONLY answer (precedence); with no
	 * preview host, the site's own address serves them (same-address mode).
	 */
	public static function servesShareRequests( string $requestHost ): bool {
		if ( self::isPreviewHost( $requestHost ) ) {
			return true;
		}

		if ( self::configuredHost() !== '' ) {
			return false;
		}

		$requestHost = strtolower( trim( $requestHost ) );
		foreach ( self::siteHosts() as $site ) {
			if ( hash_equals( $site, $requestHost ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * #286: the /share/ path shapes the same-address entry point intercepts
	 * on the main host (mirrors the private-mode allowlist): the reachability
	 * probe and token paths, including the files below a token.
	 */
	public static function isSharePath( string $path ): bool {
		return preg_match( '#^/share/_whoami$#', $path ) === 1
			|| preg_match( '#^/share/\d+/[A-Za-z0-9_-]+(?:/(?:[A-Za-z0-9_-](?:[A-Za-z0-9._ -]|%20)*/?)*)?$#', $path ) === 1;
	}

	/**
	 * Runs on template_redirect.
	 *
	 * Preview-host mode: unchanged from #55 — activates only on the exact
	 * configured host, sets the strict CSP for every response there and
	 * forces the request logged-out.
	 *
	 * Same-address mode (no preview host): activates only on a site host and
	 * only for /share/ paths; forces the request logged-out (the signed-in
	 * wp-admin session must not leak into share rendering) and lets
	 * ShareRoute answer. The blanket CSP header is NOT set here — on the main
	 * host it would break every normal page; ShareRoute applies the strict
	 * policy to its own responses instead.
	 *
	 * #643: on the share page path only, the signed-in person is read first
	 * (shareMember) and handed to ShareRoute for the page editor.
	 */
	public static function maybeServe(): void {
		$requestHost = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
		$path        = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

		if ( self::isPreviewHost( $requestHost ) ) {
			if ( ! headers_sent() ) {
				header( 'Content-Security-Policy: ' . self::CSP );
			}

			// No auth cookies honoured on the preview host: force a logged-out
			// request regardless of any cookie the browser sent.
			wp_set_current_user( 0 );

			// Serves /share/{artifactId}/{token}; no-op for any other path.
			\AgentBrain\Modules\Sharing\ShareRoute::serve();

			return;
		}

		if ( ! self::servesShareRequests( $requestHost ) || ! self::isSharePath( $path ) ) {
			return;
		}

		// #643: who is signed in, for the page editor only (ShareEditor).
		$member = self::shareMember( $requestHost, $path );

		// Same-address mode on the site's own host: treat the share request
		// as anonymous even when the browser sends wp-admin cookies.
		wp_set_current_user( 0 );

		\AgentBrain\Modules\Sharing\ShareRoute::serve( $member );
	}

	/**
	 * #643: the person signed in on this site for a share PAGE request
	 * (/share/{id}/{token}/, nothing below it) in same-address mode: the
	 * WordPress session check the members address uses (get_current_user_id),
	 * read before maybeServe() forces the request logged out. 0 on the
	 * preview host, which never honours a session, and for any other path.
	 * ShareRoute only ever offers the page editor with it.
	 */
	public static function shareMember( string $requestHost, string $path ): int {
		if ( self::configuredHost() !== '' || ! self::servesShareRequests( $requestHost ) || \AgentBrain\Modules\Sharing\ShareRoute::parsePath( $path ) === null ) {
			return 0;
		}

		return get_current_user_id();
	}

	/**
	 * Sandboxed iframe for a share link. Tokens are stored hashed and cannot
	 * be recovered, so a working URL exists only for a token the caller has
	 * just minted; with no token this returns ''.
	 * Never call this with a token read from storage into the wp-admin DOM.
	 */
	public static function iframeMarkup( int $artifactId, string $token = '' ): string {
		if ( ! \AgentBrain\Modules\Sharing\Token::isWellFormed( $token ) ) {
			return '';
		}

		$url = \AgentBrain\Modules\Sharing\ShareLinks::url( $artifactId, $token );

		return sprintf(
			'<iframe sandbox src="%s" style="width:100%%;height:600px;border:0"></iframe>',
			esc_url( $url )
		);
	}
}
