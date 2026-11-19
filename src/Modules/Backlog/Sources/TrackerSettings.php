<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Backlog\Backlog;

/**
 * #1001: Settings > Extensions, under Tomagic: the Issue tracker section
 * and the Inbox sources of each board.
 *
 * The tracker (Forgejo or GitHub; GitLab later), its repository and a
 * read-only token. The token is sealed (Config, SecretBox), never shown
 * again, never put in the page's store or given to an AI, and can be
 * replaced or removed. Test reads one page of open issues and says how
 * many it found, or why it failed. Each board switches its sources on or
 * off; every switch and every import is audited.
 */
final class TrackerSettings {

	public const ACTION = 'ab_issue_tracker';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag set by our own redirect.
		if ( isset( $_GET['ab_tracker'] ) ) {
			$said = get_transient( self::noticeKey() );
			delete_transient( self::noticeKey() );
			if ( is_array( $said ) && is_string( $said['text'] ?? null ) ) {
				$ok = ! empty( $said['ok'] );
				echo '<div class="notice ' . esc_attr( $ok ? 'notice-success' : 'notice-error' ) . ' inline" role="' . esc_attr( $ok ? 'status' : 'alert' ) . '"><p>' . esc_html( $said['text'] ) . '</p></div>';
			}
		}

		$config = Config::all()['issues'];
		$stored = $config['token'] !== '';

		echo '<section class="ab-ui-card" id="issue-tracker"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<h3>' . esc_html__( 'Issue tracker', 'tomatio' ) . '</h3>';
		echo '<p>' . esc_html__( 'Tomagic brings the open issues of one repository into its Inbox, once each, for a person to triage. It only reads: nothing is written back, and closed issues are skipped.', 'tomatio' ) . '</p>';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits; the inputs are escaped here.
		echo Ui::setting(
			__( 'Tracker', 'tomatio' ),
			esc_html__( 'Forgejo (or Gitea) or GitHub. GitLab comes later.', 'tomatio' ),
			'<select id="ab-tracker-host" name="host"><option value="forgejo"' . selected( $config['host'], 'forgejo', false ) . '>Forgejo</option><option value="github"' . selected( $config['host'], 'github', false ) . '>GitHub</option></select>',
			'ab-tracker-host'
		);
		echo Ui::setting( __( 'API address', 'tomatio' ), esc_html__( 'Forgejo: your server and /api/v1, such as https://git.example.com/api/v1. Leave empty for GitHub.', 'tomatio' ), '<input type="url" class="regular-text" id="ab-tracker-api" name="api" value="' . esc_attr( $config['api'] ) . '" />', 'ab-tracker-api' );
		echo Ui::setting( __( 'Repository', 'tomatio' ), esc_html__( 'owner/name, such as team/project.', 'tomatio' ), '<input type="text" class="regular-text" id="ab-tracker-repo" name="repo" value="' . esc_attr( $config['repo'] ) . '" placeholder="team/project" />', 'ab-tracker-repo' );
		echo Ui::setting(
			__( 'Read-only token', 'tomatio' ),
			esc_html(
				$stored
					? __( 'A token is stored, encrypted. It is never shown again, never sent to a page or an AI. Paste a new one to replace it.', 'tomatio' )
					: __( 'Read on issues only. Forgejo: read:issue, plus read:repository for a private repository. GitHub: a fine-grained token with Issues: Read on this one repository. Stored encrypted, never shown again.', 'tomatio' )
			),
			'<input type="password" class="regular-text" id="ab-tracker-token" name="token" autocomplete="off" />',
			'ab-tracker-token'
		);

		$boards = Backlog::all();
		if ( $boards !== [] ) {
			echo '<h4>' . esc_html__( 'Into the Inbox', 'tomatio' ) . '</h4>';
			echo '<p>' . esc_html__( 'Per board, which sources feed its Inbox. New work lands untriaged, with where it came from and a link back; a person picks a release or Later, or rejects it into the Archive. Rejected work does not come back. Checked hourly.', 'tomatio' ) . '</p>';
			foreach ( $boards as $board ) {
				$on   = Inbox::switches( $board );
				$last = Inbox::last( $board );
				$id   = 'ab-inbox-' . $board;
				$row  = '<label><input type="checkbox" name="inbox[' . esc_attr( (string) $board ) . '][issues]" value="1"' . checked( $on['issues'], true, false ) . ' /> ' . esc_html__( 'Issues from the tracker', 'tomatio' ) . '</label><br />'
					. '<label><input type="checkbox" name="inbox[' . esc_attr( (string) $board ) . '][tioday]" value="1"' . checked( $on['tioday'], true, false ) . ' /> ' . esc_html__( 'Open TíoDay tasks marked as features', 'tomatio' ) . '</label>'
					. '<input type="hidden" name="boards[]" value="' . esc_attr( (string) $board ) . '" />';
				echo Ui::setting( get_the_title( $board ), esc_html( self::lastLine( $last ) ), '<span id="' . esc_attr( $id ) . '">' . $row . '</span>' );
			}
		}

		echo '<p class="submit">'
			. '<button type="submit" class="button button-primary" name="do" value="save">' . esc_html__( 'Save', 'tomatio' ) . '</button> '
			. '<button type="submit" class="button" name="do" value="test">' . esc_html__( 'Save and test', 'tomatio' ) . '</button> '
			. ( $boards !== [] ? '<button type="submit" class="button" name="do" value="sync">' . esc_html__( 'Save and bring new work in now', 'tomatio' ) . '</button>' : '' )
			. '</p>';
		if ( $stored || $config['repo'] !== '' ) {
			echo '<p>'
				. ( $stored ? '<button type="submit" class="button button-link-delete" name="do" value="remove_token">' . esc_html__( 'Remove the token', 'tomatio' ) . '</button> · ' : '' )
				. '<button type="submit" class="button button-link-delete" name="do" value="remove">' . esc_html__( 'Remove the tracker', 'tomatio' ) . '</button></p>';
		}
		// phpcs:enable
		echo '</form></section>';
	}

	public static function handle(): void {
		if ( ! current_user_can( SettingsHub::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage TomaTío settings.', 'tomatio' ), 403 );
		}
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$said = self::save( wp_unslash( $_POST ), (int) get_current_user_id() );
		set_transient( self::noticeKey(), $said, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( SettingsHub::url( SettingsHub::TAB_EXTENSIONS, [ 'ab_tracker' => '1' ] ) . '#issue-tracker' );
		exit;
	}

	/**
	 * Apply the form. Returns what to tell the person: {ok, text}.
	 *
	 * @param mixed $post Unslashed form data.
	 * @return array{ok: bool, text: string}
	 */
	public static function save( $post, int $actorId ): array {
		$post  = is_array( $post ) ? $post : [];
		$do    = is_string( $post['do'] ?? null ) ? $post['do'] : 'save';
		$host  = is_string( $post['host'] ?? null ) ? $post['host'] : 'forgejo';
		$api   = is_string( $post['api'] ?? null ) ? $post['api'] : '';
		$repo  = is_string( $post['repo'] ?? null ) ? $post['repo'] : '';
		$token = is_string( $post['token'] ?? null ) ? trim( $post['token'] ) : '';

		if ( $do === 'remove' ) {
			Config::remove( 'issues' );
			self::audit( $actorId, 'backlog.source_removed' );

			return [ 'ok' => true, 'text' => __( 'Removed the tracker and its token. Items brought in from it stay, with their links.', 'tomatio' ) ];
		}
		if ( $do === 'remove_token' ) {
			$config = Config::all()['issues'];
			Config::saveIssues( $config['host'], $config['api'], $config['repo'], '', true );
			self::audit( $actorId, 'backlog.source_saved' );

			return [ 'ok' => true, 'text' => __( 'Removed the token. Only a public repository can be read now.', 'tomatio' ) ];
		}

		$text = [];
		$ok   = true;
		if ( trim( $repo ) !== '' || $token !== '' ) {
			if ( Config::saveIssues( $host, $api, $repo, $token ) ) {
				self::audit( $actorId, 'backlog.source_saved' );
				$text[] = __( 'Saved.', 'tomatio' );
			} else {
				$ok     = false;
				$text[] = __( 'The tracker was not saved: give the repository as owner/name, and for Forgejo an https API address.', 'tomatio' );
			}
		}

		$boards = array_map( 'intval', is_array( $post['boards'] ?? null ) ? $post['boards'] : [] );
		$inbox  = is_array( $post['inbox'] ?? null ) ? $post['inbox'] : [];
		foreach ( $boards as $board ) {
			if ( ! in_array( $board, Backlog::all(), true ) ) {
				continue;
			}
			$want = is_array( $inbox[ $board ] ?? null ) ? $inbox[ $board ] : [];
			foreach ( Inbox::SOURCES as $source ) {
				Inbox::setSwitch( $board, $source, ( $want[ $source ] ?? '' ) === '1', $actorId );
			}
		}
		if ( $text === [] ) {
			$text[] = __( 'Saved.', 'tomatio' );
		}

		if ( $do === 'test' && $ok ) {
			$source = IssuesSource::configured();
			if ( is_string( $source ) ) {
				return [ 'ok' => false, 'text' => __( 'Nothing to test yet: set the repository (and for Forgejo the API address) first.', 'tomatio' ) ];
			}
			$result = $source->test();
			self::audit( $actorId, 'backlog.source_tested', $result['ok'] ? 'ok' : 'failed' );

			return [ 'ok' => $result['ok'], 'text' => implode( ' ', $text ) . ' ' . $result['message'] ];
		}

		if ( $do === 'sync' && $ok ) {
			foreach ( Backlog::all() as $board ) {
				$got    = Inbox::sync( $board );
				$on     = Inbox::switches( $board );
				if ( ! $on['issues'] && ! $on['tioday'] ) {
					continue;
				}
				/* translators: 1: board title, 2: issues brought in, 3: TíoDay tasks brought in. */
				$text[] = sprintf( __( '%1$s: %2$d issues and %3$d TíoDay tasks brought into the Inbox.', 'tomatio' ), get_the_title( $board ), $got['issues'], $got['tioday'] );
				foreach ( $got['notes'] as $note ) {
					$text[] = $note;
				}
			}
		}

		return [ 'ok' => $ok, 'text' => implode( ' ', $text ) ];
	}

	/** @param array<string, mixed> $last */
	private static function lastLine( array $last ): string {
		if ( ! is_string( $last['at'] ?? null ) ) {
			return __( 'Not checked yet.', 'tomatio' );
		}
		$parts = [];
		foreach ( [ 'issues' => __( 'issues', 'tomatio' ), 'tioday' => __( 'TíoDay tasks', 'tomatio' ) ] as $source => $name ) {
			$run = is_array( $last[ $source ] ?? null ) ? $last[ $source ] : null;
			if ( $run === null ) {
				continue;
			}
			$parts[] = is_string( $run['error'] ?? null )
				/* translators: 1: source name, 2: why it failed. */
				? sprintf( __( '%1$s failed: %2$s', 'tomatio' ), $name, $run['error'] )
				/* translators: 1: how many, 2: source name. */
				: sprintf( __( '%1$d new %2$s', 'tomatio' ), (int) ( $run['imported'] ?? 0 ), $name );
		}

		/* translators: 1: when, 2: what came in. */
		return sprintf( __( 'Last checked %1$s: %2$s.', 'tomatio' ), $last['at'], $parts !== [] ? implode( '; ', $parts ) : __( 'nothing switched on', 'tomatio' ) );
	}

	private static function noticeKey(): string {
		return 'ab_tracker_notice_' . get_current_user_id();
	}

	private static function audit( int $actorId, string $event, string $status = 'ok' ): void {
		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => $event,
				'status'      => $status,
				'object_type' => 'setting',
				'object_id'   => 'issues',
			]
		);
	}
}
