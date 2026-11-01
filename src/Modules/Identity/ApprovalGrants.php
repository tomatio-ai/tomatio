<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

/**
 * Issue #252: the manager-agent approval grant store. A curator
 * (ab_manage_artifacts) may grant a specific ab_agent the right to act as a
 * delegated reviewer for the artifact pipeline: deliver section-review
 * verdicts and trigger artifact assembly. The grant is deliberately NOT a
 * curator-lite: it never extends to publish/unpublish/archive, knowledge or
 * procedure review, or the wp-admin artifact screens, and it does not lift
 * the "cannot review a section you own" rule (#152 stands).
 *
 * The grant flag itself is a single user-meta key (ab_approval_grant = '1').
 * It carries no scope list of its own: the scoping is dynamic, enforced at
 * check time in Artifacts\ObjectAccess::userCanReviewAndAssemble() (grant +
 * WRITE on the artifact's scope), so revoking a scope write grant — or this
 * grant — takes effect immediately with no secondary state to sync.
 *
 * Mirrors Scopes: fail closed everywhere, every grant/revoke is audited,
 * and the acting user's authority is re-checked inside the service rather
 * than trusted from any caller.
 */
class ApprovalGrants {

	/** User-meta key; the string '1' means granted, anything else (including absent) means not. */
	public const META_GRANT = 'ab_approval_grant';

	public function hasGrant( int $userId ): bool {
		if ( $userId <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return false;
		}

		try {
			return (string) get_user_meta( $userId, self::META_GRANT, true ) === '1';
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Grant the approval grant to an agent. The acting user must hold
	 * ab_manage_artifacts and the target must be an ab_agent. Emits
	 * agent_brain/audit either way; fails closed (returns false).
	 */
	public function grant( int $actingUserId, int $targetAgentId ): bool {
		return $this->mutate( 'grant', $actingUserId, $targetAgentId );
	}

	public function revoke( int $actingUserId, int $targetAgentId ): bool {
		return $this->mutate( 'revoke', $actingUserId, $targetAgentId );
	}

	private function mutate( string $action, int $actingUserId, int $targetAgentId ): bool {
		if ( ! $this->actingUserCanGrant( $actingUserId ) ) {
			$this->audit( $action, $actingUserId, $targetAgentId, 'denied' );

			return false;
		}

		if ( ! $this->targetIsAgent( $targetAgentId ) ) {
			$this->audit( $action, $actingUserId, $targetAgentId, 'error' );

			return false;
		}

		if ( $action === 'revoke' ) {
			$updated = function_exists( 'delete_user_meta' )
				? delete_user_meta( $targetAgentId, self::META_GRANT )
				: false;

			// delete_user_meta() returns false when the meta was already
			// absent; an absent grant after a revoke is the desired end
			// state, so re-read instead of treating "unchanged" as failure.
			$updated = ! $this->hasGrant( $targetAgentId );
		} else {
			$updated = function_exists( 'update_user_meta' )
				? update_user_meta( $targetAgentId, self::META_GRANT, '1' )
				: false;

			if ( $updated === false && function_exists( 'update_user_meta' ) ) {
				// update_user_meta() returns false both on failure and when
				// the stored value is unchanged; treat "unchanged" as success
				// by re-reading, the same pattern Scopes::mutate() uses.
				$updated = $this->hasGrant( $targetAgentId );
			}
		}

		$this->audit( $action, $actingUserId, $targetAgentId, $updated ? 'ok' : 'error' );

		return (bool) $updated;
	}

	private function actingUserCanGrant( int $actingUserId ): bool {
		if ( $actingUserId <= 0 || ! function_exists( 'user_can' ) ) {
			return false;
		}

		try {
			return (bool) user_can( $actingUserId, Capabilities::CAP_MANAGE_ARTIFACTS );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private function targetIsAgent( int $targetAgentId ): bool {
		if ( $targetAgentId <= 0 || ! function_exists( 'get_userdata' ) ) {
			return false;
		}

		$user = get_userdata( $targetAgentId );

		return $user instanceof \WP_User && in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true );
	}

	private function audit( string $action, int $actingUserId, int $targetAgentId, string $status ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action( 'agent_brain/audit', [
			'actor_id'    => $actingUserId,
			'event'       => 'approval_' . $action,
			'status'      => $status,
			'object_type' => 'user',
			'object_id'   => (string) $targetAgentId,
			'meta'        => [],
		] );
	}
}
