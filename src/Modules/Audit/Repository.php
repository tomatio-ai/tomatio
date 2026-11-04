<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Audit;

use AgentBrain\Core\Db;

/**
 * Persists `agent_brain/audit` events to {$wpdb->prefix}ab_audit.
 *
 * NOTE (learnings, flagged for orchestrator): the table name here is built
 * directly as `$wpdb->prefix . 'ab_audit'` rather than via
 * AgentBrain\Core\Db::table(), because Db::table() hardcodes an
 * `agent_brain_<name>` infix (see the Health sample module) while the
 * binding cross-module contract (the design contracts,
 * "## Tables") names this table literally `{prefix}ab_audit`. Db::table()
 * is a shared bootstrap file out of this unit's scope to change; every
 * other module reading/writing `ab_audit`, `ab_memory`, etc. will hit the
 * same mismatch and should follow this same pattern (or the orchestrator
 * should reconcile Db::table() with the contract centrally).
 */
final class Repository {

	public const STATUS_OK     = 'ok';
	public const STATUS_DENIED = 'denied';
	public const STATUS_ERROR  = 'error';

	/** Set when the retention prune first removes entries. */
	public const OPTION_PRUNED_AT = 'ab_audit_pruned_at';

	public static function tableName(): string {
		global $wpdb;

		return $wpdb->prefix . 'ab_audit';
	}

	/**
	 * Validates and persists one audit event. Malformed events are never
	 * dropped: they are recorded as a status=error row describing what was
	 * wrong, so "every ability call / every audit emission produces exactly
	 * one row" holds even for buggy emitters (L26 fail-loud).
	 *
	 * @param mixed $rawEvent
	 */
	public static function persist($rawEvent): void {
		$result = EventValidator::validate($rawEvent);

		if ($result->valid) {
			self::insertRow(
				is_int($rawEvent['actor_id']) ? $rawEvent['actor_id'] : 0,
				(string) $rawEvent['event'],
				(string) $rawEvent['status'],
				isset($rawEvent['object_type']) ? (string) $rawEvent['object_type'] : null,
				isset($rawEvent['object_id']) ? (string) $rawEvent['object_id'] : null,
				isset($rawEvent['args_hash']) ? (string) $rawEvent['args_hash'] : null,
				isset($rawEvent['meta']) && is_array($rawEvent['meta']) ? $rawEvent['meta'] : null,
				isset($rawEvent['duration_ms']) ? (int) round((float) $rawEvent['duration_ms']) : null
			);

			return;
		}

		$actorId = is_int($result->raw['actor_id'] ?? null) ? $result->raw['actor_id'] : 0;

		self::insertRow(
			$actorId,
			'audit.malformed_event',
			self::STATUS_ERROR,
			'audit',
			is_string($result->raw['event'] ?? null) ? $result->raw['event'] : null,
			null,
			[
				'errors'        => $result->errors,
				'received_keys' => array_keys($result->raw),
			],
			null
		);
	}

	/**
	 * @param array<string, mixed>|null $meta
	 */
	private static function insertRow(
		int $actorId,
		string $event,
		string $status,
		?string $objectType,
		?string $objectId,
		?string $argsHash,
		?array $meta,
		?int $durationMs
	): void {
		global $wpdb;

		$table = self::tableName();

		// wp_insert() already parameterizes via wpdb->prepare internally per
		// $format; this is the standard safe insert path (no raw SQL string
		// interpolation), consistent with Db::prepare()'s guarantee elsewhere.
		$stored = $wpdb->insert(
			$table,
			[
				'ts'          => current_time('mysql', true),
				'actor_id'    => $actorId,
				'event'       => $event,
				'status'      => $status,
				'object_type' => $objectType,
				'object_id'   => $objectId,
				'args_hash'   => $argsHash,
				'meta'        => $meta !== null ? wp_json_encode($meta) : null,
				'duration_ms' => $durationMs,
			],
			['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
		);

		// The log cannot record its own failure, so it goes to the PHP error
		// log and an action instead of vanishing (#183).
		if ($stored === false || (int) $stored < 1) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the audit table refused the write; this is the only place left to say so.
			error_log(sprintf('[agent-brain] audit entry not stored: %s (%s)', $event, (string) $wpdb->last_error));
			do_action('agent_brain/audit_write_failed', $event, $status, (string) $wpdb->last_error);
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent(int $limit, int $offset = 0): array {
		$limit  = max(1, min(200, $limit));
		$offset = max(0, $offset);

		$sql = 'SELECT id, ts, actor_id, event, status, object_type, object_id, args_hash, meta, duration_ms FROM '
			. self::tableName() . ' ORDER BY ts DESC, id DESC LIMIT %d OFFSET %d';

		return Db::getResults($sql, [$limit, $offset]);
	}

	public static function count(): int {
		$rows = Db::getResults('SELECT COUNT(*) AS c FROM ' . self::tableName());

		return isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
	}

	/**
	 * Deletes rows older than $days days. Returns the number of rows removed.
	 *
	 * The cutoff is computed in PHP (UTC), matching the `ts` column writes
	 * (current_time('mysql', true) in insertRow() above): UTC_TIMESTAMP()
	 * is not translated by the SQLite drop-in (#405), and a plain
	 * DATETIME literal is identical SQL on both runtimes.
	 */
	public static function pruneOlderThan(int $days): int {
		global $wpdb;

		$days   = max(0, $days);
		$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

		$sql = 'DELETE FROM ' . self::tableName() . ' WHERE ts < %s';
		// $sql already went through Db::prepare() below; the sniff can't see through that indirection.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(Db::prepare($sql, [$cutoff]));

		$removed = (int) $wpdb->rows_affected;
		if ($removed > 0) {
			// PCP exports declare the log as cut (audit.truncated) from now on.
			update_option(self::OPTION_PRUNED_AT, gmdate('Y-m-d H:i:s'), false);
		}

		return $removed;
	}
}
