<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Artifacts\Preview;

/**
 * #219: the MAIN-site half of the private-link sign-in round trip.
 *
 * A viewer holding a private link but no viewer token is redirected here
 * from the share address (ShareRoute) with `?ab_share_auth={link id}` and an
 * `ab_return` handle (#391): the share path, token included, stays on the
 * share address (ShareReturn), so it never reaches this host's address bar,
 * access log or wp-login redirect_to. This endpoint runs on a site host only
 * — never the preview host, which has no login (PreviewGate) — and:
 *
 * 1. anonymous  → 302 to wp-login (auth round trip, redirect back here)
 * 2. signed in but not allowed (or the link is dead/off) → the uniform 404,
 *    indistinguishable from a bad link
 * 3. allowed    → mints a single-use 60-second ShareTicket bound to
 *    (link, artifact, user), uses up the return handle and 302s back to the
 *    share address with `?ab_ticket=...`, where ShareRoute exchanges it for
 *    the viewer session
 *
 * "Allowed" is the same audience a private link admits (ART-24): a brain
 * member who may read the artifact, on an artifact with viewer identity on.
 */
final class ShareAuth {

	public const QUERY_PARAM = 'ab_share_auth';

	public const RETURN_PARAM = ShareReturn::QUERY_PARAM;

	/**
	 * template_redirect entry (Sharing module, priority 1). No
	 * private-mode allowlist exception is registered for this endpoint: an
	 * anonymous visitor a private-mode gate sends to wp-login comes back to
	 * the same URL (query string included), where this handler then runs
	 * signed in — the round trip needs no bypass.
	 */
	public static function serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the ticket param names a link, it changes no state.
		if ( ! isset( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		$query = [];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- values are validated in handle().
		wp_parse_str( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY ), $query );
		$query = array_map(
			static fn ( $value ): string => is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '',
			$query
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- consumed only as a host comparison.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';

		$response = self::handle( $query, get_current_user_id(), time(), $host );
		if ( $response === null ) {
			return;
		}

		status_header( $response['status'] );
		if ( ! headers_sent() ) {
			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value, true );
			}
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- fully built and escaped below.
		exit;
	}

	/**
	 * Pure-ish request handler (no output, no exit) so it can be tested.
	 * Returns null when the request is not an auth request on a site host.
	 *
	 * @param array<string, string> $query  Sanitised query parameters.
	 * @param int                   $actorId 0 = anonymous.
	 * @param string                $host    Request host ('' skips the host check, tests).
	 * @return array{status:int,headers:array<string,string>,body:string}|null
	 */
	public static function handle( array $query, int $actorId, int $now, string $host = '' ): ?array {
		$rawLinkId = $query[ self::QUERY_PARAM ] ?? '';
		if ( $rawLinkId === '' || ! ctype_digit( $rawLinkId ) ) {
			return null;
		}

		// The auth endpoint lives on the main site only: on the preview host
		// there is nothing to sign in against.
		if ( $host !== '' && ! in_array( strtolower( trim( $host ) ), Preview::siteHosts(), true ) ) {
			return self::notFound();
		}

		$linkId = (int) $rawLinkId;
		$link   = $linkId > 0 ? ShareLinks::find( $linkId ) : null;
		if ( $link === null
			|| ShareLinks::normalizePrivacy( (string) ( $link['privacy'] ?? '' ) ) !== ShareLinks::PRIVACY_PRIVATE
			|| Config::disabled()
			|| ! ShareLinks::isServable( get_post( (int) $link['artifact_id'] ) )
			|| $link['revoked_at'] !== null
			|| $link['approved_at'] === null
			|| Policy::isExpired( (string) $link['expires_at'], $now )
			|| ! ViewerMode::isActive( (int) $link['artifact_id'] )
		) {
			return self::notFound();
		}

		$artifactId = (int) $link['artifact_id'];
		$handle     = $query[ self::RETURN_PARAM ] ?? '';
		$back       = self::validateBack( ShareReturn::peek( $handle, $linkId ) ?? '', $artifactId );
		if ( $back === null ) {
			return self::notFound();
		}

		// Anonymous: round trip through wp-login and back here. wp_login_url
		// carries the redirect, so no auth_redirect() exit is needed — the
		// response is a plain 302 a test can assert on. Only the link id and
		// the handle ride along.
		if ( $actorId <= 0 ) {
			$authUrl = home_url( '/' );
			$authUrl = add_query_arg( [ self::QUERY_PARAM => (string) $linkId, self::RETURN_PARAM => $handle ], $authUrl );

			return self::redirect( SignInNote::url( wp_login_url( $authUrl ) ) );
		}

		if ( ! user_can( $actorId, 'read_post', $artifactId ) ) {
			ShareLinks::audit( $actorId, 'share_link_signin_denied', 'denied', $linkId, [ 'artifact_id' => $artifactId ] );

			return self::notFound();
		}

		$ticket = ShareTicket::mint( $actorId, $linkId, $artifactId, $now );
		if ( ! is_array( $ticket ) ) {
			return self::notFound();
		}

		ShareLinks::audit( $actorId, 'share_link_signin_ticket_minted', 'ok', $linkId, [ 'artifact_id' => $artifactId ] );
		ShareReturn::consume( $handle );

		$target = ShareLinks::baseUrl() . $back;
		$target = add_query_arg( ShareTicket::QUERY_PARAM, $ticket['ticket'], $target );

		return self::redirect( $target );
	}

	/**
	 * The stored path must be a share path of THIS link's artifact —
	 * otherwise the redirect off the main site would be an open redirect.
	 * Only the path is taken; the host is rebuilt from ShareLinks::baseUrl().
	 */
	private static function validateBack( string $back, int $artifactId ): ?string {
		if ( preg_match( '#^/share/(\d+)/[A-Za-z0-9_-]+/?$#', $back, $m ) !== 1 || (int) $m[1] !== $artifactId ) {
			return null;
		}

		return $back;
	}

	/**
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function redirect( string $location ): array {
		return [
			'status'  => 302,
			'headers' => [ 'Location' => $location ] + ShareRoute::securityHeaders(),
			'body'    => '',
		];
	}

	/**
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function notFound(): array {
		return ShareRoute::notFound();
	}
}
