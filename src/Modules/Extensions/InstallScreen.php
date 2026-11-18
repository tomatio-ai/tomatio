<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Admin\SettingsHub;
use WP_Error;

/**
 * #994 (RFC section 4, step 2): the install part of Settings > Extensions.
 *
 * A manager uploads a zip; the screen then shows what it is and what it
 * may do (name, publisher, version, source, files and size, its store
 * collections and where its pages go) and installs it only when they
 * confirm. Installed extensions are listed with "Add a page" and "Remove".
 */
final class InstallScreen {

	public const UPLOAD  = 'ab_extension_upload';
	public const CONFIRM = 'ab_extension_confirm';
	public const CANCEL  = 'ab_extension_cancel';
	public const PAGE    = 'ab_extension_page';
	public const REMOVE  = 'ab_extension_remove';

	private const PENDING = 'ab_extension_pending_';
	private const NOTICE  = 'ab_extension_notice_';

	public static function register(): void {
		foreach ( [ self::UPLOAD, self::CONFIRM, self::CANCEL, self::PAGE, self::REMOVE ] as $action ) {
			add_action( 'admin_post_' . $action, [ self::class, 'handle' ] );
		}
	}

	public static function render(): void {
		$user = (int) get_current_user_id();
		if ( Installer::mayInstall( $user ) instanceof WP_Error ) {
			return;
		}

		$notice = get_transient( self::NOTICE . $user );
		delete_transient( self::NOTICE . $user );
		if ( is_array( $notice ) ) {
			$class = ( $notice['ok'] ?? false ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' inline" role="status"><p>' . esc_html( (string) ( $notice['text'] ?? '' ) ) . '</p></div>';
		}

		echo '<section class="ab-ui-card"><h2>' . esc_html__( 'Installed from an archive', 'tomatio' ) . '</h2>';
		$pending = self::pending( $user );
		if ( $pending !== null ) {
			self::renderReview( $pending, $user );
		}

		$index = Installed::index();
		if ( $index === [] ) {
			echo '<p>' . esc_html__( 'None yet.', 'tomatio' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Extension', 'tomatio' ) . '</th><th>' . esc_html__( 'Version', 'tomatio' ) . '</th><th>' . esc_html__( 'Source', 'tomatio' ) . '</th><th>' . esc_html__( 'Pages', 'tomatio' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( array_keys( $index ) as $id ) {
				$record = Installed::record( (string) $id );
				if ( $record === null ) {
					continue;
				}
				echo '<tr><td><strong>' . esc_html( (string) $record['name'] ) . '</strong><br><code>' . esc_html( (string) $id ) . '</code>';
				if ( (string) $record['publisher'] !== '' ) {
					echo '<br>' . esc_html( sprintf( /* translators: %s: the publisher's name. */ __( 'By %s', 'tomatio' ), (string) $record['publisher'] ) );
				}
				echo '</td><td>' . esc_html( (string) $record['version'] ) . '</td><td>' . ( (string) $record['source'] !== '' ? esc_html( (string) $record['source'] ) : esc_html__( 'none given', 'tomatio' ) ) . '</td><td>' . esc_html( (string) count( Installer::pages( (string) $id ) ) ) . '</td><td>';
				self::button( self::PAGE, (string) $id, __( 'Add a page', 'tomatio' ), 'button' );
				self::button( self::REMOVE, (string) $id, __( 'Remove', 'tomatio' ), 'button-link-delete', __( 'Remove this extension? Its pages stay, as plain HTML pages.', 'tomatio' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::nonce( self::UPLOAD );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::UPLOAD ) . '" />';
		echo '<p><label for="ab-extension-zip">' . esc_html__( 'Install or update an extension from a zip (tomatio-extension.json at its top). You see what it is and what it may do before anything is installed.', 'tomatio' ) . '</label><br>';
		echo '<input type="file" id="ab-extension-zip" name="extension_zip" accept=".zip,application/zip" required /> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Check the archive', 'tomatio' ) . '</button></p></form>';
		echo '</section>';
	}

	/** @param array{bytes:array<string, string>} $pending */
	private static function renderReview( array $pending, int $user ): void {
		$checked = Installer::check( $pending['bytes'], $user );
		if ( $checked instanceof WP_Error ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html( $checked->get_error_message() ) . '</p></div>';
			self::button( self::CANCEL, '', __( 'Discard the archive', 'tomatio' ), 'button' );

			return;
		}
		$package = $checked['package'];
		$binding = $package->binding();
		$where   = match ( (string) ( $binding['per'] ?? '' ) ) {
			'person' => __( 'One page per person.', 'tomatio' ),
			'brain'  => __( 'One page for the whole brain.', 'tomatio' ),
			default  => __( 'As many pages as people add.', 'tomatio' ),
		};

		echo '<div class="ab-ui-callout"><h3>' . esc_html(
			$checked['action'] === 'update'
				/* translators: 1: extension name, 2: old version, 3: new version. */
				? sprintf( __( 'Update %1$s from %2$s to %3$s?', 'tomatio' ), $package->name(), $checked['from'], $package->version() )
				/* translators: 1: extension name, 2: version. */
				: sprintf( __( 'Install %1$s %2$s?', 'tomatio' ), $package->name(), $package->version() )
		) . '</h3><ul>';
		echo '<li>' . esc_html( sprintf( /* translators: %s: the extension's id. */ __( 'Id: %s', 'tomatio' ), $package->id() ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( /* translators: %s: publisher name. */ __( 'Publisher: %s', 'tomatio' ), $package->publisher() !== '' ? $package->publisher() : __( 'not given', 'tomatio' ) ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( /* translators: %s: source address. */ __( 'Source: %s. Later updates must come from the same source.', 'tomatio' ), $package->sourceRepo() !== '' ? $package->sourceRepo() : __( 'none given', 'tomatio' ) ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( /* translators: 1: number of files, 2: size. */ __( '%1$d files, %2$s, each checked against its SHA-256.', 'tomatio' ), count( $package->files() ), size_format( $package->bytes() ) ) ) . '</li>';
		echo '<li>' . esc_html( __( 'What it may do:', 'tomatio' ) . ' ' . Sandbox::describe( $package->sandbox() ) ) . '</li>';
		$collections = array_keys( $package->collections() );
		echo '<li>' . esc_html(
			$collections === []
				? __( 'Page store: none.', 'tomatio' )
				/* translators: %s: comma-separated collection names. */
				: sprintf( __( 'Page store collections: %s.', 'tomatio' ), implode( ', ', $collections ) )
		) . '</li>';
		echo '<li>' . esc_html( $where ) . ' ' . esc_html__( 'Its pages take their files from the extension only: nobody can change them by hand.', 'tomatio' ) . '</li>';
		if ( $checked['action'] === 'update' ) {
			echo '<li>' . esc_html__( 'Every page that runs it gets the new release at once. The earlier release is kept.', 'tomatio' ) . '</li>';
		}
		echo '</ul>';
		self::button( self::CONFIRM, '', $checked['action'] === 'update' ? __( 'Update', 'tomatio' ) : __( 'Install', 'tomatio' ), 'button button-primary' );
		self::button( self::CANCEL, '', __( 'Cancel', 'tomatio' ), 'button' );
		echo '</div>';
	}

	private static function button( string $action, string $id, string $label, string $class, string $confirm = '' ): void {
		echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $confirm !== '' ? ' onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ')"' : '' ) . '>';
		self::nonce( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="extension" value="' . esc_attr( $id ) . '" />';
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form> ';
	}

	/** A nonce field with no id: the screen has several forms, and ids must be unique (A11yScreensTest). */
	private static function nonce( string $action ): void {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	}

	public static function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the action only picks which nonce to check, below.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( (string) $_POST['action'] ) ) : '';
		if ( ! current_user_can( SettingsHub::CAPABILITY ) || ! in_array( $action, [ self::UPLOAD, self::CONFIRM, self::CANCEL, self::PAGE, self::REMOVE ], true ) ) {
			wp_die( esc_html__( 'You do not have permission to manage TomaTío settings.', 'tomatio' ), 403 );
		}
		check_admin_referer( $action );
		$user = (int) get_current_user_id();
		$id   = isset( $_POST['extension'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['extension'] ) ) : '';

		$result = match ( $action ) {
			self::UPLOAD  => self::upload( $user ),
			self::CONFIRM => self::confirm( $user ),
			self::CANCEL  => self::cancel( $user ),
			self::PAGE    => Installer::makePage( $id, $user ),
			default       => Installer::remove( $id, $user ),
		};

		self::notice( $user, $action, $result );
		wp_safe_redirect( SettingsHub::url( SettingsHub::TAB_EXTENSIONS ) );
		exit;
	}

	/** @return true|WP_Error */
	private static function upload( int $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle().
		$file = $_FILES['extension_zip'] ?? null;
		$tmp  = is_array( $file ) && isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'ab_extension_invalid', __( 'No archive was uploaded.', 'tomatio' ) );
		}

		return self::hold( Installer::readArchive( $tmp ), $user );
	}

	/**
	 * Keep an archive's files for $user's review (an hour).
	 *
	 * @param array<string, string>|WP_Error $bytes
	 * @return true|WP_Error
	 */
	public static function hold( $bytes, int $user ) {
		if ( $bytes instanceof WP_Error ) {
			return $bytes;
		}
		$checked = Installer::check( $bytes, $user );
		if ( $checked instanceof WP_Error ) {
			return $checked;
		}
		set_transient( self::PENDING . $user, array_map( 'base64_encode', $bytes ), HOUR_IN_SECONDS );

		return true;
	}

	/** @return array{bytes:array<string, string>}|null */
	public static function pending( int $user ): ?array {
		$held = get_transient( self::PENDING . $user );
		if ( ! is_array( $held ) || $held === [] ) {
			return null;
		}

		return [ 'bytes' => array_map( static fn ( $b ): string => (string) base64_decode( (string) $b, true ), $held ) ];
	}

	/** @return array{id:string, version:string, action:string, pages:array<int, string>}|WP_Error */
	public static function confirm( int $user ) {
		$pending = self::pending( $user );
		if ( $pending === null ) {
			return new WP_Error( 'ab_extension_invalid', __( 'There is no archive waiting; upload it again.', 'tomatio' ) );
		}
		$done = Installer::install( $pending['bytes'], $user );
		delete_transient( self::PENDING . $user );

		return $done;
	}

	/** @return true */
	private static function cancel( int $user ): bool {
		delete_transient( self::PENDING . $user );

		return true;
	}

	/** @param mixed $result */
	private static function notice( int $user, string $action, $result ): void {
		if ( $result instanceof WP_Error ) {
			$text = $result->get_error_message();
		} elseif ( $action === self::CONFIRM && is_array( $result ) ) {
			/* translators: 1: extension id, 2: version. */
			$text = sprintf( $result['action'] === 'update' ? __( 'Updated %1$s to %2$s.', 'tomatio' ) : __( 'Installed %1$s %2$s.', 'tomatio' ), $result['id'], $result['version'] );
		} elseif ( $action === self::PAGE ) {
			$text = __( 'Page added. Find it under Documents.', 'tomatio' );
		} elseif ( $action === self::REMOVE && is_array( $result ) ) {
			/* translators: %d: number of pages kept. */
			$text = sprintf( __( 'Removed. %d pages stay, as plain HTML pages.', 'tomatio' ), (int) $result['pages'] );
		} else {
			return;
		}
		set_transient( self::NOTICE . $user, [ 'ok' => ! $result instanceof WP_Error, 'text' => $text ], 5 * MINUTE_IN_SECONDS );
	}
}
