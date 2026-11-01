<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\Admin\EmptyState;
use AgentBrain\Modules\Admin\Notices;

/**
 * The Principals section of the Export and import page: every person and
 * agent of the brain with its principal id, and whether it is bound. After
 * a restore every principal is unbound; an administrator binds a person to
 * an email address (a set-password link is mailed) and an agent to a new
 * application password (shown once, here).
 */
final class PrincipalsPage {

	public const BIND_ACTION = 'agent_brain_principal_bind';

	private const SECRET = 'ab_pcp_bind_secret_';

	public static function register(): void {
		add_action('admin_post_' . self::BIND_ACTION, [self::class, 'handleBind']);
	}

	public static function render(): void {
		echo '<h2 id="principals">' . esc_html__('Principals', 'agent-brain') . '</h2>';
		echo '<p>' . esc_html__('The people and agents of this brain, by the ids exports use. After a migration each one is unbound: nobody can sign in or connect as them until you bind them here. Binding a person mails a link to set a password; binding an agent makes a new application password. Old passwords and tokens never travel.', 'agent-brain') . '</p>';

		self::renderSecret();

		$principals = array_values(array_filter(Binding::principals(), static fn (array $p): bool => $p['type'] === 'human' || $p['type'] === 'agent'));
		if ($principals === []) {
			EmptyState::render(__('No principals yet', 'agent-brain'), __('People and agents get an id when they first take part in the brain.', 'agent-brain'), null);

			return;
		}

		echo '<div class="ab-table-wrap"><table class="widefat striped"><thead><tr>'
			. '<th scope="col">' . esc_html__('Principal', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Name', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Roles', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Status', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Bound', 'agent-brain') . '</th>'
			. '</tr></thead><tbody>';

		foreach ($principals as $principal) {
			echo '<tr><td><code>' . esc_html($principal['id']) . '</code></td>'
				. '<td>' . esc_html($principal['display_name']) . '</td>'
				. '<td>' . esc_html(implode(', ', $principal['roles'])) . '</td>'
				. '<td>' . esc_html($principal['status']) . '</td><td>';

			if ($principal['bound'] || $principal['user_id'] === 0) {
				echo $principal['user_id'] === 0 ? esc_html__('No account', 'agent-brain') : esc_html__('Bound', 'agent-brain');
			} else {
				echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
				wp_nonce_field(self::BIND_ACTION . '_' . $principal['id']);
				echo '<input type="hidden" name="action" value="' . esc_attr(self::BIND_ACTION) . '" />';
				echo '<input type="hidden" name="principal" value="' . esc_attr($principal['id']) . '" />';
				if ($principal['type'] === 'human') {
					$field = 'ab-bind-' . md5($principal['id']);
					echo '<label class="screen-reader-text" for="' . esc_attr($field) . '">' . esc_html__('Email address', 'agent-brain') . '</label>';
					echo '<input type="email" id="' . esc_attr($field) . '" name="email" required placeholder="' . esc_attr__('Email address', 'agent-brain') . '" /> ';
					echo '<button type="submit" class="button">' . esc_html__('Bind and send link', 'agent-brain') . '</button>';
				} else {
					echo '<button type="submit" class="button">' . esc_html__('Bind and make an application password', 'agent-brain') . '</button>';
				}
				echo '</form>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	public static function handleBind(): void {
		if (! Binding::canBind(get_current_user_id())) {
			wp_die(esc_html__('You do not have permission to bind principals.', 'agent-brain'), 403);
		}

		$principal = isset($_POST['principal']) ? sanitize_text_field(wp_unslash($_POST['principal'])) : '';
		check_admin_referer(self::BIND_ACTION . '_' . $principal);
		$email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

		try {
			$result = Binding::bind($principal, get_current_user_id(), $email);
			if (isset($result['application_password'])) {
				set_transient(self::SECRET . get_current_user_id(), ['principal' => $principal, 'password' => $result['application_password']], 300);
				Notices::flash('success', __('The agent is bound. Copy its application password below now; it is not shown again.', 'agent-brain'));
			} elseif (! empty($result['mailed'])) {
				Notices::flash('success', __('The person is bound and the link to set a password was mailed.', 'agent-brain'));
			} else {
				set_transient(self::SECRET . get_current_user_id(), ['principal' => $principal, 'link' => (string) ($result['link'] ?? '')], 300);
				Notices::flash('warning', __('The person is bound, but the email could not be sent. Pass on the link below yourself; it works once.', 'agent-brain'));
			}
		} catch (ImportFailed $e) {
			Notices::flash('error', $e->getMessage());
		}

		wp_safe_redirect(add_query_arg(['page' => ExportPage::SLUG], admin_url('admin.php')) . '#principals');
		exit;
	}

	/** A new application password or link, shown once to the administrator who made it. */
	private static function renderSecret(): void {
		$key    = self::SECRET . get_current_user_id();
		$secret = get_transient($key);
		if (! is_array($secret)) {
			return;
		}
		delete_transient($key);

		$value = (string) ($secret['password'] ?? $secret['link'] ?? '');
		if ($value === '') {
			return;
		}

		echo '<div class="notice notice-info ab-notice"><p>' . esc_html(sprintf(
			/* translators: %s: principal id. */
			isset($secret['password']) ? __('Application password for %s:', 'agent-brain') : __('Set-password link for %s:', 'agent-brain'),
			(string) $secret['principal']
		)) . '</p><p><input type="text" class="large-text code" readonly value="' . esc_attr($value) . '" /></p></div>';
	}
}
