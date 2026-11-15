<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;
use WP_Error;

/**
 * #987: Settings > Extensions, and the Get started checklist's "Choose
 * extensions" step links here. One switch per extension, each with a line
 * on what it does. Saving counts as choosing, even with both left off.
 */
final class SettingsTab {

	public const ACTION = 'ab_extensions';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handleSave' ] );
	}

	public static function renderBody(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag set by our own redirect.
		$status = isset( $_GET['ab_extensions'] ) ? sanitize_key( wp_unslash( (string) $_GET['ab_extensions'] ) ) : '';
		if ( $status === 'saved' ) {
			echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__( 'Saved.', 'tomatio' ) . '</p></div>';
		} elseif ( $status !== '' ) {
			$error = get_transient( 'ab_extensions_error_' . get_current_user_id() );
			delete_transient( 'ab_extensions_error_' . get_current_user_id() );
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html( is_string( $error ) && $error !== '' ? $error : __( 'Nothing was saved.', 'tomatio' ) ) . '</p></div>';
		}

		$tioday  = Switches::describe( Switches::TIODAY );
		$tomagic = Switches::describe( Switches::BACKLOG );

		echo '<section class="ab-ui-card"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::setting(
			__( 'TíoDay (one per person)', 'tomatio' ),
			$tioday['enabled']
				? esc_html__( 'On: every person gets a TíoDay, a private page their AIs keep current.', 'tomatio' )
				: esc_html__( 'Off: no TíoDays are made. Any that exist stay.', 'tomatio' ),
			Ui::toggle( 'tioday', 'ab-ext-tioday', (bool) $tioday['enabled'] ),
			'ab-ext-tioday',
			'<p>' . esc_html( (string) $tioday['what'] ) . ' ' . esc_html__( 'Each person\'s is made the first time they sign in, open the home page or connect an AI, led by their default agent. Anyone can turn their own off on their profile. Turning this off makes no new ones; existing TíoDays stay as they are.', 'tomatio' ) . '</p>'
		);
		$open = $tomagic['enabled'] && (string) ( $tomagic['url'] ?? '' ) !== ''
			? ' <a href="' . esc_url( (string) $tomagic['url'] ) . '">' . esc_html__( 'Open Tomagic', 'tomatio' ) . '</a>'
			: '';
		echo Ui::setting(
			__( 'Tomagic (one for the brain)', 'tomatio' ),
			( $tomagic['enabled']
				? esc_html__( 'On: the brain has its board.', 'tomatio' )
				: esc_html__( 'Off: no board. Turn it on for a software project.', 'tomatio' ) ) . $open,
			Ui::toggle( 'tomagic', 'ab-ext-tomagic', (bool) $tomagic['enabled'] ),
			'ab-ext-tomagic',
			'<p><strong>' . esc_html( (string) $tomagic['tagline'] ) . '</strong> ' . esc_html( (string) $tomagic['what'] ) . ' ' . esc_html__( 'Off until someone turns it on. Turning it on makes the board in the team scope, led by your agent (or brings back the one archived when it was turned off). Turning it off archives the board with everything on it; nothing is deleted.', 'tomatio' ) . '</p>'
		);
		echo Ui::saveBar( 'ab_extensions_submit' );
		// phpcs:enable
		echo '</form></section>';
	}

	public static function handleSave(): void {
		if ( ! current_user_can( SettingsHub::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage TomaTío settings.', 'tomatio' ), 403 );
		}
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$result = self::save( wp_unslash( $_POST ), (int) get_current_user_id() );
		if ( $result instanceof WP_Error ) {
			set_transient( 'ab_extensions_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( SettingsHub::url( SettingsHub::TAB_EXTENSIONS, [ 'ab_extensions' => $result instanceof WP_Error ? 'error' : 'saved' ] ) );
		exit;
	}

	/**
	 * Apply the form: each switch that changed, then mark the choice made.
	 *
	 * @param mixed $post
	 * @return true|WP_Error
	 */
	public static function save( $post, int $actorId ) {
		$post = is_array( $post ) ? $post : [];
		$all  = Switches::all();
		foreach ( [ 'tioday' => Switches::TIODAY, 'tomagic' => Switches::BACKLOG ] as $field => $name ) {
			$on = isset( $post[ $field ] ) && $post[ $field ] === '1';
			if ( $on === $all[ $name ] ) {
				continue;
			}
			$done = Switches::set( $name, $on, $actorId );
			if ( $done instanceof WP_Error ) {
				return $done;
			}
		}
		Switches::markChosen();

		return true;
	}
}
