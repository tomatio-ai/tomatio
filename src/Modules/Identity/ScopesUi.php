<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

/**
 * Curator-facing UI on the ab_agent user profile screen for editing read
 * and write scope lists separately (PRD ID-4). Nonce + capability checked,
 * output escaped; the actual mutation always goes through Scopes::grant()/
 * revoke() so the cap check + audit event stay in one place.
 */
class ScopesUi {

	public const FIELD_NONCE = 'ab_scopes_nonce';
	public const NONCE_ACTION = 'ab_scopes_edit';

	private Scopes $scopes;

	public function __construct(?Scopes $scopes = null) {
		$this->scopes = $scopes ?? new Scopes();
	}

	public function registerHooks(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('show_user_profile', [$this, 'renderPanel']);
		add_action('edit_user_profile', [$this, 'renderPanel']);
		add_action('personal_options_update', [$this, 'saveFromRequest']);
		add_action('edit_user_profile_update', [$this, 'saveFromRequest']);
	}

	public function renderPanel(\WP_User $user): void {
		if (! in_array(Capabilities::ROLE_AGENT, (array) $user->roles, true)) {
			return;
		}

		if (! function_exists('current_user_can') || ! current_user_can(Capabilities::CAP_GRANT_SCOPES)) {
			return;
		}

		$allTerms = function_exists('get_terms')
			? get_terms(['taxonomy' => Scopes::TAXONOMY, 'hide_empty' => false])
			: [];
		$allTerms = is_array($allTerms) ? $allTerms : [];

		$readIds  = $this->scopes->readScopeIds($user->ID);
		$writeIds = $this->scopes->writeScopeIds($user->ID);

		echo '<h2>' . esc_html__('Agent Scopes', 'agent-brain') . '</h2>';
		wp_nonce_field(self::NONCE_ACTION, self::FIELD_NONCE);

		echo '<table class="form-table"><tr><th scope="row">' . esc_html__('Read scopes', 'agent-brain') . '</th><td>';
		$this->renderCheckboxes($allTerms, $readIds, 'ab_read_scope_ids[]');
		echo '</td></tr>';

		echo '<tr><th scope="col">' . esc_html__('Write scopes', 'agent-brain') . '</th><td>';
		$this->renderCheckboxes($allTerms, $writeIds, 'ab_write_scope_ids[]');
		echo '</td></tr></table>';
	}

	/**
	 * @param array<int, \WP_Term> $terms
	 * @param array<int, int>      $selectedIds
	 */
	private function renderCheckboxes(array $terms, array $selectedIds, string $name): void {
		foreach ($terms as $term) {
			if (! ($term instanceof \WP_Term)) {
				continue;
			}

			$checked = in_array($term->term_id, $selectedIds, true);

			echo '<label style="display:block;">';
			echo '<input type="checkbox" name="' . esc_attr($name) . '" value="' . esc_attr((string) $term->term_id) . '" '
				. checked($checked, true, false) . ' /> ';
			echo esc_html($term->name);
			echo '</label>';
		}
	}

	public function saveFromRequest(int $targetUserId): void {
		if (! isset($_POST[self::FIELD_NONCE]) || ! wp_verify_nonce((string) $_POST[self::FIELD_NONCE], self::NONCE_ACTION)) {
			return;
		}

		if (! function_exists('current_user_can') || ! current_user_can(Capabilities::CAP_GRANT_SCOPES)) {
			return;
		}

		$actingUserId = function_exists('get_current_user_id') ? get_current_user_id() : 0;

		$this->syncList($actingUserId, $targetUserId, 'read', $this->postedIds('ab_read_scope_ids'));
		$this->syncList($actingUserId, $targetUserId, 'write', $this->postedIds('ab_write_scope_ids'));
	}

	/**
	 * @return array<int, int>
	 */
	private function postedIds(string $field): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- caller (saveFromRequest) verifies the nonce before calling this.
		$raw = $_POST[$field] ?? [];
		if (! is_array($raw)) {
			return [];
		}

		return array_map('intval', $raw);
	}

	/**
	 * @param array<int, int> $desiredIds
	 */
	private function syncList(int $actingUserId, int $targetUserId, string $list, array $desiredIds): void {
		$current = $list === 'read'
			? $this->scopes->readScopeIds($targetUserId)
			: $this->scopes->writeScopeIds($targetUserId);

		foreach (array_diff($desiredIds, $current) as $toGrant) {
			$this->scopes->grant($actingUserId, $targetUserId, $list, (int) $toGrant);
		}

		foreach (array_diff($current, $desiredIds) as $toRevoke) {
			$this->scopes->revoke($actingUserId, $targetUserId, $list, (int) $toRevoke);
		}
	}
}
