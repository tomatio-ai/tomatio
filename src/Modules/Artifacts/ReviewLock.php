<?php

declare( strict_types=1 );

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Modules\Identity\Capabilities;

/**
 * "Review required" (#629): a per-artifact lock that keeps delegated
 * authority (OAuth\DelegatedAuthority) from publishing it. While it is set,
 * a request made through an AI connection lands as a draft for review, even
 * on a connection the person gave delegated authority: no one-step section
 * publish, no delegated create of its content, and no HTML version approved
 * by an agent (a person approves it themselves, for example in wp-admin).
 *
 * Its lead, a curator or a manager may set it (the artifact's side box, or
 * review_required on artifact-create). Only a person may clear it, never an
 * agent identity, so an AI cannot unlock an artifact and then publish it.
 * It shows on the artifact screen and in artifact-status.
 */
final class ReviewLock {

	public const META         = 'ab_review_required';
	public const NONCE_ACTION = 'ab_review_lock_toggle';
	public const NONCE_FIELD  = 'ab_review_lock_nonce';
	public const FIELD        = 'ab_review_required';
	public const BOX_ID       = 'ab_artifact_review_lock';

	public static function register(): void {
		add_action( 'init', [ self::class, 'registerMeta' ], 21 );
		add_action( 'add_meta_boxes_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'addMetaBox' ] );
		add_action( 'save_post_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'save' ], 10, 1 );
	}

	/** Written only through set(): keep the generic meta APIs out of it. */
	public static function registerMeta(): void {
		register_post_meta(
			Cpts::ARTIFACT_POST_TYPE,
			self::META,
			[
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => static fn (): bool => false,
			]
		);
	}

	public static function isLocked( int $artifactId ): bool {
		return $artifactId > 0 && get_post_meta( $artifactId, self::META, true ) === '1';
	}

	/** The lead, a person behind the lead, a curator or a manager. */
	public static function canLock( int $actorId, int $artifactId ): bool {
		if ( $actorId <= 0 ) {
			return false;
		}

		return ObjectAccess::userIsCurator( $actorId )
			|| ObjectAccess::userIsLead( $actorId, $artifactId )
			|| ObjectAccess::userIsBehindLead( $actorId, $artifactId )
			|| user_can( $actorId, Capabilities::CAP_MANAGE );
	}

	/** As canLock(), but never an agent identity (not even one acting via a person). */
	public static function canUnlock( int $actorId, int $artifactId ): bool {
		$user = get_userdata( $actorId );
		if ( ! $user instanceof \WP_User || in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			return false;
		}

		return self::canLock( $actorId, $artifactId );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set( int $artifactId, bool $on, int $actorId ) {
		$post = get_post( $artifactId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return new \WP_Error( 'ab_not_found', __( 'Artifact or section not found.', 'agent-brain' ), [ 'status' => 404 ] );
		}

		if ( self::isLocked( $artifactId ) === $on ) {
			return true;
		}

		if ( $on ? ! self::canLock( $actorId, $artifactId ) : ! self::canUnlock( $actorId, $artifactId ) ) {
			return new \WP_Error(
				'ab_forbidden',
				$on
					? __( 'Only the artifact\'s lead, a curator or a manager can require review.', 'agent-brain' )
					: __( 'Only a person (the lead\'s person, a curator or a manager) can stop requiring review; an agent cannot.', 'agent-brain' ),
				[ 'status' => 403 ]
			);
		}

		if ( $on ) {
			update_post_meta( $artifactId, self::META, '1' );
		} else {
			delete_post_meta( $artifactId, self::META );
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => $on ? 'artifact_review_lock_on' : 'artifact_review_lock_off',
				'status'      => 'ok',
				'object_type' => Cpts::ARTIFACT_POST_TYPE,
				'object_id'   => (string) $artifactId,
				'meta'        => [],
			]
		);

		return true;
	}

	/** Why a delegated request fell back to review on a locked artifact. */
	public static function message(): string {
		return __( 'This artifact is locked as "Review required": even when the person asks, changes go through normal review. Submit the section (section-submit) or leave the version for a person to approve.', 'agent-brain' );
	}

	public static function addMetaBox( \WP_Post $post ): void {
		if ( ! self::canLock( get_current_user_id(), (int) $post->ID ) ) {
			return;
		}

		add_meta_box( self::BOX_ID, __( 'Review required', 'agent-brain' ), [ self::class, 'render' ], Cpts::ARTIFACT_POST_TYPE, 'side', 'default' );
	}

	public static function render( \WP_Post $post ): void {
		echo self::html( (int) $post->ID, get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* in html().
	}

	public static function html( int $artifactId, int $actorId ): string {
		$locked   = self::isLocked( $artifactId );
		$disabled = $locked && ! self::canUnlock( $actorId, $artifactId );

		$out  = wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false );
		$out .= '<p><label><input type="checkbox" name="' . esc_attr( self::FIELD ) . '" value="1"' . checked( $locked, true, false ) . disabled( $disabled, true, false ) . ' /> ';
		$out .= esc_html__( 'Review required, even when a person asks their AI', 'agent-brain' ) . '</label></p>';
		$out .= '<p class="description">' . esc_html__( 'AI connections with delegated authority can create, publish and approve in one step when their person asks. With this on, their changes to this artifact still land as drafts for normal review. Only a person can turn it off.', 'agent-brain' ) . '</p>';

		return $out;
	}

	public static function save( int $postId ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( $nonce === '' || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$on = isset( $_POST[ self::FIELD ] ) && (string) wp_unslash( $_POST[ self::FIELD ] ) === '1'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to a literal.
		self::set( $postId, $on, get_current_user_id() );
	}
}
