<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\AdminUi;
use AgentBrain\Modules\Artifacts\ArtifactService;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Artifacts\Stages;

/**
 * #754: the people who can review a section artifact see its waiting
 * sections on the members address, so a link to a note that was just
 * submitted is never empty for the person meant to review it.
 *
 * - Reviewers (curators, managers, the lead and the person behind it, a
 *   reviewer with the approval grant: the people #750 links to the
 *   artifact's screen) get the submitted sections in the page, each marked
 *   "Waiting for review", and a bar above the frame with Approve all.
 * - Everyone else still sees approved sections only, exactly as before.
 *
 * Approving goes through the same check and service as the artifact's
 * screen (AdminUi::reviewActions, ArtifactService::reviewSection), one
 * section at a time; requesting changes needs notes, so it stays on that
 * screen. The buttons live on the members page itself, never inside the
 * sandboxed frame, which has no sign-in.
 */
final class WaitingSections {

	public const ACTION   = 'ab_sections_approve';
	public const NONCE    = 'ab_sections_approve_';
	public const APPROVED = 'ab_approved';

	/** Whether $actorId may see the sections waiting for review. */
	public static function canSee( int $actorId, int $artifactId ): bool {
		return MembersAddress::canViewVersions( $actorId, $artifactId )
			|| ObjectAccess::userIsBehindLead( $actorId, $artifactId )
			|| ObjectAccess::userCanReviewAndAssemble( $actorId, $artifactId );
	}

	/**
	 * The artifact's outline sections by state, in outline order.
	 *
	 * @return array{approved:int[],waiting:int[]}
	 */
	public static function outline( int $artifactId ): array {
		$out = [ 'approved' => [], 'waiting' => [] ];
		foreach ( array_unique( ( new ArtifactService() )->outlineSectionIds( $artifactId ) ) as $sectionId ) {
			$section = get_post( $sectionId );
			if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE || (int) $section->post_parent !== $artifactId ) {
				continue;
			}

			$state = Stages::sectionState( $sectionId );
			if ( $state === Cpts::SECTION_STATE_APPROVED ) {
				$out['approved'][] = (int) $sectionId;
			} elseif ( $state === Cpts::SECTION_STATE_SUBMITTED ) {
				$out['waiting'][] = (int) $sectionId;
			}
		}

		return $out;
	}

	/**
	 * The waiting sections $actorId may approve (the artifact screen's own rule).
	 *
	 * @param int[] $waiting
	 * @return int[]
	 */
	public static function approvable( int $actorId, array $waiting ): array {
		return array_values( array_filter( $waiting, static fn ( int $id ): bool => isset( AdminUi::reviewActions( $actorId, $id )['approve'] ) ) );
	}

	/**
	 * The bar above the frame for a reviewer: how many sections wait, that
	 * they are marked in the page, Approve all (when this reviewer may) and
	 * a link to the artifact's screen for one-by-one review.
	 *
	 * @param int[] $waiting
	 */
	public static function bar( int $artifactId, int $actorId, array $waiting, bool $nothingApproved ): string {
		$count = count( $waiting );
		$line  = $nothingApproved
			? sprintf(
				/* translators: %d: number of sections submitted for review. */
				_n( 'Nothing approved yet: %d section is waiting for review.', 'Nothing approved yet: %d sections are waiting for review.', $count, 'agent-brain' ),
				$count
			)
			: sprintf(
				/* translators: %d: number of sections submitted for review. */
				_n( '%d section is waiting for review.', '%d sections are waiting for review.', $count, 'agent-brain' ),
				$count
			);
		$line .= ' ' . _n( 'It is marked in the page below, and only reviewers see it until it is approved.', 'They are marked in the page below, and only reviewers see them until they are approved.', $count, 'agent-brain' );

		$approve = '';
		$mine    = self::approvable( $actorId, $waiting );
		if ( $mine !== [] ) {
			$label   = count( $mine ) === 1
				? __( 'Approve', 'agent-brain' )
				/* translators: %d: number of sections. */
				: sprintf( __( 'Approve all %d', 'agent-brain' ), count( $mine ) );
			$approve = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
				. '<input type="hidden" name="artifact_id" value="' . esc_attr( (string) $artifactId ) . '">'
				. wp_nonce_field( self::NONCE . $artifactId, '_wpnonce', false, false )
				. '<button type="submit">' . esc_html( $label ) . '</button></form>';
		}

		return '<div class="ab-pending-note ab-review-bar" role="status"><span>' . esc_html( $line ) . '</span>'
			. $approve
			. '<a href="' . esc_url( admin_url( 'post.php?post=' . $artifactId . '&action=edit' ) ) . '">' . esc_html__( 'Review them', 'agent-brain' ) . '</a></div>';
	}

	/**
	 * Approve every waiting section $actorId may approve. Returns how many
	 * were approved, or the first error (sections approved before it stay
	 * approved).
	 *
	 * @return int|\WP_Error
	 */
	public static function approveAll( int $actorId, int $artifactId ) {
		$service = new ArtifactService();
		$done    = 0;
		foreach ( self::approvable( $actorId, self::outline( $artifactId )['waiting'] ) as $sectionId ) {
			$result = $service->reviewSection( $actorId, $sectionId, 'approve', '' );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}
			++$done;
		}

		return $done;
	}

	/** admin-post handler: Approve all, then back to the members address. */
	public static function handle(): void {
		$artifactId = isset( $_POST['artifact_id'] ) ? absint( $_POST['artifact_id'] ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		check_admin_referer( self::NONCE . $artifactId );

		$actorId = get_current_user_id();
		if ( ! MembersAddress::canSeeAddress( $actorId, $artifactId ) || self::approvable( $actorId, self::outline( $artifactId )['waiting'] ) === [] ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'agent-brain' ), '', [ 'response' => 403 ] );
		}

		$result = self::approveAll( $actorId, $artifactId );
		if ( $result instanceof \WP_Error ) {
			wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 409, 'back_link' => true ] );
		}

		wp_safe_redirect( add_query_arg( self::APPROVED, (string) $result, MembersAddress::url( $artifactId ) ) );
		exit;
	}
}
