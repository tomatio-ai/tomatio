<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Artifacts\Preview;
use AgentBrain\Modules\Artifacts\Versions;
use AgentBrain\Modules\DataStore\StoreClient;
use AgentBrain\Modules\DataStore\StoreMode;
use AgentBrain\Modules\DataStore\StoreRoute;

/**
 * #219: the permanent members address, GET https://{main site}/a/{artifactId}.
 *
 * One fixed address per artifact on the MAIN site (never the preview host,
 * which has no sign-in). What it serves:
 *
 * - exactly the page, full window, always the current APPROVED version — a
 *   new approval shows up at the same address;
 * - `?v={seq}` composes with #220's Versions: an older version, clearly
 *   marked as not the current one, for the artifact's lead, curators and
 *   managers only;
 * - no token and no expiry: removing someone's access to the artifact is
 *   what stops them.
 *
 * Sign-in: an anonymous visitor is 302'd through wp-login and back (the
 * classic auth round trip, wp_login_url — no cookie is set by this route).
 * Signed in but not allowed (MembersAddress::canSeeAddress): the uniform
 * 404, indistinguishable from a missing artifact.
 *
 * Isolation: the artifact renders inside a full-window sandboxed iframe
 * (`sandbox` without `allow-same-origin`, so its scripts run in an opaque
 * origin and can never touch the signed-in session on the main site). The
 * iframe loads the same address with `?frame=1` (#500), which answers with
 * the document alone under the share CSP as a response header, `sandbox`
 * included, so the document is opaque even when opened directly in a tab.
 * A srcdoc iframe would inherit the outer page's `default-src 'none'`,
 * which blocks every script and data: image inside it. The frame request
 * is same-site, so the sign-in cookie still authorises it, and it passes
 * the same checks as the page. HTML artifacts get the self-contained
 * inlined document (relative scripts, styles and images inlined, exactly
 * the wp-admin preview document) because a sandboxed document cannot
 * authenticate its own file subrequests — no cookie scheme exists for the
 * members address and none is needed.
 *
 * #610: a page with the page store on gets window.tomatio.store here too,
 * as the signed-in member. The frame document carries the store client
 * and a short-lived viewer token for this member (share link id 0, the
 * members address), the frame policy lets it reach this address, and the
 * store endpoints answer below it, /a/{slug}/store/... (StoreRoute::
 * respondMembers), with that token as the only credential. The token
 * lasts 20 minutes; a page renews it by reloading itself inside the frame:
 * a frame navigation (Sec-Fetch-Dest: iframe) is served the frame document,
 * after the same sign-in and access checks, even without ?frame=1.
 *
 * #750: a section artifact with nothing approved yet says so in one line
 * above the frame (which then shows only the title), with a link to its
 * sections for the people who can review them.
 *
 * #754: those reviewers also see the sections waiting for review in the
 * page, marked, with Approve all in the bar above the frame
 * (WaitingSections). Everyone else still sees approved sections only.
 */
final class MembersRoute {

	/**
	 * `/a/{id}` or, since #438, `/a/{slug}`. An unknown slug resolves to
	 * artifact 0, which the handler answers with the uniform 404.
	 *
	 * @return array{artifact_id:int,redirect:bool}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/a/([a-z0-9][a-z0-9-]*)/?$#', $path, $m ) !== 1 ) {
			return null;
		}

		return MembersAddress::resolve( $m[1] );
	}

	/**
	 * template_redirect entry (Sharing module, priority 1).
	 */
	public static function serve(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- consumed only as a host comparison.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passed through esc_url_raw into handle().
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		$response = self::handle( $host, $uri, $method, get_current_user_id(), time(), $_SERVER );
		if ( $response === null ) {
			return;
		}

		status_header( $response['status'] );
		if ( ! headers_sent() ) {
			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value, true );
			}
		}
		if ( $method !== 'HEAD' ) {
			echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- fully built and escaped below.
		}
		exit;
	}

	/**
	 * Pure-ish request handler (no output, no exit) so it can be tested.
	 * Returns null when the request is not a members-address request on a
	 * site host (the preview host never serves it; neither does any other).
	 *
	 * @param array<string, mixed> $server $_SERVER-shaped.
	 * @param string|null          $body   Raw request body of a store request, when already read (tests).
	 * @return array{status:int,headers:array<string,string>,body:string}|null
	 */
	public static function handle( string $host, string $uri, string $method, int $actorId, int $now, array $server, ?string $body = null ) {
		if ( ! in_array( strtolower( trim( $host ) ), Preview::siteHosts(), true ) ) {
			return null;
		}

		// #610: the page store below the address. The frame sends no cookie
		// (opaque origin), so this comes before the sign-in redirect; the
		// viewer token is checked by StoreRoute.
		$store = StoreRoute::parseMembersPath( $uri );
		if ( $store !== null ) {
			if ( Config::disabled() || ! ShareLinks::isServable( get_post( $store['artifact_id'] ) ) ) {
				return ShareRoute::notFound();
			}
			$ip = Config::clientIp( $server );

			return StoreRoute::respondMembers( $store, $method, $uri, $body, $now, $ip !== '' ? Config::ipHash( $ip ) : '' );
		}

		$route = self::parsePath( $uri );
		if ( $route === null ) {
			return null;
		}

		$artifactId = $route['artifact_id'];

		if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true )
			|| Config::disabled()
			|| ! ShareLinks::isServable( get_post( $artifactId ) )
		) {
			return ShareRoute::notFound();
		}

		$query = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		$requestedSeq = isset( $query['v'] ) && is_string( $query['v'] ) && ctype_digit( $query['v'] ) ? (int) $query['v'] : 0;
		// #610: a navigation inside the frame (a page reloading itself for a
		// fresh viewer token) is the frame too, ?frame=1 or not.
		$frame = ( $query['frame'] ?? '' ) === '1' || strtolower( (string) ( $server['HTTP_SEC_FETCH_DEST'] ?? '' ) ) === 'iframe';
		// #632: edit the page in place (the current version only).
		$edit = ( $query[ PageEditor::PARAM ] ?? '' ) === '1' && $requestedSeq === 0;

		$redirectBack = MembersAddress::url( $artifactId ) . ( $requestedSeq > 0 ? '?v=' . $requestedSeq : ( $edit ? '?' . PageEditor::PARAM . '=1' : '' ) );

		// #438: a slug the artifact had before moves to its current address.
		if ( $route['redirect'] ) {
			return [
				'status'  => 301,
				'headers' => [ 'Location' => $redirectBack . ( $frame ? ( $requestedSeq > 0 ? '&' : '?' ) . 'frame=1' : '' ) ] + ShareRoute::securityHeaders(),
				'body'    => '',
			];
		}

		if ( $actorId <= 0 ) {
			// The frame never shows a sign-in page inside the iframe, nor the icon.
			return $frame || ( $query['icon'] ?? '' ) === '1' ? ShareRoute::notFound() : self::redirect( SignInNote::url( wp_login_url( $redirectBack ) ) );
		}

		if ( ! MembersAddress::canSeeAddress( $actorId, $artifactId ) ) {
			return ShareRoute::notFound();
		}

		if ( $requestedSeq > 0 && ! MembersAddress::canViewVersions( $actorId, $artifactId ) ) {
			return ShareRoute::notFound();
		}

		// #518: the tab icon of the members page, after the same checks.
		if ( ( $query['icon'] ?? '' ) === '1' ) {
			return self::icon( $artifactId );
		}

		$editable = PageEditor::editableFor( $actorId, $artifactId );
		if ( $edit ) {
			if ( ! PageEditor::canEdit( $actorId, $artifactId ) ) {
				return ShareRoute::notFound();
			}
			if ( $editable === null ) {
				// An app, or nothing published yet: the page as it is.
				return $frame ? ShareRoute::notFound() : self::redirect( MembersAddress::url( $artifactId ) );
			}

			return $frame ? self::editFrame( $editable, $host ) : self::editPage( $artifactId, $editable );
		}

		$built = self::buildDocument( $artifactId, $actorId, $requestedSeq, $now, $host, rtrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' ) );
		if ( ! is_array( $built ) ) {
			return ShareRoute::notFound();
		}

		if ( $frame ) {
			return [
				'status'  => 200,
				'headers' => self::frameHeaders( $built['policy'] ),
				'body'    => $built['document'],
			];
		}

		// One view per page load: the frame request is not counted again.
		if ( strtoupper( $method ) === 'GET' ) {
			$ip = Config::clientIp( $server );
			// #221 analytics: link id 0 = the members address (not a share link).
			ViewAnalytics::record( $artifactId, 0, $built['version'], ViewAnalytics::viewerHashFor( $ip ), $now );
		}

		$banner = '';
		if ( $requestedSeq > 0 ) {
			$banner = sprintf(
				'<div class="ab-version-banner" role="alert"><strong>%s</strong> <span>%s</span> <a href="%s">%s</a></div>',
				esc_html__( 'This is not the current version.', 'agent-brain' ),
				esc_html(
					sprintf(
						/* translators: 1: version sequence number, 2: date the version was recorded (UTC). */
						__( 'You are viewing version %1$d, recorded %2$s UTC.', 'agent-brain' ),
						$requestedSeq,
						$built['at']
					)
				),
				esc_url( MembersAddress::url( $artifactId ) ),
				esc_html__( 'Open the current version', 'agent-brain' )
			);
		} elseif ( ! HtmlFiles::isHtml( $artifactId ) ) {
			$banner = self::pendingNote( $artifactId, $actorId );
			// #754: back from Approve all.
			$approved = isset( $query[ WaitingSections::APPROVED ] ) && is_string( $query[ WaitingSections::APPROVED ] ) && ctype_digit( $query[ WaitingSections::APPROVED ] ) ? (int) $query[ WaitingSections::APPROVED ] : 0;
			if ( $approved > 0 ) {
				/* translators: %d: number of sections approved. */
				$banner .= '<div class="ab-saved" role="status">' . esc_html( sprintf( _n( 'Approved %d section.', 'Approved %d sections.', $approved, 'agent-brain' ), $approved ) ) . '</div>';
			}
		}

		$title = (string) get_post( $artifactId )->post_title;
		$src   = MembersAddress::url( $artifactId ) . '?' . ( $requestedSeq > 0 ? 'v=' . $requestedSeq . '&' : '' ) . 'frame=1';
		$icon  = MembersAddress::url( $artifactId ) . '?icon=1';
		$body  = $banner
			. '<iframe sandbox="allow-scripts" referrerpolicy="no-referrer" title="' . esc_attr( $title ) . '" src="' . esc_url( $src ) . '"></iframe>';

		// #632: the Edit button for the people who may edit a page that works as a document.
		if ( $requestedSeq === 0 && $editable !== null ) {
			$saved = isset( $query[ PageEditor::SAVED ] ) && is_string( $query[ PageEditor::SAVED ] ) && ctype_digit( $query[ PageEditor::SAVED ] ) ? (int) $query[ PageEditor::SAVED ] : 0;
			$body .= '<a class="ab-edit-button" href="' . esc_url( MembersAddress::url( $artifactId ) . '?' . PageEditor::PARAM . '=1' ) . '">' . esc_html__( 'Edit', 'agent-brain' ) . '</a>';
			if ( $saved > 0 ) {
				/* translators: %d: version number. */
				$body .= '<div class="ab-saved" role="status">' . esc_html( sprintf( __( 'Saved. Version %d is live.', 'agent-brain' ), $saved ) ) . '</div>';
			}
		}

		return [
			'status'  => 200,
			'headers' => self::headers(),
			'body'    => self::page( $title, $body, $icon ),
		];
	}

	/**
	 * #750: "Nothing approved yet" for a section artifact none of whose
	 * outline sections is approved, or '' once one is. The people who can
	 * review it (curators, managers, the lead and the person behind it, a
	 * reviewer with the approval grant) get a link to the artifact's
	 * screen, where its sections are approved: the Review Queue does not
	 * list sections. Other members get the line only.
	 *
	 * #754: while sections wait for review, those reviewers get the
	 * WaitingSections bar instead, approved sections or not.
	 */
	private static function pendingNote( int $artifactId, int $actorId ): string {
		$outline  = WaitingSections::outline( $artifactId );
		$reviewer = WaitingSections::canSee( $actorId, $artifactId );

		// #754: a reviewer sees the waiting sections in the page, so the bar
		// says so whether or not anything is approved yet.
		if ( $reviewer && $outline['waiting'] !== [] ) {
			return WaitingSections::bar( $artifactId, $actorId, $outline['waiting'], $outline['approved'] === [] );
		}
		if ( $outline['approved'] !== [] ) {
			return '';
		}

		$waiting = count( $outline['waiting'] );
		$line    = $waiting > 0
			? sprintf(
				/* translators: %d: number of sections submitted for review. */
				_n( 'Nothing approved yet: %d section is waiting for review.', 'Nothing approved yet: %d sections are waiting for review.', $waiting, 'agent-brain' ),
				$waiting
			)
			: __( 'Nothing approved yet.', 'agent-brain' );

		$link = '';
		if ( $reviewer ) {
			$link = ' <a href="' . esc_url( admin_url( 'post.php?post=' . $artifactId . '&action=edit' ) ) . '">' . esc_html__( 'Open the artifact', 'agent-brain' ) . '</a>';
		}

		return '<div class="ab-pending-note" role="status"><span>' . esc_html( $line ) . '</span>' . $link . '</div>';
	}

	/**
	 * #518: the artifact's icon as a PNG: its own (a file or an inline
	 * `data:` icon of the served version), or one built from its title, as
	 * share links install it (AppIcon).
	 *
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function icon( int $artifactId ): array {
		$version = HtmlFiles::isHtml( $artifactId ) ? HtmlFiles::servedVersion( $artifactId ) : null;
		$base    = ( is_array( $version ) ? AppIcon::declaredBytes( $version['files'] ) : null ) ?? AppIcon::siteIconBytes();

		return [
			'status'  => 200,
			'headers' => [
				'Content-Type'           => 'image/png',
				'Cache-Control'          => 'private, max-age=3600',
				'X-Content-Type-Options' => 'nosniff',
			],
			'body'    => AppIcon::png( $base, (string) get_post( $artifactId )->post_title, 192 ),
		];
	}

	/**
	 * The self-contained document for the iframe: the current approved
	 * version, or the requested older one, with the CSP its frame response
	 * sends as a header. Null (→ uniform 404) when there is nothing servable.
	 *
	 * #610: the current version of a store-backed HTML page also gets the
	 * store client with the member's viewer token, and a policy that lets
	 * it reach this address ($path, the address as requested).
	 *
	 * @return array{document:string,version:int,at:string,policy:string}|null
	 */
	private static function buildDocument( int $artifactId, int $actorId, int $seq, int $now, string $host, string $path ): ?array {
		$interactive = Interactive::isActive( $artifactId );

		if ( $seq <= 0 ) {
			if ( HtmlFiles::isHtml( $artifactId ) ) {
				$version = Interactive::killSwitchOn() ? null : HtmlFiles::servedVersion( $artifactId );
				if ( $version === null || ! isset( $version['files'][ HtmlFiles::ENTRY ] ) ) {
					return null;
				}

				$store  = self::storeHead( $artifactId, $actorId, $now );
				$origin = $store !== '' ? self::origin( $host ) : '';
				// WebKit reads 'self' as the opaque origin (#398): name this address.
				$scope = $store !== '' ? $origin . $path . '/' : '';

				return [
					'document' => HtmlSite::previewDocument( $version['files'], $origin, $scope, $store ),
					'version'  => (int) $version['version'],
					'at'       => (string) gmdate( 'Y-m-d H:i:s', $now ),
					'policy'   => self::htmlPolicy( $origin, $scope ),
				];
			}

			// #754: reviewers also get the sections waiting for review, marked.
			$page = ShareRoute::renderArtifact( $artifactId, $interactive, WaitingSections::canSee( $actorId, $artifactId ) );
			if ( $page === null ) {
				return null;
			}

			return [
				'document' => self::injectMetaCsp( $page, Interactive::metaCsp( $interactive ) ),
				'version'  => 0,
				'at'       => (string) gmdate( 'Y-m-d H:i:s', $now ),
				'policy'   => self::sectionPolicy( $interactive ),
			];
		}

		// ?v={seq}: compose with #220's Versions (its guards — read_post plus
		// the per-section read gate — run with the member as actor).
		$row = Versions::get( $actorId, $artifactId, $seq );
		if ( $row instanceof \WP_Error ) {
			return null;
		}

		if ( ( $row['kind'] ?? '' ) === 'html' ) {
			$filesRow = HtmlFiles::version( $artifactId, (int) ( $row['html_version'] ?? 0 ) );
			if ( $filesRow === null || ! isset( $filesRow['files'][ HtmlFiles::ENTRY ] ) ) {
				// The referenced file set was pruned: the version row
				// outlived its bytes (#220) and there is nothing to show.
				return null;
			}

			return [
				'document' => HtmlSite::previewDocument( $filesRow['files'] ),
				'version'  => (int) ( $row['html_version'] ?? 0 ),
				'at'       => (string) ( $row['at'] ?? '' ),
				'policy'   => self::htmlPolicy(),
			];
		}

		$content = $interactive
			? Interactive::sanitize( (string) ( $row['content'] ?? '' ), ShareRoute::allowedHtml() )
			: wp_kses( ShareRoute::stripActiveElements( (string) ( $row['content'] ?? '' ) ), ShareRoute::allowedHtml(), [ 'https' ] );

		return [
			'document' => self::document(
				(string) ( $row['title'] ?? '' ),
				'<section>' . $content . '</section>',
				Interactive::metaCsp( $interactive )
			),
			'version'  => 0,
			'at'       => (string) ( $row['at'] ?? '' ),
			'policy'   => self::sectionPolicy( $interactive ),
		];
	}

	/**
	 * Header CSP of an HTML artifact's frame: the share-link policy, with
	 * `sandbox allow-scripts` (an opaque origin even when the frame URL is
	 * opened on its own) and framing allowed by the members page only.
	 * #610: a store-backed page names this site and its own address (see
	 * HtmlSite::csp()), so its store calls connect.
	 */
	private static function htmlPolicy( string $origin = '', string $scope = '' ): string {
		return HtmlSite::csp( true, $origin, $scope ) . "; sandbox allow-scripts; frame-ancestors 'self'";
	}

	/**
	 * #610: the store client and a fresh viewer token for this member
	 * (share link id 0: the members address, never a share link), or ''
	 * when the page has no store. Same identity as a members link's viewer
	 * exchange (ViewerIdentity); the token lasts ViewerToken::TTL. Not
	 * audited: it comes with every view of the page, like the view itself.
	 */
	private static function storeHead( int $artifactId, int $actorId, int $now ): string {
		if ( ! StoreMode::isActive( $artifactId ) || ViewerMode::killSwitchOn() ) {
			return '';
		}

		$viewer = ViewerIdentity::describe( $actorId, $artifactId );
		if ( $viewer === null ) {
			return '';
		}

		[ $kid, $key ] = ViewerKeys::current();
		$name          = $viewer['name'] !== '' ? $viewer['name'] : '#' . $viewer['principal_id'];
		$claims        = ViewerToken::claims( 0, $artifactId, $viewer['principal_id'], $viewer['principal_type'], $name, $viewer['roles'], $now );

		return StoreClient::membersTag( ViewerToken::sign( $claims, $kid, $key ) );
	}

	/** Header CSP of a section artifact's frame, framed by the members page only. */
	private static function sectionPolicy( bool $interactive ): string {
		return $interactive ? Interactive::interactiveCsp( "'self'" ) : Interactive::staticCsp( "'self'" );
	}

	/**
	 * @return array<string, string>
	 */
	private static function frameHeaders( string $policy ): array {
		return [
			'Content-Security-Policy' => $policy,
			'X-Frame-Options'         => 'SAMEORIGIN',
		] + self::headers();
	}

	/**
	 * Inject the meta CSP right after <head> (a meta before the doctype
	 * would switch the page to quirks mode), mirroring
	 * HtmlSite::previewDocument()'s injection.
	 */
	private static function injectMetaCsp( string $html, string $policy ): string {
		$meta   = '<meta http-equiv="Content-Security-Policy" content="' . esc_attr( $policy ) . '">';
		$injected = preg_replace( '#<head\b[^>]*>#i', '$0' . $meta, $html, 1, $count );

		return $count === 1 && is_string( $injected ) ? $injected : $meta . $html;
	}

	/**
	 * A self-contained document for a section snapshot: the brain design
	 * (#630) as on the share page, the share CSP as a meta tag, no scripts
	 * in static mode.
	 */
	private static function document( string $title, string $bodyHtml, string $metaCsp ): string {
		return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<meta name="referrer" content="no-referrer">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta http-equiv="Content-Security-Policy" content="' . esc_attr( $metaCsp ) . '">'
			. '<title>' . esc_html( $title ) . '</title>'
			. \AgentBrain\Modules\Artifacts\Design::styleTag()
			. '</head><body><main class="ab-page">' . \AgentBrain\Modules\Artifacts\Design::brandLine()
			. '<h1>' . esc_html( $title ) . '</h1>' . $bodyHtml . '</main></body></html>';
	}

	/**
	 * #632: the members page origin as the browser sees it, the only one the
	 * editing frame talks to.
	 */
	private static function origin( string $host ): string {
		return set_url_scheme( 'http://' . strtolower( trim( $host ) ) );
	}

	/**
	 * #632: the frame in edit mode: the page's document with the editor.
	 *
	 * @param array{version:int,files:array<string, mixed>} $version
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function editFrame( array $version, string $host ): array {
		return [
			'status'  => 200,
			'headers' => self::frameHeaders( self::htmlPolicy() ),
			'body'    => PageEditor::injectEditor( HtmlSite::previewDocument( $version['files'] ), self::origin( $host ) ),
		];
	}

	/**
	 * #632: the page in edit mode (PageEditor::editPage), framed by nothing
	 * off-site; after a save the members page with "Saved".
	 *
	 * @param array{version:int,files:array<string, mixed>} $version
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function editPage( int $artifactId, array $version ): array {
		$back = MembersAddress::url( $artifactId );

		return PageEditor::editPage(
			$artifactId,
			$version,
			self::headers(),
			[
				'back'      => $back,
				'frame'     => $back . '?' . PageEditor::PARAM . '=1&frame=1',
				'icon'      => $back . '?icon=1',
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'done'      => '',
				'ancestors' => "'self'",
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function headers(): array {
		return [
			'Content-Type'           => 'text/html; charset=utf-8',
			'X-Robots-Tag'           => 'noindex, nofollow',
			'Referrer-Policy'        => 'no-referrer',
			'Cache-Control'          => 'private, no-store',
			'Pragma'                 => 'no-cache',
			'X-Content-Type-Options' => 'nosniff',
			// The members page itself may be framed by nothing off-site; it
			// frames only its own ?frame=1 document, sandboxed by the iframe
			// attribute and by that response's own CSP.
			'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; frame-src 'self'; frame-ancestors 'self'",
		];
	}

	private static function page( string $title, string $bodyHtml, string $icon = '' ): string {
		return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<meta name="referrer" content="no-referrer">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . esc_html( $title ) . '</title>'
			. ( $icon !== '' ? '<link rel="icon" type="image/png" href="' . esc_url( $icon ) . '"><link rel="apple-touch-icon" href="' . esc_url( $icon ) . '">' : '' )
			. '<style>'
			. 'html,body{margin:0;padding:0;height:100%;background:#f6f7f9}'
			. '@media (prefers-color-scheme:dark){html,body{background:#111318}}'
			. '.ab-version-banner{box-sizing:border-box;padding:.6rem 1rem;background:#fcf9e8;border-bottom:1px solid #f0c930;color:#3c434a;font:14px/1.4 system-ui,sans-serif}'
			. '.ab-version-banner a{margin-left:.5em;white-space:nowrap}'
			. 'iframe{display:block;width:100%;border:0;height:100vh}'
			. '.ab-pending-note{box-sizing:border-box;padding:.6rem 1rem;background:#fff;border-bottom:1px solid #dcdcde;color:#3c434a;font:14px/1.4 system-ui,sans-serif}'
			. '.ab-pending-note a{margin-left:.5em;white-space:nowrap}'
			. '.ab-review-bar{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem .8rem}'
			. '.ab-review-bar form{margin:0}.ab-review-bar a{margin-left:0}'
			. '.ab-review-bar button{padding:.35rem .9rem;border:0;border-radius:999px;background:#16a34a;color:#fff;font:600 14px/1.2 system-ui,sans-serif;cursor:pointer}'
			. '.ab-review-bar button:focus-visible{outline:3px solid #16a34a;outline-offset:2px}'
			. '@media (prefers-color-scheme:dark){.ab-pending-note{background:#1d2027;border-color:#2f333c;color:#dcdcde}.ab-pending-note a{color:#8ab4f8}}'
			. 'body:has(.ab-version-banner) iframe,body:has(.ab-pending-note) iframe{height:calc(100vh - 3rem)}'
			. '.ab-edit-button{position:fixed;right:16px;bottom:16px;padding:10px 18px;border-radius:999px;background:#16181d;color:#fff;font:600 15px/1.2 system-ui,sans-serif;text-decoration:none;box-shadow:0 4px 14px rgba(0,0,0,.25)}'
			. '.ab-edit-button:focus-visible{outline:3px solid #16a34a;outline-offset:2px}'
			. '.ab-saved{position:fixed;left:50%;top:16px;transform:translateX(-50%);padding:10px 16px;border-radius:10px;background:#16a34a;color:#fff;font:600 14px/1.3 system-ui,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.2);animation:ab-saved 5s forwards}'
			. '@keyframes ab-saved{0%,80%{opacity:1}100%{opacity:0;visibility:hidden}}'
			. '</style>'
			. '</head><body>' . $bodyHtml . '</body></html>';
	}

	/**
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function redirect( string $location ): array {
		return [
			'status'  => 302,
			'headers' => [ 'Location' => $location ] + self::headers(),
			'body'    => '',
		];
	}
}
