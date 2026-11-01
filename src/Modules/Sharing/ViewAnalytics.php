<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\Db;

/**
 * #221: anonymous per-day view analytics for share pages. One row per
 * (UTC day, artifact, link, version, viewer hash) holds the open count;
 * reads aggregate per day and per link for the wp-admin Activity box and
 * the artifact-share-analytics MCP ability.
 *
 * Privacy (private-by-design, no third-party calls, everything local):
 * the viewer is stored ONLY as HMAC-SHA256 of the client IP under a key
 * derived per UTC day from wp_salt('auth'). The raw IP is never written;
 * the daily key rotation makes cross-day re-identification impossible by
 * construction, so a row is the only unit ever counted. There is no
 * fingerprinting beyond the IP hash, and the empty hash ('' = viewer IP
 * unknown) is never counted as a unique viewer.
 *
 * Retention: daily aggregates are kept for KEEP_DAYS days and pruned by
 * the daily `agent_brain_share_analytics_prune` cron (scheduled on
 * activation); prune() is public so the cron and tests share one path.
 */
final class ViewAnalytics {

	public const KEEP_DAYS = 90;

	/**
	 * Public so extensions can hang maintenance off the same hook name the
	 * module schedules; the payload is irrelevant to the event itself.
	 */
	public const PRUNE_HOOK = 'agent_brain_share_analytics_prune';

	public static function table(): string {
		return Db::table( 'share_views' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			artifact_id BIGINT UNSIGNED NOT NULL,
			link_id BIGINT UNSIGNED NOT NULL,
			version INT UNSIGNED NOT NULL DEFAULT 0,
			day DATE NOT NULL,
			viewer_hash CHAR(64) NOT NULL DEFAULT '',
			views INT UNSIGNED NOT NULL DEFAULT 0,
			first_viewed_at DATETIME NOT NULL,
			last_viewed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY day_scope (day, artifact_id, link_id, version, viewer_hash),
			KEY artifact_day (artifact_id, day)
		) {$charset};";
	}

	/**
	 * The daily viewer hash: HMAC-SHA256 of the IP under a key derived from
	 * wp_salt('auth') and the UTC day only. Same IP + same day => same hash
	 * (unique-viewer counting); same IP + different day, or different IP +
	 * same day => unrelated hashes. An empty IP gives '' (not a viewer).
	 */
	public static function viewerHashFor( string $ip, ?int $now = null ): string {
		$ip = trim( $ip );
		if ( $ip === '' ) {
			return '';
		}

		$day = gmdate( 'Y-m-d', $now ?? time() );

		return hash_hmac( 'sha256', $ip, self::dayKey( $day ) );
	}

	/**
	 * Record one share-page open. $viewerHash is the output of
	 * viewerHashFor() for the request's client IP; '' when the IP could not
	 * be determined (the view still counts, but not as a unique viewer).
	 * Fires agent_brain/artifact_viewed for extensions.
	 */
	public static function record( int $artifactId, int $linkId, int $version, string $viewerHash, ?int $now = null ): void {
		global $wpdb;

		if ( $artifactId <= 0 ) {
			return;
		}

		$now  = $now ?? time();
		$day  = gmdate( 'Y-m-d', $now );
		$at   = gmdate( 'Y-m-d H:i:s', $now );
		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(); values go through Db::prepare().
		$wpdb->query( Db::prepare( "INSERT INTO {$table} (artifact_id, link_id, version, day, viewer_hash, views, first_viewed_at, last_viewed_at) VALUES (%d, %d, %d, %s, %s, 1, %s, %s) ON DUPLICATE KEY UPDATE views = views + 1, last_viewed_at = VALUES(last_viewed_at)", [ $artifactId, $linkId, $version, $day, $viewerHash, $at, $at ] ) );

		do_action( 'agent_brain/artifact_viewed', $artifactId, $linkId, $version, $viewerHash );
	}

	/**
	 * Delete daily aggregates older than KEEP_DAYS (UTC), unconditionally.
	 */
	public static function prune( ?int $now = null ): int {
		global $wpdb;

		$table   = self::table();
		$cutoff  = gmdate( 'Y-m-d', ( $now ?? time() ) - self::KEEP_DAYS * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(); value goes through Db::prepare().
		$wpdb->query( Db::prepare( "DELETE FROM {$table} WHERE day < %s", [ $cutoff ] ) );

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Per-day totals for an artifact over the trailing $days days.
	 *
	 * @return array<int, array{day:string, views:int, viewers:int}>
	 */
	public static function perDayTotals( int $artifactId, int $days = 30, ?int $now = null ): array {
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d', ( $now ?? time() ) - max( 1, min( self::KEEP_DAYS, $days ) ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(); values go through Db::prepare().
		$rows = Db::getResults( "SELECT day, SUM(views) AS views, COUNT(DISTINCT NULLIF(viewer_hash, '')) AS viewers FROM {$table} WHERE artifact_id = %d AND day >= %s GROUP BY day ORDER BY day DESC", [ $artifactId, $cutoff ] );

		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'day'     => (string) $row['day'],
				'views'   => (int) $row['views'],
				'viewers' => (int) $row['viewers'],
			];
		}

		return $out;
	}

	/**
	 * Per-link totals over the trailing $days days (all links of the
	 * artifact, keyed output ordered by link id).
	 *
	 * @return array<int, array{link_id:int, views:int, viewers:int}>
	 */
	public static function perLinkTotals( int $artifactId, int $days = 30, ?int $now = null ): array {
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d', ( $now ?? time() ) - max( 1, min( self::KEEP_DAYS, $days ) ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(); values go through Db::prepare().
		$rows = Db::getResults( "SELECT link_id, SUM(views) AS views, COUNT(DISTINCT NULLIF(viewer_hash, '')) AS viewers FROM {$table} WHERE artifact_id = %d AND day >= %s GROUP BY link_id ORDER BY link_id", [ $artifactId, $cutoff ] );

		$out = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'link_id' => (int) $row['link_id'],
				'views'   => (int) $row['views'],
				'viewers' => (int) $row['viewers'],
			];
		}

		return $out;
	}

	/**
	 * Aggregate totals over the trailing $days days.
	 *
	 * @return array{views:int, viewers:int}
	 */
	public static function totals( int $artifactId, int $days = 30, ?int $now = null ): array {
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d', ( $now ?? time() ) - max( 1, min( self::KEEP_DAYS, $days ) ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(); values go through Db::prepare().
		$row = Db::getResults( "SELECT SUM(views) AS views, COUNT(DISTINCT NULLIF(viewer_hash, '')) AS viewers FROM (SELECT viewer_hash, SUM(views) AS views FROM {$table} WHERE artifact_id = %d AND day >= %s GROUP BY viewer_hash) viewers_per_day", [ $artifactId, $cutoff ] );

		return [
			'views'   => (int) ( $row[0]['views'] ?? 0 ),
			'viewers' => (int) ( $row[0]['viewers'] ?? 0 ),
		];
	}

	/**
	 * Manager-gated analytics payload for the MCP ability and (a rendered
	 * subset) the wp-admin box.
	 *
	 * @return array{artifact_id:int, days:int, retention_days:int, totals:array{views:int,viewers:int}, per_day:array<int, array{day:string,views:int,viewers:int}>, per_link:array<int, array{link_id:int,views:int,viewers:int}>}|\WP_Error
	 */
	public static function forArtifact( int $artifactId, int $actorId, int $days = 30 ) {
		if ( ! ShareLinks::canManage( $actorId, $artifactId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		$days = max( 1, min( self::KEEP_DAYS, $days ) );

		return [
			'artifact_id'     => $artifactId,
			'days'            => $days,
			'retention_days'  => self::KEEP_DAYS,
			'totals'          => self::totals( $artifactId, $days ),
			'per_day'         => self::perDayTotals( $artifactId, $days ),
			'per_link'        => self::perLinkTotals( $artifactId, $days ),
		];
	}

	private static function dayKey( string $day ): string {
		return hash_hmac( 'sha256', 'ab_share_view|' . $day, wp_salt( 'auth' ) );
	}
}
