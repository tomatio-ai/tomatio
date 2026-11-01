<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Modules\Sharing\AdminPanel as SharePanel;

/**
 * Dedicated artifact screen (#77, #78, #79, #92).
 *
 * post.php?action=edit for ab_artifact is a classic-style screen, not the
 * block editor: no Publish box, categories, tags or trash link; an Agent
 * Brain "Artifact" box (stage, lead, scope, version, compiled post, allowed
 * actions) in the sidebar; the outline board and the share-links panel open
 * in the main column; "View Post" admin bar node replaced. The compiled
 * output post is an ordinary post and is untouched. Menu highlighting stays
 * in AdminUi::parentFile()/submenuFile().
 */
final class EditScreen {

	public const BOX_ID   = 'ab-artifact-box';
	public const BOARD_ID = 'ab-artifact-board';

	public static function register(): void {
		add_filter( 'use_block_editor_for_post_type', [ self::class, 'useBlockEditor' ], PHP_INT_MAX, 2 );
		add_filter( 'use_block_editor_for_post', [ self::class, 'useBlockEditorForPost' ], PHP_INT_MAX, 2 );
		add_action( 'add_meta_boxes_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'configureMetaBoxes' ], 99 );
		add_action( 'edit_form_after_title', [ self::class, 'renderMainColumn' ] );
		add_filter( 'post_updated_messages', [ self::class, 'updatedMessages' ] );
		add_action( 'admin_bar_menu', [ self::class, 'adminBar' ], 999 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'silenceHeartbeat' ], 999 );
		add_filter( 'heartbeat_settings', [ self::class, 'heartbeatSettings' ] );
	}

	/**
	 * #77 follow-up: saving the classic artifact screen showed core's
	 * "Connection lost. Saving has been disabled until you reconnect." bar.
	 * That bar is raised by the Heartbeat script when a heartbeat request
	 * (the post edit-lock refresh and autosave on a screen without the
	 * editor) fails or returns something that is not JSON, which happens
	 * right after the save redirect. The artifact is edited through its own
	 * sections and leases (Lease), not core's post lock, so the screen needs
	 * neither: heartbeat and autosave are replaced by empty scripts (kept
	 * registered so dependants such as post.js still resolve).
	 */
	public static function silenceHeartbeat(): void {
		if ( ! AdminUi::onArtifactEditScreen() ) {
			return;
		}

		foreach ( [ 'heartbeat', 'autosave' ] as $handle ) {
			wp_deregister_script( $handle );
			wp_register_script( $handle, false, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- empty handle, no file.
		}
	}

	/**
	 * @param mixed $settings
	 * @return mixed
	 */
	public static function heartbeatSettings( $settings ) {
		if ( is_array( $settings ) && AdminUi::onArtifactEditScreen() ) {
			$settings['autostart'] = false;
		}

		return $settings;
	}

	/**
	 * @param mixed  $use
	 * @param string $postType
	 * @return mixed
	 */
	public static function useBlockEditor( $use, $postType ) {
		return $postType === Cpts::ARTIFACT_POST_TYPE ? false : $use;
	}

	/**
	 * Per-post twin of useBlockEditor(). post.php asks use_block_editor_for_post(),
	 * whose own filter runs after the per-type one, so another plugin or a newer
	 * core that returns true there (or later on the type filter) put the block
	 * editor back on artifacts (#127). Both hooks run last (PHP_INT_MAX).
	 *
	 * @param mixed $use
	 * @param mixed $post
	 * @return mixed
	 */
	public static function useBlockEditorForPost( $use, $post ) {
		return $post instanceof \WP_Post && $post->post_type === Cpts::ARTIFACT_POST_TYPE ? false : $use;
	}

	/** Strip every native box an artifact never uses and add the Artifact box. */
	public static function configureMetaBoxes( \WP_Post $post ): void {
		$type = Cpts::ARTIFACT_POST_TYPE;

		foreach ( [ 'submitdiv', 'slugdiv', 'authordiv', 'commentstatusdiv', 'commentsdiv', 'trackbacksdiv', 'postcustom', 'postexcerpt', 'pageparentdiv', 'postimagediv', 'formatdiv', 'revisionsdiv' ] as $id ) {
			remove_meta_box( $id, $type, 'normal' );
			remove_meta_box( $id, $type, 'side' );
			remove_meta_box( $id, $type, 'advanced' );
		}
		foreach ( array_unique( array_merge( [ 'category', 'post_tag' ], get_object_taxonomies( $type ) ) ) as $taxonomy ) {
			foreach ( [ 'tagsdiv-' . $taxonomy, $taxonomy . 'div' ] as $id ) {
				remove_meta_box( $id, $type, 'side' );
				remove_meta_box( $id, $type, 'normal' );
			}
		}

		// The body lives in sections; the classic editor would only confuse.
		remove_post_type_support( $type, 'editor' );
		remove_post_type_support( $type, 'custom-fields' );

		add_meta_box( self::BOX_ID, __( 'Artifact', 'agent-brain' ), [ self::class, 'renderBox' ], $type, 'side', 'high' );
	}

	/**
	 * Compiled output post ids (ordinary posts), newest first.
	 *
	 * @return int[]
	 */
	public static function compiledPostIds( int $artifactId ): array {
		$ids = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'any',
			'meta_key'       => Cpts::META_COMPILED_FROM,
			'meta_value'     => (string) $artifactId,
			'fields'         => 'ids',
			'posts_per_page' => 5,
			'orderby'        => 'ID',
			'order'          => 'DESC',
		] );

		return array_map( 'intval', $ids );
	}

	/** Artifacts carry no version of their own: show the highest section version. */
	public static function latestVersion( int $artifactId ): int {
		$max = 0;
		foreach ( get_posts( [ 'post_type' => Cpts::SECTION_POST_TYPE, 'post_parent' => $artifactId, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] ) as $sectionId ) {
			$max = max( $max, (int) get_post_meta( (int) $sectionId, Cpts::META_VERSION, true ) );
		}

		return $max;
	}

	public static function renderBox( \WP_Post $post ): void {
		echo self::boxHtml( $post, get_current_user_id() );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	public static function boxHtml( \WP_Post $post, int $viewerId ): string {
		$id      = (int) $post->ID;
		$scopeId = (int) get_post_meta( $id, Cpts::META_SCOPE_ID, true );
		$term    = $scopeId > 0 ? get_term( $scopeId ) : null;
		$lead    = get_userdata( (int) get_post_meta( $id, Cpts::META_LEAD_AGENT, true ) );
		$version = self::latestVersion( $id );

		$out  = '<div class="ab-artifact-box">';
		$out .= '<p><strong>' . esc_html__( 'Stage', 'agent-brain' ) . ':</strong> ' . esc_html( ucfirst( Stages::artifactStage( $id ) ) ) . '</p>';
		$out .= '<p><strong>' . esc_html__( 'Lead', 'agent-brain' ) . ':</strong> ' . esc_html( $lead instanceof \WP_User ? $lead->display_name : '-' ) . '</p>';
		$out .= '<p><strong>' . esc_html__( 'Scope', 'agent-brain' ) . ':</strong> ' . esc_html( $term instanceof \WP_Term ? $term->name : '-' ) . '</p>';
		$out .= '<p><strong>' . esc_html__( 'Version', 'agent-brain' ) . ':</strong> ' . esc_html( $version > 0 ? (string) $version : '-' ) . '</p>';

		$out .= '<p><strong>' . esc_html__( 'Compiled post', 'agent-brain' ) . ':</strong> ';
		$links = [];
		foreach ( self::compiledPostIds( $id ) as $postId ) {
			$url      = get_edit_post_link( $postId, 'raw' );
			$title    = get_the_title( $postId );
			$label    = sprintf( '#%d %s', $postId, $title );
			$links[]  = $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
		}
		$out .= $links !== [] ? implode( ', ', $links ) : esc_html__( 'none yet', 'agent-brain' );
		$out .= '</p>';

		$actions = AdminUi::viewerActions( $viewerId, $id );
		$out    .= '<p><strong>' . esc_html__( 'Actions', 'agent-brain' ) . ':</strong> ';
		if ( $actions === [] ) {
			$out .= esc_html__( 'none available', 'agent-brain' ) . '</p>';
		} else {
			$out .= '</p><p>';
			foreach ( $actions as $slug => $label ) {
				$url  = wp_nonce_url(
					add_query_arg( [ 'action' => AdminUi::ACTION_STAGE, 'artifact_id' => $id, 'do' => $slug ], admin_url( 'admin-post.php' ) ),
					AdminUi::NONCE_STAGE . $id
				);
				$out .= '<a class="button" style="margin:0 6px 6px 0" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
			$out .= '</p>';
		}

		// Title is the only editable field; status is carried through unchanged.
		$out .= '<input type="hidden" name="post_status" value="' . esc_attr( (string) $post->post_status ) . '" />';
		$out .= '<input type="hidden" name="hidden_post_status" value="' . esc_attr( (string) $post->post_status ) . '" />';
		$out .= '<p><button type="submit" name="save" value="Save" class="button button-primary">' . esc_html__( 'Save title', 'agent-brain' ) . '</button></p>';

		return $out . '</div>';
	}

	/** Main column: the outline board and share links, open (never a collapsed drawer). */
	public static function renderMainColumn( \WP_Post $post ): void {
		if ( $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return;
		}

		echo '<div id="' . esc_attr( self::BOARD_ID ) . '" class="postbox ab-outline-panel"><div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Sections', 'agent-brain' ) . '</h2></div><div class="inside">';
		echo AdminUi::outlineHtml( (int) $post->ID, get_current_user_id() );  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '</div></div>';

		SharePanel::renderPanel( $post );
	}

	/**
	 * @param array<string, array<int|string, string>> $messages
	 * @return array<string, array<int|string, string>>
	 */
	public static function updatedMessages( array $messages ): array {
		$messages[ Cpts::ARTIFACT_POST_TYPE ] = array_fill( 0, 11, __( 'Document updated.', 'agent-brain' ) );
		$messages[ Cpts::ARTIFACT_POST_TYPE ][0] = '';

		return $messages;
	}

	/** Replace the "View Post" node (it would point at the compiled post, not the artifact). */
	public static function adminBar( \WP_Admin_Bar $bar ): void {
		if ( ! AdminUi::onArtifactEditScreen() ) {
			return;
		}

		$bar->remove_node( 'view' );
		$bar->remove_node( 'preview' );
	}
}
