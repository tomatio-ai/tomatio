<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * "Default agent" on a person's profile: the agent their AI clients write as
 * when no role is chosen (RoleSwitching::ROUTED_WRITES). People write
 * through an agent, never as themselves: see
 * docs/rfc/people-write-through-agents.md.
 *
 * Shown only to people with at least one agent they may switch into. Saving
 * re-checks that the person may still switch into the chosen agent.
 */
final class DefaultAgent {

	public const FIELD        = 'ab_default_agent';
	public const NONCE_ACTION = 'ab_default_agent_save';
	public const NONCE_FIELD  = 'ab_default_agent_nonce';

	public function registerHooks(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('show_user_profile', [$this, 'render']);
		add_action('edit_user_profile', [$this, 'render']);
		add_action('personal_options_update', [self::class, 'save']);
		add_action('edit_user_profile_update', [self::class, 'save']);
	}

	public function render(\WP_User $user): void {
		if (! current_user_can('edit_user', $user->ID)) {
			return;
		}

		$agents = RoleSwitching::agentsFor((int) $user->ID);
		if ($agents === []) {
			return;
		}

		$chosen = (int) get_user_meta($user->ID, RoleSwitching::DEFAULT_AGENT_META, true);

		echo '<h2>' . esc_html__('TomaTío', 'agent-brain') . '</h2>';
		echo '<table class="form-table" role="presentation"><tr><th><label for="' . esc_attr(self::FIELD) . '">'
			. esc_html__('Default agent', 'agent-brain') . '</label></th><td>';
		wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
		echo '<select name="' . esc_attr(self::FIELD) . '" id="' . esc_attr(self::FIELD) . '">';
		echo '<option value="0">' . esc_html(
			count($agents) === 1
				/* translators: %s: the only agent the person can use. */
				? sprintf(__('Not chosen (uses %s, your only agent)', 'agent-brain'), $agents[0]->display_name)
				: __('Not chosen', 'agent-brain')
		) . '</option>';
		foreach ($agents as $agent) {
			echo '<option value="' . esc_attr((string) $agent->ID) . '"' . selected($chosen, (int) $agent->ID, false) . '>'
				. esc_html($agent->display_name) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__(
			'When an AI client connected as you saves a memory or works on a document, it writes as this agent, credited "via" you. People do not write as themselves: what an AI types is the agent\'s work, and you make it official by approving it.',
			'agent-brain'
		) . '</p>';
		echo '</td></tr></table>';
	}

	public static function save(int $userId): void {
		// The field is only on the form when the person has agents; no nonce, nothing to save.
		if (! isset($_POST[self::NONCE_FIELD]) || ! wp_verify_nonce((string) wp_unslash($_POST[self::NONCE_FIELD]), self::NONCE_ACTION)) {
			return;
		}

		if (! current_user_can('edit_user', $userId)) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$agentId = isset($_POST[self::FIELD]) ? absint(wp_unslash((string) $_POST[self::FIELD])) : 0;
		// phpcs:enable

		self::apply($userId, $agentId);
	}

	/**
	 * Stores $agentId as $userId's default agent (0 clears it). An agent the
	 * person may not switch into is ignored. Returns whether it was stored.
	 */
	public static function apply(int $userId, int $agentId): bool {
		if ($agentId === 0) {
			delete_user_meta($userId, RoleSwitching::DEFAULT_AGENT_META);

			return true;
		}

		if (! RoleSwitching::canSwitch($userId, $agentId)) {
			return false;
		}

		update_user_meta($userId, RoleSwitching::DEFAULT_AGENT_META, $agentId);

		return true;
	}
}
