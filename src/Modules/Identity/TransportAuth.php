<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

use AgentBrain\Modules\AgentId\AgentIssuer;

/**
 * MCP transport authentication gate (PRD ID-6). Accepts only application
 * password or OAuth authentication; a cookie/nonce session (or any other
 * method, including no authentication at all) is always rejected.
 *
 * ID-5 (OAuth / CIMD) is out of scope for this wave: the OAuth path here is
 * a single filter hook that a later module implements against.
 */
class TransportAuth {

	private static bool $viaApplicationPassword = false;

	/**
	 * Wire the core hook that fires only when a request actually
	 * authenticated via an application password. This is the reliable
	 * signal per WordPress core (wp-includes/class-wp-application-passwords.php):
	 * it fires exclusively on successful application-password auth, never
	 * for cookie sessions.
	 */
	public static function registerHooks(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('application_password_did_authenticate', static function (): void {
			self::$viaApplicationPassword = true;
		}, 10, 0);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function check() {
		if (self::authenticatedViaApplicationPassword() || self::authenticatedViaOAuth()) {
			return self::statusGate();
		}

		return new \WP_Error(
			'agent_brain_transport_denied',
			'Only application-password or OAuth authentication is accepted for the MCP transport.',
			['status' => 401]
		);
	}

	/**
	 * Status gate (agent ID, RFC section 4): an agent whose status is not
	 * active, or that has no agent ID yet (#233), is refused on every MCP
	 * request.
	 *
	 * @return true|\WP_Error
	 */
	private static function statusGate() {
		$userId = function_exists('get_current_user_id') ? get_current_user_id() : 0;
		$reason = AgentIssuer::blockReason($userId);

		if ($reason === AgentIssuer::BLOCKED_NOT_ISSUED) {
			return new \WP_Error(
				'agent_brain_agent_not_issued',
				'This agent has no agent ID yet, so it cannot sign in. Ask a person to issue one on the Agents page.',
				['status' => 403]
			);
		}

		if ($reason !== null) {
			return new \WP_Error(
				'agent_brain_agent_not_active',
				'This agent ID is not active. Ask a person to reinstate it.',
				['status' => 403]
			);
		}

		return true;
	}

	private static function authenticatedViaApplicationPassword(): bool {
		if (self::$viaApplicationPassword) {
			return true;
		}

		// Secondary signal: REST core exposes the authenticated application
		// password globally once request-level auth has completed, even if
		// our own hook was registered after auth ran for this request.
		if (! empty($GLOBALS['wp_rest_application_password_uuid'] ?? null)) {
			return true;
		}

		return false;
	}

	private static function authenticatedViaOAuth(): bool {
		if (! function_exists('apply_filters')) {
			return false;
		}

		// ID-5 (OAuth/CIMD) lands in a later wave; default is false so any
		// non-application-password, non-explicitly-OAuth request (including
		// a logged-in cookie session) is rejected.
		return (bool) apply_filters('agent_brain/transport_oauth_authenticated', false);
	}

	/**
	 * Reset per-request static state. Only needed in tests, where multiple
	 * "requests" run in the same PHP process.
	 */
	public static function resetForTests(): void {
		self::$viaApplicationPassword = false;
	}
}
