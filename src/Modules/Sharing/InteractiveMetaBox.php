<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Cpts;

/**
 * Curator-only "Interactive mode" toggle on the artifact edit screen (#61).
 * A separate small box so it does not touch the main artifact screen box.
 */
final class InteractiveMetaBox {

	public const NONCE_ACTION = 'ab_interactive_toggle';
	public const NONCE_FIELD  = 'ab_interactive_nonce';
	public const FIELD        = 'ab_interactive';

	public static function register(): void {
		add_action( 'init', [ self::class, 'registerMeta' ] );
		add_action( 'add_meta_boxes_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'addMetaBox' ] );
		add_action( 'save_post_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'save' ], 10, 1 );
	}

	/**
	 * The artifact CPT supports custom-fields, so the flag must not be writable
	 * through the generic meta APIs / Custom Fields box by non-curators.
	 */
	public static function registerMeta(): void {
		register_post_meta(
			Cpts::ARTIFACT_POST_TYPE,
			Interactive::META_FLAG,
			[
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => static fn ( $allowed, $metaKey, $objectId, $userId ): bool => Interactive::canToggle( (int) $userId ),
			]
		);
	}

	public static function addMetaBox( \WP_Post $post ): void {
		unset( $post );
		if ( ! Interactive::canToggle( get_current_user_id() ) ) {
			return;
		}

		add_meta_box( 'ab_artifact_interactive', __( 'Interactive mode', 'agent-brain' ), [ self::class, 'render' ], Cpts::ARTIFACT_POST_TYPE, 'side', 'low' );
	}

	public static function render( \WP_Post $post ): void {
		echo self::html( (int) $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* in html().
	}

	public static function html( int $artifactId ): string {
		$out  = wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false );
		$out .= '<p><label><input type="checkbox" name="' . esc_attr( self::FIELD ) . '" value="1"' . checked( Interactive::isFlagged( $artifactId ), true, false ) . ' /> ';
		$out .= esc_html__( 'Allow scripts in share links (sandboxed)', 'agent-brain' ) . '</label></p>';
		$out .= '<p class="description">' . esc_html__( 'Share links of this artifact run inline scripts in an isolated sandbox: no network, no forms, no cookies. Off by default.', 'agent-brain' ) . '</p>';
		if ( Interactive::killSwitchOn() ) {
			$out .= '<p class="description"><strong>' . esc_html__( 'Interactive mode is disabled site-wide; links stay static.', 'agent-brain' ) . '</strong></p>';
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
		Interactive::set( $postId, $on, get_current_user_id() );
	}
}
