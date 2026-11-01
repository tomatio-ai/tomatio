<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Artifacts\Cpts;

/**
 * Curator-only "Shared data store" box on the artifact edit screen (ART-23,
 * #163): the opt-in toggle (same pattern as InteractiveMetaBox /
 * ViewerMetaBox), the collections pages may use (#375), plus a read-only
 * summary of the store — collection names, document counts, bytes — so
 * curators see the brain data the share pages collect without leaving
 * wp-admin.
 */
final class StoreMetaBox {

	public const NONCE_ACTION      = 'ab_datastore_toggle';
	public const NONCE_FIELD       = 'ab_datastore_nonce';
	public const FIELD             = 'ab_datastore';
	public const FIELD_COLLECTIONS = 'ab_datastore_collections';

	public static function register(): void {
		add_action( 'init', [ self::class, 'registerMeta' ] );
		add_action( 'add_meta_boxes_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'addMetaBox' ] );
		add_action( 'save_post_' . Cpts::ARTIFACT_POST_TYPE, [ self::class, 'save' ], 10, 1 );
	}

	/**
	 * The flag must not be writable through the generic meta APIs by
	 * non-curators.
	 */
	public static function registerMeta(): void {
		register_post_meta(
			Cpts::ARTIFACT_POST_TYPE,
			StoreMode::META_FLAG,
			[
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => static fn ( $allowed, $metaKey, $objectId, $userId ): bool => StoreMode::canToggle( (int) $userId ),
			]
		);

		register_post_meta(
			Cpts::ARTIFACT_POST_TYPE,
			StoreMode::META_COLLECTIONS,
			[
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => static fn ( $allowed, $metaKey, $objectId, $userId ): bool => StoreMode::canToggle( (int) $userId ),
			]
		);
	}

	/**
	 * The collection list as the textarea shows it: one "name: preset" per
	 * line (#384). Tightening made over MCP is kept as long as the line's
	 * preset stays the same.
	 *
	 * @param array<string, array<string, mixed>> $collections
	 */
	public static function collectionsText( array $collections ): string {
		$lines = [];
		foreach ( $collections as $name => $rules ) {
			$lines[] = $name . ': ' . $rules['preset'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * The textarea back to full rules, keeping the saved rules of a line
	 * whose preset did not change; the reason when a line does not parse.
	 *
	 * @param array<string, array<string, mixed>> $saved
	 * @return array<string, array<string, mixed>>|string
	 */
	public static function parseCollectionsText( string $text, array $saved = [] ) {
		$map = [];
		foreach ( preg_split( '/\R/', $text ) ?: [] as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}

			$parts  = array_map( 'trim', explode( ':', $line, 2 ) );
			$preset = $parts[1] ?? '';
			$normal = StoreRules::normalize( $preset );
			$same   = isset( $saved[ $parts[0] ] ) && is_array( $normal ) && $normal['preset'] === $saved[ $parts[0] ]['preset'];

			$map[ $parts[0] ] = $same ? $saved[ $parts[0] ] : $preset;
		}

		return StoreMode::normalizeCollections( $map );
	}

	public static function addMetaBox( \WP_Post $post ): void {
		unset( $post );
		if ( ! StoreMode::canToggle( get_current_user_id() ) ) {
			return;
		}

		add_meta_box( 'ab_artifact_datastore', __( 'Shared data store', 'agent-brain' ), [ self::class, 'render' ], Cpts::ARTIFACT_POST_TYPE, 'side', 'low' );
	}

	public static function render( \WP_Post $post ): void {
		echo self::html( (int) $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* in html().
	}

	public static function html( int $artifactId ): string {
		$out  = wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, true, false );
		$out .= '<p><label><input type="checkbox" name="' . esc_attr( self::FIELD ) . '" value="1"' . checked( StoreMode::isFlagged( $artifactId ), true, false ) . ' /> ';
		$out .= esc_html__( 'Allow share pages to use the shared data store', 'agent-brain' ) . '</label></p>';
		$out .= '<p class="description">' . esc_html__( 'Artifact pages get a small JSON API (get, list, query, set, update, delete) on the preview host, and the script _tomatio.js for it. Every write is attributed to the viewer or the share link. Off by default.', 'agent-brain' ) . '</p>';
		$out .= '<p><label for="' . esc_attr( self::FIELD_COLLECTIONS ) . '">' . esc_html__( 'Collections pages may use, one per line with its preset (scores: counter)', 'agent-brain' ) . '</label>';
		$out .= '<textarea class="widefat" rows="3" id="' . esc_attr( self::FIELD_COLLECTIONS ) . '" name="' . esc_attr( self::FIELD_COLLECTIONS ) . '">' . esc_textarea( self::collectionsText( StoreMode::collections( $artifactId ) ) ) . '</textarea></p>';
		$out .= '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: comma-separated preset names */
				__( 'Presets: %s. Empty: no collection. Keys, schema and limits are set with the artifact-store-config tool. Stored values come from viewers: pages must show them as text, never as HTML.', 'agent-brain' ),
				implode( ', ', array_keys( StoreRules::presets() ) )
			)
		) . '</p>';
		if ( isset( $_GET[ self::FIELD_COLLECTIONS . '_error' ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$out .= '<p class="description" style="color:#b32d2e">' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET[ self::FIELD_COLLECTIONS . '_error' ] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		}
		if ( StoreMode::killSwitchOn() ) {
			$out .= '<p class="description"><strong>' . esc_html__( 'The data store is disabled site-wide; the API stays off.', 'agent-brain' ) . '</strong></p>';
		}

		$collections = StoreData::collections( $artifactId );
		if ( $collections !== [] ) {
			$out .= '<table class="widefat striped" style="margin-top:.75em"><thead><tr><th>' . esc_html__( 'Collection', 'agent-brain' ) . '</th><th>' . esc_html__( 'Docs', 'agent-brain' ) . '</th><th>' . esc_html__( 'Bytes', 'agent-brain' ) . '</th></tr></thead><tbody>';
			foreach ( $collections as $row ) {
				$out .= '<tr><td>' . esc_html( $row['collection'] ) . '</td><td>' . esc_html( (string) $row['docs'] ) . '</td><td>' . esc_html( (string) $row['bytes'] ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
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

		if ( isset( $_POST[ self::FIELD_COLLECTIONS ] ) ) {
			$collections = self::parseCollectionsText( sanitize_textarea_field( wp_unslash( (string) $_POST[ self::FIELD_COLLECTIONS ] ) ), StoreMode::collections( $postId ) );
			// A line that does not parse keeps the saved list rather than
			// widening or dropping access, and says why after the redirect.
			if ( is_array( $collections ) ) {
				StoreMode::setCollections( $postId, $collections, get_current_user_id() );
			} else {
				add_filter(
					'redirect_post_location',
					static fn ( string $location ): string => add_query_arg( self::FIELD_COLLECTIONS . '_error', rawurlencode( $collections ), $location )
				);
			}
		}

		StoreMode::set( $postId, $on, get_current_user_id() );
	}
}
