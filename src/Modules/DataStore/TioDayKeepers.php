<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Identity\Capabilities;
use WP_Error;

/**
 * #1133: agents a person names to keep their TíoDay, as themselves.
 *
 * Before, only a TíoDay's lead, a curator or a manager could change it, so
 * an agent that keeps someone's day for them (a chat agent that answers the
 * comments and closes what shipped) had to connect as the person and act as
 * the lead, and its work was credited to the lead.
 *
 * A keeper is stored as one post meta row per agent (META, the agent's user
 * ID), so the TíoDays an agent keeps are found with a plain meta query. It
 * is set through the artifact setting `tioday_keepers` (ArtifactSettings),
 * by the TíoDay's person, a curator or a manager, never by an agent on its
 * own; adding one takes the confirm step.
 *
 * A keeper passes TioDay::keeps(), for reads and writes, on its own
 * connection or when a person's connection acts as it, and so uses every
 * TíoDay tool. It does not manage the artifact: artifact-store-set, the
 * page's HTML, its sharing and its settings still follow
 * ShareLinks::canManage. A suspended or revoked agent keeps nothing.
 */
final class TioDayKeepers {

	public const META = 'ab_tioday_keeper';

	/** Keepers one TíoDay may have. */
	public const MAX = 10;

	/**
	 * The agent IDs stored as keepers of $artifactId, in the order added.
	 *
	 * @return array<int, int>
	 */
	public static function ids( int $artifactId ): array {
		if ( $artifactId <= 0 ) {
			return [];
		}

		$ids = array_map( 'intval', (array) get_post_meta( $artifactId, self::META, false ) );

		return array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );
	}

	/** Whether $agentId keeps $artifactId now: listed, an agent, and neither suspended nor revoked. */
	public static function keeps( int $artifactId, int $agentId ): bool {
		return $agentId > 0 && in_array( $agentId, self::ids( $artifactId ), true ) && self::active( $agentId );
	}

	/**
	 * The TíoDays $agentId keeps, as posts, newest first.
	 *
	 * @return array<int, \WP_Post>
	 */
	public static function kept( int $agentId ): array {
		if ( ! self::active( $agentId ) ) {
			return [];
		}

		return TioDay::candidates(
			[
				[ 'key' => self::META, 'value' => (string) $agentId ],
				[ 'key' => HomeWorkspace::META_OWNER, 'compare' => 'EXISTS' ],
			]
		);
	}

	/**
	 * The setting's value: the keepers' logins, sorted. A keeper whose
	 * account is gone is left out.
	 *
	 * @return array<int, string>
	 */
	public static function logins( int $artifactId ): array {
		$out = [];
		foreach ( self::ids( $artifactId ) as $id ) {
			$user = get_userdata( $id );
			if ( $user instanceof \WP_User ) {
				$out[] = (string) $user->user_login;
			}
		}
		sort( $out );

		return $out;
	}

	/**
	 * The setting as given (a list of names, logins, agent IDs or user IDs,
	 * or one comma-separated string) as sorted logins, or a WP_Error naming
	 * what could not be matched to exactly one agent.
	 *
	 * @param mixed $value
	 * @return array<int, string>|WP_Error
	 */
	public static function normalize( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( trim( $value ), true );
			$value   = is_array( $decoded ) ? $decoded : ( trim( $value ) === '' ? [] : explode( ',', $value ) );
		}
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'ab_settings_invalid', 'a list of agents (names, logins or agent IDs)' );
		}

		$logins = [];
		foreach ( $value as $one ) {
			if ( ! is_string( $one ) && ! is_int( $one ) ) {
				return new WP_Error( 'ab_settings_invalid', 'a list of agents (names, logins or agent IDs)' );
			}
			$agent = self::find( (string) $one );
			if ( $agent instanceof WP_Error ) {
				return $agent;
			}
			$user = get_userdata( $agent );
			if ( $user instanceof \WP_User ) {
				$logins[ (string) $user->user_login ] = true;
			}
		}

		$logins = array_keys( $logins );
		if ( count( $logins ) > self::MAX ) {
			/* translators: %d: the most keepers a TíoDay may have. */
			return new WP_Error( 'ab_settings_invalid', sprintf( __( 'at most %d agents', 'tomatio' ), self::MAX ) );
		}
		sort( $logins );

		return array_map( 'strval', $logins );
	}

	/**
	 * Replace the keepers of $artifactId with the agents of $logins (as
	 * normalize() gives them). ArtifactSettings checks who may and records
	 * the change.
	 *
	 * @param array<int, string> $logins
	 * @return true|WP_Error
	 */
	public static function set( int $artifactId, array $logins, int $actorId ) {
		unset( $actorId );
		if ( ! self::isTioDay( $artifactId ) ) {
			return self::notTioDay();
		}

		$ids = [];
		foreach ( $logins as $login ) {
			$user = get_user_by( 'login', (string) $login );
			if ( ! $user instanceof \WP_User || ! TioDayAssignee::isAgent( (int) $user->ID ) ) {
				/* translators: %s: a login. */
				return new WP_Error( 'ab_settings_invalid', sprintf( __( '%s is not an agent on this brain.', 'tomatio' ), (string) $login ), [ 'status' => 400 ] );
			}
			$ids[] = (int) $user->ID;
		}

		delete_post_meta( $artifactId, self::META );
		foreach ( array_values( array_unique( $ids ) ) as $id ) {
			add_post_meta( $artifactId, self::META, (string) $id );
		}

		return true;
	}

	/**
	 * Who may change the keepers: on a TíoDay, its person, a curator or a
	 * manager; never an agent on its own (on a person's connection that acts
	 * as an agent, ArtifactSettings::decider() is the person).
	 */
	public static function canChange( int $actorId, int $artifactId ): bool {
		if ( $actorId <= 0 || ! self::isTioDay( $artifactId ) || Capabilities::isPureAgent( $actorId ) ) {
			return false;
		}

		return (int) get_post_meta( $artifactId, HomeWorkspace::META_OWNER, true ) === $actorId || StoreMode::canToggle( $actorId );
	}

	/**
	 * Whether $new names an agent $old does not: it lets one more agent
	 * write the TíoDay, so it takes the confirm step.
	 *
	 * @param mixed $old
	 * @param mixed $new
	 */
	public static function widens( $old, $new ): bool {
		return array_diff( (array) $new, (array) $old ) !== [];
	}

	/** Whether $artifactId is a TíoDay: an artifact pinned to a person. */
	public static function isTioDay( int $artifactId ): bool {
		return $artifactId > 0 && (int) get_post_meta( $artifactId, HomeWorkspace::META_OWNER, true ) > 0;
	}

	public static function notTioDay(): WP_Error {
		return new WP_Error( 'ab_settings_invalid', __( 'tioday_keepers applies to TíoDays only (a page pinned to a person), and this artifact is not one.', 'tomatio' ), [ 'status' => 400 ] );
	}

	/** Whether $agentId is an agent that is neither suspended nor revoked. */
	private static function active( int $agentId ): bool {
		return TioDayAssignee::isAgent( $agentId )
			&& ! in_array( AgentIssuer::statusOf( $agentId ), [ AgentMeta::STATUS_SUSPENDED, AgentMeta::STATUS_REVOKED ], true );
	}

	/**
	 * The one agent $name means: its user ID, login, display name, nickname,
	 * first name, agent ID or alias (TioDayAssignee::names()).
	 *
	 * @return int|WP_Error
	 */
	private static function find( string $name ) {
		$name = trim( $name );
		if ( $name === '' ) {
			return new WP_Error( 'ab_settings_invalid', __( 'an agent\'s name, login or agent ID, not an empty string', 'tomatio' ) );
		}

		if ( ctype_digit( $name ) && TioDayAssignee::isAgent( (int) $name ) ) {
			return (int) $name;
		}

		$user = get_user_by( 'login', $name );
		if ( $user instanceof \WP_User && TioDayAssignee::isAgent( (int) $user->ID ) ) {
			return (int) $user->ID;
		}

		$wanted  = TioDayAssignee::normalize( $name );
		$matches = [];
		foreach ( get_users( [ 'role' => Capabilities::ROLE_AGENT, 'fields' => 'ID', 'number' => 500 ] ) as $id ) {
			if ( in_array( $wanted, TioDayAssignee::names( (int) $id ), true ) ) {
				$matches[] = (int) $id;
			}
		}

		if ( count( $matches ) === 1 ) {
			return $matches[0];
		}

		return new WP_Error(
			'ab_settings_invalid',
			$matches === []
				/* translators: %s: the name given. */
				? sprintf( __( 'no agent goes by %s', 'tomatio' ), $name )
				/* translators: %s: the name given. */
				: sprintf( __( 'several agents go by %s: use its login or agent ID', 'tomatio' ), $name )
		);
	}
}
