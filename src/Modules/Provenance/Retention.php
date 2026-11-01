<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\Stages;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * PROV-7 (#176): retention for action-context and provenance data. The
 * design (team brain artifact 73) keeps this data "for a bounded time, not
 * forever": read sets on memory rows, section action logs and provenance
 * links age out after a retention period (default 90 days), and a curator
 * can pin individual items to keep theirs longer.
 *
 * One daily job (`agent_brain_provenance_retention`) prunes, in order:
 *
 *  - ab_memory.read_set for rows written before the cutoff (the read set
 *    is attached at write time, so the row's `created` is its age), unless
 *    the memory is pinned;
 *  - ab_action_log entries (post meta on sections) stamped before the
 *    cutoff, unless the section is pinned;
 *  - provenance link rows (ab_item_sources, ab_item_cites,
 *    ab_derived_from — each carries a `created` column since migration 5)
 *    older than the cutoff when the ITEM they belong to is not pinned, plus
 *    every link whose item or target no longer exists, whatever its age;
 *  - ab_outside_sources records older than the cutoff that nothing links
 *    to anymore (a record still linked from a live item survives; Forget
 *    removes records their owner asked to erase, with their links).
 *
 * Pins: the `ab_provenance_pin` post meta on a section (or any post-backed
 * item) keeps that item's action log and links; the
 * `agent_brain/provenance_keep` filter ($keep, $kind, $id) covers memory
 * rows and outside sources, and gives hosts the final say for posts.
 *
 * Retention is not erasure: it ages out identifiers, leaves no receipt and
 * never touches content. Every run writes one `provenance.retention` audit
 * entry (system, exported with the principal `service:retention`, like the
 * Forget module's sweep) with the per-store counts.
 */
final class Retention {

	public const HOOK         = 'agent_brain_provenance_retention';
	public const EVENT        = 'provenance.retention';
	public const OPTION_DAYS  = 'ab_action_context_retention_days';
	public const DEFAULT_DAYS = 90;
	public const MAX_DAYS     = 3650;
	public const PIN_META     = 'ab_provenance_pin';

	/** Post-backed item kinds: existence and pin checks go through posts. */
	private const POST_KINDS = [ 'section', 'artifact', 'knowledge', 'post' ];

	private const BATCH = 500;

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
	 * Days action-context and provenance data is kept: the
	 * `ab_action_context_retention_days` option (default 90), with the
	 * `agent_brain/action_context_retention_days` filter as the final say
	 * (1 to 3650 days).
	 */
	public static function days(): int {
		$stored = (int) get_option( self::OPTION_DAYS, self::DEFAULT_DAYS );
		$days   = (int) apply_filters( 'agent_brain/action_context_retention_days', $stored );

		return max( 1, min( self::MAX_DAYS, $days ) );
	}

	/**
	 * Whether one item's action context / provenance is pinned past the
	 * retention period. The pin meta covers post-backed items; the filter
	 * covers everything and wins.
	 *
	 * @param string $kind memory | section | artifact | knowledge | post | source
	 */
	public static function pinned( string $kind, int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		if ( (bool) apply_filters( 'agent_brain/provenance_keep', false, $kind, $id ) ) {
			return true;
		}

		return in_array( $kind, self::POST_KINDS, true )
			&& get_post_meta( $id, self::PIN_META, true ) === '1';
	}

	/**
	 * One sweep. $now (UTC, Y-m-d H:i:s) is for tests.
	 *
	 * @return array{read_sets:int, action_log_entries:int, links:int, sources:int}
	 */
	public static function run( ?string $now = null ): array {
		$now    = $now ?? gmdate( 'Y-m-d H:i:s' );
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( $now . ' UTC' ) - self::days() * DAY_IN_SECONDS );

		$counts = [
			'read_sets'          => self::pruneMemoryReadSets( $cutoff ),
			'action_log_entries' => self::pruneActionLogs( $cutoff ),
			'links'              => self::pruneLinks( $cutoff ),
			'sources'            => self::pruneOrphanSources( $cutoff ),
		];

		do_action( 'agent_brain/audit', [
			'actor_id'    => 0,
			'event'       => self::EVENT,
			'status'      => 'ok',
			'object_type' => 'provenance',
			'object_id'   => null,
			'meta'        => $counts + [ 'retention_days' => self::days() ],
		] );

		return $counts;
	}

	// -----------------------------------------------------------------
	// Read sets on memory rows
	// -----------------------------------------------------------------

	private static function pruneMemoryReadSets( string $cutoff ): int {
		$memory = MemoryRepository::memoryTable();
		$pruned = 0;
		$offset = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point; table name only.
			$rows = Db::getResults(
				"SELECT id FROM {$memory} WHERE read_set IS NOT NULL AND read_set <> '' AND created <= %s ORDER BY id ASC LIMIT %d OFFSET %d",
				[ $cutoff, self::BATCH, $offset ]
			);
			// phpcs:enable

			$ids = [];
			foreach ( $rows as $row ) {
				$id = (int) $row['id'];
				if ( ! self::pinned( 'memory', $id ) ) {
					$ids[] = $id;
				}
			}

			$pruned += self::clearColumnByIds( $memory, 'read_set', $ids );
			$offset += self::BATCH;
		} while ( count( $rows ) === self::BATCH );

		return $pruned;
	}

	// -----------------------------------------------------------------
	// Section action logs (post meta)
	// -----------------------------------------------------------------

	private static function pruneActionLogs( string $cutoff ): int {
		global $wpdb;

		$pruned = 0;
		$offset = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$postIds = array_map(
				'intval',
				(array) $wpdb->get_col( Db::prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY post_id LIMIT %d OFFSET %d",
					[ Cpts::META_ACTION_LOG, self::BATCH, $offset ]
				) )
			);
			// phpcs:enable

			foreach ( $postIds as $postId ) {
				if ( self::pinned( 'section', $postId ) ) {
					continue;
				}

				$pruned += self::pruneOneActionLog( $postId, $cutoff );
			}

			$offset += self::BATCH;
		} while ( count( $postIds ) === self::BATCH );

		return $pruned;
	}

	private static function pruneOneActionLog( int $postId, string $cutoff ): int {
		$log = Stages::actionLog( $postId );
		if ( $log === [] ) {
			return 0;
		}

		$kept = array_values( array_filter( $log, static function ( array $entry ) use ( $cutoff ): bool {
			// @phpstan-ignore-next-line isset.offset -- the log is decoded post meta; older entries can lack 'at'.
			$at = strtotime( (string) ( $entry['at'] ?? '' ) . ' UTC' );

			// An entry with no parseable stamp is kept: retention prunes what
			// it can date, it never guesses.
			return $at === false || gmdate( 'Y-m-d H:i:s', $at ) > $cutoff;
		} ) );

		$removed = count( $log ) - count( $kept );
		if ( $removed === 0 ) {
			return 0;
		}

		if ( $kept === [] ) {
			delete_post_meta( $postId, Cpts::META_ACTION_LOG );
		} else {
			update_post_meta( $postId, Cpts::META_ACTION_LOG, wp_slash( wp_json_encode( $kept, JSON_UNESCAPED_UNICODE ) ) );
		}

		return $removed;
	}

	// -----------------------------------------------------------------
	// Provenance links and outside sources
	// -----------------------------------------------------------------

	/**
	 * Age-based pruning: link rows (created <= cutoff) whose item is not
	 * pinned, plus every link whose item or referenced record is gone.
	 */
	private static function pruneLinks( string $cutoff ): int {
		$pruned = 0;

		foreach ( [ OutsideSources::linksTable(), ItemCites::table(), DerivedFrom::table() ] as $table ) {
			$pruned += self::pruneOldLinks( $table, $cutoff );
		}

		$pruned += self::pruneOrphanLinks();

		return $pruned;
	}

	/**
	 * Every link whose item or referenced record no longer exists — residue
	 * whatever its age. Public for the Forget cascade (Provenance\Forget):
	 * erasure and retention must never disagree about what a residue link is.
	 */
	public static function pruneOrphanLinks(): int {
		$pruned = 0;

		foreach ( [ OutsideSources::linksTable(), ItemCites::table(), DerivedFrom::table() ] as $table ) {
			$pruned += self::pruneMissingTarget( $table, 'item_kind', 'item_id' );
		}

		// Referenced-record sides: a source id / cited id pointing at a
		// deleted row is residue on its own.
		$pruned += self::pruneMissingSourceLinks();
		$pruned += self::pruneMissingTarget( ItemCites::table(), 'cited_kind', 'cited_id' );
		$pruned += self::pruneMissingTarget( DerivedFrom::table(), 'source_kind', 'source_id' );

		return $pruned;
	}

	/**
	 * DELETE link rows whose ($kindCol, $idCol) identifier no longer
	 * resolves: 'memory' against ab_memory, every other kind against posts.
	 */
	private static function pruneMissingTarget( string $table, string $kindCol, string $idCol ): int {
		global $wpdb;

		$memory = MemoryRepository::memoryTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static identifiers only; table names come from the plugin's own table helpers.
		$wpdb->query( "DELETE l FROM {$table} l
			LEFT JOIN {$memory} m ON m.id = l.{$idCol} AND l.{$kindCol} = 'memory'
			LEFT JOIN {$wpdb->posts} p ON p.ID = l.{$idCol} AND l.{$kindCol} <> 'memory'
			WHERE ( l.{$kindCol} = 'memory' AND m.id IS NULL )
			   OR ( l.{$kindCol} <> 'memory' AND p.ID IS NULL )" );
		// phpcs:enable

		return (int) $wpdb->rows_affected;
	}

	/** ab_item_sources rows whose source record is gone. */
	private static function pruneMissingSourceLinks(): int {
		global $wpdb;

		$links   = OutsideSources::linksTable();
		$sources = OutsideSources::sourcesTable();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static identifiers only; table names come from the plugin's own table helpers.
		$wpdb->query( "DELETE FROM {$links} WHERE source_id NOT IN (SELECT id FROM {$sources})" );

		return (int) $wpdb->rows_affected;
	}

	/**
	 * DELETE link rows older than the cutoff whose item is not pinned.
	 * Distinct items are checked in PHP so the pin filter and meta decide.
	 */
	private static function pruneOldLinks( string $table, string $cutoff ): int {
		global $wpdb;

		$pruned = 0;
		$offset = 0;

		do {
			$rows = Db::getResults(
				"SELECT item_kind, item_id FROM {$table} WHERE created <= %s GROUP BY item_kind, item_id LIMIT %d OFFSET %d",
				[ $cutoff, self::BATCH, $offset ]
			);

			foreach ( $rows as $row ) {
				$kind = (string) $row['item_kind'];
				$id   = (int) $row['item_id'];

				if ( self::pinned( $kind, $id ) ) {
					continue;
				}

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
				$wpdb->query( Db::prepare(
					"DELETE FROM {$table} WHERE created <= %s AND item_kind = %s AND item_id = %d",
					[ $cutoff, $kind, $id ]
				) );
				// phpcs:enable

				$pruned += (int) $wpdb->rows_affected;
			}

			$offset += self::BATCH;
		} while ( count( $rows ) === self::BATCH );

		return $pruned;
	}

	/**
	 * Outside source records past the cutoff that nothing links to anymore.
	 * A record still cited by a live item is that item's provenance and
	 * stays until the item (or its link) ages out or the item is forgotten.
	 */
	private static function pruneOrphanSources( string $cutoff ): int {
		$sources = OutsideSources::sourcesTable();
		$links   = OutsideSources::linksTable();
		$pruned  = 0;
		$offset  = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point; table names only.
			$rows = Db::getResults(
				"SELECT id FROM {$sources} WHERE created <= %s AND id NOT IN (SELECT source_id FROM {$links}) ORDER BY id ASC LIMIT %d OFFSET %d",
				[ $cutoff, self::BATCH, $offset ]
			);
			// phpcs:enable

			$ids = [];
			foreach ( $rows as $row ) {
				$id = (int) $row['id'];
				if ( ! self::pinned( 'source', $id ) ) {
					$ids[] = $id;
				}
			}

			$pruned += self::deleteRowsByIds( $sources, $ids );
			$offset += self::BATCH;
		} while ( count( $rows ) === self::BATCH );

		return $pruned;
	}

	// -----------------------------------------------------------------
	// Shared helpers
	// -----------------------------------------------------------------

	/**
	 * SET $column = NULL for a batch of ids (empty $ids is a no-op).
	 *
	 * @param int[] $ids
	 */
	private static function clearColumnByIds( string $table, string $column, array $ids ): int {
		return self::affectRowsByIds( $table, "SET {$column} = NULL", $ids );
	}

	/**
	 * DELETE a batch of rows by id (empty $ids is a no-op).
	 *
	 * @param int[] $ids
	 */
	private static function deleteRowsByIds( string $table, array $ids ): int {
		return self::affectRowsByIds( $table, '', $ids, true );
	}

	/**
	 * @param int[] $ids
	 */
	private static function affectRowsByIds( string $table, string $set, array $ids, bool $delete = false ): int {
		global $wpdb;

		if ( $ids === [] ) {
			return 0;
		}

		$verb = $delete ? 'DELETE FROM' : 'UPDATE';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point; static verb and set clause.
		$wpdb->query( Db::prepare(
			"{$verb} {$table} {$set} WHERE id IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
			$ids
		) );
		// phpcs:enable

		return (int) $wpdb->rows_affected;
	}
}
