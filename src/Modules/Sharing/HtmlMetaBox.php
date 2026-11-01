<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;

/**
 * "HTML page" box on the artifact screen of an HTML artifact (#138): files and
 * versions, approval, and a preview of the approved version in an iframe
 * sandboxed without allow-same-origin (srcdoc, so no preview-host token is
 * needed and the scripts cannot reach wp-admin).
 */
final class HtmlMetaBox {

	public const NONCE_ACTION = 'ab_html_approve';
	public const NONCE_FIELD  = 'ab_html_nonce';
	public const FIELD        = 'ab_html_approve_version';
	public const FIELD_LATEST = 'ab_html_serve_latest';
	public const FIELD_RELOAD = 'ab_html_auto_reload';

	public static function register(): void {
		add_action( 'add_meta_boxes_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'addMetaBox' ] );
		add_action( 'save_post_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'save' ], 10, 1 );
	}

	public static function addMetaBox( \WP_Post $post ): void {
		if ( ! HtmlFiles::isHtml( (int) $post->ID ) ) {
			return;
		}

		add_meta_box( 'ab_artifact_html', __( 'HTML page', 'agent-brain' ), [ self::class, 'render' ], Cpts::ARTIFACT_POST_TYPE, 'normal', 'default' );
	}

	public static function render( \WP_Post $post ): void {
		echo self::html( (int) $post->ID, get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* in html().
	}

	public static function html( int $artifactId, int $actorId ): string {
		$versions = HtmlFiles::versions( $artifactId );
		$approved = HtmlFiles::approvedNumber( $artifactId );
		$latest   = HtmlFiles::latestNumber( $artifactId );
		$canEdit  = HtmlFiles::canEdit( $actorId, $artifactId );

		$out = '<p>' . esc_html( sprintf(
			/* translators: 1: approved version number or none, 2: latest version number. */
			__( 'Approved version: %1$s. Latest version: %2$s.', 'agent-brain' ),
			$approved > 0 ? (string) $approved : __( 'none', 'agent-brain' ),
			(string) $latest
		) ) . '</p>';

		if ( $versions !== [] ) {
			$out .= '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Version', 'agent-brain' ) . '</th><th>' . esc_html__( 'Files', 'agent-brain' ) . '</th><th>' . esc_html__( 'Uploaded', 'agent-brain' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( $versions ) as $row ) {
				$out .= '<tr><td>' . esc_html( (string) $row['version'] ) . '</td><td>' . esc_html( implode( ', ', array_keys( $row['files'] ) ) ) . '</td><td>' . esc_html( $row['created_at'] ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}

		if ( $canEdit && $versions !== [] ) {
			$out .= wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false );
			$out .= '<p><label>' . esc_html__( 'Approve version', 'agent-brain' ) . ' <select name="' . esc_attr( self::FIELD ) . '"><option value="">&mdash;</option>';
			foreach ( array_reverse( $versions ) as $row ) {
				$out .= '<option value="' . esc_attr( (string) $row['version'] ) . '">' . esc_html( (string) $row['version'] ) . '</option>';
			}
			$out .= '</select></label></p>';
			if ( HtmlFiles::canSetMode( $actorId ) ) {
				$out .= '<p><label><input type="checkbox" name="' . esc_attr( self::FIELD_LATEST ) . '" value="1"' . checked( HtmlFiles::mode( $artifactId ) === HtmlFiles::MODE_LATEST, true, false ) . ' /> ' . esc_html__( 'Share links serve the latest version without approval', 'agent-brain' ) . '</label></p>';
			}
			// #469: a hidden 0 first, so an unticked box is saved as off.
			$out .= '<p><input type="hidden" name="' . esc_attr( self::FIELD_RELOAD ) . '" value="0" /><label><input type="checkbox" name="' . esc_attr( self::FIELD_RELOAD ) . '" value="1"' . checked( HtmlFiles::autoReload( $artifactId ), true, false ) . ' /> ' . esc_html__( 'Reload open pages when a new version is approved', 'agent-brain' ) . '</label></p>';
		}

		$shown = $approved > 0 ? HtmlFiles::version( $artifactId, $approved ) : null;
		if ( $shown === null ) {
			return $out . '<p class="description">' . esc_html__( 'Nothing approved yet, so there is nothing to preview.', 'agent-brain' ) . '</p>';
		}

		return $out . '<h4>' . esc_html__( 'Preview of the approved version', 'agent-brain' ) . '</h4>'
			. '<iframe sandbox="allow-scripts" referrerpolicy="no-referrer" style="width:100%;height:520px;border:1px solid #ccd0d4;background:#fff" srcdoc="' . esc_attr( HtmlSite::previewDocument( $shown['files'] ) ) . '"></iframe>';
	}

	public static function save( int $postId ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( $nonce === '' || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$actorId = get_current_user_id();
		$version = isset( $_POST[ self::FIELD ] ) ? absint( wp_unslash( $_POST[ self::FIELD ] ) ) : 0;
		$latest  = isset( $_POST[ self::FIELD_LATEST ] ) && (string) wp_unslash( $_POST[ self::FIELD_LATEST ] ) === '1'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to a literal.
		$mode    = $latest ? HtmlFiles::MODE_LATEST : HtmlFiles::MODE_APPROVED;

		// The latest-version checkbox is only offered to curators: for anyone else keep the current mode.
		if ( ! HtmlFiles::canSetMode( $actorId ) ) {
			$mode = HtmlFiles::mode( $postId );
		}

		if ( $version > 0 || $mode !== HtmlFiles::mode( $postId ) ) {
			HtmlFiles::approve( $postId, $actorId, $version, $mode );
		}

		if ( isset( $_POST[ self::FIELD_RELOAD ] ) ) {
			HtmlFiles::setAutoReload( $postId, $actorId, (string) wp_unslash( $_POST[ self::FIELD_RELOAD ] ) === '1' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to a literal.
		}
	}
}
