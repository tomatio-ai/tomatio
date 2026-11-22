<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Sharing\HtmlSite;
use AgentBrain\Modules\Sharing\ShareRoute;

/**
 * #375: the page-store client script below a validated share link,
 *
 *   GET /share/{id}/{token}/_tomatio.js
 *
 * a few lines of plain JavaScript (assets/tomatio-store.js) that give a
 * sandboxed page window.tomatio.store on top of StoreRoute. Same-origin, so
 * the page policy's script-src 'self' admits it with no CSP change; it
 * works out its base from location, so it is the same bytes for every link.
 * Served whether or not the store is on (it holds nothing); the API answers
 * for itself. A file of that name in an HTML artifact is shadowed.
 */
final class StoreClient {

	public const PATH  = '_tomatio.js';
	public const ASSET = '/assets/tomatio-store.js';

	/** #610: the meta tag that hands a members-address page its viewer token. */
	public const TOKEN_META = 'tomatio-viewer-token';

	/** The header links between TíoDay and Magic Backlog on the members address (MembersRoute, Switches::links). */
	public const EXTENSIONS_META = 'tomatio-extensions';

	/** #1073: the viewer's own TíoDay Done and Decided, on a board page only (MembersRoute, Switches::record). */
	public const RECORD_META = 'tomatio-record';

	/**
	 * #904: the meta tag that tells a page on its members address which
	 * TomaTío release serves it (TíoDay shows it at the bottom). Only the
	 * signed-in members address gets it; a share link never does. Not
	 * 'tomatio-version': that name is the page content version the store
	 * client parses as a number to reload open pages (#469).
	 */
	public const VERSION_META = 'tomatio-release';

	/**
	 * #611: the client as an inline script, put into every served HTML page
	 * ahead of the page's own scripts. A page on a private link runs
	 * sandboxed and cannot present the viewer cookie, so fetching
	 * _tomatio.js from it answered the uniform 404 and window.tomatio never
	 * existed; inline it rides on the page request, which passed the
	 * private-link check. Falls back to the script URL if the asset is
	 * missing.
	 */
	public static function inlineTag( string $base ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled plugin file, read-only.
		$script = defined( 'AGENT_BRAIN_PLUGIN_DIR' ) ? file_get_contents( \AGENT_BRAIN_PLUGIN_DIR . self::ASSET ) : false;

		return is_string( $script ) && $script !== ''
			? '<script>' . self::inlineScript( $script ) . '</script>'
			: '<script src="' . esc_attr( $base . self::PATH ) . '" defer></script>';
	}

	/**
	 * #610: the client for a page on its members address (/a/{slug}), with
	 * the member's viewer token in a meta tag ahead of it (the frame has no
	 * fragment to carry it, and a reload in the frame gets a fresh one).
	 * Empty when the asset is missing: there is no script URL to fall back
	 * to there.
	 */
	public static function membersTag( string $viewerToken ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled plugin file, read-only.
		$script = defined( 'AGENT_BRAIN_PLUGIN_DIR' ) ? file_get_contents( \AGENT_BRAIN_PLUGIN_DIR . self::ASSET ) : false;

		return is_string( $script ) && $script !== ''
			? '<meta name="' . self::TOKEN_META . '" content="' . esc_attr( $viewerToken ) . '">' . self::versionMeta() . '<script>' . self::inlineScript( $script ) . '</script>'
			: '';
	}

	/**
	 * #904: the release meta tag for a members-address page, or '' when the
	 * plugin version is unknown.
	 */
	public static function versionMeta(): string {
		$version = defined( 'AGENT_BRAIN_VERSION' ) ? (string) \AGENT_BRAIN_VERSION : '';

		return $version !== '' ? '<meta name="' . self::VERSION_META . '" content="' . esc_attr( $version ) . '">' : '';
	}

	/**
	 * JavaScript made safe inside a <script> element: no closing tag and no
	 * comment opener that would end it or change how it is parsed.
	 */
	public static function inlineScript( string $js ): string {
		return (string) preg_replace( [ '#</(script)#i', '#<!--#' ], [ '<\\/$1', '<\\!--' ], $js );
	}

	/**
	 * @return array{artifact_id:int,token:string}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/share/(\d+)/([^/]+)/' . preg_quote( self::PATH, '#' ) . '$#', $path, $m ) !== 1 ) {
			return null;
		}

		return [
			'artifact_id' => (int) $m[1],
			'token'       => rawurldecode( $m[2] ),
		];
	}

	/**
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respond( string $method ): array {
		if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) ) {
			return ShareRoute::notFound();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled plugin file, read-only.
		$script = file_get_contents( \AGENT_BRAIN_PLUGIN_DIR . self::ASSET );
		if ( ! is_string( $script ) ) {
			return ShareRoute::notFound();
		}

		return [
			'status'  => 200,
			'headers' => HtmlSite::headers( 'text/javascript; charset=utf-8' ),
			'body'    => $script,
		];
	}
}
