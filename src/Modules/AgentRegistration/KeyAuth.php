<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentRegistration;

use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\OAuth\Bearer;

/**
 * Transport authentication by registration key (RFC section 6.2). The key
 * authenticates no real user: it resolves to a capability-less gate account (the
 * MCP adapter needs a user for its sessions) and satisfies the transport gate (through the
 * agent_brain/transport_oauth_authenticated filter, like OAuth bearer tokens)
 * and is accepted only on the MCP route, for agent-register and agent-whoami.
 * Every other ability checks a real user and so refuses; rest_pre_dispatch
 * additionally rejects any other route and any other tool call.
 */
final class KeyAuth {

	private const OPTION_GATE_USER = 'ab_registration_gate_user';

	/** @var array<string, mixed>|null */
	private static ?array $record = null;

	private static bool $resolved = false;

	public static function registerHooks(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		// Priority 30: after core application passwords and OAuth bearer (20), so a
		// real credential always wins and the key never replaces a user.
		add_filter( 'determine_current_user', [ self::class, 'authenticate' ], 30, 1 );
		add_filter( 'agent_brain/transport_oauth_authenticated', [ self::class, 'transportAuthenticated' ], 10, 1 );
		add_filter( 'rest_pre_dispatch', [ self::class, 'restrictDispatch' ], 0, 3 );
	}

	/**
	 * The MCP adapter ties every session to a WordPress user, so a valid key
	 * resolves to the gate user: an account with no role and no capability,
	 * which therefore passes no ability's permission check except the two
	 * registration tools (which test for the key itself).
	 *
	 * @param int|false $userId
	 * @return int|false
	 */
	public static function authenticate( $userId ) {
		if ( ! empty( $userId ) || self::record() === null ) {
			return $userId;
		}

		return self::gateUserId();
	}

	private static function gateUserId(): int {
		$id = (int) get_option( self::OPTION_GATE_USER, 0 );
		if ( $id > 0 && get_userdata( $id ) instanceof \WP_User ) {
			return $id;
		}

		AgentIssuer::deferAutoIssue( true );
		try {
			$created = wp_insert_user( [
				'user_login'   => 'ab-registration-gate',
				'user_pass'    => wp_generate_password( 64, true, true ),
				'display_name' => 'TomaTío registration gate',
				'role'         => '',
			] );
		} finally {
			AgentIssuer::deferAutoIssue( false );
		}

		if ( $created instanceof \WP_Error ) {
			$existing = get_user_by( 'login', 'ab-registration-gate' );
			$id       = $existing instanceof \WP_User ? (int) $existing->ID : 0;
		} else {
			$id = (int) $created;
		}

		if ( $id > 0 ) {
			update_option( self::OPTION_GATE_USER, $id, false );
		}

		return $id;
	}

	public static function transportAuthenticated( bool $default ): bool {
		return $default || self::record() !== null;
	}

	/**
	 * The valid, pending registration the current request presents, or null.
	 * Resolved once per request; every failed presentation counts toward the
	 * per-key lockout.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function record(): ?array {
		if ( self::$resolved ) {
			return self::$record;
		}
		self::$resolved = true;

		if ( ! Bearer::isMcpNamespaceRequest() ) {
			return null;
		}

		$key = self::extractKey();
		if ( $key === null ) {
			return null;
		}

		// Bounded guess limit across keys, by client bucket (fixed transient keys).
		if ( RegistrationStore::isUnknownLocked() ) {
			return null;
		}

		$hash = RegistrationKey::hash( $key );
		if ( RegistrationStore::isLocked( $hash ) ) {
			return null;
		}

		$row = RegistrationStore::findByKey( $key );
		if ( $row === null ) {
			// Unknown key: never creates a row per key, only counts in a fixed bucket.
			RegistrationStore::recordUnknownFailure();

			return null;
		}

		// Spent, revoked and expired keys deliberately fall through to the same
		// generic 401 as unknown keys: telling an anonymous caller which keys
		// once existed would let them probe for valid registrations. Only the
		// person, on the Agents page, sees why a key no longer works. The
		// attempt IS audited though (#236): a second use of a key must leave a
		// row, attributed to the anonymous presenter (actor 0) and the
		// hashed client address, like the share-link access-attempt events.
		if ( $row['status'] !== RegistrationStore::STATUS_PENDING
			|| RegistrationStore::isExpired( $row ) ) {
			RegistrationStore::recordFailure( $hash );
			self::auditKeyReuse( $row );

			return null;
		}

		self::$record = $row;

		return self::$record;
	}

	public static function active(): bool {
		return self::record() !== null;
	}

	/**
	 * Audit a known-but-unusable key being presented again (#236, RFC section
	 * 6.1). The presenter is anonymous, so the actor is 0 — the same
	 * convention as the share-link access-attempt events — and the client
	 * address is stored only as a sha256, never raw.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function auditKeyReuse( array $row ): void {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never output.

		do_action( 'agent_brain/audit', [
			'actor_id'    => 0,
			'event'       => 'agent.register_key_reused',
			'status'      => 'denied',
			'object_type' => 'registration',
			'object_id'   => (string) $row['id'],
			'meta'        => [
				'key_status' => RegistrationStore::displayStatus( $row ),
				'client'     => $ip !== '' ? hash( 'sha256', $ip ) : null,
			],
		] );
	}

	/**
	 * `rest_pre_dispatch`: once a registration key authenticated this
	 * request, only the MCP route and only the two registration tools.
	 *
	 * @param mixed $result
	 * @param mixed $server
	 * @param mixed $request
	 * @return mixed
	 */
	public static function restrictDispatch( $result, $server, $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || ! self::active() ) {
			return $result;
		}

		$route = (string) $request->get_route();
		if ( $route !== '/agent-brain/v1/mcp' ) {
			return new \WP_Error( 'ab_registration_key_off_route', 'A registration key is only valid on the MCP endpoint.', [ 'status' => 401 ] );
		}

		if ( method_exists( $request, 'get_method' ) && $request->get_method() !== 'POST' ) {
			return $result;
		}

		$body = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;
		if ( ! is_array( $body ) || $body === [] ) {
			return new \WP_Error( 'ab_registration_key_scope', 'A registration key can only call agent-register and agent-whoami.', [ 'status' => 403 ] );
		}

		$messages = array_keys( $body ) === range( 0, count( $body ) - 1 ) ? $body : [ $body ];
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! self::messageAllowed( $message ) ) {
				return new \WP_Error( 'ab_registration_key_scope', 'A registration key can only call agent-register and agent-whoami.', [ 'status' => 403 ] );
			}
		}

		return $result;
	}

	/**
	 * @param array<mixed> $message
	 */
	public static function messageAllowed( array $message ): bool {
		$method = $message['method'] ?? null;
		if ( ! is_string( $method ) ) {
			return false;
		}

		if ( $method === 'initialize' || $method === 'ping' || $method === 'tools/list' || strncmp( $method, 'notifications/', 14 ) === 0 ) {
			return true;
		}

		if ( $method !== 'tools/call' ) {
			return false;
		}

		$params = $message['params'] ?? null;
		$name   = is_array( $params ) ? ( $params['name'] ?? null ) : null;

		return is_string( $name ) && in_array( $name, self::allowedToolNames(), true );
	}

	/**
	 * Tool names as the adapter exposes them (ability name with "/" -> "-"),
	 * plus the ability names themselves, in both spellings: tools/list shows
	 * the `tomatio-` one (#637) and the `agent-brain-` one still works.
	 *
	 * @return array<int, string>
	 */
	private static function allowedToolNames(): array {
		$names = [];
		foreach ( AbilityNames::REGISTRATION as $ability ) {
			foreach ( [ $ability, AbilityNames::aliasFor( $ability ) ] as $spelling ) {
				if ( $spelling !== null ) {
					$names[] = $spelling;
					$names[] = str_replace( '/', '-', $spelling );
				}
			}
		}

		return $names;
	}

	/**
	 * Authorization: Bearer header only. A query parameter is deliberately not
	 * accepted: URLs end up in server, proxy and CDN access logs.
	 */
	private static function extractKey(): ?string {
		$header = self::authorizationHeader();
		if ( $header !== '' && stripos( $header, 'Bearer ' ) === 0 ) {
			$token = trim( substr( $header, 7 ) );

			return RegistrationKey::looksLikeKey( $token ) ? $token : null;
		}

		return null;
	}

	private static function authorizationHeader(): string {
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $name ) {
			if ( ! empty( $_SERVER[ $name ] ) ) {
				return (string) $_SERVER[ $name ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared and hashed, never output.
			}
		}

		return '';
	}

	/** Per-request static state; tests run several "requests" in one process. */
	public static function resetForTests(): void {
		self::$record   = null;
		self::$resolved = false;
	}
}
