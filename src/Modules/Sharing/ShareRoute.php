<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Block;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\Design;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Artifacts\Preview;
use AgentBrain\Modules\Artifacts\Stages;

/**
 * GET https://{share address}/share/{artifactId}/{token}
 *
 * The share address is the configured preview host when one is set, and
 * otherwise this site's own address (#286 same-address mode). Every failure
 * (unknown / wrong / expired / revoked / IP-mismatched token, private link
 * without a viewer token on an API subpath, kill switch) is the same 404 —
 * except one #219 case: a private-link PAGE request (GET|HEAD, the entry
 * itself) without a usable viewer token is redirected through the sign-in
 * round trip on the main site (ShareAuth) instead of 404'd, so a member
 * holding the link lands on the page after one login.
 *
 * #643: the render never reads the WordPress session. The one exception
 * is the page editor: a person signed in on this site who may edit the
 * page on its members address gets the same editor on the page path
 * (ShareEditor), after every check above; for everyone else nothing
 * changes.
 */
final class ShareRoute {

	/**
	 * @return array{artifact_id:int,token:string}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/share/(\d+)/([^/]+)/?$#', $path, $m ) !== 1 ) {
			return null;
		}

		return [
			'artifact_id' => (int) $m[1],
			'token'       => rawurldecode( $m[2] ),
		];
	}

	/**
	 * Filter agent_brain/private_mode_allow_request: a private-mode gate (the
	 * deployment's mu-plugin) asks whether a request needs no login. Only the
	 * share route on the share address does — the preview host when one is
	 * configured, otherwise the site's own host in same-address mode; the
	 * same path on any other host stays gated. Unknown tokens still get the
	 * uniform 404 from the route.
	 *
	 * @param mixed $allow Previous filter value.
	 */
	public static function allowPrivateRequest( $allow, string $path = '', string $host = '' ): bool {
		if ( $allow === true ) {
			return true;
		}

		return Preview::servesShareRequests( $host )
			&& ( preg_match( '#^/share/_whoami$#', $path ) === 1
				|| preg_match( '#^/share/\d+/[A-Za-z0-9_-]+(?:/(?:[A-Za-z0-9_-](?:[A-Za-z0-9._ -]|%20)*/?)*)?$#', $path ) === 1 );
	}

	/**
	 * @param string[]|null $embedOrigins null for a plain link (never framable);
	 *                                    for an embed link the allowlist (empty = nobody may frame).
	 * @return array<string, string>
	 */
	public static function securityHeaders( bool $interactive = false, ?array $embedOrigins = null ): array {
		$frameAncestors = "'none'";
		if ( $embedOrigins !== null && $embedOrigins !== [] ) {
			$directive      = EmbedPolicy::frameAncestors( $embedOrigins );
			$frameAncestors = substr( $directive, strlen( 'frame-ancestors ' ) );
		}

		$headers = [
			'Content-Type'            => 'text/html; charset=utf-8',
			'X-Robots-Tag'            => 'noindex, nofollow',
			'Referrer-Policy'         => 'no-referrer',
			'Cache-Control'           => 'private, no-store',
			'Pragma'                  => 'no-cache',
			'X-Frame-Options'         => 'DENY',
			'X-Content-Type-Options'  => 'nosniff',
			'Content-Security-Policy' => $interactive ? Interactive::interactiveCsp( $frameAncestors ) : Interactive::staticCsp( $frameAncestors ),
		];

		if ( $frameAncestors !== "'none'" ) {
			// Embed link with a non-empty allowlist: framing is governed by CSP
			// frame-ancestors alone (X-Frame-Options has no allowlist form).
			unset( $headers['X-Frame-Options'] );
		}

		return $headers;
	}

	/**
	 * Pure-ish request handler (no output, no exit) so it can be tested.
	 * Returns null when the request is not a share request on the share
	 * address (the preview host when configured, else the site's own host).
	 *
	 * @param array<string, mixed> $server $_SERVER-shaped.
	 * @param string|null          $body   Raw request body (POST/DELETE), when already read (tests).
	 * @param array<string, mixed>|null $cookies $_COOKIE-shaped, when already read (tests).
	 * @param int                       $memberId #643: the person signed in on this site (Preview::shareMember), 0 = none; only ShareEditor reads it.
	 * @return array{status:int,headers:array<string,string>,body:string}|null
	 */
	public static function handle( string $host, string $uri, string $method, array $server, ?string $body = null, ?array $cookies = null, int $memberId = 0 ): ?array {
		if ( ! Preview::servesShareRequests( $host ) ) {
			return null;
		}

		// #285: the reachability probe. A short-lived value signed by this
		// install's identity (Whoami); answered before any token handling.
		if ( (string) wp_parse_url( $uri, PHP_URL_PATH ) === Whoami::PATH ) {
			if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) ) {
				return null;
			}

			$headers                 = self::securityHeaders();
			$headers['Content-Type'] = 'text/plain; charset=utf-8';

			return [ 'status' => 200, 'headers' => $headers, 'body' => Whoami::payload() ];
		}

		$embedArtifact = EmbedRoute::parsePath( $uri );
		if ( $embedArtifact !== null ) {
			return EmbedRoute::handle( $embedArtifact, $uri, $method, $server );
		}

		// Below the token: files of an HTML artifact (/share/{id}/{token}/app.js).
		$site  = HtmlSite::parsePath( $uri );
		$route = self::parsePath( $uri ) ?? ( $site !== null && $site['path'] !== '' ? $site : null );
		if ( $route === null || $site === null ) {
			return null;
		}

		// ART-23 (#163) + ART-27 (#167): store and uploads API paths below the
		// link answer their own methods and rate limit; everything else stays
		// GET|HEAD-only.
		$isStore   = \AgentBrain\Modules\DataStore\StoreRoute::parsePath( $uri ) !== null;
		$isUploads = \AgentBrain\Modules\Uploads\UploadsRoute::parsePath( $uri ) !== null;
		// #469: seats are claimed with POST (and preflighted: the secret rides in a header).
		$seatRoute = \AgentBrain\Modules\DataStore\SeatRoute::parsePath( $uri );

		// ART-26 (#166): the ask endpoint is POST by design (a question in the
		// body); the ask path alone is also exempt from the GET|HEAD-only rule.
		$askRoute = \AgentBrain\Modules\AskBrain\Route::parsePath( $uri );
		$isAskPost = $askRoute !== null && strtoupper( $method ) === 'POST';

		if ( ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) && ! $isStore && ! $isUploads && ! $isAskPost && $seatRoute === null ) || Config::disabled() ) {
			return self::notFound();
		}

		$ip       = Config::clientIp( $server );
		$clientIp = $ip !== '' ? Config::ipHash( $ip ) : '';
		$link     = ShareLinks::findByToken( $route['token'] );

		// A page of an HTML artifact fetches its files one request each.
		$isHtml = HtmlFiles::isHtml( $route['artifact_id'] );
		if ( ! $isStore && $seatRoute === null && ! RateLimiter::allow( $clientIp !== '' ? $clientIp : str_repeat( '0', 64 ), $link !== null ? (int) $link['id'] : 0, null, $isHtml ? RateLimiter::PER_MINUTE * 5 : RateLimiter::PER_MINUTE ) ) {
			$response                           = self::response( 429, 'Too many requests.' );
			$response['headers']['Retry-After'] = '60';

			return $response;
		}

		$result = ShareLinks::validate( $route['token'], $route['artifact_id'], $clientIp );
		if ( $result['status'] !== ShareLinks::STATUS_OK || $result['link'] === null ) {
			return self::notFound();
		}

		// ART-24 (#164): the viewer endpoint of a validated share link, for
		// both plain and HTML artifacts. It is also the identity exchange a
		// private link's viewer performs FIRST, so it stays reachable
		// anonymously; everything else below requires the viewer token the
		// exchange hands out (#286).
		$viewer = ViewerRoute::parsePath( $uri );

		// #219: the sign-in ticket exchange. A single-use ?ab_ticket= on the
		// page path (issued by ShareAuth after the main-site login) becomes
		// the viewer session: a host-only, path-scoped cookie for document
		// requests plus a URL fragment carrying the token for the page's own
		// scripts. A ticket presented anywhere else is the uniform 404.
		$queryParams = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $queryParams );
		$ticketParam = isset( $queryParams[ ShareTicket::QUERY_PARAM ] ) && is_string( $queryParams[ ShareTicket::QUERY_PARAM ] )
			? $queryParams[ ShareTicket::QUERY_PARAM ]
			: null;
		if ( $ticketParam !== null && $ticketParam !== '' ) {
			$isPagePath = $viewer === null && ! $isStore && ! $isUploads && $askRoute === null && $seatRoute === null && $site['path'] === '';

			return $isPagePath
				? self::exchangeTicket( $route, $result['link'], $ticketParam, time() )
				: self::notFound();
		}

		// The viewer cookie set by the ticket exchange (#219): the same
		// ViewerToken, presented by the browser on document requests.
		$viewerCookie = null;
		if ( is_array( $cookies ) ) {
			$viewerCookie = isset( $cookies[ ShareTicket::COOKIE ] ) && is_string( $cookies[ ShareTicket::COOKIE ] ) ? $cookies[ ShareTicket::COOKIE ] : null;
		} elseif ( isset( $_COOKIE[ ShareTicket::COOKIE ] ) && is_string( $_COOKIE[ ShareTicket::COOKIE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a passive token presentation, verified by ViewerToken::verify().
			$viewerCookie = sanitize_text_field( wp_unslash( $_COOKIE[ ShareTicket::COOKIE ] ) );
		}

		// #286: per-request share context (serving mode, link privacy, viewer
		// identity). A private link without a valid viewer token is the same
		// uniform 404 as a bad token — except one #219 case: the entry page
		// with NO token presented at all redirects through the sign-in round
		// trip on the main site (an expired cookie re-auths seamlessly). A
		// token that WAS presented but failed (wrong link, expired) keeps
		// the 404 — the #286 byte-identical guarantee.
		$context = ShareContext::resolve( $result['link'], $uri, $body, $viewer !== null, time(), $viewerCookie );
		if ( $context === null ) {
			$tokenPresented = isset( $queryParams['viewer_token'] ) || isset( $queryParams['ab_viewer_token'] );
			$isEntryPage    = $viewer === null && ! $isStore && ! $isUploads && $askRoute === null && $site['path'] === '';
			if ( ! $tokenPresented && $isEntryPage && in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) ) {
				return self::signInRedirect( $result['link'], $uri );
			}

			return self::notFound();
		}

		// ART-23 (#163): the store endpoints of a validated link, for both
		// plain and HTML artifacts. StoreRoute itself answers the uniform 404
		// when the artifact has the store off.
		if ( $isStore ) {
			$store = \AgentBrain\Modules\DataStore\StoreRoute::parsePath( $uri );
			if ( $store !== null && $store['artifact_id'] === $route['artifact_id'] && hash_equals( $route['token'], $store['token'] ) ) {
				return \AgentBrain\Modules\DataStore\StoreRoute::respond( $store, $result['link'], $method, $uri, null, time(), $clientIp, self::seatSecret( $server ) );
			}
		}

		// #469: seats (personal links on one public link), ahead of the HTML file lookup.
		if ( $seatRoute !== null && $seatRoute['artifact_id'] === $route['artifact_id'] && hash_equals( $route['token'], $seatRoute['token'] ) ) {
			return \AgentBrain\Modules\DataStore\SeatRoute::respond( $seatRoute, $result['link'], $method, $uri, $body, self::seatSecret( $server ), $clientIp, time() );
		}

		// #469: the update signal, the web app manifest and the install icon.
		$app = ShareApp::parsePath( $uri );
		if ( $app !== null && $app['artifact_id'] === $route['artifact_id'] && hash_equals( $route['token'], $app['token'] ) ) {
			return ShareApp::respond( $app, $method, $uri, $host );
		}

		// #375: the page-store client script (window.tomatio.store), for
		// both plain and HTML artifacts; ahead of the HTML file lookup.
		$storeClient = \AgentBrain\Modules\DataStore\StoreClient::parsePath( $uri );
		if ( $storeClient !== null && $storeClient['artifact_id'] === $route['artifact_id'] && hash_equals( $route['token'], $storeClient['token'] ) ) {
			return \AgentBrain\Modules\DataStore\StoreClient::respond( $method );
		}

		// ART-24 (#164): the viewer endpoint of a validated share link, for
		// both plain and HTML artifacts. Serves anonymous by default; a
		// member's single-use code yields a signed viewer token.
		if ( $viewer !== null && $viewer['artifact_id'] === $route['artifact_id'] && hash_equals( $route['token'], $viewer['token'] ) ) {
			return ViewerRoute::respond( $viewer, $result['link'], $method, $uri, $body, time() );
		}

		// ART-27 (#167): asset uploads under the share path: list/download for
		// anyone holding the link; member upload/delete with a viewer token.
		$uploads = \AgentBrain\Modules\Uploads\UploadsRoute::parsePath( $uri );
		if ( $uploads !== null && $uploads['artifact_id'] === $route['artifact_id'] && hash_equals( $route['token'], $uploads['token'] ) ) {
			return \AgentBrain\Modules\Uploads\UploadsRoute::respond( $uploads, $result['link'], $method, $uri, $server, $body );
		}

		// ART-25 (#165): read-only live brain data in the artifact's scope.
		// Intercepted for both plain and HTML artifacts, before the HTML file
		// lookup, so the path is a JSON endpoint or a uniform 404 — never a
		// way to render the artifact.
		$brain = \AgentBrain\Modules\BrainRead\Route::parsePath( $uri );
		if ( $brain !== null && $brain['artifact_id'] === $route['artifact_id'] && hash_equals( $route['token'], $brain['token'] ) ) {
			return \AgentBrain\Modules\BrainRead\Route::respond( $brain, $result['link'], $method, $uri, null, time(), $server );
		}

		// ART-26 (#166): ask the brain a question from a page — answered by
		// the site's configured AI provider from the artifact's scope, with
		// citations. POST only; dispatched before the HTML file lookup, so
		// the path is a JSON endpoint or a uniform 404 — never a way to
		// render the artifact.
		if ( $askRoute !== null && $askRoute['artifact_id'] === $route['artifact_id'] && hash_equals( $route['token'], $askRoute['token'] ) ) {
			return \AgentBrain\Modules\AskBrain\Route::respond( $askRoute, $result['link'], $method, $uri, null, time(), $server );
		}

		if ( $isHtml ) {
			// #643: a signed-in person who may edit the page gets the editor;
			// everyone else, and every other file, exactly the page.
			return ShareEditor::respond( $site, $result['link'], $method, $host, $uri, $memberId )
				?? HtmlSite::respond( $site, $result['link'], $method, $host, $uri );
		}

		// Decided per request from the artifact's current flag, never from the link.
		$interactive = Interactive::isActive( $route['artifact_id'] );
		$body        = self::renderArtifact( $route['artifact_id'], $interactive );
		if ( $body === null ) {
			return self::notFound();
		}

		ShareLinks::recordOpen( (int) $result['link']['id'] );
		// #221: one analytics row per share-page open (version 0 = block artifact).
		ViewAnalytics::record( $route['artifact_id'], (int) $result['link']['id'], 0, ViewAnalytics::viewerHashFor( $ip ) );

		$response           = self::response( 200, $body );
		$embed                = EmbedPolicy::normalizeKind( (string) ( $result['link']['kind'] ?? '' ) ) === EmbedPolicy::KIND_EMBED;
		$response['headers'] = self::securityHeaders( $interactive, $embed ? EmbedSettings::allowedOrigins() : null );

		return $response;
	}

	/**
	 * template_redirect entry (via Preview::maybeServe).
	 *
	 * @param int $memberId #643: the person signed in on this site, read before the request went logged out (Preview::shareMember).
	 */
	public static function serve( int $memberId = 0 ): void {
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : 'GET';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- consumed only by ClientIp, which validates IPs.
		$response = self::handle( $host, $uri, $method, $_SERVER, null, null, $memberId );
		if ( $response === null ) {
			return;
		}

		status_header( $response['status'] );
		if ( ! headers_sent() ) {
			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value, true );
			}
		}

		if ( strtoupper( $method ) !== 'HEAD' ) {
			echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- fully built and escaped/sanitized by renderArtifact().
		}

		exit;
	}

	/**
	 * #469: the seat secret a page presents (X-Tomatio-Seat), or null. Only
	 * ever hashed; never logged.
	 *
	 * @param array<string, mixed> $server $_SERVER-shaped.
	 */
	private static function seatSecret( array $server ): ?string {
		$value = $server['HTTP_X_TOMATIO_SEAT'] ?? null;

		return is_string( $value ) && preg_match( '/^[A-Za-z0-9_-]{20,64}$/', $value ) === 1 ? $value : null;
	}

	/**
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function notFound(): array {
		return self::response( 404, 'Not found.' );
	}

	/**
	 * #219: consume the single-use sign-in ticket issued by ShareAuth and
	 * establish the viewer session for this private link. Success is a 302
	 * to the bare share URL: the signed ViewerToken travels in the fragment
	 * (never a server or access log — the ART-24 convention) for the page's
	 * own scripts, and in a host-only, path-scoped, HttpOnly cookie for
	 * document requests, so reloads keep working until the token (at most
	 * the ViewerToken TTL, and never past the link's own expiry/revocation)
	 * runs out; the next anonymous page request then simply repeats the
	 * sign-in round trip while the main-site session lives. Every failure is
	 * the uniform 404.
	 *
	 * @param array{artifact_id:int,token:string} $route Validated route context.
	 * @param array<string, mixed>                $link  The validated share link row.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function exchangeTicket( array $route, array $link, string $ticket, int $now ): array {
		$consumed = ShareTicket::consume( $ticket, (int) $link['id'], $route['artifact_id'], $now );
		if ( $consumed === null
			|| ! ViewerMode::isActive( $route['artifact_id'] )
			|| ShareLinks::normalizeViewerMode( (string) ( $link['viewer_mode'] ?? '' ) ) !== ShareLinks::VIEWER_MEMBERS
		) {
			return self::notFound();
		}

		$viewer = ViewerIdentity::describe( $consumed['user_id'], $route['artifact_id'] );
		if ( $viewer === null ) {
			return self::notFound();
		}

		[ $kid, $key ] = ViewerKeys::current();
		$claims        = ViewerToken::claims( (int) $link['id'], $route['artifact_id'], $viewer['principal_id'], $viewer['principal_type'], $viewer['name'], $viewer['roles'], $now );
		$token         = ViewerToken::sign( $claims, $kid, $key );

		ShareLinks::audit( $viewer['principal_id'], 'viewer_token_issued', 'ok', (int) $link['id'], [ 'artifact_id' => $route['artifact_id'], 'principal_type' => $viewer['principal_type'], 'via' => 'share_ticket' ] );

		$ttl    = max( 1, $claims['exp'] - $now );
		$cookie = ShareTicket::COOKIE . '=' . rawurlencode( $token )
			. '; Path=/share/' . $route['artifact_id'] . '/' . rawurlencode( $route['token'] ) . '/'
			. '; Max-Age=' . $ttl . '; HttpOnly; SameSite=Lax';
		if ( wp_parse_url( ShareLinks::baseUrl(), PHP_URL_SCHEME ) === 'https' ) {
			// Preview-host mode is https by construction; same-address mode
			// may be plain http on local installs, where a Secure cookie
			// would never be stored.
			$cookie .= '; Secure';
		}

		$headers = self::securityHeaders();
		$headers['Location']   = '/share/' . $route['artifact_id'] . '/' . rawurlencode( $route['token'] ) . '/'
			. '#' . ViewerRoute::TOKEN_FRAGMENT_PARAM . '=' . rawurlencode( $token );
		$headers['Set-Cookie'] = $cookie;

		return [ 'status' => 302, 'headers' => $headers, 'body' => '' ];
	}

	/**
	 * #219: the private-link page without a usable viewer token goes through
	 * the sign-in round trip on the MAIN site: ShareAuth checks the session,
	 * mints the single-use ticket and sends the viewer back with
	 * ?ab_ticket=. The path stays here under a ShareReturn handle (#391);
	 * ShareAuth validates it against the link's artifact and rebuilds the
	 * share host, so this is never an open redirect.
	 *
	 * @param array<string, mixed> $link The validated share link row.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function signInRedirect( array $link, string $uri ): array {
		// #391: the page path holds the share token; it stays here, under a
		// single-use handle. The main site only ever sees the link id and
		// the handle.
		$back = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$auth = add_query_arg(
			[
				ShareAuth::QUERY_PARAM  => (string) $link['id'],
				ShareAuth::RETURN_PARAM => ShareReturn::create( (int) $link['id'], $back ),
			],
			home_url( '/' )
		);

		return [
			'status'  => 302,
			'headers' => [ 'Location' => $auth ] + self::securityHeaders(),
			'body'    => '',
		];
	}

	/**
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function response( int $status, string $body ): array {
		if ( $status !== 200 ) {
			$body = self::page( 'Not available', '<p>' . esc_html( $body ) . '</p>', '', false );
		}

		return [
			'status'  => $status,
			'headers' => self::securityHeaders(),
			'body'    => $body,
		];
	}

	/**
	 * Static HTML of the approved sections, re-sanitized (no scripts, no
	 * forms, https links/images only). Null when the artifact is missing.
	 *
	 * #754: $waiting adds the sections submitted for review, each marked
	 * "Waiting for review", for the reviewers on the members address only.
	 */
	public static function renderArtifact( int $artifactId, bool $interactive = false, bool $waiting = false ): ?string {
		$artifact = get_post( $artifactId );
		if ( ! ShareLinks::isServable( $artifact ) ) {
			return null;
		}

		$html     = '';
		$sections = 0;
		foreach ( parse_blocks( (string) $artifact->post_content ) as $block ) {
			if ( ( $block['blockName'] ?? null ) !== Block::NAME || ! isset( $block['attrs']['sectionId'] ) ) {
				continue;
			}

			$sectionId = (int) $block['attrs']['sectionId'];
			$section   = get_post( $sectionId );
			if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE || (int) $section->post_parent !== $artifactId ) {
				// A section of another artifact must never be published here.
				continue;
			}
			$state     = Stages::sectionState( $sectionId );
			$isWaiting = $waiting && $state === Cpts::SECTION_STATE_SUBMITTED;
			if ( $state !== Cpts::SECTION_STATE_APPROVED && ! $isWaiting ) {
				continue;
			}

			$content = $interactive
				? Interactive::sanitize( (string) $section->post_content, self::allowedHtml() )
				: wp_kses( self::stripActiveElements( (string) $section->post_content ), self::allowedHtml(), [ 'https' ] );
			$html   .= $isWaiting
				? '<section class="ab-waiting"><p class="ab-status ab-status--in-progress">' . esc_html__( 'Waiting for review', 'agent-brain' ) . '</p>' . $content . '</section>'
				: '<section>' . $content . '</section>';
			++$sections;
		}

		$updated = strtotime( (string) $artifact->post_modified_gmt . ' UTC' );
		$meta    = sprintf(
			/* translators: 1: number of sections, 2: date */
			_n( '%1$d section, updated %2$s', '%1$d sections, updated %2$s', $sections, 'agent-brain' ),
			$sections,
			$updated ? wp_date( 'j F Y', $updated ) : ''
		);

		return self::page( (string) $artifact->post_title, self::withContents( $html ), $meta );
	}

	/**
	 * Give the h2/h3 headings ids and put a table of contents in front when
	 * there are at least three. The ids are generated here (any id the
	 * section carried is replaced), so they cannot clash with each other.
	 */
	private static function withContents( string $html ): string {
		$items = [];
		$out   = preg_replace_callback(
			'#<h([23])(\s[^>]*)?>(.*?)</h\1>#is',
			static function ( array $m ) use ( &$items ): string {
				$id        = 'ab-h-' . ( count( $items ) + 1 );
				$items[]   = [ (int) $m[1], $id, trim( wp_strip_all_tags( $m[3] ) ) ];
				$attrs     = preg_replace( '#\sid=("[^"]*"|\'[^\']*\')#i', '', $m[2] );

				return '<h' . $m[1] . ' id="' . $id . '"' . $attrs . '>' . $m[3] . '</h' . $m[1] . '>';
			},
			$html
		);
		if ( ! is_string( $out ) || count( $items ) < 3 ) {
			return $html;
		}

		$toc = '<nav class="ab-toc" aria-label="' . esc_attr__( 'Contents', 'agent-brain' ) . '"><strong>' . esc_html__( 'Contents', 'agent-brain' ) . '</strong><ul>';
		foreach ( $items as [ $level, $id, $text ] ) {
			$toc .= '<li class="ab-toc--h' . $level . '"><a href="#' . esc_attr( $id ) . '">' . esc_html( $text ) . '</a></li>';
		}

		return $toc . '</ul></nav>' . $out;
	}

	/**
	 * Static mode: drop script and style elements WITH their contents. wp_kses
	 * removes the tags but keeps the inner text, which would show up as
	 * visible code. An unclosed element is dropped to the end of the input.
	 */
	public static function stripActiveElements( string $html ): string {
		// Repeat until stable so a nested form such as <scr<script></script>ipt> cannot reassemble.
		do {
			$before = $html;
			$html   = preg_replace( '#<(script|style)\b[^>]*>.*?(?:</\1\s*>|$)#is', '', $html );
			if ( ! is_string( $html ) ) {
				return '';
			}
		} while ( $html !== $before );

		return $html;
	}

	/**
	 * @return array<string, array<string, bool>>
	 */
	public static function allowedHtml(): array {
		$common = [ 'class' => true, 'id' => true ];
		$tags   = [];
		foreach ( [ 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'strong', 'em', 'b', 'i', 'u', 's', 'sub', 'sup', 'br', 'hr', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'span', 'div', 'cite', 'mark' ] as $tag ) {
			$tags[ $tag ] = $common;
		}
		$tags['th']  = $common + [ 'colspan' => true, 'rowspan' => true, 'scope' => true ];
		$tags['td']  = $common + [ 'colspan' => true, 'rowspan' => true ];
		$tags['a']   = $common + [ 'href' => true, 'title' => true ];
		$tags['img'] = $common + [ 'src' => true, 'alt' => true, 'width' => true, 'height' => true ];

		return $tags;
	}

	/**
	 * #630: a document in the brain design: the brain's favicons and brand
	 * line, its title and meta line, then the sections. The not-found page
	 * leaves the brand line out.
	 */
	private static function page( string $title, string $bodyHtml, string $meta = '', bool $brand = true ): string {
		return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<meta name="referrer" content="no-referrer">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . esc_html( $title ) . '</title>'
			. Design::faviconTags()
			. Design::styleTag()
			. '</head><body><main class="ab-page">'
			. ( $brand ? Design::brandLine() : '' )
			. '<h1>' . esc_html( $title ) . '</h1>'
			. ( $meta !== '' ? '<p class="ab-meta">' . esc_html( $meta ) . '</p>' : '' )
			. $bodyHtml . '</main></body></html>';
	}
}
