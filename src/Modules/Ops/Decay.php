<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Ops;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Portability\PageIndex;

/**
 * OPS-3 decay: when DB usage is >= 60% of `ab_db_budget_mb`, prune the
 * lowest-importance, oldest `ab_memory` rows that are either already
 * `status = 'rolled_up'` (superseded by a summary elsewhere) or still
 * `type = 'working'` (ephemeral by design, never curator-approved), in
 * batches until usage is back under 50%. Never touches:
 *
 * - approved knowledge/procedures (those are `ab_knowledge`/`ab_procedure`
 *   posts, never rows in `ab_memory` — decay only ever queries ab_memory);
 * - a memory row still needed as the *source* of an existing derived row
 *   per `ab_memory_links` (derived_kind='memory' rows that still exist, or
 *   derived_kind='post' rows whose post still exists) — pruning a row that
 *   a still-existing summary/proposal cites as provenance would break the
 *   provenance chain the contract treats as load-bearing.
 *
 * Each batch is audited via `agent_brain/audit` before being deleted, so a
 * curator can always see what decay removed and why.
 */
final class Decay {

	// How many candidate ids to fetch per SELECT round-trip. This is just a
	// page size for reading candidates cheaply; it is NOT how many rows get
	// deleted before the budget is rechecked (see DELETE_CHUNK_SIZE below) —
	// deleting a whole fetched page at once would prune higher-importance
	// rows the target didn't actually require removing.
	public const CANDIDATE_PAGE_SIZE = 200;

	// Rows are deleted (and audited) one at a time so the budget threshold
	// is rechecked after every single deletion: pruning stops exactly at
	// the lowest-importance row that was actually necessary, never
	// spilling into a higher-importance row already inside a fetched page
	// just because it happened to share that page.
	public const DELETE_CHUNK_SIZE = 1;

	public const MAX_PAGES = 500; // hard stop so a runaway budget can't loop forever in one cron tick.

	// A row is prunable when it is either already superseded (status
	// 'rolled_up') or still ephemeral working memory (type 'working');
	// 'deleted'/'proposed' status and 'episode'/'fact' type rows that
	// aren't rolled_up are never candidates. 'active' is a status value,
	// not a type — 'working'/'episode'/'fact' are the type enum.
	public const PRUNABLE_STATUS = 'rolled_up';
	public const PRUNABLE_TYPE   = 'working';

	/**
	 * Run one decay pass. Returns the number of rows deleted.
	 */
	public static function run(): int {
		if (! DbBudget::isAtWarnThreshold()) {
			return 0;
		}

		$deleted = 0;
		$pages   = 0;

		while (! DbBudget::isUnderDecayTarget() && $pages < self::MAX_PAGES) {
			$ids = self::candidatesInPruneOrder();
			if ($ids === []) {
				break; // Nothing left that's safe to prune.
			}

			foreach (array_chunk($ids, self::DELETE_CHUNK_SIZE) as $chunk) {
				// PHPStan can't see that DbBudget::isUnderDecayTarget() reads
				// live information_schema state that self::pruneIds() below
				// (and prior loop iterations) mutates, so it treats this as
				// the same (already-false) expression checked in the while
				// condition above rather than a fresh read.
				// @phpstan-ignore-next-line booleanNot.alwaysFalse, if.alwaysFalse
				if (DbBudget::isUnderDecayTarget()) {
					break 2;
				}

				$deleted += self::pruneIds($chunk);
			}

			$pages++;
		}

		return $deleted;
	}

	/**
	 * Candidate `ab_memory` row ids, lowest-importance/oldest first, that
	 * are safe to prune right now (prunable status + not still cited as a
	 * source of an existing derived row). Exposed publicly (not just via
	 * run()) so ordering/protection can be asserted independent of actual
	 * information_schema byte accounting, which does not shrink
	 * deterministically after a handful of DELETEs on InnoDB.
	 *
	 * @return array<int, int>
	 */
	public static function candidatesInPruneOrder(int $limit = self::CANDIDATE_PAGE_SIZE): array {
		global $wpdb;

		$table      = $wpdb->prefix . 'ab_memory';
		$linksTable = $wpdb->prefix . 'ab_memory_links';
		$postsTable = $wpdb->posts;

		// A row is protected if it appears as a source_id in ab_memory_links
		// for a derived_kind='memory' row that still exists in ab_memory, or
		// a derived_kind='post' row that still exists in wp_posts.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- routed through Db::prepare below.
		$sql = "SELECT m.id
			FROM {$table} m
			WHERE (m.status = %s OR m.type = %s)
			AND NOT EXISTS (
				SELECT 1 FROM {$linksTable} l
				WHERE l.source_id = m.id AND l.derived_kind = 'memory'
				AND EXISTS (SELECT 1 FROM {$table} d WHERE d.id = l.derived_id)
			)
			AND NOT EXISTS (
				SELECT 1 FROM {$linksTable} l2
				WHERE l2.source_id = m.id AND l2.derived_kind = 'post'
				AND EXISTS (SELECT 1 FROM {$postsTable} p WHERE p.ID = l2.derived_id)
			)
			ORDER BY m.importance ASC, m.created ASC
			LIMIT %d";

		$params = [self::PRUNABLE_STATUS, self::PRUNABLE_TYPE, max(1, $limit)];
		$rows   = Db::getResults($sql, $params);

		return array_map(static fn (array $row): int => (int) $row['id'], $rows);
	}

	/**
	 * Delete and audit a specific set of `ab_memory` row ids. Public so
	 * tests can prune a precise, deterministic set (e.g. exactly one row)
	 * without depending on real-time budget byte accounting.
	 *
	 * @param array<int, int> $ids
	 */
	public static function pruneIds(array $ids): int {
		if ($ids === []) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'ab_memory';

		if (function_exists('do_action')) {
			do_action('agent_brain/audit', [
				'actor_id'    => 0,
				'event'       => 'ops.decay_prune_batch',
				'status'      => 'ok',
				'object_type' => 'ab_memory',
				'object_id'   => implode(',', $ids),
				'meta'        => ['count' => count($ids)],
			]);
		}

		$placeholders = implode(',', array_fill(0, count($ids), '%d'));

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- routed through Db::prepare below.
		$sql = "DELETE FROM {$table} WHERE id IN ({$placeholders})";

		$prepared = Db::prepare($sql, $ids);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $prepared already went through Db::prepare above.
		$wpdb->query($prepared);

		$deleted = (int) $wpdb->rows_affected;
		PageIndex::removeMemory($ids);

		return $deleted;
	}
}
