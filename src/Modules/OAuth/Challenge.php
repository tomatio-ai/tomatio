<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * Adds the RFC 9728 section 5.1 challenge to 401s from the MCP route:
 * `WWW-Authenticate: Bearer resource_metadata="<protected resource metadata URL>", scope="..."`,
 * with `error="invalid_token"` first when the request carried a token that
 * did not validate (PCP §3.2 item 4). MCP clients (claude.ai among them)
 * start OAuth discovery from this header; without it they have to guess
 * where the metadata lives. Only 401s on an MCP route spelling are touched
 * (agent-brain/v1/mcp canonical, tomatio/v1/mcp alias — #184, and the
 * role-switching address .../mcp-roles in both — #647), never other routes
 * or status codes.
 */
final class Challenge {

	/** @var array<int, string> */
	private const MCP_ROUTES = ['/agent-brain/v1/mcp', '/tomatio/v1/mcp', '/agent-brain/v1/mcp-roles', '/tomatio/v1/mcp-roles'];

	public static function registerHooks(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		add_filter('rest_post_dispatch', [self::class, 'addChallenge'], 10, 3);
	}

	/**
	 * @param mixed $response
	 * @param mixed $server
	 * @param mixed $request
	 * @return mixed
	 */
	public static function addChallenge($response, $server, $request) {
		if (! $response instanceof \WP_REST_Response || $response->get_status() !== 401) {
			return $response;
		}

		if (! is_object($request) || ! method_exists($request, 'get_route') || ! self::isMcpRoute((string) $request->get_route())) {
			return $response;
		}

		if (array_key_exists('WWW-Authenticate', $response->get_headers())) {
			return $response;
		}

		// The role-switching address gets resource metadata naming it, so
		// the client's authorization request says which address it is. The
		// mcp-roles route has its own metadata path, with no query (#647);
		// the older ?roles=1 address keeps the query on the metadata URL.
		$metadata = Metadata::protectedResourceMetadataUrl();
		if (RoleSwitching::isRolesRoute((string) $request->get_route())) {
			$metadata = Metadata::rolesResourceMetadataUrl();
		} elseif (method_exists($request, 'get_param') && (string) $request->get_param(RoleSwitching::QUERY) === '1') {
			$metadata = add_query_arg(RoleSwitching::QUERY, '1', $metadata);
		}

		$params = [];
		if (Bearer::rejectedTokenThisRequest()) {
			$params[] = 'error="invalid_token"';
		}
		$params[] = sprintf('resource_metadata="%s"', $metadata);
		$params[] = sprintf('scope="%s"', implode(' ', PcpDiscovery::DEFAULT_SCOPES));

		$response->header('WWW-Authenticate', 'Bearer ' . implode(', ', $params));

		return $response;
	}

	private static function isMcpRoute(string $route): bool {
		foreach (self::MCP_ROUTES as $mcpRoute) {
			if ($route === $mcpRoute || str_starts_with($route, $mcpRoute . '/')) {
				return true;
			}
		}

		return false;
	}
}
