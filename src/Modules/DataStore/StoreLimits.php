<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\Db;

/**
 * #384: the per-collection write limits of the page store rules (per key,
 * per identity, per daily IP hash). A sliding window over a log of write
 * times per bucket, so "1 per key per 90 seconds" holds exactly — a fixed
 * window would let two writes through either side of its edge. Buckets are
 * hashes; the table holds no key, identity or address in the clear.
 *
 * These come on top of StoreRate (per link and network, per minute), which
 * stays the coarse flood guard for every request.
 */
final class StoreLimits {

	public static function table(): string {
		return Db::table( 'datastore_hits' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bucket CHAR(64) NOT NULL,
			at INT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			KEY bucket_at (bucket, at),
			KEY at (at)
		) {$charset};";
	}

	/**
	 * The buckets a write counts against, from the collection's limits.
	 *
	 * @param array<string, mixed> $rules
	 * @return array<int, array{bucket:string,max:int,seconds:int}>
	 */
	public static function buckets( int $artifactId, string $collection, array $rules, string $key, string $identity, string $dailyIpHash ): array {
		$scopes = [
			'per_key'      => 'key:' . $key,
			'per_identity' => 'identity:' . $identity,
			'per_ip_day'   => 'ip:' . $dailyIpHash,
		];

		$out = [];
		foreach ( $rules['limits'] ?? [] as $scope => $limit ) {
			if ( ! isset( $scopes[ $scope ] ) || ( $scope === 'per_key' && $key === '' ) ) {
				continue;
			}
			$seconds = (int) ( $limit['seconds'] ?? 86400 );
			$out[]   = [
				'bucket'  => hash( 'sha256', $artifactId . '|' . $collection . '|' . $scopes[ $scope ] . '|' . $seconds ),
				'max'     => (int) $limit['max'],
				'seconds' => $seconds,
			];
		}

		return $out;
	}

	/**
	 * Seconds until the write may be retried, or 0 when every bucket has room.
	 *
	 * @param array<int, array{bucket:string,max:int,seconds:int}> $buckets
	 */
	public static function retryAfter( array $buckets, int $now ): int {
		global $wpdb;

		$table = self::table();
		$wait  = 0;
		foreach ( $buckets as $b ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(); values go through Db::prepare().
			$times = $wpdb->get_col( Db::prepare( "SELECT at FROM {$table} WHERE bucket = %s AND at > %d ORDER BY at DESC LIMIT %d", [ $b['bucket'], $now - $b['seconds'], $b['max'] ] ) );
			if ( count( $times ) >= $b['max'] ) {
				// The oldest of the newest max writes leaves the window first.
				$wait = max( $wait, (int) min( $times ) + $b['seconds'] - $now );
			}
		}

		return max( 0, $wait );
	}

	/**
	 * Records one write in every bucket.
	 *
	 * @param array<int, array{bucket:string,max:int,seconds:int}> $buckets
	 */
	public static function record( array $buckets, int $now ): void {
		global $wpdb;

		foreach ( $buckets as $b ) {
			$wpdb->insert( self::table(), [ 'bucket' => $b['bucket'], 'at' => $now ], [ '%s', '%d' ] );
		}

		if ( $buckets !== [] && wp_rand( 1, 50 ) === 1 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
			$wpdb->query( Db::prepare( 'DELETE FROM ' . self::table() . ' WHERE at < %d', [ $now - StoreRules::MAX_LIMIT_SECONDS ] ) );
		}
	}
}
