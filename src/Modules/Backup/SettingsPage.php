<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

use AgentBrain\Modules\Admin\Notices;
use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Cloud\Connector;

/**
 * Curator-facing backups screen: enable/disable the daily cron, trigger a
 * manual "Back up now", and download the encryption key. Follows the same
 * ab_manage + nonce gating as Cloud\Settings.
 *
 * Admin IA wave (Unit S): this used to be its own admin_options page. It
 * is now the Backups tab of SettingsHub (agent-brain-settings); the old
 * slug (ab-backups) stays registered as a hidden page under the
 * core-hidden `options.php` parent purely so old bookmarks/links redirect
 * to the hub instead of 404ing.
 */
final class SettingsPage {

	public const CAPABILITY = 'ab_manage';
	public const PAGE_SLUG  = 'ab-backups';

	public const ACTION_TOGGLE_ENABLED = 'ab_backup_toggle_enabled';

	public static function registerPage(): void {
		$hook = add_submenu_page(
			'options.php',
			__('TomaTío Backups', 'agent-brain'),
			__('TomaTío Backups', 'agent-brain'),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[self::class, 'renderPage']
		);

		if (is_string($hook)) {
			add_action('load-' . $hook, [self::class, 'redirectToHub']);
		}
	}

	/**
	 * Fired on `load-{hook}` for the old ab-backups slug, before any
	 * output — forwards straight to the Backups tab of the settings hub,
	 * preserving whatever query args the old link carried (minus `page`).
	 */
	public static function redirectToHub(): void {
		wp_safe_redirect(self::hubRedirectUrl());
		exit;
	}

	public static function hubRedirectUrl(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect, no state change.
		$args = wp_unslash($_GET);
		unset($args['page']);

		/** @var array<string, string> $args */
		return SettingsHub::url(SettingsHub::TAB_BACKUPS, $args);
	}

	public static function registerAdminNotice(): void {
		add_action('admin_notices', [self::class, 'renderDegradedNotice']);
	}

	public static function renderDegradedNotice(): void {
		if (! current_user_can(self::CAPABILITY)) {
			return;
		}

		$warning = get_option(MarkdownStage::OPTION_LAST_WARNING);
		if (! is_string($warning) || $warning === '') {
			return;
		}

		printf(
			'<div class="notice notice-warning" role="status"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: warning message. */
					__('TomaTío Backups: %s', 'agent-brain'),
					$warning
				)
			)
		);
	}

	public static function renderPage(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage backups.', 'agent-brain'), 403);
		}

		echo '<div class="wrap"><h1>' . esc_html__('TomaTío Backups', 'agent-brain') . '</h1>';
		self::renderBody();
		echo '</div>';
	}

	/**
	 * The tab body, without the outer `<div class="wrap"><h1>` — called by
	 * SettingsHub for the Backups tab (capability already checked there).
	 * #751: one row per setting or action, one Save bar for the switch.
	 */
	public static function renderBody(): void {
		self::renderResultNotice();

		$connected = Connector::isConnected();
		$enabled   = (bool) get_option(Cron::OPTION_ENABLED, false);
		$hasKey    = KeyManager::exists();
		$post      = esc_url(admin_url('admin-post.php'));

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		if (! $connected) {
			echo Ui::callout(
				'warn',
				__('Connect the cloud first.', 'agent-brain'),
				esc_html__('Backups go to the cloud (Settings > Summarising), so the daily backup, Back up now and the backup key wait until this site is connected.', 'agent-brain'),
				'<a class="button" href="' . esc_url(SettingsHub::url(SettingsHub::TAB_CLOUD)) . '">' . esc_html__('Go to Summarising', 'agent-brain') . '</a>'
			);
		}

		if (! $enabled) {
			$now = esc_html__('Off: no automatic backups.', 'agent-brain');
		} elseif ($connected) {
			$now = esc_html__('On: an encrypted copy goes to the cloud once a day.', 'agent-brain');
		} else {
			$now = esc_html__('On, but waiting until the cloud is connected.', 'agent-brain');
		}

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__('Daily backup', 'agent-brain') . '</h3>';
		echo '<form method="post" action="' . $post . '">';
		wp_nonce_field(self::ACTION_TOGGLE_ENABLED);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_TOGGLE_ENABLED) . '" />';
		echo Ui::setting(__('Back up every day', 'agent-brain'), $now, Ui::toggle('ab_backup_enabled', 'ab_backup_enabled', $enabled), 'ab_backup_enabled');
		echo Ui::saveBar('ab_backup_save');
		echo '</form></section>';

		$button = static function (string $action, string $name, string $label, bool $disabled): string {
			return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
				. wp_nonce_field($action, '_wpnonce', true, false)
				. '<input type="hidden" name="action" value="' . esc_attr($action) . '" />'
				. '<button type="submit" name="' . esc_attr($name) . '" value="1" class="button"' . ($disabled ? ' disabled="disabled"' : '') . '>' . esc_html($label) . '</button></form>';
		};

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__('Now and the key', 'agent-brain') . '</h3>';
		echo Ui::setting(
			__('Back up now', 'agent-brain'),
			esc_html__('Sends an encrypted copy to the cloud right away. A large site keeps going in the background.', 'agent-brain'),
			$button(AdminActions::ACTION_BACKUP_NOW, 'ab_backup_now', __('Back up now', 'agent-brain'), ! $connected)
		);
		echo Ui::setting(
			__('Backup key', 'agent-brain'),
			$hasKey
				? esc_html__('Made. Keep a copy somewhere safe: backups can only ever be decrypted with it, and the cloud never has one.', 'agent-brain')
				: esc_html__('Not made yet. Backups can only ever be decrypted with it, and the cloud never has a copy.', 'agent-brain'),
			$button(AdminActions::ACTION_DOWNLOAD_KEY, 'ab_backup_key', $hasKey ? __('Download backup key', 'agent-brain') : __('Generate and download backup key', 'agent-brain'), ! $connected && ! $hasKey)
		);
		echo '</section>';
		// phpcs:enable
	}

	/**
	 * Confirms the outcome of the Save / Back up now actions (their
	 * admin-post handlers redirect back here with one of these flags).
	 */
	private static function renderResultNotice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only result flags set by our own redirects.
		if (isset($_GET['ab_backup_saved'])) {
			$notice = ['success', __('Backup settings saved.', 'agent-brain')];
		} elseif (isset($_GET['ab_backup_ok'])) {
			$notice = ['success', __('Backup finished and was sent to the cloud.', 'agent-brain')];
		} elseif (isset($_GET['ab_backup_started'])) {
			$notice = ['info', __('Backup started. A large site keeps going in the background; check back shortly.', 'agent-brain')];
		} elseif (isset($_GET['ab_backup_error'])) {
			$notice = ['error', __('The backup ran but reported a problem. See the warning above or the Health tab.', 'agent-brain')];
		} else {
			return;
		}
		// phpcs:enable

		echo Notices::html($notice[0], $notice[1]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notices::html() escapes.
	}

	public static function handleToggleEnabled(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage backups.', 'agent-brain'), 403);
		}

		check_admin_referer(self::ACTION_TOGGLE_ENABLED);

		$enabled = isset($_POST['ab_backup_enabled']) && $_POST['ab_backup_enabled'] === '1';
		update_option(Cron::OPTION_ENABLED, $enabled);

		wp_safe_redirect(SettingsHub::url(SettingsHub::TAB_BACKUPS, ['ab_backup_saved' => '1']));
		exit;
	}
}
