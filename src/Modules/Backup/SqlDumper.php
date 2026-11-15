<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

/**
 * Dumps every `{prefix}ab_*` table to a plain-SQL file via $wpdb —
 * `SHOW CREATE TABLE` for schema, batched SELECT -> INSERT for data. Never
 * shells out (no mysqldump). All values are escaped through
 * `$wpdb->prepare()`; batches are streamed to disk so memory stays bounded
 * regardless of table size.
 */
final class SqlDumper {

	public const BATCH_ROWS = 500;

	/**
	 * @return array<int, string> table names dumped (without prefix stripped)
	 */
	public static function dumpTables(string $outputFile): array {
		$out = fopen($outputFile, 'wb');
		if ($out === false) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to open SQL dump output: {$outputFile}");
		}

		$tables = self::listBackupTables();

		self::writeHeader($out);

		foreach ($tables as $table) {
			self::dumpTableSchema($out, $table);

			$offset = 0;
			while (true) {
				$written = self::dumpTableBatch($out, $table, $offset, self::BATCH_ROWS);
				if ($written === 0) {
					break;
				}
				$offset += $written;
				if ($written < self::BATCH_ROWS) {
					break;
				}
			}

			fwrite($out, "\n");
		}

		self::writeFooter($out);
		fclose($out);

		return $tables;
	}

	/**
	 * @param resource $out
	 */
	public static function writeHeader($out): void {
		fwrite($out, "-- TomaTío SQL snapshot generated " . gmdate('c') . "\n");
		fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n\n");
	}

	/**
	 * @param resource $out
	 */
	public static function writeFooter($out): void {
		fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
	}

	/**
	 * @return array<int, string>
	 */
	public static function listBackupTables(): array {
		global $wpdb;

		$pattern = $wpdb->esc_like($wpdb->prefix . 'ab_') . '%';
		// Table name patterns cannot be bound as query params; %s here is a
		// LIKE pattern already escaped via $wpdb->esc_like() above.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare('SHOW TABLES LIKE %s', $pattern);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col($sql);

		return array_values(array_map('strval', $rows));
	}

	/**
	 * Writes DROP TABLE + CREATE TABLE for $table. Called exactly once per
	 * table, before any dumpTableBatch() call for that table.
	 *
	 * @param resource $out
	 */
	public static function dumpTableSchema($out, string $table): void {
		global $wpdb;

		$escapedTable = self::backtick($table);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifier, not user input; no bind param possible.
		$createRow = $wpdb->get_row("SHOW CREATE TABLE {$escapedTable}", ARRAY_A);
		$createSql = is_array($createRow) ? (string) ($createRow['Create Table'] ?? '') : '';

		fwrite($out, "DROP TABLE IF EXISTS {$escapedTable};\n");
		fwrite($out, $createSql . ";\n\n");
	}

	/**
	 * Dumps a single id-range batch of $table's rows (LIMIT $batchRows
	 * OFFSET $offset) as one INSERT statement. Resumable: repeated calls
	 * with increasing $offset (in exact BATCH_ROWS steps) produce byte-for-
	 * byte the same output as one continuous dump — this is the unit both
	 * the single-shot SqlDumper::dumpTables() and the resumable
	 * BackupStateMachine dump phase call. Returns the number of rows
	 * written (0 = table is exhausted, nothing left to dump).
	 *
	 * @param resource $out
	 */
	public static function dumpTableBatch($out, string $table, int $offset, int $batchRows = self::BATCH_ROWS): int {
		global $wpdb;

		$escapedTable = self::backtick($table);
		$columns      = self::columnNames($table);

		if ($columns === []) {
			return 0;
		}

		$columnList = implode(', ', array_map([self::class, 'backtick'], $columns));

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifier only; LIMIT/OFFSET are bound via prepare().
		$sql = $wpdb->prepare("SELECT * FROM {$escapedTable} LIMIT %d OFFSET %d", $batchRows, $offset);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results($sql, ARRAY_A);

		if (! is_array($rows) || $rows === []) {
			return 0;
		}

		$valueRows = [];
		foreach ($rows as $row) {
			$values = [];
			foreach ($columns as $column) {
				$values[] = self::formatValue($row[$column] ?? null);
			}
			$valueRows[] = '(' . implode(', ', $values) . ')';
		}

		fwrite(
			$out,
			"INSERT INTO {$escapedTable} ({$columnList}) VALUES\n" . implode(",\n", $valueRows) . ";\n"
		);

		return count($rows);
	}

	/**
	 * @return array<int, string>
	 */
	private static function columnNames(string $table): array {
		global $wpdb;

		$escapedTable = self::backtick($table);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table identifier only.
		$rows = $wpdb->get_results("SHOW COLUMNS FROM {$escapedTable}", ARRAY_A);

		if (! is_array($rows)) {
			return [];
		}

		return array_map(static fn (array $row): string => (string) $row['Field'], $rows);
	}

	private static function formatValue(mixed $value): string {
		global $wpdb;

		if ($value === null) {
			return 'NULL';
		}

		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}

		// $wpdb->prepare('%s', ...) returns the value already quoted and
		// escaped — the one sanctioned path for value literals in a
		// hand-built statement (contract: "$wpdb->prepare/esc_sql").
		return $wpdb->prepare('%s', (string) $value);
	}

	private static function backtick(string $identifier): string {
		return '`' . str_replace('`', '``', $identifier) . '`';
	}
}
