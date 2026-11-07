<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;

/**
 * Idempotency keys of imported pages (PCP v0.3 SPEC section 8.7.3 item 6)
 * in ab_pcp_import_keys: a hash of the source path and the SHA-256 of the
 * file as received. A page whose key is here was imported before and is
 * skipped as a duplicate; an edited source page has a new hash and is
 * imported again under on_conflict.
 */
final class ImportKeys {

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'ab_pcp_import_keys';
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			import_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			source_path VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			path VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			created DATETIME NOT NULL,
			PRIMARY KEY  (import_key)
		) ENGINE=InnoDB {$charset};";
	}

	public static function exists(string $key): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return Db::getResults('SELECT 1 FROM ' . self::table() . ' WHERE import_key = %s LIMIT 1', [$key]) !== [];
	}

	public static function add(string $key, string $sourcePath, string $path): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query(Db::prepare(
			'INSERT IGNORE INTO ' . self::table() . ' (import_key, source_path, path, created) VALUES (%s, %s, %s, %s)',
			[$key, $sourcePath, $path, gmdate('Y-m-d H:i:s')]
		));
		// phpcs:enable
	}
}
