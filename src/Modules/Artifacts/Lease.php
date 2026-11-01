<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

/**
 * ART-3: expiring section leases built on top of WordPress's own edit lock.
 *
 * `_edit_lock` is core's post meta key ("time:user_id") that wp-admin's own
 * "someone else is editing this" UI already understands — reusing it means
 * humans see our lease as the normal lock notice for free. We add
 * ab_lease_expires so the lease can be reclaimed automatically once stale,
 * which core's own lock (2-minute heartbeat-refreshed) does not support.
 */
class Lease {

	public const OPTION_LEASE_MINUTES  = 'ab_lease_minutes';
	public const DEFAULT_LEASE_MINUTES = 15;
	public const MAX_LEASE_MINUTES     = 1440;

	public static function leaseMinutes(): int {
		$minutes = (int) get_option( self::OPTION_LEASE_MINUTES, self::DEFAULT_LEASE_MINUTES );

		return $minutes > 0 ? $minutes : self::DEFAULT_LEASE_MINUTES;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function claim( int $actorId, int $sectionId ) {
		$holder = self::currentHolder( $sectionId );

		if ( $holder !== null && $holder !== $actorId && ! self::isExpired( $sectionId ) ) {
			return new \WP_Error(
				'ab_lease_held',
				__( 'This section is currently claimed by another agent.', 'agent-brain' ),
				[ 'status' => 409, 'held_by' => $holder ]
			);
		}

		update_post_meta( $sectionId, '_edit_lock', time() . ':' . $actorId );
		update_post_meta( $sectionId, Cpts::META_LEASE_EXPIRES, time() + ( self::leaseMinutes() * MINUTE_IN_SECONDS ) );

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function release( int $actorId, int $sectionId ) {
		$holder = self::currentHolder( $sectionId );

		if ( $holder !== null && $holder !== $actorId && ! ObjectAccess::userIsCurator( $actorId ) ) {
			return new \WP_Error(
				'ab_lease_not_held',
				__( 'You do not hold the lease on this section.', 'agent-brain' ),
				[ 'status' => 409 ]
			);
		}

		self::clear( $sectionId );

		return true;
	}

	/**
	 * Drops the lease whatever its holder. Used when a section leaves the
	 * drafting stage (submitted, approved): the edit lease no longer means
	 * anything there, so it must not keep showing as "Claimed by ...".
	 * ART-14 (#128).
	 */
	public static function clear( int $sectionId ): void {
		delete_post_meta( $sectionId, '_edit_lock' );
		delete_post_meta( $sectionId, Cpts::META_LEASE_EXPIRES );
	}

	/**
	 * §1.10 (artifact-sharing-design.md): narrow, additive guard checked at
	 * the top of ArtifactService::updateSection() — CAS on ab_version
	 * remains the real lost-update guard; this only stops a *different*
	 * actor from writing while someone else holds a live (non-expired)
	 * lease, returning a distinct, checkable error rather than a generic
	 * stale-version 409.
	 *
	 * @return true|\WP_Error
	 */
	public static function assertHeldByOrUnclaimed( int $sectionId, int $actorId ) {
		$holder = self::currentHolder( $sectionId );

		if ( $holder !== null && $holder !== $actorId && ! self::isExpired( $sectionId ) ) {
			return new \WP_Error(
				'ab_lease_conflict',
				__( 'This section is currently leased by another actor. Wait for the lease to expire or ask them to release it.', 'agent-brain' ),
				[ 'status' => 409, 'held_by' => $holder ]
			);
		}

		return true;
	}

	public static function isExpired( int $sectionId ): bool {
		$expires = (int) get_post_meta( $sectionId, Cpts::META_LEASE_EXPIRES, true );

		return $expires === 0 || $expires < time();
	}

	/**
	 * The user id currently holding `_edit_lock` on $sectionId, or null if
	 * unclaimed. Public so StatusFields (FIX2-strip) can surface the
	 * *lease* holder to REST separately from the section's long-term
	 * `ab_section_owner` -- claim() is owner-or-curator (see
	 * ArtifactService::claim -> ObjectAccess::canEditSection), so a curator
	 * claiming on an owner's behalf is a real case where holder != owner.
	 */
	public static function currentHolder( int $sectionId ): ?int {
		$lock = (string) get_post_meta( $sectionId, '_edit_lock', true );
		if ( $lock === '' ) {
			return null;
		}

		$parts = explode( ':', $lock );
		if ( count( $parts ) !== 2 ) {
			return null;
		}

		return (int) $parts[1];
	}
}
