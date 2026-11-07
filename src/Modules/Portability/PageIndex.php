<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Memory\MemoryRepository;
use WP_Post;

/**
 * Page ids and paths (PCP v0.3 SPEC section 5.1) for everything an export
 * will turn into a page, in the table ab_pcp_index:
 *
 *  - memory rows: id "ab-memory-<id>", path "memory/<type>/<id>";
 *  - knowledge and procedure posts: id "ab-knowledge-<ID>" or
 *    "ab-procedure-<ID>", path "knowledge/<slug>" or "procedures/<slug>"
 *    from an ASCII slug of the title, else the post id.
 *
 * Assigned once, when the object is created (or backfilled), and never
 * recomputed: editing a title keeps the path. path_lower is unique, so no
 * two paths differ only in case (rule 1 of section 5.1); a clash gets "-2",
 * "-3". The row goes when its object is deleted for good.
 */
final class PageIndex {

	public const KIND_MEMORY    = 'memory';
	public const KIND_KNOWLEDGE = 'knowledge';
	public const KIND_PROCEDURE = 'procedure';

	/** post type => [kind, path prefix] */
	public const POST_KINDS = [
		'ab_knowledge' => [self::KIND_KNOWLEDGE, 'knowledge'],
		'ab_procedure' => [self::KIND_PROCEDURE, 'procedures'],
	];

	/** Statuses that are not yet (or never) a page of their own. */
	private const SKIPPED_STATUSES = ['auto-draft', 'inherit'];

	private const MAX_ATTEMPTS = 1000;
	private const BATCH        = 500;

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'ab_pcp_index';
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			object_kind VARCHAR(16) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			pcp_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			path VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			path_lower VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (object_kind,object_id),
			UNIQUE KEY pcp_id (pcp_id),
			UNIQUE KEY path_lower (path_lower)
		) ENGINE=InnoDB {$charset};";
	}

	/** Pure: the path segment for a memory type. */
	public static function memoryTypeSegment(string $type): string {
		return in_array($type, MemoryRepository::TYPES, true) ? $type : 'other';
	}

	/**
	 * Index a memory row just inserted (MemoryRepository::insert()).
	 *
	 * @return array{pcp_id:string, path:string}|null
	 */
	public static function assignMemory(int $id, string $type): ?array {
		if ($id <= 0) {
			return null;
		}

		return self::insert(
			self::KIND_MEMORY,
			$id,
			'ab-memory-' . $id,
			'memory/' . self::memoryTypeSegment($type) . '/' . $id
		);
	}

	/**
	 * Index a knowledge or procedure post. A post that already has a row
	 * keeps it.
	 *
	 * @return array{pcp_id:string, path:string}|null
	 */
	public static function assignPost(int $postId): ?array {
		$post = get_post($postId);
		if (! $post instanceof WP_Post || ! isset(self::POST_KINDS[$post->post_type]) || in_array($post->post_status, self::SKIPPED_STATUSES, true)) {
			return null;
		}

		[$kind, $prefix] = self::POST_KINDS[$post->post_type];

		$existing = self::get($kind, $postId);
		if ($existing !== null) {
			return $existing;
		}

		$segment = Names::titleSegment((string) $post->post_title) ?? (string) $postId;

		return self::insert($kind, $postId, 'ab-' . $kind . '-' . $postId, $prefix . '/' . $segment);
	}

	/**
	 * @return array{pcp_id:string, path:string}|null
	 */
	public static function get(string $kind, int $objectId): ?array {
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT pcp_id, path FROM {$table} WHERE object_kind = %s AND object_id = %d LIMIT 1",
			[$kind, $objectId]
		);
		// phpcs:enable

		return isset($rows[0]) ? ['pcp_id' => (string) $rows[0]['pcp_id'], 'path' => (string) $rows[0]['path']] : null;
	}

	/**
	 * The object at a path (ignoring case), or null.
	 *
	 * @return array{kind:string, id:int}|null
	 */
	public static function byPath(string $path): ?array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows = Db::getResults('SELECT object_kind, object_id FROM ' . self::table() . ' WHERE path_lower = %s LIMIT 1', [strtolower($path)]);

		return isset($rows[0]) ? ['kind' => (string) $rows[0]['object_kind'], 'id' => (int) $rows[0]['object_id']] : null;
	}

	/**
	 * Forget the rows of memory rows deleted for good (forget, decay, the
	 * Memories screen).
	 *
	 * @param array<int, int> $ids
	 */
	public static function removeMemory(array $ids): void {
		self::remove([self::KIND_MEMORY], $ids);
	}

	/**
	 * Backfill: every memory row (one statement) and every knowledge and
	 * procedure post, in id order, so path clashes resolve the same way on
	 * every run. Re-running adds nothing.
	 *
	 * @return array{memory:int, posts:int}
	 */
	public static function backfill(): array {
		global $wpdb;

		$table  = self::table();
		$memory = MemoryRepository::memoryTable();
		$types  = MemoryRepository::TYPES;
		$in     = implode(', ', array_fill(0, count($types), '%s'));
		$now    = gmdate('Y-m-d H:i:s');

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$segment = "CASE WHEN type IN ({$in}) THEN type ELSE 'other' END";
		$added   = $wpdb->query(Db::prepare(
			"INSERT IGNORE INTO {$table} (object_kind, object_id, pcp_id, path, path_lower, created)
				SELECT %s, id, CONCAT('ab-memory-', id), CONCAT('memory/', {$segment}, '/', id), CONCAT('memory/', {$segment}, '/', id), %s
				FROM {$memory} ORDER BY id ASC",
			array_merge([self::KIND_MEMORY], $types, $types, [$now])
		));
		// phpcs:enable

		$posts  = 0;
		$lastId = 0;
		$skip   = self::SKIPPED_STATUSES;
		$kinds  = array_keys(self::POST_KINDS);

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$rows = Db::getResults(
				"SELECT p.ID FROM {$wpdb->posts} p
					LEFT JOIN {$table} i ON i.object_id = p.ID AND i.object_kind IN (%s, %s)
					WHERE p.ID > %d AND p.post_type IN (%s, %s) AND p.post_status NOT IN (%s, %s) AND i.object_id IS NULL
					ORDER BY p.ID ASC
					LIMIT %d",
				[self::KIND_KNOWLEDGE, self::KIND_PROCEDURE, $lastId, $kinds[0], $kinds[1], $skip[0], $skip[1], self::BATCH]
			);
			// phpcs:enable

			foreach ($rows as $row) {
				$lastId = (int) $row['ID'];
				if (self::assignPost($lastId) !== null) {
					++$posts;
				}
			}
		} while (count($rows) === self::BATCH);

		return ['memory' => is_int($added) ? $added : 0, 'posts' => $posts];
	}

	// -----------------------------------------------------------------
	// Hooks
	// -----------------------------------------------------------------

	/**
	 * wp_insert_post: every way a knowledge or procedure post is created
	 * (proposals, assembled artifacts, the editor, imports by code). The
	 * editor's auto-draft is skipped; its first real save assigns.
	 *
	 * @param mixed $post
	 */
	public static function onInsertPost(int $postId, $post = null): void {
		if (! $post instanceof WP_Post || ! isset(self::POST_KINDS[$post->post_type])) {
			return;
		}

		self::assignPost($postId);
	}

	/**
	 * deleted_post: a post deleted for good (trash emptied, forget). Posts in
	 * the trash keep their row, because they can come back.
	 *
	 * @param mixed $post
	 */
	public static function onDeletedPost(int $postId, $post = null): void {
		if ($post instanceof WP_Post && ! isset(self::POST_KINDS[$post->post_type])) {
			return;
		}

		// Quiet: the uninstall routine drops this table before it deletes posts.
		global $wpdb;
		$quiet = $wpdb->suppress_errors(true);
		self::remove([self::KIND_KNOWLEDGE, self::KIND_PROCEDURE], [$postId]);
		$wpdb->suppress_errors($quiet);
	}

	// -----------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------

	/**
	 * @return array{pcp_id:string, path:string}|null
	 */
	private static function insert(string $kind, int $objectId, string $pcpId, string $base): ?array {
		global $wpdb;

		$table = self::table();
		$now   = gmdate('Y-m-d H:i:s');
		$id    = self::freePcpId($pcpId);

		for ($n = 1; $n <= self::MAX_ATTEMPTS; $n++) {
			$path = Names::pathCandidate($base, $n);
			if (! Names::isPath($path) || $id === null) {
				return null;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$result = $wpdb->query(Db::prepare(
				"INSERT IGNORE INTO {$table} (object_kind, object_id, pcp_id, path, path_lower, created) VALUES (%s, %d, %s, %s, %s, %s)",
				[$kind, $objectId, $id, $path, strtolower($path), $now]
			));
			// phpcs:enable

			if ($result === false) {
				return null;
			}

			if ((int) $result === 1) {
				return ['pcp_id' => $id, 'path' => $path];
			}

			// Ignored: this object already has a row, the path is taken, or
			// (a race) the id was just taken.
			$existing = self::get($kind, $objectId);
			if ($existing !== null) {
				return $existing;
			}
			$id = self::freePcpId($id);
		}

		return null;
	}

	/**
	 * $pcpId, or the first of "<id>-2", "<id>-3" that no page holds. A
	 * restored page keeps the id its archive gave it ("ab-knowledge-<old
	 * ID>"), which a new post can match by number on the new install.
	 */
	private static function freePcpId(string $pcpId): ?string {
		$table = self::table();

		for ($n = 1; $n <= self::MAX_ATTEMPTS; $n++) {
			$candidate = $n === 1 ? $pcpId : $pcpId . '-' . $n;
			if (! Names::isPageId($candidate)) {
				return null;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$taken = Db::getResults("SELECT 1 FROM {$table} WHERE pcp_id = %s LIMIT 1", [$candidate]);
			// phpcs:enable
			if ($taken === []) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * @param array<int, string> $kinds
	 * @param array<int, int>    $ids
	 */
	private static function remove(array $kinds, array $ids): void {
		$ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
		if ($ids === [] || $kinds === []) {
			return;
		}

		global $wpdb;

		$table = self::table();
		$kin   = implode(', ', array_fill(0, count($kinds), '%s'));
		$idin  = implode(', ', array_fill(0, count($ids), '%d'));

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query(Db::prepare(
			"DELETE FROM {$table} WHERE object_kind IN ({$kin}) AND object_id IN ({$idin})",
			array_merge($kinds, $ids)
		));
		// phpcs:enable
	}
}
