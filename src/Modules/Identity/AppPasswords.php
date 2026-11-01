<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

/**
 * Per-deployment application passwords as the agent kill switch (PRD ID-2).
 * Thin wrapper over core WP_Application_Passwords plus an admin panel on
 * the agent's profile screen and audit logging on create/delete.
 */
class AppPasswords {

	public const REVOKE_ACTION = 'ab_revoke_app_password';
	public const NONCE_ACTION  = 'ab_revoke_app_password_nonce';

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function listForUser(int $userId): array {
		if (! class_exists('WP_Application_Passwords')) {
			return [];
		}

		return array_values(\WP_Application_Passwords::get_user_application_passwords($userId));
	}

	/**
	 * Revoke (delete) one application password. This is the kill switch:
	 * once deleted, that deployment's next authenticated request fails.
	 */
	public function revoke(int $userId, string $uuid): bool {
		if (! class_exists('WP_Application_Passwords')) {
			return false;
		}

		$result = \WP_Application_Passwords::delete_application_password($userId, $uuid);

		return $result === true;
	}

	public function registerHooks(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('wp_create_application_password', [$this, 'onCreated'], 10, 3);
		add_action('wp_delete_application_password', [$this, 'onDeleted'], 10, 2);
		add_action('show_user_profile', [$this, 'renderProfilePanel']);
		add_action('edit_user_profile', [$this, 'renderProfilePanel']);
		add_action('admin_post_' . self::REVOKE_ACTION, [$this, 'handleRevokeRequest']);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function onCreated(int $userId, array $item, string $appId = ''): void {
		$this->audit('app_password_create', $userId, 'ok', $item['uuid'] ?? '');
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public function onDeleted(int $userId, array $item): void {
		$this->audit('app_password_delete', $userId, 'ok', $item['uuid'] ?? '');
	}

	/**
	 * Renders a curator-facing panel on the ab_agent user's profile screen
	 * listing every application password (label, created, last used) with a
	 * nonce + capability-checked revoke action. Only rendered for ab_agent
	 * users, and only for a viewer who may edit that user.
	 */
	public function renderProfilePanel(\WP_User $user): void {
		if (! in_array(Capabilities::ROLE_AGENT, (array) $user->roles, true)) {
			return;
		}

		if (! function_exists('current_user_can') || ! current_user_can('edit_user', $user->ID)) {
			return;
		}

		$passwords = $this->listForUser($user->ID);

		echo '<h2>' . esc_html__('Agent Deployments (Application Passwords)', 'agent-brain') . '</h2>';
		echo '<div class="ab-table-wrap"><table class="widefat"><thead><tr>'
			. '<th scope="col">' . esc_html__('Label', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Created', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Last Used', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Action', 'agent-brain') . '</th>'
			. '</tr></thead><tbody>';

		foreach ($passwords as $item) {
			$uuid    = (string) ($item['uuid'] ?? '');
			$label   = (string) ($item['name'] ?? '');
			$created = isset($item['created']) ? gmdate('Y-m-d H:i:s', (int) $item['created']) : '—';
			$lastUsed = ! empty($item['last_used']) ? gmdate('Y-m-d H:i:s', (int) $item['last_used']) : '—';

			echo '<tr>';
			echo '<td>' . esc_html($label) . '</td>';
			echo '<td>' . esc_html($created) . '</td>';
			echo '<td>' . esc_html($lastUsed) . '</td>';
			echo '<td>';

			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . \AgentBrain\Modules\Admin\Confirm::attr(\AgentBrain\Modules\Admin\Confirm::message(__('Revoke this application password: anything using it stops working', 'agent-brain'), __('Create a new application password to reconnect', 'agent-brain'))) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Confirm::attr() escapes.
			echo '<input type="hidden" name="action" value="' . esc_attr(self::REVOKE_ACTION) . '" />';
			echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $user->ID) . '" />';
			echo '<input type="hidden" name="uuid" value="' . esc_attr($uuid) . '" />';
			wp_nonce_field(self::NONCE_ACTION . '_' . $uuid, '_ab_revoke_nonce');
			echo '<button type="submit" class="button">' . esc_html__('Revoke', 'agent-brain') . '</button>';
			echo '</form>';

			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * admin-post.php handler for the revoke button above: nonce-checked,
	 * capability-checked (edit_user on the target), fails closed.
	 */
	public function handleRevokeRequest(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce itself is read here and verified on the very next line before any use.
		$userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below before use.
		$uuid   = isset($_POST['uuid']) ? sanitize_text_field((string) $_POST['uuid']) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this is the nonce read + verification itself.
		$nonce  = isset($_POST['_ab_revoke_nonce']) ? (string) $_POST['_ab_revoke_nonce'] : '';

		if ($userId <= 0 || $uuid === '' || ! wp_verify_nonce($nonce, self::NONCE_ACTION . '_' . $uuid)) {
			wp_die(esc_html__('Invalid request.', 'agent-brain'), 400);
		}

		if (! current_user_can('edit_user', $userId)) {
			wp_die(esc_html__('You are not allowed to do this.', 'agent-brain'), 403);
		}

		$this->revoke($userId, $uuid);

		wp_safe_redirect(wp_get_referer() ?: admin_url());
		exit;
	}

	private function audit(string $event, int $userId, string $status, string $uuid): void {
		if (! function_exists('do_action')) {
			return;
		}

		do_action('agent_brain/audit', [
			'actor_id'    => function_exists('get_current_user_id') ? get_current_user_id() : $userId,
			'event'       => $event,
			'status'      => $status,
			'object_type' => 'application_password',
			'object_id'   => $uuid,
			'meta'        => ['agent_user_id' => $userId],
		]);
	}
}
