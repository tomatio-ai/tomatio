<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentRegistration;

use AgentBrain\Core\Db;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\AgentId\Fqdn;

/**
 * Persistence for pending agent registrations. Only the sha256 of the key is
 * stored. State changes are single atomic UPDATEs guarded by the expected
 * status, so two concurrent registrations can never both win.
 */
final class RegistrationStore {

	public const STATUS_PENDING = 'pending';
	public const STATUS_CLAIMED = 'claimed';
	public const STATUS_SPENT   = 'spent';
	public const STATUS_REVOKED = 'revoked';

	public const DEFAULT_TTL_HOURS = 24;
	public const OPTION_TTL_HOURS  = 'ab_agent_registration_ttl_hours';

	/**
	 * A key for several bots (#807): each bot redeems the same key under its
	 * own name and becomes its own agent, up to the key's use limit.
	 */
	public const MAX_USES            = 100;
	public const DEFAULT_SHARED_USES = 10;
	public const DEFAULT_SHARED_DAYS = 7;
	public const MAX_SHARED_DAYS     = 30;

	/** User meta on an agent a shared key made: the key's registration id. */
	public const META_REGISTRATION = 'ab_agent_registration_id';

	public static function table(): string {
		return Db::table( 'agent_registrations' );
	}

	/** Lifetime in seconds: 24h by default, configurable down only. */
	public static function ttlSeconds(): int {
		$hours = (int) get_option( self::OPTION_TTL_HOURS, self::DEFAULT_TTL_HOURS );
		$hours = max( 1, min( self::DEFAULT_TTL_HOURS, $hours ) );

		return $hours * HOUR_IN_SECONDS;
	}

	/** Pure: the lifetime of a key for several bots, in whole days (default 7, 1 to 30). */
	public static function sharedDays( int $days ): int {
		return $days <= 0 ? self::DEFAULT_SHARED_DAYS : min( self::MAX_SHARED_DAYS, $days );
	}

	/**
	 * $maxUses 1 is the classic one-time key (24 hours at most). More than 1
	 * makes a key for several bots: no fixed name (each bot brings its own)
	 * and a lifetime of $sharedDays days (default 7, at most 30).
	 *
	 * @param int[] $readScopeIds
	 * @param int[] $writeScopeIds
	 * @return array{key:string, id:int, expires:string, max_uses:int}|\WP_Error
	 */
	public static function create( string $class, array $readScopeIds, array $writeScopeIds, ?string $agentName, int $issuedBy, int $maxUses = 1, int $sharedDays = 0 ) {
		global $wpdb;

		if ( ! in_array( $class, AgentMeta::CLASSES, true ) ) {
			return new \WP_Error( 'ab_invalid_agent_class', 'Unknown agent class.', [ 'status' => 422 ] );
		}

		if ( $agentName !== null && $agentName !== '' && ! Fqdn::isValidName( $agentName ) ) {
			return new \WP_Error( 'ab_invalid_agent_name', 'Invalid agent name.', [ 'status' => 422 ] );
		}

		if ( $maxUses < 1 || $maxUses > self::MAX_USES ) {
			return new \WP_Error( 'ab_invalid_use_limit', sprintf( 'A key can make 1 to %d agents.', self::MAX_USES ), [ 'status' => 422 ] );
		}

		if ( $maxUses > 1 && $agentName !== null && $agentName !== '' ) {
			return new \WP_Error( 'ab_shared_key_fixed_name', 'A key for several bots cannot fix one name: each bot chooses its own.', [ 'status' => 422 ] );
		}

		$lifetime   = $maxUses > 1 ? self::sharedDays( $sharedDays ) * DAY_IN_SECONDS : self::ttlSeconds();
		$key        = RegistrationKey::generate();
		$registerId = self::reserveRegisterId();
		$expires    = gmdate( 'Y-m-d H:i:s', time() + $lifetime );

		$ok = $wpdb->insert(
			self::table(),
			[
				'key_hash'     => RegistrationKey::hash( $key ),
				'register_id'  => $registerId,
				'status'       => self::STATUS_PENDING,
				'agent_class'  => $class,
				'read_scopes'  => (string) wp_json_encode( array_values( array_map( 'intval', $readScopeIds ) ) ),
				'write_scopes' => (string) wp_json_encode( array_values( array_map( 'intval', $writeScopeIds ) ) ),
				'agent_name'   => ( $agentName === null || $agentName === '' ) ? null : $agentName,
				'issued_by'    => $issuedBy,
				'created'      => gmdate( 'Y-m-d H:i:s' ),
				'expires'      => $expires,
				'max_uses'     => $maxUses,
				'uses'         => 0,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d' ]
		);

		if ( $ok !== 1 ) {
			return new \WP_Error( 'ab_registration_store', 'Could not store the registration.', [ 'status' => 500 ] );
		}

		return [ 'key' => $key, 'id' => (int) $wpdb->insert_id, 'expires' => $expires, 'max_uses' => $maxUses ];
	}

	private static function reserveRegisterId(): string {
		global $wpdb;

		$table = self::table();
		for ( $i = 0; $i < 8; $i++ ) {
			$id = Fqdn::generateRegisterId();
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from Db::table(), values prepared.
			$taken = $wpdb->get_var( Db::prepare( "SELECT id FROM {$table} WHERE register_id = %s", [ $id ] ) );
			if ( $taken === null ) {
				return $id;
			}
		}

		return Fqdn::generateRegisterId();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function findByKey( string $key ): ?array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from Db::table(), values prepared.
		$row = $wpdb->get_row( Db::prepare( "SELECT * FROM {$table} WHERE key_hash = %s", [ RegistrationKey::hash( $key ) ] ), ARRAY_A );

		return is_array( $row ) ? self::normalize( $row ) : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from Db::table(), values prepared.
		$row = $wpdb->get_row( Db::prepare( "SELECT * FROM {$table} WHERE id = %d", [ $id ] ), ARRAY_A );

		return is_array( $row ) ? self::normalize( $row ) : null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 50 ): array {
		$table = self::table();
		$rows  = Db::getResults( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", [ $limit ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().

		return array_map( [ self::class, 'normalize' ], $rows );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function normalize( array $row ): array {
		$row['id']            = (int) $row['id'];
		$row['issued_by']     = (int) $row['issued_by'];
		$row['agent_user_id'] = (int) ( $row['agent_user_id'] ?? 0 );
		$row['max_uses']      = max( 1, (int) ( $row['max_uses'] ?? 1 ) );
		$row['uses']          = (int) ( $row['uses'] ?? 0 );
		$row['read_ids']      = self::decodeIds( (string) ( $row['read_scopes'] ?? '' ) );
		$row['write_ids']     = self::decodeIds( (string) ( $row['write_scopes'] ?? '' ) );

		return $row;
	}

	/**
	 * @return int[]
	 */
	private static function decodeIds( string $json ): array {
		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? array_values( array_map( 'intval', $decoded ) ) : [];
	}

	/**
	 * Display state: pending rows past their expiry read as "expired".
	 *
	 * @param array<string, mixed> $row
	 */
	public static function displayStatus( array $row ): string {
		if ( $row['status'] === self::STATUS_PENDING && self::isExpired( $row ) ) {
			return 'expired';
		}

		return (string) $row['status'];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function isExpired( array $row ): bool {
		$ts = strtotime( (string) $row['expires'] . ' UTC' );

		return $ts === false || $ts <= time();
	}

	/**
	 * A key for several bots (#807)?
	 *
	 * @param array<string, mixed> $row
	 */
	public static function isShared( array $row ): bool {
		return (int) ( $row['max_uses'] ?? 1 ) > 1;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function usesLeft( array $row ): int {
		return max( 0, (int) ( $row['max_uses'] ?? 1 ) - (int) ( $row['uses'] ?? 0 ) );
	}

	/**
	 * Take one use of a shared key, atomically: true only when the key was
	 * pending, unexpired and had a use left. The last use marks it spent.
	 * The row lock this UPDATE takes holds until the caller's transaction
	 * ends, so concurrent redemptions queue up and each sees the count the
	 * one before it left; a rollback gives the use back.
	 */
	public static function claimUse( int $id ): bool {
		global $wpdb;

		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		// MySQL applies the assignments left to right: status and spent_at
		// already see the incremented count.
		$sql      = Db::prepare( "UPDATE {$table} SET uses = uses + 1, status = IF(uses >= max_uses, %s, status), spent_at = IF(uses >= max_uses, %s, spent_at) WHERE id = %d AND status = %s AND uses < max_uses AND expires > %s", [ self::STATUS_SPENT, $now, $id, self::STATUS_PENDING, $now ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		$affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.

		return (int) $affected === 1;
	}

	/**
	 * The agents a shared key made, oldest first.
	 *
	 * @return int[] user ids
	 */
	public static function agentsMadeBy( int $id ): array {
		$ids = get_users( [
			'meta_key'   => self::META_REGISTRATION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, admin-only list.
			'meta_value' => (string) $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- small, admin-only list.
			'fields'     => 'ID',
			'orderby'    => 'ID',
			'order'      => 'ASC',
		] );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Atomically move pending -> claimed (the single-use gate). True only for
	 * the one caller that wins.
	 */
	public static function claim( int $id ): bool {
		global $wpdb;

		$table = self::table();
		$sql      = Db::prepare( "UPDATE {$table} SET status = %s WHERE id = %d AND status = %s AND expires > %s", [ self::STATUS_CLAIMED, $id, self::STATUS_PENDING, gmdate( 'Y-m-d H:i:s' ) ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		$affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.

		return (int) $affected === 1;
	}

	/** Undo a claim after a failed registration. */
	public static function release( int $id ): void {
		self::transition( $id, self::STATUS_CLAIMED, self::STATUS_PENDING );
	}

	public static function markSpent( int $id, int $agentUserId ): bool {
		global $wpdb;

		$table = self::table();
		$sql      = Db::prepare( "UPDATE {$table} SET status = %s, agent_user_id = %d, spent_at = %s WHERE id = %d AND status = %s", [ self::STATUS_SPENT, $agentUserId, gmdate( 'Y-m-d H:i:s' ), $id, self::STATUS_CLAIMED ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().
		$affected = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.

		return (int) $affected === 1;
	}

	/**
	 * Revoke a key that still has a use left. Spent keys cannot be revoked
	 * (revoke the agent instead); the agents a shared key already made stay.
	 */
	public static function revoke( int $id ): bool {
		return self::transition( $id, self::STATUS_PENDING, self::STATUS_REVOKED );
	}

	private static function transition( int $id, string $from, string $to ): bool {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from Db::table(), values prepared.
		$affected = $wpdb->query( Db::prepare( "UPDATE {$table} SET status = %s WHERE id = %d AND status = %s", [ $to, $id, $from ] ) );

		return (int) $affected === 1;
	}

	/**
	 * Failed-attempt window for a key hash (transient; never stores the key).
	 *
	 * @return array{count:int, start:int}|null
	 */
	public static function failureState( string $keyHash ): ?array {
		$state = get_transient( self::failureKey( $keyHash ) );

		return is_array( $state ) && isset( $state['count'], $state['start'] )
			? [ 'count' => (int) $state['count'], 'start' => (int) $state['start'] ]
			: null;
	}

	public static function recordFailure( string $keyHash ): void {
		$next = RegistrationKey::afterFailure( self::failureState( $keyHash ), time() );
		set_transient( self::failureKey( $keyHash ), $next, RegistrationKey::FAILURE_WINDOW );
	}

	public static function isLocked( string $keyHash ): bool {
		return RegistrationKey::isLocked( self::failureState( $keyHash ), time() );
	}

	/**
	 * Unknown keys must not create a row each (storage-bloat DoS). They count
	 * in one of UNKNOWN_BUCKETS fixed transients, chosen by client address.
	 */
	public static function recordUnknownFailure(): void {
		$key  = self::unknownKey();
		$raw  = get_transient( $key );
		$cur  = is_array( $raw ) && isset( $raw['count'], $raw['start'] ) ? [ 'count' => (int) $raw['count'], 'start' => (int) $raw['start'] ] : null;
		$next = RegistrationKey::afterFailure( $cur, time() );
		set_transient( $key, $next, RegistrationKey::FAILURE_WINDOW );
	}

	public static function isUnknownLocked(): bool {
		$raw = get_transient( self::unknownKey() );
		$cur = is_array( $raw ) && isset( $raw['count'], $raw['start'] ) ? [ 'count' => (int) $raw['count'], 'start' => (int) $raw['start'] ] : null;

		return RegistrationKey::isLocked( $cur, time(), RegistrationKey::MAX_UNKNOWN_FAILURES );
	}

	private static function unknownKey(): string {
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed into a bucket number, never output.
		$bucket = hexdec( substr( hash( 'sha256', $ip ), 0, 6 ) ) % RegistrationKey::UNKNOWN_BUCKETS;

		return 'ab_regfail_u' . $bucket;
	}

	private static function failureKey( string $keyHash ): string {
		return 'ab_regfail_' . substr( $keyHash, 0, 32 );
	}
}
