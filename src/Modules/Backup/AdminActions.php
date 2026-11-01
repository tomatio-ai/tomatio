<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

use AgentBrain\Modules\Admin\SettingsHub;

/**
 * State-changing admin-post handlers for backups, each gated on the
 * `ab_manage` capability AND a matching nonce (same pattern as
 * Cloud\Settings). The key download handler is the ONLY place the raw
 * backup key ever leaves the server, and it is streamed straight to the
 * browser as a file download — never echoed into a rendered page, never
 * logged.
 */
final class AdminActions {

	public const CAPABILITY       = 'ab_manage';
	public const ACTION_BACKUP_NOW = 'ab_backup_now';
	public const ACTION_DOWNLOAD_KEY = 'ab_backup_download_key';

	public static function handleBackupNow(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to run a backup.', 'agent-brain'), 403);
		}

		check_admin_referer(self::ACTION_BACKUP_NOW);

		// "Back up now" starts (or resumes) the very same resumable
		// BackupStateMachine the daily cron drives (ops/F3): a small site's
		// backup still completes within this one request; a large one
		// simply keeps going on the next scheduled tick.
		BackupStateMachine::start();

		self::redirectBack(self::backupStatusArgs());
	}

	/**
	 * @return array<string, string>
	 */
	private static function backupStatusArgs(): array {
		if (BackupStateMachine::isRunning()) {
			return ['ab_backup_started' => '1'];
		}

		$warning = get_option(MarkdownStage::OPTION_LAST_WARNING);

		return is_string($warning) && $warning !== ''
			? ['ab_backup_error' => 'ab_backup_failed']
			: ['ab_backup_ok' => '1'];
	}

	public static function handleDownloadKey(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to download the backup key.', 'agent-brain'), 403);
		}

		check_admin_referer(self::ACTION_DOWNLOAD_KEY);

		$key = KeyManager::getOrCreateKey();

		do_action('agent_brain/audit', [
			'actor_id'    => get_current_user_id(),
			'event'       => 'backup.key_downloaded',
			'status'      => 'ok',
			'object_type' => 'backup_key',
			'object_id'   => null,
			// Deliberately no key material in meta.
			'meta'        => [],
		]);

		nocache_headers();
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="agent-brain-backup-key.txt"');
		header('Content-Length: ' . strlen(base64_encode($key)));

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw key download, not HTML output.
		echo base64_encode($key);
		exit;
	}

	/**
	 * @param array<string, string> $args
	 */
	private static function redirectBack(array $args): void {
		wp_safe_redirect(SettingsHub::url(SettingsHub::TAB_BACKUPS, $args));
		exit;
	}
}
