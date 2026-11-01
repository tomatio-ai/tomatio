<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Forget;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Portability\PageIndex;

/**
 * Decision 11 and PCP v0.3 SPEC 8.10 item 3: memory rows with status
 * `deleted` are kept for undo, then purged 30 days after they were deleted,
 * like the WordPress trash.
 *
 * Rows carry no deletion time, so a daily job (`agent_brain_retention_sweep`)
 * notes in `{prefix}ab_retention_marks` when it first sees each deleted row,
 * forgets the marks of rows that are gone or no longer deleted, and purges
 * the rows whose mark is older than the interval. A row is therefore purged
 * between 30 and 31 days after it was deleted. Rows already deleted when
 * this version arrived are counted from its first run.
 *
 * Retention is not erasure: it removes the row and its provenance links,
 * not what was derived from it, and leaves no receipt. Every run writes one
 * `retention.sweep` audit entry (system, exported with the principal
 * `service:retention`) with the number of rows deleted.
 */
final class Retention {

	public const HOOK         = 'agent_brain_retention_sweep';
	public const EVENT        = 'retention.sweep';
	public const PRINCIPAL    = 'service:retention';
	public const DEFAULT_DAYS = 30;

	private const BATCH = 500;

	public static function tableName(): string {
		global $wpdb;

		return $wpdb->prefix . 'ab_retention_marks';
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table = self::tableName();

		return "CREATE TABLE {$table} (
			memory_id BIGINT UNSIGNED NOT NULL,
			seen_at DATETIME NOT NULL,
			PRIMARY KEY  (memory_id),
			KEY seen_at (seen_at)
		) {$wpdb->get_charset_collate()};";
	}

	public static function register(): void {
		add_action( self::HOOK, static function (): void {
			self::run();
		} );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Days a deleted row is kept for undo: 30, or what the
	 * `agent_brain/deleted_retention_days` filter returns (1 to 365).
	 */
	public static function days(): int {
		$days = (int) apply_filters( 'agent_brain/deleted_retention_days', self::DEFAULT_DAYS );

		return max( 1, min( 365, $days ) );
	}

	/**
	 * One sweep. $now (UTC, Y-m-d H:i:s) is for tests.
	 *
	 * @return int Rows purged.
	 */
	public static function run( ?string $now = null ): int {
		global $wpdb;

		$now    = $now ?? gmdate( 'Y-m-d H:i:s' );
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( $now . ' UTC' ) - self::days() * DAY_IN_SECONDS );
		$marks  = self::tableName();
		$memory = MemoryRepository::memoryTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point; table names only.
		$wpdb->query( Db::prepare(
			"INSERT IGNORE INTO {$marks} (memory_id, seen_at) SELECT id, %s FROM {$memory} WHERE status = %s",
			[ $now, 'deleted' ]
		) );
		$wpdb->query( Db::prepare(
			"DELETE k FROM {$marks} k LEFT JOIN {$memory} m ON m.id = k.memory_id WHERE m.id IS NULL OR m.status <> %s",
			[ 'deleted' ]
		) );
		// phpcs:enable

		$purged = 0;

		do {
			$rows = Db::getResults(
				"SELECT k.memory_id FROM {$marks} k INNER JOIN {$memory} m ON m.id = k.memory_id
					WHERE k.seen_at <= %s AND m.status = %s
					ORDER BY k.memory_id ASC
					LIMIT %d",
				[ $cutoff, 'deleted', self::BATCH ]
			);
			$ids = array_map( static fn ( array $row ): int => (int) $row['memory_id'], $rows );

			if ( $ids !== [] ) {
				$purged += self::purge( $ids );
			}
		} while ( count( $ids ) === self::BATCH );

		do_action( 'agent_brain/audit', [
			'actor_id'    => 0,
			'event'       => self::EVENT,
			'status'      => 'ok',
			'object_type' => 'ab_memory',
			'object_id'   => null,
			'meta'        => [ 'deleted' => $purged, 'retention_days' => self::days() ],
		] );

		return $purged;
	}

	/**
	 * @param int[] $ids
	 */
	private static function purge( array $ids ): int {
		global $wpdb;

		$in     = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$memory = MemoryRepository::memoryTable();
		$links  = MemoryRepository::linksTable();
		$marks  = self::tableName();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query( Db::prepare(
			"DELETE FROM {$links} WHERE ( derived_kind = %s AND derived_id IN ({$in}) ) OR source_id IN ({$in})",
			array_merge( [ 'memory' ], $ids, $ids )
		) );
		$wpdb->query( Db::prepare( "DELETE FROM {$memory} WHERE status = %s AND id IN ({$in})", array_merge( [ 'deleted' ], $ids ) ) );
		$purged = (int) $wpdb->rows_affected;
		$wpdb->query( Db::prepare( "DELETE FROM {$marks} WHERE memory_id IN ({$in})", $ids ) );
		// phpcs:enable

		PageIndex::removeMemory( $ids );

		return $purged;
	}
}
