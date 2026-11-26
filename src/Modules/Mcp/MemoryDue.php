<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

/**
 * #1192: the brain asks for a memory-write when a connection has changed
 * things for a while without saving any.
 *
 * Every MCP tool answer passes through `mcp_adapter_tool_call_result`. Each
 * successful call to a tool that changes something (ToolHints kind write or
 * destructive) counts as one change for this connection; a successful
 * memory-write sets the count back to zero. Once a connection has made
 * THRESHOLD changes, or MIN_CHANGES changes over MAX_MINUTES, every answer
 * it gets carries `memory_due`: one line asking it to save the why now.
 * It stops as soon as the connection writes a memory.
 *
 * This is the product's own version of the Stop hook a person can install
 * for Claude Code: it reaches every app (claude.ai, ChatGPT, Cursor, cloud
 * agents), because the brain sees the work, not the app. Instructions alone
 * ("save as you go") were not enough: a 24-26 Nov 2026 session made 129
 * turns of changes and saved nothing.
 *
 * A connection is the MCP session when the client keeps one, else the
 * OAuth connection or application password, else the client name; always
 * with the user. Counts live in a transient for a day.
 */
final class MemoryDue {

	/** Changes without a memory-write before answers ask for one. */
	public const THRESHOLD = 8;

	/** Fewer changes ask too, once this many minutes have passed since the first. */
	public const MAX_MINUTES = 40;

	/** The fewest changes the time rule asks for. */
	public const MIN_CHANGES = 3;

	/** Filter: the THRESHOLD to use; 0 turns the nudge off. */
	public const FILTER_THRESHOLD = 'tomatio/memory_due_threshold';

	/** The output key the line goes under. */
	public const KEY = 'memory_due';

	private const TRANSIENT_PREFIX = 'ab_memdue_';

	/**
	 * Tools that change something but are not work worth a memory: choosing
	 * who to act as, registering, and the steps that prepare a memory-write.
	 */
	private const NOT_WORK = [
		AbilityNames::ACT_AS,
		AbilityNames::AGENT_REGISTER,
		AbilityNames::SOURCE_REGISTER,
	];

	public static function register(): void {
		if (function_exists('add_filter')) {
			add_filter('mcp_adapter_tool_call_result', [self::class, 'filterResult'], 20, 4);
		}
	}

	/**
	 * `mcp_adapter_tool_call_result`.
	 *
	 * @param mixed $result
	 * @param mixed $args
	 * @param mixed $toolName
	 * @param mixed $tool
	 * @return mixed
	 */
	public static function filterResult($result, $args = null, $toolName = '', $tool = null) {
		$context = is_object($tool) && method_exists($tool, 'get_observability_context') ? $tool->get_observability_context() : [];
		$ability = is_array($context) && isset($context['ability_name']) ? (string) $context['ability_name'] : '';
		$userId  = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
		if ($ability === '' || ! self::isAgent($userId)) {
			return $result;
		}

		return self::apply($result, $ability, self::connection($userId), time());
	}

	/**
	 * Counts this call for the connection and adds the line when due.
	 * Public for tests; $now is a Unix time.
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function apply($result, string $ability, string $connection, int $now) {
		$threshold = self::threshold();
		if ($threshold <= 0) {
			return $result;
		}

		$ok        = ! (function_exists('is_wp_error') && is_wp_error($result));
		$canonical = AbilityNames::canonicalFor($ability);
		$state     = self::load($connection);

		if ($canonical === AbilityNames::MEMORY_WRITE) {
			if ($ok) {
				self::save($connection, ['changes' => 0, 'first' => 0]);
			}

			return $result;
		}

		if ($ok && ToolHints::changes($canonical) && ! in_array($canonical, self::NOT_WORK, true)) {
			$state['changes']++;
			if ($state['first'] === 0) {
				$state['first'] = $now;
			}
			self::save($connection, $state);
		}

		if (! $ok || ! is_array($result) || ! self::due($state, $threshold, $now) || ! self::canCarry($result)) {
			return $result;
		}

		$result[self::KEY] = self::message($state['changes'], $state['first'] > 0 ? (int) floor(($now - $state['first']) / 60) : 0);

		return $result;
	}

	/**
	 * Whether this state asks for a memory-write.
	 *
	 * @param array{changes: int, first: int} $state
	 */
	public static function due(array $state, int $threshold, int $now): bool {
		if ($state['changes'] >= $threshold) {
			return true;
		}

		return $state['changes'] >= self::MIN_CHANGES
			&& $state['first'] > 0
			&& ($now - $state['first']) >= self::MAX_MINUTES * 60;
	}

	public static function message(int $changes, int $minutes): string {
		return sprintf(
			/* translators: 1: number of changes, 2: minutes */
			__('Memory due: this connection made %1$d changes over %2$d minutes and saved no memory. Before you go on, write what was decided and why, findings, open questions and who asked, with tomatio-memory-write, in the scope it belongs to (a TíoDay or board item keeps only a title). Then carry on; this line goes away once you have.', 'tomatio'),
			$changes,
			$minutes
		);
	}

	/**
	 * Only an object-shaped answer can carry one more key.
	 *
	 * @param array<mixed> $result
	 */
	private static function canCarry(array $result): bool {
		if ($result === [] || array_is_list($result)) {
			return false;
		}

		// A resource or image answer is a content block, not tool data.
		return ! isset($result['type']) || ! in_array($result['type'], ['resource', 'image'], true);
	}

	/** Only agents write memories, so only agent connections are asked to. */
	private static function isAgent(int $userId): bool {
		$user = $userId > 0 && function_exists('get_userdata') ? get_userdata($userId) : false;

		return $user instanceof \WP_User && in_array(\AgentBrain\Modules\Identity\Capabilities::ROLE_AGENT, (array) $user->roles, true);
	}

	private static function threshold(): int {
		$value = function_exists('apply_filters') ? apply_filters(self::FILTER_THRESHOLD, self::THRESHOLD) : self::THRESHOLD;

		return is_numeric($value) ? max(0, (int) $value) : self::THRESHOLD;
	}

	/** The connection key: user plus session, connection or client. Never client-chosen alone. */
	public static function connection(int $userId): string {
		$session = isset($_SERVER['HTTP_MCP_SESSION_ID']) && is_string($_SERVER['HTTP_MCP_SESSION_ID']) ? $_SERVER['HTTP_MCP_SESSION_ID'] : '';
		if ($session !== '') {
			return $userId . '|s|' . $session;
		}

		$connection = AdapterBridge::connectionKey();
		if ($connection !== null) {
			return $userId . '|c|' . $connection;
		}

		return $userId . '|a|' . (string) \AgentBrain\Core\ClientContext::current();
	}

	/** @return array{changes: int, first: int} */
	public static function load(string $connection): array {
		$value = function_exists('get_transient') ? get_transient(self::TRANSIENT_PREFIX . md5($connection)) : false;
		if (! is_array($value)) {
			return ['changes' => 0, 'first' => 0];
		}

		return [
			'changes' => isset($value['changes']) ? max(0, (int) $value['changes']) : 0,
			'first'   => isset($value['first']) ? max(0, (int) $value['first']) : 0,
		];
	}

	/** @param array{changes: int, first: int} $state */
	private static function save(string $connection, array $state): void {
		if (function_exists('set_transient')) {
			set_transient(self::TRANSIENT_PREFIX . md5($connection), $state, DAY_IN_SECONDS);
		}
	}
}
