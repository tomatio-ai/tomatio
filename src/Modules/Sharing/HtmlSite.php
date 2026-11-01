<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\HtmlFiles;

/**
 * ART-22 (#138): serves an HTML artifact (a set of static files) on the
 * preview host, below /share/{id}/{token}/. Token checks, IP binding, expiry,
 * revocation and the uniform 404 are those of ShareRoute; this class only
 * picks the served version and builds the response.
 *
 * Every response carries the script-capable but isolated policy: `sandbox
 * allow-scripts` without allow-same-origin (opaque origin: no cookies, no
 * storage, no access to the preview host), no forms, no top navigation, no
 * framing, and network access only to the artifact's own files plus the
 * allowlisted hosts (all off by default).
 *
 * #398: the page's origin is opaque, and WebKit (Safari, every iPhone
 * browser) matches 'self' against that opaque origin, so 'self' matches
 * nothing there: every fetch, XHR, script, style, font and image of the
 * page's own share host was blocked. Chrome matches 'self' against the page
 * URL. The policy therefore also names the share host explicitly (from the
 * request) in every directive that uses 'self', and connect-src names only
 * this link's own path (https://host/share/{id}/{token}/, a prefix match):
 * tighter than 'self', which stays for the browsers that read it as meant.
 */
final class HtmlSite {

	public const OPT_CONNECT_HOSTS = 'ab_html_connect_hosts';

	/**
	 * Remote hosts the artifact may load scripts, styles and fonts from
	 * (ab_interactive_script_hosts, shared with interactive mode).
	 *
	 * @return array<int, string>
	 */
	private static function cdnSources(): array {
		return array_map( static fn ( string $host ): string => 'https://' . $host, Interactive::scriptHosts() );
	}

	/**
	 * Hosts fetch/XHR may reach: none unless the site lists some.
	 *
	 * @return array<int, string>
	 */
	public static function connectHosts(): array {
		$raw = get_option( self::OPT_CONNECT_HOSTS, [] );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw );
		}

		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $host ) {
			$host = strtolower( trim( (string) $host ) );
			if ( preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host ) === 1 ) {
				$out[] = 'https://' . $host;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param bool   $meta   True for a <meta http-equiv> policy (no sandbox / frame-ancestors there).
	 * @param string $origin The share host as an origin (https://host), '' when unknown.
	 * @param string $scope  This link's own path below that origin (…/share/{id}/{token}/), '' when unknown.
	 */
	public static function csp( bool $meta = false, string $origin = '', string $scope = '' ): string {
		$cdn     = self::cdnSources();
		$join    = static fn ( array $sources ): string => implode( ' ', $sources );
		$own     = $origin !== '' ? [ "'self'", $origin ] : [ "'self'" ];
		$connect = array_merge( [ "'self'" ], $scope !== '' ? [ $scope ] : [], self::connectHosts() );

		$policy = "default-src 'none'; img-src " . $join( array_merge( $own, [ 'data:' ], $cdn ) )
			. '; style-src ' . $join( array_merge( $own, [ "'unsafe-inline'" ], $cdn ) )
			. '; font-src ' . $join( array_merge( $own, [ 'data:' ], $cdn ) )
			. '; script-src ' . $join( array_merge( $own, [ "'unsafe-inline'" ], $cdn ) )
			. '; connect-src ' . $join( $connect )
			// #469: the per-link web app manifest.
			. '; manifest-src ' . $join( array_merge( [ "'self'" ], $scope !== '' ? [ $scope ] : [] ) )
			. "; form-action 'none'; base-uri 'none'";

		return $meta ? $policy : $policy . "; sandbox allow-scripts; frame-ancestors 'none'";
	}

	/**
	 * #398: the explicit sources for a page served on $host for link
	 * ($artifactId, $token): [origin, path scope], or ['', ''] when the
	 * host is not a plain host name (it is then left to 'self'). The host
	 * comes from the request, which ShareRoute already matched against the
	 * hosts that serve share links; the scheme from the configured share
	 * address.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function sources( string $host, int $artifactId, string $token ): array {
		$host = strtolower( trim( $host ) );
		if ( preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::\d{1,5})?$/', $host ) !== 1 || $token === '' ) {
			return [ '', '' ];
		}

		$scheme = (string) wp_parse_url( ShareLinks::baseUrl(), PHP_URL_SCHEME );
		$origin = ( $scheme === 'http' ? 'http' : 'https' ) . '://' . $host;

		return [ $origin, $origin . '/share/' . $artifactId . '/' . rawurlencode( $token ) . '/' ];
	}

	/**
	 * @param string $origin See csp().
	 * @param string $scope  See csp().
	 * @return array<string, string>
	 */
	public static function headers( string $contentType, string $origin = '', string $scope = '' ): array {
		return [
			'Content-Type'                 => $contentType,
			'X-Robots-Tag'                 => 'noindex, nofollow',
			'Referrer-Policy'              => 'no-referrer',
			'Cache-Control'                => 'private, no-store',
			'Pragma'                       => 'no-cache',
			'X-Frame-Options'              => 'DENY',
			'X-Content-Type-Options'       => 'nosniff',
			'Content-Security-Policy'      => self::csp( false, $origin, $scope ),
			// The page runs in an opaque origin, so its own scripts, modules and fetches reach the files cross-origin.
			'Access-Control-Allow-Origin'  => '*',
			'Cross-Origin-Resource-Policy' => 'cross-origin',
		];
	}

	/**
	 * /share/{id}/{token}[/{path}] with the path part (without the slash that
	 * follows the token), or null.
	 *
	 * @return array{artifact_id:int,token:string,path:string,slash:bool}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/share/(\d+)/([^/]+)(?:(/)(.*))?$#', $path, $m ) !== 1 ) {
			return null;
		}

		return [
			'artifact_id' => (int) $m[1],
			'token'       => rawurldecode( $m[2] ),
			'path'        => rawurldecode( $m[4] ?? '' ),
			'slash'       => ( $m[3] ?? '' ) === '/',
		];
	}

	/**
	 * The response for a request whose token was already validated.
	 *
	 * @param array{artifact_id:int,token:string,path:string,slash:bool} $route
	 * @param array<string, mixed>                                       $link
	 * @param string                                                     $host The request host (#398), '' when unknown.
	 * @param string                                                     $uri  The request URI (#469: ?seat= dresses a personal link).
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respond( array $route, array $link, string $method, string $host = '', string $uri = '' ): array {
		$artifactId = $route['artifact_id'];
		$version    = Interactive::killSwitchOn() ? null : HtmlFiles::servedVersion( $artifactId );
		if ( $version === null ) {
			return ShareRoute::notFound();
		}

		$path    = $route['path'] === '' ? HtmlFiles::ENTRY : $route['path'];
		$isEntry = $path === HtmlFiles::ENTRY;
		if ( ! isset( $version['files'][ $path ] ) || ( $route['path'] !== '' && ! HtmlFiles::isValidPath( $route['path'] ) ) ) {
			return ShareRoute::notFound();
		}

		if ( ! $route['slash'] ) {
			// Relative asset paths only resolve below a trailing slash.
			$response            = ShareRoute::notFound();
			$response['status']  = 302;
			$response['body']    = '';
			$response['headers'] = [ 'Location' => '/share/' . $artifactId . '/' . rawurlencode( $route['token'] ) . '/' ] + $response['headers'];

			return $response;
		}

		$file = $version['files'][ $path ];
		if ( $isEntry && strtoupper( $method ) === 'GET' ) {
			ShareLinks::recordOpen( (int) $link['id'] );
			// #221: one analytics row per share-page open of the served version.
			ViewAnalytics::record( $artifactId, (int) $link['id'], (int) $version['version'], ViewAnalytics::viewerHashFor( Config::clientIp() ) );
		}

		[ $origin, $scope ] = self::sources( $host, $artifactId, $route['token'] );

		$body = HtmlFiles::bytes( $file );
		if ( str_starts_with( (string) $file['type'], 'text/html' ) ) {
			// #469: installable, self-updating (ShareApp).
			$body = ShareApp::inject( $body, $artifactId, $route['token'], $version, $uri );
		}

		return [
			'status'  => 200,
			'headers' => self::headers( (string) $file['type'], $origin, $scope ),
			'body'    => $body,
		];
	}

	/**
	 * Self-contained document for the wp-admin preview (srcdoc iframe):
	 * relative scripts, stylesheets and images of the version are inlined, and
	 * the page policy is applied as a meta tag. The iframe itself is sandboxed
	 * without allow-same-origin.
	 *
	 * #610: the members address frame passes its own origin and path scope
	 * (see csp()), and $head: tags put in after the policy and the design,
	 * ahead of everything the page brings (the store client).
	 *
	 * @param array<string, array{enc:string,data:string,type:string}> $files
	 */
	public static function previewDocument( array $files, string $origin = '', string $scope = '', string $head = '' ): string {
		$entry = isset( $files[ HtmlFiles::ENTRY ] ) ? HtmlFiles::bytes( $files[ HtmlFiles::ENTRY ] ) : '';
		$local = static fn ( string $ref ): ?array => preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|/|\#)#i', $ref ) === 1
			? null
			: ( $files[ ltrim( (string) preg_replace( '#^\./#', '', strtok( $ref, '?#' ) ?: '' ), '/' ) ] ?? null );

		$html = preg_replace_callback(
			'#<script\b([^>]*?)\bsrc\s*=\s*(["\'])(.*?)\2([^>]*)>\s*</script>#is',
			static function ( array $m ) use ( $local ): string {
				$file = $local( $m[3] );

				return $file === null ? $m[0] : '<script' . $m[1] . $m[4] . '>' . str_ireplace( '</script', '<\/script', HtmlFiles::bytes( $file ) ) . '</script>';
			},
			$entry
		) ?? $entry;

		$html = preg_replace_callback(
			'#<link\b[^>]*\brel\s*=\s*(["\'])stylesheet\1[^>]*\bhref\s*=\s*(["\'])(.*?)\2[^>]*>#is',
			static function ( array $m ) use ( $local ): string {
				$file = $local( $m[3] );

				return $file === null ? $m[0] : '<style>' . str_ireplace( '</style', '<\/style', HtmlFiles::bytes( $file ) ) . '</style>';
			},
			$html
		) ?? $html;

		$html = preg_replace_callback(
			'#(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2#is',
			static function ( array $m ) use ( $local ): string {
				$file = $local( $m[3] );

				// #632: data-ab-src keeps the file it came from, so the page editor saves the reference, not the bytes.
				return $file === null ? $m[0] : $m[1] . '"data:' . strtok( (string) $file['type'], ';' ) . ';base64,' . base64_encode( HtmlFiles::bytes( $file ) ) . '" data-ab-src="' . esc_attr( $m[3] ) . '"';
			},
			$html
		) ?? $html;

		// #630: the brain design, decided on the page as written (before its stylesheets were inlined).
		$design = \AgentBrain\Modules\Artifacts\Design::pageTag( $entry );
		$policy = '<meta http-equiv="Content-Security-Policy" content="' . esc_attr( self::csp( true, $origin, $scope ) ) . '">' . $design . $head;

		// After <head> so a doctype stays first (a meta before it would switch the page to quirks mode).
		// The tags go in literally (a script has backslashes and dollar signs).
		$withPolicy = preg_replace( '#<head\b[^>]*>#i', '$0' . str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $policy ), $html, 1, $count );

		return $count === 1 && is_string( $withPolicy ) ? $withPolicy : $policy . $html;
	}
}
