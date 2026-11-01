<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\Db;

/**
 * ART-23 (#163): fixed-window (one minute) request limiter for the store
 * API, keyed by (ip hash, link id, kind). A dedicated table so store traffic
 * never consumes the share render route's budget (and vice versa): reads
 * and writes get separate, tighter limits.
 */
final class StoreRate {

	public const READ_PER_MINUTE  = 120;
	public const WRITE_PER_MINUTE = 30;

	public const KIND_READ  = 'read';
	public const KIND_WRITE = 'write';

	public static function table(): string {
		return Db::table( 'datastore_rate' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			ip_hash CHAR(64) NOT NULL,
			link_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(8) NOT NULL,
			window_start INT UNSIGNED NOT NULL,
			hits INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (ip_hash, link_id, kind, window_start)
		) {$charset};";
	}

	/**
	 * Records one request and returns true while within the limit for $kind.
	 */
	public static function allow( string $ipHash, int $linkId, string $kind, ?int $now = null ): bool {
		global $wpdb;

		$now    = $now ?? time();
		$window = $now - ( $now % 60 );
		$limit  = $kind === self::KIND_WRITE ? self::WRITE_PER_MINUTE : self::READ_PER_MINUTE;
		$table  = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(); values go through Db::prepare().
		$wpdb->query( Db::prepare( "INSERT INTO {$table} (ip_hash, link_id, kind, window_start, hits) VALUES (%s, %d, %s, %d, 1) ON DUPLICATE KEY UPDATE hits = hits + 1", [ $ipHash, $linkId, $kind, $window ] ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
		$hits = (int) $wpdb->get_var( Db::prepare( "SELECT hits FROM {$table} WHERE ip_hash = %s AND link_id = %d AND kind = %s AND window_start = %d", [ $ipHash, $linkId, $kind, $window ] ) );

		if ( wp_rand( 1, 50 ) === 1 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
			$wpdb->query( Db::prepare( "DELETE FROM {$table} WHERE window_start < %d", [ $window - 3600 ] ) );
		}

		return $hits <= $limit;
	}
}
