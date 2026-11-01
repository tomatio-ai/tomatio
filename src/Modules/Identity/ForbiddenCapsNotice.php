<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

/**
 * Issue #231: tells people with ab_manage when a capability an agent may
 * never hold is stored anyway, on the ab_agent role or on an agent's own
 * account (wp user add-cap, a role editor, another plugin). Capabilities
 * refuses those at runtime whatever grants them; the notice is how the
 * stored state gets cleaned, with one button.
 */
final class ForbiddenCapsNotice {

	public const ACTION = 'ab_purge_agent_caps';

	/** Agents checked per screen load. */
	private const MAX_AGENTS = 500;

	public static function registerHooks(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_notices', [self::class, 'render']);
		add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
	}

	/**
	 * Forbidden capabilities that are stored: on the ab_agent role, per
	 * agent (granted to the account itself), and per agent through another
	 * role it also holds (only a person can fix that one).
	 *
	 * @return array{role: array<int, string>, users: array<int, array<int, string>>, other_roles: array<int, array<int, string>>}
	 */
	public static function findings(): array {
		$forbidden = Capabilities::agentForbiddenCapabilities();
		$role      = get_role(Capabilities::ROLE_AGENT);
		$found     = [
			'role'        => $role !== null ? self::held($forbidden, $role->capabilities) : [],
			'users'       => [],
			'other_roles' => [],
		];

		foreach (get_users(['role' => Capabilities::ROLE_AGENT, 'number' => self::MAX_AGENTS]) as $user) {
			$own = self::held($forbidden, (array) $user->caps);
			if ($own !== []) {
				$found['users'][(int) $user->ID] = $own;
			}

			$viaRoles = [];
			foreach ((array) $user->roles as $name) {
				$other = $name !== Capabilities::ROLE_AGENT ? get_role($name) : null;
				if ($other !== null && self::held($forbidden, $other->capabilities) !== []) {
					$viaRoles[] = (string) $name;
				}
			}
			if ($viaRoles !== []) {
				$found['other_roles'][(int) $user->ID] = $viaRoles;
			}
		}

		return $found;
	}

	/**
	 * Removes every stored forbidden capability from the ab_agent role and
	 * from agent accounts. Roles other than ab_agent are left to a person.
	 *
	 * @return int Number of capabilities removed.
	 */
	public static function purge(): int {
		$found   = self::findings();
		$removed = 0;

		$role = get_role(Capabilities::ROLE_AGENT);
		foreach ($found['role'] as $cap) {
			if ($role !== null) {
				$role->remove_cap($cap);
				++$removed;
			}
		}

		foreach ($found['users'] as $userId => $caps) {
			$user = new \WP_User($userId);
			foreach ($caps as $cap) {
				$user->remove_cap($cap);
				++$removed;
			}
		}

		return $removed;
	}

	public static function render(): void {
		if (! current_user_can(Capabilities::CAP_MANAGE) || ! self::onRelevantScreen()) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by handle() after its nonce check.
		if (isset($_GET['ab_caps_purged'])) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(sprintf(
					/* translators: %d: number of capabilities removed. */
					__('TomaTío removed %d capabilities that agents may not hold.', 'agent-brain'),
					absint(wp_unslash($_GET['ab_caps_purged'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
				))
			);
		}

		$found = self::findings();
		if ($found['role'] === [] && $found['users'] === [] && $found['other_roles'] === []) {
			return;
		}

		$lines = [];
		if ($found['role'] !== []) {
			/* translators: %s: comma-separated capability names. */
			$lines[] = sprintf(__('The Agent role holds %s.', 'agent-brain'), implode(', ', $found['role']));
		}
		foreach ($found['users'] as $userId => $caps) {
			/* translators: 1: agent name, 2: comma-separated capability names. */
			$lines[] = sprintf(__('%1$s holds %2$s.', 'agent-brain'), self::name($userId), implode(', ', $caps));
		}
		foreach ($found['other_roles'] as $userId => $roles) {
			/* translators: 1: agent name, 2: comma-separated role names. */
			$lines[] = sprintf(__('%1$s also has the role %2$s, which gives capabilities agents may not hold. Remove that role from the agent.', 'agent-brain'), self::name($userId), implode(', ', $roles));
		}

		echo '<div class="notice notice-warning" role="alert"><p><strong>'
			. esc_html__('TomaTío: capabilities an agent may never hold are stored.', 'agent-brain')
			. '</strong> '
			. esc_html__('They are refused whenever an agent uses them, but they should not be there.', 'agent-brain')
			. '</p><ul>';
		foreach ($lines as $line) {
			echo '<li>' . esc_html($line) . '</li>';
		}
		echo '</ul>';

		if ($found['role'] !== [] || $found['users'] !== []) {
			$confirm = \AgentBrain\Modules\Admin\Confirm::message(
				__('Remove these capabilities from the Agent role and from the agent accounts listed', 'agent-brain'),
				__('Agents are refused them anyway, so nothing an agent may do changes; an administrator can grant them again', 'agent-brain')
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Confirm::attr() escapes.
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . \AgentBrain\Modules\Admin\Confirm::attr($confirm) . '><p>';
			echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
			wp_nonce_field(self::ACTION);
			submit_button(__('Remove them', 'agent-brain'), 'secondary', 'submit', false);
			echo '</p></form>';
		}
		echo '</div>';
	}

	public static function handle(): void {
		if (! current_user_can(Capabilities::CAP_MANAGE)) {
			wp_die(esc_html__('You are not allowed to do this.', 'agent-brain'), '', ['response' => 403]);
		}
		check_admin_referer(self::ACTION);

		$removed = self::purge();

		do_action('agent_brain/audit', [
			'actor_id'    => get_current_user_id(),
			'event'       => 'agent.forbidden_caps_removed',
			'status'      => 'ok',
			'object_type' => 'role',
			'object_id'   => Capabilities::ROLE_AGENT,
			'meta'        => ['removed' => $removed],
		]);

		$back = wp_get_referer();
		wp_safe_redirect(add_query_arg('ab_caps_purged', $removed, $back !== false ? $back : admin_url('users.php')));
		exit;
	}

	/**
	 * @param array<int, string>  $forbidden
	 * @param array<string, mixed> $caps
	 * @return array<int, string>
	 */
	private static function held(array $forbidden, array $caps): array {
		return array_values(array_filter($forbidden, static fn (string $cap): bool => ! empty($caps[$cap])));
	}

	private static function name(int $userId): string {
		$user = get_userdata($userId);

		return $user instanceof \WP_User ? (string) $user->display_name : '#' . $userId;
	}

	/** Users, the dashboard and TomaTío's own screens: where people manage accounts. */
	private static function onRelevantScreen(): bool {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen === null) {
			return false;
		}

		return in_array($screen->id, ['dashboard', 'users', 'user-edit', 'profile'], true) || str_contains((string) $screen->id, 'agent-brain');
	}
}
