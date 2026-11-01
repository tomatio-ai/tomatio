<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

use AgentBrain\Core\ActionContext;
use AgentBrain\Core\ClientContext;
use AgentBrain\Core\RestNamespaces;
use AgentBrain\Modules\OAuth\Bearer;
use AgentBrain\Modules\OAuth\Connections;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\ErrorLogMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

/**
 * THE ONLY FILE in this codebase allowed to reference `WP\MCP\*`
 * (wordpress/mcp-adapter, pinned exactly to 0.6.1) classes. Every other
 * module talks to MCP exclusively through the static methods here, so an
 * adapter upgrade or replacement is a one-file change plus
 * tests/integration/Mcp/AdapterBridgeContractTest.php.
 *
 * Responsibilities:
 *  - Boot the adapter singleton and register our one custom server on the
 *    `mcp_adapter_init` hook, at route namespace `agent-brain/v1`, route
 *    `mcp` (⇒ REST URL `/wp-json/agent-brain/v1/mcp`), listing exactly the
 *    abilities in AbilityNames::ALL as tools (plus their `tomatio/` rename
 *    aliases during the #184 alias window, via AbilityNames::allWithAliases()).
 *    The alias namespace `tomatio/v1` is mirrored by registerAliasRoute().
 *  - Supply the transport-level permission callback (TransportGuard::check)
 *    so every request to that route is refused unless
 *    Core\Health::isDegraded() is false AND
 *    Identity\TransportAuth::check() passes (app password or OAuth; never
 *    cookie/nonce).
 *  - Filter `mcp_adapter_tools_list` (adapter public hook, since 0.5.0) so
 *    discovery (tools/list) for OUR server only ever lists abilities the
 *    requesting user may actually execute — never all of them unconditionally
 *    — and lists each tool once, under its `tomatio-` spelling (#637; the
 *    `agent-brain-` spelling stays callable, see AbilityNames::isListedToolName()).
 *
 * Transport session rule (pinned in docs/mcp-transport-session-rule.md and
 * tests/integration/Mcp/TransportSessionTest.php, #251): the HTTP transport
 * requires a valid `Mcp-Session-Id` on EVERY JSON-RPC request except
 * `initialize`, for read-only tools and mutations alike — there is no
 * tool-dependent split. The rule is enforced entirely inside the pinned
 * adapter (HttpRequestHandler::process_jsonrpc_request →
 * HttpSessionValidator); this class contributes the per-request observability
 * log line (see registerServer()) that makes any future refusal diagnosable.
 */
final class AdapterBridge {

	public const SERVER_ID              = 'agent-brain-mcp';
	public const SERVER_ROUTE_NAMESPACE = 'agent-brain/v1';
	public const SERVER_ROUTE           = 'mcp';

	/**
	 * The role-switching connector address (#647): agent-brain/v1/mcp-roles
	 * and tomatio/v1/mcp-roles, the same server as SERVER_ROUTE
	 * (registerRolesRoutes()). OAuth\RoleSwitching treats it as the roles
	 * resource.
	 */
	public const ROLES_ROUTE = 'mcp-roles';
	public const SERVER_NAME            = 'TomaTío MCP Server';
	public const SERVER_VERSION         = 'v1';

	/**
	 * Called once from Mcp\Module::register() on every request. Registering
	 * the same WordPress action callback twice is a documented no-op in
	 * WordPress core (add_action() dedupes identical callbacks), so this is
	 * safe to call unconditionally.
	 */
	public static function registerHooks(): void {
		if (! class_exists(McpAdapter::class)) {
			// wordpress/mcp-adapter did not load (composer dependency
			// missing/broken). Nothing to wire; ModuleRegistry/Health
			// already surfaces a degraded state for a module whose
			// register() throws, but this path deliberately does NOT throw
			// — a missing adapter must not fatal an unrelated request, it
			// must simply mean no MCP server exists this request.
			return;
		}

		if (function_exists('add_filter')) {
			// The PRD calls for exactly ONE custom MCP server (route
			// `agent-brain/v1/mcp`) — the adapter's own generic default
			// server (mcp-adapter/discover-abilities etc., at route `mcp`)
			// is not part of that contract and is turned off here so it
			// never exists as a second, unaccounted-for surface.
			add_filter('mcp_adapter_create_default_server', '__return_false');
		}

		// Boots the adapter singleton (idempotent: instance() short-circuits
		// on repeat calls) so its own `rest_api_init`/`init` wiring runs and
		// `mcp_adapter_init` fires at all.
		McpAdapter::instance();

		if (function_exists('add_action')) {
			add_action('mcp_adapter_init', [self::class, 'registerServer']);
			// #184 rename alias: tomatio/v1/mcp mirrors the canonical server
			// route for at least one minor release.
			add_action('rest_api_init', [self::class, 'registerAliasRoute']);
			// #647: the role-switching address, a path with no query.
			add_action('rest_api_init', [self::class, 'registerRolesRoutes']);
		}

		if (function_exists('add_filter')) {
			add_filter('mcp_adapter_tools_list', [self::class, 'filterToolsForCurrentUser'], 10, 2);
			add_filter('mcp_adapter_tool_call_result', [self::class, 'explainInvalidInput'], 10, 4);
		}

		// M1 (the design contracts "Fix wave — mixed Kimi +
		// Claude team test", Unit M): capture Core\ClientContext from the
		// `initialize` request's clientInfo, and restore it (from the
		// adapter's own session store, never a client-supplied value on a
		// later request) for every subsequent request over the same
		// Mcp-Session-Id. Plain WordPress REST hooks, not `WP\MCP\*` — the
		// adapter itself exposes no filter carrying both the raw request
		// params and the negotiated session id, so this reads the same
		// transport-level request/response our own REST route already
		// handles rather than reaching into adapter internals.
		if (function_exists('add_filter')) {
			add_filter('rest_request_before_callbacks', [self::class, 'restoreClientContext'], 10, 3);

			// Priority MUST run after wordpress/mcp-adapter's own
			// `rest_post_dispatch` filter (HttpRequestHandler::
			// add_session_header_to_response(), registered by the adapter
			// at the DEFAULT priority 10 during dispatch — i.e. later than
			// this class's own boot-time registration, so registering at
			// priority 10 here would run FIRST and see no Mcp-Session-Id
			// header yet, silently persisting nothing). A late priority
			// (PHP_INT_MAX - 10, leaving headroom for anything that
			// legitimately wants to run last) guarantees the adapter has
			// already written the header by the time this reads it.
			// captureClientContext() itself still tolerates the header
			// being absent (e.g. a non-MCP-adapter transport): it simply
			// never persists a session, it does not guess one.
			add_filter('rest_post_dispatch', [self::class, 'captureClientContext'], PHP_INT_MAX - 10, 3);
		}
	}

	/**
	 * `rest_request_before_callbacks`: for our own route only, restores any
	 * previously captured client (keyed by the incoming Mcp-Session-Id
	 * header) into Core\ClientContext before the request reaches
	 * tools/call's execute_callback.
	 *
	 * @param mixed                                            $response
	 * @param array<string, mixed>                              $handler
	 * @param \WP_REST_Request                                  $request
	 * @return mixed
	 */
	public static function restoreClientContext($response, $handler, $request) {
		if (! self::isOwnRoute($request)) {
			return $response;
		}

		$sessionId = self::sessionIdFromRequest($request);
		if ($sessionId !== null) {
			ClientContext::restoreFromSession($sessionId);
			// PROV-6 (#175): same handshake, same session key — the read set
			// this session accumulated follows it across requests so a later
			// tools/call write can attach what the session has read.
			ActionContext::restoreFromSession($sessionId);
		}

		return $response;
	}

	/**
	 * `rest_post_dispatch`: for our own route only, on an `initialize`
	 * request, records the client from `clientInfo` into Core\ClientContext
	 * — persisted under the session id the adapter just minted for this
	 * request (available only now, via the `Mcp-Session-Id` response
	 * header; it does not exist yet when the request comes in). Every other
	 * method on this route leaves ClientContext exactly as
	 * restoreClientContext() set it.
	 *
	 * @param mixed              $response
	 * @param mixed              $server
	 * @param \WP_REST_Request   $request
	 * @return mixed
	 */
	public static function captureClientContext($response, $server, $request) {
		if (! self::isOwnRoute($request) || ! is_object($response) || ! method_exists($response, 'get_headers')) {
			return $response;
		}

		$body = self::jsonBody($request);
		if (($body['method'] ?? null) !== 'initialize') {
			return $response;
		}

		$params     = is_array($body['params'] ?? null) ? $body['params'] : [];
		$clientInfo = is_array($params['clientInfo'] ?? null) ? $params['clientInfo'] : [];
		$name       = isset($clientInfo['name']) && is_string($clientInfo['name']) ? $clientInfo['name'] : null;
		$version    = isset($clientInfo['version']) && is_string($clientInfo['version']) ? $clientInfo['version'] : null;

		$headers   = $response->get_headers();
		$sessionId = is_array($headers) && isset($headers['Mcp-Session-Id']) && is_string($headers['Mcp-Session-Id'])
			? $headers['Mcp-Session-Id']
			: null;

		ClientContext::set($name, $version, $sessionId);

		// PROV-6 (#175): a fresh session starts with an empty read set; it
		// must never inherit what a previous session on this transport saw.
		if ($sessionId !== null) {
			ActionContext::resetSession($sessionId);
		}

		return $response;
	}

	private static function isOwnRoute(\WP_REST_Request $request): bool {
		if (! is_object($request) || ! method_exists($request, 'get_route')) {
			return false;
		}

		$route = $request->get_route();

		// Both namespace spellings are this server (#184 alias window):
		// /agent-brain/v1/mcp and /tomatio/v1/mcp, and the role-switching
		// address in both (#647).
		return in_array(
			$route,
			[
				'/' . RestNamespaces::CANONICAL . '/' . self::SERVER_ROUTE,
				'/' . RestNamespaces::ALIAS . '/' . self::SERVER_ROUTE,
				'/' . RestNamespaces::CANONICAL . '/' . self::ROLES_ROUTE,
				'/' . RestNamespaces::ALIAS . '/' . self::ROLES_ROUTE,
			],
			true
		);
	}

	private static function sessionIdFromRequest(\WP_REST_Request $request): ?string {
		if (! method_exists($request, 'get_header')) {
			return null;
		}

		$value = $request->get_header('Mcp-Session-Id');

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function jsonBody(\WP_REST_Request $request): array {
		// get_json_params() returns null for a bodyless or non-JSON request
		// (MCP GET/DELETE, an empty POST); that used to fatal the endpoint.
		// Core's docblock claims array, so widen it for phpstan.
		/** @var mixed $params */
		$params = $request->get_json_params();

		return is_array($params) ? $params : [];
	}

	/**
	 * Registered on `mcp_adapter_init` (the only action during which
	 * McpAdapter::create_server() is accepted).
	 */
	public static function registerServer(): void {
		$adapter = McpAdapter::instance();

		if ($adapter->get_server(self::SERVER_ID) !== null) {
			// Already registered (e.g. `mcp_adapter_init` fired more than
			// once in the same process, which happens routinely across a
			// test run since McpAdapter's server list is a process-lifetime
			// singleton): re-calling create_server() for the same id would
			// trigger the adapter's own _doing_it_wrong() and a WP_Error,
			// for no benefit — the existing registration is already correct.
			return;
		}

		$result = $adapter->create_server(
			self::SERVER_ID,
			self::SERVER_ROUTE_NAMESPACE,
			self::SERVER_ROUTE,
			self::SERVER_NAME,
			__('TomaTío memory, knowledge, handoff, and artifact abilities exposed over MCP.', 'agent-brain'),
			self::SERVER_VERSION,
			[HttpTransport::class],
			ErrorLogMcpErrorHandler::class,
			// #251: was null (NullMcpObservabilityHandler — every request
			// silently unrecorded). The adapter's RequestRouter emits one
			// `mcp.request` event per routed JSON-RPC message carrying
			// method, session id, status and error code, and
			// ErrorLogMcpObservabilityHandler sanitizes its tags
			// (scalar-sanitized params only, sensitive key patterns
			// redacted, values capped at 1024 chars) before writing a
			// single `[MCP Observability] EVENT mcp.request ...` line to
			// the PHP error log. That line is the audit trail that makes
			// an intermittent transport refusal (e.g. a -32601 whose
			// method string must be read off the wire) diagnosable after
			// the fact instead of unreproducible.
			ErrorLogMcpObservabilityHandler::class,
			AbilityNames::allWithAliases(),
			[],
			[],
			[TransportGuard::class, 'check']
		);

		if (function_exists('is_wp_error') && is_wp_error($result) && function_exists('error_log')) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- fail-loud (L26.2): a server that could not be created must not fail silently.
			error_log('[agent-brain] MCP server registration failed: ' . $result->get_error_message());
		}
	}

	/**
	 * The rename alias route (#184): `tomatio/v1/mcp` answers exactly like
	 * the canonical `agent-brain/v1/mcp` while the alias window is open
	 * (kept for at least one minor release). There is still exactly ONE
	 * custom MCP server — the alias is a thin REST shim that re-dispatches
	 * the request, byte-for-byte (method, query/body params, body, headers,
	 * so the Mcp-Session-Id header rides along), to the canonical route and
	 * returns its response. Both spellings run the same TransportGuard
	 * permission check, so the permission layer is untouched.
	 */
	public static function registerAliasRoute(): void {
		register_rest_route(
			RestNamespaces::ALIAS,
			'/' . self::SERVER_ROUTE,
			[
				'methods'             => ['POST', 'GET', 'DELETE'],
				'callback'            => [self::class, 'handleAliasRequest'],
				'permission_callback' => [TransportGuard::class, 'check'],
			]
		);
	}

	/**
	 * The role-switching connector address (#647): `agent-brain/v1/mcp-roles`
	 * and `tomatio/v1/mcp-roles`. Re-dispatched to the canonical route the
	 * same way as the alias, so there is still one MCP server, one transport
	 * guard and one session store. What makes it the roles address is
	 * outside the server: OAuth\Challenge points its 401 at resource
	 * metadata naming it, consent from it grants the `roles` scope, and
	 * OAuth\RoleSwitching lets a person's connection on it switch.
	 */
	public static function registerRolesRoutes(): void {
		foreach (RestNamespaces::all() as $namespace) {
			register_rest_route(
				$namespace,
				'/' . self::ROLES_ROUTE,
				[
					'methods'             => ['POST', 'GET', 'DELETE'],
					'callback'            => [self::class, 'handleAliasRequest'],
					'permission_callback' => [TransportGuard::class, 'check'],
				]
			);
		}
	}

	/**
	 * Re-dispatches an alias or role-switching request to the canonical route.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handleAliasRequest(\WP_REST_Request $request) {
		$canonical = new \WP_REST_Request(
			$request->get_method(),
			'/' . RestNamespaces::CANONICAL . '/' . self::SERVER_ROUTE
		);
		$canonical->set_query_params($request->get_query_params());
		$canonical->set_body_params($request->get_body_params());
		$canonical->set_file_params($request->get_file_params());
		$canonical->set_body($request->get_body());
		foreach ($request->get_headers() as $name => $value) {
			$canonical->set_header($name, $value);
		}

		return rest_do_request($canonical);
	}

	/**
	 * `mcp_adapter_tools_list` filter (adapter 0.5.0+): scopes discovery to
	 * only the tools the CURRENT user may execute, and only for our own
	 * server (a second/third-party server's tool list is left untouched).
	 *
	 * #225: this filter fires exactly when a client (re)lists tools — the
	 * moment its cached snapshot is refreshed — so it also records the
	 * connection's tool-set hash (effective + full surface, with the plugin
	 * version) via OAuth\Connections. A client that never re-lists keeps its
	 * old hash and later gets the out-of-date notice in its briefing.
	 *
	 * `$tools` here are protocol-only `Tool` DTOs (McpServer::get_tools()'s
	 * return type); the ability-backed permission check lives on the
	 * internal `McpTool` wrapper (`McpServer::get_mcp_tool($name)`), so each
	 * DTO is resolved back to its wrapper and `check_permission([])` is
	 * called — the same check the adapter itself runs at execution time, so
	 * this can never drift from what tools/call would actually allow. A
	 * tool that can't be resolved, or whose check throws or returns
	 * anything other than `true`, is excluded — fail closed.
	 *
	 * @param array<int|string, \WP\McpSchema\Server\Tools\DTO\Tool> $tools
	 * @param \WP\MCP\Core\McpServer                                 $server
	 * @return array<int, \WP\McpSchema\Server\Tools\DTO\Tool>
	 */
	public static function filterToolsForCurrentUser(array $tools, $server): array {
		if (! self::isOwnServer($server)) {
			return $tools;
		}

		$permitted = self::permittedTools($tools, $server);

		$userId   = (int) get_current_user_id();
		$clientId = Bearer::currentClientId();
		if ($userId > 0 && $clientId !== null) {
			Connections::recordToolSet($userId, $clientId, self::toolNames($permitted), self::toolNames($tools), self::pluginVersion());
		}

		return $permitted;
	}

	/**
	 * The same permission filtering as filterToolsForCurrentUser(), without
	 * the #225 recording — used by the current-hash helpers below so a
	 * staleness check never mutates the recorded snapshot.
	 *
	 * @param array<int|string, \WP\McpSchema\Server\Tools\DTO\Tool> $tools
	 * @param mixed                                                  $server Our own McpServer (runtime-checked).
	 * @return array<int, \WP\McpSchema\Server\Tools\DTO\Tool>
	 */
	private static function permittedTools(array $tools, $server): array {
		return array_values(array_filter($tools, static function ($toolDto) use ($server): bool {
			if (! is_object($toolDto) || ! method_exists($toolDto, 'getName') || ! method_exists($server, 'get_mcp_tool')) {
				return false;
			}

			if (! AbilityNames::isListedToolName((string) $toolDto->getName())) {
				return false;
			}

			$mcpTool = $server->get_mcp_tool($toolDto->getName());
			if ($mcpTool === null) {
				return false;
			}

			try {
				$result = $mcpTool->check_permission([]);
			} catch (\Throwable $e) {
				return false;
			}

			return self::isListed($result);
		}));
	}

	/**
	 * Hash of the tool list the CURRENT user would get from a fresh
	 * `tools/list` right now, or null when our MCP server is not registered
	 * in this process (e.g. outside a REST request). Feeds the #225
	 * out-of-date notice; never records anything.
	 */
	public static function currentEffectiveToolHash(): ?string {
		$server = self::currentServer();
		if ($server === null) {
			return null;
		}

		$names = self::toolNames(self::permittedTools($server->get_tools(), $server));

		return $names === [] ? null : Connections::hashFor($names);
	}

	/**
	 * Hash of the full, unfiltered tool surface right now (independent of
	 * the caller's permissions), or null when our MCP server is not
	 * registered in this process. Compared against the surface hash recorded
	 * at the connection's last `tools/list` — the deploy-changed-the-tools
	 * half of the #225 out-of-date check, usable in wp-admin where running
	 * per-user permission callbacks is not practical.
	 */
	public static function currentSurfaceHash(): ?string {
		$server = self::currentServer();
		if ($server === null) {
			return null;
		}

		$names = self::toolNames($server->get_tools());

		return $names === [] ? null : Connections::hashFor($names);
	}

	/**
	 * Our MCP server when the adapter registered it in this process, else
	 * null (outside a REST request, or a context where `mcp_adapter_init`
	 * never fired).
	 */
	private static function currentServer(): ?McpServer {
		if (! class_exists(McpAdapter::class)) {
			return null;
		}

		$server = McpAdapter::instance()->get_server(self::SERVER_ID);
		if (! $server instanceof McpServer) {
			return null;
		}

		return $server;
	}

	/**
	 * @param array<int|string, mixed> $tools
	 * @return array<int, string>
	 */
	private static function toolNames(array $tools): array {
		$names = [];
		foreach ($tools as $tool) {
			if (is_object($tool) && method_exists($tool, 'getName')) {
				$names[] = (string) $tool->getName();
			}
		}

		return $names;
	}

	private static function pluginVersion(): string {
		return defined('AGENT_BRAIN_VERSION') ? (string) AGENT_BRAIN_VERSION : '0';
	}

	/**
	 * @param mixed $server
	 */
	private static function isOwnServer($server): bool {
		return is_object($server) && method_exists($server, 'get_server_id') && $server->get_server_id() === self::SERVER_ID;
	}

	/**
	 * Whether a permission-check result keeps a tool in tools/list: allowed,
	 * or an explained curators-only denial (artifact-create for a
	 * non-curator stays listed so the agent can read why, instead of the
	 * tool vanishing). Shared with the Agent View screen so the two agree.
	 *
	 * @param mixed $result
	 */
	public static function isListed($result): bool {
		if (is_object($result) && method_exists($result, 'get_error_code') && $result->get_error_code() === Abilities::CODE_CURATORS_ONLY) {
			return true;
		}

		return $result === true;
	}

	/**
	 * `mcp_adapter_tool_call_result`: replaces WordPress's bare schema
	 * validation message with one that names the tool's parameters.
	 *
	 * Contract (verified against wordpress/mcp-adapter 0.6.1, the pinned
	 * version; minimum 0.6.1): ToolsHandler::call_tool() applies
	 * apply_filters('mcp_adapter_tool_call_result', $result, $args,
	 * $tool_name, $mcp_tool, $server) after McpTool::execute(), and
	 * McpTool::get_observability_context() carries 'ability_name'. The
	 * WP_Error code 'ability_invalid_input' comes from WP core's
	 * WP_Ability::execute(). Pinned by ScopeDiscoveryTest through the real
	 * ToolsHandler::call_tool() path.
	 *
	 * @param mixed $result
	 * @param mixed $args
	 * @param mixed $toolName
	 * @param mixed $tool
	 * @return mixed
	 */
	public static function explainInvalidInput($result, $args = null, $toolName = '', $tool = null) {
		if (! is_wp_error($result) || $result->get_error_code() !== 'ability_invalid_input' || ! function_exists('wp_get_ability')) {
			return $result;
		}

		$context     = is_object($tool) && method_exists($tool, 'get_observability_context') ? $tool->get_observability_context() : [];
		$abilityName = is_array($context) && isset($context['ability_name']) ? (string) $context['ability_name'] : '';
		$ability     = $abilityName !== '' ? wp_get_ability($abilityName) : null;
		if ($ability === null) {
			return $result;
		}

		return new \WP_Error(
			'ability_invalid_input',
			InputErrors::message($abilityName, (array) $ability->get_input_schema(), $args, $result->get_error_message()),
			$result->get_error_data()
		);
	}

	/**
	 * Whether a permission-check result is the explained curators-only denial.
	 *
	 * @param mixed $result
	 */
	public static function isCuratorsOnlyDenial($result): bool {
		return is_object($result) && method_exists($result, 'get_error_code') && $result->get_error_code() === Abilities::CODE_CURATORS_ONLY;
	}
}
