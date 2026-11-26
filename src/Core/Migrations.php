<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * Runs versioned per-module migrations via dbDelta, tracking applied
 * versions in a single wp_options row so re-activation and version bumps
 * are idempotent (re-running an already-applied migration is a no-op from
 * the caller's perspective; dbDelta itself is idempotent for CREATE TABLE).
 */
class Migrations {

	private const OPTION_KEY = 'agent_brain_module_versions';

	/**
	 * @param array<string, ModuleInterface> $modules id => module
	 */
	public static function runAll(array $modules): void {
		foreach ($modules as $module) {
			self::runForModule($module);
		}
	}

	/**
	 * True when any module has a migration newer than the version recorded
	 * for it. Cheap (one autoloaded option, array keys only), so boot can
	 * apply schema changes that arrive without a plugin version bump.
	 *
	 * @param array<string, ModuleInterface> $modules id => module
	 */
	public static function hasPending(array $modules): bool {
		$applied = self::getAppliedVersions();

		foreach ($modules as $module) {
			$versions = array_keys($module->migrations());
			if ($versions !== [] && max($versions) > ($applied[$module->id()] ?? 0)) {
				return true;
			}
		}

		return false;
	}

	public static function runForModule(ModuleInterface $module): void {
		$applied     = self::getAppliedVersions();
		$id          = $module->id();
		$currentVer  = $applied[$id] ?? 0;
		$migrations  = $module->migrations();

		ksort($migrations);

		foreach ($migrations as $version => $migration) {
			if ($version <= $currentVer) {
				continue;
			}

			self::apply($migration);

			$applied[$id] = $version;
			self::saveAppliedVersions($applied);
		}
	}

	/**
	 * @param callable|string $migration
	 */
	private static function apply($migration): void {
		if (is_callable($migration)) {
			$migration();

			return;
		}

		if (is_string($migration)) {
			self::dbDelta($migration);
		}
	}

	/**
	 * Add the per-agent memory-write idempotency key (memory migration 6).
	 *
	 * Idempotent and fail-loud: each step runs only when the schema still
	 * needs it, every ALTER result is checked, and the finished schema is
	 * verified, so a failure throws before the runner records the version
	 * and the next boot retries from wherever the last attempt stopped.
	 *
	 * The key column uses a binary collation: keys that differ only in case
	 * or accents are different keys.
	 *
	 * @throws \RuntimeException when an ALTER fails or the schema is not in place afterwards.
	 */
	public static function addMemoryClientRequestId(): void {
		global $wpdb;

		$table      = $wpdb->prefix . 'ab_memory';
		$definition = 'VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() handles values; the table, column and index names are fixed identifiers.
		$collation = self::memoryClientRequestIdCollation($table);
		if ($collation === null) {
			self::alterOrFail("ALTER TABLE {$table} ADD COLUMN client_request_id {$definition}");
		} elseif ($collation !== 'utf8mb4_bin') {
			self::alterOrFail("ALTER TABLE {$table} MODIFY COLUMN client_request_id {$definition}");
		}

		if (! self::memoryClientRequestIdIndexExists($table)) {
			self::alterOrFail("ALTER TABLE {$table} ADD UNIQUE KEY agent_client_request_id (agent_id, client_request_id)");
		}
		// phpcs:enable

		if (self::memoryClientRequestIdCollation($table) !== 'utf8mb4_bin' || ! self::memoryClientRequestIdIndexExists($table)) {
			throw new \RuntimeException(sprintf('Memory migration 6 did not leave %s.client_request_id with a binary collation and its unique index.', esc_html($table)));
		}
	}

	private static function alterOrFail(string $sql): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- schema statement built from fixed identifiers by the caller.
		if ($wpdb->query($sql) === false) {
			throw new \RuntimeException(sprintf('Schema change failed (%s): %s', esc_html($sql), esc_html((string) $wpdb->last_error)));
		}
	}

	/** The collation of the client_request_id column, or null when the column does not exist. */
	private static function memoryClientRequestIdCollation(string $table): ?string {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Db::prepare() is the enforcement point.
		$row = $wpdb->get_row(Db::prepare(
			'SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			[DB_NAME, $table, 'client_request_id']
		));
		// phpcs:enable

		return $row === null ? null : (string) $row->COLLATION_NAME;
	}

	private static function memoryClientRequestIdIndexExists(string $table): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Db::prepare() is the enforcement point.
		$row = $wpdb->get_row(Db::prepare(
			'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0
				LIMIT 1',
			[DB_NAME, $table, 'agent_client_request_id']
		));
		// phpcs:enable

		return $row !== null;
	}

	private static function dbDelta(string $sql): void {
		if (! function_exists('dbDelta')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta($sql);
	}

	/**
	 * @return array<string, int>
	 */
	private static function getAppliedVersions(): array {
		$stored = get_option(self::OPTION_KEY, []);

		return is_array($stored) ? $stored : [];
	}

	/**
	 * @param array<string, int> $versions
	 */
	private static function saveAppliedVersions(array $versions): void {
		update_option(self::OPTION_KEY, $versions);
	}
}
