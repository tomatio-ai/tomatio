<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Voice;

use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\OAuth\DelegatedAuthority;
use AgentBrain\Modules\Provenance\ScopeGuard;

/**
 * #1002: who may read and change a voice profile.
 *
 * The owner is a WordPress user, a person or an agent. On a call, the
 * actor is the current user (an agent chosen with act-as is already the
 * current user while the tool runs) and the person is whoever is behind
 * it (TioDay::person(): AuthorizedBy, a role-switching or routed
 * connection, or a signed-in person).
 *
 * Read and write (relation()):
 *  - owner: the owner itself;
 *  - through: an agent working for the owner, either with the owner
 *    behind the call (act-as, a delegated token, a routed write) or one
 *    of the owner's own agents (issued by the owner) on its own;
 *  - lead: for an agent owner, the person who issued it, also through
 *    their own agents;
 *  - curator: the person behind the call (or the signed-in person) is a
 *    curator.
 *
 * Read only: anyone who may read the scope the profile is kept in
 * (canRead()). A profile in the owner's private scope adds nobody; one
 * kept in a team scope (a brand voice) is read by that scope's readers.
 *
 * Approve (approver()): readable fields an AI writes apply at once only
 * when a person decides on the call: the signed-in person, or the person
 * behind a connection with delegated authority (#629), and that person is
 * the owner, the owner's lead or a curator. Anything else waits as
 * pending until one of them approves it on their profile screen
 * (ProfileScreen) or with voice-set approve through such a connection.
 * Measurements (voice-learn) never wait: they are numbers, not words put
 * in someone's mouth.
 */
final class Access {

	public const OWNER   = 'owner';
	public const THROUGH = 'through';
	public const LEAD    = 'lead';
	public const CURATOR = 'curator';

	/** The current user (the agent, while an act-as tool runs). */
	public static function actor(): int {
		return (int) get_current_user_id();
	}

	/** The person behind the call, or 0 for an agent on its own. */
	public static function person(): int {
		return TioDay::person();
	}

	/** The owner a call means when it names nobody: the person behind it, else the caller. */
	public static function me(): int {
		$person = self::person();

		return $person > 0 ? $person : self::actor();
	}

	public static function isAgent( int $userId ): bool {
		$user = $userId > 0 ? get_userdata( $userId ) : false;

		return $user instanceof \WP_User && in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true );
	}

	/** The person who issued $agentId (its lead here), or 0. */
	public static function lead( int $agentId ): int {
		if ( ! self::isAgent( $agentId ) ) {
			return 0;
		}
		$issuer = (int) get_user_meta( $agentId, AgentMeta::ISSUED_BY, true );

		return $issuer > 0 && $issuer !== $agentId && ! self::isAgent( $issuer ) && get_userdata( $issuer ) instanceof \WP_User ? $issuer : 0;
	}

	/**
	 * Why $actor, with $person behind it, may act for $owner: OWNER,
	 * THROUGH, LEAD or CURATOR; '' when it may not.
	 */
	public static function relation( int $owner, int $actor, int $person ): string {
		if ( $owner <= 0 || $actor <= 0 ) {
			return '';
		}
		if ( $actor === $owner && ( $person <= 0 || $person === $owner ) ) {
			return self::OWNER;
		}
		if ( $person === $owner || $actor === $owner ) {
			return self::THROUGH;
		}
		if ( ! self::isAgent( $owner ) && in_array( $actor, ScopeGuard::ownAgents( $owner ), true ) ) {
			return self::THROUGH;
		}
		$lead = self::lead( $owner );
		if ( $lead > 0 && ( $actor === $lead || $person === $lead || in_array( $actor, ScopeGuard::ownAgents( $lead ), true ) ) ) {
			return self::LEAD;
		}
		$decider = $person > 0 ? $person : ( self::isAgent( $actor ) ? 0 : $actor );
		if ( $decider > 0 && ! self::isAgent( $decider ) && ObjectAccess::userIsCurator( $decider ) ) {
			return self::CURATOR;
		}

		return '';
	}

	/** Whether $actor (with $person behind it) may read a profile of $owner kept in $scopeId. */
	public static function canRead( int $owner, int $scopeId, int $actor, int $person ): bool {
		if ( self::relation( $owner, $actor, $person ) !== '' ) {
			return true;
		}

		return $scopeId > 0 && $actor > 0 && ( new Scopes() )->canRead( $actor, $scopeId );
	}

	public static function canWrite( int $owner, int $actor, int $person ): bool {
		return self::relation( $owner, $actor, $person ) !== '';
	}

	/**
	 * The person whose say-so applies readable fields on this call, or 0
	 * (they wait as pending): a signed-in person, or the person behind a
	 * connection with delegated authority, who is the owner, the owner's
	 * lead or a curator.
	 */
	public static function approver( int $owner, int $actor ): int {
		$decider = self::isAgent( $actor ) ? DelegatedAuthority::personFor( $actor ) : $actor;
		if ( $decider <= 0 || self::isAgent( $decider ) ) {
			return 0;
		}

		return $decider === $owner || $decider === self::lead( $owner ) || ObjectAccess::userIsCurator( $decider ) ? $decider : 0;
	}

	/**
	 * The owner's private scope: one the owner reads whose readers are
	 * only the owner, the owner's own agents and, for an agent owner, its
	 * lead and the lead's agents (the test ScopeGuard and the briefing use
	 * for a person's private scope). A scope marked as the owner's own
	 * (TioDayDefault::SCOPE_META) comes first. 0 when there is none.
	 */
	public static function homeScope( int $owner ): int {
		if ( $owner <= 0 ) {
			return 0;
		}
		$allowed = self::household( $owner );
		$readers = ScopeGuard::readerMap();
		$found   = [];
		foreach ( ( new Scopes() )->readScopeIds( $owner ) as $scopeId ) {
			$scopeId = (int) $scopeId;
			if ( self::onlyFor( $readers[ $scopeId ] ?? [], $allowed ) ) {
				$found[] = $scopeId;
			}
		}
		foreach ( $found as $scopeId ) {
			if ( (string) get_term_meta( $scopeId, \AgentBrain\Modules\DataStore\TioDayDefault::SCOPE_META, true ) === (string) $owner ) {
				return $scopeId;
			}
		}

		return $found[0] ?? 0;
	}

	/** Whether $scopeId is private to $owner by the homeScope() test. */
	public static function isPrivateTo( int $scopeId, int $owner ): bool {
		$readers = ScopeGuard::readerMap();

		return $scopeId > 0 && self::onlyFor( $readers[ $scopeId ] ?? [], self::household( $owner ) );
	}

	/**
	 * The owner, its own agents and (for an agent) its lead and the lead's agents.
	 *
	 * @return array<int, int>
	 */
	private static function household( int $owner ): array {
		$out  = array_merge( [ $owner ], ScopeGuard::ownAgents( $owner ) );
		$lead = self::lead( $owner );
		if ( $lead > 0 ) {
			$out = array_merge( $out, [ $lead ], ScopeGuard::ownAgents( $lead ) );
		}

		return array_values( array_unique( array_map( 'intval', $out ) ) );
	}

	/**
	 * @param array<int, int> $readers
	 * @param array<int, int> $allowed
	 */
	private static function onlyFor( array $readers, array $allowed ): bool {
		if ( $readers === [] ) {
			return false;
		}
		foreach ( $readers as $reader ) {
			if ( ! in_array( (int) $reader, $allowed, true ) ) {
				return false;
			}
		}

		return true;
	}
}
