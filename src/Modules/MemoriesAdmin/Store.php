<?php

declare(strict_types=1);

namespace AgentBrain\Modules\MemoriesAdmin;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Portability\PageIndex;

/**
 * Curator-side access to raw ab_memory rows for the Memories admin screen.
 *
 * This is deliberately NOT an agent request path: it has no per-agent scope
 * filter, exactly like MemoryRepository's maintenance* methods, and is only
 * reachable from a screen gated on ab_manage. Agents keep going through
 * MemoryRepository::search()/get() with their allowed scope ids. The screen
 * passes the viewer's own visible scopes (query()'s $scopeIds, findFor()),
 * so a person sees only the memory in their scopes (#552).
 */
final class Store {

	public const PER_PAGE = 20;

	/**
	 * @param array<string, mixed> $f Output of Filters::fromArray().
	 * @param int[]|null $scopeIds Only rows in these scopes; null for every scope.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public static function query(array $f, int $page, int $perPage = self::PER_PAGE, ?array $scopeIds = null): array {
		global $wpdb;

		$table = MemoryRepository::memoryTable();
		[$where, $params] = self::scoped(self::where($f, $wpdb), $scopeIds);

		// Db::prepare() skips prepare() for an empty param list; the unfiltered
		// WHERE is the constant "1=1" so that is safe, but keep one placeholder
		// in the count so every query takes the same path.
		$countRows = Db::getResults("SELECT COUNT(*) AS c FROM {$table} WHERE {$where} AND %d = %d", array_merge($params, [1, 1]));
		$total     = (int) ($countRows[0]['c'] ?? 0);

		$page    = max(1, $page);
		$perPage = max(1, min(100, $perPage));
		$offset  = ($page - 1) * $perPage;

		$rows = Db::getResults(
			"SELECT id, agent_id, scope_id, type, content, search_keys, importance, status, client, created FROM {$table} WHERE {$where} ORDER BY created DESC, id DESC LIMIT %d OFFSET %d",
			array_merge($params, [$perPage, $offset])
		);

		return ['rows' => $rows, 'total' => $total];
	}

	/**
	 * How many rows of each type match $f, whatever its type filter: the
	 * counts on the Memories screen's type chips (#751).
	 *
	 * @param array<string, mixed> $f Output of Filters::fromArray().
	 * @param int[]|null $scopeIds
	 * @return array<string, int> type => rows
	 */
	public static function typeCounts(array $f, ?array $scopeIds = null): array {
		global $wpdb;

		$table = MemoryRepository::memoryTable();
		[$where, $params] = self::scoped(self::where(['type' => ''] + $f, $wpdb), $scopeIds);

		$out = [];
		foreach (Db::getResults("SELECT type, COUNT(*) AS c FROM {$table} WHERE {$where} AND %d = %d GROUP BY type", array_merge($params, [1, 1])) as $row) {
			$out[(string) $row['type']] = (int) $row['c'];
		}

		return $out;
	}

	/**
	 * Rows written since $sinceUtc ("Y-m-d H:i:s"), in $scopeIds.
	 *
	 * @param int[]|null $scopeIds
	 */
	public static function countSince(string $sinceUtc, ?array $scopeIds = null): int {
		global $wpdb;

		$table = MemoryRepository::memoryTable();
		[$where, $params] = self::scoped(self::where(Filters::fromArray([]), $wpdb), $scopeIds);
		$rows = Db::getResults("SELECT COUNT(*) AS c FROM {$table} WHERE {$where} AND created >= %s", array_merge($params, [$sinceUtc]));

		return (int) ($rows[0]['c'] ?? 0);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find(int $id): ?array {
		if ($id <= 0) {
			return null;
		}

		$table = MemoryRepository::memoryTable();
		$rows  = Db::getResults("SELECT * FROM {$table} WHERE id = %d LIMIT 1", [$id]);

		return $rows[0] ?? null;
	}

	/**
	 * The row, or null when it does not exist or $viewerId may not see its
	 * scope (#552): the screen never confirms a memory outside them.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function findFor(int $viewerId, int $id): ?array {
		$row = self::find($id);

		return $row !== null && ScopeVisibility::canSeeScope($viewerId, (int) $row['scope_id']) ? $row : null;
	}

	public static function updateMeta(int $id, int $importance, string $searchKeys): bool {
		global $wpdb;

		if (self::find($id) === null || $importance < 0 || $importance > 100) {
			return false;
		}

		$table = MemoryRepository::memoryTable();
		$keys  = $searchKeys === '' ? 'NULL' : '%s';
		$args  = $searchKeys === '' ? [$importance, $id] : [$importance, $searchKeys, $id];

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point; $keys is a fixed literal.
		$result = $wpdb->query(Db::prepare("UPDATE {$table} SET importance = %d, search_keys = {$keys} WHERE id = %d", $args));
		// phpcs:enable

		return $result !== false;
	}

	/**
	 * Deletes one memory row and its provenance link rows (both directions),
	 * mirroring Forget's row delete. Derived summaries are left in place.
	 */
	public static function delete(int $id): bool {
		global $wpdb;

		if (self::find($id) === null) {
			return false;
		}

		$memory = MemoryRepository::memoryTable();
		$links  = MemoryRepository::linksTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query(Db::prepare(
			"DELETE FROM {$links} WHERE (derived_kind = %s AND derived_id = %d) OR source_id = %d",
			['memory', $id, $id]
		));
		$result = $wpdb->query(Db::prepare("DELETE FROM {$memory} WHERE id = %d", [$id]));
		// phpcs:enable

		$deleted = $result !== false && (int) $result > 0;
		if ($deleted) {
			PageIndex::removeMemory([$id]);
		}

		return $deleted;
	}

	/**
	 * Narrows a where() result to $scopeIds; null leaves it as it is.
	 *
	 * @param array{0: string, 1: array<int, mixed>} $where
	 * @param int[]|null $scopeIds
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function scoped(array $where, ?array $scopeIds): array {
		if ($scopeIds === null) {
			return $where;
		}

		$scopeIds = array_values(array_map('intval', $scopeIds));

		return $scopeIds === []
			? [$where[0] . ' AND 1=0', $where[1]]
			: [$where[0] . ' AND scope_id IN (' . implode(', ', array_fill(0, count($scopeIds), '%d')) . ')', array_merge($where[1], $scopeIds)];
	}

	/**
	 * @param array<string, mixed> $f
	 * @param \wpdb $wpdb
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function where(array $f, $wpdb): array {
		$clauses = ['1=1'];
		$params  = [];

		if ((int) $f['scope_id'] > 0) {
			$clauses[] = 'scope_id = %d';
			$params[]  = (int) $f['scope_id'];
		}

		if ((int) $f['agent_id'] > 0) {
			$clauses[] = 'agent_id = %d';
			$params[]  = (int) $f['agent_id'];
		}

		if ($f['type'] !== '') {
			$clauses[] = 'type = %s';
			$params[]  = (string) $f['type'];
		}

		if ($f['status'] !== '') {
			$clauses[] = 'status = %s';
			$params[]  = (string) $f['status'];
		}

		if ((int) $f['imp_min'] > 0) {
			$clauses[] = 'importance >= %d';
			$params[]  = (int) $f['imp_min'];
		}

		if ((int) $f['imp_max'] < 100) {
			$clauses[] = 'importance <= %d';
			$params[]  = (int) $f['imp_max'];
		}

		if ($f['date_from'] !== '') {
			$clauses[] = 'created >= %s';
			$params[]  = $f['date_from'] . ' 00:00:00';
		}

		if ($f['date_to'] !== '') {
			$clauses[] = 'created <= %s';
			$params[]  = $f['date_to'] . ' 23:59:59';
		}

		if ($f['q'] !== '') {
			$like      = '%' . $wpdb->esc_like((string) $f['q']) . '%';
			$clauses[] = '(content LIKE %s OR search_keys LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
		}

		return [implode(' AND ', $clauses), $params];
	}
}
