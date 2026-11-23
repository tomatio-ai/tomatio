<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Context;

use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Identity\Scopes;

/**
 * #991: the "Read into the brain" block on a connected integration.
 * A stored key is never written into the page.
 */
final class Page {

	public static function render( string $service ): void {
		if ( ! isset( Read::KINDS[ $service ] ) ) {
			return;
		}
		$actor  = (int) get_current_user_id();
		$scopes = self::scopes( $actor );
		echo '<h4>' . esc_html__( 'Read into the brain', 'tomatio' ) . '</h4>';
		echo '<p>' . esc_html__( 'Pick what this brain reads. It belongs to one scope. The first read only counts; nothing is stored until you confirm. A refresh runs every hour. Nothing is written back to the service.', 'tomatio' ) . '</p>';

		if ( $scopes === [] ) {
			echo '<p>' . esc_html__( 'Add a scope first, then pick it here.', 'tomatio' ) . '</p>';
		} else {
			self::previewForm( $service, $scopes );
		}
		foreach ( Library::forService( $service ) as $pick ) {
			if ( ScopeVisibility::canSeeScope( $actor, (int) ( $pick['scope_id'] ?? 0 ) ) ) {
				self::pick( $service, $pick );
			}
		}
	}

	/**
	 * @param array<int, string> $scopes
	 */
	private static function previewForm( string $service, array $scopes ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( Actions::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::ACTION ) . '" />';
		echo '<input type="hidden" name="service" value="' . esc_attr( $service ) . '" />';
		echo '<p><label for="ab-ctx-kind-' . esc_attr( $service ) . '">' . esc_html__( 'What to read', 'tomatio' ) . '</label> ';
		echo '<select id="ab-ctx-kind-' . esc_attr( $service ) . '" name="kind">';
		foreach ( self::kinds( $service ) as $kind => $label ) {
			echo '<option value="' . esc_attr( $kind ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="ab-ctx-loc-' . esc_attr( $service ) . '">' . esc_html__( 'Link or id', 'tomatio' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="ab-ctx-loc-' . esc_attr( $service ) . '" name="locator" autocomplete="off" /></p>';
		echo '<p><label for="ab-ctx-scope-' . esc_attr( $service ) . '">' . esc_html__( 'Scope', 'tomatio' ) . '</label> ';
		echo '<select id="ab-ctx-scope-' . esc_attr( $service ) . '" name="scope_id">';
		foreach ( $scopes as $id => $name ) {
			echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $name ) . '</option>';
		}
		echo '</select></p>';
		$help = $service === Read::LINEAR
			? __( 'Paste the team or project id from Linear, or a link that contains it.', 'tomatio' )
			: __( 'Paste a Notion page or database link. A section is a page and the pages directly under it.', 'tomatio' );
		echo '<p class="description">' . esc_html( $help ) . '</p>';
		echo '<p><button type="submit" class="button" name="do" value="preview">' . esc_html__( 'Preview', 'tomatio' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $pick
	 */
	private static function pick( string $service, array $pick ): void {
		$summary = is_array( $pick['summary'] ?? null ) ? $pick['summary'] : [];
		$status  = (string) ( $pick['status'] ?? '' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( Actions::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::ACTION ) . '" />';
		echo '<input type="hidden" name="service" value="' . esc_attr( $service ) . '" />';
		echo '<input type="hidden" name="pick" value="' . esc_attr( (string) ( $pick['id'] ?? '' ) ) . '" />';
		if ( $status === 'preview' ) {
			echo '<p>' . esc_html( Read::previewWords( [
				'count'     => (int) ( $summary['count'] ?? 0 ),
				'scope'     => (string) ( $summary['scope'] ?? '' ),
				'truncated' => ! empty( $summary['truncated'] ),
			], $service ) ) . '</p>';
			echo '<p><button type="submit" class="button button-primary" name="do" value="store">' . esc_html__( 'Store this read', 'tomatio' ) . '</button></p>';
		} else {
			$when = (string) ( $pick['read_at'] ?? '' );
			$line = sprintf(
				/* translators: 1: how many pages or issues, 2: scope name. */
				__( 'Reading %1$d into %2$s.', 'tomatio' ),
				(int) ( $summary['count'] ?? 0 ),
				(string) ( $pick['scope_name'] ?? '' )
			);
			if ( $when !== '' ) {
				$line .= ' ' . sprintf(
					/* translators: %s: UTC time of the last read, Y-m-d H:i:s. */
					__( 'Last read %s UTC.', 'tomatio' ),
					$when
				);
			}
			echo '<p>' . esc_html( $line ) . '</p>';
			echo '<p><button type="submit" class="button" name="do" value="refresh">' . esc_html__( 'Refresh now', 'tomatio' ) . '</button> ';
			echo '<button type="submit" class="button button-link-delete" name="do" value="remove">' . esc_html__( 'Stop reading', 'tomatio' ) . '</button></p>';
		}
		echo '</form>';
	}

	/**
	 * @return array<string, string>
	 */
	private static function kinds( string $service ): array {
		if ( $service === Read::LINEAR ) {
			return [
				'team'    => __( 'Team', 'tomatio' ),
				'project' => __( 'Project', 'tomatio' ),
			];
		}

		return [
			'page'     => __( 'Page', 'tomatio' ),
			'database' => __( 'Database', 'tomatio' ),
			'section'  => __( 'Section', 'tomatio' ),
		];
	}

	/**
	 * @return array<int, string>
	 */
	private static function scopes( int $actor ): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return [];
		}
		$terms = get_terms( [
			'taxonomy'   => Scopes::TAXONOMY,
			'hide_empty' => false,
		] );
		if ( ! is_array( $terms ) ) {
			return [];
		}
		$out = [];
		foreach ( $terms as $term ) {
			$id = (int) $term->term_id;
			if ( $id > 0 && ScopeVisibility::canSeeScope( $actor, $id ) ) {
				$out[ $id ] = (string) $term->name;
			}
		}

		return $out;
	}
}
