<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\OAuth\Bearer;
use AgentBrain\Modules\OAuth\RoleSwitching;
use WP_Error;

/**
 * B65 (2.0 vision, decision 10): a narrow login for the hooks layer.
 *
 * The hooks script runs outside the AI, on one computer, and needs its own
 * way into the brain without holding the person's rights. A person makes
 * one per computer on their own profile. It is an application password
 * marked with APP_ID, and that mark narrows it:
 *
 * - it works on the MCP endpoint only (no other REST route, no XML-RPC);
 * - it can call the TíoDay tools in ALLOWED and nothing else, for the
 *   person's own TíoDay only;
 * - its TíoDay writes run as the person's agent, for the person, as their
 *   own AI connection's do.
 *
 * The person revokes it like any application password.
 *
 * B211: `tomatio-hooks login` can wait on this computer at
 * http://127.0.0.1:<port>/ with a one-time state. Its link to the profile
 * carries both, and the page that makes the login posts the key straight
 * back to that address instead of showing it, so nobody copies or pastes
 * it. Only 127.0.0.1 is ever a target: the key never leaves the brain for
 * anywhere but the person's own computer.
 */
final class HooksLogin {

	/** Marks an application password as a hooks login (core's app_id field). */
	public const APP_ID = '6f6b8b2e-3c1d-4e65-9b0a-1d2a7e4c0b65';

	public const ACTION       = 'ab_hooks_login_create';
	public const NONCE_ACTION = 'ab_hooks_login_create';
	public const MACHINE_ARG  = 'tomatio_hooks';
	public const PORT_ARG     = 'tomatio_hooks_port';
	public const STATE_ARG    = 'tomatio_hooks_state';

	/**
	 * What a hooks login may call: read the TíoDay (tasks, approval answers,
	 * comments) and write its entries.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED = [
		AbilityNames::TIODAY_GET,
		AbilityNames::TIODAY_TASK,
		AbilityNames::TIODAY_DONE,
		AbilityNames::TIODAY_INFLIGHT,
		AbilityNames::TIODAY_REPLY,
	];

	/** The person a hooks login signed in this request (0: none). */
	private static int $person = 0;

	public static function register(): void {
		add_action( 'application_password_did_authenticate', [ self::class, 'onAuthenticated' ], 10, 2 );
		add_filter( 'rest_pre_dispatch', [ self::class, 'offRoute' ], 0, 3 );
		add_filter( 'authenticate', [ self::class, 'noXmlRpc' ], 99, 1 );
		// Priority 5: before Audit\AbilityListener (10), so the audit log records the refusal.
		add_filter( 'wp_ability_permission_result', [ self::class, 'narrow' ], 5, 3 );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handleCreate' ] );
	}

	/** Whether this request signed in with a hooks login. */
	public static function active(): bool {
		return self::$person > 0;
	}

	/**
	 * `application_password_did_authenticate`.
	 *
	 * @param mixed $user
	 * @param mixed $item
	 */
	public static function onAuthenticated( $user = null, $item = null ): void {
		if ( ! $user instanceof \WP_User || ! is_array( $item ) || (string) ( $item['app_id'] ?? '' ) !== self::APP_ID ) {
			return;
		}

		self::$person = (int) $user->ID;
		RoleSwitching::routeWrites( self::$person );
	}

	/**
	 * `rest_pre_dispatch`: a hooks login reaches the MCP endpoint only.
	 *
	 * @param mixed $result
	 * @param mixed $server
	 * @param mixed $request
	 * @return mixed
	 */
	public static function offRoute( $result, $server, $request ) {
		if ( ! self::active() || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}
		if ( Bearer::isMcpRoute( (string) $request->get_route() ) ) {
			return $result;
		}

		return new WP_Error( 'ab_hooks_login_off_route', __( 'A hooks login works on the MCP endpoint only.', 'tomatio' ), [ 'status' => 403 ] );
	}

	/**
	 * `authenticate`: no XML-RPC with a hooks login.
	 *
	 * @param mixed $user
	 * @return mixed
	 */
	public static function noXmlRpc( $user ) {
		if ( self::active() && defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return new WP_Error( 'ab_hooks_login_off_route', __( 'A hooks login works on the MCP endpoint only.', 'tomatio' ) );
		}

		return $user;
	}

	/**
	 * `wp_ability_permission_result`: a hooks login may use the TíoDay tools
	 * in ALLOWED, on its person's own TíoDay, and nothing else. It also
	 * hides every other tool from tools/list, which asks the same question.
	 *
	 * @param mixed $permission
	 * @param mixed $input
	 * @return mixed
	 */
	public static function narrow( $permission, string $abilityName, $input ) {
		if ( ! self::active() ) {
			return $permission;
		}

		if ( ! in_array( AbilityNames::canonicalFor( $abilityName ), self::ALLOWED, true ) ) {
			return new WP_Error( 'ab_hooks_login_narrow', __( 'A hooks login can only read and write its person\'s TíoDay.', 'tomatio' ), [ 'status' => 403 ] );
		}

		$asked = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;
		if ( $asked > 0 && $asked !== TioDay::forPerson( self::$person ) ) {
			return new WP_Error( 'ab_hooks_login_narrow', __( 'A hooks login can only read and write its person\'s own TíoDay.', 'tomatio' ), [ 'status' => 403 ] );
		}

		return $permission;
	}

	/**
	 * Make a hooks login for $userId on one computer. Only the person makes
	 * their own.
	 *
	 * @return array{login:string, password:string, uuid:string, name:string}|WP_Error
	 */
	public static function create( int $userId, string $machine ) {
		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User || Capabilities::isPureAgent( $userId ) ) {
			return new WP_Error( 'ab_hooks_login_person', __( 'Only a person can have a hooks login.', 'tomatio' ), [ 'status' => 403 ] );
		}
		if ( ! class_exists( '\WP_Application_Passwords' ) || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new WP_Error( 'ab_hooks_login_unavailable', __( 'Application passwords are turned off for this account, so it cannot have a hooks login.', 'tomatio' ), [ 'status' => 403 ] );
		}

		$name    = self::name( $machine );
		$created = \WP_Application_Passwords::create_new_application_password( $userId, [ 'name' => $name, 'app_id' => self::APP_ID ] );
		if ( $created instanceof WP_Error ) {
			return $created;
		}

		\AgentBrain\Core\Hooks::action( 'audit', [
			'actor_id'    => (int) get_current_user_id(),
			'event'       => 'hooks_login.create',
			'status'      => 'ok',
			'object_type' => 'application_password',
			'object_id'   => $created[1]['uuid'],
			'meta'        => [ 'user_id' => $userId, 'name' => $name ],
		] );

		return [
			'login'    => (string) $user->user_login,
			'password' => (string) $created[0],
			'uuid'     => $created[1]['uuid'],
			'name'     => $name,
		];
	}

	/** The login's name: "TomaTío hooks: <computer>". */
	public static function name( string $machine ): string {
		$machine = trim( preg_replace( '/[^\p{L}\p{N} ._-]+/u', '', $machine ) ?? '' );
		$machine = function_exists( 'mb_substr' ) ? mb_substr( $machine, 0, 60 ) : substr( $machine, 0, 60 );

		return 'TomaTío hooks: ' . ( $machine !== '' ? $machine : gmdate( 'Y-m-d' ) );
	}

	/**
	 * The person's hooks logins, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function forUser( int $userId ): array {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return [];
		}
		$items = array_values( array_filter(
			\WP_Application_Passwords::get_user_application_passwords( $userId ),
			static fn ( array $item ): bool => $item['app_id'] === self::APP_ID
		) );
		usort( $items, static fn ( array $a, array $b ): int => $b['created'] <=> $a['created'] );

		return $items;
	}

	/**
	 * The loopback target from `tomatio-hooks login`: its port and one-time
	 * state, or null when either is missing or malformed.
	 *
	 * @return array{port:int, state:string}|null
	 */
	public static function loopback( string $port, string $state ): ?array {
		if ( ! preg_match( '/^[0-9]{4,5}$/', $port ) || ! preg_match( '/^[A-Za-z0-9_-]{22,128}$/', $state ) ) {
			return null;
		}
		$number = (int) $port;
		if ( $number < 1024 || $number > 65535 ) {
			return null;
		}

		return [ 'port' => $number, 'state' => $state ];
	}

	/** Where the key goes back to: the person's own computer, never another host. */
	public static function loopbackUrl( int $port ): string {
		return 'http://127.0.0.1:' . $port . '/tomatio-hooks';
	}

	/**
	 * The create link, for $machine (from the installer's link) or none,
	 * and the loopback target when the installer is waiting.
	 *
	 * @param array{port:int, state:string}|null $loopback
	 */
	public static function createUrl( string $machine = '', ?array $loopback = null ): string {
		$args = [ 'action' => self::ACTION ];
		if ( $machine !== '' ) {
			$args['machine'] = $machine;
		}
		if ( $loopback !== null ) {
			$args['port']  = (string) $loopback['port'];
			$args['state'] = $loopback['state'];
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::NONCE_ACTION );
	}

	/**
	 * The "Hooks logins" row of the TíoDay table on the person's own profile
	 * (TioDayProfile). Never on someone else's profile: only the person
	 * makes their own.
	 */
	public static function renderRow( \WP_User $user ): void {
		if ( (int) $user->ID !== (int) get_current_user_id() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: names the computer in the link below.
		$machine = isset( $_GET[ self::MACHINE_ARG ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::MACHINE_ARG ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: checked by loopback() and carried in the link below.
		$port = isset( $_GET[ self::PORT_ARG ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::PORT_ARG ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: checked by loopback() and carried in the link below.
		$state    = isset( $_GET[ self::STATE_ARG ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::STATE_ARG ] ) ) : '';
		$loopback = self::loopback( $port, $state );
		$logins   = self::forUser( (int) $user->ID );

		echo '<tr id="tomatio-hooks"><th>' . esc_html__( 'Hooks logins', 'tomatio' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'The TomaTío hooks on a computer keep your TíoDay current from Claude Code, Codex and Cursor. Each computer has its own login, which can only read and write your TíoDay.', 'tomatio' ) . '</p>';
		if ( $logins !== [] ) {
			echo '<ul>';
			foreach ( $logins as $item ) {
				$used = ! empty( $item['last_used'] ) ? gmdate( 'Y-m-d', (int) $item['last_used'] ) : __( 'never', 'tomatio' );
				/* translators: 1: the login's name, 2: the date it was last used, or "never". */
				echo '<li>' . esc_html( sprintf( __( '%1$s (last used: %2$s)', 'tomatio' ), (string) ( $item['name'] ?? '' ), $used ) ) . '</li>';
			}
			echo '</ul>';
			echo '<p class="description">' . esc_html__( 'Revoke one under Application Passwords on this page.', 'tomatio' ) . '</p>';
		}
		$label = $machine !== ''
			/* translators: %s: the computer's name. */
			? sprintf( __( 'Make a hooks login for %s', 'tomatio' ), $machine )
			: __( 'Make a hooks login for a computer', 'tomatio' );
		if ( $loopback !== null ) {
			echo '<p class="description">' . esc_html__( 'tomatio-hooks login is waiting on this computer: the new key goes straight to it, with nothing to copy.', 'tomatio' ) . '</p>';
		}
		$class = $loopback !== null ? 'button button-primary' : 'button';
		echo '<p><a class="' . esc_attr( $class ) . '" href="' . esc_url( self::createUrl( $machine, $loopback ) ) . '">' . esc_html( $label ) . '</a></p>';
		echo '</td></tr>';
	}

	/** admin-post.php: make the login and show its key once. */
	public static function handleCreate(): void {
		check_admin_referer( self::NONCE_ACTION );
		$userId = (int) get_current_user_id();
		if ( $userId <= 0 ) {
			wp_die( esc_html__( 'Sign in first.', 'tomatio' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() above.
		$machine = isset( $_GET['machine'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['machine'] ) ) : '';
		$login   = self::create( $userId, $machine );
		if ( $login instanceof WP_Error ) {
			wp_die( esc_html( $login->get_error_message() ), 403 );
		}

		nocache_headers();
		$key = $login['login'] . ':' . $login['password'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() above.
		$port = isset( $_GET['port'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['port'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by check_admin_referer() above.
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$loopback = self::loopback( $port, $state );
		$html     = $loopback !== null ? self::handOffPage( $login['name'], $key, $loopback ) : self::keyPage( $login['name'], $key );

		wp_die( $html, esc_html__( 'Your hooks login', 'tomatio' ), [ 'response' => 200 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * B211: the page that hands the key to `tomatio-hooks login` on this
	 * computer. A form POST, so the key is never in an address or the
	 * browser's history; it sends itself, with a button if scripts are off.
	 *
	 * @param array{port:int, state:string} $loopback
	 */
	public static function handOffPage( string $name, string $key, array $loopback ): string {
		$fields = [
			'state' => $loopback['state'],
			'key'   => $key,
			'brain' => home_url( '/' ),
		];
		$inputs = '';
		foreach ( $fields as $field => $value ) {
			$inputs .= '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '" />';
		}

		return '<h1>' . esc_html__( 'Your hooks login', 'tomatio' ) . '</h1>'
			. '<p>' . esc_html( sprintf(
				/* translators: %s: the login's name. */
				__( 'Made "%s". It can only read and write your TíoDay. Sending it to tomatio-hooks on this computer…', 'tomatio' ),
				$name
			) ) . '</p>'
			. '<form id="tomatio-hooks-handoff" method="post" action="' . esc_url( self::loopbackUrl( $loopback['port'] ), [ 'http' ] ) . '">'
			. $inputs
			. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Send it to this computer', 'tomatio' ) . '</button></p>'
			. '</form>'
			. '<script>document.getElementById("tomatio-hooks-handoff").submit();</script>'
			. '<p>' . esc_html__( 'If tomatio-hooks login stopped waiting, run it again and make a new login: this one stays unused until you revoke it.', 'tomatio' ) . '</p>';
	}

	/** The page that shows the key once, to paste at the keychain's prompt. */
	public static function keyPage( string $name, string $key ): string {
		return '<h1>' . esc_html__( 'Your hooks login', 'tomatio' ) . '</h1>'
			. '<p>' . esc_html( sprintf(
				/* translators: %s: the login's name. */
				__( 'Made "%s". It can only read and write your TíoDay. This key is shown once: copy it now.', 'tomatio' ),
				$name
			) ) . '</p>'
			. '<p><input type="text" readonly style="width:100%;font-family:monospace" onfocus="this.select()" value="' . esc_attr( $key ) . '" /></p>'
			. '<p>' . esc_html__( 'On that computer, in a terminal of your own (not in an AI chat), run this and paste the key when it asks:', 'tomatio' ) . '</p>'
			. '<pre>tomatio-hooks login --paste ' . esc_html( home_url( '/' ) ) . '</pre>'
			. '<p>' . esc_html__( 'It keeps the key in the computer\'s keychain. Never paste the key into an AI chat.', 'tomatio' ) . '</p>'
			. '<p><a href="' . esc_url( admin_url( 'profile.php#tomatio-hooks' ) ) . '">' . esc_html__( 'Back to your profile', 'tomatio' ) . '</a></p>';
	}

	/** Tests only. */
	public static function reset(): void {
		self::$person = 0;
	}
}
