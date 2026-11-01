<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Core\Db;

/**
 * PROV-7 (#176): erasure reaches provenance residue through the same
 * contract Artifacts\Forget and Comments\Forget use — report targets on
 * `agent_brain/forget_collect`, hard-delete on `agent_brain/forget_delete`
 * — plus one subject-independent step on `agent_brain/forget_propagate`.
 *
 *  - Store `provenance_sources` (subject erasure): outside source records
 *    whose title, locator or publisher contains the subject. A source
 *    record is a small reference (never content), but its title can name a
 *    person, so it answers to subject erasure. Deleting a record deletes
 *    its item -> source links with it.
 *
 *  - Link residue (every erasure kind): memory rows, posts, sections and
 *    artifacts erased by Forget leave their provenance links (ab_item_sources,
 *    ab_item_cites, ab_derived_from) pointing at nothing. propagate() prunes
 *    exactly the links Retention prunes (item or referenced record gone),
 *    and reports the count through `agent_brain/forget_propagation_result`
 *    so the receipt shows the store was cleaned.
 *
 * Read sets need no step of their own: a memory row's read_set column dies
 * with the row, and a section's action log dies with the section post.
 */
final class Forget {

	public const STORE_SOURCES = 'provenance_sources';
	public const STORE_LINKS   = 'provenance_links';

	/** Links pruned by the most recent propagate() call, for the receipt. */
	private static int $prunedLinks = 0;

	/**
	 * @param array<int, array{store:string,id:int|string}> $targets
	 * @return array<int, array{store:string,id:int|string}>
	 */
	public static function collect( array $targets, string $subject ): array {
		global $wpdb;

		if ( trim( $subject ) === '' ) {
			return $targets;
		}

		// One phrase, case-insensitive, in the title, locator or publisher —
		// identical semantics to the other Forget stores (PCP SPEC 8.9.1
		// item 3: erasure must never treat a full name as two words).
		$like = '%' . $wpdb->esc_like( $subject ) . '%';

		$rows = Db::getResults(
			'SELECT id FROM ' . OutsideSources::sourcesTable() . ' WHERE title LIKE %s OR locator LIKE %s OR publisher LIKE %s',
			[ $like, $like, $like ]
		);

		foreach ( $rows as $row ) {
			$targets[] = [ 'store' => self::STORE_SOURCES, 'id' => (int) $row['id'] ];
		}

		return $targets;
	}

	/**
	 * @param array<int, int|string> $ids
	 */
	public static function delete( string $store, array $ids ): void {
		if ( $store !== self::STORE_SOURCES ) {
			return;
		}

		global $wpdb;

		$sources = OutsideSources::sourcesTable();
		$links   = OutsideSources::linksTable();

		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point; table names only.
			$wpdb->query( Db::prepare( "DELETE FROM {$links} WHERE source_id IN ({$in})", $chunk ) );
			$wpdb->query( Db::prepare( "DELETE FROM {$sources} WHERE id IN ({$in})", $chunk ) );
			// phpcs:enable
		}
	}

	/**
	 * Runs after every real erasure, whatever its kind: prune the provenance
	 * links whose item or referenced record the erasure (or anything earlier)
	 * removed. Delegates to Retention so erasure and the daily sweep share
	 * one definition of a residue link.
	 */
	public static function propagate( string $subject, string $receiptId ): void {
		unset( $subject, $receiptId );

		self::$prunedLinks = Retention::pruneOrphanLinks();
	}

	/**
	 * @param array<int, mixed> $results
	 * @return array<int, mixed>
	 */
	public static function propagationResult( array $results, string $receiptId ): array {
		unset( $receiptId );

		if ( self::$prunedLinks > 0 ) {
			$results[] = [
				'store'   => self::STORE_LINKS,
				'deleted' => self::$prunedLinks,
				'success' => true,
			];
		}

		return $results;
	}
}
