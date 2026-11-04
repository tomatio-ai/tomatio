<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AskBrain;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Sharing\ViewerMode;

/**
 * ART-26 (#166): opt-in "ask the brain" for an artifact's share pages.
 *
 * The BrainRead\Mode / ART-24 ViewerMode pattern: a per-artifact post meta
 * flag evaluated on every request, off by default, curator (or ab_manage)
 * only, plus a site kill switch; additionally gated on viewer identity being
 * active for the artifact (the page cannot be attributed without it, and
 * the anonymous fallback only exists where a curator opted the artifact
 * into both).
 */
final class Mode {

	public const META_FLAG    = 'ab_artifact_ask_brain';
	public const META_CAP     = 'ab_artifact_ask_cap';
	public const OPT_DISABLED = 'ab_ask_brain_disabled';

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
	 * Curator (or ab_manage) only; identical to BrainRead\Mode::canToggle.
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
			return new \WP_Error( 'ab_not_found', __( 'Artifact not found.', 'tomatio' ), [ 'status' => 404 ] );
		}

		if ( ! self::canToggle( $actorId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'tomatio' ), [ 'status' => 403 ] );
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
				'event'       => 'artifact_ask_brain',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'enabled' => $on ],
			]
		);

		return true;
	}

	/**
	 * The artifact's daily ask budget in USD, or null for the default
	 * (Spend::DEFAULT_ARTIFACT_CAP_USD); 0 means unlimited.
	 */
	public static function cap( int $artifactId ): ?float {
		$raw = get_post_meta( $artifactId, self::META_CAP, true );

		return is_numeric( $raw ) ? (float) $raw : null;
	}

	/**
	 * Set the daily ask budget (curator or ab_manage only): null for the
	 * default, a negative amount counts as 0 (unlimited). Shared by the
	 * "Ask the brain" box and artifact-settings-set (#796).
	 *
	 * @return true|\WP_Error
	 */
	public static function setCap( int $artifactId, ?float $cap, int $actorId ) {
		$post = get_post( $artifactId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return new \WP_Error( 'ab_not_found', __( 'Artifact not found.', 'tomatio' ), [ 'status' => 404 ] );
		}

		if ( ! self::canToggle( $actorId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'tomatio' ), [ 'status' => 403 ] );
		}

		if ( $cap === null ) {
			delete_post_meta( $artifactId, self::META_CAP );

			return true;
		}

		$cap = max( 0.0, $cap );
		if ( self::cap( $artifactId ) === $cap ) {
			return true;
		}

		update_post_meta( $artifactId, self::META_CAP, (string) $cap );
		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'artifact_ask_cap',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'cap_usd' => $cap ],
			]
		);

		return true;
	}
}
