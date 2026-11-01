<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

use AgentBrain\Modules\Admin\Confirm;
use AgentBrain\Modules\Admin\Notices;
use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;

/**
 * Settings section for the OpenAI-compatible summarising endpoint: base URL,
 * API key, model, "this endpoint is on my own network", and Test connection.
 * Rendered inside the Summarising tab (slug cloud) of the settings hub,
 * one row per setting (#751).
 *
 * The API key is write-only: it is never written back into the form, never
 * included in a redirect or notice, and audit rows record only whether it
 * was replaced or cleared.
 */
final class EndpointSettings {

	public const CAPABILITY    = 'ab_manage';
	public const ACTION_SAVE   = 'ab_endpoint_save';
	public const ACTION_TEST   = 'ab_endpoint_test';
	public const ACTION_REMOVE = 'ab_endpoint_remove';

	private const RESULT_ARG = 'ab_endpoint';
	private const CODE_ARG   = 'ab_endpoint_code';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, [ self::class, 'handleSave' ] );
		add_action( 'admin_post_' . self::ACTION_TEST, [ self::class, 'handleTest' ] );
		add_action( 'admin_post_' . self::ACTION_REMOVE, [ self::class, 'handleRemove' ] );
	}

	public static function renderSection(): void {
		self::renderResultNotice();

		$status = Endpoint::keyStatus();
		$url    = Endpoint::baseUrl();
		$model  = Endpoint::model();
		$local  = Endpoint::allowLocal();

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__( 'OpenAI-compatible endpoint', 'agent-brain' ) . '</h3>';
		echo '<p>' . esc_html__( 'Summarise old memories with any service that speaks the OpenAI chat completions API: OpenAI, Cloudflare Workers AI or AI Gateway, OpenRouter, or a server on your own network such as Ollama.', 'agent-brain' ) . '</p>';

		if ( $status === Endpoint::KEY_CHANGED || $status === Endpoint::KEY_BROKEN ) {
			$unreadable = $status === Endpoint::KEY_CHANGED
				? __( 'The stored API key can no longer be read because this site\'s wp-config.php salts (or AGENT_BRAIN_SECRET_KEY) changed. The endpoint is not used until you enter the API key again.', 'agent-brain' )
				: __( 'The stored API key could not be decrypted and may have been modified. The endpoint is not used until you enter the API key again.', 'agent-brain' );
			echo Notices::html( 'error', $unreadable, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notices::html() escapes.
		}

		$notSet = esc_html__( 'Not set.', 'agent-brain' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::setting() escapes what it emits.
		echo Ui::setting(
			__( 'Base URL', 'agent-brain' ),
			$url !== '' ? '<code>' . esc_html( $url ) . '</code>' : $notSet,
			'<input type="url" id="ab_endpoint_url" name="ab_endpoint_url" class="regular-text" placeholder="https://api.openai.com/v1" value="' . esc_attr( $url ) . '" autocomplete="off" />',
			'ab_endpoint_url',
			'<p>' . esc_html__( 'The part before /chat/completions. For Cloudflare Workers AI: https://api.cloudflare.com/client/v4/accounts/ACCOUNT_ID/ai/v1', 'agent-brain' ) . '</p>'
		);
		echo Ui::setting(
			__( 'Model', 'agent-brain' ),
			$model !== '' ? '<code>' . esc_html( $model ) . '</code>' : $notSet,
			'<input type="text" id="ab_endpoint_model" name="ab_endpoint_model" class="regular-text" value="' . esc_attr( $model ) . '" autocomplete="off" />',
			'ab_endpoint_model'
		);
		echo Ui::setting(
			__( 'API key', 'agent-brain' ),
			esc_html( self::keyStateText( $status ) ),
			'<input type="password" id="ab_endpoint_key" name="ab_endpoint_key" class="regular-text" value="" autocomplete="new-password" spellcheck="false" />'
				. ( $status !== Endpoint::KEY_NONE ? '<label><input type="checkbox" name="ab_endpoint_clear_key" value="1" /> ' . esc_html__( 'Remove the stored key', 'agent-brain' ) . '</label>' : '' ),
			'ab_endpoint_key',
			'<p>' . esc_html__( 'The API key is encrypted in the database with a key derived from your wp-config.php salts. It is never shown again, never written to logs, and never included in exports or backups. Only memory text that is being summarised is sent to this endpoint.', 'agent-brain' ) . '</p>'
		);
		echo Ui::setting(
			__( 'On my own network', 'agent-brain' ),
			$local
				? esc_html__( 'On: http:// and private addresses are allowed, and the key is optional.', 'agent-brain' )
				: esc_html__( 'Off: only https:// addresses on the public internet are accepted.', 'agent-brain' ),
			Ui::toggle( 'ab_endpoint_local', 'ab_endpoint_local', $local ),
			'ab_endpoint_local',
			'<p>' . esc_html__( 'Leave off for a hosted service. Turn it on only for a server you run yourself, such as Ollama or LM Studio.', 'agent-brain' ) . '</p>'
		);
		echo Ui::saveBar( 'ab_endpoint_save_button', __( 'Save endpoint', 'agent-brain' ) );
		// phpcs:enable
		echo '</form>';

		if ( Endpoint::isSet() ) {
			$test = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. wp_nonce_field( self::ACTION_TEST, '_wpnonce', true, false )
				. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_TEST ) . '" />'
				. '<button type="submit" name="ab_endpoint_test_button" value="1" class="button">' . esc_html__( 'Test connection', 'agent-brain' ) . '</button></form>';
			$remove = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . Confirm::attr( Confirm::message( __( 'Remove the endpoint: its URL, model and encrypted API key are deleted from this site and compression falls back to the next available option', 'agent-brain' ), __( 'You can enter them again at any time', 'agent-brain' ) ) ) . '>'
				. wp_nonce_field( self::ACTION_REMOVE, '_wpnonce', true, false )
				. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REMOVE ) . '" />'
				. '<button type="submit" name="ab_endpoint_remove_button" value="1" class="button button-link-delete">' . esc_html__( 'Remove endpoint', 'agent-brain' ) . '</button></form>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::setting() escapes what it emits.
			echo Ui::setting( __( 'Check it works', 'agent-brain' ), esc_html__( 'Sends one short request with the saved settings.', 'agent-brain' ), $test . $remove );
		}
		echo '</section>';
	}

	private static function keyStateText( string $status ): string {
		switch ( $status ) {
			case Endpoint::KEY_OK:
				return __( 'A key is stored (encrypted). It is not shown. Type a new key to replace it, or leave this blank to keep it.', 'agent-brain' );
			case Endpoint::KEY_CHANGED:
			case Endpoint::KEY_BROKEN:
				return __( 'A stored key cannot be read. Enter the key again to replace it.', 'agent-brain' );
			default:
				return __( 'No key stored.', 'agent-brain' );
		}
	}

	private static function renderResultNotice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only result flags set by our own redirects.
		if ( ! isset( $_GET[ self::RESULT_ARG ] ) ) {
			return;
		}
		$result = sanitize_key( wp_unslash( (string) $_GET[ self::RESULT_ARG ] ) );
		$code   = isset( $_GET[ self::CODE_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::CODE_ARG ] ) ) : '';
		// phpcs:enable

		switch ( $result ) {
			case 'saved':
				$notice = [ 'success', __( 'Endpoint saved. Compression will use it from the next run.', 'agent-brain' ) ];
				break;
			case 'removed':
				$notice = [ 'success', __( 'Endpoint removed, including the stored API key.', 'agent-brain' ) ];
				break;
			case 'tested':
				$notice = [ 'success', __( 'Connection works: the endpoint answered a test request.', 'agent-brain' ) ];
				break;
			case 'test_failed':
				$notice = [ 'error', self::testFailureText( $code ) ];
				break;
			case 'error':
				$notice = [ 'error', self::saveErrorText( $code ) ];
				break;
			default:
				return;
		}

		echo Notices::html( $notice[0], $notice[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notices::html() escapes.
	}

	private static function saveErrorText( string $code ): string {
		$code = str_starts_with( $code, 'ab_endpoint_' ) ? substr( $code, strlen( 'ab_endpoint_' ) ) : $code;

		switch ( $code ) {
			case 'model':
				return __( 'Enter the model name.', 'agent-brain' );
			case 'key_invalid':
				return __( 'The API key contains characters that are not allowed.', 'agent-brain' );
			case 'encrypt':
				return __( 'The API key could not be encrypted because the site has no usable key material. Set AUTH_KEY and AUTH_SALT in wp-config.php.', 'agent-brain' );
			default:
				return UrlGuard::message( $code );
		}
	}

	private static function testFailureText( string $code ): string {
		switch ( $code ) {
			case 'ab_endpoint_key_changed':
			case 'ab_endpoint_key_unreadable':
				return __( 'The stored API key cannot be read (the site\'s wp-config.php salts changed). Enter the API key again.', 'agent-brain' );
			case 'ab_compression_endpoint_auth':
				return __( 'The endpoint rejected the API key. Check the key and that it may use this model.', 'agent-brain' );
			case 'ab_compression_endpoint_http':
				return __( 'The endpoint returned an error. Check the base URL and the model name.', 'agent-brain' );
			case 'ab_compression_endpoint_transport':
				return __( 'Could not reach the endpoint. Check the base URL and that the server is up.', 'agent-brain' );
			case 'ab_compression_endpoint_url_rejected':
				return __( 'The endpoint address was refused (it must be https:// and public, unless it is marked as on your own network).', 'agent-brain' );
			case 'ab_compression_endpoint_unconfigured':
				return __( 'Enter and save a base URL and a model first.', 'agent-brain' );
			default:
				return __( 'The endpoint answered, but not like an OpenAI-compatible chat completions API.', 'agent-brain' );
		}
	}

	public static function handleSave(): void {
		self::guard( self::ACTION_SAVE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$url   = isset( $_POST['ab_endpoint_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['ab_endpoint_url'] ), [ 'http', 'https' ] ) : '';
		$model = isset( $_POST['ab_endpoint_model'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ab_endpoint_model'] ) ) : '';
		// The key is a secret: no sanitiser may alter it, and it is only ever sealed.
		$key   = isset( $_POST['ab_endpoint_key'] ) && is_string( $_POST['ab_endpoint_key'] ) ? wp_unslash( $_POST['ab_endpoint_key'] ) : '';
		$clear = ! empty( $_POST['ab_endpoint_clear_key'] );
		$local = ! empty( $_POST['ab_endpoint_local'] );
		// phpcs:enable

		$result = Endpoint::save( $url, $model, $local, $key, $clear );
		unset( $key );

		if ( $result instanceof \WP_Error ) {
			self::redirect( [ self::RESULT_ARG => 'error', self::CODE_ARG => $result->get_error_code() ] );

			return;
		}

		do_action( 'agent_brain/audit', [
			'actor_id'    => get_current_user_id(),
			'event'       => 'compression.endpoint_updated',
			'status'      => 'ok',
			'object_type' => 'compression_endpoint',
			'object_id'   => null,
			// Never the key or the URL path (it can embed an account id); only the host and what changed.
			'meta'        => [
				'host'          => (string) wp_parse_url( Endpoint::baseUrl(), PHP_URL_HOST ),
				'model'         => Endpoint::model(),
				'own_network'   => Endpoint::allowLocal(),
				'url_changed'   => $result['url_changed'],
				'model_changed' => $result['model_changed'],
				'key'           => $result['key'],
			],
		] );

		self::redirect( [ self::RESULT_ARG => 'saved' ] );
	}

	public static function handleTest(): void {
		self::guard( self::ACTION_TEST );

		$result = OpenAiCompatibleProvider::testConnection();

		do_action( 'agent_brain/audit', [
			'actor_id'    => get_current_user_id(),
			'event'       => 'compression.endpoint_tested',
			'status'      => $result === true ? 'ok' : 'error',
			'object_type' => 'compression_endpoint',
			'object_id'   => null,
			'meta'        => $result === true ? [] : [ 'reason' => $result->get_error_code() ],
		] );

		self::redirect( $result === true
			? [ self::RESULT_ARG => 'tested' ]
			: [ self::RESULT_ARG => 'test_failed', self::CODE_ARG => $result->get_error_code() ] );
	}

	public static function handleRemove(): void {
		self::guard( self::ACTION_REMOVE );

		Endpoint::clearAll();

		do_action( 'agent_brain/audit', [
			'actor_id'    => get_current_user_id(),
			'event'       => 'compression.endpoint_removed',
			'status'      => 'ok',
			'object_type' => 'compression_endpoint',
			'object_id'   => null,
			'meta'        => [],
		] );

		self::redirect( [ self::RESULT_ARG => 'removed' ] );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the summarising endpoint.', 'agent-brain' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * @param array<string, string> $args
	 */
	private static function redirect( array $args ): void {
		wp_safe_redirect( SettingsHub::url( SettingsHub::TAB_CLOUD, $args ) );
		exit;
	}
}
