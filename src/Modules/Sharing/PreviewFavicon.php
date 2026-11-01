<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Preview;

/**
 * #412: /favicon.ico on the preview host is answered there, never redirected.
 *
 * Shared HTML pages run sandboxed with img-src naming only the share host
 * (#398), and WebKit checks a redirect's target against it: the 302 to the
 * main site that PreviewGate and core's do_favicon() both used to give was a
 * CSP violation on every shared page in Safari. PreviewGate now lets the
 * path through; a brain's own do_favicon handler (priority below this one,
 * such as the demo brand mu-plugin) still answers first, and this one serves
 * the bytes before core (priority 10) would redirect: the site icon file
 * when one is set, else the bundled TomaTío icon. Off the preview host it
 * does nothing, so the main site keeps whatever it does today.
 */
final class PreviewFavicon {

	public const BUNDLED = '/assets/brand/tomatio-32.png';

	public static function register(): void {
		add_action( 'do_favicon', [ self::class, 'maybeServe' ], 5 );
	}

	public static function maybeServe(): void {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';

		$response = self::respond( $host );
		if ( $response === null ) {
			return;
		}

		status_header( $response['status'] );
		if ( ! headers_sent() ) {
			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value, true );
			}
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- image bytes from a local file.
		exit;
	}

	/**
	 * The icon response on the preview host, or null anywhere else.
	 *
	 * @return array{status:int,headers:array<string,string>,body:string}|null
	 */
	public static function respond( string $host ): ?array {
		if ( ! Preview::isPreviewHost( $host ) ) {
			return null;
		}

		$file = self::siteIconFile() ?? \AGENT_BRAIN_PLUGIN_DIR . self::BUNDLED;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local image file, read-only.
		$bytes = is_readable( $file ) ? file_get_contents( $file ) : false;
		if ( ! is_string( $bytes ) || $bytes === '' ) {
			return [ 'status' => 404, 'headers' => [ 'Cache-Control' => 'public, max-age=3600' ], 'body' => '' ];
		}

		$type = wp_check_filetype( $file )['type'];

		return [
			'status'  => 200,
			'headers' => [
				'Content-Type'                 => is_string( $type ) && str_starts_with( $type, 'image/' ) ? $type : 'image/png',
				'Cache-Control'                => 'public, max-age=86400',
				'X-Content-Type-Options'       => 'nosniff',
				// A sandboxed share page (opaque origin) loads it cross-origin.
				'Cross-Origin-Resource-Policy' => 'cross-origin',
			],
			'body'    => $bytes,
		];
	}

	/**
	 * #469: /apple-touch-icon.png on the preview host: a 180px opaque PNG of
	 * the site icon, else the bundled TomaTío one. Null anywhere else.
	 *
	 * @return array{status:int,headers:array<string,string>,body:string}|null
	 */
	public static function touchIcon( string $host ): ?array {
		if ( ! Preview::isPreviewHost( $host ) ) {
			return null;
		}

		$id   = (int) get_option( 'site_icon' );
		$file = $id > 0 ? get_attached_file( $id ) : false;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local image file, read-only.
		$bytes = is_string( $file ) && is_readable( $file ) ? file_get_contents( $file ) : false;

		return [
			'status'  => 200,
			'headers' => [
				'Content-Type'                 => 'image/png',
				'Cache-Control'                => 'public, max-age=86400',
				'X-Content-Type-Options'       => 'nosniff',
				'Cross-Origin-Resource-Policy' => 'cross-origin',
			],
			'body'    => is_string( $bytes ) && $bytes !== ''
				? AppIcon::png( $bytes, (string) get_bloginfo( 'name' ), AppIcon::DEFAULT )
				: (string) file_get_contents( \AGENT_BRAIN_PLUGIN_DIR . AppIcon::BUNDLED ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled plugin file.
		];
	}

	/**
	 * The local file of the site icon (WordPress Site Identity), the 32px
	 * size when it exists, or null when no icon is set or it is not local.
	 */
	private static function siteIconFile(): ?string {
		$id = (int) get_option( 'site_icon' );
		if ( $id <= 0 ) {
			return null;
		}

		$small = image_get_intermediate_size( $id, [ 32, 32 ] );
		$base  = wp_get_upload_dir()['basedir'] ?? '';
		if ( is_array( $small ) && $base !== '' && is_readable( $base . '/' . $small['path'] ) ) {
			return $base . '/' . $small['path'];
		}

		$full = get_attached_file( $id );

		return is_string( $full ) && is_readable( $full ) ? $full : null;
	}
}
