<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Preview;

/**
 * Preview-host hardening (#55). On the preview host (Host == ab_preview_host)
 * only the share route is served. Everything else is redirected (302) to the
 * same path on the main site host, except surfaces that must never answer on
 * the preview host: /wp-json and ?rest_route= (404, so the REST API cannot be
 * used for user enumeration there) and wp-login.php / wp-admin / xmlrpc.php
 * (redirected to the main host root, not the same path).
 *
 * #412: /favicon.ico passes too, so the preview host answers it itself
 * (PreviewFavicon, or a brain's own do_favicon handler): a redirect to the
 * main site breaks the icon on every sandboxed share page in WebKit.
 *
 * #469: /apple-touch-icon.png (and -precomposed) is answered here with an
 * icon (PreviewFavicon::touchIcon): iOS asks the host root for it when a
 * page is added to the Home Screen.
 *
 * #388: a /share/ path is never redirected. One the share route does not
 * serve (for example an encoded "<" in a store key) is the same uniform 404
 * as an unknown link: a redirect would carry the share token to the main
 * site's address bar, access logs and login redirect.
 *
 * decide() is pure (no WordPress) so it is unit-testable. The gate has no
 * effect when ab_preview_host is unset or the request is on another host.
 */
final class PreviewGate {

	public const PASS     = 'pass';
	public const REDIRECT = 'redirect';
	public const NOT_FOUND = 'not_found';
	public const ICON      = 'icon';

	public const TOUCH_ICONS = [ '/apple-touch-icon.png', '/apple-touch-icon-precomposed.png' ];

	public static function register(): void {
		add_action( 'init', [ self::class, 'maybeGate' ], 0 );
	}

	/**
	 * @param string $mainBase Main site base URL without trailing slash, e.g. https://www.example.com.
	 * @return array{action:string,location:string}
	 */
	public static function decide( bool $isPreviewHost, string $uri, string $mainBase ): array {
		$pass = [ 'action' => self::PASS, 'location' => '' ];
		if ( ! $isPreviewHost ) {
			return $pass;
		}

		$mainBase = rtrim( $mainBase, '/' );
		$path     = (string) parse_url( $uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WordPress.
		$query    = (string) parse_url( $uri, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		// Collapse leading slashes so "//evil.example" can never become a scheme-relative redirect.
		$path = '/' . ltrim( $path, '/' );
		$low  = strtolower( $path );

		parse_str( $query, $params );
		if ( isset( $params['rest_route'] ) || $low === '/wp-json' || strpos( $low, '/wp-json/' ) === 0 ) {
			return [ 'action' => self::NOT_FOUND, 'location' => '' ];
		}

		// #285: the reachability probe must answer on the preview host.
		if ( $low === '/share/_whoami' ) {
			return $pass;
		}

		// #412: browsers and sandboxed share pages ask for the icon here.
		if ( $path === '/favicon.ico' ) {
			return $pass;
		}

		if ( in_array( $low, self::TOUCH_ICONS, true ) ) {
			return [ 'action' => self::ICON, 'location' => '' ];
		}

		// Below the token: the files of an HTML artifact (ShareRoute decides if the path exists).
		if ( preg_match( '#^/share/\d+/[^/]+(?:/(?:[A-Za-z0-9_-](?:[A-Za-z0-9._ -]|%20)*/?)*)?$#', $path ) === 1 ) {
			return $pass;
		}

		if ( $low === '/share' || strpos( $low, '/share/' ) === 0 ) {
			return [ 'action' => self::NOT_FOUND, 'location' => '' ];
		}

		$blocked = $low === '/wp-login.php' || $low === '/xmlrpc.php' || $low === '/wp-admin' || strpos( $low, '/wp-admin/' ) === 0;
		if ( $blocked || $path === '/' ) {
			return [ 'action' => self::REDIRECT, 'location' => $mainBase . '/' ];
		}

		return [ 'action' => self::REDIRECT, 'location' => $mainBase . $path . ( $query !== '' ? '?' . $query : '' ) ];
	}

	/**
	 * #388: what a NOT_FOUND decision answers — byte for byte the share
	 * route's uniform 404 for an unknown link, so a malformed share path
	 * tells a prober nothing an unknown token would not.
	 *
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function notFound(): array {
		return ShareRoute::notFound();
	}

	public static function maybeGate(): void {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed, never echoed.

		$decision = self::decide( Preview::isPreviewHost( $host ), $uri, untrailingslashit( home_url() ) );
		if ( $decision['action'] === self::PASS ) {
			return;
		}

		if ( $decision['action'] === self::NOT_FOUND || $decision['action'] === self::ICON ) {
			$response = $decision['action'] === self::ICON ? ( PreviewFavicon::touchIcon( $host ) ?? self::notFound() ) : self::notFound();
			status_header( $response['status'] );
			if ( ! headers_sent() ) {
				foreach ( $response['headers'] as $name => $value ) {
					header( $name . ': ' . $value, true );
				}
			}
			echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- ShareRoute's fixed, escaped not-found page, or icon bytes.
			exit;
		}

		nocache_headers();
		wp_redirect( $decision['location'], 302, 'TomaTío' ); // phpcs:ignore WordPress.Security.SafeRedirect -- location is built from home_url() and a normalised path.
		exit;
	}
}
