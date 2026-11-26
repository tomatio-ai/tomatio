<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentInbox;

use AgentBrain\Core\Db;
use AgentBrain\Modules\AgentId\TaskTakers;
use WP_Error;

/**
 * #1060: a durable inbox per agent that takes tasks, so an always-on agent
 * (such as Obi-Wan, a Hermes agent) wakes on events instead of polling.
 *
 * One row per event per agent. A writer calls push(): one INSERT IGNORE,
 * and the unique (agent, source, source_id) key drops a redelivery. The
 * row holds a short summary and a pointer (`ref`: "t:<task>@<artifact>",
 * a URL, a PR number), never a copy of the content: the agent reads the
 * thing itself in its run.
 *
 * The agent takes every pending, available event as one batch with
 * claim(), under a lease (default 15 minutes). An agent has at most one
 * live claim: a second claim while one is live gets nothing and the lease
 * end. A lease that expires puts its events back to pending and counts an
 * attempt; at MAX_ATTEMPTS an event is failed. done() closes events of a
 * claim as done or failed, with a note. Old done and failed rows are
 * pruned after RETENTION_DAYS (Cron).
 *
 * Times are UTC DATETIME computed in PHP (no NOW()/UTC_TIMESTAMP(), which
 * the SQLite drop-in does not translate), and no named locks are used: the
 * claim is a conditional UPDATE on pending rows, so two racing claims never
 * take the same event.
 */
final class Inbox {

	public const STATUS_PENDING = 'pending';
	public const STATUS_WORKING = 'working';
	public const STATUS_DONE    = 'done';
	public const STATUS_FAILED  = 'failed';

	public const DEFAULT_LEASE_MINUTES = 15;
	public const MAX_LEASE_MINUTES     = 120;
	public const MAX_ATTEMPTS          = 5;

	/** Events one claim takes at most; the rest stay pending for the next. */
	public const MAX_BATCH = 100;

	/** What peek() shows of the next events. */
	public const PEEK = 5;

	public const RETENTION_DAYS = 30;

	public const MAX_SUMMARY   = 200;
	public const MAX_REF       = 255;
	public const MAX_SOURCE_ID = 128;
	public const MAX_NOTE      = 255;

	private const SOURCE_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';
	private const TYPE_PATTERN   = '/^[a-z][a-z0-9_.-]{0,47}$/';

	public static function table(): string {
		return Db::table( 'agent_inbox' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agent_user_id BIGINT(20) UNSIGNED NOT NULL,
			source VARCHAR(32) NOT NULL,
			source_id VARCHAR(128) NOT NULL,
			type VARCHAR(48) NOT NULL,
			summary VARCHAR(255) NOT NULL,
			ref VARCHAR(255) NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			attempts SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			available_at DATETIME NOT NULL,
			claimed_at DATETIME NULL,
			lease_until DATETIME NULL,
			claim_id VARCHAR(40) NULL,
			note VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY agent_event (agent_user_id, source, source_id),
			KEY agent_queue (agent_user_id, status, available_at),
			KEY claim (claim_id),
			KEY finished (status, finished_at)
		) {$charset};";
	}

	/**
	 * Add an event to an agent's inbox: fast and idempotent. The id of the
	 * new row, or 0 when it was dropped (a redelivery, an account that is
	 * not an agent that takes tasks, or a malformed source or type).
	 *
	 * @param int|null $now Unix time (tests); default time().
	 */
	public static function push( int $agentId, string $source, string $sourceId, string $type, string $summary, string $ref = '', ?int $now = null ): int {
		global $wpdb;

		if ( $agentId <= 0 || preg_match( self::SOURCE_PATTERN, $source ) !== 1 || preg_match( self::TYPE_PATTERN, $type ) !== 1 ) {
			return 0;
		}
		$sourceId = trim( $sourceId );
		if ( $sourceId === '' ) {
			return 0;
		}
		if ( strlen( $sourceId ) > self::MAX_SOURCE_ID ) {
			// Still unique per event, still the same for a redelivery.
			$sourceId = substr( $sourceId, 0, self::MAX_SOURCE_ID - 41 ) . '#' . sha1( $sourceId );
		}
		if ( ! TaskTakers::takes( $agentId ) ) {
			return 0;
		}

		$at = self::sqlTime( $now ?? time() );
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$inserted = $wpdb->query(
			Db::prepare(
				"INSERT IGNORE INTO {$table} (agent_user_id, source, source_id, type, summary, ref, status, attempts, available_at, created_at) VALUES (%d, %s, %s, %s, %s, %s, %s, 0, %s, %s)",
				[ $agentId, $source, $sourceId, $type, self::line( $summary, self::MAX_SUMMARY ), self::line( $ref, self::MAX_REF ), self::STATUS_PENDING, $at, $at ]
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $inserted !== 1 ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;
		Wake::queue( $agentId );

		/**
		 * #1060: an event landed in an agent's inbox.
		 *
		 * @param int    $id
		 * @param int    $agentId
		 * @param string $source
		 * @param string $type
		 */
		\AgentBrain\Core\Hooks::action( 'agent_inbox_pushed', $id, $agentId, $source, $type );

		return $id;
	}

	/**
	 * Take every pending, available event as one batch under a lease.
	 *
	 * @return array{claim_id: ?string, lease_until: ?string, events: array<int, array<string, mixed>>, busy: bool, more: int}
	 */
	public static function claim( int $agentId, int $leaseMinutes = self::DEFAULT_LEASE_MINUTES, ?int $now = null ): array {
		global $wpdb;

		$now   = $now ?? time();
		$lease = max( 1, min( self::MAX_LEASE_MINUTES, $leaseMinutes ) ) * 60;
		self::expire( $agentId, $now );

		$live = self::liveLease( $agentId, $now );
		if ( $live !== null ) {
			return [ 'claim_id' => null, 'lease_until' => self::isoTime( $live['lease_until'] ), 'events' => [], 'busy' => true, 'more' => self::availableCount( $agentId, $now ) ];
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		$ids = array_map( 'intval', (array) $wpdb->get_col( Db::prepare( "SELECT id FROM {$table} WHERE agent_user_id = %d AND status = %s AND available_at <= %s ORDER BY id ASC LIMIT %d", [ $agentId, self::STATUS_PENDING, self::sqlTime( $now ), self::MAX_BATCH ] ) ) );
		if ( $ids === [] ) {
			return [ 'claim_id' => null, 'lease_until' => null, 'events' => [], 'busy' => false, 'more' => 0 ];
		}

		$claimId = 'c_' . bin2hex( random_bytes( 12 ) );
		$until   = self::sqlTime( $now + $lease );
		$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// Only rows still pending are taken: a racing claim that took them first wins them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(); placeholders built for each id.
		$wpdb->query( Db::prepare( "UPDATE {$table} SET status = %s, claim_id = %s, claimed_at = %s, lease_until = %s WHERE agent_user_id = %d AND status = %s AND id IN ({$in})", array_merge( [ self::STATUS_WORKING, $claimId, self::sqlTime( $now ), $until, $agentId, self::STATUS_PENDING ], $ids ) ) );

		// Two claims that raced each took some rows: the one holding the
		// oldest event keeps its claim, the other puts its rows back.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		$mine = $wpdb->get_var( Db::prepare( "SELECT MIN(id) FROM {$table} WHERE claim_id = %s AND status = %s", [ $claimId, self::STATUS_WORKING ] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		$other = $wpdb->get_var( Db::prepare( "SELECT MIN(id) FROM {$table} WHERE agent_user_id = %d AND status = %s AND claim_id <> %s AND lease_until > %s", [ $agentId, self::STATUS_WORKING, $claimId, self::sqlTime( $now ) ] ) );
		if ( $mine === null || ( $other !== null && (int) $other < (int) $mine ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
			$wpdb->query( Db::prepare( "UPDATE {$table} SET status = %s, claim_id = NULL, claimed_at = NULL, lease_until = NULL WHERE claim_id = %s AND status = %s", [ self::STATUS_PENDING, $claimId, self::STATUS_WORKING ] ) );
			$live = self::liveLease( $agentId, $now );

			return [ 'claim_id' => null, 'lease_until' => $live !== null ? self::isoTime( $live['lease_until'] ) : null, 'events' => [], 'busy' => $live !== null, 'more' => self::availableCount( $agentId, $now ) ];
		}

		$rows = Db::getResults( "SELECT * FROM {$table} WHERE claim_id = %s AND status = %s ORDER BY id ASC", [ $claimId, self::STATUS_WORKING ] );

		return [
			'claim_id'    => $claimId,
			'lease_until' => self::isoTime( $until ),
			'events'      => array_map( [ self::class, 'present' ], $rows ),
			'busy'        => false,
			'more'        => self::availableCount( $agentId, $now ),
		];
	}

	/**
	 * Close events of a claim: the ones in $ids, or all of them (null).
	 * Late is fine: a claim whose lease ran out but whose events were not
	 * put back yet still closes.
	 *
	 * @param array<int, int>|null $ids
	 * @return array{claim_id: string, status: string, closed: int, unknown: array<int, int>, remaining: int}|WP_Error
	 */
	public static function done( int $agentId, string $claimId, ?array $ids, bool $failed = false, string $note = '', ?int $now = null ) {
		global $wpdb;

		$now   = $now ?? time();
		$table = self::table();
		$held  = array_map( 'intval', (array) $wpdb->get_col( Db::prepare( "SELECT id FROM {$table} WHERE agent_user_id = %d AND claim_id = %s AND status = %s", [ $agentId, $claimId, self::STATUS_WORKING ] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		if ( $held === [] ) {
			return new WP_Error(
				'ab_inbox_claim_not_found',
				__( 'No open events under that claim. Its events are closed already, or its lease ran out and they went back to pending: claim again.', 'tomatio' ),
				[ 'status' => 404 ]
			);
		}

		$wanted  = $ids === null ? $held : array_values( array_unique( array_map( 'intval', $ids ) ) );
		$closing = array_values( array_intersect( $wanted, $held ) );
		$unknown = array_values( array_diff( $wanted, $held ) );
		$status  = $failed ? self::STATUS_FAILED : self::STATUS_DONE;

		$closed = 0;
		if ( $closing !== [] ) {
			$in = implode( ',', array_fill( 0, count( $closing ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(); placeholders built for each id.
			$closed = (int) $wpdb->query( Db::prepare( "UPDATE {$table} SET status = %s, finished_at = %s, note = %s, lease_until = NULL WHERE agent_user_id = %d AND claim_id = %s AND status = %s AND id IN ({$in})", array_merge( [ $status, self::sqlTime( $now ), self::line( $note, self::MAX_NOTE ), $agentId, $claimId, self::STATUS_WORKING ], $closing ) ) );
		}

		return [
			'claim_id'  => $claimId,
			'status'    => $status,
			'closed'    => $closed,
			'unknown'   => $unknown,
			'remaining' => count( $held ) - $closed,
		];
	}

	/**
	 * How many events wait, and the next few, without claiming.
	 *
	 * @return array{pending: int, later: int, working: int, lease_until: ?string, next: array<int, array<string, mixed>>}
	 */
	public static function peek( int $agentId, ?int $now = null ): array {
		$now = $now ?? time();
		self::expire( $agentId, $now );

		$table = self::table();
		$at    = self::sqlTime( $now );
		$later = Db::getResults( "SELECT COUNT(*) AS c FROM {$table} WHERE agent_user_id = %d AND status = %s AND available_at > %s", [ $agentId, self::STATUS_PENDING, $at ] );
		$work  = Db::getResults( "SELECT COUNT(*) AS c FROM {$table} WHERE agent_user_id = %d AND status = %s", [ $agentId, self::STATUS_WORKING ] );
		$next  = Db::getResults( "SELECT * FROM {$table} WHERE agent_user_id = %d AND status = %s AND available_at <= %s ORDER BY id ASC LIMIT %d", [ $agentId, self::STATUS_PENDING, $at, self::PEEK ] );
		$live  = self::liveLease( $agentId, $now );

		return [
			'pending'     => self::availableCount( $agentId, $now ),
			'later'       => (int) ( $later[0]['c'] ?? 0 ),
			'working'     => (int) ( $work[0]['c'] ?? 0 ),
			'lease_until' => $live !== null ? self::isoTime( $live['lease_until'] ) : null,
			'next'        => array_map(
				static fn ( array $row ): array => array_intersect_key( self::present( $row ), array_flip( [ 'id', 'source', 'type', 'summary', 'ref', 'created_at' ] ) ),
				$next
			),
		];
	}

	/** Pending events the agent could claim now. */
	public static function availableCount( int $agentId, ?int $now = null ): int {
		$rows = Db::getResults( 'SELECT COUNT(*) AS c FROM ' . self::table() . ' WHERE agent_user_id = %d AND status = %s AND available_at <= %s', [ $agentId, self::STATUS_PENDING, self::sqlTime( $now ?? time() ) ] );

		return (int) ( $rows[0]['c'] ?? 0 );
	}

	/**
	 * Expired leases (of one agent, or of all when $agentId is 0): their
	 * events go back to pending with one more attempt, or to failed at
	 * MAX_ATTEMPTS. Returns how many rows changed.
	 */
	public static function expire( int $agentId = 0, ?int $now = null ): int {
		global $wpdb;

		$at     = self::sqlTime( $now ?? time() );
		$table  = self::table();
		$where  = 'status = %s AND lease_until <= %s';
		$params = [ self::STATUS_FAILED, $at, __( 'The lease ran out on every attempt.', 'tomatio' ), self::STATUS_WORKING, $at ];
		if ( $agentId > 0 ) {
			$where   .= ' AND agent_user_id = %d';
			$params[] = $agentId;
		}

		// attempts is read before it is raised: MySQL assigns left to right
		// and SQLite reads every old value, so both see the old count here.
		$sql = "UPDATE {$table} SET "
			. 'status = CASE WHEN attempts + 1 >= ' . self::MAX_ATTEMPTS . " THEN %s ELSE '" . self::STATUS_PENDING . "' END, "
			. 'finished_at = CASE WHEN attempts + 1 >= ' . self::MAX_ATTEMPTS . ' THEN %s ELSE NULL END, '
			. 'note = CASE WHEN attempts + 1 >= ' . self::MAX_ATTEMPTS . ' THEN %s ELSE note END, '
			. 'attempts = attempts + 1, claim_id = NULL, claimed_at = NULL, lease_until = NULL '
			. "WHERE {$where}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Db::prepare() is the enforcement point; table name from Db::table().
		return (int) $wpdb->query( Db::prepare( $sql, $params ) );
	}

	/** Remove done and failed events older than $days. Returns how many. */
	public static function prune( int $days = self::RETENTION_DAYS, ?int $now = null ): int {
		global $wpdb;

		$before = self::sqlTime( ( $now ?? time() ) - max( 1, $days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Db::prepare() is the enforcement point; table name from Db::table().
		return (int) $wpdb->query( Db::prepare( 'DELETE FROM ' . self::table() . ' WHERE status IN (%s, %s) AND finished_at < %s', [ self::STATUS_DONE, self::STATUS_FAILED, $before ] ) );
	}

	/** An account was deleted: its inbox goes with it. */
	public static function deleteForAgent( int $agentId ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Db::prepare() is the enforcement point; table name from Db::table().
		$wpdb->query( Db::prepare( 'DELETE FROM ' . self::table() . ' WHERE agent_user_id = %d', [ $agentId ] ) );
	}

	/**
	 * One event as the tools answer it.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function present( array $row ): array {
		return [
			'id'          => (int) $row['id'],
			'source'      => (string) $row['source'],
			'source_id'   => (string) $row['source_id'],
			'type'        => (string) $row['type'],
			'summary'     => (string) $row['summary'],
			'ref'         => (string) $row['ref'],
			'status'      => (string) $row['status'],
			'attempts'    => (int) $row['attempts'],
			'created_at'  => self::isoTime( (string) $row['created_at'] ),
			'finished_at' => isset( $row['finished_at'] ) && $row['finished_at'] !== null ? self::isoTime( (string) $row['finished_at'] ) : null,
			'note'        => isset( $row['note'] ) && $row['note'] !== null ? (string) $row['note'] : null,
		];
	}

	/**
	 * The live claim of an agent: its id and lease end, or null.
	 *
	 * @return array{claim_id: string, lease_until: string}|null
	 */
	private static function liveLease( int $agentId, int $now ): ?array {
		$rows = Db::getResults( 'SELECT claim_id, MAX(lease_until) AS lease_until FROM ' . self::table() . ' WHERE agent_user_id = %d AND status = %s AND lease_until > %s GROUP BY claim_id ORDER BY lease_until DESC LIMIT 1', [ $agentId, self::STATUS_WORKING, self::sqlTime( $now ) ] );
		if ( $rows === [] || ! is_string( $rows[0]['claim_id'] ?? null ) ) {
			return null;
		}

		return [ 'claim_id' => (string) $rows[0]['claim_id'], 'lease_until' => (string) $rows[0]['lease_until'] ];
	}

	/** One line of at most $max characters: tags and line breaks out. */
	public static function line( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	public static function sqlTime( int $time ): string {
		return gmdate( 'Y-m-d H:i:s', $time );
	}

	public static function isoTime( string $sqlTime ): string {
		$time = strtotime( $sqlTime . ' UTC' );

		return $time === false ? $sqlTime : gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}
}
