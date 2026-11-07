<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Forget;

use AgentBrain\Core\ModuleInterface;

/**
 * Forget module (FGT-1, FGT-2, FGT-3, PCP v0.3 SPEC 8.9 and 8.10): provable
 * erasure by subject, pages or principal, with keyed receipts and
 * tombstones, the management routes for it (ErasureRest), and the daily
 * purge of soft-deleted memory rows (Retention). Exports are PCP archives
 * now (Portability: Exports, ExportStore).
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'forget';
	}

	public function register(): void {
		AdminPage::register();
		Retention::register();
		add_action( 'rest_api_init', [ ErasureRest::class, 'registerRoutes' ] );
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			1 => function (): void {
				global $wpdb;

				$table          = ReceiptRepository::tableName();
				$charsetCollate = $wpdb->get_charset_collate();

				$sql = "CREATE TABLE {$table} (
					id VARCHAR(36) NOT NULL,
					subject_hash CHAR(64) NOT NULL,
					created DATETIME NOT NULL,
					curator_id BIGINT UNSIGNED NOT NULL,
					counts LONGTEXT NULL,
					residue LONGTEXT NULL,
					status VARCHAR(16) NOT NULL,
					PRIMARY KEY  (id),
					KEY created (created),
					KEY subject_hash (subject_hash)
				) {$charsetCollate};";

				if ( ! function_exists( 'dbDelta' ) ) {
					require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				}

				dbDelta( $sql );
			},
			// PCP erasure alignment: receipt kinds, keyed digests and both
			// times; tombstones; the retention marks and the daily sweep.
			// Decision 5: the legacy unkeyed hashes go (receipts'
			// subject_hash, the forget audit rows' args_hash), so subjects
			// forgotten before this can no longer be confirmed.
			2 => static function (): void {
				self::upgradeReceipts();
				self::dbDelta( Tombstones::createTableSql() );
				self::dbDelta( Retention::createTableSql() );
				self::clearLegacyAuditHashes();
				Retention::schedule();
			},
			// Every args_hash written before keyed digests is a plain SHA-256
			// of a tool call's arguments, which a guess can reverse for short
			// ones (a search for a name). Keyed hashes start after this runs.
			3 => static function (): void {
				self::clearPlainArgsHashes();
			},
		];
	}

	public function activate(): void {
		Retention::schedule();
	}

	public function deactivate(): void {
		// The retired nightly export hook is cleared by the Portability migrations.
		Retention::unschedule();
	}

	/**
	 * New receipt columns, then the unkeyed subject_hash dropped.
	 */
	public static function upgradeReceipts(): void {
		global $wpdb;

		$table = ReceiptRepository::tableName();

		self::dbDelta( "CREATE TABLE {$table} (
			id VARCHAR(36) NOT NULL,
			kind VARCHAR(16) NOT NULL DEFAULT 'subject',
			subject_digest CHAR(64) NULL,
			digest_key_id VARCHAR(32) NULL,
			include_derived TINYINT(1) NOT NULL DEFAULT 1,
			requested_at DATETIME NULL,
			created DATETIME NOT NULL,
			curator_id BIGINT UNSIGNED NOT NULL,
			counts LONGTEXT NULL,
			residue LONGTEXT NULL,
			status VARCHAR(24) NOT NULL,
			PRIMARY KEY  (id),
			KEY created (created),
			KEY subject_digest (subject_digest)
		) {$wpdb->get_charset_collate()};" );

		self::dropLegacyHash( $table );
	}

	/**
	 * Decision 5: drops receipts' unkeyed subject_hash (sha256 of the
	 * subject) and fills requested_at for older receipts. Idempotent.
	 *
	 * @return bool Whether a subject_hash column was dropped.
	 */
	public static function dropLegacyHash( string $table ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name only.
		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( in_array( 'requested_at', $columns, true ) ) {
			$wpdb->query( "UPDATE {$table} SET requested_at = created WHERE requested_at IS NULL" );
		}

		if ( ! in_array( 'subject_hash', $columns, true ) ) {
			return false;
		}

		$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Key_name = 'subject_hash'", 2 );
		if ( $indexes !== [] ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX subject_hash" );
		}
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN subject_hash" );
		// phpcs:enable

		return true;
	}

	/**
	 * Decision 5: the forget audit rows' args_hash was sha256(subject).
	 * $table is for tests; by default the audit table, when it exists.
	 *
	 * @return int Rows cleared.
	 */
	public static function clearLegacyAuditHashes( ?string $table = null ): int {
		global $wpdb;

		$table = $table ?? self::auditTable();
		if ( $table === null ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query( \AgentBrain\Core\Db::prepare(
			"UPDATE {$table} SET args_hash = NULL WHERE event IN (%s, %s) AND args_hash IS NOT NULL",
			Forgetter::LEGACY_EVENTS
		) );
		// phpcs:enable

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Clears every args_hash in the audit log: the plain SHA-256 that
	 * AbilityListener wrote before its hashes were keyed (DigestKey).
	 * Run once, when keyed hashes start. $table is for tests.
	 *
	 * @return int Rows cleared.
	 */
	public static function clearPlainArgsHashes( ?string $table = null ): int {
		global $wpdb;

		$table = $table ?? self::auditTable();
		if ( $table === null ) {
			return 0;
		}

		$wpdb->query( "UPDATE {$table} SET args_hash = NULL WHERE args_hash IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.

		return (int) $wpdb->rows_affected;
	}

	/** The audit table, or null before the Audit module has created it. */
	private static function auditTable(): ?string {
		global $wpdb;

		$table  = \AgentBrain\Modules\Audit\Repository::tableName();
		$exists = $wpdb->get_var( \AgentBrain\Core\Db::prepare( 'SHOW TABLES LIKE %s', [ $wpdb->esc_like( $table ) ] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Db::prepare() is the enforcement point.

		return $exists === $table ? $table : null;
	}

	private static function dbDelta( string $sql ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
	}
}
