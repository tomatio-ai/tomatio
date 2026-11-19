<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Integrations\Context\Page as ContextPage;
use AgentBrain\Modules\Integrations\Planning\Page as PlanningPage;

/**
 * #990: Settings > Integrations. Curators connect, test and disconnect
 * each service. A stored key is never written into the page.
 */
final class SettingsPage {

	public static function renderBody(): void {
		if ( ! current_user_can( SettingsHub::CAPABILITY ) ) {
			return;
		}
		self::notice();
		$catalog = new Catalog();
		foreach ( $catalog->problems() as $problem ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $problem ) . '</p></div>';
		}
		if ( $catalog->services() === [] ) {
			echo '<p>' . esc_html__( 'No integrations are installed.', 'tomatio' ) . '</p>';

			return;
		}
		$redirect = Actions::redirectUri();
		foreach ( $catalog->services() as $id => $spec ) {
			self::card( $id, $spec, Store::row( $id ), $redirect );
		}
	}

	/**
	 * @param array<string, mixed> $spec
	 * @param array<string, mixed> $row
	 */
	private static function card( string $id, array $spec, array $row, string $redirect ): void {
		$public = Vault::publicRow( $row, $id, (string) $spec['name'] );
		$app    = Store::app( $id, $row );
		$ready  = $app['id'] !== '' && $app['secret'] !== '';
		echo '<section class="ab-ui-card" id="integration-' . esc_attr( $id ) . '">';
		echo '<h3>' . esc_html( (string) $spec['name'] ) . '</h3>';
		if ( (string) $spec['blurb'] !== '' ) {
			echo '<p>' . esc_html( (string) $spec['blurb'] ) . '</p>';
		}
		echo '<p>' . esc_html( self::statusLine( $public, $ready ) ) . '</p>';
		if ( ! empty( $public['failing'] ) && (string) $public['message'] !== '' ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html( (string) $public['message'] ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( Actions::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::ACTION ) . '" />';
		echo '<input type="hidden" name="service" value="' . esc_attr( $id ) . '" />';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::setting(
			(string) $spec['token_label'],
			esc_html( (string) $spec['token_help'] ),
			'<input type="password" class="regular-text" id="ab-int-token-' . esc_attr( $id ) . '" name="token" autocomplete="off" />',
			'ab-int-token-' . $id
		);
		// phpcs:enable
		echo '<p class="submit">';
		echo '<button type="submit" class="button button-primary" name="do" value="token">' . esc_html__( 'Save and test', 'tomatio' ) . '</button> ';
		if ( ! empty( $public['connected'] ) ) {
			echo '<button type="submit" class="button" name="do" value="test">' . esc_html__( 'Test', 'tomatio' ) . '</button> ';
			echo '<button type="submit" class="button button-link-delete" name="do" value="disconnect">' . esc_html__( 'Disconnect', 'tomatio' ) . '</button>';
		}
		echo '</p></form>';

		if ( is_array( $spec['oauth'] ) ) {
			self::oauthForm( $id, $spec, $app, $ready, $redirect );
		}
		if ( ! empty( $public['connected'] ) ) {
			ContextPage::render( $id );
			PlanningPage::render( $id );
		}
		echo '</section>';
	}

	/**
	 * @param array<string, mixed> $spec
	 * @param array{id: string, secret: string, from_config: bool} $app
	 */
	private static function oauthForm( string $id, array $spec, array $app, bool $ready, string $redirect ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( Actions::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::ACTION ) . '" />';
		echo '<input type="hidden" name="service" value="' . esc_attr( $id ) . '" />';
		echo '<h4>' . esc_html__( 'Or sign in', 'tomatio' ) . '</h4>';
		echo '<p>' . esc_html__( 'Register this address as the redirect on the app, then connect. A pasted key works without an app.', 'tomatio' ) . '</p>';
		echo '<p><code>' . esc_html( $redirect ) . '</code></p>';
		if ( $app['from_config'] ) {
			echo '<p>' . esc_html__( 'This brain uses the app set in its configuration.', 'tomatio' ) . '</p>';
		} else {
			$secretNote = $app['secret'] !== ''
				? __( 'An app secret is stored, encrypted. It is never shown again. Paste a new one to replace it.', 'tomatio' )
				: __( 'The app secret is stored encrypted and never shown again.', 'tomatio' );
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
			echo Ui::setting(
				__( 'App id', 'tomatio' ),
				'',
				'<input type="text" class="regular-text" id="ab-int-app-' . esc_attr( $id ) . '" name="client_id" value="' . esc_attr( $app['id'] ) . '" autocomplete="off" />',
				'ab-int-app-' . $id
			);
			echo Ui::setting(
				__( 'App secret', 'tomatio' ),
				esc_html( $secretNote ),
				'<input type="password" class="regular-text" id="ab-int-secret-' . esc_attr( $id ) . '" name="client_secret" autocomplete="off" />',
				'ab-int-secret-' . $id
			);
			// phpcs:enable
		}
		echo '<p class="submit">';
		if ( ! $app['from_config'] ) {
			echo '<button type="submit" class="button" name="do" value="app">' . esc_html__( 'Save app', 'tomatio' ) . '</button> ';
		}
		if ( $ready ) {
			echo '<button type="submit" class="button button-primary" name="do" value="oauth">' . esc_html( sprintf(
				/* translators: %s: service name, such as Linear. */
				__( 'Connect %s', 'tomatio' ),
				(string) $spec['name']
			) ) . '</button>';
		}
		echo '</p></form>';
	}

	/** @param array<string, mixed> $public */
	private static function statusLine( array $public, bool $ready ): string {
		if ( empty( $public['connected'] ) ) {
			return $ready
				? __( 'Not connected. Paste a key, or sign in.', 'tomatio' )
				: __( 'Not connected. Paste a key, or add an app and sign in.', 'tomatio' );
		}
		$how = ( $public['auth'] ?? '' ) === 'oauth'
			? __( 'Signed in', 'tomatio' )
			: __( 'Key stored', 'tomatio' );
		$who = (string) ( $public['account'] ?? '' );
		$line = $who !== '' ? $how . ', ' . $who . '.' : $how . '.';
		if ( ! empty( $public['failing'] ) ) {
			$line .= ' ' . __( 'This connection is failing.', 'tomatio' );
		}
		$reads = (int) ( $public['reads'] ?? 0 );
		if ( $reads > 0 ) {
			$line .= ' ' . sprintf(
				/* translators: %d: number of reads counted, not listed one by one. */
				_n( '%d read.', '%d reads.', $reads, 'tomatio' ),
				$reads
			);
		}

		return $line;
	}

	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by our own redirect.
		if ( ! isset( $_GET['ab_integration'] ) ) {
			return;
		}
		$said = get_transient( 'ab_int_notice_' . (int) get_current_user_id() );
		delete_transient( 'ab_int_notice_' . (int) get_current_user_id() );
		if ( ! is_array( $said ) || ! is_string( $said['text'] ?? null ) ) {
			return;
		}
		$ok = ! empty( $said['ok'] );
		echo '<div class="notice ' . esc_attr( $ok ? 'notice-success' : 'notice-error' ) . ' inline" role="' . esc_attr( $ok ? 'status' : 'alert' ) . '"><p>' . esc_html( $said['text'] ) . '</p></div>';
	}
}
