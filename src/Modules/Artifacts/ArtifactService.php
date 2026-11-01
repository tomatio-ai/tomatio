<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Core\ClientContext;
use AgentBrain\Core\Denials;
use AgentBrain\Core\SqliteRuntime;
use AgentBrain\Core\Text;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\InsertFailed;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Provenance\DerivedFrom;
use AgentBrain\Modules\Review\GrantTaxonomy;
use AgentBrain\Modules\Sharing\Config;

/**
 * ART-1..ART-5, ART-7: the object-level service every Artifacts caller
 * (Abilities API, wp-admin glue, tests) goes through. Every method takes the
 * acting user id first and checks permissions against that explicit actor
 * via user_can($actorId, ...) — never the ambient current user — so a
 * caller acting on behalf of an agent cannot accidentally borrow the
 * current request's session privileges.
 */
class ArtifactService {

	/**
	 * Fix wave A2: which MCP client (Core\ClientContext::current()) last
	 * wrote a section's content, alongside the existing agent_id
	 * (ab_last_writer). Post meta only — not a Cpts:: constant because this
	 * unit's file boundary does not include Cpts.php.
	 */
	private const META_LAST_WRITER_CLIENT = 'ab_last_writer_client';

	/**
	 * §1.8: when an artifact is archived (any stage; Forget-compatible —
	 * no delete/trash). Not a Cpts:: constant — this unit's file boundary
	 * does not include Cpts.php.
	 */
	public const META_ARCHIVED_AT = 'ab_archived_at';

	/**
	 * §1.8/§2: the caller-requested share visibility recorded at publish
	 * time (e.g. "unlisted"/"restricted" — the exact vocabulary belongs to
	 * unit 8.2's share-link data layer, out of this unit's scope). Stored
	 * here purely so that later unit has something to read; this unit
	 * assigns no meaning to the value beyond persisting it verbatim.
	 */
	private const META_SHARE_VISIBILITY = 'ab_share_visibility';

	/**
	 * Multi-agent authoring pipeline: artifact-create now requires a
	 * $scopeId the lead agent holds WRITE on (Identity\Scopes) — everything
	 * the pipeline later writes to memory (assemble()) lands in this scope.
	 * Curator-only, unless the agent-create setting is on: then an agent may
	 * create an artifact for itself (it must be the lead).
	 *
	 * #629: $delegatedFor is the person an agent acts for under delegated
	 * authority (OAuth\DelegatedAuthority::personFor(), resolved by the
	 * caller). The agent may then create what that person may: an artifact
	 * with any lead, when the person is a curator. The audit entry records
	 * the agent as the actor, the person as `via`, and the marker.
	 *
	 * @return int|\WP_Error New artifact post ID.
	 */
	public function create( int $actorId, string $title, int $leadAgentId, int $scopeId, int $delegatedFor = 0 ) {
		$curator   = ObjectAccess::userIsCurator( $actorId );
		$delegated = ! $curator && $delegatedFor > 0 && $delegatedFor !== $actorId && ObjectAccess::userIsCurator( $delegatedFor );
		if ( ! $curator && ! $delegated && ! ( Config::agentCreateEnabled() && $actorId > 0 && $actorId === $leadAgentId ) ) {
			return Config::agentCreateEnabled() && $actorId > 0 ? Refusals::notSelfLead( $actorId, $leadAgentId ) : $this->forbidden();
		}

		if ( $scopeId <= 0 ) {
			return new \WP_Error(
				'ab_scope_required',
				__( 'An artifact requires a scope_id.', 'agent-brain' ),
				[ 'status' => 422 ]
			);
		}

		if ( ! ( new Scopes() )->canWrite( $leadAgentId, $scopeId ) ) {
			return new \WP_Error(
				'ab_scope_forbidden',
				__( 'The lead agent does not hold WRITE on this scope.', 'agent-brain' ),
				[ 'status' => 403 ]
			);
		}

		$postId = wp_insert_post( [
			'post_type'   => Cpts::ARTIFACT_POST_TYPE,
			'post_title'  => $title,
			'post_status' => 'private',
			'post_author' => $leadAgentId,
			'post_content' => '',
		], true );

		if ( $postId instanceof \WP_Error ) {
			return $postId;
		}

		update_post_meta( $postId, Cpts::META_LEAD_AGENT, $leadAgentId );
		update_post_meta( $postId, Cpts::META_SCOPE_ID, $scopeId );
		update_post_meta( $postId, Cpts::META_STAGE, Cpts::STAGE_OUTLINE );

		do_action( 'agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => 'artifact_create',
			'status'      => 'ok',
			'object_type' => Cpts::ARTIFACT_POST_TYPE,
			'object_id'   => (string) $postId,
			'meta'        => [ 'lead_agent' => $leadAgentId, 'scope_id' => $scopeId ]
				+ ( $curator ? [] : [ 'created_by' => 'agent' ] )
				+ ( $delegated ? [ 'via' => $delegatedFor, 'delegated_authority' => true ] : [] ),
		] );

		// Flag (do not enforce, docs/rfc/agent-id-fqdn.md open decision 3):
		// a lead that is not an ab_agent, e.g. a curator or a person.
		$lead = get_userdata( $leadAgentId );
		if ( ! $lead instanceof \WP_User || ! in_array( Capabilities::ROLE_AGENT, (array) $lead->roles, true ) ) {
			do_action( 'agent_brain/audit', [
				'actor_id'    => $actorId,
				'event'       => 'artifact_lead_warning',
				'status'      => 'ok',
				'object_type' => Cpts::ARTIFACT_POST_TYPE,
				'object_id'   => (string) $postId,
				'meta'        => [ 'warning' => 'lead_not_ab_agent', 'lead_agent' => $leadAgentId ],
			] );
		}

		return $postId;
	}

	/**
	 * @return array<int, int>|\WP_Error Ordered section IDs in the outline.
	 */
	public function getOutline( int $actorId, int $artifactId ) {
		$artifact = $this->requireArtifact( $actorId, $artifactId, 'read_post' );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		if ( $this->hiddenAsArchived( $actorId, $artifactId ) ) {
			return $this->notFound();
		}

		return $this->parseOutline( $artifact );
	}

	/**
	 * Multi-agent authoring pipeline: creates every section of an outline in
	 * one call (lead or curator), each with an owner + a brief, appended in
	 * order. Allowed while the artifact is still `outline` or `drafting`
	 * (a lead may add further sections while drafting is underway). Every
	 * owner must be a real agent/curator holding READ on the artifact's
	 * scope, or the whole call fails naming that owner (fail loud) — no
	 * section is created. Moves the artifact to `drafting` if it was still
	 * `outline`.
	 *
	 * @param array<int, array{title:string, owner_id:int, brief?:string}> $sections
	 * @return int[]|\WP_Error Ordered new section IDs.
	 */
	public function setOutline( int $actorId, int $artifactId, array $sections ) {
		$artifact = $this->requireArtifact( $actorId, $artifactId, null );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		if ( ! ObjectAccess::canEditOutline( $actorId, $artifactId ) ) {
			return Refusals::notLead( $actorId, $artifactId );
		}

		$stage = Stages::artifactStage( $artifactId );
		if ( ! in_array( $stage, [ Cpts::STAGE_OUTLINE, Cpts::STAGE_DRAFTING ], true ) ) {
			return new \WP_Error(
				'ab_invalid_stage_transition',
				sprintf(
					/* translators: %s: current stage */
					__( 'Cannot set the outline while the artifact is in stage "%s".', 'agent-brain' ),
					$stage
				),
				[ 'status' => 409, 'from' => $stage ]
			);
		}

		foreach ( $sections as $section ) {
			$invalid = $this->validateOwner( $artifactId, (int) $section['owner_id'] );
			if ( $invalid instanceof \WP_Error ) {
				return $invalid;
			}
		}

		$sectionIds = [];

		foreach ( $sections as $section ) {
			$title   = (string) $section['title'];
			$ownerId = (int) $section['owner_id'];
			$brief   = (string) ( $section['brief'] ?? '' );

			$sectionId = $this->createSection( $actorId, $artifactId, $title, $ownerId );
			if ( $sectionId instanceof \WP_Error ) {
				return $sectionId;
			}

			// A2: $brief is caller-supplied text (the lead's instructions to
			// the section owner) — wp_slash() so WordPress' unslash-on-save
			// round-trips quotes/backslashes exactly instead of mangling them.
			update_post_meta( $sectionId, Cpts::META_SECTION_BRIEF, wp_slash( $brief ) );
			Stages::setSectionState( $sectionId, Cpts::SECTION_STATE_DRAFTING );

			$sectionIds[] = $sectionId;
		}

		if ( $stage === Cpts::STAGE_OUTLINE ) {
			$transitioned = Stages::transitionArtifact( $actorId, $artifactId, Cpts::STAGE_DRAFTING, 'artifact-set-outline' );
			if ( $transitioned instanceof \WP_Error ) {
				return $transitioned;
			}
		}

		return $sectionIds;
	}

	/**
	 * Multi-agent authoring pipeline: read-only status snapshot for the
	 * lead, a curator, or any section owner of this artifact — titles and
	 * states only, no section content.
	 *
	 * @return array{stage:string,scope_id:int,sections:array<int,array{id:int,title:string,owner_id:int,state:string,version:int,has_open_review:bool}>}|\WP_Error
	 */
	public function status( int $actorId, int $artifactId ) {
		$artifact = $this->requireArtifact( $actorId, $artifactId, null );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		if ( $this->hiddenAsArchived( $actorId, $artifactId ) ) {
			return $this->notFound();
		}

		$sectionIds = $this->parseOutline( $artifact );

		$isCurator = ObjectAccess::userIsCurator( $actorId );
		$isLead    = ObjectAccess::userIsLead( $actorId, $artifactId );
		$isOwner   = false;
		foreach ( $sectionIds as $sectionId ) {
			if ( ObjectAccess::userIsOwner( $actorId, $sectionId ) ) {
				$isOwner = true;
				break;
			}
		}

		if ( ! $isCurator && ! $isLead && ! $isOwner ) {
			return $this->forbidden();
		}

		$sections = [];
		foreach ( $sectionIds as $sectionId ) {
			$section = get_post( $sectionId );
			if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE ) {
				continue;
			}

			$state = Stages::sectionState( $sectionId );

			$sections[] = [
				'id'              => $sectionId,
				'title'           => (string) $section->post_title,
				'owner_id'        => (int) get_post_meta( $sectionId, Cpts::META_SECTION_OWNER, true ),
				'state'           => $state,
				'version'         => $this->currentVersion( $sectionId ),
				'has_open_review' => $state === Cpts::SECTION_STATE_SUBMITTED,
			];
		}

		return [
			'stage'           => Stages::artifactStage( $artifactId ),
			'scope_id'        => (int) get_post_meta( $artifactId, Cpts::META_SCOPE_ID, true ),
			'sections'        => $sections,
			// #629: locked as "Review required" (ReviewLock).
			'review_required' => ReviewLock::isLocked( $artifactId ),
		];
	}

	/**
	 * @param int[] $sectionIds
	 * @return true|\WP_Error
	 */
	public function reorder( int $actorId, int $artifactId, array $sectionIds ) {
		$artifact = $this->requireArtifact( $actorId, $artifactId, null );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		if ( ! ObjectAccess::canEditOutline( $actorId, $artifactId ) ) {
			return Refusals::notLead( $actorId, $artifactId );
		}

		foreach ( $sectionIds as $sectionId ) {
			$section = get_post( $sectionId );
			if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE || (int) $section->post_parent !== $artifactId ) {
				return new \WP_Error(
					'ab_invalid_section',
					sprintf( __( 'Section %d does not belong to this artifact.', 'agent-brain' ), $sectionId ),
					[ 'status' => 422 ]
				);
			}
		}

		$content = $this->serializeOutline( $sectionIds );

		$result = wp_update_post( [ 'ID' => $artifactId, 'post_content' => $content ], true );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return true;
	}

	/**
	 * PRD goal 3 / Artifact fix wave: creates a new private section child of
	 * $artifactId and appends it to the outline atomically. Allowed for the
	 * artifact's lead agent or a curator (same rule as reorder/assign — see
	 * ObjectAccess::canEditOutline). Starts at version 1 via
	 * initializeVersion()'s wp_insert_post hook.
	 *
	 * @return int|\WP_Error New section post ID.
	 */
	public function createSection( int $actorId, int $artifactId, string $title, ?int $ownerId = null ) {
		$artifact = $this->requireArtifact( $actorId, $artifactId, null );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		if ( ! ObjectAccess::canEditOutline( $actorId, $artifactId ) ) {
			return Refusals::notLead( $actorId, $artifactId );
		}

		if ( $ownerId !== null ) {
			$invalid = $this->validateOwner( $artifactId, $ownerId );
			if ( $invalid instanceof \WP_Error ) {
				return $invalid;
			}
		}

		$owner = $ownerId ?? $actorId;

		$sectionId = wp_insert_post( [
			'post_type'    => Cpts::SECTION_POST_TYPE,
			'post_parent'  => $artifactId,
			'post_title'   => $title,
			'post_status'  => 'private',
			'post_author'  => $owner,
			'post_content' => '',
		], true );

		if ( $sectionId instanceof \WP_Error ) {
			return $sectionId;
		}

		update_post_meta( $sectionId, Cpts::META_SECTION_OWNER, $owner );

		$appended = $this->appendToOutlineAtomic( $artifactId, $sectionId );
		if ( $appended instanceof \WP_Error ) {
			// The section exists but never made it into the outline: surface
			// the failure rather than leaving a silently-orphaned section
			// behind (fail loud, L26).
			return $appended;
		}

		return $sectionId;
	}

	/**
	 * Appends $sectionId to $artifactId's outline under a MySQL named lock
	 * scoped to the artifact, so two concurrent createSection() calls for the
	 * same artifact can't both read-then-write the outline and silently drop
	 * one of the appends (the outline itself carries no CAS version meta —
	 * see casVersion()'s docblock for why a plain read-modify-write on
	 * post_content isn't safe for concurrent writers).
	 *
	 * @return true|\WP_Error
	 */
	private function appendToOutlineAtomic( int $artifactId, int $sectionId ) {
		global $wpdb;

		$lockName = 'ab_outline_cas_' . $artifactId;

		// SQLite drop-in (#405): GET_LOCK is untranslated and would always
		// time out. Playground serializes requests, so skip the named lock
		// and run the read-modify-write unserialized (fail-open per the
		// #402 audit); the finally block only releases when we took it.
		$locked = false;
		if ( ! SqliteRuntime::isActive() ) {
			$acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lockName ) );

			if ( $acquired !== 1 ) {
				return new \WP_Error(
					'ab_outline_lock_timeout',
					__( 'Could not update the artifact outline; another writer is holding the lock.', 'agent-brain' ),
					[ 'status' => 409 ]
				);
			}

			$locked = true;
		}

		try {
			$artifact = get_post( $artifactId );
			if ( ! $artifact ) {
				return $this->notFound();
			}

			$sectionIds   = $this->parseOutline( $artifact );
			$sectionIds[] = $sectionId;

			$result = wp_update_post( [
				'ID'           => $artifactId,
				'post_content' => $this->serializeOutline( $sectionIds ),
			], true );

			return $result instanceof \WP_Error ? $result : true;
		} finally {
			if ( $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lockName ) );
			}
		}
	}

	/**
	 * An owner must be an agent/curator (may use MCP) holding READ on the
	 * artifact's scope. Shared by set-outline, section-create and section-assign.
	 *
	 * @return true|\WP_Error
	 */
	private function validateOwner( int $artifactId, int $ownerId ) {
		$scopeId = (int) get_post_meta( $artifactId, Cpts::META_SCOPE_ID, true );

		if ( $ownerId <= 0 || ! user_can( $ownerId, Capabilities::CAP_USE_MCP ) || ! ( new Scopes() )->canRead( $ownerId, $scopeId ) ) {
			return new \WP_Error(
				'ab_invalid_owner',
				sprintf(
					/* translators: %d: owner user id */
					__( 'Owner %d is not an agent/curator with READ on this artifact\'s scope.', 'agent-brain' ),
					$ownerId
				),
				[ 'status' => 422, 'owner_id' => $ownerId ]
			);
		}

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function assign( int $actorId, int $sectionId, int $ownerId ) {
		$section = get_post( $sectionId );
		if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE ) {
			return $this->notFound();
		}

		if ( ! ObjectAccess::canEditOutline( $actorId, (int) $section->post_parent ) ) {
			return Refusals::notLead( $actorId, (int) $section->post_parent );
		}

		$invalid = $this->validateOwner( (int) $section->post_parent, $ownerId );
		if ( $invalid instanceof \WP_Error ) {
			return $invalid;
		}

		update_post_meta( $sectionId, Cpts::META_SECTION_OWNER, $ownerId );
		wp_update_post( [ 'ID' => $sectionId, 'post_author' => $ownerId ] );

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function claim( int $actorId, int $sectionId ) {
		$section = $this->requireSection( $actorId, $sectionId, 'edit_post' );
		if ( $section instanceof \WP_Error ) {
			return $section;
		}

		return Lease::claim( $actorId, $sectionId );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function release( int $actorId, int $sectionId ) {
		$section = get_post( $sectionId );
		if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE ) {
			return $this->notFound();
		}

		return Lease::release( $actorId, $sectionId );
	}

	/**
	 * Artifact fix wave A1: checks ObjectAccess::canReadSection() (owner,
	 * curator, or the parent artifact's lead) — the SAME helper the
	 * 'read_post' meta-cap branch uses for REST, so MCP and REST never
	 * disagree on who may read a section again. A lead must be able to
	 * section-get every section of an artifact it may review; see
	 * tests/integration/Leak/CrossAgentLeakTest.php (out of this unit's
	 * scope boundary) for the complementary invariant that a non-owner,
	 * non-lead, non-curator agent still gets nothing.
	 *
	 * agent_id (provenance fix): the real last writer of the section's
	 * content when it has one, else the section's owner — never left empty.
	 * Callers that wrap this for an untrusted-content surface (review
	 * notes, briefs) are responsible for the Core\Untrusted wrap; this
	 * method returns the raw values.
	 *
	 * #220: with $version set, the content/version returned are the recorded
	 * HISTORY snapshot of that section version (taken at submit time), not
	 * the live post — so any past version, including a superseded or pending
	 * one, can be read back. Same read gate as the live read; a version with
	 * no snapshot is ab_version_not_found (404).
	 *
	 * @return array{id:int,content:string,version:int,owner:int,agent_id:int,allowed_blocks:string[],state:string,brief:string,review_log:array<int,array{version:int,reviewer_id:int,verdict:string,notes:string,at:string}>,client:?string}|\WP_Error
	 */
	public function getSection( int $actorId, int $sectionId, ?int $version = null ) {
		$section = get_post( $sectionId );
		if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE ) {
			return $this->notFound();
		}

		if ( ! ObjectAccess::canReadSection( $actorId, $sectionId ) ) {
			return $this->forbidden();
		}

		$owner       = (int) get_post_meta( $sectionId, Cpts::META_SECTION_OWNER, true );
		$lastWriter  = (int) get_post_meta( $sectionId, Cpts::META_LAST_WRITER, true );
		$clientRaw   = get_post_meta( $sectionId, self::META_LAST_WRITER_CLIENT, true );
		$client      = $clientRaw !== '' ? (string) $clientRaw : null;

		$content = (string) $section->post_content;
		if ( $version !== null ) {
			$snapshot = Versions::sectionSnapshot( (int) $section->post_parent, $sectionId, $version );
			if ( $snapshot === null ) {
				return new \WP_Error(
					'ab_version_not_found',
					__( 'That version does not exist.', 'agent-brain' ),
					[ 'status' => 404 ]
				);
			}
			$content = $snapshot['content'];
		} else {
			$version = $this->currentVersion( $sectionId );
		}

		return [
			'id'             => $sectionId,
			'content'        => $content,
			'version'        => $version,
			'owner'          => $owner,
			'agent_id'       => $lastWriter > 0 ? $lastWriter : $owner,
			'client'         => $client,
			'allowed_blocks' => $this->allowedBlocks( $sectionId ),
			'state'          => Stages::sectionState( $sectionId ),
			'brief'          => (string) get_post_meta( $sectionId, Cpts::META_SECTION_BRIEF, true ),
			'review_log'     => Stages::reviewLog( $sectionId ),
		];
	}

	/**
	 * ART-4/ART-5: rejects stale writes and disallowed/unsanitized markup.
	 *
	 * @param array<int, array<string, mixed>> $blocksJson
	 * @return array{version:int}|\WP_Error
	 */
	public function updateSection( int $actorId, int $sectionId, array $blocksJson, int $expectedVersion ) {
		$section = $this->requireSection( $actorId, $sectionId, 'edit_post' );
		if ( $section instanceof \WP_Error ) {
			return $section;
		}

		// §1.10: a different actor's live (non-expired) lease blocks the
		// write with a distinct, checkable 409 — CAS on ab_version (below)
		// remains the real lost-update guard.
		$leaseCheck = Lease::assertHeldByOrUnclaimed( $sectionId, $actorId );
		if ( $leaseCheck instanceof \WP_Error ) {
			return $leaseCheck;
		}

		// Pipeline lock: only the owner/curator (checked above) AND only
		// while the section is drafting|changes_requested may write content
		// — an approved or assembled section is read-only.
		if ( ! Stages::sectionIsEditable( $sectionId ) ) {
			return $this->sectionLocked();
		}

		$markup = BlockSanitizer::build( $blocksJson, $this->allowedBlocks( $sectionId ) );
		if ( $markup instanceof \WP_Error ) {
			return $markup;
		}

		$currentVersion = $this->currentVersion( $sectionId );
		if ( $currentVersion !== $expectedVersion ) {
			return $this->staleVersion();
		}

		$won = $this->casVersion( $sectionId, $currentVersion, $currentVersion + 1 );
		if ( ! $won ) {
			return $this->staleVersion();
		}

		$result = wp_update_post( [ 'ID' => $sectionId, 'post_content' => $markup ], true );
		if ( $result instanceof \WP_Error ) {
			// Roll the version back so the lost write doesn't silently
			// consume a version number with no corresponding content change.
			update_post_meta( $sectionId, Cpts::META_VERSION, $currentVersion, $currentVersion + 1 );

			return $result;
		}

		update_post_meta( $sectionId, Cpts::META_LAST_WRITER, $actorId );

		// A2: record which MCP client wrote this content, slashed the same
		// way META_SECTION_BRIEF is above so WordPress' unslash-on-save
		// round-trips it exactly; null (unknown/non-MCP caller) clears any
		// stale value instead of writing a literal empty string.
		$client = ClientContext::current();
		if ( $client === null ) {
			delete_post_meta( $sectionId, self::META_LAST_WRITER_CLIENT );
		} else {
			update_post_meta( $sectionId, self::META_LAST_WRITER_CLIENT, wp_slash( $client ) );
		}

		return [ 'version' => $currentVersion + 1 ];
	}

	/**
	 * Multi-agent authoring pipeline: owner (or curator) submits a section
	 * for review. Allowed from `drafting` or `changes_requested` only.
	 * The FIRST submission of any section in the artifact moves the
	 * artifact itself from `drafting` to `revising`.
	 *
	 * @return true|\WP_Error
	 */
	public function submit( int $actorId, int $sectionId ) {
		$section = $this->requireSection( $actorId, $sectionId, 'edit_post' );
		if ( $section instanceof \WP_Error ) {
			return $section;
		}

		$state = Stages::sectionState( $sectionId );
		if ( ! in_array( $state, [ Cpts::SECTION_STATE_DRAFTING, Cpts::SECTION_STATE_CHANGES_REQUESTED ], true ) ) {
			return new \WP_Error(
				'ab_invalid_section_transition',
				sprintf(
					/* translators: %s: current section state */
					__( 'Cannot submit a section in state "%s".', 'agent-brain' ),
					$state
				),
				[ 'status' => 409, 'from' => $state ]
			);
		}

		// §1.2: a section with no reviewable content is never allowed
		// through the pipeline's last gate before a human/lead reviews it —
		// defense in depth even though updateSection()/BlockSanitizer
		// already reject an empty text-bearing block on write.
		if ( $this->sectionIsEffectivelyEmpty( $sectionId ) ) {
			return new \WP_Error(
				'ab_empty_section',
				__( 'This section has no content to submit.', 'agent-brain' ),
				[ 'status' => 422 ]
			);
		}

		Stages::setSectionState( $sectionId, Cpts::SECTION_STATE_SUBMITTED );
		update_post_meta( $sectionId, 'ab_section_submitted', 1 );
		// ART-14 (#128): the author's edit lease ends with the submit.
		Lease::clear( $sectionId );

		// #220: a submission is a version — snapshot it into the artifact's
		// unified history so any past (or still pending) version can be
		// viewed, compared and restored later.
		Versions::recordSection( (int) $section->post_parent, $sectionId, $actorId );

		$artifactId = (int) $section->post_parent;
		if ( $artifactId > 0 && Stages::artifactStage( $artifactId ) === Cpts::STAGE_DRAFTING ) {
			$transitioned = Stages::transitionArtifact( $actorId, $artifactId, Cpts::STAGE_REVISING, 'first-section-submit' );
			if ( $transitioned instanceof \WP_Error ) {
				return $transitioned;
			}
		}

		return true;
	}

	/**
	 * Multi-agent authoring pipeline: lead, curator, or a manager-agent
	 * holding the scoped approval grant (Identity\ApprovalGrants, issue #252:
	 * grant + WRITE on the artifact's scope) reviews a submitted section. A
	 * reviewer may not review a section they own (unless curator — the grant
	 * does NOT lift that rule, #152 stands). Approving locks the section (updateSection then refuses via
	 * sectionIsEditable()); once EVERY section of the artifact is approved,
	 * the artifact moves to `ready` automatically. request_changes reopens
	 * the section for the owner and, if the artifact had already reached
	 * `ready`, moves it back to `revising`. reopen (ART-9) moves an approved
	 * section back to changes_requested, and a `ready` or `assembled`
	 * artifact back to `revising`.
	 *
	 * @return array{state:string}|\WP_Error
	 */
	public function reviewSection( int $actorId, int $sectionId, string $verdict, string $notes ) {
		$section = get_post( $sectionId );
		if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE ) {
			return $this->notFound();
		}

		if ( ! in_array( $verdict, [ 'approve', 'request_changes', 'reopen' ], true ) ) {
			return new \WP_Error(
				'ab_invalid_verdict',
				__( 'verdict must be "approve", "request_changes", or "reopen".', 'agent-brain' ),
				[ 'status' => 422 ]
			);
		}

		$artifactId = (int) $section->post_parent;

		if ( ! ObjectAccess::userIsCurator( $actorId ) && ! ObjectAccess::userIsLead( $actorId, $artifactId ) && ! ObjectAccess::userCanReviewAndAssemble( $actorId, $artifactId ) ) {
			return $this->forbidden();
		}

		// §1.7: curator/lead-only "un-approve" of a single approved section.
		// Deliberately handled before, and independent of, the
		// reviewer-only "cannot review your own section" check below —
		// reopening a section you yourself approved is not the
		// conflict-of-interest that check exists to prevent.
		if ( $verdict === 'reopen' ) {
			$reopened = Stages::reopenSection( $sectionId );
			if ( $reopened instanceof \WP_Error ) {
				return $reopened;
			}

			Stages::appendReviewLog( $sectionId, $this->currentVersion( $sectionId ), $actorId, $verdict, $notes );

			do_action( 'agent_brain/audit', [
				'actor_id'    => $actorId,
				'event'       => 'artifact_section_review',
				'status'      => 'ok',
				'object_type' => Cpts::SECTION_POST_TYPE,
				'object_id'   => (string) $sectionId,
				'meta'        => [ 'verdict' => $verdict, 'artifact_id' => $artifactId ],
			] );

			// Same rule request_changes already applies below: un-approving
			// one section of a `ready` or `assembled` artifact (every section
			// was approved to get there) can no longer honestly claim it.
			if ( $artifactId > 0 && in_array( Stages::artifactStage( $artifactId ), [ Cpts::STAGE_READY, Cpts::STAGE_ASSEMBLED ], true ) ) {
				$transitioned = Stages::transitionArtifact( $actorId, $artifactId, Cpts::STAGE_REVISING, 'section-reopened' );
				if ( $transitioned instanceof \WP_Error ) {
					return $transitioned;
				}
			}

			return [ 'state' => Cpts::SECTION_STATE_CHANGES_REQUESTED ];
		}

		if ( ! ObjectAccess::userIsCurator( $actorId ) && ObjectAccess::userIsOwner( $actorId, $sectionId ) ) {
			return new \WP_Error(
				'ab_cannot_review_own_section',
				__( 'You cannot review a section you own.', 'agent-brain' ),
				[ 'status' => 403 ]
			);
		}

		// A4: a submitted section may always be reviewed. A section already in
		// changes_requested may ALSO be re-reviewed by the lead/curator (both
		// already required above) — the new verdict supersedes the mistaken
		// one: approve moves it straight to approved, request_changes just
		// replaces the notes. An approved (or any other) state stays locked —
		// no re-review once approved.
		$state = Stages::sectionState( $sectionId );
		if ( ! in_array( $state, [ Cpts::SECTION_STATE_SUBMITTED, Cpts::SECTION_STATE_CHANGES_REQUESTED ], true ) ) {
			return new \WP_Error(
				'ab_invalid_section_transition',
				sprintf(
					/* translators: %s: current section state */
					__( 'Cannot review a section in state "%s"; it must be submitted or changes_requested.', 'agent-brain' ),
					$state
				),
				[ 'status' => 409, 'from' => $state ]
			);
		}

		$newState = $verdict === 'approve' ? Cpts::SECTION_STATE_APPROVED : Cpts::SECTION_STATE_CHANGES_REQUESTED;
		Stages::setSectionState( $sectionId, $newState );
		Stages::appendReviewLog( $sectionId, $this->currentVersion( $sectionId ), $actorId, $verdict, $notes );

		// ART-14 (#128): an approved section is locked, so no lease stays on it.
		if ( $newState === Cpts::SECTION_STATE_APPROVED ) {
			Lease::clear( $sectionId );
		}

		do_action( 'agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => 'artifact_section_review',
			'status'      => 'ok',
			'object_type' => Cpts::SECTION_POST_TYPE,
			'object_id'   => (string) $sectionId,
			'meta'        => [ 'verdict' => $verdict, 'artifact_id' => $artifactId ],
		] );

		if ( $artifactId > 0 ) {
			$artifactPost = get_post( $artifactId );
			$sectionIds   = $artifactPost ? $this->parseOutline( $artifactPost ) : [];
			$stage        = Stages::artifactStage( $artifactId );

			if ( $verdict === 'approve' && $stage === Cpts::STAGE_REVISING && Stages::allSectionsApproved( $sectionIds ) ) {
				$transitioned = Stages::transitionArtifact( $actorId, $artifactId, Cpts::STAGE_READY, 'all-sections-approved' );
				if ( $transitioned instanceof \WP_Error ) {
					return $transitioned;
				}
			}

			if ( $verdict === 'request_changes' && $stage === Cpts::STAGE_READY ) {
				$transitioned = Stages::transitionArtifact( $actorId, $artifactId, Cpts::STAGE_REVISING, 'section-reopened' );
				if ( $transitioned instanceof \WP_Error ) {
					return $transitioned;
				}
			}
		}

		return [ 'state' => $newState ];
	}

	/**
	 * #220 restore (section artifacts): put a recorded history snapshot's
	 * content back as a NEW version of the section — nothing is overwritten.
	 * The gate is identical to approval (ArtifactService::reviewSection's
	 * curator/lead/#252-manager-grant set): a plain agent, including the
	 * section's own owner, cannot restore. The restored section lands in
	 * `submitted`, so it must go through the normal review path again before
	 * it counts as approved; un-approving a section of a `ready`/`assembled`
	 * artifact moves the artifact back to `revising`, exactly like reopen.
	 * Every restore is audited; viewing history never is.
	 *
	 * @param string $content The snapshot content (already-sanitized block
	 *                        markup recorded by Versions::recordSection()).
	 * @param int    $restoredFromVersion The history section_version the
	 *                                    content was taken from.
	 * @return array{version:int,state:string,seq:int|null}|\WP_Error
	 */
	public function restoreSectionVersion( int $actorId, int $sectionId, string $content, int $restoredFromVersion, string $note = '' ) {
		$section = get_post( $sectionId );
		if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE ) {
			return $this->notFound();
		}

		$artifactId = (int) $section->post_parent;

		if ( ! ObjectAccess::userIsCurator( $actorId ) && ! ObjectAccess::userIsLead( $actorId, $artifactId ) && ! ObjectAccess::userCanReviewAndAssemble( $actorId, $artifactId ) ) {
			return $this->forbidden();
		}

		$currentVersion = $this->currentVersion( $sectionId );
		if ( ! $this->casVersion( $sectionId, $currentVersion, $currentVersion + 1 ) ) {
			return $this->staleVersion();
		}

		$result = wp_update_post( [ 'ID' => $sectionId, 'post_content' => $content ], true );
		if ( $result instanceof \WP_Error ) {
			// Roll the version back so the failed restore doesn't consume a
			// version number (same contract as updateSection()).
			update_post_meta( $sectionId, Cpts::META_VERSION, $currentVersion, $currentVersion + 1 );

			return $result;
		}

		update_post_meta( $sectionId, Cpts::META_LAST_WRITER, $actorId );
		$client = ClientContext::current();
		if ( $client === null ) {
			delete_post_meta( $sectionId, self::META_LAST_WRITER_CLIENT );
		} else {
			update_post_meta( $sectionId, self::META_LAST_WRITER_CLIENT, wp_slash( $client ) );
		}

		// The restored content must be approved like any other submission:
		// it goes back into the review queue, never straight to approved.
		Stages::setSectionState( $sectionId, Cpts::SECTION_STATE_SUBMITTED );
		Lease::clear( $sectionId );

		$note = trim( $note ) !== ''
			? sprintf( /* translators: 1: restored-from version, 2: caller note. */ __( 'Restore of version %1$d: %2$s', 'agent-brain' ), $restoredFromVersion, $note )
			: sprintf( /* translators: %d: restored-from version. */ __( 'Restore of version %d', 'agent-brain' ), $restoredFromVersion );
		Stages::appendReviewLog( $sectionId, $currentVersion + 1, $actorId, 'restore', $note );

		$seq = Versions::recordSection( $artifactId, $sectionId, $actorId, $note );

		do_action( 'agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => 'artifact_section_restore',
			'status'      => 'ok',
			'object_type' => Cpts::SECTION_POST_TYPE,
			'object_id'   => (string) $sectionId,
			'meta'        => [ 'artifact_id' => $artifactId, 'from_version' => $restoredFromVersion, 'version' => $currentVersion + 1 ],
		] );

		// Un-approving a section of a finished artifact can no longer honestly
		// claim the artifact is finished (same rule as reopen/request_changes).
		$stage = Stages::artifactStage( $artifactId );
		if ( $artifactId > 0 && in_array( $stage, [ Cpts::STAGE_READY, Cpts::STAGE_ASSEMBLED ], true ) ) {
			$transitioned = Stages::transitionArtifact( $actorId, $artifactId, Cpts::STAGE_REVISING, 'section-restored' );
			if ( $transitioned instanceof \WP_Error ) {
				return $transitioned;
			}
		} elseif ( $artifactId > 0 && $stage === Cpts::STAGE_DRAFTING ) {
			// Mirror submit()'s first-submission rule for an artifact whose
			// very first activity is a restore.
			$transitioned = Stages::transitionArtifact( $actorId, $artifactId, Cpts::STAGE_REVISING, 'section-restored' );
			if ( $transitioned instanceof \WP_Error ) {
				return $transitioned;
			}
		}

		return [
			'version' => $currentVersion + 1,
			'state'   => Cpts::SECTION_STATE_SUBMITTED,
			'seq'     => $seq,
		];
	}

	/**
	 * ART-7: flattens the outline into a standalone post. Always 'draft'
	 * regardless of caller input — REV-3's publish block is enforced here
	 * structurally, not by a checkable-but-bypassable flag. ART-20: updates
	 * the artifact's newest existing compiled draft (WordPress keeps the
	 * revisions) unless $newDraft asks for a fresh post.
	 *
	 * $postType picks what the draft is: a `post` (default) or a `page`
	 * (see targetPostType()).
	 *
	 * @return int|\WP_Error Compiled post ID.
	 */
	public function compile( int $actorId, int $artifactId, string $postType = 'post', bool $newDraft = false ) {
		$artifact = $this->requireArtifact( $actorId, $artifactId, null );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		$postType = $this->targetPostType( $postType );
		if ( $postType instanceof \WP_Error ) {
			return $postType;
		}

		if ( ! ObjectAccess::userIsCurator( $actorId ) ) {
			return $this->forbidden();
		}

		// Nothing approved yet (e.g. a fresh outline): a compiled draft post
		// would be empty, so refuse with a reason instead of creating it.
		if ( ! $this->hasApprovedSection( $artifact ) ) {
			return new \WP_Error(
				'ab_nothing_to_compile',
				__( 'Nothing to compile yet: no section of this artifact is approved. Approve at least one section first.', 'agent-brain' ),
				[ 'status' => 409 ]
			);
		}

		// §1.6 (compile side): compile() stays usable at any artifact
		// stage, so non-approved sections are EXCLUDED (with an inline
		// marker) rather than blocking the whole preview — $requireApproved
		// defaults false, so buildAssembledMarkup() never returns a
		// \WP_Error on this path.
		$markup = $this->buildAssembledMarkup( $artifact );
		if ( $markup instanceof \WP_Error ) {
			return $markup;
		}

		// PROV-1 (#170): the compiled post lists the artifact and the
		// section versions it was built from — only the approved sections
		// whose content actually made it into the markup.
		$includedSectionIds = [];
		foreach ( $this->parseOutline( $artifact ) as $sectionId ) {
			if ( Stages::sectionState( $sectionId ) === Cpts::SECTION_STATE_APPROVED ) {
				$includedSectionIds[] = $sectionId;
			}
		}

		$fields = [
			'post_type'    => $postType,
			'post_status'  => 'draft',
			'post_title'   => $artifact->post_title,
			'post_content' => $markup,
		];

		$existingId = $newDraft ? 0 : $this->existingCompiledDraftId( $artifactId, $postType );
		if ( $existingId > 0 ) {
			$postId = wp_update_post( $fields + [ 'ID' => $existingId ], true );
			if ( $postId instanceof \WP_Error ) {
				return $postId;
			}

			// The draft is rebuilt: its derived-from links are replaced, not
			// appended, so a source that dropped out never lingers.
			$this->recordBuildSources( 'post', (int) $postId, $artifactId, $includedSectionIds, true );

			return (int) $postId;
		}

		$postId = wp_insert_post( $fields, true );

		if ( $postId instanceof \WP_Error ) {
			return $postId;
		}

		update_post_meta( $postId, Cpts::META_COMPILED_FROM, $artifactId );
		$this->recordBuildSources( 'post', (int) $postId, $artifactId, $includedSectionIds );

		return $postId;
	}

	/** Newest compiled draft of the artifact in $postType (assembled private posts never match), or 0. */
	private function existingCompiledDraftId( int $artifactId, string $postType ): int {
		$found = get_posts( [
			'post_type'      => $postType,
			'post_status'    => 'draft',
			'meta_key'       => Cpts::META_COMPILED_FROM,
			'meta_value'     => (string) $artifactId,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		] );

		return $found !== [] ? (int) $found[0] : 0;
	}

	/**
	 * The post type compile() and assemble() create: `post` or `page` by
	 * default, widened or narrowed with the `agent_brain/artifact_target_post_types`
	 * filter. Anything else is refused, so a caller cannot make an
	 * arbitrary (or private internal) post type.
	 *
	 * @return string|\WP_Error
	 */
	private function targetPostType( string $postType ) {
		$allowed = (array) apply_filters( 'agent_brain/artifact_target_post_types', [ 'post', 'page' ] );
		if ( ! in_array( $postType, $allowed, true ) || ! post_type_exists( $postType ) ) {
			return new \WP_Error(
				'ab_invalid_post_type',
				sprintf(
					/* translators: %s: comma separated post types */
					__( 'Unsupported target post type. Use one of: %s.', 'agent-brain' ),
					implode( ', ', array_map( 'strval', $allowed ) )
				),
				[ 'status' => 400 ]
			);
		}

		return $postType;
	}

	/** True when at least one outline section is approved (the compile precondition). */
	public function canCompile( int $artifactId ): bool {
		$artifact = get_post( $artifactId );

		return $artifact instanceof \WP_Post && $artifact->post_type === Cpts::ARTIFACT_POST_TYPE && $this->hasApprovedSection( $artifact );
	}

	private function hasApprovedSection( \WP_Post $artifact ): bool {
		foreach ( $this->parseOutline( $artifact ) as $sectionId ) {
			if ( Stages::sectionState( $sectionId ) === Cpts::SECTION_STATE_APPROVED ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * §1.7: curator/lead-only. Moves an `assembled` artifact back to
	 * `revising` so its sections can be edited again. Per Roger's decision
	 * (§7.5): reopening a PUBLISHED artifact does NOT auto-unpublish — the
	 * live public share link stays up until explicit unpublish, revoke,
	 * expiry, or Forget. This method never touches post_status/share
	 * links; it only moves the pipeline stage.
	 *
	 * @return true|\WP_Error
	 */
	public function reopen( int $actorId, int $artifactId ) {
		$artifact = $this->requireArtifact( $actorId, $artifactId, null );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		if ( ! ObjectAccess::userIsCurator( $actorId ) && ! ObjectAccess::userIsLead( $actorId, $artifactId ) ) {
			return $this->forbidden();
		}

		return Stages::transitionArtifact( $actorId, $artifactId, Cpts::STAGE_REVISING, 'artifact-reopen' );
	}

	/**
	 * Multi-agent authoring pipeline: assembles the ready artifact into a
	 * standalone private post (same markup rules as compile(), never
	 * published — REV-3), sets stage `assembled`, then updates the memory
	 * space: writes an episode memory row in the artifact's scope
	 * (agent_id = lead, agent_written 0, importance 70, a bounded summary),
	 * fires `agent_brain/artifact_assembled` for Review to propose
	 * knowledge from, and returns every id a caller needs, including
	 * whatever Review's `agent_brain/artifact_assembled_knowledge_id`
	 * filter hands back (0 if nothing proposed it / Review isn't active).
	 * Allowed for the lead agent, a curator, or a manager-agent holding the
	 * scoped approval grant (issue #252); stage must be `ready`.
	 *
	 * $postType is `post` (default) or `page` (see targetPostType()).
	 *
	 * @return array{assembled_post_id:int, memory_id:int, proposed_knowledge_id:int}|\WP_Error
	 */
	public function assemble( int $actorId, int $artifactId, string $postType = 'post' ) {
		$artifact = $this->requireArtifact( $actorId, $artifactId, null );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		$postType = $this->targetPostType( $postType );
		if ( $postType instanceof \WP_Error ) {
			return $postType;
		}

		if ( ! ObjectAccess::userIsCurator( $actorId ) && ! ObjectAccess::userIsLead( $actorId, $artifactId ) && ! ObjectAccess::userCanReviewAndAssemble( $actorId, $artifactId ) ) {
			return $this->forbidden();
		}

		$stage = Stages::artifactStage( $artifactId );
		if ( $stage !== Cpts::STAGE_READY ) {
			return new \WP_Error(
				'ab_invalid_stage_transition',
				sprintf(
					/* translators: %s: current stage */
					__( 'Cannot assemble an artifact in stage "%s"; it must be ready.', 'agent-brain' ),
					$stage
				),
				[ 'status' => 409, 'from' => $stage ]
			);
		}

		$scopeId    = (int) get_post_meta( $artifactId, Cpts::META_SCOPE_ID, true );
		$leadId     = (int) get_post_meta( $artifactId, Cpts::META_LEAD_AGENT, true );
		$sectionIds = $this->parseOutline( $artifact );

		// §1.6 (highest-severity fix, security-review pipeline-lens HIGH
		// #1): assemble() REFUSES to run unless every outline section is
		// approved. In the current state machine `ready` already implies
		// this (Stages::allSectionsApproved() gates the transition), so
		// this should never fire via the normal path — it exists as
		// defense in depth against any future code path that reaches
		// assemble() without going through the `ready` gate (a direct
		// call, a future admin action, a stage-check bug).
		$markup = $this->buildAssembledMarkup( $artifact, true );
		if ( $markup instanceof \WP_Error ) {
			return $markup;
		}

		$assembledPostId = wp_insert_post( [
			'post_type'    => $postType,
			'post_status'  => 'private',
			'post_title'   => $artifact->post_title,
			'post_content' => $markup,
		], true );

		if ( $assembledPostId instanceof \WP_Error ) {
			return $assembledPostId;
		}

		update_post_meta( $assembledPostId, Cpts::META_COMPILED_FROM, $artifactId );
		$this->recordBuildSources( 'post', (int) $assembledPostId, $artifactId, $sectionIds );

		$transitioned = Stages::transitionArtifact( $actorId, $artifactId, Cpts::STAGE_ASSEMBLED, 'artifact-assemble' );
		if ( $transitioned instanceof \WP_Error ) {
			return $transitioned;
		}

		try {
			$memoryId = ( new MemoryRepository() )->insert( [
				'agent_id'      => $leadId,
				'scope_id'      => $scopeId,
				'type'          => 'episode',
				'content'       => $this->assemblySummary( $artifact, $sectionIds ),
				'importance'    => 70,
				'agent_written' => 0,
				'source_ids'    => $sectionIds,
			] );
		} catch ( InsertFailed $e ) {
			// The document is assembled; only its summary memory is missing.
			// Say so in the audit log rather than naming another row (#183).
			$memoryId = 0;
			do_action( 'agent_brain/audit', [
				'actor_id'    => $actorId,
				'event'       => 'artifact_assembly_memory_failed',
				'status'      => 'error',
				'object_type' => Cpts::ARTIFACT_POST_TYPE,
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'scope_id' => $scopeId ],
			] );
		}

		do_action( 'agent_brain/artifact_assembled', $artifactId, $assembledPostId, $scopeId, $leadId, $sectionIds, $memoryId );

		// PROV-1 (#170): the assembly episode lists the artifact and section
		// versions it summarises (0 when the insert above failed — nothing
		// to link then).
		$this->recordBuildSources( 'memory', $memoryId, $artifactId, $sectionIds );

		$proposedKnowledgeId = (int) apply_filters( 'agent_brain/artifact_assembled_knowledge_id', 0, $artifactId );
		$this->recordBuildSources( 'post', $proposedKnowledgeId, $artifactId, $sectionIds );

		return [
			'assembled_post_id'     => $assembledPostId,
			'memory_id'             => $memoryId,
			'proposed_knowledge_id' => $proposedKnowledgeId,
		];
	}

	/**
	 * §1.8: curator/lead only (unreachable by an agent identity — CAP_PUBLISH
	 * is never in Identity\Capabilities::agentCapabilities()). Requires
	 * stage `assembled`, which by §1.6 now guarantees every section was
	 * approved at assemble time. Assigns the `public-grant` term (the only
	 * sanctioned path — GrantTaxonomy::assignPublicGrant(), which itself
	 * re-checks CAP_PUBLISH) before flipping post_status, satisfying
	 * Review\PublishGuard's own independent gate. That guard checks the
	 * AMBIENT current user (out of this unit's scope to change), so this
	 * method re-reads the resulting post_status after the write and fails
	 * loud (`ab_publish_blocked`) rather than silently reporting success
	 * if PublishGuard reverted it.
	 *
	 * @return true|\WP_Error
	 */
	public function publish( int $actorId, int $artifactId, ?string $shareVisibility = null ) {
		$artifact = $this->requireArtifact( $actorId, $artifactId, null );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		if ( ! user_can( $actorId, Capabilities::CAP_PUBLISH ) ) {
			return $this->forbidden();
		}

		$stage = Stages::artifactStage( $artifactId );
		if ( $stage !== Cpts::STAGE_ASSEMBLED ) {
			return new \WP_Error(
				'ab_invalid_stage_transition',
				sprintf(
					/* translators: %s: current stage */
					__( 'Cannot publish an artifact in stage "%s"; it must be assembled.', 'agent-brain' ),
					$stage
				),
				[ 'status' => 409, 'from' => $stage ]
			);
		}

		if ( ! GrantTaxonomy::assignPublicGrant( $actorId, $artifactId ) ) {
			return new \WP_Error(
				'ab_grant_failed',
				__( 'Could not assign the public-grant term required to publish.', 'agent-brain' ),
				[ 'status' => 500 ]
			);
		}

		$result = wp_update_post( [ 'ID' => $artifactId, 'post_status' => 'publish' ], true );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$updated = get_post( $artifactId );
		if ( ! $updated || $updated->post_status !== 'publish' ) {
			return new \WP_Error(
				'ab_publish_blocked',
				__( 'Publish was blocked by the publish guard.', 'agent-brain' ),
				[ 'status' => 403 ]
			);
		}

		if ( $shareVisibility !== null ) {
			update_post_meta( $artifactId, self::META_SHARE_VISIBILITY, wp_slash( $shareVisibility ) );
		}

		do_action( 'agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => 'artifact_publish',
			'status'      => 'ok',
			'object_type' => Cpts::ARTIFACT_POST_TYPE,
			'object_id'   => (string) $artifactId,
			'meta'        => [ 'share_visibility' => $shareVisibility ],
		] );

		// Wiring hook for unit 8.2 (share-link mint/request UI): a caller
		// may want to mint the default share link right after publish.
		do_action( 'agent_brain/artifact_published', $artifactId, $actorId, $shareVisibility );

		return true;
	}

	/**
	 * §1.8: CAP_PUBLISH-gated (curator/lead only, unreachable by an agent
	 * identity). Sets post_status back to 'private' and — fail closed —
	 * revokes every live share link for this post by default. This unit
	 * does not own the share-link data layer (unit 8.2's ShareLinks.php),
	 * so revocation itself is not implemented here: `$keepShareLinks` is
	 * threaded through to the `agent_brain/artifact_unpublished` action for
	 * unit 8.2/8.8 to hook a ShareLinks::revokeAllForPost()-style listener
	 * onto. See this unit's return for the exact wiring contract.
	 *
	 * @return true|\WP_Error
	 */
	public function unpublish( int $actorId, int $artifactId, bool $keepShareLinks = false ) {
		$artifact = $this->requireArtifact( $actorId, $artifactId, null );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		if ( ! user_can( $actorId, Capabilities::CAP_PUBLISH ) ) {
			return $this->forbidden();
		}

		$result = wp_update_post( [ 'ID' => $artifactId, 'post_status' => 'private' ], true );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		do_action( 'agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => 'artifact_unpublish',
			'status'      => 'ok',
			'object_type' => Cpts::ARTIFACT_POST_TYPE,
			'object_id'   => (string) $artifactId,
			'meta'        => [ 'keep_share_links' => $keepShareLinks ],
		] );

		do_action( 'agent_brain/artifact_unpublished', $artifactId, $actorId, $keepShareLinks );

		return true;
	}

	/**
	 * §1.8: CAP_PUBLISH-gated (curator/lead only, unreachable by an agent
	 * identity), valid from any stage. Never deletes/trashes (Forget-
	 * compatible — a Forget cascade still finds and processes this post
	 * normally). Calls unpublish() first if currently published.
	 *
	 * @return true|\WP_Error
	 */
	public function archive( int $actorId, int $artifactId ) {
		$artifact = $this->requireArtifact( $actorId, $artifactId, null );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		if ( ! user_can( $actorId, Capabilities::CAP_PUBLISH ) ) {
			return $this->forbidden();
		}

		if ( $artifact->post_status === 'publish' ) {
			$unpublished = $this->unpublish( $actorId, $artifactId );
			if ( $unpublished instanceof \WP_Error ) {
				return $unpublished;
			}
		}

		update_post_meta( $artifactId, self::META_ARCHIVED_AT, gmdate( 'Y-m-d H:i:s' ) );

		do_action( 'agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => 'artifact_archive',
			'status'      => 'ok',
			'object_type' => Cpts::ARTIFACT_POST_TYPE,
			'object_id'   => (string) $artifactId,
			'meta'        => [],
		] );

		return true;
	}

	/** §1.8: an archived artifact is invisible to everyone but curators. */
	private function hiddenAsArchived( int $actorId, int $artifactId ): bool {
		return get_post_meta( $artifactId, self::META_ARCHIVED_AT, true ) !== '' && ! ObjectAccess::userIsCurator( $actorId );
	}

	/**
	 * Shared by compile() and assemble(): flattens the outline's section
	 * markup into one document — the "same markup rules as compile" the
	 * assemble contract calls for.
	 *
	 * §1.6: $requireApproved distinguishes the two callers' needs.
	 * `compile()` (false, default) stays usable at any artifact stage and
	 * EXCLUDES non-approved sections, annotating the gap with an HTML
	 * comment marker rather than silently producing a shorter document.
	 * `assemble()` (true) REFUSES entirely (returns \WP_Error
	 * `ab_unapproved_sections`, listing every offending section id) if any
	 * outline section is not approved — defense in depth; see assemble()'s
	 * own docblock for why this should never actually fire via the normal
	 * `ready`-gated path.
	 *
	 * Either mode returns \WP_Error `ab_foreign_sections` (422) if the outline
	 * names a section that is not a child of this artifact (ART-11).
	 *
	 * @return string|\WP_Error
	 */
	private function buildAssembledMarkup( \WP_Post $artifact, bool $requireApproved = false ) {
		$sectionIds = $this->parseOutline( $artifact );

		// ART-11: every outline entry must be a section of THIS artifact
		// (post_parent), otherwise a hand-edited outline could pull another
		// artifact's section into the compiled/assembled post. Same check
		// the share route makes.
		$foreign = [];
		foreach ( $sectionIds as $sectionId ) {
			$section = get_post( $sectionId );
			if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE || (int) $section->post_parent !== $artifact->ID ) {
				$foreign[] = $sectionId;
			}
		}

		if ( $foreign !== [] ) {
			return new \WP_Error(
				'ab_foreign_sections',
				sprintf(
					/* translators: %s: comma-separated section ids */
					__( 'The outline lists section(s) %s that do not belong to this artifact.', 'agent-brain' ),
					implode( ', ', $foreign )
				),
				[ 'status' => 422, 'section_ids' => $foreign ]
			);
		}

		if ( $requireApproved ) {
			$unapproved = [];
			foreach ( $sectionIds as $sectionId ) {
				if ( Stages::sectionState( $sectionId ) !== Cpts::SECTION_STATE_APPROVED ) {
					$unapproved[] = $sectionId;
				}
			}

			if ( $unapproved !== [] ) {
				return new \WP_Error(
					'ab_unapproved_sections',
					sprintf(
						/* translators: %s: comma-separated section ids */
						__( 'Cannot assemble: section(s) %s are not approved.', 'agent-brain' ),
						implode( ', ', $unapproved )
					),
					[ 'status' => 409, 'section_ids' => $unapproved ]
				);
			}
		}

		$content = '';
		foreach ( $sectionIds as $sectionId ) {
			$section = get_post( $sectionId );
			if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE ) {
				continue;
			}

			if ( ! $requireApproved && Stages::sectionState( $sectionId ) !== Cpts::SECTION_STATE_APPROVED ) {
				$content .= sprintf(
					"<!-- ab:excluded-section id=%d state=%s -->\n\n",
					$sectionId,
					Stages::sectionState( $sectionId )
				);
				continue;
			}

			$content .= (string) $section->post_content . "\n\n";
		}

		return trim( $content );
	}

	/**
	 * Bounded episode summary written to memory on assemble(): title +
	 * section titles + a short, plain-text excerpt of each section's text
	 * (never the full raw markup — the memory row is a summary, not a
	 * mirror of the assembled post).
	 *
	 * Follow-up wave, Unit G fix: this used to run section post_content
	 * through a bare `wp_strip_all_tags()`, which -- same bug as the
	 * pre-fix ReviewQueuePage excerpt -- glued a `core/table` block's cells
	 * together with no separator (live: "Results: Metric ValueRecall@5
	 * 91.1%Latency10.6 ms…"). It now goes through the same
	 * `Core\Text::plainTextFromBlocks()` every other plain-text extraction
	 * in the plugin uses, so table cells stay separated here too. Only the
	 * extraction changed; the 280-char-per-section bound and "…" cut marker
	 * are unchanged.
	 *
	 * @param int[] $sectionIds
	 */
	private function assemblySummary( \WP_Post $artifact, array $sectionIds ): string {
		$lines   = [];
		$lines[] = 'Artifact assembled: ' . $artifact->post_title;

		foreach ( $sectionIds as $sectionId ) {
			$section = get_post( $sectionId );
			if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE ) {
				continue;
			}

			$excerpt = trim( Text::plainTextFromBlocks( (string) $section->post_content ) );
			if ( function_exists( 'mb_substr' ) && mb_strlen( $excerpt ) > 280 ) {
				$excerpt = mb_substr( $excerpt, 0, 280 ) . '…';
			} elseif ( strlen( $excerpt ) > 280 ) {
				$excerpt = substr( $excerpt, 0, 280 ) . '…';
			}

			$lines[] = '- ' . $section->post_title . ': ' . $excerpt;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Section IDs in the order the artifact's outline stores them, without an
	 * access check. For rendering only (the edit screen board): callers must
	 * already have decided the viewer may see the artifact.
	 *
	 * @return int[]
	 */
	public function outlineSectionIds( int $artifactId ): array {
		$artifact = get_post( $artifactId );

		return $artifact instanceof \WP_Post && $artifact->post_type === Cpts::ARTIFACT_POST_TYPE
			? $this->parseOutline( $artifact )
			: [];
	}

	/**
	 * @return int[]
	 */
	private function parseOutline( \WP_Post $artifact ): array {
		$blocks = parse_blocks( (string) $artifact->post_content );
		$ids    = [];

		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? null ) === Block::NAME && isset( $block['attrs']['sectionId'] ) ) {
				$ids[] = (int) $block['attrs']['sectionId'];
			}
		}

		return $ids;
	}

	/**
	 * @param int[] $sectionIds
	 */
	private function serializeOutline( array $sectionIds ): string {
		$blocks = [];

		foreach ( $sectionIds as $sectionId ) {
			$blocks[] = [
				'blockName'    => Block::NAME,
				'attrs'        => [ 'sectionId' => $sectionId ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			];
		}

		return serialize_blocks( $blocks );
	}

	private function currentVersion( int $sectionId ): int {
		$raw = get_post_meta( $sectionId, Cpts::META_VERSION, true );

		return $raw === '' ? 1 : (int) $raw;
	}

	/**
	 * PROV-1 (#170): derived-from links for an item the pipeline built from
	 * an artifact — the artifact itself plus every section id WITH the
	 * section's version at build time (identifiers and versions only, never
	 * content). $replace=true swaps the link set (compile() rebuilding an
	 * existing draft); the default appends idempotently (assemble() creates
	 * each item once). No-op for a 0 $itemId (a failed memory insert must
	 * not mask the assembly itself).
	 *
	 * @param int[] $sectionIds
	 */
	private function recordBuildSources( string $itemKind, int $itemId, int $artifactId, array $sectionIds, bool $replace = false ): void {
		if ( $itemId <= 0 ) {
			return;
		}

		$sources = [ [ 'kind' => 'artifact', 'id' => $artifactId ] ];
		foreach ( $sectionIds as $sectionId ) {
			$sources[] = [
				'kind'    => 'section',
				'id'      => (int) $sectionId,
				'version' => $this->currentVersion( (int) $sectionId ),
			];
		}

		$derived = new DerivedFrom();
		if ( $replace ) {
			$derived->replace( $itemKind, $itemId, $sources );
		} else {
			$derived->link( $itemKind, $itemId, $sources );
		}
	}

	/**
	 * ART-4: genuine compare-and-set on ab_version.
	 *
	 * update_post_meta()'s $prev_value parameter only yields CAS semantics
	 * from WordPress core when a meta row for the key already exists —
	 * update_metadata() falls through to add_metadata() (which ignores
	 * $prev_value and is not unique) whenever it doesn't. To close that gap
	 * for a section's first-ever write, initializeVersion() (hooked on
	 * wp_insert_post) creates the ab_version=1 row synchronously as part of
	 * section creation, before any client can hold a section id to race on.
	 *
	 * For any pre-existing section that predates that hook (row missing),
	 * legacyFirstWriteCas() below serializes the insert-if-absent check via
	 * a MySQL named lock (GET_LOCK), because wp_postmeta carries no unique
	 * index on (post_id, meta_key) — add_post_meta()'s $unique=true is a
	 * plain SELECT-then-INSERT (add_metadata() with unique=true; see
	 * wp-includes/meta.php) and is NOT atomic on its own. The named lock is
	 * what actually closes the race for two genuinely concurrent
	 * first-writers on a legacy row; without it, both could observe an
	 * absent row and both insert.
	 */
	private function casVersion( int $sectionId, int $currentVersion, int $newVersion ): bool {
		$raw = get_post_meta( $sectionId, Cpts::META_VERSION, true );

		if ( $raw === '' ) {
			if ( $currentVersion !== 1 ) {
				return false;
			}

			return $this->legacyFirstWriteCas( $sectionId, $newVersion );
		}

		return update_post_meta( $sectionId, Cpts::META_VERSION, $newVersion, $currentVersion ) !== false;
	}

	/**
	 * Atomic insert-if-absent for a legacy section whose ab_version row is
	 * missing. Serializes concurrent first-writers with a MySQL session
	 * named lock scoped to this section id: whichever caller acquires the
	 * lock re-checks for the row under the lock (a rival may have inserted
	 * it while this caller waited) before inserting. Fails closed (returns
	 * false, never a silent "success") if the lock can't be acquired within
	 * the timeout, which surfaces to the caller as ab_stale_version rather
	 * than a lost update.
	 */
	private function legacyFirstWriteCas( int $sectionId, int $newVersion ): bool {
		global $wpdb;

		$lockName = 'ab_version_cas_' . $sectionId;

		// SQLite drop-in (#405): GET_LOCK is untranslated; skip the named
		// lock (requests are serialized there) and degrade to the version
		// comparison only. The finally block only releases when we took it.
		$locked = false;
		if ( ! SqliteRuntime::isActive() ) {
			$acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lockName ) );

			if ( $acquired !== 1 ) {
				// Fail loud: could not serialize against a rival first-writer
				// within the timeout, so refuse rather than risk a lost update.
				return false;
			}

			$locked = true;
		}

		try {
			$raw = get_post_meta( $sectionId, Cpts::META_VERSION, true );

			if ( $raw !== '' ) {
				// A rival writer already inserted the row while we waited
				// for the lock; this caller is no longer a valid first
				// writer.
				return false;
			}

			return add_post_meta( $sectionId, Cpts::META_VERSION, $newVersion, true ) !== false;
		} finally {
			if ( $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lockName ) );
			}
		}
	}

	/**
	 * ART-4: hooked on wp_insert_post so every ab_artifact_section gets an
	 * explicit ab_version=1 postmeta row at creation time, atomically
	 * (add_post_meta's $unique=true), rather than relying on
	 * currentVersion()'s "no row yet" fallback of 1 — which is what left
	 * the very first updateSection() call on a section without a genuine
	 * CAS guard.
	 */
	public static function initializeVersion( int $postId, \WP_Post $post, bool $update ): void {
		if ( $update || $post->post_type !== Cpts::SECTION_POST_TYPE ) {
			return;
		}

		add_post_meta( $postId, Cpts::META_VERSION, 1, true );
	}

	/**
	 * @return string[]
	 */
	private function allowedBlocks( int $sectionId ): array {
		$raw = get_post_meta( $sectionId, Cpts::META_ALLOWED_BLOCKS, true );

		return is_array( $raw ) && $raw !== [] ? array_values( array_filter( $raw, 'is_string' ) ) : Cpts::defaultAllowedBlocks();
	}

	/**
	 * §1.2: whether $sectionId's saved content reduces to empty text under
	 * the same block-text-extraction rules used elsewhere in this class
	 * (Core\Text::plainTextFromBlocks(), also used by assemblySummary()) —
	 * a section with zero blocks, or whose only block(s) carry no visible
	 * text, counts as empty. Checked at submit time (read path) rather
	 * than only at write time (BlockSanitizer::build()'s own
	 * ab_empty_block check), since content could in principle reach
	 * `submitted` through a path that bypassed updateSection() (defense in
	 * depth, not a known bypass being closed).
	 */
	private function sectionIsEffectivelyEmpty( int $sectionId ): bool {
		$section = get_post( $sectionId );
		if ( ! $section ) {
			return true;
		}

		return trim( Text::plainTextFromBlocks( (string) $section->post_content ) ) === '';
	}

	/**
	 * @return \WP_Post|\WP_Error
	 */
	private function requireArtifact( int $actorId, int $artifactId, ?string $cap ) {
		$post = get_post( $artifactId );
		if ( ! $post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return $this->notFound();
		}

		if ( $cap !== null && ! user_can( $actorId, $cap, $artifactId ) ) {
			return $this->forbidden();
		}

		return $post;
	}

	/**
	 * @return \WP_Post|\WP_Error
	 */
	private function requireSection( int $actorId, int $sectionId, string $cap ) {
		$post = get_post( $sectionId );
		if ( ! $post || $post->post_type !== Cpts::SECTION_POST_TYPE ) {
			return $this->notFound();
		}

		if ( ! user_can( $actorId, $cap, $sectionId ) ) {
			return $cap === 'edit_post' ? Refusals::notOwner( $actorId, $sectionId ) : $this->forbidden();
		}

		return $post;
	}

	private function forbidden(): \WP_Error {
		return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
	}

	private function notFound(): \WP_Error {
		return new \WP_Error( 'ab_not_found', __( 'Artifact or section not found.', 'agent-brain' ), [ 'status' => 404 ] );
	}

	private function staleVersion(): \WP_Error {
		return new \WP_Error(
			'ab_stale_version',
			__( 'This section changed since you last read it. Re-read before retrying.', 'agent-brain' ),
			[ 'status' => 409 ]
		);
	}

	private function sectionLocked(): \WP_Error {
		return new \WP_Error(
			'ab_section_locked',
			__( 'This section is locked (approved or the artifact is assembled) and cannot be edited.', 'agent-brain' ),
			[ 'status' => 409 ]
		);
	}
}
