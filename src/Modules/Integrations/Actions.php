<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

use AgentBrain\Core\SecretBox;
use AgentBrain\Core\SecretBoxException;
use AgentBrain\Modules\Admin\SettingsHub;

/**
 * #990: connect, test and disconnect from Settings > Integrations.
 * Curators only (ab_manage, which the curator role has). A new key is
 * stored only when the test succeeds. The key is never put in a
 * redirect, a notice or the audit log.
 */
final class Actions {

	public const ACTION = 'ab_integration';
	public const OAUTH  = 'ab_integration_oauth';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_post_' . self::OAUTH, [ self::class, 'oauth' ] );
	}

	public static function redirectUri(): string {
		return admin_url( 'admin-post.php?action=' . self::OAUTH );
	}

	public static function handle(): void {
		self::guard();
		check_admin_referer( self::ACTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$post    = wp_unslash( $_POST );
		$service = self::service( is_string( $post['service'] ?? null ) ? $post['service'] : '' );
		if ( $service === null ) {
			self::finish( '', false, __( 'That integration is not installed.', 'tomatio' ) );
		}
		$id = (string) $service['id'];
		$do = is_string( $post['do'] ?? null ) ? $post['do'] : '';

		if ( $do === 'disconnect' ) {
			self::disconnect( $id, $service );
		}
		if ( $do === 'app' ) {
			self::saveApp( $id, $post );
		}
		if ( $do === 'oauth' ) {
			self::startOAuth( $id, $service );
		}
		if ( $do === 'test' ) {
			self::testStored( $id, $service );
		}
		self::saveToken( $id, $service, is_string( $post['token'] ?? null ) ? $post['token'] : '' );
	}

	public static function oauth(): void {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the provider cannot send our nonce; the one-time state is the check.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$held  = $state !== '' ? get_transient( self::stateKey( $state ) ) : false;
		delete_transient( self::stateKey( $state ) );
		if ( ! is_array( $held ) || (int) ( $held['user'] ?? 0 ) !== (int) get_current_user_id() ) {
			self::finish( '', false, __( 'That connection request expired. Start it again from Settings.', 'tomatio' ) );
		}
		$id      = is_string( $held['service'] ?? null ) ? $held['service'] : '';
		$service = self::service( $id );
		if ( $service === null ) {
			self::finish( '', false, __( 'That integration is not installed.', 'tomatio' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- provider callback, checked via state above.
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( (string) $_GET['error'] ) ) : '';
		if ( $error !== '' ) {
			self::finish( $id, false, sprintf(
				/* translators: %s: service name, such as Linear. */
				__( '%s was not connected.', 'tomatio' ),
				(string) $service['name']
			) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- provider callback, checked via state above.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		if ( preg_match( '/^[\x21-\x7E]{8,512}$/', $code ) !== 1 ) {
			self::finish( $id, false, sprintf(
				/* translators: %s: service name, such as Linear. */
				__( '%s did not send a code back. Start the connection again.', 'tomatio' ),
				(string) $service['name']
			) );
		}
		$app = Store::app( $id, Store::row( $id ) );
		if ( $app['id'] === '' || $app['secret'] === '' ) {
			self::finish( $id, false, sprintf(
				/* translators: %s: service name, such as Linear. */
				__( '%s has no app set up, so sign-in cannot finish. Paste a key instead, or add the app.', 'tomatio' ),
				(string) $service['name']
			) );
		}
		$request = Exchange::tokenRequest( $service, $code, $app['id'], $app['secret'], self::redirectUri() );
		$sent    = Client::send( $request['method'], $request['url'], $request['headers'], $request['body'] );
		$access  = Exchange::accessToken( $sent['body'] );
		if ( $access === '' ) {
			self::finish( $id, false, sprintf(
				/* translators: %s: service name, such as Linear. */
				__( '%s did not give a key back. Try again, or paste a key.', 'tomatio' ),
				(string) $service['name']
			) );
		}
		self::storeAndTest( $id, $service, $access, 'oauth', Exchange::refreshToken( $sent['body'] ), Exchange::expiresIn( $sent['body'], time() ) );
	}

	/**
	 * @param array<string, mixed> $service
	 */
	private static function saveToken( string $id, array $service, string $token ): void {
		$token   = KeyPaste::clean( $token );
		$problem = KeyPaste::problem(
			$token,
			(string) $service['name'],
			is_array( $service['token_prefixes'] ?? null ) ? array_values( array_filter( $service['token_prefixes'], 'is_string' ) ) : []
		);
		if ( $problem !== '' ) {
			self::finish( $id, false, $problem );
		}
		self::storeAndTest( $id, $service, $token, 'token', '', 0 );
	}

	/**
	 * @param array<string, mixed> $service
	 */
	private static function storeAndTest( string $id, array $service, string $token, string $auth, string $refresh, int $expires ): void {
		$now  = time();
		$row  = Store::row( $id );
		$wait = Limiter::wait( $row, $service, $now );
		if ( $wait > 0 ) {
			self::finish( $id, false, self::slow( (string) $service['name'], $wait ) );
		}
		$result = self::call( $service, $token );
		if ( ! $result['ok'] ) {
			self::finish( $id, false, $result['words'] );
		}
		try {
			$material = SecretBox::currentMaterial();
			$next     = $auth === 'oauth'
				? Vault::sealOAuth( $row, $token, $refresh, $expires, $material, (int) get_current_user_id() )
				: Vault::sealToken( $row, $token, $material, (int) get_current_user_id() );
		} catch ( SecretBoxException ) {
			self::finish( $id, false, __( 'The key could not be stored. Check the site secret key.', 'tomatio' ) );
		}
		$next = Limiter::countRead( self::applyResult( $next, $result, $now ), $now );
		Store::save( $id, $next );
		self::audit( 'integration.connect', $id, (string) $auth );
		Notice::sync( $id, (string) $service['name'], false, '', (int) get_current_user_id() );
		Planning\Choices::forget( $id );
		$auto = '';
		try {
			$auto = Planning\Choices::autoSetup( $id, (int) get_current_user_id() );
		} catch ( \Throwable ) {
			$auto = ''; // the connection stands; the card offers the setup.
		}
		self::finish( $id, true, trim( $result['words'] . ' ' . $auto ) );
	}

	/**
	 * @param array<string, mixed> $service
	 */
	private static function testStored( string $id, array $service ): void {
		$token = Store::bearer( $id );
		if ( $token === null || $token === '' ) {
			self::finish( $id, false, sprintf(
				/* translators: %s: service name, such as Linear. */
				__( 'Connect %s first.', 'tomatio' ),
				(string) $service['name']
			) );
		}
		$row  = Store::row( $id );
		$now  = time();
		$wait = Limiter::wait( $row, $service, $now );
		if ( $wait > 0 ) {
			self::finish( $id, false, self::slow( (string) $service['name'], $wait ) );
		}
		$result = self::call( $service, $token );
		$next   = self::applyResult( Limiter::countRead( $row, $now ), $result, $now );
		if ( $result['limited'] ) {
			$next = Limiter::limit( $next, $service, $now, $result['retry_after'] );
		}
		Store::save( $id, $next );
		Notice::sync( $id, (string) $service['name'], (bool) $next['failing'], (string) $next['message'], (int) ( $row['connected_by'] ?? 0 ) ?: (int) get_current_user_id() );
		self::finish( $id, $result['ok'], $result['words'] );
	}

	/**
	 * @param array<string, mixed> $service
	 */
	private static function disconnect( string $id, array $service ): void {
		$row  = Store::row( $id );
		$user = (int) ( $row['connected_by'] ?? 0 ) ?: (int) get_current_user_id();
		Store::save( $id, Vault::clearCredential( $row ) );
		self::audit( 'integration.disconnect', $id, '' );
		Planning\Choices::forget( $id );
		Notice::sync( $id, (string) $service['name'], false, '', $user );
		self::finish( $id, true, sprintf(
			/* translators: %s: service name, such as Linear. */
			__( '%s is disconnected. The stored key is gone.', 'tomatio' ),
			(string) $service['name']
		) );
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function saveApp( string $id, array $post ): void {
		$client = is_string( $post['client_id'] ?? null ) ? trim( $post['client_id'] ) : '';
		$secret = is_string( $post['client_secret'] ?? null ) ? trim( $post['client_secret'] ) : '';
		if ( preg_match( '/^[\x21-\x7E]{4,200}$/', $client ) !== 1 ) {
			self::finish( $id, false, __( 'The app id does not look right.', 'tomatio' ) );
		}
		if ( $secret !== '' && preg_match( '/^[\x21-\x7E]{8,4096}$/', $secret ) !== 1 ) {
			self::finish( $id, false, __( 'The app secret does not look right.', 'tomatio' ) );
		}
		$row = Store::row( $id );
		if ( $secret === '' && (string) $row['app_secret'] === '' ) {
			self::finish( $id, false, __( 'Paste the app secret. It is stored encrypted and never shown again.', 'tomatio' ) );
		}
		try {
			$next = Vault::sealApp( $row, $client, $secret, SecretBox::currentMaterial(), $secret === '' );
		} catch ( SecretBoxException ) {
			self::finish( $id, false, __( 'The app secret could not be stored. Check the site secret key.', 'tomatio' ) );
		}
		Store::save( $id, $next );
		self::audit( 'integration.app', $id, '' );
		self::finish( $id, true, __( 'The app is saved. Connect when you are ready. The secret is not shown again.', 'tomatio' ) );
	}

	/**
	 * @param array<string, mixed> $service
	 */
	private static function startOAuth( string $id, array $service ): void {
		if ( ! is_array( $service['oauth'] ) ) {
			self::finish( $id, false, sprintf(
				/* translators: %s: service name, such as Linear. */
				__( '%s has no sign-in. Paste a key instead.', 'tomatio' ),
				(string) $service['name']
			) );
		}
		$app = Store::app( $id, Store::row( $id ) );
		if ( $app['id'] === '' || $app['secret'] === '' ) {
			self::finish( $id, false, sprintf(
				/* translators: %s: service name, such as Linear. */
				__( 'Add the %s app first (client id and secret), or paste a key.', 'tomatio' ),
				(string) $service['name']
			) );
		}
		try {
			$state = bin2hex( random_bytes( 16 ) );
		} catch ( \Exception ) {
			self::finish( $id, false, __( 'Could not start the connection. Try again.', 'tomatio' ) );
		}
		set_transient( self::stateKey( $state ), [
			'user'    => (int) get_current_user_id(),
			'service' => $id,
		], 10 * MINUTE_IN_SECONDS );
		$url  = Exchange::authorizeUrl( $service, $app['id'], self::redirectUri(), $state );
		$host = (string) parse_url( $url, PHP_URL_HOST );
		add_filter( 'allowed_redirect_hosts', static function ( $hosts ) use ( $host ) {
			$hosts   = is_array( $hosts ) ? $hosts : [];
			$hosts[] = $host;

			return $hosts;
		} );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @param array<string, mixed> $service
	 * @return array{ok: bool, words: string, account: string, limited: bool, retry_after: int}
	 */
	private static function call( array $service, string $token ): array {
		$request = Probe::request( $service, $token );
		$sent    = Client::send( $request['method'], $request['url'], $request['headers'], $request['body'] );

		return Probe::words( $service, $sent['status'], $sent['body'], $sent['headers'], $token );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array{ok: bool, words: string, account: string, limited: bool, retry_after: int} $result
	 * @return array<string, mixed>
	 */
	private static function applyResult( array $row, array $result, int $now ): array {
		$row['last_test'] = $now;
		$row['message']   = $result['words'];
		$row['failing']   = ! $result['ok'];
		if ( $result['ok'] ) {
			$row['account'] = $result['account'];
			$row            = Limiter::clear( $row );
		}

		return $row;
	}

	/** @return array<string, mixed>|null */
	private static function service( string $id ): ?array {
		return ( new Catalog() )->get( sanitize_key( $id ) );
	}

	private static function guard(): void {
		if ( ! current_user_can( SettingsHub::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage TomaTío settings.', 'tomatio' ), 403 );
		}
	}

	private static function audit( string $event, string $id, string $auth ): void {
		$event = [
			'actor_id'    => (int) get_current_user_id(),
			'event'       => $event,
			'status'      => 'ok',
			'object_type' => 'integration',
			'object_id'   => $id,
		];
		if ( $auth !== '' ) {
			$event['meta'] = [ 'auth' => $auth ];
		}
		\AgentBrain\Core\Hooks::action( 'audit', $event );
	}

	private static function slow( string $name, int $wait ): string {
		return sprintf(
			/* translators: 1: service name, 2: seconds to wait. */
			__( '%1$s asked us to slow down. Try again in %2$d seconds.', 'tomatio' ),
			$name,
			$wait
		);
	}

	private static function finish( string $id, bool $ok, string $text ): never {
		set_transient( self::noticeKey(), [ 'ok' => $ok, 'text' => $text ], 5 * MINUTE_IN_SECONDS );
		$args = [ 'ab_integration' => '1' ];
		$url  = SettingsHub::url( SettingsHub::TAB_INTEGRATIONS, $args );
		if ( $id !== '' ) {
			$url .= '#integration-' . rawurlencode( $id );
		}
		wp_safe_redirect( $url );
		exit;
	}

	private static function noticeKey(): string {
		return 'ab_int_notice_' . (int) get_current_user_id();
	}

	private static function stateKey( string $state ): string {
		return 'ab_io_' . $state;
	}
}
