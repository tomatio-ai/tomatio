<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * Serves RFC 8414 / RFC 9728 metadata and the PCP context document at the
 * canonical .well-known paths (which are root-level, not under /wp-json/,
 * so they can't be plain REST routes). Intercepted at template_redirect -
 * late enough that routing has finished, early enough to pre-empt the 404
 * template. Any other /.well-known/ path WordPress would 404 gets a JSON
 * 404, not the theme's HTML page (PCP §3.2 item 6): a client probing
 * /.well-known/openid-configuration must learn it does not exist.
 */
final class WellKnown {

	public const AS_PATH = '/.well-known/oauth-authorization-server';
	public const RESOURCE_PATH = '/.well-known/oauth-protected-resource';

	public static function maybeServe(): void {
		$path = self::requestPath();
		$data = self::responseForPath($path);

		if ($data !== null) {
			self::sendJson($data);
		}

		if (self::isUnknownWellKnown($path, is_404())) {
			self::sendJson(['error' => 'not_found'], 404);
		}
	}

	/**
	 * True for a /.well-known/ path nothing else handled. Paths another
	 * plugin routes (through rewrite rules or a query var) are not 404s by
	 * template_redirect, so they are left alone; the
	 * agent_brain/well_known_json_404 filter turns this off entirely.
	 */
	public static function isUnknownWellKnown(string $path, bool $is404): bool {
		if (! $is404 || ! str_starts_with($path, '/.well-known/')) {
			return false;
		}

		return (bool) apply_filters('agent_brain/well_known_json_404', true, $path);
	}

	/**
	 * Pure routing decision (no output, no exit), split out from
	 * maybeServe() so the .well-known path matching is directly testable
	 * without terminating the test process.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function responseForPath(string $path): ?array {
		if ($path === self::AS_PATH) {
			return Metadata::authorizationServer();
		}

		if ($path === PcpDiscovery::PATH) {
			return PcpDiscovery::contextDocument();
		}

		// #647: the role-switching address has its own metadata, whose
		// resource is that address with no query. Checked first: on a site
		// without pretty permalinks both MCP paths would otherwise be equal.
		if ($path === self::RESOURCE_PATH . self::rolesResourcePath()) {
			return Metadata::protectedResource(true);
		}

		if ($path === self::RESOURCE_PATH || $path === self::RESOURCE_PATH . self::resourcePath()) {
			// RFC 9728 section 3.1: clients look for the metadata of
			// https://host/wp-json/agent-brain/v1/mcp at
			// /.well-known/oauth-protected-resource/wp-json/agent-brain/v1/mcp
			// before falling back to the bare path.
			return Metadata::protectedResource();
		}

		return null;
	}

	/** The MCP endpoint's path, the suffix of its RFC 9728 well-known URL. */
	public static function resourcePath(): string {
		$path = wp_parse_url(rest_url('agent-brain/v1/mcp'), PHP_URL_PATH);

		return is_string($path) ? untrailingslashit($path) : '';
	}

	/**
	 * The role-switching address's path (.../wp-json/agent-brain/v1/mcp-roles),
	 * the suffix of its RFC 9728 well-known URL. On a site without pretty
	 * permalinks the REST URL is a query (?rest_route=), so the suffix is
	 * the pretty form, which this class serves all the same.
	 */
	public static function rolesResourcePath(): string {
		$path = wp_parse_url(RoleSwitching::rolesUrl(), PHP_URL_PATH);
		$path = is_string($path) ? untrailingslashit($path) : '';
		if (RoleSwitching::isRolesRoute($path)) {
			return $path;
		}

		return '/' . trim(rest_get_url_prefix(), '/') . '/' . \AgentBrain\Core\RestNamespaces::CANONICAL . '/' . \AgentBrain\Modules\Mcp\AdapterBridge::ROLES_ROUTE;
	}

	public static function requestPath(): string {
		$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

		$path = parse_url($uri, PHP_URL_PATH);

		return is_string($path) ? untrailingslashit($path) : '';
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function sendJson(array $data, int $status = 200): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body, not HTML; wp_json_encode() is the correct encoding here.
		echo self::prepareJsonResponse($data, $status);
		exit;
	}

	/**
	 * Sends the status and headers and returns the body. The explicit 200
	 * matters: WordPress has no route for /.well-known/* and has already set
	 * a 404 by template_redirect, and discovery clients (claude.ai among
	 * them) treat a 404 as "no metadata" whatever the body says, then fall
	 * back to POST /register.
	 *
	 * Metadata is public, so browser-based clients may read it from any
	 * origin (PCP §3.2 item 6).
	 *
	 * @param array<string, mixed> $data
	 */
	public static function prepareJsonResponse(array $data, int $status = 200): string {
		status_header($status);
		nocache_headers();
		if (! headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
			header('Access-Control-Allow-Origin: *');
		}

		return (string) wp_json_encode($data);
	}
}
