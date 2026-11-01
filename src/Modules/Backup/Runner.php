<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

/**
 * End-to-end "run a backup now" flow: build the encrypted snapshot, upload
 * it, and always clean up the local encrypted copy afterwards — there is no
 * reason to keep a second copy on disk once the cloud has it.
 */
final class Runner {

	/**
	 * @return true|\WP_Error
	 */
	public static function run(): bool|\WP_Error {
		try {
			$snapshot = Snapshot::build();
		} catch (\Throwable $exception) {
			do_action('agent_brain/audit', [
				'actor_id'    => function_exists('get_current_user_id') ? get_current_user_id() : 0,
				'event'       => 'backup.run',
				'status'      => 'error',
				'object_type' => 'backup',
				'object_id'   => null,
				'meta'        => ['stage' => 'snapshot', 'message' => $exception->getMessage()],
			]);

			return new \WP_Error('ab_backup_snapshot_failed', 'Building the backup snapshot failed: ' . $exception->getMessage());
		}

		$backupId = wp_generate_uuid4();

		try {
			$result = UploadClient::upload($backupId, $snapshot['path'], $snapshot['bytes'], $snapshot['sha256']);
		} finally {
			@unlink($snapshot['path']);
		}

		if (is_wp_error($result)) {
			return $result;
		}

		do_action('agent_brain/audit', [
			'actor_id'    => function_exists('get_current_user_id') ? get_current_user_id() : 0,
			'event'       => 'backup.run',
			'status'      => 'ok',
			'object_type' => 'backup',
			'object_id'   => $backupId,
			'meta'        => [
				'bytes'          => $snapshot['bytes'],
				'markdown_files' => $snapshot['markdown_files'],
				'sql_tables'     => count($snapshot['sql_tables']),
			],
		]);

		return true;
	}
}
