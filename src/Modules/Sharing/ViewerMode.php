<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * ART-24 (#164): opt-in viewer identity for an artifact's share pages.
 *
 * The mode is a property of the ARTIFACT (post meta), evaluated on every
 * request, never stored on a link — the same pattern as interactive mode
 * (#61): a link minted while the flag is off serves anonymous viewers until
 * the flag (or the kill switch) turns on. Only when it is on AND the share
 * link itself was minted for named members (viewer_mode=members) may a brain
 * member exchange a code for a signed viewer token (ViewerExchange).
 */
final class ViewerMode {

	public const META_FLAG    = 'ab_artifact_viewer_identity';
	public const OPT_DISABLED = 'ab_viewer_identity_disabled';

	public static function killSwitchOn(): bool {
		return (bool) get_option( self::OPT_DISABLED, false );
	}

	public static function isFlagged( int $artifactId ): bool {
		return get_post_meta( $artifactId, self::META_FLAG, true ) === '1';
	}

	/**
	 * True only when the kill switch is off, sharing is enabled and the
	 * artifact opted in.
	 */
	public static function isActive( int $artifactId ): bool {
		return ! self::killSwitchOn() && ! Config::disabled() && self::isFlagged( $artifactId );
	}

	/**
	 * Curator (or ab_manage) only; being the lead agent is not enough —
	 * identical to the interactive-mode toggle.
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
				'event'       => $on ? 'artifact_viewer_identity_enabled' : 'artifact_viewer_identity_disabled',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [],
			]
		);

		return true;
	}
}
