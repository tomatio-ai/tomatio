<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

/**
 * Thin adapter onto the PCP page writer
 * (`AgentBrain\Modules\Portability\BackupPages::write()`): a snapshot's
 * Markdown half holds every page and its history as a PCP export writes
 * them (memory/<path>.md, history/<path>/<n>.md), so a backup and an export
 * never disagree about the pages. The snapshot itself stays encrypted with
 * the site key; that is what makes it a backup rather than an export.
 *
 * L26 fail-loud: if the page writer is missing (or fails), the snapshot
 * still proceeds with the SQL half, but this never fails silently: it
 * records an admin-visible warning and an audit event so a curator notices
 * the backup is missing the Markdown pages.
 */
final class MarkdownStage {

	public const CONTRACT_CLASS = 'AgentBrain\\Modules\\Portability\\BackupPages';

	public const CONTRACT_METHOD = 'write';

	public const OPTION_LAST_WARNING = 'ab_backup_last_warning';

	/**
	 * Writes the pages into $dir. Returns the list of files written, or an
	 * empty array (with a recorded warning) if the writer could not run.
	 *
	 * @return array<int, string>
	 */
	public static function export(string $dir): array {
		// Filterable only so the degrade-loud path (writer absent) is
		// exercisable from a test without ever affecting production, which
		// always resolves to the real contracted class.
		$writerClass = (string) apply_filters('agent_brain/backup_markdown_exporter_class', self::CONTRACT_CLASS);

		if (! class_exists($writerClass) || ! method_exists($writerClass, self::CONTRACT_METHOD)) {
			self::warn('The PCP page writer is not available; backup snapshot skips the Markdown pages.');

			return [];
		}

		try {
			$reflection = new \ReflectionMethod($writerClass, self::CONTRACT_METHOD);

			/** @var mixed $files */
			$files = $reflection->isStatic()
				? $reflection->invoke(null, $dir)
				: $reflection->invoke(new $writerClass(), $dir);
		} catch (\Throwable $exception) {
			self::warn('The PCP page writer threw: ' . $exception->getMessage());

			return [];
		}

		if (! is_array($files)) {
			self::warn('The PCP page writer returned an unexpected type; treating as empty.');

			return [];
		}

		return array_values(array_map('strval', $files));
	}

	private static function warn(string $message): void {
		update_option(self::OPTION_LAST_WARNING, $message, false);

		do_action('agent_brain/audit', [
			'actor_id'    => function_exists('get_current_user_id') ? get_current_user_id() : 0,
			'event'       => 'backup.markdown_export_degraded',
			'status'      => 'error',
			'object_type' => 'backup',
			'object_id'   => null,
			'meta'        => ['message' => $message],
		]);
	}
}
