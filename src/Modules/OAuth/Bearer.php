<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Modules\AgentId\AgentIssuer;

/**
 * Bearer-token authentication for the MCP transport (ID-5, ID-6 contract).
 * Only ever authenticates requests whose path is under an MCP REST namespace
 * spelling - a valid access token presented against any other route (or the
 * front end) is inert. Wires TransportAuth::authenticatedViaOAuth() by
 * adding to the agent_brain/transport_oauth_authenticated filter, true ONLY
 * for the current request when a bearer token actually validated it here.
 *
 * During the rename alias window (#184) both `agent-brain/v1/mcp` (canonical:
 * already-issued tokens name it as their resource) and `tomatio/v1/mcp`
 * (folded alias) authenticate; the resource stays canonical either way.
 * The role-switching address (`.../mcp-roles`, #647) authenticates too. A
 * token is not bound to the address it was issued for: a role-switching
 * token works on the plain address and the other way round, as with
 * ?roles=1 (RoleSwitching decides whether the connection may switch).
 */
final class Bearer {

	/**
	 * The MCP route spellings, and the role-switching address in both
	 * namespaces (#647: agent-brain/v1/mcp-roles, re-dispatched to the
	 * canonical route by Mcp\AdapterBridge).
	 *
	 * @var array<int, string>
	 */
	private const NAMESPACE_PATHS = ['agent-brain/v1/mcp', 'tomatio/v1/mcp', 'agent-brain/v1/mcp-roles', 'tomatio/v1/mcp-roles'];

	private static bool $authenticatedThisRequest = false;

	/** A bearer token was presented on the MCP route and did not validate. */
	private static bool $rejectedThisRequest = false;

	/** The OAuth client_id whose bearer token authenticated this request (#225). */
	private static ?string $clientId = null;

	public static function registerHooks(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		// Priority 20: run after core/application-password auth so we never
		// clobber an already-authenticated user for this request.
		add_filter('determine_current_user', [self::class, 'authenticate'], 20, 1);
		add_filter('agent_brain/transport_oauth_authenticated', [self::class, 'transportAuthenticated'], 10, 1);
		// Defense in depth: whatever the URL looked like at auth time, a
		// bearer-authenticated request must actually dispatch to the MCP route.
		add_filter('rest_pre_dispatch', [self::class, 'rejectOffRouteDispatch'], 0, 3);
	}

	/**
	 * @param int|false $userId
	 * @return int|false
	 */
	public static function authenticate($userId) {
		if (! empty($userId)) {
			return $userId;
		}

		if (! self::isMcpNamespaceRequest()) {
			return $userId;
		}

		$token = self::extractBearerToken();
		if ($token === null) {
			return $userId;
		}

		$record = TokenStore::findValidAccessToken($token);
		if ($record === null) {
			// Unknown, expired or revoked: the 401 says invalid_token (Challenge).
			self::$rejectedThisRequest = true;

			return $userId;
		}

		// A suspended or revoked agent ID is refused even with a valid token.
		if (AgentIssuer::isBlocked((int) $record['user_id'])) {
			return $userId;
		}

		self::$authenticatedThisRequest = true;
		self::$clientId = (string) $record['client_id'];

		// #225: the connection was seen (throttled inside touch()); the tool
		// set itself is recorded by Mcp\AdapterBridge on tools/list.
		Connections::touch((int) $record['user_id'], self::$clientId);

		// A delegated token ("Connect this AI as" an agent) remembers the
		// person who authorized it; memory writes and the audit log stamp it.
		$authorizedBy = $record['authorized_by'];
		\AgentBrain\Core\AuthorizedBy::set($authorizedBy !== $record['user_id'] ? $authorizedBy : 0);

		// #629: whether this connection was given delegated authority at
		// consent (read from the token row on every request, so turning it
		// off on the profile takes effect at once).
		DelegatedAuthority::remember($record['scope'], self::$clientId);

		// Role switching: a person's own connection may be acting as one of
		// their agents (the act-as tool). The request stays the person's, so
		// the MCP session stays valid; RoleSwitching runs each brain tool as
		// the chosen agent.
		return RoleSwitching::resolve($record['user_id'], $record['client_id'], $record['family_id'], $record['scope']);
	}

	/** True when this request presented a bearer token that did not validate. */
	public static function rejectedTokenThisRequest(): bool {
		return self::$rejectedThisRequest;
	}

	/**
	 * The OAuth client_id whose bearer token authenticated this request, or
	 * null when the current request did not present a valid OAuth access
	 * token (no Authorization header, an application password, or wp-admin).
	 * Only ever set from the validated token record, never from input (#225).
	 */
	public static function currentClientId(): ?string {
		return self::$clientId;
	}

	public static function transportAuthenticated(bool $default): bool {
		return $default || self::$authenticatedThisRequest;
	}

	/**
	 * `rest_pre_dispatch`: refuses any REST dispatch outside the MCP
	 * namespace once a bearer token authenticated this request.
	 *
	 * @param mixed $result
	 * @param mixed $server
	 * @param mixed $request
	 * @return mixed
	 */
	public static function rejectOffRouteDispatch($result, $server, $request) {
		if (! self::$authenticatedThisRequest || ! is_object($request) || ! method_exists($request, 'get_route')) {
			return $result;
		}

		if (self::pathMatchesMcpNamespace((string) $request->get_route())) {
			return $result;
		}

		return new \WP_Error('ab_bearer_off_route', 'OAuth access tokens are only valid on the MCP endpoint.', ['status' => 401]);
	}

	/**
	 * Matches the request against an MCP REST namespace spelling
	 * (`agent-brain/v1/mcp` canonical, `tomatio/v1/mcp` alias), as either the
	 * pretty-permalink path (/wp-json/agent-brain/v1/mcp...) or the
	 * ?rest_route= query-var form (/agent-brain/v1/mcp...). A query string
	 * that merely contains the namespace text (e.g. ?evil=agent-brain/v1/mcp)
	 * must never match.
	 *
	 * A `rest_route` request var (GET or POST) overrides the pretty-permalink
	 * path in WP::parse_request(), so when one is present it alone decides:
	 * /wp-json/agent-brain/v1/mcp?rest_route=/wp/v2/users dispatches to
	 * /wp/v2/users and must not authenticate.
	 */
	public static function isMcpNamespaceRequest(): bool {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only route detection, not a state-changing action; no nonce applies.
		foreach ([$_GET, $_POST] as $vars) {
			if (! array_key_exists('rest_route', $vars)) {
				continue;
			}

			if (! is_string($vars['rest_route'])) {
				return false;
			}

			return self::pathMatchesMcpNamespace('/' . ltrim($vars['rest_route'], '/'));
		}
		// phpcs:enable

		$path = self::requestPath();

		return $path !== null && self::pathMatchesMcpNamespace($path);
	}

	private static function requestPath(): ?string {
		if (! isset($_SERVER['REQUEST_URI'])) {
			return null;
		}

		$uri  = (string) $_SERVER['REQUEST_URI'];
		$path = function_exists('wp_parse_url') ? wp_parse_url($uri, PHP_URL_PATH) : parse_url($uri, PHP_URL_PATH);

		if (! is_string($path) || $path === '') {
			return null;
		}

		return rawurldecode($path);
	}

	/**
	 * True when $path is under ANY MCP namespace spelling: either the direct
	 * route form (what WP_REST_Request::get_route() returns) or the
	 * pretty-permalink form (/wp-json/<namespace>/mcp).
	 */
	private static function pathMatchesMcpNamespace(string $path): bool {
		$prefix = function_exists('rest_get_url_prefix') ? trim(rest_get_url_prefix(), '/') : 'wp-json';

		foreach (self::NAMESPACE_PATHS as $namespacePath) {
			if (self::pathHasMcpNamespaceSegment($path, '/' . $namespacePath)) {
				return true;
			}

			if (self::pathHasMcpNamespaceSegment($path, '/' . $prefix . '/' . $namespacePath)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Requires an exact path-segment match, not a substring anywhere in the
	 * path (so "/foo/agent-brain/v1/mcpxyz" does not match, but
	 * "/wp-json/agent-brain/v1/mcp" and "/wp-json/agent-brain/v1/mcp/execute" do).
	 */
	private static function pathHasMcpNamespaceSegment(string $path, ?string $needle = null): bool {
		if ($needle === null) {
			$prefix = function_exists('rest_get_url_prefix') ? trim(rest_get_url_prefix(), '/') : 'wp-json';
			$needle = '/' . $prefix . '/' . self::NAMESPACE_PATHS[0];
		}

		$pos = strpos($path, $needle);
		if ($pos === false) {
			return false;
		}

		$after = substr($path, $pos + strlen($needle));

		return $after === '' || $after[0] === '/';
	}

	private static function extractBearerToken(): ?string {
		$header = self::authorizationHeader();

		if ($header === '' || stripos($header, 'Bearer ') !== 0) {
			return null;
		}

		$token = trim(substr($header, 7));

		return $token === '' ? null : $token;
	}

	private static function authorizationHeader(): string {
		if (! empty($_SERVER['HTTP_AUTHORIZATION'])) {
			return (string) $_SERVER['HTTP_AUTHORIZATION'];
		}

		if (! empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
			return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}

		if (function_exists('getallheaders')) {
			foreach (getallheaders() as $name => $value) {
				if (strtolower($name) === 'authorization') {
					return (string) $value;
				}
			}
		}

		return '';
	}

	/**
	 * Reset per-request static state. Only needed in tests, where multiple
	 * "requests" run in the same PHP process.
	 */
	public static function resetForTests(): void {
		\AgentBrain\Core\AuthorizedBy::reset();
		RoleSwitching::reset();
		DelegatedAuthority::reset();
		self::$authenticatedThisRequest = false;
		self::$rejectedThisRequest      = false;
		self::$clientId                 = null;
	}
}
