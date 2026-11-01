<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Uploads;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Sharing\Config;

/**
 * ART-27 (#167): opt-in asset uploads for an artifact's share pages — the
 * ViewerMode pattern (ART-24) applied to uploads: a per-artifact post meta
 * flag evaluated on every request, off by default, plus a site kill switch.
 * Only when active do the share-path upload endpoints and the MCP abilities
 * exist at all; with the switch off the route is the uniform 404.
 */
final class UploadMode {

	public const META_FLAG    = 'ab_artifact_uploads_enabled';
	public const OPT_DISABLED = 'ab_uploads_disabled';

	public static function killSwitchOn(): bool {
		return (bool) get_option( self::OPT_DISABLED, false );
	}

	public static function isFlagged( int $artifactId ): bool {
		return get_post_meta( $artifactId, self::META_FLAG, true ) === '1';
	}

	/**
	 * True only when the kill switch is off, sharing is enabled and the
	 * artifact opted in. Uploads exist only under share links, so a site
	 * with sharing disabled has no upload endpoints either.
	 */
	public static function isActive( int $artifactId ): bool {
		return ! self::killSwitchOn() && ! Config::disabled() && self::isFlagged( $artifactId );
	}

	/**
	 * Curator (or ab_manage) only; identical to the viewer identity and
	 * interactive-mode toggles.
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
				'event'       => $on ? 'artifact_uploads_enabled' : 'artifact_uploads_disabled',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [],
			]
		);

		return true;
	}
}
