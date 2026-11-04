<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Audit;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Forget\Forgetter;
use AgentBrain\Modules\Forget\Matcher;
use AgentBrain\Modules\Portability\Restores;

/**
 * Wires the audit log into Forget (PCP v0.3 SPEC 8.9.1 item 3). An entry's
 * meta is free-form context and can hold names: agent.propose keeps the
 * proposed agent's name, an ability call its arguments. A subject erasure
 * finds every entry whose meta mentions the subject (as typed or as JSON
 * stores it) and clears that meta. The entry itself stays: when, who, the
 * action and its outcome are the audit trail, and hold no content.
 *
 * Left out on purpose: Forget's own erase.* entries (their meta is counts,
 * written by the erasure itself), and entries a restore kept (object type
 * pcp_restored), whose meta is the exported line, which by design holds
 * categories and ids, never content.
 */
final class Forget {

	public const STORE = 'audit_meta';

	/**
	 * agent_brain/forget_collect: entries whose meta mentions the subject.
	 *
	 * @param mixed $collected
	 * @return mixed
	 */
	public static function collect($collected, string $subject) {
		if (! is_array($collected) || trim($subject) === '') {
			return $collected;
		}

		$table  = Repository::tableName();
		$likes  = Matcher::jsonLikes($subject);
		$events = array_merge([Forgetter::EVENT_REQUEST, Forgetter::EVENT_COMPLETE], Forgetter::LEGACY_EVENTS);
		$in     = implode(', ', array_fill(0, count($events), '%s'));
		$or     = implode(' OR ', array_fill(0, count($likes), 'meta LIKE %s'));

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT id FROM {$table}
				WHERE meta IS NOT NULL
				AND ( object_type IS NULL OR object_type <> %s )
				AND event NOT IN ({$in})
				AND ( {$or} )",
			array_merge([Restores::RESTORED_AUDIT_TYPE], $events, $likes)
		);
		// phpcs:enable

		foreach ($rows as $row) {
			$collected[] = ['store' => self::STORE, 'id' => (int) $row['id']];
		}

		return $collected;
	}

	/**
	 * agent_brain/forget_delete: clears the meta of the entries collected.
	 *
	 * @param mixed $ids
	 */
	public static function delete(string $store, $ids): void {
		if ($store !== self::STORE || ! is_array($ids)) {
			return;
		}

		$ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
		if ($ids === []) {
			return;
		}

		global $wpdb;
		$table = Repository::tableName();

		foreach (array_chunk($ids, 500) as $chunk) {
			$in = implode(', ', array_fill(0, count($chunk), '%d'));
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$wpdb->query(Db::prepare(
				"UPDATE {$table} SET meta = NULL WHERE id IN ({$in}) AND ( object_type IS NULL OR object_type <> %s )",
				array_merge($chunk, [Restores::RESTORED_AUDIT_TYPE])
			));
			// phpcs:enable
		}
	}
}
