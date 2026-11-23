<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;

/**
 * "Default agent" on a person's profile: the agent their AI clients write as
 * when no role is chosen (RoleSwitching::ROUTED_WRITES). People write
 * through an agent, never as themselves: see
 * docs/rfc/people-write-through-agents.md.
 *
 * Shown to people with an agent they may connect as. Agents they cannot
 * switch into yet are listed with the scopes they lack, and someone who may
 * grant scopes can add those scopes here (#1022). Saving re-checks that the
 * person may still switch into the chosen agent.
 */
final class DefaultAgent {

	public const FIELD        = 'ab_default_agent';
	public const NONCE_ACTION = 'ab_default_agent_save';
	public const NONCE_FIELD  = 'ab_default_agent_nonce';
	public const TAKE_FIELD   = 'ab_take_agent_scopes';

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

		$userId = (int) $user->ID;
		if (Delegation::agentsFor($userId) === []) {
			return;
		}

		$agents  = RoleSwitching::agentsFor($userId);
		$blocked = self::blockedAgents($userId);
		$chosen  = (int) get_user_meta($userId, RoleSwitching::DEFAULT_AGENT_META, true);
		$own     = $userId === get_current_user_id();

		echo '<h2>' . esc_html__('TomaTío', 'tomatio') . '</h2>';
		echo '<table class="form-table" role="presentation"><tr><th><label for="' . esc_attr(self::FIELD) . '">'
			. esc_html__('Default agent', 'tomatio') . '</label></th><td>';
		wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

		if ($agents !== []) {
			echo '<select name="' . esc_attr(self::FIELD) . '" id="' . esc_attr(self::FIELD) . '">';
			echo '<option value="0">' . esc_html(
				count($agents) === 1
					/* translators: %s: the only agent the person can use. */
					? sprintf(__('Not chosen (uses %s, your only agent)', 'tomatio'), $agents[0]->display_name)
					: __('Not chosen', 'tomatio')
			) . '</option>';
			foreach ($agents as $agent) {
				echo '<option value="' . esc_attr((string) $agent->ID) . '"' . selected($chosen, (int) $agent->ID, false) . '>'
					. esc_html($agent->display_name) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__(
				'When an AI client connected as you saves a memory or works on a document, it writes as this agent, credited "via" you. People do not write as themselves: what an AI types is the agent\'s work, and you make it official by approving it.',
				'tomatio'
			) . '</p>';
		} else {
			echo '<p>' . esc_html(
				$own
					? __('No agent to pick yet. Your AI can switch only into an agent whose scopes you hold all of yourself, and none qualifies, so it reads with your own scopes and cannot save.', 'tomatio')
					: __('No agent to pick yet. This person\'s AI can switch only into an agent whose scopes they hold all of themselves, and none qualifies, so it reads with their own scopes and cannot save.', 'tomatio')
			) . '</p>';
		}
		echo '</td></tr>';

		if ($blocked !== []) {
			$mayGrant = current_user_can(Capabilities::CAP_GRANT_SCOPES);
			echo '<tr><th>' . esc_html__('Not switchable yet', 'tomatio') . '</th><td><fieldset>';
			echo '<p class="description">' . esc_html(
				$mayGrant
					? __('These agents use scopes missing here. Tick one to add its scopes (read and write as the agent has them); it can then be picked above and switched into.', 'tomatio')
					: __('These agents use scopes missing here. Ask a curator to give you those scopes.', 'tomatio')
			) . '</p>';
			foreach ($blocked as $row) {
				$names = implode(', ', $row['names']);
				if ($mayGrant) {
					echo '<label style="display:block;margin:6px 0"><input type="checkbox" name="' . esc_attr(self::TAKE_FIELD) . '[]" value="' . esc_attr((string) $row['agent']->ID) . '"> '
						. esc_html(sprintf(
							/* translators: 1: agent name, 2: comma-separated scope names. */
							$own ? __('Let me switch into %1$s (adds: %2$s)', 'tomatio') : __('Allow switching into %1$s (adds: %2$s)', 'tomatio'),
							$row['agent']->display_name,
							$names
						)) . '</label>';
				} else {
					echo '<p>' . esc_html(sprintf(
						/* translators: 1: agent name, 2: comma-separated scope names. */
						__('%1$s (needs: %2$s)', 'tomatio'),
						$row['agent']->display_name,
						$names
					)) . '</p>';
				}
			}
			echo '</fieldset></td></tr>';
		}

		echo '</table>';
	}

	public static function save(int $userId): void {
		// The section is only on the form when the person has agents; no nonce, nothing to save.
		if (! isset($_POST[self::NONCE_FIELD]) || ! wp_verify_nonce((string) wp_unslash($_POST[self::NONCE_FIELD]), self::NONCE_ACTION)) {
			return;
		}

		if (! current_user_can('edit_user', $userId)) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$take    = isset($_POST[self::TAKE_FIELD]) ? array_map('absint', (array) wp_unslash($_POST[self::TAKE_FIELD])) : [];
		$hasPick = isset($_POST[self::FIELD]);
		$agentId = $hasPick ? absint(wp_unslash((string) $_POST[self::FIELD])) : 0;
		// phpcs:enable

		foreach ($take as $agent) {
			self::takeScopes(get_current_user_id(), $userId, $agent);
		}

		if ($hasPick) {
			self::apply($userId, $agentId);
		}
	}

	/**
	 * Agents $userId could connect as but not switch into, each with the
	 * read and write scopes it holds that $userId lacks (#1022).
	 *
	 * @return list<array{agent: \WP_User, read: list<int>, write: list<int>, names: list<string>}>
	 */
	public static function blockedAgents(int $userId): array {
		$scopes = new Scopes();
		$read   = $scopes->readScopeIds($userId);
		$write  = $scopes->writeScopeIds($userId);
		$rows   = [];

		foreach (Delegation::agentsFor($userId) as $agent) {
			$needRead  = array_values(array_diff($scopes->readScopeIds((int) $agent->ID), $read));
			$needWrite = array_values(array_diff($scopes->writeScopeIds((int) $agent->ID), $write));
			if ($needRead === [] && $needWrite === []) {
				continue;
			}

			$names = [];
			foreach (array_unique(array_merge($needRead, $needWrite)) as $id) {
				$term    = get_term($id, Scopes::TAXONOMY);
				$names[] = $term instanceof \WP_Term ? $term->name : '#' . $id;
			}

			$rows[] = ['agent' => $agent, 'read' => $needRead, 'write' => $needWrite, 'names' => $names];
		}

		return $rows;
	}

	/**
	 * $actorId gives $userId the scopes $agentId holds that $userId lacks, so
	 * $userId can switch into it (#1022). Only for someone who may grant
	 * scopes, and only for an agent $userId may connect as; each grant is
	 * audited as scope_grant by $actorId. Returns whether $userId can switch
	 * into the agent afterwards.
	 */
	public static function takeScopes(int $actorId, int $userId, int $agentId): bool {
		if ($actorId <= 0 || ! user_can($actorId, Capabilities::CAP_GRANT_SCOPES)) {
			return false;
		}

		foreach (self::blockedAgents($userId) as $row) {
			if ((int) $row['agent']->ID !== $agentId) {
				continue;
			}

			$scopes = new Scopes();
			foreach (['read', 'write'] as $list) {
				foreach ($row[$list] as $scopeId) {
					$scopes->grant($actorId, $userId, $list, $scopeId);
				}
			}
		}

		return RoleSwitching::canSwitch($userId, $agentId);
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
