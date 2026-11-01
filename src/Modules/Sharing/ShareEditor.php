<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Preview;

/**
 * #643: one link is enough. Someone who opens a share link of a page while
 * signed in to the brain as a person who may edit it on its members address
 * gets the same Edit button and editor there (#632). The check is the
 * members address's own, PageEditor::editableFor(): the address check, the
 * editor check and a page that works as a document. Sections and pages
 * with scripts are not edited in place there, so not here either.
 *
 * Who is signed in: the share page is a top-level navigation, so in
 * same-address mode (#286) it carries the WordPress auth cookie of a
 * signed-in person. Preview::shareMember() reads it with the session check
 * the members address uses (get_current_user_id()) for the share page path
 * only, just before the request is forced logged out, and ShareRoute hands
 * the id here. The page itself still renders logged out. A preview host is
 * another host without a session (PreviewGate), so it never offers the
 * editor; neither do embed links, which other sites frame.
 *
 * Below a validated link, on the page path only:
 *
 *   /share/{id}/{token}/                   the page, with an Edit button
 *                                          (and "Saved" after ?saved=N)
 *   /share/{id}/{token}/?edit=1            the edit page: Save and Cancel
 *                                          above the editing frame
 *   /share/{id}/{token}/?edit=1&frame=1    the editing frame: the page and
 *                                          the editor, sandboxed
 *
 * Save posts to PageEditor's REST route with the person's own cookie and
 * REST nonce, exactly as on the members address: a new version under the
 * person's name, live at once, which the link then serves to everyone.
 * The browser comes back to the link with ?saved=N.
 *
 * Everyone else (signed out, a member who may not edit, an agent, a viewer
 * token or a seat, any other file below the link) gets null here and the
 * response HtmlSite gives today, byte for byte. Every response with the
 * editor in it is `private, no-store`, varies on the cookie and tells page
 * caches to keep out (DONOTCACHEPAGE).
 */
final class ShareEditor {

	/**
	 * The editor's response, or null when this request gets none.
	 *
	 * @param array{artifact_id:int,token:string,path:string,slash:bool} $site A validated link's route (HtmlSite::parsePath).
	 * @param array<string, mixed>                                       $link The validated share link row.
	 * @param int                                                        $memberId The signed-in person (Preview::shareMember), 0 = none.
	 * @return array{status:int,headers:array<string,string>,body:string}|null
	 */
	public static function respond( array $site, array $link, string $method, string $host, string $uri, int $memberId ): ?array {
		if ( $memberId <= 0
			|| $site['path'] !== ''
			|| ! $site['slash']
			|| ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true )
			|| Preview::effectiveHost()['mode'] !== Preview::MODE_SITE_ADDRESS
			|| ! in_array( strtolower( trim( $host ) ), Preview::siteHosts(), true )
			|| EmbedPolicy::normalizeKind( (string) ( $link['kind'] ?? '' ) ) === EmbedPolicy::KIND_EMBED
		) {
			return null;
		}

		$artifactId = $site['artifact_id'];
		$version    = PageEditor::editableFor( $memberId, $artifactId );
		if ( $version === null ) {
			return null;
		}

		$query = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		$base = ShareApp::base( $artifactId, $site['token'] );

		if ( ( $query[ PageEditor::PARAM ] ?? '' ) === '1' ) {
			return ( $query['frame'] ?? '' ) === '1'
				? self::frame( $version, $host )
				: self::editPage( $artifactId, $version, $base, $memberId );
		}

		$response = HtmlSite::respond( $site, $link, $method, $host, $uri );
		if ( $response['status'] !== 200 ) {
			return $response;
		}

		$saved               = isset( $query[ PageEditor::SAVED ] ) && is_string( $query[ PageEditor::SAVED ] ) && ctype_digit( $query[ PageEditor::SAVED ] ) ? (int) $query[ PageEditor::SAVED ] : 0;
		$response['body']    = self::withButton( $response['body'], $base, $saved );
		$response['headers'] = self::privateHeaders( $response['headers'] );

		return $response;
	}

	/**
	 * The page as served, with the Edit button (and the "Saved" note) at
	 * the end of its body.
	 */
	private static function withButton( string $html, string $base, int $saved ): string {
		$style = '<style id="ab-edit-button-style">'
			. '.ab-edit-button{all:initial;position:fixed;right:16px;bottom:16px;z-index:2147483647;box-sizing:border-box;padding:10px 18px;border-radius:999px;background:#16181d;color:#fff;font:600 15px/1.2 system-ui,-apple-system,"Segoe UI",sans-serif;text-decoration:none;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.25)}'
			. '.ab-edit-button:focus-visible{outline:3px solid #16a34a;outline-offset:2px}'
			. '.ab-saved{all:initial;position:fixed;left:50%;top:16px;z-index:2147483647;transform:translateX(-50%);box-sizing:border-box;padding:10px 16px;border-radius:10px;background:#16a34a;color:#fff;font:600 14px/1.3 system-ui,-apple-system,"Segoe UI",sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.2);animation:ab-saved 5s forwards}'
			. '@keyframes ab-saved{0%,80%{opacity:1}100%{opacity:0;visibility:hidden}}'
			. '</style>';
		$tags  = $style . '<a class="ab-edit-button" href="' . esc_url( $base . '?' . PageEditor::PARAM . '=1' ) . '">' . esc_html__( 'Edit', 'agent-brain' ) . '</a>';
		if ( $saved > 0 ) {
			/* translators: %d: version number. */
			$tags .= '<div class="ab-saved" role="status">' . esc_html( sprintf( __( 'Saved. Version %d is live.', 'agent-brain' ), $saved ) ) . '</div>';
		}

		$close = strripos( $html, '</body' );

		return $close === false ? $html . $tags : substr( $html, 0, $close ) . $tags . substr( $html, $close );
	}

	/**
	 * The edit page, as on the members address, framed by nothing; the
	 * REST nonce is the signed-in person's (the request itself runs logged
	 * out, so the nonce is made as that person and the request goes back
	 * to logged out).
	 *
	 * @param array{version:int,files:array<string, mixed>} $version
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function editPage( int $artifactId, array $version, string $base, int $memberId ): array {
		$previous = get_current_user_id();
		wp_set_current_user( $memberId );
		$nonce = wp_create_nonce( 'wp_rest' );
		wp_set_current_user( $previous );

		$response = PageEditor::editPage(
			$artifactId,
			$version,
			ShareRoute::securityHeaders(),
			[
				'back'      => $base,
				'frame'     => $base . '?' . PageEditor::PARAM . '=1&frame=1',
				'icon'      => $base . ShareApp::ICON,
				'nonce'     => $nonce,
				'done'      => $base . '?' . PageEditor::SAVED . '=',
				'ancestors' => "'none'",
			]
		);
		$response['headers'] = self::privateHeaders( $response['headers'] );

		return $response;
	}

	/**
	 * The editing frame: the page's document with the editor, under the
	 * share policy as a header (sandbox included), framed by the edit page
	 * on this site only.
	 *
	 * @param array{version:int,files:array<string, mixed>} $version
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function frame( array $version, string $host ): array {
		$headers                            = self::privateHeaders( ShareRoute::securityHeaders() );
		$headers['Content-Security-Policy'] = HtmlSite::csp( true ) . "; sandbox allow-scripts; frame-ancestors 'self'";
		$headers['X-Frame-Options']         = 'SAMEORIGIN';

		return [
			'status'  => 200,
			'headers' => $headers,
			'body'    => PageEditor::injectEditor( HtmlSite::previewDocument( $version['files'] ), set_url_scheme( 'http://' . strtolower( trim( $host ) ) ) ),
		];
	}

	/**
	 * A response with the editor in it belongs to one person: never stored,
	 * never shared, and no page cache may keep it.
	 *
	 * @param array<string, string> $headers
	 * @return array<string, string>
	 */
	private static function privateHeaders( array $headers ): array {
		$headers['Cache-Control'] = 'private, no-store';
		$headers['Pragma']        = 'no-cache';
		$headers['Vary']          = 'Cookie';

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page-cache convention every cache plugin reads.
		}

		return $headers;
	}
}
