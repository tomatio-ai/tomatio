<?php

declare(strict_types=1);

namespace AgentBrain\Modules\BrainRead;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Sharing\ViewerMode;

/**
 * Curator-only "Live brain reads" toggle on the artifact edit screen
 * (ART-25, #165) — the same small side-box pattern as the ART-24
 * ViewerMetaBox, kept separate so it never touches the main artifact box.
 */
final class MetaBox {

	public const NONCE_ACTION = 'ab_brain_read_toggle';
	public const NONCE_FIELD  = 'ab_brain_read_nonce';
	public const FIELD        = 'ab_brain_read';

	public static function register(): void {
		add_action( 'init', [ self::class, 'registerMeta' ] );
		add_action( 'add_meta_boxes_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'addMetaBox' ] );
		add_action( 'save_post_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'save' ], 10, 1 );
	}

	/**
	 * The artifact CPT supports custom-fields, so the flag must not be
	 * writable through the generic meta APIs / Custom Fields box by
	 * non-curators.
	 */
	public static function registerMeta(): void {
		register_post_meta(
			Cpts::ARTIFACT_POST_TYPE,
			Mode::META_FLAG,
			[
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => static fn ( $allowed, $metaKey, $objectId, $userId ): bool => Mode::canToggle( (int) $userId ),
			]
		);
	}

	public static function addMetaBox( \WP_Post $post ): void {
		unset( $post );
		if ( ! Mode::canToggle( get_current_user_id() ) ) {
			return;
		}

		add_meta_box( 'ab_artifact_brain_read', __( 'Live brain reads', 'agent-brain' ), [ self::class, 'render' ], Cpts::ARTIFACT_POST_TYPE, 'side', 'low' );
	}

	public static function render( \WP_Post $post ): void {
		echo self::html( (int) $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* in html().
	}

	public static function html( int $artifactId ): string {
		$out  = wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false );
		$out .= '<p><label><input type="checkbox" name="' . esc_attr( self::FIELD ) . '" value="1"' . checked( Mode::isFlagged( $artifactId ), true, false ) . ' /> ';
		$out .= esc_html__( 'Let share pages of this artifact read live brain data in its scope', 'agent-brain' ) . '</label></p>';
		$out .= '<p class="description">' . esc_html__( 'Pages can search and read memories and approved knowledge of the artifact\'s scope: named members with a read grant see what their grants allow, everyone else the scope read-only. Requires viewer identity. Off by default.', 'agent-brain' ) . '</p>';
		if ( Mode::killSwitchOn() ) {
			$out .= '<p class="description"><strong>' . esc_html__( 'Live brain reads are disabled site-wide.', 'agent-brain' ) . '</strong></p>';
		} elseif ( ! ViewerMode::isActive( $artifactId ) ) {
			$out .= '<p class="description"><strong>' . esc_html__( 'Requires viewer identity to be enabled for this artifact.', 'agent-brain' ) . '</strong></p>';
		}

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
		Mode::set( $postId, $on, get_current_user_id() );
	}
}
