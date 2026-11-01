<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;

/**
 * What a restore keeps verbatim because TomaTío has no columns for it,
 * so its own next export gives it back unchanged (PCP v0.3 SPEC sections
 * 8.5 item 4 and 8.8), in table ab_pcp_kept:
 *
 *  - receipt: each erasure receipt line of the archive, in order. Receipts
 *    describe erasures already done; they hold digests and counts, never
 *    the subject.
 *  - extension: each file of an extension this version does not import,
 *    under its archive path, and extension_entry: its manifest entry.
 *
 * Kept extension files are content: forget searches and deletes them like
 * any other store (collect() and delete() below).
 */
final class Kept {

	public const KIND_RECEIPT         = 'receipt';
	public const KIND_EXTENSION       = 'extension';
	public const KIND_EXTENSION_ENTRY = 'extension_entry';
	/** #547: a share link record a migration brought, to create again; never exported again. */
	public const KIND_SHARE           = 'share';

	public const FORGET_STORE = 'pcp_kept';

	private const BATCH = 200;

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'ab_pcp_kept';
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(20) NOT NULL,
			path VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
			body LONGBLOB NOT NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY kind (kind, id)
		) ENGINE=InnoDB {$charset};";
	}

	public static function add(string $kind, string $path, string $body): int {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			['kind' => $kind, 'path' => $path, 'body' => $body, 'created' => gmdate('Y-m-d H:i:s')],
			['%s', '%s', '%s', '%s']
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Calls $each(path, body) for every row of a kind, in the order kept.
	 *
	 * @param callable(string, string): void $each
	 */
	public static function each(string $kind, callable $each): int {
		$table  = self::table();
		$lastId = 0;
		$count  = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$rows = Db::getResults(
				"SELECT id, path, body FROM {$table} WHERE kind = %s AND id > %d ORDER BY id ASC LIMIT %d",
				[$kind, $lastId, self::BATCH]
			);
			// phpcs:enable

			foreach ($rows as $row) {
				$lastId = (int) $row['id'];
				$each((string) $row['path'], (string) $row['body']);
				++$count;
			}
		} while (count($rows) === self::BATCH);

		return $count;
	}

	public static function count(string $kind = ''): int {
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = $kind === ''
			? Db::getResults("SELECT COUNT(*) AS c FROM {$table}")
			: Db::getResults("SELECT COUNT(*) AS c FROM {$table} WHERE kind = %s", [$kind]);
		// phpcs:enable

		return (int) ($rows[0]['c'] ?? 0);
	}

	/**
	 * agent_brain/forget_collect: kept extension files that mention the
	 * subject (case-insensitive, like the other stores).
	 *
	 * @param mixed $collected
	 * @return mixed
	 */
	public static function collect($collected, string $subject) {
		if (! is_array($collected) || trim($subject) === '') {
			return $collected;
		}

		global $wpdb;
		$table = self::table();
		$like  = '%' . $wpdb->esc_like($subject) . '%';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT id FROM {$table} WHERE kind = %s AND CONVERT(body USING utf8mb4) LIKE %s",
			[self::KIND_EXTENSION, $like]
		);
		// phpcs:enable

		foreach ($rows as $row) {
			$collected[] = ['store' => self::FORGET_STORE, 'id' => (int) $row['id']];
		}

		return $collected;
	}

	/**
	 * agent_brain/forget_delete.
	 *
	 * @param mixed $ids
	 */
	public static function delete(string $store, $ids): void {
		if ($store !== self::FORGET_STORE || ! is_array($ids)) {
			return;
		}

		$ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
		if ($ids === []) {
			return;
		}

		global $wpdb;
		$table = self::table();
		$in    = implode(', ', array_fill(0, count($ids), '%d'));
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query(Db::prepare("DELETE FROM {$table} WHERE kind = %s AND id IN ({$in})", array_merge([self::KIND_EXTENSION], $ids)));
		// phpcs:enable
	}
}
