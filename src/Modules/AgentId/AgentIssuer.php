<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentId;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\OAuth\Delegation;
use AgentBrain\Modules\OAuth\TokenStore;
use AgentBrain\Modules\Portability\Principals;

/**
 * Issues and manages agent IDs (docs/rfc/agent-id-fqdn.md sections 2-5).
 * WordPress is the registry: the FQDN and its lifecycle live in user meta.
 */
final class AgentIssuer {

	private const MAX_ID_ATTEMPTS = 8;

	/** Agent name used when nothing about the account makes a valid one. */
	public const FALLBACK_NAME = 'agent';

	/** While true the user_register / set_user_role hooks do not auto-issue. */
	private static bool $deferAuto = false;

	/**
	 * Pure: does this stored status let the agent in? A missing status
	 * (legacy agent, not yet migrated) counts as active.
	 */
	public static function statusAllowsAccess( ?string $status ): bool {
		return $status === null || $status === '' || $status === AgentMeta::STATUS_ACTIVE;
	}

	/**
	 * Pure: first problem with the issue inputs, as an error code, or null.
	 */
	public static function validateInput( string $agentName, string $class ): ?string {
		if ( ! Fqdn::isValidName( $agentName ) ) {
			return 'ab_invalid_agent_name';
		}

		if ( ! in_array( $class, AgentMeta::CLASSES, true ) ) {
			return 'ab_invalid_agent_class';
		}

		return null;
	}

	public static function deferAutoIssue( bool $defer ): void {
		self::$deferAuto = $defer;
	}

	public static function autoIssueDeferred(): bool {
		return self::$deferAuto;
	}

	/**
	 * @return string|\WP_Error The FQDN.
	 */
	public function issue( int $userId, string $agentName, string $class, int $issuedBy, ?string $registerId = null ) {
		$problem = self::validateInput( $agentName, $class );
		if ( $problem !== null ) {
			return new \WP_Error( $problem, 'Invalid agent name or class.', [ 'status' => 422 ] );
		}

		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User || ! in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			return new \WP_Error( 'ab_not_an_agent', 'Only users with the ab_agent role get an agent ID.', [ 'status' => 422 ] );
		}

		// People (administrators, curators, mixed accounts) never get an agent ID.
		if ( ! Delegation::isPureAgent( $user ) ) {
			return new \WP_Error( 'ab_not_an_agent', 'Administrators and curators do not get an agent ID.', [ 'status' => 422 ] );
		}

		if ( self::fqdnFor( $userId ) !== null ) {
			return new \WP_Error( 'ab_already_issued', 'This agent already has an ID.', [ 'status' => 409 ] );
		}

		// An agent suspended or revoked before it had an ID (#233) keeps that
		// status; a revoked one never gets an ID.
		$status = self::statusOf( $userId );
		if ( $status === AgentMeta::STATUS_REVOKED ) {
			return new \WP_Error( 'ab_revoked', 'This agent was revoked.', [ 'status' => 409 ] );
		}

		$org  = AgentMeta::org();
		$base = AgentMeta::base();

		if ( $registerId !== null ) {
			if ( ! Fqdn::isValidRegisterId( $registerId ) ) {
				return new \WP_Error( 'ab_invalid_register_id', 'Invalid register id.', [ 'status' => 422 ] );
			}
			$attempts = 1;
		} else {
			$attempts = self::MAX_ID_ATTEMPTS;
		}

		$fqdn     = null;
		$chosenId = null;
		for ( $i = 0; $i < $attempts; $i++ ) {
			$candidateId = $registerId ?? Fqdn::generateRegisterId();
			$candidate   = Fqdn::build( $agentName, $candidateId, $org, $base );
			if ( $candidate === null ) {
				return new \WP_Error( 'ab_invalid_fqdn', 'Could not build a valid agent ID from these parts.', [ 'status' => 422 ] );
			}
			// Never reissued: the registry also holds the FQDN of deleted users.
			if ( self::userIdByFqdn( $candidate ) === null && Principals::byFqdn( $candidate ) === null ) {
				$fqdn     = $candidate;
				$chosenId = $candidateId;
				break;
			}
		}

		if ( $fqdn === null || $chosenId === null ) {
			return new \WP_Error( 'ab_fqdn_taken', 'That agent ID is already in use.', [ 'status' => 409 ] );
		}

		update_user_meta( $userId, AgentMeta::FQDN, $fqdn );
		update_user_meta( $userId, AgentMeta::REGISTER_ID, $chosenId );
		update_user_meta( $userId, AgentMeta::CLASS_NAME, $class );
		update_user_meta( $userId, AgentMeta::STATUS, $status ?? AgentMeta::STATUS_ACTIVE );
		update_user_meta( $userId, AgentMeta::ISSUED_BY, $issuedBy );
		update_user_meta( $userId, AgentMeta::ISSUED_AT, gmdate( 'Y-m-d\TH:i:s\Z' ) );

		wp_update_user( [ 'ID' => $userId, 'display_name' => $fqdn ] );

		self::audit( $issuedBy, 'agent.issue', $userId, [ 'fqdn' => $fqdn, 'class' => $class ] );

		return $fqdn;
	}

	/**
	 * Who issued the agent and when, for display. A missing or zero issuer is
	 * the system (the migration or a CLI/auto issue), never a blank.
	 *
	 * @return array{by:string, at:string}
	 */
	public static function issuedInfo( int $userId ): array {
		$by   = (int) get_user_meta( $userId, AgentMeta::ISSUED_BY, true );
		$at   = (string) get_user_meta( $userId, AgentMeta::ISSUED_AT, true );
		$user = $by > 0 ? get_userdata( $by ) : false;

		if ( $user instanceof \WP_User ) {
			$who = (string) $user->display_name;
		} else {
			$who = $by > 0 ? '#' . $by : 'system (migration)';
		}

		return [ 'by' => $who, 'at' => $at !== '' ? $at : '-' ];
	}

	public static function fqdnFor( int $userId ): ?string {
		$value = get_user_meta( $userId, AgentMeta::FQDN, true );

		return is_string( $value ) && $value !== '' ? $value : null;
	}

	/**
	 * Stored status; null when the user has no stored status.
	 */
	public static function statusOf( int $userId ): ?string {
		$value = get_user_meta( $userId, AgentMeta::STATUS, true );

		return is_string( $value ) && $value !== '' ? $value : null;
	}

	public const BLOCKED_NOT_ACTIVE = 'not_active';
	public const BLOCKED_NOT_ISSUED = 'not_issued';

	/**
	 * True when this agent may not sign in: see blockReason(). People are
	 * never blocked.
	 */
	public static function isBlocked( int $userId ): bool {
		return self::blockReason( $userId ) !== null;
	}

	/**
	 * Why an agent (ab_agent role, not a person) may not sign in, or null:
	 * its status is not active (with or without an ID), or it has no agent
	 * ID yet (#233). Before #233 an agent without an ID had no status and
	 * passed every check.
	 */
	public static function blockReason( int $userId ): ?string {
		if ( $userId <= 0 ) {
			return null;
		}

		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User || ! Delegation::isPureAgent( $user ) ) {
			return null;
		}

		if ( ! self::statusAllowsAccess( self::statusOf( $userId ) ) ) {
			return self::BLOCKED_NOT_ACTIVE;
		}

		return self::fqdnFor( $userId ) === null && self::requireAgentId() ? self::BLOCKED_NOT_ISSUED : null;
	}

	/**
	 * Whether an agent needs an ID to sign in (default yes). The integration
	 * suite turns it off with auto-issue, for suites that create agents
	 * without IDs and are not about identity.
	 */
	public static function requireAgentId(): bool {
		return (bool) apply_filters( 'agent_brain/require_agent_id', true );
	}

	public static function userIdByFqdn( string $fqdn ): ?int {
		$fqdn = Fqdn::normalize( $fqdn );
		if ( $fqdn === '' ) {
			return null;
		}

		$ids = get_users( [
			'meta_key'   => AgentMeta::FQDN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- exact-match lookup of the registry key.
			'meta_value' => $fqdn, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'     => 'ID',
			'number'     => 1,
		] );

		return $ids === [] ? null : (int) $ids[0];
	}

	/**
	 * @return true|\WP_Error
	 */
	public function setStatus( int $userId, string $status, int $actingId, string $reason = '' ) {
		if ( ! in_array( $status, AgentMeta::STATUSES, true ) ) {
			return new \WP_Error( 'ab_invalid_status', 'Unknown status.', [ 'status' => 422 ] );
		}

		// Every agent can be suspended and revoked, with or without an ID (#233).
		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User || ! in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			return new \WP_Error( 'ab_not_an_agent', 'Only agents have a status.', [ 'status' => 422 ] );
		}

		$fqdn = self::fqdnFor( $userId );

		$from = self::statusOf( $userId ) ?? AgentMeta::STATUS_ACTIVE;
		if ( ! AgentMeta::canTransition( $from, $status ) ) {
			return new \WP_Error( 'ab_invalid_transition', sprintf( 'Cannot change status from %s to %s.', $from, $status ), [ 'status' => 409 ] );
		}

		update_user_meta( $userId, AgentMeta::STATUS, $status );

		// Suspension is immediate on every route (#234): sessions already
		// open end now; SignInGate refuses new ones.
		if ( $status !== AgentMeta::STATUS_ACTIVE && class_exists( '\WP_Session_Tokens' ) ) {
			\WP_Session_Tokens::get_instance( $userId )->destroy_all();
		}

		if ( $status === AgentMeta::STATUS_REVOKED ) {
			self::revokeCredentials( $userId );
		}

		self::audit( $actingId, 'agent.status', $userId, [
			'fqdn'   => $fqdn,
			'login'  => (string) $user->user_login,
			'from'   => $from,
			'to'     => $status,
			'reason' => sanitize_text_field( $reason ),
		] );

		return true;
	}

	private static function revokeCredentials( int $userId ): void {
		if ( class_exists( '\WP_Application_Passwords' ) ) {
			\WP_Application_Passwords::delete_all_application_passwords( $userId );
		}

		delete_user_meta( $userId, Scopes::META_READ_SCOPES );
		delete_user_meta( $userId, Scopes::META_WRITE_SCOPES );

		foreach ( TokenStore::grantsForUser( $userId ) as $grant ) {
			TokenStore::revokeGrantForUser( $userId, (string) $grant['family_id'] );
		}
	}

	/**
	 * Issue an ID for an agent user from a name hint, its login or display
	 * name, or FALLBACK_NAME. Returns the FQDN, or null when it could not be
	 * issued; the agent then stays "unissued", cannot sign in, and the admin
	 * page offers to issue it.
	 */
	public function issueFromUser( int $userId, int $issuedBy, string $class = AgentMeta::CLASS_EXECUTOR, ?string $nameHint = null ): ?string {
		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User || self::fqdnFor( $userId ) !== null ) {
			return null;
		}

		$name = null;
		foreach ( [ $nameHint, (string) $user->user_login, (string) $user->display_name ] as $source ) {
			if ( $source !== null && $source !== '' ) {
				$name = Fqdn::slug( $source );
				if ( $name !== null ) {
					break;
				}
			}
		}

		// A login or name that cannot become a valid name (for example "QA",
		// too short) still gets an ID: the register id makes it unique (#233).
		$name ??= self::FALLBACK_NAME;

		$result = $this->issue( $userId, $name, $class, $issuedBy );

		return is_string( $result ) ? $result : null;
	}

	/**
	 * The agent_name for a free-text name ("Sound designer"): its slug, or
	 * FALLBACK_NAME when nothing valid can be made of it.
	 */
	public static function nameFor( string $display ): string {
		return Fqdn::slug( $display ) ?? self::FALLBACK_NAME;
	}

	/**
	 * A register id for a new agent called $agentName whose FQDN and login
	 * (the FQDN's first label) are both free, or null. Creation paths pick
	 * it before the account exists, so the login is the first label from
	 * the start (#232, RFC section 3).
	 */
	public static function freeRegisterId( string $agentName ): ?string {
		$org  = AgentMeta::org();
		$base = AgentMeta::base();

		for ( $i = 0; $i < self::MAX_ID_ATTEMPTS; $i++ ) {
			$registerId = Fqdn::generateRegisterId();
			$fqdn       = Fqdn::build( $agentName, $registerId, $org, $base );
			if ( $fqdn === null ) {
				return null;
			}

			$login = (string) Fqdn::login( $fqdn );
			if ( self::userIdByFqdn( $fqdn ) === null && Principals::byFqdn( $fqdn ) === null
				&& username_exists( $login ) === false && ! Principals::loginReserved( $login ) ) {
				return $registerId;
			}
		}

		return null;
	}

	/**
	 * Pure agents (role ab_agent, not people) that still have no ID.
	 *
	 * @return int[]
	 */
	public static function unissuedAgentIds(): array {
		$ids = get_users( [ 'role' => Capabilities::ROLE_AGENT, 'fields' => 'ID' ] );

		return array_values( array_filter(
			array_map( 'intval', $ids ),
			static function ( int $id ): bool {
				$user = get_userdata( $id );

				return $user instanceof \WP_User && self::fqdnFor( $id ) === null && Delegation::isPureAgent( $user );
			}
		) );
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function audit( int $actorId, string $event, int $userId, array $meta ): void {
		do_action( 'agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => $event,
			'status'      => 'ok',
			'object_type' => 'user',
			'object_id'   => (string) $userId,
			'meta'        => $meta,
		] );
	}
}
