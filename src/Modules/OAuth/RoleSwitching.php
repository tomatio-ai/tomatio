<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Core\AuthorizedBy;
use AgentBrain\Core\RestNamespaces;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Mcp\AdapterBridge;
use WP_User;

/**
 * Role switching: one connection, the agent chosen per task.
 *
 * A person connects an AI client once, as themselves, on the role-switching
 * address (rolesUrl(): .../agent-brain/v1/mcp-roles; the older
 * .../mcp?roles=1 still works), and the client calls the `act-as` tool when the work changes
 * ("switching to the coder"). Until it switches again, every brain tool on
 * that connection runs as the chosen agent, attributed "via" the person.
 *
 * How (both learned from claude.ai): the choice is kept per connection (the
 * OAuth token family), not per MCP session, because clients open new MCP
 * sessions freely (about one per message). And the request itself stays the
 * person's: the MCP adapter binds a session to the user who opened it, so
 * changing the request's user made the next call fail with "session not
 * found". Instead each agent-brain ability except act-as runs as the agent
 * (switched on wp_ability_invoked, before the audit listener records the
 * actor) and the person is restored afterwards.
 *
 * Boundaries:
 *  - Only a person's own OAuth connection can switch, never an agent's.
 *  - Only into agents Delegation allows for that person, and only agents
 *    whose read and write scopes are all within the person's own, so a
 *    connection can never reach more than the person could already. This
 *    keeps scopes meaningful as a blast-radius limit: an agent with scopes
 *    the person does not hold is not switchable.
 *  - The choice is per connection and expires after TTL without use.
 *  - Every switch is audited (`agent.switch`, actor = the person).
 *
 * Allowed by default (see enabled()). A brain whose agents' scopes are
 * deliberately separate can turn it off, so no single conversation can move
 * between them.
 *
 * People write through an agent (docs/rfc/people-write-through-agents.md).
 * Over MCP every write is typed by an AI model, so a person's connection
 * never writes as the person: the writes in ROUTED_WRITES run as the
 * person's agent, attributed "via" the person, the same way a role-switching
 * connection does. Which agent: the person's chosen default
 * (DEFAULT_AGENT_META, set on their profile), or else the only agent they
 * may switch into. With neither, the write is refused with a message naming
 * their agents. Applies to a person's own OAuth connection on either
 * address, while no agent is chosen with act-as.
 */
final class RoleSwitching {

	public const OPTION           = 'agent_brain_role_switching';

	/** OAuth scope that marks a role-switching connection (granted at consent). */
	public const SCOPE = 'roles';

	/** Query argument of the older role-switching connector address (?roles=1). */
	public const QUERY = 'roles';
	public const TRANSIENT_PREFIX = 'ab_act_as_';
	public const TTL              = 30 * DAY_IN_SECONDS;

	/** User meta: the agent a person's writes go to when no role is chosen. */
	public const DEFAULT_AGENT_META = 'ab_default_agent';

	/** The agent-only writes: run as the person's agent, never as the person. */
	public const ROUTED_WRITES = [
		\AgentBrain\Modules\Mcp\AbilityNames::MEMORY_WRITE,
		\AgentBrain\Modules\Mcp\AbilityNames::HANDOFF_REQUEST_REVIEW,
		\AgentBrain\Modules\Mcp\AbilityNames::SECTION_CLAIM,
		\AgentBrain\Modules\Mcp\AbilityNames::SECTION_RELEASE,
		\AgentBrain\Modules\Mcp\AbilityNames::SECTION_UPDATE,
		\AgentBrain\Modules\Mcp\AbilityNames::SECTION_SUBMIT,
		// #654: TíoDay entries are an AI's work too.
		\AgentBrain\Modules\Mcp\AbilityNames::TIODAY_TASK,
		\AgentBrain\Modules\Mcp\AbilityNames::TIODAY_DONE,
		\AgentBrain\Modules\Mcp\AbilityNames::TIODAY_INFLIGHT,
		\AgentBrain\Modules\Mcp\AbilityNames::TIODAY_DECIDED,
		\AgentBrain\Modules\Mcp\AbilityNames::TIODAY_REPLY,
	];

	/** The person whose connection this request is (0: not a switchable connection). */
	private static int $person = 0;

	/** Transient key for this connection's chosen agent. */
	private static string $connection = '';

	/** The agent this connection acts as for brain tools (0: the person). */
	private static int $active = 0;

	/** The person on this connection whose writes are routed (0: none). */
	private static int $writer = 0;

	/** The agent those writes run as (0: no agent found; the write is refused). */
	private static int $writeAgent = 0;

	/** True while a routed write runs as the agent. */
	private static bool $routing = false;

	public static function registerHooks(): void {
		if (! function_exists('add_action')) {
			return;
		}

		// Priority 1: before Audit\AbilityListener (10) records the actor.
		add_action('wp_ability_invoked', [self::class, 'enterRole'], 1, 1);
		add_action('wp_after_execute_ability', [self::class, 'leaveRole'], 99, 0);
		add_filter('rest_post_dispatch', static function ($response) {
			self::leaveRole();

			return $response;
		}, 99);
	}

	/**
	 * Runs a brain ability as the connection's chosen agent. act-as itself
	 * always runs as the person, who is the one choosing. Both spellings of
	 * an ability (`agent-brain/` and its `tomatio/` rename alias) are the
	 * same tool here (#371).
	 */
	public static function enterRole(string $abilityName): void {
		$abilityName = \AgentBrain\Modules\Mcp\AbilityNames::canonicalFor($abilityName);
		if (! str_starts_with($abilityName, \AgentBrain\Modules\Mcp\AbilityNames::CANONICAL_PREFIX) || $abilityName === \AgentBrain\Modules\Mcp\AbilityNames::ACT_AS) {
			return;
		}

		if (self::$active > 0) {
			wp_set_current_user(self::$active);

			return;
		}

		// No role chosen: a person's agent-only write runs as their agent.
		if (self::$writeAgent > 0 && in_array($abilityName, self::ROUTED_WRITES, true)) {
			self::$routing = true;
			wp_set_current_user(self::$writeAgent);
			AuthorizedBy::set(self::$writer);
		}
	}

	/** Back to the person after an ability (and at the end of the request). */
	public static function leaveRole(): void {
		if (self::$person > 0 && get_current_user_id() !== self::$person) {
			wp_set_current_user(self::$person);
		}

		if (self::$routing) {
			self::$routing = false;
			wp_set_current_user(self::$writer);
			AuthorizedBy::reset();
		}
	}

	/**
	 * Whether this brain allows role-switching connections at all. On by
	 * default: a role-switching connection is opted into per connection (its
	 * own address) and can never reach beyond the person's own scopes, so
	 * allowing it adds no access; brains with deliberately separate agent
	 * scopes can still turn it off.
	 */
	public static function enabled(): bool {
		// Stored as '1' / '0' (see OnboardingSettings::handleSave()); absent means on.
		return (bool) apply_filters('agent_brain/role_switching', (bool) get_option(self::OPTION, '1'));
	}

	/**
	 * The role-switching connector address: its own MCP route, mcp-roles,
	 * with no query (#647). claude.ai drops the query from the resource it
	 * sends at consent (RFC 8707 says a resource indicator SHOULD NOT carry
	 * one), so the older ?roles=1 form connected with the agent picker.
	 * The plain endpoint connects as one account (the consent picker).
	 */
	public static function rolesUrl(): string {
		return rest_url(RestNamespaces::CANONICAL . '/' . AdapterBridge::ROLES_ROUTE);
	}

	/** The older role-switching address, the MCP endpoint with ?roles=1. Still works. */
	public static function legacyRolesUrl(): string {
		return add_query_arg(self::QUERY, '1', rest_url('agent-brain/v1/mcp'));
	}

	/**
	 * True when $resource (an OAuth resource indicator) is a role-switching
	 * address: the mcp-roles route (either namespace spelling), or the MCP
	 * endpoint with ?roles=1.
	 */
	public static function isRolesResource(string $resource): bool {
		if ($resource === '') {
			return false;
		}

		$query = (string) wp_parse_url($resource, PHP_URL_QUERY);
		parse_str($query, $args);

		if (($args[self::QUERY] ?? '') === '1'
			&& wp_parse_url($resource, PHP_URL_PATH) === wp_parse_url(rest_url('agent-brain/v1/mcp'), PHP_URL_PATH)) {
			return true;
		}

		foreach (RestNamespaces::all() as $namespace) {
			if (self::sameAddress($resource, rest_url($namespace . '/' . AdapterBridge::ROLES_ROUTE))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Same host, path and REST route (the rest_route query argument, on
	 * sites without pretty permalinks). Scheme and a trailing slash are
	 * ignored.
	 */
	private static function sameAddress(string $resource, string $address): bool {
		$a = wp_parse_url($resource);
		$b = wp_parse_url($address);
		if (! is_array($a) || ! is_array($b)) {
			return false;
		}

		if (strtolower((string) ($a['host'] ?? '')) !== strtolower((string) ($b['host'] ?? ''))
			|| (int) ($a['port'] ?? 0) !== (int) ($b['port'] ?? 0)
			|| untrailingslashit((string) ($a['path'] ?? '')) !== untrailingslashit((string) ($b['path'] ?? ''))) {
			return false;
		}

		parse_str((string) ($a['query'] ?? ''), $queryA);
		parse_str((string) ($b['query'] ?? ''), $queryB);

		return untrailingslashit((string) ($queryA['rest_route'] ?? '')) === untrailingslashit((string) ($queryB['rest_route'] ?? ''));
	}

	/**
	 * True when the current request came in on a role-switching address:
	 * the mcp-roles route, or ?roles=1. Read from the raw request (the
	 * bearer token is checked before REST routing).
	 */
	public static function requestIsRoles(): bool {
		return self::requestHasRolesQuery() || self::requestIsRolesRoute();
	}

	/** True when the current request carries ?roles=1 (the older address). */
	public static function requestHasRolesQuery(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a routing flag, not a state change.
		return isset($_GET[self::QUERY]) && sanitize_text_field(wp_unslash((string) $_GET[self::QUERY])) === '1';
	}

	/** True when the current request is for the mcp-roles route, in either namespace spelling. */
	public static function requestIsRolesRoute(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only route detection, not a state change.
		// Like Bearer: a rest_route query argument overrides the path.
		if (isset($_GET['rest_route'])) {
			$route = is_string($_GET['rest_route']) ? (string) wp_unslash($_GET['rest_route']) : '';
		} else {
			$uri   = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
			$route = rawurldecode((string) wp_parse_url($uri, PHP_URL_PATH));
		}
		// phpcs:enable

		return self::isRolesRoute($route);
	}

	/**
	 * True when $route (a REST route, /agent-brain/v1/mcp-roles, or a URL
	 * path ending in one) is the mcp-roles route in either namespace spelling.
	 */
	public static function isRolesRoute(string $route): bool {
		$route = untrailingslashit($route);
		foreach (RestNamespaces::all() as $namespace) {
			if (str_ends_with($route, '/' . $namespace . '/' . AdapterBridge::ROLES_ROUTE)) {
				return true;
			}
		}

		return false;
	}

	/** True when an OAuth scope string grants role switching. */
	public static function scopeAllows(string $scope): bool {
		return in_array(self::SCOPE, preg_split('/\s+/', trim($scope)) ?: [], true);
	}

	/**
	 * Agents $personId may switch into, by display name.
	 *
	 * @return WP_User[]
	 */
	public static function agentsFor(int $personId): array {
		$scopes = new Scopes();
		$read   = $scopes->readScopeIds($personId);
		$write  = $scopes->writeScopeIds($personId);

		return array_values(array_filter(
			Delegation::agentsFor($personId),
			static fn (WP_User $agent): bool => array_diff($scopes->readScopeIds((int) $agent->ID), $read) === []
				&& array_diff($scopes->writeScopeIds((int) $agent->ID), $write) === []
		));
	}

	public static function canSwitch(int $personId, int $agentId): bool {
		foreach (self::agentsFor($personId) as $agent) {
			if ((int) $agent->ID === $agentId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Called by Bearer once a token is valid. For a person's own
	 * role-switching connection, remembers the connection and the agent it
	 * chose (setting AuthorizedBy to the person while one is chosen). Always
	 * returns the token's own user: the request stays the person's.
	 */
	public static function resolve(int $tokenUserId, string $clientId, string $familyId, string $scope = 'mcp'): int {
		self::reset();

		if (! self::enabled()) {
			return $tokenUserId;
		}

		$user = get_userdata($tokenUserId);
		if (! $user instanceof WP_User || Delegation::isPureAgent($user)) {
			return $tokenUserId;
		}

		// A role-switching connection: granted at consent from the roles
		// address (scope "mcp roles"), or this request uses that address.
		if (self::scopeAllows($scope) || self::requestIsRoles()) {
			self::rememberConnection($tokenUserId, $clientId, $familyId);
		}

		// Until an agent is chosen, the person's writes go to their agent.
		if (self::$active <= 0) {
			self::$writer     = $tokenUserId;
			self::$writeAgent = self::defaultAgentFor($tokenUserId);
		}

		return $tokenUserId;
	}

	/**
	 * The agent a person's writes go to when no role is chosen: their default
	 * (if they may still switch into it), else their only switchable agent,
	 * else 0.
	 */
	public static function defaultAgentFor(int $personId): int {
		$agents = self::agentsFor($personId);
		$chosen = (int) get_user_meta($personId, self::DEFAULT_AGENT_META, true);

		foreach ($agents as $agent) {
			if ((int) $agent->ID === $chosen) {
				return $chosen;
			}
		}

		return count($agents) === 1 ? (int) $agents[0]->ID : 0;
	}

	/** The person whose writes this connection routes (0: none). */
	public static function writer(): int {
		return self::$writer;
	}

	/** The agent those writes go to (0: none found). */
	public static function writeAgent(): int {
		return self::$writeAgent;
	}

	private static function rememberConnection(int $tokenUserId, string $clientId, string $familyId): void {
		self::$person     = $tokenUserId;
		self::$connection = self::TRANSIENT_PREFIX . hash('sha256', $tokenUserId . '|' . $clientId . '|' . $familyId);

		$agentId = (int) get_transient(self::$connection);
		if ($agentId > 0 && self::canSwitch($tokenUserId, $agentId)) {
			self::$active = $agentId;
			AuthorizedBy::set($tokenUserId);
			set_transient(self::$connection, $agentId, self::TTL); // Sliding expiry while in use.
		}
	}

	/** The person behind this connection, when it can switch roles. */
	public static function person(): int {
		return self::$person;
	}

	/**
	 * Switch this conversation to $agentId (0: back to the person). Takes
	 * effect from the next request.
	 */
	public static function switchTo(int $agentId): bool {
		if (self::$person <= 0 || self::$connection === '') {
			return false;
		}

		if ($agentId === 0 || $agentId === self::$person) {
			delete_transient(self::$connection);
			self::$active = 0;
			AuthorizedBy::reset();
		} elseif (self::canSwitch(self::$person, $agentId)) {
			set_transient(self::$connection, $agentId, self::TTL);
			self::$active = $agentId;
			AuthorizedBy::set(self::$person);
		} else {
			return false;
		}

		do_action('agent_brain/audit', [
			'actor_id'    => self::$person,
			'event'       => 'agent.switch',
			'status'      => 'ok',
			'object_type' => 'user',
			'object_id'   => (string) ($agentId > 0 ? $agentId : self::$person),
			'meta'        => ['to' => $agentId > 0 ? $agentId : self::$person],
		]);

		return true;
	}

	/** The agent this connection currently acts as (0: the person). */
	public static function current(): int {
		return self::$active;
	}

	public static function reset(): void {
		self::$person     = 0;
		self::$connection = '';
		self::$active     = 0;
		self::$writer     = 0;
		self::$writeAgent = 0;
		self::$routing    = false;
	}
}
