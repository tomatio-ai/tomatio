<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * #415: tell a viewer why they landed on the sign-in page.
 *
 * A private share link (ShareAuth) or a members address (MembersRoute) sends
 * an anonymous viewer through wp-login, which on its own shows a bare login
 * form. Those routes add PARAM to the login URL, and the login_message filter
 * then shows a short note. A private-mode brain's gate may send the viewer to
 * wp-login first, without the flag, so a redirect back to the sign-in step
 * (ab_share_auth) counts too. The note is fixed text and never names the
 * artifact, so it tells an anonymous visitor nothing about what is behind
 * the link.
 */
final class SignInNote {

	public const PARAM = 'ab_share_signin';

	/** The login URL with the flag that shows the note. */
	public static function url( string $loginUrl ): string {
		return add_query_arg( self::PARAM, '1', $loginUrl );
	}

	/**
	 * Whether the login page was reached from a protected link.
	 *
	 * @param array<string, mixed> $query The login request's query ($_GET).
	 */
	public static function applies( array $query ): bool {
		if ( ( $query[ self::PARAM ] ?? '' ) === '1' ) {
			return true;
		}
		$redirect = $query['redirect_to'] ?? '';

		return is_string( $redirect ) && str_contains( $redirect, ShareAuth::QUERY_PARAM . '=' );
	}

	/**
	 * `login_message`: the note above the form when it applies.
	 *
	 * @param mixed $message
	 */
	public static function message( $message ): string {
		$message = is_string( $message ) ? $message : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides whether to show fixed text.
		if ( ! self::applies( $_GET ) ) {
			return $message;
		}

		return '<p class="message ab-share-signin">'
			. esc_html__( 'This is a protected link. Sign in with your account on this site to view it; only its members can open it.', 'agent-brain' )
			. '</p>' . $message;
	}
}
