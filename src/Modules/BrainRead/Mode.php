<?php

declare(strict_types=1);

namespace AgentBrain\Modules\BrainRead;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Sharing\ViewerMode;

/**
 * ART-25 (#165): opt-in live brain reads for an artifact's share pages.
 *
 * The interactive-mode / ART-24 ViewerMode pattern: a per-artifact post meta
 * flag evaluated on every request, off by default, curator (or ab_manage)
 * only, plus a site kill switch. Per the ART-24 design ("the per-artifact
 * switch siblings gate their own switches on" ViewerMode), brain reads are
 * additionally gated on viewer identity being active for the artifact —
 * the page cannot name its viewers without it, and the anonymous fallback
 * only exists where a curator opted the artifact into both.
 */
final class Mode {

	public const META_FLAG    = 'ab_artifact_brain_read';
	public const OPT_DISABLED = 'ab_brain_read_disabled';

	public static function killSwitchOn(): bool {
		return (bool) get_option( self::OPT_DISABLED, false );
	}

	public static function isFlagged( int $artifactId ): bool {
		return get_post_meta( $artifactId, self::META_FLAG, true ) === '1';
	}

	/**
	 * True only when the kill switch is off and both this flag and the
	 * ART-24 viewer-identity switch are active for the artifact.
	 */
	public static function isActive( int $artifactId ): bool {
		return ! self::killSwitchOn() && self::isFlagged( $artifactId ) && ViewerMode::isActive( $artifactId );
	}

	/**
	 * Curator (or ab_manage) only; identical to ViewerMode::canToggle.
	 */
	public static function canToggle( int $actorId ): bool {
		return $actorId > 0 && ( ObjectAccess::userIsCurator( $actorId ) || user_can( $actorId, Capabilities::CAP_MANAGE ) );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set( int $artifactId, bool $on, int $actorId ) {
		$post = get_post( $artifactId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return new \WP_Error( 'ab_not_found', __( 'Artifact not found.', 'agent-brain' ), [ 'status' => 404 ] );
		}

		if ( ! self::canToggle( $actorId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( self::isFlagged( $artifactId ) === $on ) {
			return true;
		}

		if ( $on ) {
			update_post_meta( $artifactId, self::META_FLAG, '1' );
		} else {
			delete_post_meta( $artifactId, self::META_FLAG );
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'artifact_brain_read',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'enabled' => $on ],
			]
		);

		return true;
	}
}
