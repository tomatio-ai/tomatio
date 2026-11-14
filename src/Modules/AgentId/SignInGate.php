<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentId;

/**
 * An agent that may not sign in (suspended, revoked, or without an agent
 * ID: AgentIssuer::blockReason()) is refused on every route, not only the
 * MCP transport (issue #234, RFC section 4). Before this, a suspended
 * agent's application password still worked on the rest of the REST API,
 * for example /wp/v2/users/me.
 *
 * - Application passwords: wp_authenticate_application_password_errors,
 *   which WordPress runs for every REST and XML-RPC request that brings one.
 * - Passwords (wp-login.php, XML-RPC): wp_authenticate_user.
 * - Sessions already open: destroyed when the agent is suspended or
 *   revoked (AgentIssuer::setStatus()).
 *
 * OAuth bearer tokens are refused by OAuth\Bearer, which already checks
 * the same reason.
 */
final class SignInGate {

	public static function registerHooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'wp_authenticate_application_password_errors', [ self::class, 'refuseApplicationPassword' ], 10, 2 );
		add_filter( 'wp_authenticate_user', [ self::class, 'refusePassword' ], 10, 1 );
	}

	/**
	 * @param mixed $user
	 */
	public static function refuseApplicationPassword( \WP_Error $errors, $user ): void {
		if ( $user instanceof \WP_User && AgentIssuer::isBlocked( (int) $user->ID ) ) {
			$errors->add( 'agent_brain_agent_not_active', self::message( (int) $user->ID ) );
		}
	}

	/**
	 * @param mixed $user
	 * @return mixed
	 */
	public static function refusePassword( $user ) {
		if ( $user instanceof \WP_User && AgentIssuer::isBlocked( (int) $user->ID ) ) {
			return new \WP_Error( 'agent_brain_agent_not_active', self::message( (int) $user->ID ) );
		}

		return $user;
	}

	private static function message( int $userId ): string {
		return AgentIssuer::blockReason( $userId ) === AgentIssuer::BLOCKED_NOT_ISSUED
			? 'This agent has no agent ID yet, so it cannot sign in. Ask a person to issue one on the Agents page.'
			: 'This agent ID is not active. Ask a person to reinstate it.';
	}
}
