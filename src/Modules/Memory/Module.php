<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Memory;

use AgentBrain\Core\Db;
use AgentBrain\Core\ModuleInterface;

/**
 * Memory module (MEM-1..MEM-5): raw agent memory table + FULLTEXT search,
 * provenance links for cascade deletion, hourly working->episode rollover,
 * and the private ab_knowledge / ab_procedure post types.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'memory';
	}

	public function register(): void {
		add_action( 'init', [ Cpts::class, 'register' ], 20 );
		add_action( Rollover::CRON_HOOK, [ Rollover::class, 'run' ] );
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			1 => static function (): void {
				global $wpdb;

				$table          = MemoryRepository::memoryTable();
				$charsetCollate = $wpdb->get_charset_collate();

				$sql = "CREATE TABLE {$table} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					agent_id BIGINT UNSIGNED NOT NULL,
					scope_id BIGINT UNSIGNED NOT NULL,
					type VARCHAR(16) NOT NULL,
					content LONGTEXT NOT NULL,
					search_keys TEXT NULL,
					importance TINYINT UNSIGNED NOT NULL DEFAULT 50,
					source_ids LONGTEXT NULL,
					status VARCHAR(16) NOT NULL DEFAULT 'active',
					agent_written TINYINT(1) NOT NULL DEFAULT 1,
					created DATETIME NOT NULL,
					expires DATETIME NULL,
					PRIMARY KEY  (id),
					KEY agent_scope_type_status (agent_id, scope_id, type, status),
					KEY expires (expires),
					FULLTEXT KEY content_search (content, search_keys)
				) ENGINE=InnoDB {$charsetCollate};";

				self::dbDelta( $sql );
			},
			2 => static function (): void {
				global $wpdb;

				$table          = MemoryRepository::linksTable();
				$charsetCollate = $wpdb->get_charset_collate();

				$sql = "CREATE TABLE {$table} (
					derived_kind VARCHAR(8) NOT NULL,
					derived_id BIGINT UNSIGNED NOT NULL,
					source_id BIGINT UNSIGNED NOT NULL,
					PRIMARY KEY  (derived_kind, derived_id, source_id),
					KEY source_id (source_id)
				) ENGINE=InnoDB {$charsetCollate};";

				self::dbDelta( $sql );
			},
			3 => static function (): void {
				global $wpdb;

				$table = MemoryRepository::memoryTable();

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
				$existing = $wpdb->get_row( Db::prepare(
					'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
						WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					[ DB_NAME, $table, 'client' ]
				) );
				// phpcs:enable

				if ( $existing !== null ) {
					return; // Idempotent: re-running an already-applied migration is a no-op.
				}

				// P1 (the design contracts "Fix wave — mixed
				// Kimi + Claude team test", Unit P): provenance column for
				// which MCP client wrote a memory row. Nullable — system-
				// written rows (compression, outcome, assembly) leave it
				// null, see MemoryRepository::insert().
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier only, no user input.
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN client VARCHAR(64) NULL AFTER agent_written" );
			},
			4 => static function (): void {
				global $wpdb;

				$table = MemoryRepository::memoryTable();

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
				$existing = $wpdb->get_row( Db::prepare(
					'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
						WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					[ DB_NAME, $table, 'via' ]
				) );
				// phpcs:enable

				if ( $existing !== null ) {
					return; // Idempotent.
				}

				// The person an agent was acting for when it wrote the row
				// (Core\AuthorizedBy, set from a delegated OAuth token).
				// Nullable: rows written by a person, by an application
				// password, or by the system leave it null.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier only, no user input.
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN via BIGINT UNSIGNED NULL AFTER client" );
			},
			5 => static function (): void {
				global $wpdb;

				$table = MemoryRepository::memoryTable();

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
				$existing = $wpdb->get_row( Db::prepare(
					'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
						WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					[ DB_NAME, $table, 'read_set' ]
				) );
				// phpcs:enable

				if ( $existing !== null ) {
					return; // Idempotent.
				}

				// PROV-6 (#175): action-context read set attached to the row
				// by the MCP write that created it — a JSON array of
				// {kind, id, version?} identifiers the writing session had
				// read since its last write (Core\ActionContext). Identifiers
				// only, never content. Nullable: rows written outside an MCP
				// session, with the switch off, or before this column leave
				// it null.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier only, no user input.
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN read_set LONGTEXT NULL AFTER via" );
			},
		];
	}

	public function activate(): void {
		if ( ! wp_next_scheduled( Rollover::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', Rollover::CRON_HOOK );
		}
	}

	public function deactivate(): void {
		$timestamp = wp_next_scheduled( Rollover::CRON_HOOK );
		if ( $timestamp !== false ) {
			wp_unschedule_event( $timestamp, Rollover::CRON_HOOK );
		}
	}

	private static function dbDelta( string $sql ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
	}
}
