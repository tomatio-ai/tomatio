<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

/**
 * FIX2-strip: exposes the `ab_artifact_section` status a brain/section
 * block's status strip needs (owner, version, lease) to REST, read-only.
 *
 * Live bug fixed here: none of ab_section_owner / ab_version /
 * ab_lease_expires were registered for REST, so `useEntityBlockEditor`'s
 * `record.meta` never carried them and the block silently fell back to
 * defaults (owner "none", lease "Unclaimed", version 1) even when the DB
 * held real values -- a silent degraded UI with no indication anything was
 * wrong (the exact failure L26/"fail loud" exists to prevent).
 *
 * Read access to these values is already correctly scoped: a single
 * section's REST response is only served at all to its owner, its
 * artifact's lead, or a curator (ObjectAccess::mapMetaCap's 'read_post'
 * branch, enforced by core's REST controller before any field is
 * serialized) -- there is no separate per-field read check to add. What
 * this class adds is:
 *
 *   - show_in_rest for the three meta keys, each with `auth_callback`
 *     hard-wired to false so REST writes to them are always rejected
 *     (403 rest_cannot_update) -- versions/leases change only through
 *     ArtifactService (claim/release/updateSection), never by a client
 *     PATCHing meta directly.
 *   - a computed, read-only `ab_lease_holder` REST field (no
 *     update_callback at all, so it cannot be written via REST by
 *     construction) resolving the *current* `_edit_lock` holder, which is
 *     the lease claimant and can legitimately differ from the section's
 *     long-term ab_section_owner (see Lease::currentHolder's docblock).
 */
class StatusFields {

	public static function registerMeta(): void {
		if ( ! function_exists( 'register_post_meta' ) ) {
			return;
		}

		register_post_meta( Cpts::SECTION_POST_TYPE, Cpts::META_SECTION_OWNER, [
			'type'          => 'integer',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => '__return_false',
		] );

		register_post_meta( Cpts::SECTION_POST_TYPE, Cpts::META_VERSION, [
			'type'          => 'integer',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => '__return_false',
		] );

		register_post_meta( Cpts::SECTION_POST_TYPE, Cpts::META_LEASE_EXPIRES, [
			'type'          => 'integer',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => '__return_false',
		] );

		register_post_meta( Cpts::SECTION_POST_TYPE, Cpts::META_SECTION_STATE, [
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => '__return_false',
		] );
	}

	public static function registerRestFields(): void {
		if ( ! function_exists( 'register_rest_field' ) ) {
			return;
		}

		register_rest_field( Cpts::SECTION_POST_TYPE, 'ab_lease_holder', [
			'get_callback' => [ self::class, 'getLeaseHolder' ],
			// Deliberately no 'update_callback': this field can never be
			// written via REST, by construction, not by a permission check
			// that could be misconfigured.
			'schema'       => [
				'description' => __( 'User id currently holding the section edit lease, or null if unclaimed/expired.', 'agent-brain' ),
				'type'        => [ 'integer', 'null' ],
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
		] );
	}

	/**
	 * Deliberately does NOT collapse an expired lease's holder to null: the
	 * client needs to tell "never claimed" (no holder at all -> Unclaimed)
	 * apart from "claimed, but the lease timestamp has passed and nobody
	 * has reclaimed/released it yet" (holder still present -> Lease
	 * expired), which only the raw holder + ab_lease_expires pair together
	 * can express. See blocks/section/utils.js's getLeaseState for the
	 * client-side state machine this feeds.
	 *
	 * @param array<string, mixed> $object
	 */
	public static function getLeaseHolder( array $object ): ?int {
		$sectionId = isset( $object['id'] ) ? (int) $object['id'] : 0;
		if ( $sectionId <= 0 ) {
			return null;
		}

		// ART-14 (#128): a submitted or approved section has no lease to show
		// (see Lease::clear and the AdminUi lease column).
		$state = Stages::sectionState( $sectionId );
		if ( $state === Cpts::SECTION_STATE_SUBMITTED || $state === Cpts::SECTION_STATE_APPROVED ) {
			return null;
		}

		return Lease::currentHolder( $sectionId );
	}
}
