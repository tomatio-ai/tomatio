<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\AgentId\TaskTakers;
use AgentBrain\Modules\Artifacts\Cpts;

/**
 * #970: what a TíoDay page needs to show that a task is with an agent.
 *
 * meta/page carries two server-written fields (meta is read-only for the
 * page, so it can't be faked from the page):
 *
 * - `agents`: every agent that takes tasks (#953), [{name, aliases, names}].
 *   `names` are all the names a task's `who` may use for it (display name,
 *   nickname, first name, login, agent ID, aliases), lowercased with spaces
 *   collapsed, as TioDayAssignee matches them, and only those no other
 *   account goes by. The page compares a task's `who` with them.
 * - `agent_seen`: {<task key>: {by, at}}, set the first time the agent
 *   reads its assigned tasks with that task in them (tomatio-tioday-assigned,
 *   or GET /tioday/assigned signed in as the agent; the feed token poll,
 *   which carries only a count and a digest, does not count).
 *
 * The list is written to every TíoDay when it changes: when an agent is
 * marked as taking tasks or not, its aliases, names or status change (user
 * meta hooks, once at shutdown), and once per plugin version. A fingerprint
 * in OPTION keeps that to real changes.
 */
final class TioDayAgents {

	/** SHA-256 of the list last written, and the plugin version it was written by. */
	public const OPTION = 'agent_brain_tioday_agents';

	/** At most this many seen marks are kept on a TíoDay (the newest). */
	public const MAX_SEEN = 300;

	/** User meta that changes the list. */
	private const KEYS = [ TaskTakers::META_FLAG, TaskTakers::META_ALIAS, AgentMeta::STATUS, AgentMeta::FQDN, 'nickname', 'first_name' ];

	private static bool $dirty = false;

	public static function register(): void {
		foreach ( [ 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'metaChanged' ], 10, 3 );
		}
		add_action( 'profile_update', [ self::class, 'dirty' ] );
		add_action( 'set_user_role', [ self::class, 'dirty' ] );
		add_action( 'deleted_user', [ self::class, 'dirty' ] );
		add_action( 'init', [ self::class, 'maybeSync' ], 32 );
		add_action( 'shutdown', [ self::class, 'flush' ] );
	}

	/**
	 * @param mixed $metaId
	 * @param mixed $userId
	 * @param mixed $key
	 */
	public static function metaChanged( $metaId, $userId, $key ): void {
		if ( is_string( $key ) && in_array( $key, self::KEYS, true ) ) {
			self::$dirty = true;
		}
	}

	public static function dirty(): void {
		self::$dirty = true;
	}

	/** At shutdown: write the list if an agent changed during this request. */
	public static function flush(): void {
		if ( self::$dirty ) {
			self::$dirty = false;
			self::sync();
			// #987: Magic Backlog's assignee picker lists the same agents.
			\AgentBrain\Modules\Backlog\Assignees::dirty();
		}
	}

	/** Once per plugin version: write the list (an upgraded brain gets it). */
	public static function maybeSync(): void {
		$version = defined( 'AGENT_BRAIN_VERSION' ) ? (string) AGENT_BRAIN_VERSION : '';
		$stored  = get_option( self::OPTION );
		if ( is_array( $stored ) && ( $stored['version'] ?? '' ) === $version ) {
			return;
		}
		self::sync( true );
	}

	/**
	 * The agents that take tasks, as meta/page.agents holds them.
	 *
	 * @return array<int, array{name: string, aliases: array<int, string>, names: array<int, string>}>
	 */
	public static function list(): array {
		$out = [];
		foreach ( TaskTakers::all() as $agent ) {
			$id    = (int) $agent->ID;
			$out[] = [
				'name'    => TaskTakers::nameOf( $id ),
				'aliases' => TaskTakers::aliases( $id ),
				'names'   => array_values( array_filter( TioDayAssignee::names( $id ), static fn ( string $n ): bool => ! TioDayAssignee::takenByOthers( $n, [ $id ] ) ) ),
			];
		}

		return $out;
	}

	/**
	 * Write the list into every TíoDay when it differs from the last one
	 * written (or always, with $force). Returns how many TíoDays changed.
	 */
	public static function sync( bool $force = false ): int {
		$list   = self::list();
		$print  = hash( 'sha256', (string) wp_json_encode( $list ) );
		$stored = get_option( self::OPTION );
		$same   = is_array( $stored ) && ( $stored['print'] ?? '' ) === $print;
		update_option( self::OPTION, [ 'print' => $print, 'version' => defined( 'AGENT_BRAIN_VERSION' ) ? (string) AGENT_BRAIN_VERSION : '' ], false );
		if ( $same && ! $force ) {
			return 0;
		}

		$changed = 0;
		foreach ( self::tiodays() as $artifactId ) {
			$page = StoreData::get( $artifactId, 'meta', 'page' );
			$have = $page !== null && is_array( $page['value'] ) ? ( $page['value']['agents'] ?? null ) : null;
			if ( $have === $list || ( $have === null && $list === [] ) ) {
				continue;
			}
			if ( self::merge( $artifactId, [ 'agents' => $list ] ) ) {
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * Mark the agent's tasks on a TíoDay as seen by it: the first time only,
	 * so an agent polling every few minutes writes nothing new.
	 *
	 * @param array<int, string> $keys Task keys the agent just read.
	 */
	public static function seen( int $artifactId, int $agentId, array $keys ): void {
		if ( $keys === [] ) {
			return;
		}
		$page = StoreData::get( $artifactId, 'meta', 'page' );
		$have = $page !== null && is_array( $page['value'] ) && is_array( $page['value']['agent_seen'] ?? null ) ? $page['value']['agent_seen'] : [];
		$name = TaskTakers::nameOf( $agentId );
		$now  = gmdate( 'Y-m-d\TH:i:s\Z' );
		$new  = false;
		foreach ( $keys as $key ) {
			$mark = $have[ $key ] ?? null;
			if ( is_array( $mark ) && ( $mark['by'] ?? '' ) === $name ) {
				continue;
			}
			$have[ $key ] = [ 'by' => $name, 'at' => $now ];
			$new          = true;
		}
		if ( ! $new ) {
			return;
		}
		if ( count( $have ) > self::MAX_SEEN ) {
			uasort( $have, static fn ( $a, $b ): int => strcmp( (string) ( $b['at'] ?? '' ), (string) ( $a['at'] ?? '' ) ) );
			$have = array_slice( $have, 0, self::MAX_SEEN, true );
		}
		self::merge( $artifactId, [ 'agent_seen' => $have ] );
	}

	/**
	 * Merge $fields into meta/page, keeping every other field. A page with
	 * no meta/page yet gets nothing: TioDay writes it with its owner first.
	 *
	 * @param array<string, mixed> $fields
	 */
	private static function merge( int $artifactId, array $fields ): bool {
		$page = StoreData::get( $artifactId, 'meta', 'page' );
		if ( $page === null || ! is_array( $page['value'] ) || array_is_list( $page['value'] ) ) {
			return false;
		}
		$stored = StoreData::set( $artifactId, 'meta', 'page', array_merge( $page['value'], $fields ), StoreData::anonymousAttribution(), null, (int) $page['version'] );

		return ! $stored instanceof \WP_Error;
	}

	/**
	 * Every TíoDay (a page with an owner), archived ones included.
	 *
	 * @return array<int, int>
	 */
	private static function tiodays(): array {
		$ids = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => [ 'publish', 'private', 'draft', 'pending' ],
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'meta_query'     => [ [ 'key' => HomeWorkspace::META_OWNER, 'compare' => 'EXISTS' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one row per person.
			]
		);

		return array_map( 'intval', $ids );
	}
}
