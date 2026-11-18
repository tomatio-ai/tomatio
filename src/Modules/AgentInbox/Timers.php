<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentInbox;

use AgentBrain\Core\Db;
use WP_Error;

/**
 * #1060: an agent's own follow-ups, so it sets one ("check PR #12 at
 * 15:00") instead of promising to look later.
 *
 * Timers live in their own table. A follow-up fires once; a schedule
 * fires every every_seconds (at least 15 minutes). The scheduler (Cron,
 * every minute) turns each due timer into an inbox event with source
 * "timer" and a source_id of "<timer id>@<due time>", so a pass that runs
 * twice, or two passes at once, fire a due time only once (the inbox drops
 * the second push). Then a follow-up is marked done and a schedule moves
 * to its next due time after now (missed times are skipped, not caught up).
 */
final class Timers {

	public const KIND_FOLLOW_UP = 'follow-up';
	public const KIND_SCHEDULE  = 'schedule';

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_DONE      = 'done';
	public const STATUS_CANCELLED = 'cancelled';

	/** Active timers per agent, at most. */
	public const MAX_ACTIVE = 50;

	public const MIN_EVERY_MINUTES = 15;
	public const MAX_EVERY_MINUTES = 43200; // 30 days.
	public const MAX_AHEAD_DAYS    = 365;

	/** Timers one scheduler pass fires at most. */
	public const MAX_FIRE = 200;

	public const MAX_NOTE = 200;
	public const MAX_REF  = 255;

	public static function table(): string {
		return Db::table( 'agent_timers' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			agent_user_id BIGINT(20) UNSIGNED NOT NULL,
			kind VARCHAR(16) NOT NULL,
			due_at DATETIME NOT NULL,
			every_seconds INT(10) UNSIGNED NOT NULL DEFAULT 0,
			note VARCHAR(255) NOT NULL,
			ref VARCHAR(255) NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			fired INT(10) UNSIGNED NOT NULL DEFAULT 0,
			last_fired_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY due (status, due_at),
			KEY agent (agent_user_id, status)
		) {$charset};";
	}

	/**
	 * Set a timer for $agentId. Input: kind (follow-up or schedule; a
	 * schedule when every_minutes is given), at (ISO 8601) or in_minutes,
	 * every_minutes (a schedule, at least 15), note, ref.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error The timer.
	 */
	public static function set( int $agentId, array $input, ?int $now = null ) {
		global $wpdb;

		$now   = $now ?? time();
		$every = isset( $input['every_minutes'] ) && $input['every_minutes'] !== null && $input['every_minutes'] !== '' ? $input['every_minutes'] : null;
		$kind  = is_string( $input['kind'] ?? null ) && $input['kind'] !== '' ? $input['kind'] : ( $every !== null ? self::KIND_SCHEDULE : self::KIND_FOLLOW_UP );
		if ( ! in_array( $kind, [ self::KIND_FOLLOW_UP, self::KIND_SCHEDULE ], true ) ) {
			return self::invalid( __( 'kind is follow-up (once) or schedule (every every_minutes).', 'tomatio' ) );
		}

		$seconds = 0;
		if ( $kind === self::KIND_SCHEDULE ) {
			if ( ! is_numeric( $every ) || (int) $every < self::MIN_EVERY_MINUTES || (int) $every > self::MAX_EVERY_MINUTES ) {
				/* translators: 1: the least, 2: the most minutes. */
				return self::invalid( sprintf( __( 'A schedule needs every_minutes, from %1$d to %2$d.', 'tomatio' ), self::MIN_EVERY_MINUTES, self::MAX_EVERY_MINUTES ) );
			}
			$seconds = (int) $every * 60;
		} elseif ( $every !== null ) {
			return self::invalid( __( 'A follow-up fires once: leave out every_minutes, or set kind schedule.', 'tomatio' ) );
		}

		$due = self::dueOf( $input, $now, $kind === self::KIND_SCHEDULE ? $now + $seconds : null );
		if ( $due instanceof WP_Error ) {
			return $due;
		}

		if ( self::activeCount( $agentId ) >= self::MAX_ACTIVE ) {
			/* translators: %d: the most active timers. */
			return new WP_Error( 'ab_timer_limit', sprintf( __( 'You have %d active timers, the most there may be. Cancel one with timer-cancel first.', 'tomatio' ), self::MAX_ACTIVE ), [ 'status' => 422 ] );
		}

		$row = [
			'agent_user_id' => $agentId,
			'kind'          => $kind,
			'due_at'        => Inbox::sqlTime( $due ),
			'every_seconds' => $seconds,
			'note'          => Inbox::line( is_string( $input['note'] ?? null ) ? $input['note'] : '', self::MAX_NOTE ),
			'ref'           => Inbox::line( is_string( $input['ref'] ?? null ) ? $input['ref'] : '', self::MAX_REF ),
			'status'        => self::STATUS_ACTIVE,
			'fired'         => 0,
			'created_at'    => Inbox::sqlTime( $now ),
		];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->insert( self::table(), $row, [ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' ] ) ) {
			return new WP_Error( 'ab_timer_failed', __( 'The timer could not be saved. Try again.', 'tomatio' ), [ 'status' => 500 ] );
		}

		return self::present( $row + [ 'id' => (int) $wpdb->insert_id, 'last_fired_at' => null ] );
	}

	/**
	 * The agent's active timers, soonest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( int $agentId ): array {
		$rows = Db::getResults( 'SELECT * FROM ' . self::table() . ' WHERE agent_user_id = %d AND status = %s ORDER BY due_at ASC, id ASC', [ $agentId, self::STATUS_ACTIVE ] );

		return array_map( [ self::class, 'present' ], $rows );
	}

	/** Cancel one of the agent's own active timers. False when it has none by that id. */
	public static function cancel( int $agentId, int $timerId ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->update( self::table(), [ 'status' => self::STATUS_CANCELLED ], [ 'id' => $timerId, 'agent_user_id' => $agentId, 'status' => self::STATUS_ACTIVE ], [ '%s' ], [ '%d', '%d', '%s' ] ) === 1;
	}

	/**
	 * Fire every due timer: one inbox event per due time, then a follow-up
	 * is done and a schedule moves to its next time. Returns how many
	 * timers fired.
	 */
	public static function fire( ?int $now = null ): int {
		global $wpdb;

		$now   = $now ?? time();
		$table = self::table();
		$rows  = Db::getResults( "SELECT * FROM {$table} WHERE status = %s AND due_at <= %s ORDER BY due_at ASC, id ASC LIMIT %d", [ self::STATUS_ACTIVE, Inbox::sqlTime( $now ), self::MAX_FIRE ] );

		$fired = 0;
		foreach ( $rows as $row ) {
			$id      = (int) $row['id'];
			$agentId = (int) $row['agent_user_id'];
			$dueAt   = (string) $row['due_at'];
			$dueTs   = (int) strtotime( $dueAt . ' UTC' );
			$every   = (int) $row['every_seconds'];
			$isOnce  = (string) $row['kind'] !== self::KIND_SCHEDULE || $every <= 0;
			$note    = (string) $row['note'];

			Inbox::push(
				$agentId,
				'timer',
				$id . '@' . $dueTs,
				$isOnce ? 'timer.follow-up' : 'timer.schedule',
				$note !== '' ? $note : ( $isOnce ? __( 'Your follow-up is due.', 'tomatio' ) : __( 'Your schedule is due.', 'tomatio' ) ),
				(string) $row['ref'] !== '' ? (string) $row['ref'] : 'timer:' . $id,
				$now
			);

			// Only when nobody moved it meanwhile (a parallel pass, a cancel).
			$change = $isOnce
				? [ 'status' => self::STATUS_DONE ]
				: [ 'due_at' => Inbox::sqlTime( $dueTs + $every * ( intdiv( max( 0, $now - $dueTs ), $every ) + 1 ) ) ];
			$change += [ 'fired' => (int) $row['fired'] + 1, 'last_fired_at' => Inbox::sqlTime( $now ) ];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( (int) $wpdb->update( $table, $change, [ 'id' => $id, 'status' => self::STATUS_ACTIVE, 'due_at' => $dueAt ] ) === 1 ) {
				++$fired;
			}
		}

		return $fired;
	}

	public static function activeCount( int $agentId ): int {
		$rows = Db::getResults( 'SELECT COUNT(*) AS c FROM ' . self::table() . ' WHERE agent_user_id = %d AND status = %s', [ $agentId, self::STATUS_ACTIVE ] );

		return (int) ( $rows[0]['c'] ?? 0 );
	}

	/** Remove done and cancelled timers older than $days. */
	public static function prune( int $days = Inbox::RETENTION_DAYS, ?int $now = null ): int {
		global $wpdb;

		$before = Inbox::sqlTime( ( $now ?? time() ) - max( 1, $days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Db::prepare() is the enforcement point; table name from Db::table().
		return (int) $wpdb->query( Db::prepare( 'DELETE FROM ' . self::table() . ' WHERE status IN (%s, %s) AND created_at < %s AND (last_fired_at IS NULL OR last_fired_at < %s)', [ self::STATUS_DONE, self::STATUS_CANCELLED, $before, $before ] ) );
	}

	public static function deleteForAgent( int $agentId ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), [ 'agent_user_id' => $agentId ], [ '%d' ] );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function present( array $row ): array {
		$every = (int) $row['every_seconds'];

		return [
			'id'            => (int) $row['id'],
			'kind'          => (string) $row['kind'],
			'due_at'        => Inbox::isoTime( (string) $row['due_at'] ),
			'every_minutes' => $every > 0 ? intdiv( $every, 60 ) : null,
			'note'          => (string) $row['note'],
			'ref'           => (string) $row['ref'],
			'status'        => (string) $row['status'],
			'fired'         => (int) $row['fired'],
			'created_at'    => Inbox::isoTime( (string) $row['created_at'] ),
		];
	}

	/**
	 * The first due time: `at` (ISO 8601) or `in_minutes`, else $default.
	 *
	 * @param array<string, mixed> $input
	 * @return int|WP_Error
	 */
	private static function dueOf( array $input, int $now, ?int $default ) {
		$at = is_string( $input['at'] ?? null ) ? trim( $input['at'] ) : '';
		$in = $input['in_minutes'] ?? null;
		if ( $at !== '' && $in !== null && $in !== '' ) {
			return self::invalid( __( 'Give at or in_minutes, not both.', 'tomatio' ) );
		}

		if ( $at !== '' ) {
			$due = strtotime( $at );
			if ( $due === false || preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $at ) !== 1 ) {
				return self::invalid( __( 'at is a time in ISO 8601, such as 2026-11-18T15:00:00Z (without a zone it is read as UTC).', 'tomatio' ) );
			}
			if ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $at ) !== 1 ) {
				$due = (int) strtotime( $at . ' UTC' );
			}
			if ( $due < $now - MINUTE_IN_SECONDS ) {
				return self::invalid( __( 'at is in the past.', 'tomatio' ) );
			}
		} elseif ( $in !== null && $in !== '' ) {
			if ( ! is_numeric( $in ) || (int) $in < 1 ) {
				return self::invalid( __( 'in_minutes is a whole number of minutes, at least 1.', 'tomatio' ) );
			}
			$due = $now + (int) $in * 60;
		} elseif ( $default !== null ) {
			$due = $default;
		} else {
			return self::invalid( __( 'A follow-up needs at (an ISO 8601 time) or in_minutes.', 'tomatio' ) );
		}

		if ( $due > $now + self::MAX_AHEAD_DAYS * DAY_IN_SECONDS ) {
			/* translators: %d: days. */
			return self::invalid( sprintf( __( 'A timer may be at most %d days ahead.', 'tomatio' ), self::MAX_AHEAD_DAYS ) );
		}

		return max( $now, (int) $due );
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'ab_invalid_input', $message, [ 'status' => 400 ] );
	}
}
