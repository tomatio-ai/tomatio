<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\Db;

/**
 * Fixed-window (one minute) request limiter for the share render route,
 * keyed by (ip hash, link id) in its own table. Unknown tokens use link id 0
 * so scanning a host is throttled per IP too.
 */
final class RateLimiter {

	public const PER_MINUTE = 60;

	public static function table(): string {
		return Db::table( 'share_rate' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			ip_hash CHAR(64) NOT NULL,
			link_id BIGINT UNSIGNED NOT NULL,
			window_start INT UNSIGNED NOT NULL,
			hits INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (ip_hash, link_id, window_start)
		) {$charset};";
	}

	/**
	 * Records one request and returns true while within the limit.
	 */
	public static function allow( string $ipHash, int $linkId, ?int $now = null, int $limit = self::PER_MINUTE ): bool {
		global $wpdb;

		$now    = $now ?? time();
		$window = $now - ( $now % 60 );
		$table  = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(); values go through Db::prepare().
		$wpdb->query( Db::prepare( "INSERT INTO {$table} (ip_hash, link_id, window_start, hits) VALUES (%s, %d, %d, 1) ON DUPLICATE KEY UPDATE hits = hits + 1", [ $ipHash, $linkId, $window ] ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
		$hits = (int) $wpdb->get_var( Db::prepare( "SELECT hits FROM {$table} WHERE ip_hash = %s AND link_id = %d AND window_start = %d", [ $ipHash, $linkId, $window ] ) );

		if ( wp_rand( 1, 50 ) === 1 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
			$wpdb->query( Db::prepare( "DELETE FROM {$table} WHERE window_start < %d", [ $window - 3600 ] ) );
		}

		return $hits <= $limit;
	}
}
