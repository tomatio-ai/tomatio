<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Modules\Admin\BrandChrome;
use AgentBrain\Modules\Admin\BrandIcon;

/**
 * #630: the brain design. One stylesheet (assets/brain.css) in TomaTío's
 * look, which every document and page of the brain follows, with the
 * brain's own accent, mark (Site Icon or monogram) and name on top. No
 * setup per brain: the tokens come from the brand settings it already has.
 *
 * - Documents (section artifacts) render with it on share links, the
 *   members address and print (ShareRoute::page()).
 * - HTML pages get it in their head by default, ahead of their own CSS, so
 *   anything the page styles itself wins. A page with no CSS of its own gets
 *   the full look; one with CSS gets the tokens and classes, plus the
 *   element look under class="ab-doc". `<meta name="tomatio-design"
 *   content="off">` leaves a page alone.
 *
 * Inline, never a file: shared pages run sandboxed and documents allow no
 * stylesheets but inline ones.
 */
final class Design {

	public const ASSET    = '/assets/brain.css';
	public const OPT_OUT  = 'tomatio-design';
	public const STYLE_ID = 'tomatio-design';

	/** TomaTío's own accent set, used while the brain keeps the default accent. */
	private const TOMATIO_ACCENT = [ '#16a34a', '#dcfce7', '#15803d', '#4ade80', '#12301d' ];

	/** The mark is inlined up to this size; a bigger Site Icon is linked instead. */
	private const MARK_MAX_BYTES = 48000;

	/** @var array<string, string> Built once per request, by scope. */
	private static array $cache = [];

	/**
	 * The stylesheet: the brain's tokens, then brain.css. $wholePage applies
	 * the element rules to the whole page (documents, pages without CSS of
	 * their own); otherwise they apply under .ab-doc only.
	 */
	public static function css( bool $wholePage = true ): string {
		$key = $wholePage ? 'page' : 'scoped';
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled plugin file, read-only.
		$sheet = defined( 'AGENT_BRAIN_PLUGIN_DIR' ) ? file_get_contents( \AGENT_BRAIN_PLUGIN_DIR . self::ASSET ) : false;
		$sheet = self::minify( is_string( $sheet ) ? $sheet : '' );
		if ( $wholePage ) {
			$sheet = str_replace( [ '.ab-doc{', '.ab-doc ' ], [ ':where(body){', '' ], $sheet );
		}

		return self::$cache[ $key ] = self::tokens() . $sheet;
	}

	/** The stylesheet as a style element. */
	public static function styleTag( bool $wholePage = true ): string {
		return '<style id="' . self::STYLE_ID . '">' . str_ireplace( '</style', '<\/style', self::css( $wholePage ) ) . '</style>';
	}

	/**
	 * The brain's own custom properties: accent (with its soft and ink
	 * shades, light and dark), mark and name.
	 */
	public static function tokens(): string {
		$accent = BrandIcon::accent();
		if ( $accent === BrandIcon::DEFAULT_ACCENT ) {
			[ $light, $soft, $ink, $dark, $softDark ] = self::TOMATIO_ACCENT;
		} else {
			$light    = $accent;
			$soft     = 'color-mix(in srgb, ' . $accent . ' 14%, #fff)';
			$ink      = 'color-mix(in srgb, ' . $accent . ' 82%, #000)';
			$dark     = 'color-mix(in srgb, ' . $accent . ' 60%, #fff)';
			$softDark = 'color-mix(in srgb, ' . $accent . ' 24%, #111318)';
		}

		return ':root{--ab-accent:' . $light . ';--ab-accent-soft:' . $soft . ';--ab-accent-ink:' . $ink
			. ';--ab-accent-dark:' . $dark . ';--ab-accent-soft-dark:' . $softDark
			. ';--ab-mark:url("' . self::cssString( self::markUri() ) . '")'
			. ';--ab-name:"' . self::cssString( BrandIcon::name() ) . '"}';
	}

	/**
	 * The brain's mark: the Site Icon (its 180 px size when WordPress made
	 * one) as a data: URI, so it shows on sandboxed pages and offline; a
	 * Site Icon too big to inline by URL; otherwise the monogram.
	 */
	public static function markUri(): string {
		if ( ! function_exists( 'has_site_icon' ) || ! has_site_icon() ) {
			return BrandIcon::uri();
		}

		$id    = (int) get_option( 'site_icon' );
		$file  = null;
		$small = image_get_intermediate_size( $id, [ 180, 180 ] );
		$base  = wp_get_upload_dir()['basedir'] ?? '';
		if ( is_array( $small ) && $base !== '' && is_readable( $base . '/' . $small['path'] ) ) {
			$file = $base . '/' . $small['path'];
		} else {
			$full = get_attached_file( $id );
			$file = is_string( $full ) && is_readable( $full ) ? $full : null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local image file, read-only.
		$bytes = $file !== null && filesize( $file ) <= self::MARK_MAX_BYTES ? file_get_contents( $file ) : false;
		$type  = $file !== null ? (string) wp_check_filetype( $file )['type'] : '';
		if ( is_string( $bytes ) && $bytes !== '' && str_starts_with( $type, 'image/' ) ) {
			return 'data:' . $type . ';base64,' . base64_encode( $bytes );
		}

		return (string) get_site_icon_url( 180 );
	}

	/**
	 * The brand line of a document: the brain's mark and name.
	 */
	public static function brandLine(): string {
		return '<div class="ab-brand">' . esc_html( BrandIcon::name() ) . '</div>';
	}

	/**
	 * The brain's favicon set for a document: the Site Icon as data: URIs
	 * when it is local, else the monogram's set (SVG, 32 px PNG, 180 px
	 * apple-touch-icon).
	 */
	public static function faviconTags(): string {
		if ( function_exists( 'has_site_icon' ) && has_site_icon() ) {
			$mark = self::markUri();

			return '<link rel="icon" href="' . esc_url( $mark, [ 'https', 'http', 'data' ] ) . '">'
				. '<link rel="apple-touch-icon" href="' . esc_url( $mark, [ 'https', 'http', 'data' ] ) . '">';
		}

		return BrandChrome::faviconTags();
	}

	/** Whether an HTML page asked to be left alone (`<meta name="tomatio-design" content="off">`). */
	public static function optedOut( string $html ): bool {
		if ( preg_match_all( '#<meta\b[^>]*>#i', $html, $metas ) < 1 ) {
			return false;
		}
		foreach ( $metas[0] as $tag ) {
			if ( preg_match( '#\bname\s*=\s*(["\']?)\s*' . self::OPT_OUT . '\s*\1#i', $tag ) === 1
				&& preg_match( '#\bcontent\s*=\s*(["\']?)\s*(?:off|none|false|0)\s*\1#i', $tag ) === 1 ) {
				return true;
			}
		}

		return false;
	}

	/** Whether an HTML page brings CSS of its own (a style element or a stylesheet link). */
	public static function hasOwnCss( string $html ): bool {
		return preg_match( '#<style\b|<link\b[^>]*\brel\s*=\s*(["\']?)\s*stylesheet\b#i', $html ) === 1;
	}

	/**
	 * The style element for an HTML page, or '' when it opted out: the
	 * whole look when the page has no CSS of its own, else the tokens and
	 * classes with the element look under .ab-doc.
	 */
	public static function pageTag( string $html ): string {
		return self::optedOut( $html ) ? '' : self::styleTag( ! self::hasOwnCss( $html ) );
	}

	/**
	 * Put the design into an HTML page, right after <head> (or <html>, or
	 * the doctype) so a doctype stays first and the page's own CSS comes
	 * after it.
	 */
	public static function inject( string $html ): string {
		$tag = self::pageTag( $html );
		if ( $tag === '' ) {
			return $html;
		}

		foreach ( [ '#<head\b[^>]*>#i', '#<html\b[^>]*>#i', '#<!doctype\b[^>]*>#i' ] as $anchor ) {
			$count = 0;
			$out   = preg_replace( $anchor, '$0' . str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $tag ), $html, 1, $count );
			if ( $count === 1 && is_string( $out ) ) {
				return $out;
			}
		}

		return $tag . $html;
	}

	/** Forget the per-request cache (tests change the brand between requests). */
	public static function reset(): void {
		self::$cache = [];
	}

	/** A value safe inside a double-quoted CSS string (and inside a style element). */
	private static function cssString( string $value ): string {
		return (string) preg_replace_callback(
			'/[\\\\"<>\x00-\x1f\x7f]/',
			static fn ( array $m ): string => '\\' . dechex( ord( $m[0] ) ) . ' ',
			$value
		);
	}

	/** Comments out, whitespace collapsed. */
	private static function minify( string $css ): string {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = (string) preg_replace( '/\s+/', ' ', $css );
		$css = (string) preg_replace( '/\s*([{};])\s*/', '$1', $css );

		return trim( $css );
	}
}
