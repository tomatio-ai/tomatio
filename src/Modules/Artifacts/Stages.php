<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Core\ActionContext;

/**
 * Multi-agent authoring pipeline: server-side enforced stage machine for
 * ab_artifact (`ab_stage`) and ab_artifact_section (`ab_section_state`).
 *
 * outline -> drafting -> revising -> ready -> assembled (artifact)
 * drafting -> submitted -> approved                       (section)
 *                       \-> changes_requested -> submitted (loop back)
 *
 * Every transition here is a pure decision (old state + reason -> allowed?)
 * so ArtifactService can call it, then persist + audit atomically. Illegal
 * transitions are never silently coerced — the caller always gets a
 * WP_Error naming the current and attempted state.
 */
class Stages {

	/**
	 * A3: where an unparseable stored review log is preserved before it gets
	 * overwritten by appendReviewLog(). Not a Cpts:: constant — this unit's
	 * file boundary does not include Cpts.php, and this key is internal to
	 * Stages' own persistence, not part of the cross-module contract.
	 */
	private const META_REVIEW_LOG_CORRUPT = 'ab_review_log_corrupt';

	/**
	 * PROV-6 (#175): bound on ab_action_log entries per section; the newest
	 * entries are kept when a long-lived section accumulates more writes
	 * than this (the log is action context, not a full history — per-
	 * revision blame is PROV-5/#174).
	 */
	private const MAX_ACTION_LOG_ENTRIES = 50;

	/** @var array<string, string[]> */
	private const ARTIFACT_TRANSITIONS = [
		Cpts::STAGE_OUTLINE   => [ Cpts::STAGE_DRAFTING ],
		Cpts::STAGE_DRAFTING  => [ Cpts::STAGE_REVISING ],
		Cpts::STAGE_REVISING  => [ Cpts::STAGE_READY ],
		Cpts::STAGE_READY     => [ Cpts::STAGE_ASSEMBLED, Cpts::STAGE_REVISING ],
		// §1.7: a curator/lead may reopen an already-assembled artifact for
		// further revision (ArtifactService::reopen()). Per Roger's
		// decision (§7.5), reopening a PUBLISHED artifact does not touch
		// its live public share link — that is a property of the
		// unrelated post_status/share-link state, not this stage machine.
		Cpts::STAGE_ASSEMBLED => [ Cpts::STAGE_REVISING ],
	];

	public static function artifactStage( int $artifactId ): string {
		$raw = (string) get_post_meta( $artifactId, Cpts::META_STAGE, true );

		return in_array( $raw, Cpts::stages(), true ) ? $raw : Cpts::STAGE_OUTLINE;
	}

	public static function canTransitionArtifact( string $from, string $to ): bool {
		return in_array( $to, self::ARTIFACT_TRANSITIONS[ $from ] ?? [], true );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function transitionArtifact( int $actorId, int $artifactId, string $to, string $reason ) {
		$from = self::artifactStage( $artifactId );

		if ( $from === $to ) {
			return true;
		}

		if ( ! self::canTransitionArtifact( $from, $to ) ) {
			return new \WP_Error(
				'ab_invalid_stage_transition',
				sprintf(
					/* translators: 1: current stage, 2: attempted stage */
					__( 'Cannot move artifact from stage "%1$s" to "%2$s".', 'agent-brain' ),
					$from,
					$to
				),
				[ 'status' => 409, 'from' => $from, 'to' => $to ]
			);
		}

		update_post_meta( $artifactId, Cpts::META_STAGE, $to );

		do_action( 'agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => 'artifact_stage_transition',
			'status'      => 'ok',
			'object_type' => Cpts::ARTIFACT_POST_TYPE,
			'object_id'   => (string) $artifactId,
			'meta'        => [ 'from' => $from, 'to' => $to, 'reason' => $reason ],
		] );

		return true;
	}

	public static function sectionState( int $sectionId ): string {
		$raw = (string) get_post_meta( $sectionId, Cpts::META_SECTION_STATE, true );

		return in_array( $raw, Cpts::sectionStates(), true ) ? $raw : Cpts::SECTION_STATE_DRAFTING;
	}

	public static function setSectionState( int $sectionId, string $state ): void {
		update_post_meta( $sectionId, Cpts::META_SECTION_STATE, $state );
	}

	/**
	 * @return bool True when a section may be content-edited (updateSection).
	 */
	public static function sectionIsEditable( int $sectionId ): bool {
		return in_array(
			self::sectionState( $sectionId ),
			[ Cpts::SECTION_STATE_DRAFTING, Cpts::SECTION_STATE_CHANGES_REQUESTED ],
			true
		);
	}

	/**
	 * @param int[] $sectionIds
	 */
	public static function allSectionsApproved( array $sectionIds ): bool {
		if ( $sectionIds === [] ) {
			return false;
		}

		foreach ( $sectionIds as $sectionId ) {
			if ( self::sectionState( $sectionId ) !== Cpts::SECTION_STATE_APPROVED ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * §1.7: curator/lead-only "un-approve" — moves a single `approved`
	 * section back to `changes_requested` via ArtifactService::reviewSection()'s
	 * new `reopen` verdict. Deliberately distinct from the reviewer-only
	 * `approve`/`request_changes` verdicts (which are gated by "cannot
	 * review your own section" in the caller): a curator/lead reopening
	 * their own prior approval is not a conflict-of-interest review, so
	 * that restriction does not apply here. No new MCP ability needed —
	 * `agent-brain/section-review` already exists and is already
	 * leak-suite covered.
	 *
	 * @return true|\WP_Error
	 */
	public static function reopenSection( int $sectionId ) {
		$state = self::sectionState( $sectionId );

		if ( $state !== Cpts::SECTION_STATE_APPROVED ) {
			return new \WP_Error(
				'ab_invalid_section_transition',
				sprintf(
					/* translators: %s: current section state */
					__( 'Cannot reopen a section in state "%s"; it must be approved.', 'agent-brain' ),
					$state
				),
				[ 'status' => 409, 'from' => $state ]
			);
		}

		self::setSectionState( $sectionId, Cpts::SECTION_STATE_CHANGES_REQUESTED );

		return true;
	}

	/**
	 * @return array<int, array{version:int,reviewer_id:int,verdict:string,notes:string,at:string,client?:?string,via?:?int,ai?:bool}>
	 */
	public static function reviewLog( int $sectionId ): array {
		$raw = get_post_meta( $sectionId, Cpts::META_REVIEW_LOG, true );
		$log = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;

		return is_array( $log ) ? $log : [];
	}

	public static function appendReviewLog( int $sectionId, int $version, int $reviewerId, string $verdict, string $notes ): void {
		// A3: never silently overwrite a stored value that fails to parse as
		// a JSON array — copy it to ab_review_log_corrupt first so the
		// history is preserved instead of vanishing behind the next write.
		$raw = get_post_meta( $sectionId, Cpts::META_REVIEW_LOG, true );
		if ( is_string( $raw ) && $raw !== '' && ! is_array( json_decode( $raw, true ) ) ) {
			add_post_meta( $sectionId, self::META_REVIEW_LOG_CORRUPT, wp_slash( $raw ), false );
		}

		// PROV-6 (#175): stamp the approval's action context — which MCP
		// client the verdict arrived on (null from wp-admin/WP-CLI), which
		// person a delegated token acted for, and whether an AI client
		// relayed it. Read from the Core request registries, never from
		// caller input. Existing log entries lack these keys: readers must
		// treat absent client/ai as "unknown, pre-PROV-6".
		$context = ActionContext::actorContext();

		$log   = self::reviewLog( $sectionId );
		$log[] = [
			'version'     => $version,
			'reviewer_id' => $reviewerId,
			'verdict'     => $verdict,
			'notes'       => $notes,
			'at'          => gmdate( 'Y-m-d H:i:s' ),
			'client'      => $context['client'],
			'via'         => $context['via'] > 0 ? $context['via'] : null,
			'ai'          => $context['ai'],
		];

		// A2: $notes is caller-supplied text (a reviewer's free-text review
		// note) — JSON_UNESCAPED_UNICODE keeps non-ASCII (em dash, emoji) as
		// real UTF-8 rather than \uXXXX escapes, and wp_slash() makes
		// WordPress' unslash-on-save round-trip quotes/backslashes exactly.
		update_post_meta( $sectionId, Cpts::META_REVIEW_LOG, wp_slash( wp_json_encode( $log, JSON_UNESCAPED_UNICODE ) ) );
	}

	/**
	 * PROV-6 (#175): appends one action-context entry to the section's
	 * ab_action_log for a content write — the exact version written, the
	 * actor, the client/via/AI label, and the MCP session's read set since
	 * its last write (consumed here; identifiers only, never content).
	 * No-op when the site switch is off. Bounded to the newest
	 * MAX_ACTION_LOG_ENTRIES entries.
	 */
	public static function appendActionLog( int $sectionId, int $version, int $actorId ): void {
		if ( ! ActionContext::enabled() || $sectionId <= 0 || $version <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return;
		}

		$context = ActionContext::actorContext();

		$entry = [
			'version'   => $version,
			'actor_id'  => $actorId,
			'client'    => $context['client'],
			'via'       => $context['via'] > 0 ? $context['via'] : null,
			'ai'        => $context['ai'],
			'read_set'  => ActionContext::consume(),
			'at'        => gmdate( 'Y-m-d H:i:s' ),
		];

		$raw = get_post_meta( $sectionId, Cpts::META_ACTION_LOG, true );
		$log = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : [];
		if ( ! is_array( $log ) ) {
			$log = [];
		}

		$log[] = $entry;
		$log   = array_slice( $log, - self::MAX_ACTION_LOG_ENTRIES );

		update_post_meta( $sectionId, Cpts::META_ACTION_LOG, wp_slash( wp_json_encode( $log, JSON_UNESCAPED_UNICODE ) ) );
	}

	/**
	 * @return array<int, array{version:int, actor_id:int, client:?string, via:?int, ai:bool, read_set:array<int, array{kind:string, id:int, version?:int}>, at:string}>
	 */
	public static function actionLog( int $sectionId ): array {
		$raw = get_post_meta( $sectionId, Cpts::META_ACTION_LOG, true );
		$log = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;

		return is_array( $log ) ? $log : [];
	}
}
