<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Preview;

/**
 * #286: the per-request share context, resolved once in ShareRoute after the
 * link token validated. It carries everything downstream code needs to know
 * about WHO is being served and HOW, in both serving modes:
 *
 *   mode    'preview_host' | 'site_address' (which address answers)
 *   privacy 'private' | 'public' (who the link admits)
 *   viewer  null (anonymous) or the ViewerToken claims of a named member
 *
 * Private links: every path below the link — the page itself, HTML artifact
 * files, the data store, uploads, brain-read, ask — requires a valid viewer
 * token (presented the same way the ART-23/25/27 endpoints already accept
 * it: the viewer_token query parameter or a viewer_token JSON field in a
 * POST body), except the viewer.json exchange endpoint itself, which is how
 * a member obtains the token. resolve()
 * returns null for anything else, which ShareRoute renders as the uniform
 * 404, so an anonymous request to a private link is indistinguishable from a
 * bad token.
 *
 * This object is the seam #219 (permanent members address / sign-in ticket
 * flow) builds on: a future members surface can resolve richer identity into
 * ->viewer without touching the route dispatchers.
 */
final class ShareContext {

	public string $mode;
	public string $privacy;

	/**
	 * @var array{share_link_id:int,artifact_id:int,principal_id:int,principal_type:string,name:string,roles:array<int,string>,exp:int,nonce:string}|null
	 */
	public ?array $viewer;

	/**
	 * @param array{share_link_id:int,artifact_id:int,principal_id:int,principal_type:string,name:string,roles:array<int,string>,exp:int,nonce:string}|null $viewer
	 */
	private function __construct( string $mode, string $privacy, ?array $viewer ) {
		$this->mode    = $mode;
		$this->privacy = $privacy;
		$this->viewer  = $viewer;
	}

	public function isPrivate(): bool {
		return $this->privacy === ShareLinks::PRIVACY_PRIVATE;
	}

	/**
	 * @param array<string, mixed> $link            The validated share link row.
	 * @param bool                 $isViewerEndpoint The viewer.json exchange must
	 *                                               stay reachable anonymously: it
	 *                                               is how a member GETS a token.
	 * @param string|null          $viewerCookie     The ab_viewer_token cookie the
	 *                                               #219 ticket exchange set for
	 *                                               this link's path, when present.
	 * @return self|null Null when a private link got no usable viewer token.
	 */
	public static function resolve( array $link, string $uri, ?string $body, bool $isViewerEndpoint, int $now, ?string $viewerCookie = null ): ?self {
		$mode    = Preview::effectiveHost()['mode'];
		$privacy = ShareLinks::normalizePrivacy( (string) ( $link['privacy'] ?? '' ) );
		$viewer  = self::viewerFromRequest( $link, $uri, $body, $now, $viewerCookie );

		if ( $privacy === ShareLinks::PRIVACY_PRIVATE && ! $isViewerEndpoint && $viewer === null ) {
			return null;
		}

		return new self( $mode, $privacy, $viewer );
	}

	/**
	 * A valid, matching viewer token presented with the request, or null.
	 * Accepting the token on public links too means a named viewer's identity
	 * rides along whenever they present one; only private links REQUIRE it.
	 *
	 * The token arrives as the viewer_token query parameter (the convention
	 * the ART-23/25/27 endpoints already use) or ab_viewer_token, as the
	 * host-only viewer cookie the #219 ticket exchange set for this link's
	 * path, and as a viewer_token field in a JSON POST body.
	 *
	 * @param array<string, mixed> $link
	 * @param string|null          $viewerCookie The ab_viewer_token cookie value, when present.
	 * @return array{share_link_id:int,artifact_id:int,principal_id:int,principal_type:string,name:string,roles:array<int,string>,exp:int,nonce:string}|null
	 */
	private static function viewerFromRequest( array $link, string $uri, ?string $body, int $now, ?string $viewerCookie = null ): ?array {
		$raw = '';

		$query = [];
		wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
		foreach ( [ 'viewer_token', 'ab_viewer_token' ] as $param ) {
			if ( isset( $query[ $param ] ) && is_string( $query[ $param ] ) ) {
				$raw = $query[ $param ];
				break;
			}
		}
		if ( $raw === '' && is_string( $viewerCookie ) && $viewerCookie !== '' ) {
			$raw = $viewerCookie;
		}
		if ( $raw === '' && $body !== null && $body !== '' ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) && isset( $decoded['viewer_token'] ) && is_string( $decoded['viewer_token'] ) ) {
				$raw = $decoded['viewer_token'];
			}
		}

		if ( $raw === '' ) {
			return null;
		}

		$claims = ViewerToken::verify( $raw, ViewerKeys::all(), $now );
		if ( $claims === null
			|| $claims['share_link_id'] !== (int) $link['id']
			|| $claims['artifact_id'] !== (int) $link['artifact_id']
		) {
			return null;
		}

		return $claims;
	}
}
