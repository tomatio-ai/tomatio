<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Identity\Capabilities;

/**
 * #606: "TíoDay" on a person's profile, the switch for their own page.
 * Turning it off archives their TíoDay (nothing is deleted) and stops a new
 * one being made; turning it on brings the archived one back, or makes one.
 * Shown while the brain makes TíoDays (TioDayDefault::enabled()), to people
 * only, never on an agent's profile.
 */
final class TioDayProfile {

	public const FIELD        = 'ab_tioday_on';
	public const NONCE_ACTION = 'ab_tioday_save';
	public const NONCE_FIELD  = 'ab_tioday_nonce';

	public static function register(): void {
		add_action( 'show_user_profile', [ self::class, 'render' ] );
		add_action( 'edit_user_profile', [ self::class, 'render' ] );
		add_action( 'personal_options_update', [ self::class, 'save' ] );
		add_action( 'edit_user_profile_update', [ self::class, 'save' ] );
	}

	public static function render( \WP_User $user ): void {
		$userId = (int) $user->ID;
		if ( ! TioDayDefault::enabled() || Capabilities::isPureAgent( $userId ) || ! current_user_can( 'edit_user', $userId ) ) {
			return;
		}

		$on = ! TioDayDefault::isOff( $userId );
		$id = $on ? TioDay::forPerson( $userId ) : 0;
		if ( $on && $id > 0 ) {
			$state = sprintf(
				'<a href="%s">%s</a>',
				esc_url( TioDay::url( $id ) ),
				esc_html__( 'Open the TíoDay', 'tomatio' )
			);
		} elseif ( $on ) {
			$state = TioDayDefault::lead( $userId ) > 0
				? esc_html__( 'It is made the next time this person signs in or their AI connects.', 'tomatio' )
				: esc_html__( 'It is made once this person has an agent.', 'tomatio' );
		} else {
			$state = esc_html__( 'Off. Turning it on brings back the archived page, with everything on it.', 'tomatio' );
		}

		echo '<h2>' . esc_html__( 'TíoDay', 'tomatio' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tr><th>' . esc_html__( 'TíoDay', 'tomatio' ) . '</th><td>';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="0" />';
		echo '<label for="' . esc_attr( self::FIELD ) . '"><input type="checkbox" name="' . esc_attr( self::FIELD ) . '" id="' . esc_attr( self::FIELD ) . '" value="1"' . checked( $on, true, false ) . ' /> '
			. esc_html__( 'Keep a TíoDay', 'tomatio' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'A private page of what is yours to do, what your AIs are working on, what got done and what you decided. Your AIs keep it current. Turning it off archives the page; nothing is deleted.', 'tomatio' ) . '</p>';
		echo '<p class="description">' . $state . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '</td></tr></table>';
	}

	public static function save( int $userId ): void {
		// The field is only on the form while the brain makes TíoDays; no nonce, nothing to save.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( (string) wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $userId ) || Capabilities::isPureAgent( $userId ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$on = isset( $_POST[ self::FIELD ] ) && wp_unslash( (string) $_POST[ self::FIELD ] ) === '1';
		if ( $on === ! TioDayDefault::isOff( $userId ) ) {
			return;
		}

		if ( $on ) {
			TioDayDefault::turnOn( $userId, (int) get_current_user_id() );
		} else {
			TioDayDefault::turnOff( $userId, (int) get_current_user_id() );
		}
	}
}
