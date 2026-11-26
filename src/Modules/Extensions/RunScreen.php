<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Admin\SettingsHub;
use WP_Error;

/**
 * #994 (RFC section 6): the "Running extensions" part of Settings >
 * Extensions, for managers who are people. One row per extension, bundled
 * or installed: the release it runs, "Turn off" / "Turn on", "Roll back to
 * X.Y.Z" for each other kept release (installed ones only: bundled ones
 * keep none), and its bound pages, each with "Pin" / "Unpin".
 *
 * An extension that asks for sandbox flags beyond a plain page also gets
 * "Base sandbox only" / "Allow its sandbox flags" next to "Turn off"
 * (Sandbox::setBaseOnly). A rollback whose older release has store rules
 * that may break the pages' data says so on its row and in its confirm
 * (Installer::rollbackWarnings); it warns, it does not block.
 */
final class RunScreen {

	public const OFF      = 'ab_extension_off';
	public const ON       = 'ab_extension_on';
	public const ROLLBACK = 'ab_extension_rollback';
	public const PIN      = 'ab_extension_pin';
	public const UNPIN    = 'ab_extension_unpin';
	public const BASE     = 'ab_extension_base_sandbox';
	public const EXTRA    = 'ab_extension_extra_sandbox';

	private const ACTIONS = [ self::OFF, self::ON, self::ROLLBACK, self::PIN, self::UNPIN, self::BASE, self::EXTRA ];

	private const NOTICE = 'ab_extension_run_notice_';

	public static function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, [ self::class, 'handle' ] );
		}
	}

	/**
	 * Every extension on this brain: the bundled ones, then the installed ones.
	 *
	 * @return array<string, Package>
	 */
	public static function extensions(): array {
		$out = [];
		foreach ( Installer::BUNDLED as $folder ) {
			$package = Package::bundled( $folder );
			if ( $package instanceof Package ) {
				$out[ $package->id() ] = $package;
			}
		}
		foreach ( array_keys( Installed::index() ) as $id ) {
			$package = Sandbox::package( (string) $id );
			if ( $package !== null ) {
				$out[ (string) $id ] = $package;
			}
		}

		return $out;
	}

	public static function render(): void {
		$user = (int) get_current_user_id();
		if ( Installer::mayRun( $user ) instanceof WP_Error ) {
			return;
		}

		$notice = get_transient( self::NOTICE . $user );
		delete_transient( self::NOTICE . $user );
		if ( is_array( $notice ) ) {
			$class = ( $notice['ok'] ?? false ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' inline" role="status"><p>' . esc_html( (string) ( $notice['text'] ?? '' ) ) . '</p></div>';
		}

		echo '<section class="ab-ui-card"><h2>' . esc_html__( 'Running extensions', 'tomatio' ) . '</h2>';
		echo '<p>' . esc_html__( 'Turning an extension off serves its pages as "not found" and takes them off the home page; nothing is deleted, and turning it on brings them back. Rolling back serves an earlier kept release to every page that is not pinned. A pinned page stays on its release through updates and rollbacks.', 'tomatio' ) . '</p>';
		if ( TurnedOff::allOff() ) {
			echo '<div class="notice notice-warning inline" role="status"><p>' . esc_html__( 'All extensions are turned off (Settings > Sharing, "Turn off all extensions").', 'tomatio' ) . '</p></div>';
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Extension', 'tomatio' ) . '</th><th>' . esc_html__( 'Running', 'tomatio' ) . '</th><th>' . esc_html__( 'Pages', 'tomatio' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( self::extensions() as $id => $package ) {
			$off = in_array( $id, TurnedOff::ids(), true );
			echo '<tr><td><strong>' . esc_html( $package->name() ) . '</strong><br><code>' . esc_html( $id ) . '</code><br>'
				. ( TurnedOff::isOff( $id ) ? esc_html__( 'Off: its pages are not served.', 'tomatio' ) : esc_html__( 'On.', 'tomatio' ) )
				. ( Sandbox::baseOnly( $id ) ? '<br>' . esc_html__( 'Runs with the base sandbox only.', 'tomatio' ) : '' ) . '</td>';
			echo '<td>' . esc_html( $package->version() ) . ( Installed::has( $id ) ? '' : '<br>' . esc_html__( 'Comes with the plugin.', 'tomatio' ) ) . '</td><td>';
			self::renderPages( $id, $package );
			echo '</td><td>';
			if ( $off ) {
				self::button( self::ON, [ 'extension' => $id ], __( 'Turn on', 'tomatio' ), 'button' );
			} else {
				self::button( self::OFF, [ 'extension' => $id ], __( 'Turn off', 'tomatio' ), 'button', __( 'Turn this extension off? Its pages are served as "not found" until you turn it on again. Nothing is deleted.', 'tomatio' ) );
			}
			if ( Sandbox::baseOnly( $id ) ) {
				self::button( self::EXTRA, [ 'extension' => $id ], __( 'Allow its sandbox flags', 'tomatio' ), 'button' );
			} elseif ( array_intersect( Sandbox::ALLOWED, $package->sandbox() ) !== [] ) {
				self::button( self::BASE, [ 'extension' => $id ], __( 'Base sandbox only', 'tomatio' ), 'button', __( 'Run this extension with the base sandbox only? Its pages keep their scripts, but lose what it asks for beyond a plain page (links in a new tab) until you allow it again.', 'tomatio' ) );
			}
			// Bundled extensions keep no releases (their source is the plugin): no rollback.
			foreach ( Installed::releases( $id ) as $version ) {
				if ( $version === $package->version() ) {
					continue;
				}
				$warns   = Installer::rollbackWarnings( $id, $version );
				$confirm = __( 'Serve this release to every page that is not pinned? Store data stays as it is.', 'tomatio' );
				if ( $warns !== [] ) {
					/* translators: %s: what may break, in sentences. */
					$confirm .= ' ' . sprintf( __( 'Warning: its store rules may break data the pages have now. %s', 'tomatio' ), Installer::describeBreaking( $warns ) );
				}
				/* translators: %s: a version, such as 1.3.2. */
				self::button( self::ROLLBACK, [ 'extension' => $id, 'version' => $version ], sprintf( __( 'Roll back to %s', 'tomatio' ), $version ), 'button', $confirm );
				if ( $warns !== [] ) {
					/* translators: 1: a version, 2: what may break, in sentences. */
					echo '<p class="ab-store-breaking"><strong>' . esc_html( sprintf( __( 'Rolling back to %1$s may break store data: %2$s', 'tomatio' ), $version, Installer::describeBreaking( $warns ) ) ) . '</strong></p>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	private static function renderPages( string $id, Package $package ): void {
		$pages = array_values( array_filter( Installer::pages( $id ), static fn ( int $page ): bool => Binding::extensionId( $page ) === $id ) );
		if ( $pages === [] ) {
			echo esc_html__( 'None.', 'tomatio' );

			return;
		}
		echo '<ul>';
		foreach ( $pages as $page ) {
			$served = (string) get_post_meta( $page, PageMaker::META_VERSION, true );
			$pin    = Pin::version( $page );
			echo '<li>' . esc_html( get_the_title( $page ) !== '' ? get_the_title( $page ) : '#' . $page ) . ' ' . esc_html( '(#' . $page . ', ' . ( $served !== '' ? $served : $package->version() ) . ')' ) . ' ';
			if ( $pin !== '' ) {
				/* translators: %s: a version. */
				echo esc_html( sprintf( __( 'Pinned to %s.', 'tomatio' ), $pin ) ) . ' ';
				self::button( self::UNPIN, [ 'artifact' => (string) $page ], __( 'Unpin', 'tomatio' ), 'button-link' );
			} else {
				self::button( self::PIN, [ 'artifact' => (string) $page ], __( 'Pin', 'tomatio' ), 'button-link' );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/** @param array<string, string> $fields */
	private static function button( string $action, array $fields, string $label, string $class, string $confirm = '' ): void {
		echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $confirm !== '' ? ' onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ')"' : '' ) . '>';
		// A nonce field with no id: the screen has several forms, and ids must be unique (A11yScreensTest).
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form> ';
	}

	public static function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the action only picks which nonce to check, below.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( (string) $_POST['action'] ) ) : '';
		if ( ! current_user_can( SettingsHub::CAPABILITY ) || ! in_array( $action, self::ACTIONS, true ) ) {
			wp_die( esc_html__( 'You do not have permission to manage TomaTío settings.', 'tomatio' ), 403 );
		}
		check_admin_referer( $action );
		$user    = (int) get_current_user_id();
		$id      = isset( $_POST['extension'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['extension'] ) ) : '';
		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['version'] ) ) : '';
		$page    = isset( $_POST['artifact'] ) ? absint( wp_unslash( (string) $_POST['artifact'] ) ) : 0;

		$result = self::apply( $action, $user, $id, $version, $page );
		$text   = $result instanceof WP_Error ? $result->get_error_message() : self::done( $action, $id, $version, $result );
		set_transient( self::NOTICE . $user, [ 'ok' => ! $result instanceof WP_Error, 'text' => $text ], 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( SettingsHub::url( SettingsHub::TAB_EXTENSIONS ) );
		exit;
	}

	/**
	 * One form's action, for $user.
	 *
	 * @return mixed|WP_Error
	 */
	public static function apply( string $action, int $user, string $id, string $version, int $page ) {
		return match ( $action ) {
			self::OFF      => TurnedOff::set( $id, true, $user ),
			self::ON       => TurnedOff::set( $id, false, $user ),
			self::ROLLBACK => Installer::rollBack( $id, $version, $user ),
			self::PIN      => Pin::set( $page, $user ),
			self::UNPIN    => Pin::clear( $page, $user ),
			self::BASE     => Sandbox::setBaseOnly( $id, true, $user ),
			self::EXTRA    => Sandbox::setBaseOnly( $id, false, $user ),
			default        => new WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'tomatio' ), [ 'status' => 403 ] ),
		};
	}

	/** @param mixed $result */
	private static function done( string $action, string $id, string $version, $result ): string {
		return match ( $action ) {
			self::OFF      => __( 'Turned off. Its pages are served as "not found" until you turn it on again.', 'tomatio' ),
			self::ON       => __( 'Turned on. Its pages are served again.', 'tomatio' ),
			/* translators: 1: extension id, 2: version, 3: number of pages that got it. */
			self::ROLLBACK => sprintf( __( 'Rolled %1$s back to %2$s; %3$d pages run it (pinned pages kept theirs).', 'tomatio' ), $id, $version, is_array( $result ) && is_array( $result['pages'] ?? null ) ? count( array_filter( $result['pages'], static fn ( $what ): bool => in_array( $what, [ 'refreshed', 'current' ], true ) ) ) : 0 )
				. ( is_array( $result ) && is_array( $result['store_breaking'] ?? null ) && $result['store_breaking'] !== [] ? ' ' . Installer::describeBreaking( $result['store_breaking'] ) : '' ),
			self::BASE     => __( 'Its pages now run with the base sandbox only.', 'tomatio' ),
			self::EXTRA    => __( 'Its pages run with the sandbox flags it asks for and was approved again.', 'tomatio' ),
			/* translators: %s: a version. */
			self::PIN      => sprintf( __( 'Pinned to %s. Updates and rollbacks skip this page.', 'tomatio' ), is_string( $result ) ? $result : '' ),
			default        => __( 'Unpinned. The page runs the extension\'s current release.', 'tomatio' ),
		};
	}
}
