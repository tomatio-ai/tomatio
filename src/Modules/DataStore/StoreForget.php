<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Forget\Matcher;

/**
 * ART-23 (#163): wires the data store into Forget (PCP v0.3 SPEC 8.9.1
 * item 3). A store document is content: when a subject erasure matches its
 * value (as typed or as JSON stores it), the whole document goes — the
 * attribution columns hold only ids and display snapshots, and a display
 * snapshot that names the subject is cleared by the audit-meta erasure
 * pattern elsewhere; here the value is the record, so the row is the target.
 *
 * agent_brain/forget_collect: rows whose value JSON-matches the subject.
 * agent_brain/forget_delete: deletes the collected rows.
 */
final class StoreForget {

	public const STORE         = 'ab_datastore';
	public const STORE_HISTORY = 'ab_datastore_history';

	/**
	 * agent_brain/forget_collect: store documents whose value mentions the
	 * subject.
	 *
	 * @param mixed $collected
	 * @return mixed
	 */
	public static function collect( $collected, string $subject ) {
		if ( ! is_array( $collected ) || trim( $subject ) === '' ) {
			return $collected;
		}

		$table = StoreData::table();
		$likes = Matcher::jsonLikes( $subject );
		$or    = implode( ' OR ', array_fill( 0, count( $likes ), 'value LIKE %s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT id FROM {$table} WHERE {$or}",
			$likes
		);
		// phpcs:enable

		foreach ( $rows as $row ) {
			$collected[] = [ 'store' => self::STORE, 'id' => (int) $row['id'] ];
		}

		// #384: the history keeps earlier values (and operation arguments)
		// of documents; a row that mentions the subject goes too.
		$history = StoreHistory::table();
		$hor     = implode( ' OR ', array_fill( 0, count( $likes ), '(value LIKE %s OR delta LIKE %s)' ) );
		$args    = [];
		foreach ( $likes as $like ) {
			array_push( $args, $like, $like );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults( "SELECT id FROM {$history} WHERE {$hor}", $args );
		// phpcs:enable

		foreach ( $rows as $row ) {
			$collected[] = [ 'store' => self::STORE_HISTORY, 'id' => (int) $row['id'] ];
		}

		return $collected;
	}

	/**
	 * agent_brain/forget_delete: deletes the collected rows.
	 *
	 * @param mixed $ids
	 */
	public static function delete( string $store, $ids ): void {
		if ( ! in_array( $store, [ self::STORE, self::STORE_HISTORY ], true ) || ! is_array( $ids ) ) {
			return;
		}

		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) );
		if ( $ids === [] ) {
			return;
		}

		global $wpdb;

		$table = $store === self::STORE ? StoreData::table() : StoreHistory::table();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$wpdb->query( Db::prepare( "DELETE FROM {$table} WHERE id IN ({$in})", $chunk ) );
			// phpcs:enable
		}
	}
}
