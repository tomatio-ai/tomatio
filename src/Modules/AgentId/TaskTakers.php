<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentId;

use AgentBrain\Modules\DataStore\TioDayAssignee;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * #953: agents that take tasks. A curator marks an agent that runs
 * elsewhere (a bot such as Hermes Agent on a Raspberry Pi) as one people
 * give work to through TíoDay tasks, and gives it aliases, the other names
 * people call it. Any class may take tasks, observers included: the class
 * still says what the agent may write in its scopes.
 *
 * Only an agent that takes tasks can be a task's assignee (#949,
 * DataStore\TioDayAssignee), every connected AI that keeps a TíoDay is told
 * about these agents (DataStore\TioDay::instructions()), and act-as on one
 * of them answers with how to give it work instead (Mcp\Abilities).
 */
final class TaskTakers {

	/** User meta: '1' when the agent takes tasks. */
	public const META_FLAG = 'ab_agent_takes_tasks';

	/** User meta, one row per alias. */
	public const META_ALIAS = 'ab_agent_alias';

	public const MAX_ALIASES = 5;
	public const MAX_ALIAS   = 60;

	/** Agents the instructions name, at most. */
	private const LISTED = 10;

	/** Whether $agentId is an agent that takes tasks and may work (not suspended or revoked). */
	public static function takes( int $agentId ): bool {
		if ( ! TioDayAssignee::isAgent( $agentId ) || (string) get_user_meta( $agentId, self::META_FLAG, true ) !== '1' ) {
			return false;
		}

		return ! in_array( AgentIssuer::statusOf( $agentId ), [ AgentMeta::STATUS_SUSPENDED, AgentMeta::STATUS_REVOKED ], true );
	}

	/**
	 * The agent's aliases, as a curator wrote them.
	 *
	 * @return array<int, string>
	 */
	public static function aliases( int $agentId ): array {
		$rows = $agentId > 0 ? get_user_meta( $agentId, self::META_ALIAS, false ) : [];

		return array_values( array_filter( array_map( static fn ( $alias ): string => is_string( $alias ) ? $alias : '', is_array( $rows ) ? $rows : [] ), static fn ( string $alias ): bool => $alias !== '' ) );
	}

	/**
	 * Every agent that takes tasks, by name.
	 *
	 * @return array<int, \WP_User>
	 */
	public static function all(): array {
		$users = get_users(
			[
				'role'       => Capabilities::ROLE_AGENT,
				'meta_key'   => self::META_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of agents.
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => 'display_name',
				'order'      => 'ASC',
			]
		);

		return array_values( array_filter( $users, static fn ( $user ): bool => $user instanceof \WP_User && self::takes( (int) $user->ID ) ) );
	}

	/** The one agent that takes tasks that $name names (name, alias, login or agent ID); 0 for none. */
	public static function named( string $name ): int {
		foreach ( self::all() as $agent ) {
			if ( TioDayAssignee::isNamed( (int) $agent->ID, $name ) ) {
				return (int) $agent->ID;
			}
		}

		return 0;
	}

	/** What people call the agent: its nickname, else its display name. */
	public static function nameOf( int $agentId ): string {
		$nickname = trim( (string) get_user_meta( $agentId, 'nickname', true ) );
		$user     = get_userdata( $agentId );
		$login    = $user instanceof \WP_User ? (string) $user->user_login : '';
		$fqdn     = (string) get_user_meta( $agentId, AgentMeta::FQDN, true );
		if ( $nickname !== '' && $nickname !== $login && $nickname !== $fqdn ) {
			return $nickname;
		}

		return $user instanceof \WP_User ? (string) $user->display_name : '';
	}

	/**
	 * Mark an agent as taking tasks (or not) and set its aliases. True, or
	 * a WP_Error that says why not.
	 *
	 * @param array<int, string> $aliases
	 * @return true|\WP_Error
	 */
	public static function set( int $actingId, int $agentId, bool $takes, array $aliases ) {
		if ( $actingId <= 0 || ! user_can( $actingId, Capabilities::CAP_MANAGE ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'Only a curator may say which agents take tasks.', 'tomatio' ), [ 'status' => 403 ] );
		}
		if ( ! TioDayAssignee::isAgent( $agentId ) ) {
			return new \WP_Error( 'ab_not_an_agent', __( 'Not an agent.', 'tomatio' ), [ 'status' => 422 ] );
		}

		$clean = self::cleanAliases( $agentId, $aliases );
		if ( $clean instanceof \WP_Error ) {
			return $clean;
		}

		$before = [ 'takes' => self::takes( $agentId ), 'aliases' => self::aliases( $agentId ) ];
		if ( $takes ) {
			update_user_meta( $agentId, self::META_FLAG, '1' );
		} else {
			delete_user_meta( $agentId, self::META_FLAG );
		}
		delete_user_meta( $agentId, self::META_ALIAS );
		foreach ( $clean as $alias ) {
			add_user_meta( $agentId, self::META_ALIAS, $alias );
		}

		if ( $before !== [ 'takes' => self::takes( $agentId ), 'aliases' => $clean ] ) {
			\AgentBrain\Core\Hooks::action(
				'audit',
				[
					'actor_id'    => $actingId,
					'event'       => 'agent.tasks',
					'status'      => 'ok',
					'object_type' => 'user',
					'object_id'   => (string) $agentId,
					'meta'        => [ 'fqdn' => AgentIssuer::fqdnFor( $agentId ), 'takes_tasks' => $takes, 'aliases' => $clean ],
				]
			);
		}

		return true;
	}

	/**
	 * Aliases as stored: plain text, spaces collapsed, no duplicates
	 * (without case), none the agent's own name, none another account goes by.
	 *
	 * @param array<int, string> $aliases
	 * @return array<int, string>|\WP_Error
	 */
	private static function cleanAliases( int $agentId, array $aliases ) {
		$own   = TioDayAssignee::names( $agentId );
		$clean = [];
		$seen  = [];
		foreach ( $aliases as $alias ) {
			$alias = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $alias ) ) );
			$key   = TioDayAssignee::normalize( $alias );
			if ( $alias === '' || isset( $seen[ $key ] ) || in_array( $key, array_diff( $own, array_map( [ TioDayAssignee::class, 'normalize' ], self::aliases( $agentId ) ) ), true ) ) {
				continue;
			}
			if ( mb_strlen( $alias ) > self::MAX_ALIAS ) {
				/* translators: %d: the most characters an alias may have. */
				return new \WP_Error( 'ab_agent_alias_long', sprintf( __( 'An alias has at most %d characters.', 'tomatio' ), self::MAX_ALIAS ), [ 'status' => 422 ] );
			}
			if ( TioDayAssignee::takenByOthers( $alias, [ $agentId ] ) ) {
				/* translators: %s: the alias that was asked for. */
				return new \WP_Error( 'ab_agent_alias_taken', sprintf( __( 'Another account already goes by %s. Pick an alias nobody else has.', 'tomatio' ), $alias ), [ 'status' => 409 ] );
			}
			$seen[ $key ] = true;
			$clean[]      = $alias;
		}
		if ( count( $clean ) > self::MAX_ALIASES ) {
			/* translators: %d: the most aliases an agent may have. */
			return new \WP_Error( 'ab_agent_alias_many', sprintf( __( 'An agent has at most %d aliases.', 'tomatio' ), self::MAX_ALIASES ), [ 'status' => 422 ] );
		}

		return $clean;
	}

	/**
	 * The lines every AI that keeps a TíoDay is told about the agents that
	 * take tasks (not counting $exceptId, the caller); '' when there are none.
	 */
	public static function instructions( int $exceptId = 0 ): string {
		$agents = array_values( array_filter( self::all(), static fn ( \WP_User $agent ): bool => (int) $agent->ID !== $exceptId ) );
		if ( $agents === [] ) {
			return '';
		}

		$listed = [];
		foreach ( array_slice( $agents, 0, self::LISTED ) as $agent ) {
			$line    = self::nameOf( (int) $agent->ID );
			$aliases = self::aliases( (int) $agent->ID );
			if ( $aliases !== [] ) {
				/* translators: %s: comma-separated aliases. */
				$line .= ' ' . sprintf( __( '(also %s)', 'tomatio' ), implode( ', ', $aliases ) );
			}
			$about = trim( (string) $agent->description );
			if ( $about !== '' ) {
				$line .= ': ' . mb_substr( $about, 0, 120 );
			}
			$listed[] = $line;
		}

		return sprintf(
			/* translators: %s: the agents, such as "Obi-Wan (also Obi): builds the website". */
			__( 'Agents that take tasks on this brain: %s. They run on their own, outside this conversation. To give one of them work ("ask Obi-Wan to …"), add a TíoDay task with tomatio-tioday-task and who set to its name; it picks the task up, then closes it or comments on it, which shows on the TíoDay. Never act as it (tomatio-act-as) to do the work yourself.', 'tomatio' ),
			implode( '; ', $listed )
		);
	}

	/** The act-as refusal for an agent that takes tasks. */
	public static function actAsRefusal( int $agentId ): \WP_Error {
		$name = self::nameOf( $agentId );

		return new \WP_Error(
			'ab_act_as_takes_tasks',
			sprintf(
				/* translators: %s: the agent's name. */
				__( '%1$s takes tasks: it works on its own, outside this conversation, so acting as it would not reach it. To give %1$s work, add a TíoDay task with tomatio-tioday-task and who "%1$s"; it picks the task up, then closes it or comments on it.', 'tomatio' ),
				$name
			),
			[ 'status' => 409 ]
		);
	}
}
