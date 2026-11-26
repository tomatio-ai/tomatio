<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

use AgentBrain\Core\AuthorizedBy;
use AgentBrain\Core\ClientContext;
use AgentBrain\Core\Db;
use AgentBrain\Modules\MemoriesAdmin\Store as MemoryStore;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Portability\Binding;
use AgentBrain\Modules\Portability\Principals;

/**
 * #1002: voice profiles are typed records in the brain's memory (Dennis,
 * 14 Nov 2026): ab_memory rows of type `voice`, in the owner's private
 * scope unless a team scope is chosen. So they carry the memory row's
 * provenance (who wrote it, for whom, from which client), go out with a
 * PCP export as pages under memory/voice/ and come back with a restore or
 * import, and an erasure finds them like any other row. Each owner has at
 * most one live profile per context and language (and one global part);
 * a profile changes in place, and what changed is kept in its shifts.
 *
 * The rows are few (a handful per person), so finding an owner's profiles
 * reads the live voice rows and matches the owner by login, which survives
 * an export, then by id.
 */
final class Profiles {

	public const TYPE = 'voice';

	/** @var array<int, array<int, array<string, mixed>>>|null owner id => their profiles, per request */
	private static ?array $cache = null;

	/** Forget what this request read (after a write, and in tests). */
	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * Every live profile of $owner: each record with its row's id
	 * (`memory_id`) and `scope_id`, the global part included.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function forOwner( int $owner ): array {
		return self::all()[ $owner ] ?? [];
	}

	/** @return array<string, mixed>|null */
	public static function find( int $owner, string $context, string $language ): ?array {
		foreach ( self::forOwner( $owner ) as $record ) {
			if ( $record['context'] === $context && $record['language'] === $language ) {
				return $record;
			}
		}

		return null;
	}

	/** @return array<string, mixed>|null */
	public static function byId( int $memoryId ): ?array {
		foreach ( self::all() as $records ) {
			foreach ( $records as $record ) {
				if ( (int) $record['memory_id'] === $memoryId ) {
					return $record;
				}
			}
		}

		return null;
	}

	/**
	 * Store a record: a new row when it has no memory_id, else the row is
	 * rewritten in place (content, search keys and, when moved, scope).
	 * The writer is the current user, attributed via the person behind
	 * the call, from the current MCP client.
	 *
	 * @param array<string, mixed> $record
	 * @return int the row id, 0 when it could not be stored
	 */
	public static function save( array $record, int $scopeId ): int {
		global $wpdb;

		$owner = get_userdata( (int) $record['owner_id'] );
		if ( ! $owner instanceof \WP_User || $scopeId <= 0 ) {
			return 0;
		}

		$principal                 = Principals::ensure( (int) $owner->ID );
		$record['owner']           = (string) $owner->user_login;
		$record['owner_principal'] = is_string( $principal ) ? $principal : (string) ( $record['owner_principal'] ?? '' );
		$record['updated_at']      = gmdate( 'Y-m-d\TH:i:s\Z' );
		$record['measured']        = Measure::trim( is_array( $record['measured'] ?? null ) ? $record['measured'] : [] );
		$memoryId                  = (int) ( $record['memory_id'] ?? 0 );
		unset( $record['memory_id'], $record['scope_id'] );

		$content = Profile::render( $record, (string) $owner->display_name );
		$keys    = self::searchKeys( $record, $owner );
		self::reset();

		if ( $memoryId > 0 ) {
			$table = MemoryRepository::memoryTable();
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$done = $wpdb->query( Db::prepare(
				"UPDATE {$table} SET content = %s, search_keys = %s, scope_id = %d WHERE id = %d AND type = %s AND status = %s",
				[ $content, $keys, $scopeId, $memoryId, self::TYPE, 'active' ]
			) );
			// phpcs:enable

			return $done === false ? 0 : $memoryId;
		}

		$writer = (int) get_current_user_id();
		$writer = $writer > 0 ? $writer : (int) $owner->ID;
		$via    = Access::person();
		$via    = $via > 0 && $via !== $writer ? $via : ( AuthorizedBy::id() > 0 && AuthorizedBy::id() !== $writer ? AuthorizedBy::id() : 0 );

		try {
			return ( new MemoryRepository() )->insert( [
				'agent_id'      => $writer,
				'scope_id'      => $scopeId,
				'type'          => self::TYPE,
				'content'       => $content,
				'search_keys'   => $keys,
				'importance'    => 60,
				'status'        => 'active',
				'agent_written' => Access::isAgent( $writer ) ? 1 : 0,
				'client'        => ClientContext::current(),
				'via'           => $via > 0 ? $via : null,
			] );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/** Remove a profile for good: the row, its links and its page index entry. */
	public static function delete( int $memoryId ): bool {
		self::reset();

		return $memoryId > 0 && self::byIdUncached( $memoryId ) && MemoryStore::delete( $memoryId );
	}

	/**
	 * Words a memory search finds the profile by.
	 *
	 * @param array<string, mixed> $record
	 */
	private static function searchKeys( array $record, \WP_User $owner ): string {
		return mb_substr( implode( ' ', array_filter( [ 'voice profile writing style', (string) $owner->user_login, (string) $owner->display_name, (string) $record['context'], (string) $record['language'] ] ) ), 0, 250 );
	}

	private static function byIdUncached( int $memoryId ): bool {
		$table = MemoryRepository::memoryTable();
		$rows = Db::getResults( "SELECT id FROM {$table} WHERE id = %d AND type = %s", [ $memoryId, self::TYPE ] );

		return $rows !== [];
	}

	/**
	 * Whose profile a record is: the account holding its principal id
	 * (which a restore keeps), else the account with its login, else its
	 * user id. 0 when none is left.
	 *
	 * @param array<string, mixed> $record
	 * @param array<string, int>   $users lookups so far, by key
	 */
	private static function ownerOf( array $record, array &$users ): int {
		$principal = (string) ( $record['owner_principal'] ?? '' );
		if ( $principal !== '' ) {
			$key = 'p:' . $principal;
			if ( ! isset( $users[ $key ] ) ) {
				$users[ $key ] = (int) ( Binding::userFor( $principal ) ?? 0 );
			}
			if ( $users[ $key ] > 0 ) {
				return $users[ $key ];
			}
		}
		$key = 'l:' . (string) $record['owner'];
		if ( ! isset( $users[ $key ] ) ) {
			$user          = get_user_by( 'login', (string) $record['owner'] );
			$users[ $key ] = $user instanceof \WP_User ? (int) $user->ID : 0;
		}
		if ( $users[ $key ] > 0 ) {
			return $users[ $key ];
		}

		return $principal === '' && get_userdata( (int) $record['owner_id'] ) instanceof \WP_User ? (int) $record['owner_id'] : 0;
	}

	/** @return array<int, array<int, array<string, mixed>>> */
	private static function all(): array {
		if ( self::$cache !== null ) {
			return self::$cache;
		}

		$table = MemoryRepository::memoryTable();
		$rows  = Db::getResults( "SELECT id, scope_id, content FROM {$table} WHERE type = %s AND status = %s ORDER BY id DESC LIMIT %d", [ self::TYPE, 'active', 5000 ] );
		$out   = [];
		$seen  = [];
		$users = [];
		foreach ( $rows as $row ) {
			$record = Profile::parse( (string) $row['content'] );
			if ( $record === null ) {
				continue;
			}
			$owner = self::ownerOf( $record, $users );
			// The newest row wins when an import left two for the same key.
			$key = $owner . '|' . $record['context'] . '|' . $record['language'];
			if ( $owner <= 0 || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ]          = true;
			$record['owner_id']    = $owner;
			$record['memory_id']   = (int) $row['id'];
			$record['scope_id']    = (int) $row['scope_id'];
			$out[ $owner ][]       = $record;
		}
		foreach ( $out as $owner => $records ) {
			usort( $records, static fn ( array $a, array $b ): int => strcmp( $a['context'] . '|' . $a['language'], $b['context'] . '|' . $b['language'] ) );
			$out[ $owner ] = $records;
		}

		self::$cache = $out;

		return $out;
	}
}
