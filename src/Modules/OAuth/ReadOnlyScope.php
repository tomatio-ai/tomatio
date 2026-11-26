<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * B99 (#976): a read-only OAuth connection, for agents that only answer
 * questions. A client that asks for the `memory:read` scope, and for no
 * scope that writes, gets a connection that can search and read but never
 * change anything, whatever the person's role would allow.
 *
 *  - The mapping (grantFor()): the client's requested scope words decide.
 *    `memory:read` with no write word (`memory:propose`, `memory:write`)
 *    is read only. Anything else keeps today's connection ("mcp", plus
 *    "roles" on the role-switching path). Unknown words are dropped: they
 *    never grant anything, so they can only narrow, never widen. A client
 *    cannot ask for `roles` or `delegate`; the server builds those.
 *  - The grant is the SCOPE word on the token family, so it is carried
 *    through the code exchange and every refresh (a refresh never takes
 *    the scope from the request). Bearer reads it on every request
 *    (remember()).
 *  - The limit (guardArgs()): every TomaTío ability whose `meta.annotations`
 *    does not say `readonly` (ToolHints, #637) gets a permission check that
 *    refuses it on a read-only connection with a clear error. One check
 *    serves tools/list (which lists only tools whose check passes) and
 *    tools/call (which runs the same check), so the two cannot drift. An
 *    ability with no annotation counts as a write: fail closed.
 *  - The consent screen says it is read only and offers no delegated
 *    authority and no new agent; the profile's connections table shows it.
 */
final class ReadOnlyScope {

	/** The scope word a client asks for, and the token carries. */
	public const SCOPE = 'memory:read';

	/** Error code of the refusal a write tool gets on a read-only connection. */
	public const CODE = 'tomatio_read_only_connection';

	/** Requested words that ask for writes: with any of them the grant is the full one. */
	public const WRITE_WORDS = ['memory:propose', 'memory:write'];

	/** Words this server knows in a request; every other word is dropped. */
	public const KNOWN_WORDS = ['mcp', self::SCOPE, 'memory:propose', 'memory:write'];

	/** Whether the connection authenticating this request is read only. */
	private static bool $active = false;

	/**
	 * The scope words of $scope, split on whitespace, empty words dropped.
	 *
	 * @return string[]
	 */
	public static function words(string $scope): array {
		return array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: [], static fn (string $word): bool => $word !== ''));
	}

	/**
	 * The requested words this server knows (in the order asked), so an
	 * unknown word never reaches a grant.
	 *
	 * @return string[]
	 */
	public static function knownWords(string $requested): array {
		return array_values(array_unique(array_filter(self::words($requested), static fn (string $word): bool => in_array($word, self::KNOWN_WORDS, true))));
	}

	/**
	 * Whether a client's requested scope asks for a read-only connection:
	 * `memory:read`, and no word that writes. No scope at all, or only
	 * words this server does not know, is not a read-only request: it keeps
	 * today's connection.
	 */
	public static function requested(string $requested): bool {
		$known = self::knownWords($requested);

		return in_array(self::SCOPE, $known, true) && array_intersect($known, self::WRITE_WORDS) === [];
	}

	/**
	 * The base scope a code is issued with for $requested: "mcp memory:read"
	 * for a read-only request, else "mcp". The caller adds "roles" and
	 * "delegate" as before (never "delegate" on a read-only one).
	 */
	public static function grantFor(string $requested): string {
		return self::requested($requested) ? 'mcp ' . self::SCOPE : 'mcp';
	}

	/** True when a token's (server-built) scope string is read only. */
	public static function scopeLimits(string $scope): bool {
		return in_array(self::SCOPE, self::words($scope), true);
	}

	/**
	 * Called by Bearer for each request whose access token validated (the
	 * row is read fresh per request).
	 */
	public static function remember(string $scope): void {
		self::$active = self::scopeLimits($scope);
	}

	/** Whether this request's connection is read only. */
	public static function active(): bool {
		return self::$active;
	}

	public static function reset(): void {
		self::$active = false;
	}

	/**
	 * Whether an ability's registration args mark it read only: its
	 * `meta.annotations.readonly` is true (ToolHints::apply()).
	 *
	 * @param array<string, mixed> $args
	 */
	public static function readOnlyArgs(array $args): bool {
		$meta        = isset($args['meta']) && is_array($args['meta']) ? $args['meta'] : [];
		$annotations = isset($meta['annotations']) && is_array($meta['annotations']) ? $meta['annotations'] : [];

		return ($annotations['readonly'] ?? false) === true;
	}

	/**
	 * `wp_register_ability_args`: wraps the permission check of every
	 * TomaTío ability that is not read only, so a read-only connection is
	 * refused it (and tools/list leaves it out). Read-only abilities, other
	 * plugins' abilities and every other connection are untouched.
	 *
	 * @param mixed $args
	 * @param mixed $name
	 * @return mixed
	 */
	public static function guardArgs($args, $name) {
		if (! is_array($args) || ! is_string($name) || ! self::isOurAbility($name) || self::readOnlyArgs($args)) {
			return $args;
		}

		$inner = $args['permission_callback'] ?? null;
		if (! is_callable($inner)) {
			return $args;
		}

		$args['permission_callback'] = static function (mixed ...$input) use ($inner, $name): mixed {
			if (self::$active) {
				return self::refusal($name);
			}

			return $inner(...$input);
		};

		return $args;
	}

	/** The clear permission error a write tool gets on a read-only connection. */
	public static function refusal(string $name): \WP_Error {
		return new \WP_Error(
			self::CODE,
			sprintf(
				/* translators: %s: the tool name */
				__('This connection is read only (scope memory:read): it can search and read, but %s changes the brain, so it is refused. To let this AI save or change things, connect it again without asking for memory:read only.', 'tomatio'),
				self::toolName($name)
			),
			['status' => 403]
		);
	}

	/**
	 * How the profile's connections table shows a grant's scope: read-only
	 * grants say so in words, with the scope after it.
	 */
	public static function label(string $scope): string {
		if (! self::scopeLimits($scope)) {
			return $scope;
		}

		/* translators: %s: the OAuth scope string, for example "mcp memory:read" */
		return sprintf(__('Read only (%s)', 'tomatio'), $scope);
	}

	/** An ability name as tools/list shows it: `agent-brain/memory-write` is `tomatio-memory-write`. */
	public static function toolName(string $name): string {
		$canonical = \AgentBrain\Modules\Mcp\AbilityNames::canonicalFor($name);
		$alias     = \AgentBrain\Modules\Mcp\AbilityNames::aliasFor($canonical) ?? $canonical;

		return str_replace('/', '-', $alias);
	}

	private static function isOurAbility(string $name): bool {
		return str_starts_with($name, \AgentBrain\Modules\Mcp\AbilityNames::CANONICAL_PREFIX)
			|| str_starts_with($name, \AgentBrain\Modules\Mcp\AbilityNames::ALIAS_PREFIX);
	}
}
