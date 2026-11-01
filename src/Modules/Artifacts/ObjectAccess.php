<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Modules\Identity\ApprovalGrants;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;

/**
 * ART-2: object-level capability checks for ab_artifact / ab_artifact_section.
 *
 * Rather than relying on post_author + WordPress's built-in "own vs others"
 * primitive caps (which would require every reassignment of a lead agent or
 * section owner to also rewrite post_author), ownership is read straight
 * from the ab_lead_agent / ab_section_owner postmeta on every check. A
 * curator (ab_manage_artifacts) always passes. Every other combination is
 * denied outright via 'do_not_allow' — never a silent fallback.
 *
 * This is wired through the core 'map_meta_cap' filter, so it governs both
 * wp-admin's own post editing screens (map_meta_cap=true on the CPTs) and
 * every ArtifactService call (which uses user_can($actorId, ...) — object
 * level, explicit actor, never the ambient current user).
 */
class ObjectAccess {

	private const ALLOW = [ 'read' ];
	private const DENY  = [ 'do_not_allow' ];

	private const META_CAPS = [ 'edit_post', 'delete_post', 'read_post' ];

	/**
	 * @param string[] $caps
	 * @param array<int, mixed> $args
	 * @return string[]
	 */
	public static function mapMetaCap( array $caps, string $cap, int $userId, array $args ): array {
		if ( ! in_array( $cap, self::META_CAPS, true ) ) {
			return $caps;
		}

		$postId = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $postId <= 0 ) {
			return $caps;
		}

		$post = get_post( $postId );
		if ( ! $post ) {
			return $caps;
		}

		if ( $post->post_type === Cpts::ARTIFACT_POST_TYPE ) {
			// Artifact fix wave (2026-09-27): delete is denied unconditionally —
			// including for curators — on every surface (wp-admin, REST, any
			// future caller) for THIS post type only. Per Cpts.php's
			// docblock, nobody deletes either post type through the generic
			// controller; ArtifactService itself exposes no delete method at
			// all, and outline integrity
			// (ArtifactService::parseOutline/serializeOutline) assumes
			// sections are never removed out from under a
			// compiled/compiling artifact.
			// A prior revision of this check returned ALLOW (['read']) for
			// curators here, which — because 'read' is a capability every
			// logged-in user holds — granted every curator a real hard-delete
			// via DELETE /wp/v2/ab-artifact-sections/{id}. Do not reintroduce
			// that without also adding an ArtifactService-mediated delete path
			// that keeps the outline consistent.
			// This check is scoped inside the ARTIFACT_POST_TYPE branch
			// deliberately: mapMetaCap is registered as a global (unscoped)
			// 'map_meta_cap' filter in Module.php, so a delete_post denial
			// placed before the post_type check would fire for every post on
			// the entire site, not just this CPT.
			if ( $cap === 'delete_post' ) {
				return self::decide( false );
			}

			if ( $cap === 'read_post' ) {
				// Fix wave A1: read_post on an artifact is granted to the
				// same audience as artifact-status — curator, lead, or the
				// owner of ANY section of the artifact — so
				// artifact-get-outline works for section owners instead of
				// denying them while artifact-status lists the very
				// sections they own. Edit/delete stay curator/lead only,
				// below. Issue #252: a manager-agent holding the curator-
				// granted approval grant (Identity\ApprovalGrants) plus WRITE
				// on this artifact's scope may also read it — a reviewer who
				// cannot read the outline cannot review.
				return self::decide(
					self::userIsCurator( $userId ) || self::userIsLead( $userId, $postId ) || self::userOwnsAnySection( $userId, $postId ) || self::userCanReviewAndAssemble( $userId, $postId )
				);
			}

			return self::decide( self::userIsCurator( $userId ) || self::userIsLead( $userId, $postId ) );
		}

		if ( $post->post_type === Cpts::SECTION_POST_TYPE ) {
			// Same rationale as the ARTIFACT_POST_TYPE branch above: delete
			// is denied unconditionally for sections too, scoped to this CPT
			// only.
			if ( $cap === 'delete_post' ) {
				return self::decide( false );
			}

			if ( $cap === 'read_post' ) {
				// REST contract: a section may be read by its owner, the lead
				// of its parent artifact, or a curator. Edit stays
				// owner/curator only (below) — the lead does not get write
				// access to a section they don't own via this path.
				return self::decide( self::canReadSection( $userId, $postId ) );
			}

			return self::decide( self::userIsCurator( $userId ) || self::userIsOwner( $userId, $postId ) );
		}

		return $caps;
	}

	/**
	 * @return string[]
	 */
	private static function decide( bool $allowed ): array {
		return $allowed ? self::ALLOW : self::DENY;
	}

	public static function userIsCurator( int $userId ): bool {
		if ( ! function_exists( 'user_can' ) ) {
			return false;
		}

		try {
			return (bool) user_can( $userId, Capabilities::CAP_MANAGE_ARTIFACTS );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function userIsLead( int $userId, int $artifactId ): bool {
		if ( $userId <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}

		return $userId === (int) get_post_meta( $artifactId, Cpts::META_LEAD_AGENT, true );
	}

	/**
	 * #632: the person behind the artifact's lead agent: the person who
	 * issued that agent (AgentMeta::ISSUED_BY), never the agent itself and
	 * never another agent.
	 */
	public static function userIsBehindLead( int $userId, int $artifactId ): bool {
		if ( $userId <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}

		$leadId = (int) get_post_meta( $artifactId, Cpts::META_LEAD_AGENT, true );
		if ( $leadId <= 0 || $leadId === $userId || (int) get_user_meta( $leadId, \AgentBrain\Modules\AgentId\AgentMeta::ISSUED_BY, true ) !== $userId ) {
			return false;
		}

		$user = get_userdata( $userId );

		return $user instanceof \WP_User && ! \AgentBrain\Modules\OAuth\Delegation::isPureAgent( $user );
	}

	public static function userIsOwner( int $userId, int $sectionId ): bool {
		if ( $userId <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}

		return $userId === (int) get_post_meta( $sectionId, Cpts::META_SECTION_OWNER, true );
	}

	/**
	 * Whether $userId may edit the outline of $artifactId: the lead agent or
	 * a curator. Deliberately independent of read_post/edit_post so callers
	 * that need "outline rights" specifically (reorder, assign) get one
	 * unambiguous check rather than relying on the generic meta cap name.
	 */
	public static function canEditOutline( int $userId, int $artifactId ): bool {
		return self::userIsCurator( $userId ) || self::userIsLead( $userId, $artifactId );
	}

	public static function canEditSection( int $userId, int $sectionId ): bool {
		return self::userIsCurator( $userId ) || self::userIsOwner( $userId, $sectionId );
	}

	/**
	 * Artifact fix wave A1 (+ issue #252): a section may be READ (not edited)
	 * by its owner, a curator, the lead agent of its parent artifact, or a
	 * manager-agent holding the approval grant with WRITE on the artifact's
	 * scope — one helper shared
	 * by both the 'read_post' meta-cap branch above (REST) and
	 * ArtifactService::getSection() (MCP), so the two surfaces never drift
	 * out of sync again. Live evidence: the lead of artifact 42 previously
	 * got "permission denied" from getSection() on every section it did not
	 * own, even though it could review them.
	 */
	public static function canReadSection( int $userId, int $sectionId ): bool {
		if ( self::userIsCurator( $userId ) || self::userIsOwner( $userId, $sectionId ) ) {
			return true;
		}

		$section = get_post( $sectionId );
		if ( ! $section ) {
			return false;
		}

		$artifactId = (int) $section->post_parent;

		// Issue #252: a manager-agent holding the approval grant plus WRITE
		// on the artifact's scope may read the sections it reviews.
		return self::userIsLead( $userId, $artifactId ) || self::userCanReviewAndAssemble( $userId, $artifactId );
	}

	/**
	 * Issue #252: the scoped manager-agent approval check — the artifact
	 * pipeline's "act as a delegated reviewer" gate, checked alongside the
	 * curator/lead checks on every review/assembly (and review-enabling
	 * read) path. True only when BOTH hold:
	 *
	 * 1. the user carries the curator-granted approval grant
	 *    (Identity\ApprovalGrants — grant/revoke are curator-only and
	 *    audited there), and
	 * 2. the user holds WRITE on THIS artifact's scope, checked here and now
	 *    against the artifact's ab_scope_id postmeta — so the grant is
	 *    scoped dynamically: lose the scope write grant (or the approval
	 *    grant) and every pipeline gate closes immediately.
	 *
	 * Fails closed: a missing artifact or a scope id of 0 (which can never
	 * appear in a write-scope list) denies.
	 */
	public static function userCanReviewAndAssemble( int $userId, int $artifactId ): bool {
		if ( $userId <= 0 || $artifactId <= 0 || ! ( new ApprovalGrants() )->hasGrant( $userId ) || ! function_exists( 'get_post_meta' ) ) {
			return false;
		}

		$scopeId = (int) get_post_meta( $artifactId, Cpts::META_SCOPE_ID, true );

		return $scopeId > 0 && ( new Scopes() )->canWrite( $userId, $scopeId );
	}

	/**
	 * Fix wave A1: whether $userId owns at least one section of
	 * $artifactId. Sections are a flat post type whose post_parent is
	 * always the owning artifact (Cpts.php docblock), so a direct
	 * post_parent + owner-meta query is used rather than depending on
	 * ArtifactService's outline-block parsing.
	 */
	public static function userOwnsAnySection( int $userId, int $artifactId ): bool {
		if ( $userId <= 0 || $artifactId <= 0 || ! function_exists( 'get_posts' ) ) {
			return false;
		}

		$sections = get_posts(
			[
				'post_type'      => Cpts::SECTION_POST_TYPE,
				'post_parent'    => $artifactId,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => Cpts::META_SECTION_OWNER,
				'meta_value'     => (string) $userId,
			]
		);

		return count( $sections ) > 0;
	}

	/**
	 * The wp-admin list/edit primitive capabilities dynamically granted to
	 * anyone holding ab_manage_artifacts. Deliberately excludes
	 * create/publish/delete: creation only ever happens through
	 * ArtifactService (see Cpts::register()'s 'create_posts' => 'do_not_allow'
	 * override, which stays enforced regardless of this grant), and delete
	 * is hard-denied for both post types in mapMetaCap() above.
	 *
	 * @var string[]
	 */
	private const CURATOR_WP_ADMIN_CAPS = [
		'edit_ab_artifacts',
		'edit_others_ab_artifacts',
		'read_private_ab_artifacts',
	];

	/**
	 * Grants Identity's ab_manage_artifacts holders (curators, and
	 * administrator via role merge) the CPT's plural/primitive capabilities
	 * (edit_ab_artifacts etc.) needed for wp-admin's list table and edit
	 * screen — WordPress checks these directly via current_user_can(),
	 * never through the map_meta_cap filter above, so mapMetaCap() alone
	 * cannot unlock them.
	 *
	 * This is done via a 'user_has_cap' filter, evaluated fresh on every
	 * request, rather than by mutating the ab_curator role's stored
	 * capability set: doing the latter would change what
	 * Identity's Capabilities::curatorCapabilities() must assert (a
	 * cross-module contract this module must not touch — scope boundary),
	 * and it would silently persist even if this module were later
	 * deactivated. Object-level access (which artifact/section a given
	 * user may actually open or edit) remains entirely mapMetaCap()'s job,
	 * unchanged by this filter: a curator already passes userIsCurator()
	 * there for every object, and an ab_agent — including an artifact
	 * lead — never holds ab_manage_artifacts, so this filter grants them
	 * nothing and their wp-admin access stays exactly what mapMetaCap()
	 * already allowed (i.e. none, since agents have no dashboard access at
	 * all — see Identity\Capabilities::agentCapabilities()).
	 *
	 * @param array<string, bool> $allcaps
	 * @param string[]            $requiredCaps unused: this filter grants
	 *                                           unconditionally on identity, not per checked cap.
	 * @param array<int, mixed>   $args
	 * @return array<string, bool>
	 */
	public static function userHasCap( array $allcaps, array $requiredCaps, array $args, \WP_User $user ): array {
		if ( empty( $allcaps[ Capabilities::CAP_MANAGE_ARTIFACTS ] ) ) {
			return $allcaps;
		}

		foreach ( self::CURATOR_WP_ADMIN_CAPS as $cap ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * Hooks userHasCap() above onto WordPress' 'user_has_cap' filter.
	 *
	 * Named seam kept from the pre-Admin-IA-wave no-op version of this
	 * method (its capability set was deliberately empty back then — see
	 * git history — precisely so a later, explicitly-scoped unit could add
	 * wp-admin capabilities here without hunting for where to hook in).
	 * Idempotent: WordPress's add_filter() dedupes identical
	 * callback+priority registrations, so calling this more than once in a
	 * request is harmless.
	 *
	 * Must run on every request (capability filters are not persisted
	 * across requests) — call this from a hook that fires unconditionally,
	 * the same way this module's mapMetaCap() is wired from
	 * Module::register() via 'map_meta_cap'.
	 */
	public static function installCapabilities(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			// Mirrors this class's other WordPress-optional methods (see
			// userIsCurator() etc.): the unit suite exercises this class
			// with no WordPress loaded at all and must never fatal here.
			return;
		}

		add_filter( 'user_has_cap', [ self::class, 'userHasCap' ], 10, 4 );
	}
}
