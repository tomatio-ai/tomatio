<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\DataStore\StoreClient;
use AgentBrain\Modules\DataStore\StoreMode;
use AgentBrain\Modules\DataStore\StoreSeats;

/**
 * #469: a shared HTML artifact as a phone app, served for every browser
 * without page code. Below a validated share link:
 *
 *   GET /share/{id}/{token}/_version              -> {"version": n, "reload": bool}
 *   GET /share/{id}/{token}/manifest.webmanifest  -> the web app manifest (?seat=NAME for a personal link)
 *   GET /share/{id}/{token}/_icon/{seat}-{hash}-{size}.png -> the install icon the page and manifest name
 *   GET /share/{id}/{token}/apple-touch-icon.png  -> the same icon at a fixed address (?size=, ?seat=)
 *
 * and every HTML file served gets a few head tags: the served version, the
 * manifest and icon links, the Home Screen title and the page-store client
 * (_tomatio.js), which also reloads open pages when a new version is
 * approved (HtmlFiles::autoReload) at the next visibility change.
 *
 * The manifest has no start_url, so an installed page opens the address it
 * was installed from, fragment included (a personal link's #seat= secret
 * stays on the device). ?seat=NAME only dresses the page (title, icon,
 * manifest) and only for a claimed seat; it proves nothing. Files of these
 * names in the artifact are shadowed; its own icon is the base of ours.
 *
 * Safari's Add to Dock reads the icon from the HTML as served and keeps it
 * per web app, so the page and the manifest name the icon by content: the
 * seat ("app" for the link) and a hash of what it is drawn from are in the
 * path, and a new icon is a new address, cached for good. A seat's icon
 * follows the artifact's seat icon style (StoreMode::seatIconStyle), which
 * is part of its hash too.
 */
final class ShareApp {

	public const VERSION  = '_version';
	public const MANIFEST = 'manifest.webmanifest';
	public const ICON     = 'apple-touch-icon.png';
	public const ICONS    = '_icon';

	public const VERSION_META = 'tomatio-version';

	/**
	 * @return array{artifact_id:int,token:string,what:string,slug?:string,hash?:string,size?:int}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/share/(\d+)/([^/]+)/' . self::ICONS . '/([a-z0-9-]{1,24}?)-([a-f0-9]{12})-(\d{3})\.png$#', $path, $m ) === 1 ) {
			return [
				'artifact_id' => (int) $m[1],
				'token'       => rawurldecode( $m[2] ),
				'what'        => self::ICONS,
				'slug'        => $m[3],
				'hash'        => $m[4],
				'size'        => (int) $m[5],
			];
		}
		$what = '(' . implode( '|', array_map( static fn ( string $p ): string => preg_quote( $p, '#' ), [ self::VERSION, self::MANIFEST, self::ICON ] ) ) . ')';

		if ( preg_match( '#^/share/(\d+)/([^/]+)/' . $what . '$#', $path, $m ) !== 1 ) {
			return null;
		}

		return [
			'artifact_id' => (int) $m[1],
			'token'       => rawurldecode( $m[2] ),
			'what'        => $m[3],
		];
	}

	/**
	 * @param array{artifact_id:int,token:string,what:string,slug?:string,hash?:string,size?:int} $route Validated route context.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respond( array $route, string $method, string $uri, string $host = '' ): array {
		$artifactId = $route['artifact_id'];
		if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) || ! HtmlFiles::isHtml( $artifactId ) || Interactive::killSwitchOn() ) {
			return ShareRoute::notFound();
		}

		[ $origin, $scope ] = HtmlSite::sources( $host, $artifactId, $route['token'] );
		$query              = self::query( $uri );

		if ( $route['what'] === self::VERSION ) {
			$headers = HtmlSite::headers( 'application/json; charset=utf-8', $origin, $scope );

			return [
				'status'  => 200,
				'headers' => $headers,
				'body'    => (string) wp_json_encode( [ 'version' => HtmlFiles::servedNumber( $artifactId ), 'reload' => HtmlFiles::autoReload( $artifactId ) ] ),
			];
		}

		$version = HtmlFiles::servedVersion( $artifactId );
		if ( $version === null ) {
			return ShareRoute::notFound();
		}

		$title = self::title( $artifactId, $version['files'] );
		$path  = AppIcon::declaredPath( $version['files'] );
		// The page's own icon, else the brain's Site Icon, else one from the title.
		$base  = $path !== null ? HtmlFiles::bytes( $version['files'][ $path ] ) : AppIcon::siteIconBytes();

		if ( $route['what'] === self::ICONS ) {
			// The seat whose current icon has this address; an old address
			// (the icon changed since) gets today's icon, never cached.
			$current = null;
			foreach ( self::seatsFor( $artifactId, (string) $route['slug'] ) as $candidate ) {
				if ( AppIcon::contentHash( $base, $title, $candidate, StoreMode::seatIconStyle( $artifactId ) ) === $route['hash'] ) {
					$current = [ $candidate ];
					break;
				}
			}
			$seat                     = $current[0] ?? self::seatsFor( $artifactId, (string) $route['slug'] )[0] ?? null;
			$headers                  = HtmlSite::headers( 'image/png', $origin, $scope );
			$headers['Cache-Control'] = $current !== null ? 'private, max-age=31536000, immutable' : 'private, no-store';

			return [
				'status'  => 200,
				'headers' => $headers,
				'body'    => AppIcon::png( $base, $title, (int) $route['size'], $seat, StoreMode::seatIconStyle( $artifactId ) ),
			];
		}

		$seat = self::seat( $artifactId, $query['seat'] ?? null );

		if ( $route['what'] === self::MANIFEST ) {
			$headers                  = HtmlSite::headers( 'application/manifest+json; charset=utf-8', $origin, $scope );
			$headers['Cache-Control'] = 'private, max-age=300';

			return [
				'status'  => 200,
				'headers' => $headers,
				'body'    => (string) wp_json_encode( self::manifest( $artifactId, $route['token'], $version, $title, $seat ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			];
		}

		$size    = isset( $query['size'] ) && is_numeric( $query['size'] ) ? (int) $query['size'] : AppIcon::DEFAULT;
		$headers = HtmlSite::headers( 'image/png', $origin, $scope );
		// A fixed address: kept briefly, the content-addressed one is for good.
		$headers['Cache-Control'] = 'private, max-age=300';

		return [
			'status'  => 200,
			'headers' => $headers,
			'body'    => AppIcon::png( $base, $title, $size, $seat, StoreMode::seatIconStyle( $artifactId ) ),
		];
	}

	/**
	 * The tags put in front of an HTML file's head, and the file with them.
	 *
	 * @param array{version:int,files:array<string, mixed>} $version
	 */
	public static function inject( string $html, int $artifactId, string $token, array $version, string $uri ): string {
		$seat  = self::seat( $artifactId, self::query( $uri )['seat'] ?? null );
		$title = self::title( $artifactId, $version['files'] );
		$name  = self::appName( $title, $seat );
		$base  = self::base( $artifactId, $token );

		$tags = '<meta name="' . self::VERSION_META . '" content="' . (int) $version['version'] . '">'
			. '<link rel="manifest" href="' . esc_attr( $base . self::MANIFEST . self::args( [ 'seat' => $seat ] ) ) . '">'
			. '<link rel="apple-touch-icon" href="' . esc_attr( self::iconPath( $artifactId, $token, $version['files'], $title, $seat, AppIcon::DEFAULT ) ) . '">'
			. '<link rel="icon" type="image/png" sizes="192x192" href="' . esc_attr( self::iconPath( $artifactId, $token, $version['files'], $title, $seat, 192 ) ) . '">'
			. '<meta name="apple-mobile-web-app-capable" content="yes">'
			. '<meta name="mobile-web-app-capable" content="yes">'
			. '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $name ) . '">'
			. StoreClient::inlineTag( $base )
			// #630: the brain design, ahead of the page's own CSS so the page wins.
			. \AgentBrain\Modules\Artifacts\Design::pageTag( $html );

		// The author's own install tags would compete with these (their icon is our base).
		$html = (string) preg_replace( '#<link\b[^>]*\brel\s*=\s*(["\'])\s*(?:apple-touch-icon(?:-precomposed)?|manifest)\s*\1[^>]*>#i', '', $html );
		$html = (string) preg_replace( '#<meta\b[^>]*\bname\s*=\s*(["\'])\s*apple-mobile-web-app-title\s*\1[^>]*>#i', '', $html );

		if ( $seat !== null ) {
			$count = 0;
			$named = preg_replace_callback(
				'#(<title\b[^>]*>)(.*?)(</title\s*>)#is',
				static fn ( array $m ): string => $m[1] . esc_html( $seat ) . ' · ' . $m[2] . $m[3],
				$html,
				1,
				$count
			);
			$html  = is_string( $named ) ? $named : $html;
			$tags .= $count === 1 ? '' : '<title>' . esc_html( $name ) . '</title>';
		}

		// After <head> (or <html>, or the doctype) so a doctype stays first.
		foreach ( [ '#<head\b[^>]*>#i', '#<html\b[^>]*>#i', '#<!doctype\b[^>]*>#i' ] as $anchor ) {
			$count = 0;
			$out = preg_replace( $anchor, '$0' . str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $tags ), $html, 1, $count );
			if ( $count === 1 && is_string( $out ) ) {
				return $out;
			}
		}

		return $tags . $html;
	}

	/**
	 * The manifest of a link (or a seat on it).
	 *
	 * @param array{version:int,files:array<string, mixed>} $version
	 * @return array<string, mixed>
	 */
	public static function manifest( int $artifactId, string $token, array $version, string $title, ?string $seat ): array {
		$base  = self::base( $artifactId, $token );
		$theme = self::themeColor( $version['files'] );
		$icons = [];
		foreach ( [ 192, 512, 180 ] as $size ) {
			$icons[] = [
				'src'     => self::iconPath( $artifactId, $token, $version['files'], $title, $seat, $size ),
				'sizes'   => $size . 'x' . $size,
				'type'    => 'image/png',
				'purpose' => 'any',
			];
		}

		$manifest = [
			'id'          => $base . self::args( [ 'seat' => $seat ] ),
			'name'        => self::appName( $title, $seat ),
			'short_name'  => $seat ?? mb_substr( $title, 0, 24 ),
			'scope'       => $base,
			'display'     => 'standalone',
			'icons'       => $icons,
		];
		if ( $theme !== null ) {
			$manifest['theme_color']      = $theme;
			$manifest['background_color'] = $theme;
		}

		return $manifest;
	}

	/**
	 * The content address of an icon: /share/{id}/{token}/_icon/{seat}-{hash}-{size}.png.
	 *
	 * @param array<string, mixed> $files
	 */
	public static function iconPath( int $artifactId, string $token, array $files, string $title, ?string $seat, int $size ): string {
		$path = AppIcon::declaredPath( $files );
		$hash = AppIcon::contentHash( $path !== null ? HtmlFiles::bytes( $files[ $path ] ) : AppIcon::siteIconBytes(), $title, $seat, StoreMode::seatIconStyle( $artifactId ) );

		return self::base( $artifactId, $token ) . self::ICONS . '/' . AppIcon::slug( $seat ) . '-' . $hash . '-' . $size . '.png';
	}

	/**
	 * The seats (null: the link itself) an icon address segment may name.
	 *
	 * @return array<int, string|null>
	 */
	private static function seatsFor( int $artifactId, string $slug ): array {
		$out = $slug === 'app' ? [ null ] : [];
		if ( StoreMode::isActive( $artifactId ) && StoreMode::seatsOn( $artifactId ) ) {
			foreach ( StoreSeats::all( $artifactId ) as $row ) {
				if ( AppIcon::slug( $row['name'] ) === $slug ) {
					$out[] = $row['name'];
				}
			}
		}

		return $out;
	}

	/**
	 * The page's own <title>, else the artifact's title.
	 *
	 * @param array<string, mixed> $files
	 */
	public static function title( int $artifactId, array $files ): string {
		$html = isset( $files[ HtmlFiles::ENTRY ] ) ? HtmlFiles::bytes( $files[ HtmlFiles::ENTRY ] ) : '';
		if ( preg_match( '#<title\b[^>]*>(.*?)</title\s*>#is', $html, $m ) === 1 ) {
			$title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
			if ( $title !== '' ) {
				return $title;
			}
		}

		return trim( (string) get_the_title( $artifactId ) );
	}

	/**
	 * "NAME · Title" for a seat, else the title.
	 */
	public static function appName( string $title, ?string $seat ): string {
		return $seat !== null ? $seat . ' · ' . $title : $title;
	}

	/**
	 * The claimed seat a ?seat= hint names, in its claimed spelling, or null
	 * (seats off, not claimed, not a name).
	 *
	 * @param mixed $hint
	 */
	public static function seat( int $artifactId, $hint ): ?string {
		if ( ! is_string( $hint ) || $hint === '' || ! StoreMode::isActive( $artifactId ) || ! StoreMode::seatsOn( $artifactId ) ) {
			return null;
		}

		return StoreSeats::named( $artifactId, $hint );
	}

	/**
	 * /share/{id}/{token}/, the path every link-relative address starts with.
	 */
	public static function base( int $artifactId, string $token ): string {
		return '/share/' . $artifactId . '/' . rawurlencode( $token ) . '/';
	}

	/**
	 * @param array<string, mixed> $files
	 */
	private static function themeColor( array $files ): ?string {
		$html = isset( $files[ HtmlFiles::ENTRY ] ) ? HtmlFiles::bytes( $files[ HtmlFiles::ENTRY ] ) : '';
		if ( preg_match( '#<meta\b[^>]*\bname\s*=\s*(["\'])theme-color\1[^>]*>#i', $html, $tag ) === 1
			&& preg_match( '#\bcontent\s*=\s*(["\'])\s*(\#[0-9a-f]{3}(?:[0-9a-f]{3})?)\s*\1#i', $tag[0], $m ) === 1 ) {
			return strtolower( $m[2] );
		}

		return null;
	}

	/**
	 * @param array<string, string|null> $args
	 */
	private static function args( array $args ): string {
		$args = array_filter( $args, static fn ( $v ): bool => $v !== null && $v !== '' );

		return $args === [] ? '' : '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function query( string $uri ): array {
		$query = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );

		return $query;
	}
}
