<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Extensions\Sandbox;

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
	 * @param int    $artifactId #994: a page bound to an extension may add the flags a manager approved (Extensions\Sandbox); 0 for none.
	 */
	public static function csp( bool $meta = false, string $origin = '', string $scope = '', int $artifactId = 0 ): string {
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

		return $meta ? $policy : $policy . '; sandbox ' . Sandbox::attribute( $artifactId ) . "; frame-ancestors 'none'";
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
	 * @param int    $artifactId See csp().
	 * @return array<string, string>
	 */
	public static function headers( string $contentType, string $origin = '', string $scope = '', int $artifactId = 0 ): array {
		return [
			'Content-Type'                 => $contentType,
			'X-Robots-Tag'                 => 'noindex, nofollow',
			'Referrer-Policy'              => 'no-referrer',
			'Cache-Control'                => 'private, no-store',
			'Pragma'                       => 'no-cache',
			'X-Frame-Options'              => 'DENY',
			'X-Content-Type-Options'       => 'nosniff',
			'Content-Security-Policy'      => self::csp( false, $origin, $scope, $artifactId ),
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
	 * @param string|null                                                $asset_capability Optional signed capability for a private link's assets (#420).
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respond( array $route, array $link, string $method, string $host = '', string $uri = '', ?string $asset_capability = null ): array {
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

		// #420: private links rewrite local asset references to a capability
		// path so the sandboxed page can load them without the viewer cookie.
		if ( $isEntry && $asset_capability !== null && strtoupper( $method ) === 'GET' ) {
			$body = self::rewriteAssetUrls( $body, $version['files'], $artifactId, $route['token'], $asset_capability );
		}

		if ( str_starts_with( (string) $file['type'], 'text/html' ) ) {
			// #469: installable, self-updating (ShareApp).
			$body = ShareApp::inject( $body, $artifactId, $route['token'], $version, $uri );
		}

		return [
			'status'  => 200,
			'headers' => self::headers( (string) $file['type'], $origin, $scope, $artifactId ),
			'body'    => $body,
		];
	}

	/**
	 * Rewrite local file references to the capability path.
	 *
	 * @param array<string, array{enc:string,data:string,type:string}> $files
	 */
	public static function rewriteAssetUrls( string $html, array $files, int $artifactId, string $token, string $capability ): string {
		$base  = '/share/' . $artifactId . '/' . rawurlencode( $token ) . '/' . AssetCapability::PATH_PREFIX . '/' . $capability . '/';
		$local = static function ( string $ref ) use ( $base, $files ): ?string {
			if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|/|\#)#i', $ref ) === 1 ) {
				return null;
			}

			$raw      = strtok( $ref, '?#' ) ?: '';
			$pathOnly = ltrim( (string) preg_replace( '#^\./#', '', $raw ), '/' );
			if ( $pathOnly === '' || ! HtmlFiles::isValidPath( $pathOnly ) || ! isset( $files[ $pathOnly ] ) ) {
				return null;
			}

			$suffix = substr( $ref, strlen( $raw ) );

			return $base . $pathOnly . $suffix;
		};

		$html = preg_replace_callback(
			'#<script\b([^>]*?)\bsrc\s*=\s*(["\'])(.*?)\2([^>]*)>\s*</script>#is',
			static function ( array $m ) use ( $local ): string {
				$rewritten = $local( $m[3] );

				return $rewritten === null ? $m[0] : '<script' . $m[1] . 'src="' . esc_attr( $rewritten ) . '"' . $m[4] . '></script>';
			},
			$html
		) ?? $html;

		$html = preg_replace_callback(
			'#<link\b([^>]*?)\bhref\s*=\s*(["\'])(.*?)\2([^>]*)>#is',
			static function ( array $m ) use ( $local ): string {
				$rewritten = $local( $m[3] );

				return $rewritten === null ? $m[0] : '<link' . $m[1] . 'href="' . esc_attr( $rewritten ) . '"' . $m[4] . '>';
			},
			$html
		) ?? $html;

		$html = preg_replace_callback(
			'#(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2#is',
			static function ( array $m ) use ( $local ): string {
				$rewritten = $local( $m[3] );

				return $rewritten === null ? $m[0] : $m[1] . '"' . esc_attr( $rewritten ) . '"';
			},
			$html
		) ?? $html;

		$html = preg_replace_callback(
			'#(<source\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2#is',
			static function ( array $m ) use ( $local ): string {
				$rewritten = $local( $m[3] );

				return $rewritten === null ? $m[0] : $m[1] . '"' . esc_attr( $rewritten ) . '"';
			},
			$html
		) ?? $html;

		// srcset on <img>/<source>: rewrite each candidate's URL, keep its descriptor.
		$html = preg_replace_callback(
			'#(<(?:img|source)\b[^>]*?\bsrcset\s*=\s*)(["\'])(.*?)\2#is',
			static function ( array $m ) use ( $local ): string {
				$candidates = array_map(
					static fn ( string $c ): string => preg_replace_callback(
						'#^(\s*)(\S+)#',
						static function ( array $p ) use ( $local ): string {
							$rewritten = $local( $p[2] );

							return $rewritten === null ? $p[0] : $p[1] . $rewritten;
						},
						$c
					) ?? $c,
					explode( ',', $m[3] )
				);

				return $m[1] . '"' . esc_attr( implode( ',', $candidates ) ) . '"';
			},
			$html
		) ?? $html;

		return preg_replace_callback(
			'#url\(\s*(["\']?)(.*?)\1\s*\)#is',
			static function ( array $m ) use ( $local ): string {
				$rewritten = $local( $m[2] );

				return $rewritten === null ? $m[0] : 'url("' . esc_attr( $rewritten ) . '")';
			},
			$html
		) ?? $html;
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

		// #1086: an inline script ignores defer and async, so a local script
		// inlined at its tag ran before the page existed. A classic script
		// that asked to run late goes to the end of the body instead, in
		// document order. Scripts from other hosts that have defer go with
		// them (as plain scripts), so a library still runs before the page's
		// own code. Modules stay: an inline module is deferred as it is.
		$mark = '<!--tomatio-late-' . md5( $entry ) . '-';
		$late = [];
		$html = preg_replace_callback(
			'#<!--.*?-->|<script\b(?![^>]*\bsrc\s*=)[^>]*>.*?</script\s*>|<script\b([^>]*?)\bsrc\s*=\s*(["\'])(.*?)\2([^>]*)>\s*</script>#is',
			static function ( array $m ) use ( $local, $mark, &$late ): string {
				// A comment or an inline script: a tag written inside one is text.
				if ( ! isset( $m[3] ) ) {
					return $m[0];
				}

				$file  = $local( $m[3] );
				$asked = self::lateScript( $m[1] . $m[4] );
				if ( $file === null ) {
					if ( $asked === null || $asked['async'] ) {
						return $m[0];
					}

					$token  = $mark . count( $late ) . '-->';
					$late[] = [
						'token' => $token,
						'tag'   => $m[0],
						'moved' => '<script' . $asked['attrs'] . ' src=' . $m[2] . $m[3] . $m[2] . '></script>',
					];

					return $token;
				}

				$code = str_ireplace( '</script', '<\/script', HtmlFiles::bytes( $file ) );
				if ( $asked === null ) {
					return '<script' . $m[1] . $m[4] . '>' . $code . '</script>';
				}

				$late[] = [
					'token' => '',
					'tag'   => '',
					'moved' => '<script' . $asked['attrs'] . '>' . $code . '</script>',
				];

				return '';
			},
			self::beforeBodyEnd( $entry, $mark . 'end-->' )
		);
		if ( ! is_string( $html ) ) {
			$html = $entry;
			$late = [];
		}

		// Nothing moves unless a script of the page's own did: a page whose
		// only deferred scripts come from other hosts is left as written.
		$moves = in_array( '', array_column( $late, 'token' ), true );
		$swap  = [ $mark . 'end-->' => $moves ? implode( '', array_column( $late, 'moved' ) ) : '' ];
		foreach ( $late as $script ) {
			if ( $script['token'] !== '' ) {
				$swap[ $script['token'] ] = $moves ? '' : $script['tag'];
			}
		}
		$html = strtr( $html, $swap );

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

	/**
	 * #1086: whether a script tag asked to run late, from its attributes
	 * (everything but src). Null for a tag that runs where it stands: no
	 * defer or async, a module, or a type that is not JavaScript. Otherwise
	 * the attributes without defer and async, and whether it said async
	 * (which wins over defer, as in the browser).
	 *
	 * @return array{attrs:string, async:bool}|null
	 */
	private static function lateScript( string $attributes ): ?array {
		preg_match_all( '#([^\s=/"\'<>]+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?#', $attributes, $found, PREG_SET_ORDER );

		$kept  = '';
		$defer = false;
		$async = false;
		foreach ( $found as $attribute ) {
			$name  = strtolower( $attribute[1] );
			$value = strtolower( trim( $attribute[2] ?? '', "\"' \t\r\n" ) );
			if ( $name === 'type' && $value !== '' && preg_match( '#^(?:text|application)/(?:x-)?(?:java|ecma)script#', $value ) !== 1 ) {
				return null;
			}
			if ( $name === 'defer' ) {
				$defer = true;
			} elseif ( $name === 'async' ) {
				$async = true;
			} else {
				$kept .= ' ' . $attribute[0];
			}
		}

		if ( ! $defer && ! $async ) {
			return null;
		}

		return [
			'attrs' => $kept,
			'async' => $async,
		];
	}

	/**
	 * #1086: $html with $insert just before its last </body>, or its last
	 * </html> when it has no </body>, or at the end. A closing tag written
	 * inside a script or a comment is text, not the end of the body.
	 */
	private static function beforeBodyEnd( string $html, string $insert ): string {
		foreach ( [ 'body', 'html' ] as $element ) {
			$at = null;
			preg_match_all( '#<!--.*?-->|<script\b[^>]*>.*?</script\s*>|</' . $element . '\s*>#is', $html, $found, PREG_OFFSET_CAPTURE );
			foreach ( $found[0] as [ $text, $offset ] ) {
				if ( stripos( $text, '</' . $element ) === 0 ) {
					$at = $offset;
				}
			}
			if ( $at !== null ) {
				return substr( $html, 0, $at ) . $insert . substr( $html, $at );
			}
		}

		return $html . $insert;
	}
}
