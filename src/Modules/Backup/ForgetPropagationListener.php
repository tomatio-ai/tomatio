<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

/**
 * privacy/F1 (contracts.md "Final fix wave additions" / "Forget reaches
 * exports and backups"): a completed forget invalidates every backup that
 * could contain the subject. Listens on `agent_brain/forget_propagate` and:
 *
 *  1. discards any local in-progress backup (temp snapshot files + the
 *     BackupStateMachine's state) — never let a forget finish while a
 *     backup snapshot of the pre-forget data is still sitting on disk;
 *  2. if the cloud is connected, lists every remote backup for this site
 *     via the signed GET list and deletes (signed POST) every one created
 *     before this propagation ran, then schedules a fresh backup so the
 *     site isn't left without a current one;
 *  3. reports the outcome through `agent_brain/forget_propagation_result` by
 *     appending a `{store: 'backup', success, deleted, failed?, reason?,
 *     skipped?}` entry to the flat, numerically-indexed list the filter
 *     composes (`array_merge($result, [$entry])`) — the shape Forgetter's
 *     `hasPropagationFailure()` and the Forget module's own tests expect. A
 *     `success: false` (or a non-empty `reason` with no `success` key)
 *     marks the whole forget `residue_found` instead of `complete`.
 *
 * If the cloud isn't connected there is nothing remote to clean up and
 * that is not itself a failure — `Backup module docs`: "report
 * {deleted:0, skipped:'cloud_not_connected'}".
 */
final class ForgetPropagationListener {

	/**
	 * @var array<string, mixed>|null
	 */
	private static ?array $lastResult = null;

	public static function register(): void {
		add_action('agent_brain/forget_propagate', [self::class, 'onForgetPropagate'], 10, 2);
		add_filter('agent_brain/forget_propagation_result', [self::class, 'reportResult'], 10, 2);
	}

	public static function onForgetPropagate(string $subject, string $receiptId): void {
		$cutoff = time();

		// (1) never let an in-flight backup of pre-forget data survive a
		// completed forget — discard local temp snapshots and any
		// in-progress state unconditionally, cloud or not.
		BackupStateMachine::discard();

		if (! (bool) get_option('ab_cloud_enabled', false) || ! \AgentBrain\Modules\Cloud\Connector::isConnected()) {
			self::setLastResult(['deleted' => 0, 'skipped' => 'cloud_not_connected']);

			return;
		}

		$backups = UploadClient::listBackups();

		if (is_wp_error($backups)) {
			self::setLastResult(['deleted' => 0, 'failed' => 0, 'reason' => 'backup_delete_failed']);
			self::audit($receiptId, 'error', ['stage' => 'list', 'message' => $backups->get_error_message()]);

			return;
		}

		$deleted = 0;
		$failed  = 0;

		foreach ($backups as $backup) {
			$created = (int) $backup['created'];
			$id      = (string) $backup['backup_id'];

			if ($id === '' || $created >= $cutoff) {
				continue;
			}

			$result = UploadClient::deleteBackup($id);

			if (is_wp_error($result)) {
				$failed++;

				continue;
			}

			$deleted++;
		}

		$result = ['deleted' => $deleted, 'failed' => $failed];
		if ($failed > 0) {
			$result['reason'] = 'backup_delete_failed';
		}

		self::setLastResult($result);
		self::audit($receiptId, $failed > 0 ? 'error' : 'ok', ['deleted' => $deleted, 'failed' => $failed]);

		// (2c) schedule a fresh backup now that the pre-forget snapshots
		// (local and, where reachable, remote) are gone.
		BackupStateMachine::start();
	}

	/**
	 * Appends a `{store: 'backup', ...}` entry to the flat propagation
	 * result list rather than keying a top-level `backups` slot — the
	 * filter is shared with other modules (e.g. Forget's own contract),
	 * which compose it via `array_merge($result, $outcome)` into a flat,
	 * numerically-indexed list of per-module outcome entries.
	 *
	 * @param array<int|string, mixed> $result
	 * @return array<int|string, mixed>
	 */
	public static function reportResult(array $result, string $receiptId): array {
		$outcome = self::$lastResult ?? ['deleted' => 0, 'failed' => 0];
		$entry   = array_merge(['store' => 'backup'], $outcome);

		if (! array_key_exists('success', $entry)) {
			$entry['success'] = ! (isset($entry['reason']) && $entry['reason'] !== '');
		}

		return array_merge($result, [$entry]);
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function setLastResult(array $result): void {
		self::$lastResult = $result;
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function audit(string $receiptId, string $status, array $meta = []): void {
		do_action('agent_brain/audit', [
			'actor_id'    => function_exists('get_current_user_id') ? get_current_user_id() : 0,
			'event'       => 'backup.forget_propagate',
			'status'      => $status,
			'object_type' => 'ab_forget_receipt',
			'object_id'   => $receiptId,
			'meta'        => $meta,
		]);
	}
}
