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
 * The alias is kept for at least one minor release. Remove the alias
 * registrations (the second entry in all()) in the minor after the window
 * closes; flipping the canonical/alias order is the recommended way to
 * retire the old spelling afterwards.
 */
final class RestNamespaces {

	public const CANONICAL = 'agent-brain/v1';
	public const ALIAS     = 'tomatio/v1';

	/**
	 * @return array<int, string>
	 */
	public static function all(): array {
		return [self::CANONICAL, self::ALIAS];
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
