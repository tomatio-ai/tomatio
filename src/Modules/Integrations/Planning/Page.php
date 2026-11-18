<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

/**
 * #992: the planning sync block on a connected integration.
 * A stored key is never written into the page.
 */
final class Page {

	public static function render( string $service ): void {
		if ( $service !== 'linear' && $service !== 'notion' ) {
			return;
		}
		$row = Library::row( $service );
		echo '<h4>' . esc_html__( 'Planning sync', 'tomatio' ) . '</h4>';
		echo '<p>' . esc_html__( 'Tomagic and this service stay in step. The board wins rank and release. The service wins status and assignee. The first run is a dry run: nothing is written until you approve its fingerprint. After that it runs on its own. An item removed from the board is unlinked, not deleted on the service.', 'tomatio' ) . '</p>';
		self::form( $service, $row );
		if ( (string) $row['report'] !== '' ) {
			echo '<pre class="ab-planning-report">' . esc_html( (string) $row['report'] ) . '</pre>';
		}
		if ( ! empty( $row['live'] ) ) {
			echo '<p>' . esc_html__( 'Sync is on.', 'tomatio' ) . '</p>';
		}
	}

	/** @param array<string, mixed> $row */
	private static function form( string $service, array $row ): void {
		$mapping = Rules::mapping( $service, is_array( $row['mapping'] ?? null ) ? $row['mapping'] : [] );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( Actions::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::ACTION ) . '" />';
		echo '<input type="hidden" name="service" value="' . esc_attr( $service ) . '" />';
		echo '<p><label for="ab-plan-target-' . esc_attr( $service ) . '">' . esc_html__( 'Link or id', 'tomatio' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="ab-plan-target-' . esc_attr( $service ) . '" name="target" value="' . esc_attr( (string) $row['target'] ) . '" autocomplete="off" /></p>';
		if ( $service === 'linear' ) {
			echo '<p><label for="ab-plan-kind-' . esc_attr( $service ) . '">' . esc_html__( 'What to sync', 'tomatio' ) . '</label> ';
			echo '<select id="ab-plan-kind-' . esc_attr( $service ) . '" name="target_kind">';
			echo '<option value="team"' . selected( (string) $row['target_kind'], 'team', false ) . '>' . esc_html__( 'Team', 'tomatio' ) . '</option>';
			echo '<option value="project"' . selected( (string) $row['target_kind'], 'project', false ) . '>' . esc_html__( 'Project', 'tomatio' ) . '</option>';
			echo '</select></p>';
			echo '<p><label for="ab-plan-via-' . esc_attr( $service ) . '">' . esc_html__( 'Releases match', 'tomatio' ) . '</label> ';
			echo '<select id="ab-plan-via-' . esc_attr( $service ) . '" name="release_via">';
			echo '<option value="project"' . selected( (string) $row['release_via'], 'project', false ) . '>' . esc_html__( 'Projects', 'tomatio' ) . '</option>';
			echo '<option value="cycle"' . selected( (string) $row['release_via'], 'cycle', false ) . '>' . esc_html__( 'Cycles', 'tomatio' ) . '</option>';
			echo '</select></p>';
		} else {
			echo '<input type="hidden" name="target_kind" value="database" />';
		}
		echo '<p><label for="ab-plan-board-' . esc_attr( $service ) . '">' . esc_html__( 'Tomagic board id, if this brain has more than one', 'tomatio' ) . '</label><br />';
		echo '<input type="number" class="small-text" id="ab-plan-board-' . esc_attr( $service ) . '" name="backlog_id" value="' . esc_attr( (string) ( (int) $row['backlog_id'] > 0 ? (int) $row['backlog_id'] : '' ) ) . '" min="1" /></p>';
		echo '<p>' . esc_html__( 'Field names. Leave a box as the default unless the database uses another name.', 'tomatio' ) . '</p>';
		foreach ( [ 'title' => 'Title', 'status' => 'Status', 'owner' => 'Assignee', 'release' => 'Release', 'detail' => 'Detail', 'rank' => 'Rank' ] as $field => $label ) {
			echo '<p><label for="ab-plan-map-' . esc_attr( $service . '-' . $field ) . '">' . esc_html( $label ) . '</label><br />';
			echo '<input type="text" class="regular-text" id="ab-plan-map-' . esc_attr( $service . '-' . $field ) . '" name="map_' . esc_attr( $field ) . '" value="' . esc_attr( (string) ( $mapping[ $field ] ?? '' ) ) . '" /></p>';
		}
		echo '<p class="submit">';
		echo '<button type="submit" class="button" name="do" value="save">' . esc_html__( 'Save', 'tomatio' ) . '</button> ';
		if ( (string) $row['target'] !== '' ) {
			echo '<button type="submit" class="button" name="do" value="preview">' . esc_html__( 'Dry run', 'tomatio' ) . '</button> ';
		}
		if ( (string) $row['fingerprint'] !== '' && empty( $row['live'] ) ) {
			echo '<input type="hidden" name="fingerprint" value="' . esc_attr( (string) $row['fingerprint'] ) . '" />';
			echo '<button type="submit" class="button button-primary" name="do" value="approve">' . esc_html__( 'Approve this dry run', 'tomatio' ) . '</button> ';
		}
		if ( ! empty( $row['live'] ) ) {
			echo '<button type="submit" class="button button-link-delete" name="do" value="stop">' . esc_html__( 'Stop sync', 'tomatio' ) . '</button>';
		}
		echo '</p></form>';
	}
}
