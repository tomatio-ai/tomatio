<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

use AgentBrain\Modules\Admin\SettingsHub;

/**
 * #993: the Get started checklist's optional "Connect Linear or Notion"
 * step, after Extensions. It is done once any integration is connected
 * on Settings > Integrations (a sealed key in Store), or once a curator
 * skips it. Both are remembered in OPTION, so disconnecting later does
 * not bring the step back.
 */
final class SetupStep {

	/** '' (open), 'connected' or 'skipped'. */
	public const OPTION = 'ab_setup_integrations';

	public const ACTION = 'ab_setup_integrations_skip';

	/** The nonce field's name; not `_wpnonce`, so its id is unique on the Overview. */
	public const NONCE = 'ab_setup_integrations_nonce';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/** Whether the step is done: an integration connected, or the step skipped. */
	public static function done(): bool {
		$stored = (string) get_option( self::OPTION, '' );
		if ( $stored === 'connected' || $stored === 'skipped' ) {
			return true;
		}
		if ( self::connected() ) {
			update_option( self::OPTION, 'connected', false );

			return true;
		}

		return false;
	}

	/** Whether any integration holds a key now. */
	public static function connected(): bool {
		foreach ( Store::all() as $row ) {
			if ( (string) ( $row['sealed'] ?? '' ) !== '' ) {
				return true;
			}
		}

		return false;
	}

	public static function skipped(): bool {
		return (string) get_option( self::OPTION, '' ) === 'skipped';
	}

	/**
	 * Skip the step. False when $userId may not manage settings.
	 */
	public static function skip( int $userId ): bool {
		if ( $userId <= 0 || ! user_can( $userId, SettingsHub::CAPABILITY ) ) {
			return false;
		}
		if ( self::done() ) {
			return true;
		}
		update_option( self::OPTION, 'skipped', false );
		\AgentBrain\Core\Hooks::action( 'audit', [
			'actor_id'    => $userId,
			'event'       => 'integration.setup_skipped',
			'status'      => 'ok',
			'object_type' => 'integration',
			'object_id'   => 'setup',
		] );

		return true;
	}

	/** The Skip button under the step, for someone who may skip it. */
	public static function skipForm(): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ab-step-skip">'
			. wp_nonce_field( self::ACTION, self::NONCE, true, false )
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />'
			. '<button type="submit" class="button button-link">' . esc_html__( 'Skip this step', 'tomatio' ) . '</button>'
			. '</form>';
	}

	public static function handle(): void {
		check_admin_referer( self::ACTION, self::NONCE );
		if ( ! self::skip( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage TomaTío settings.', 'tomatio' ), 403 );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=' . \AgentBrain\Modules\Admin\Menu::PARENT ) );
		exit;
	}
}
