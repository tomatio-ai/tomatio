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
		echo '<p>' . esc_html__( 'Magic Backlog and this service stay in step. The board wins rank and release. The service wins status and assignee. The first run is a dry run: nothing is written until you approve its fingerprint. After that it runs on its own. An item removed from the board is unlinked, not deleted on the service.', 'tomatio' ) . '</p>';
		$fresh = empty( $row['live'] ) && (string) $row['target'] === '' && (string) $row['fingerprint'] === '';
		if ( $service === 'notion' && $fresh && (string) $row['setup'] === '' ) {
			self::question( $service );
		} elseif ( $service === 'notion' && $fresh && (string) $row['setup'] === 'create' ) {
			self::createForm( $service, $row );
		} else {
			self::form( $service, $row );
		}
		if ( (string) $row['target_url'] !== '' && (string) $row['target'] !== '' ) {
			echo '<p><a href="' . esc_url( (string) $row['target_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open the backlog in Notion', 'tomatio' ) . '</a></p>';
		}
		if ( (string) $row['report'] !== '' ) {
			echo '<pre class="ab-planning-report">' . esc_html( (string) $row['report'] ) . '</pre>';
		}
		if ( ! empty( $row['live'] ) ) {
			echo '<p>' . esc_html__( 'Sync is on.', 'tomatio' ) . '</p>';
		}
	}

	/**
	 * B236: the first question on the Notion card.
	 */
	private static function question( string $service ): void {
		self::formOpen( $service );
		echo '<p><strong>' . esc_html__( 'Do you already have a backlog in Notion?', 'tomatio' ) . '</strong> ' . esc_html__( 'If not, TomaTío makes one for you, fills it with this board’s open items and keeps it in step.', 'tomatio' ) . '</p>';
		echo '<p class="submit">';
		echo '<button type="submit" class="button" name="do" value="have">' . esc_html__( 'Yes, I have one', 'tomatio' ) . '</button> ';
		echo '<button type="submit" class="button button-primary" name="do" value="new">' . esc_html__( 'No, create one', 'tomatio' ) . '</button>';
		echo '</p></form>';
	}

	/**
	 * B236: pick the page a new backlog database goes under.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function createForm( string $service, array $row ): void {
		$pages  = Choices::pages();
		$boards = Choices::boards( (int) get_current_user_id() );
		self::formOpen( $service );
		$ready = false;
		if ( is_string( $pages ) ) {
			echo '<p>' . esc_html( sprintf(
				/* translators: %s: why the list could not be read. */
				__( 'The list of Notion pages could not be read: %s', 'tomatio' ),
				$pages
			) ) . '</p>';
		} elseif ( $pages === [] ) {
			echo '<p role="status">' . esc_html( Choices::noPages() ) . '</p>';
		} else {
			$field = 'ab-plan-parent-' . $service;
			echo '<p><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Notion page to put it under', 'tomatio' ) . '</label><br />';
			echo '<select id="' . esc_attr( $field ) . '" name="parent">';
			if ( count( $pages ) > 1 ) {
				echo '<option value="">' . esc_html__( 'Choose one', 'tomatio' ) . '</option>';
			}
			foreach ( $pages as $id => $name ) {
				echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $name ) . '</option>';
			}
			echo '</select></p>';
			$ready = $boards !== [];
		}
		self::boardField( $service, $boards, (int) Choices::pick( $boards, (int) $row['backlog_id'] > 0 ? (string) (int) $row['backlog_id'] : '' ) );
		if ( $ready ) {
			echo '<p class="description">' . esc_html__( 'TomaTío makes a database on that page, named after the board, with the columns Name, Detail, Status, Owner, Rank and Release. It writes every open item into it at once and keeps the two in step every hour. Nothing else on the page changes.', 'tomatio' ) . '</p>';
		}
		echo '<p class="submit">';
		if ( $ready ) {
			echo '<button type="submit" class="button button-primary" name="do" value="create">' . esc_html__( 'Create the database and fill it', 'tomatio' ) . '</button> ';
		}
		echo '<button type="submit" class="button" name="do" value="new">' . esc_html__( 'Check again', 'tomatio' ) . '</button> ';
		echo '<button type="submit" class="button button-link" name="do" value="ask">' . esc_html__( 'Back', 'tomatio' ) . '</button>';
		echo '</p></form>';
	}

	private static function formOpen( string $service ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( Actions::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::ACTION ) . '" />';
		echo '<input type="hidden" name="service" value="' . esc_attr( $service ) . '" />';
	}

	/** @param array<int, string> $boards */
	private static function boardField( string $service, array $boards, int $picked ): void {
		if ( $boards === [] ) {
			echo '<p>' . esc_html__( 'This brain has no Magic Backlog board you can write yet. Turn on Magic Backlog first.', 'tomatio' ) . '</p>';
		} elseif ( count( $boards ) === 1 ) {
			$only = (int) array_key_first( $boards );
			echo '<input type="hidden" name="backlog_id" value="' . esc_attr( (string) $only ) . '" />';
			echo '<p>' . esc_html( sprintf(
				/* translators: %s: Magic Backlog board title. */
				__( 'Magic Backlog board: %s', 'tomatio' ),
				(string) $boards[ $only ]
			) ) . '</p>';
		} else {
			echo '<p><label for="ab-plan-board-' . esc_attr( $service ) . '">' . esc_html__( 'Magic Backlog board', 'tomatio' ) . '</label><br />';
			echo '<select id="ab-plan-board-' . esc_attr( $service ) . '" name="backlog_id">';
			if ( $picked <= 0 ) {
				echo '<option value="">' . esc_html__( 'Choose one', 'tomatio' ) . '</option>';
			}
			foreach ( $boards as $id => $title ) {
				echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $picked, (int) $id, false ) . '>' . esc_html( $title ) . '</option>';
			}
			echo '</select></p>';
		}
	}

	/** @param array<string, mixed> $row */
	private static function form( string $service, array $row ): void {
		$mapping = Rules::mapping( $service, is_array( $row['mapping'] ?? null ) ? $row['mapping'] : [] );
		$suggest = Choices::suggest( $service, (int) get_current_user_id() );
		$remote  = $suggest['remote'];
		$boards  = $suggest['boards'];
		$what    = $service === 'linear' ? __( 'Linear team', 'tomatio' ) : __( 'Notion database', 'tomatio' );
		$legacy  = $service === 'linear' && (string) $row['target_kind'] === 'project' && (string) $row['target'] !== '';

		if ( empty( $row['live'] ) && (string) $row['fingerprint'] === '' && is_array( $remote ) && $suggest['target'] !== '' && $suggest['board'] > 0 ) {
			echo '<p><strong>' . esc_html( sprintf(
				/* translators: 1: Magic Backlog board title, 2: Linear team or Notion database name. */
				__( 'Suggested: sync “%1$s” with “%2$s”.', 'tomatio' ),
				(string) $boards[ $suggest['board'] ],
				(string) $remote[ $suggest['target'] ]
			) ) . '</strong> ' . esc_html__( 'Change it below if that is not right. Nothing is written until you approve the dry run.', 'tomatio' ) . '</p>';
		}

		self::formOpen( $service );

		$field = 'ab-plan-target-' . $service;
		if ( is_array( $remote ) && $remote !== [] && ! $legacy ) {
			echo '<p><label for="' . esc_attr( $field ) . '">' . esc_html( $what ) . '</label><br />';
			echo '<select id="' . esc_attr( $field ) . '" name="target">';
			if ( $suggest['target'] === '' ) {
				echo '<option value="">' . esc_html__( 'Choose one', 'tomatio' ) . '</option>';
			}
			foreach ( $remote as $id => $name ) {
				echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $suggest['target'], (string) $id, false ) . '>' . esc_html( $name ) . '</option>';
			}
			echo '</select></p>';
			echo '<input type="hidden" name="target_kind" value="' . esc_attr( $service === 'linear' ? 'team' : 'database' ) . '" />';
		} else {
			if ( is_string( $remote ) ) {
				echo '<p>' . esc_html( sprintf(
					/* translators: %s: why the list could not be read. */
					__( 'The list could not be read: %s Paste an id instead.', 'tomatio' ),
					$remote
				) ) . '</p>';
			} elseif ( $remote === [] ) {
				echo '<p>' . esc_html( $service === 'linear'
					? __( 'This sign-in sees no Linear team. Make one in Linear, or paste an id.', 'tomatio' )
					: __( 'This sign-in sees no Notion database. Share one with the integration in Notion, or paste an id.', 'tomatio' ) ) . '</p>';
			}
			echo '<p><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Link or id', 'tomatio' ) . '</label><br />';
			echo '<input type="text" class="regular-text" id="' . esc_attr( $field ) . '" name="target" value="' . esc_attr( (string) $row['target'] ) . '" autocomplete="off" /></p>';
			if ( $service === 'linear' ) {
				echo '<p><label for="ab-plan-kind-' . esc_attr( $service ) . '">' . esc_html__( 'What to sync', 'tomatio' ) . '</label> ';
				echo '<select id="ab-plan-kind-' . esc_attr( $service ) . '" name="target_kind">';
				echo '<option value="team"' . selected( (string) $row['target_kind'], 'team', false ) . '>' . esc_html__( 'Team', 'tomatio' ) . '</option>';
				echo '<option value="project"' . selected( (string) $row['target_kind'], 'project', false ) . '>' . esc_html__( 'Project', 'tomatio' ) . '</option>';
				echo '</select></p>';
			} else {
				echo '<input type="hidden" name="target_kind" value="database" />';
			}
		}

		self::boardField( $service, $boards, (int) $suggest['board'] );

		if ( $service === 'linear' ) {
			echo '<p><label for="ab-plan-via-' . esc_attr( $service ) . '">' . esc_html__( 'Releases match', 'tomatio' ) . '</label> ';
			echo '<select id="ab-plan-via-' . esc_attr( $service ) . '" name="release_via">';
			echo '<option value="project"' . selected( (string) $row['release_via'], 'project', false ) . '>' . esc_html__( 'Projects', 'tomatio' ) . '</option>';
			echo '<option value="cycle"' . selected( (string) $row['release_via'], 'cycle', false ) . '>' . esc_html__( 'Cycles', 'tomatio' ) . '</option>';
			echo '</select></p>';
			echo '<p class="description">' . esc_html__( 'A release links to the Linear project or cycle with exactly the same name, such as v1.10.3. With Projects, sync creates a project Linear lacks, named after the release. Cycles must already exist in Linear. Items whose release has no cycle still sync, without one.', 'tomatio' ) . '</p>';
		}

		echo '<details class="ab-plan-fields"><summary>' . esc_html__( 'Field names', 'tomatio' ) . '</summary>';
		echo '<p>' . esc_html__( 'Leave a box as the default unless the database uses another name.', 'tomatio' ) . '</p>';
		foreach ( [ 'title' => 'Title', 'status' => 'Status', 'owner' => 'Assignee', 'release' => 'Release', 'detail' => 'Detail', 'rank' => 'Rank' ] as $key => $label ) {
			echo '<p><label for="ab-plan-map-' . esc_attr( $service . '-' . $key ) . '">' . esc_html( $label ) . '</label><br />';
			echo '<input type="text" class="regular-text" id="ab-plan-map-' . esc_attr( $service . '-' . $key ) . '" name="map_' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $mapping[ $key ] ?? '' ) ) . '" /></p>';
		}
		echo '</details>';

		echo '<p class="submit">';
		$ready = (string) $row['fingerprint'] !== '' && empty( $row['live'] );
		echo '<button type="submit" class="button' . ( $ready ? '' : ' button-primary' ) . '" name="do" value="setup">' . esc_html( $ready ? __( 'Save and run the dry run again', 'tomatio' ) : __( 'Save and do a dry run', 'tomatio' ) ) . '</button> ';
		if ( $ready ) {
			echo '<input type="hidden" name="fingerprint" value="' . esc_attr( (string) $row['fingerprint'] ) . '" />';
			echo '<button type="submit" class="button button-primary" name="do" value="approve">' . esc_html__( 'Approve this dry run', 'tomatio' ) . '</button> ';
		}
		if ( ! empty( $row['live'] ) ) {
			echo '<button type="submit" class="button button-link-delete" name="do" value="stop">' . esc_html__( 'Stop sync', 'tomatio' ) . '</button>';
		} elseif ( $service === 'notion' && (string) $row['target'] === '' ) {
			echo '<button type="submit" class="button" name="do" value="new">' . esc_html__( 'No backlog yet? Create one', 'tomatio' ) . '</button>';
		}
		echo '</p></form>';
	}
}
