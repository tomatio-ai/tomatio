<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * Read-only helper for Compression's own batch selection. Deliberately does
 * NOT add methods to Memory\MemoryRepository (out of this unit's scope
 * boundary) — instead it queries the same `ab_memory`/`ab_memory_links`
 * tables MemoryRepository owns, read-only, the same way
 * MemoryRepository::maintenanceExpiredWorking() is a trusted,
 * maintenance-only, scope-agnostic query used only from cron.
 */
final class BatchReader {

	public const DEFAULT_LIMIT = 200;

	/**
	 * Active working/episode rows that have not yet been compressed
	 * ("not yet compressed" = the row id never appears as a source_id in
	 * ab_memory_links), ordered so callers can group by (agent_id, scope_id).
	 *
	 * Excludes rows whose `source_ids` column is populated (CMP-4 fix): those
	 * rows are themselves the *output* of a prior compression/rollover pass
	 * (Runner::applyValidatedOutput's episode insert, or Rollover::rolloverGroup's
	 * episode insert — both always set `source_ids` on the row they create),
	 * already fact-extracted where applicable. Without this exclusion, a
	 * freshly-created summary episode has nothing linking *it* as a source
	 * yet, so it would satisfy the NOT EXISTS check above and re-enter the
	 * pending set on the very next run, minted into a brand-new (agent,
	 * scope, [episodeId]) idempotency key and re-compressed into a duplicate
	 * fact — defeating the rerun-is-idempotent contract (CMP-4). A row an
	 * agent (or another module such as Review's REV-2 outcome-episode) wrote
	 * directly, with no `source_ids`, is genuine unprocessed content and
	 * remains eligible here.
	 *
	 * MemoryRepository::insert() now stores an unset `source_ids` as SQL
	 * NULL (fixed: `$wpdb->prepare()`'s `%s` placeholder would otherwise
	 * cast a null argument to an empty string), but rows written before that
	 * fix — or by any other future writer that doesn't take the same care —
	 * may still carry `''` on disk, so the check below tests for both to
	 * stay correct regardless of that column's on-disk representation.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function pendingRows( int $limit = self::DEFAULT_LIMIT ): array {
		$memoryTable = MemoryRepository::memoryTable();
		$linksTable  = MemoryRepository::linksTable();

		$limit = max( 1, $limit );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		return Db::getResults(
			"SELECT m.* FROM {$memoryTable} m
				WHERE m.type IN ('working', 'episode') AND m.status = 'active'
				AND ( m.source_ids IS NULL OR m.source_ids = '' )
				AND NOT EXISTS (
					SELECT 1 FROM {$linksTable} l WHERE l.source_id = m.id
				)
				ORDER BY m.agent_id, m.scope_id, m.id
				LIMIT %d",
			[ $limit ]
		);
		// phpcs:enable
	}

	/**
	 * Existing active facts in exactly one (agent, scope) — the contract
	 * requires this list scoped to a single scope so the model chooses
	 * add/update/skip only against facts it's actually allowed to touch.
	 *
	 * @return array<int, array{id:int, text:string}>
	 */
	public static function existingFacts( int $agentId, int $scopeId ): array {
		$memoryTable = MemoryRepository::memoryTable();

		$rows = Db::getResults(
			"SELECT id, content FROM {$memoryTable}
				WHERE agent_id = %d AND scope_id = %d AND type = 'fact' AND status = 'active'
				ORDER BY id",
			[ $agentId, $scopeId ]
		);

		return array_map(
			static fn( array $row ): array => [ 'id' => (int) $row['id'], 'text' => (string) $row['content'] ],
			$rows
		);
	}
}
