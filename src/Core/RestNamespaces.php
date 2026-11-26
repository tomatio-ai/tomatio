<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * The plugin's REST namespaces during the rename transition (#184).
 *
 * `agent-brain/v1` stays canonical: already-issued OAuth tokens name
 * `.../agent-brain/v1/mcp` as their resource, and the endpoints advertised
 * in the OAuth server metadata keep pointing at it, so existing clients
 * never need to move. Every route is ALSO registered under the folded
 * alias `tomatio/v1`, so clients that discover the site fresh may use
 * either spelling. Both namespaces are the same routes — identical
 * callbacks and permission_callbacks — so the permission layer is
 * untouched and no capability, option or grant changes.
 *
 * Since #833 the URLs the plugin hands out (the MCP endpoint on the Connect
 * page and in discovery, the OAuth endpoints, the protected-resource
 * metadata) use ADVERTISED, the TomaTío spelling. Routes are still
 * registered under CANONICAL first, and both spellings serve the same
 * routes, so a client connected to either URL keeps working. Tokens do not
 * store their resource, so one token works on both.
 */
final class RestNamespaces {

	public const CANONICAL = 'agent-brain/v1';
	public const ALIAS     = 'tomatio/v1';

	/** The namespace in every URL the plugin hands out (#833). */
	public const ADVERTISED = self::ALIAS;

	/**
	 * @return array<int, string>
	 */
	public static function all(): array {
		return [self::CANONICAL, self::ALIAS];
	}

	/**
	 * The namespace a REST route (`/tomatio/v1/mcp`, `/agent-brain/v1/...`)
	 * belongs to, or ADVERTISED when it is neither.
	 */
	public static function of(string $route): string {
		$route = '/' . ltrim($route, '/');
		foreach (self::all() as $namespace) {
			if ($route === '/' . $namespace || str_starts_with($route, '/' . $namespace . '/')) {
				return $namespace;
			}
		}

		return self::ADVERTISED;
	}

	/** $namespace when it is one of ours, else ADVERTISED. */
	public static function known(string $namespace): string {
		return in_array($namespace, self::all(), true) ? $namespace : self::ADVERTISED;
	}

	/** The MCP endpoint's URL under $namespace (default the advertised one). */
	public static function mcpUrl(string $namespace = self::ADVERTISED): string {
		return rest_url(self::known($namespace) . '/mcp');
	}

	/**
	 * Register one route under every namespace alias, canonical first, with
	 * the exact same args for each spelling.
	 *
	 * @param string                  $route The route path, as for register_rest_route().
	 * @param array<array-key, mixed> $args  The route args, as for register_rest_route(). May be a
	 *                                       list of method-definition arrays (multi-method routes).
	 */
	public static function register(string $route, array $args): void {
		foreach (self::all() as $namespace) {
			register_rest_route($namespace, $route, $args);
		}
	}
}
