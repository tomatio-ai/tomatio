<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Backlog\Backlog;
use AgentBrain\Modules\DataStore\TioDay;

/**
 * Settings > Backlog (#892): where the backlog's sources read from. The
 * issues source (a Forgejo or GitHub repository, with an optional read
 * token) and the Notion source (a database and its integration token).
 * Tokens are stored encrypted and never shown again; a person pastes them
 * here, never an agent.
 */
final class SettingsTab {

	public const ACTION = 'ab_backlog_sources';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handleSave' ] );
	}

	public static function renderBody(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag set by our own redirect.
		$status = isset( $_GET['ab_backlog'] ) ? sanitize_key( wp_unslash( (string) $_GET['ab_backlog'] ) ) : '';
		if ( $status === 'saved' ) {
			echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__( 'Saved.', 'tomatio' ) . '</p></div>';
		} elseif ( $status === 'removed' ) {
			echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__( 'Removed. Items read from it keep their links and last snapshot.', 'tomatio' ) . '</p></div>';
		} elseif ( $status !== '' ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html__( 'Nothing was saved: give the repository as owner/name with an https API address, or the Notion database link or ID.', 'tomatio' ) . '</p></div>';
		}

		$config = Config::all();

		echo '<section class="ab-ui-card"><h3>' . esc_html__( 'Backlogs', 'tomatio' ) . '</h3>';
		$backlogs = Backlog::all();
		if ( $backlogs === [] ) {
			echo '<p>' . esc_html__( 'No backlog yet. A curator makes one with wp tomatio backlog create --scope=<id> --lead=<agent>.', 'tomatio' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $backlogs as $id ) {
				echo '<li><a href="' . esc_url( TioDay::url( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a> · ' . esc_html( sprintf( /* translators: %d: artifact id. */ __( 'artifact %d', 'tomatio' ), $id ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p>' . esc_html__( 'A read always starts as a dry run that writes nothing and prints a report: wp tomatio backlog read. The real run writes only the plan whose report a person said yes to.', 'tomatio' ) . '</p></section>';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui and formStart() escape what they emit; the inputs are escaped here.
		echo '<section class="ab-ui-card">' . self::formStart( 'issues' ) . '<h3>' . esc_html__( 'Issues (GitHub or Forgejo)', 'tomatio' ) . '</h3>';
		echo '<p>' . esc_html__( 'Read-only: the backlog reads the open issues and the pull requests that close them, and never writes to the repository.', 'tomatio' ) . '</p>';
		echo Ui::setting(
			__( 'Host', 'tomatio' ),
			esc_html__( 'Forgejo (or Gitea) or GitHub.', 'tomatio' ),
			'<select id="ab-backlog-host" name="host"><option value="forgejo"' . selected( $config['issues']['host'], 'forgejo', false ) . '>Forgejo</option><option value="github"' . selected( $config['issues']['host'], 'github', false ) . '>GitHub</option></select>',
			'ab-backlog-host'
		);
		echo Ui::setting( __( 'API address', 'tomatio' ), esc_html__( 'Such as https://git.example.com/api/v1. Leave empty for GitHub.', 'tomatio' ), '<input type="url" class="regular-text" id="ab-backlog-api" name="api" value="' . esc_attr( $config['issues']['api'] ) . '" />', 'ab-backlog-api' );
		echo Ui::setting( __( 'Repository', 'tomatio' ), esc_html__( 'owner/name.', 'tomatio' ), '<input type="text" class="regular-text" id="ab-backlog-repo" name="repo" value="' . esc_attr( $config['issues']['repo'] ) . '" placeholder="team/project" />', 'ab-backlog-repo' );
		echo Ui::setting(
			__( 'Read token', 'tomatio' ),
			esc_html( $config['issues']['token'] !== '' ? __( 'A token is stored (encrypted). Paste a new one to replace it.', 'tomatio' ) : __( 'Optional for a public repository. A token that can read issues and pull requests. Stored encrypted, never shown again.', 'tomatio' ) ),
			'<input type="password" class="regular-text" id="ab-backlog-issues-token" name="token" autocomplete="off" />',
			'ab-backlog-issues-token'
		);
		echo Ui::saveBar( 'save', __( 'Save issues source', 'tomatio' ) );
		if ( $config['issues']['repo'] !== '' ) {
			echo '<p><button type="submit" class="button button-link-delete" name="remove" value="issues">' . esc_html__( 'Remove the issues source', 'tomatio' ) . '</button></p>';
		}
		echo '</form></section>';

		echo '<section class="ab-ui-card">' . self::formStart( 'notion' ) . '<h3>' . esc_html__( 'Notion database', 'tomatio' ) . '</h3>';
		echo '<p>' . esc_html__( 'In Notion, make an internal integration with read content and update content rights, share the backlog database with it, and paste its token here. This version only reads; writing back comes later, and only after a person approves the first sync.', 'tomatio' ) . '</p>';
		echo Ui::setting( __( 'Database', 'tomatio' ), esc_html__( 'The database link or its ID.', 'tomatio' ), '<input type="text" class="regular-text" id="ab-backlog-notion-db" name="database" value="' . esc_attr( $config['notion']['database'] ) . '" />', 'ab-backlog-notion-db' );
		echo Ui::setting(
			__( 'Integration token', 'tomatio' ),
			esc_html( $config['notion']['token'] !== '' ? __( 'A token is stored (encrypted). Paste a new one to replace it.', 'tomatio' ) : __( 'The integration\'s secret. Stored encrypted, never shown again.', 'tomatio' ) ),
			'<input type="password" class="regular-text" id="ab-backlog-notion-token" name="token" autocomplete="off" />',
			'ab-backlog-notion-token'
		);
		echo Ui::saveBar( 'save', __( 'Save Notion source', 'tomatio' ) );
		if ( $config['notion']['database'] !== '' ) {
			echo '<p><button type="submit" class="button button-link-delete" name="remove" value="notion">' . esc_html__( 'Remove the Notion source', 'tomatio' ) . '</button></p>';
		}
		// phpcs:enable
		echo '</form></section>';
	}

	public static function handleSave(): void {
		if ( ! current_user_can( SettingsHub::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage TomaTío settings.', 'tomatio' ), 403 );
		}
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$result = self::save( wp_unslash( $_POST ) );

		wp_safe_redirect( SettingsHub::url( SettingsHub::TAB_BACKLOG, [ 'ab_backlog' => $result ] ) );
		exit;
	}

	/**
	 * Save or remove one source. Returns 'saved', 'removed' or 'invalid'.
	 *
	 * @param array<string, mixed> $post Unslashed form data.
	 */
	public static function save( array $post ): string {
		$source = is_string( $post['source'] ?? null ) ? $post['source'] : '';
		$token  = is_string( $post['token'] ?? null ) ? trim( $post['token'] ) : '';
		if ( isset( $post['remove'] ) && in_array( $post['remove'], [ 'issues', 'notion' ], true ) ) {
			Config::remove( (string) $post['remove'] );
			self::note( 'backlog.source_removed', (string) $post['remove'] );

			return 'removed';
		}
		if ( $source === 'issues' ) {
			$ok = Config::saveIssues( is_string( $post['host'] ?? null ) ? $post['host'] : 'forgejo', is_string( $post['api'] ?? null ) ? $post['api'] : '', is_string( $post['repo'] ?? null ) ? $post['repo'] : '', $token );
		} elseif ( $source === 'notion' ) {
			$ok = Config::saveNotion( is_string( $post['database'] ?? null ) ? $post['database'] : '', $token );
		} else {
			$ok = false;
		}
		if ( ! $ok ) {
			return 'invalid';
		}
		self::note( 'backlog.source_saved', $source );

		return 'saved';
	}

	/** A form for one source; its nonce field has no id, so two forms never share one. */
	private static function formStart( string $source ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::ACTION ) ) . '" />'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
			. '<input type="hidden" name="source" value="' . esc_attr( $source ) . '" />';
	}

	private static function note( string $event, string $source ): void {
		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => get_current_user_id(),
				'event'       => $event,
				'status'      => 'ok',
				'object_type' => 'setting',
				'object_id'   => $source,
			]
		);
	}
}
