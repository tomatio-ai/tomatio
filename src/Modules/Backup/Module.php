<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

use AgentBrain\Core\ModuleInterface;

/**
 * Backup module (Wave 2 "Backups" contract): encrypted Markdown+SQL
 * snapshots, chunked signed upload to the cloud, daily cron, and the
 * "Back up now" / "download backup key" curator actions.
 *
 * No new database tables of its own — state lives in options
 * (ab_backup_key, ab_backup_enabled, ab_backup_last_warning).
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'backup';
	}

	public function register(): void {
		Cron::register();
		BackupStateMachine::register();
		ForgetPropagationListener::register();

		add_action('admin_menu', [SettingsPage::class, 'registerPage']);
		SettingsPage::registerAdminNotice();

		add_action('admin_post_' . SettingsPage::ACTION_TOGGLE_ENABLED, [SettingsPage::class, 'handleToggleEnabled']);
		add_action('admin_post_' . AdminActions::ACTION_BACKUP_NOW, [AdminActions::class, 'handleBackupNow']);
		add_action('admin_post_' . AdminActions::ACTION_DOWNLOAD_KEY, [AdminActions::class, 'handleDownloadKey']);
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [];
	}

	public function activate(): void {
		Cron::activate();
	}

	public function deactivate(): void {
		Cron::deactivate();
	}
}
