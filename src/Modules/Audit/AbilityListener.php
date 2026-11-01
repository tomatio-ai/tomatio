<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Audit;

use AgentBrain\Modules\Forget\DigestKey;

/**
 * AUD-1: logs every ability invocation in our own namespace.
 *
 * WordPress 7.1 core (plugin/agent-brain/vendor/wordpress-core/wp-includes/abilities-api/class-wp-ability.php,
 * WP_Ability::execute()) fires, in this order, for every ability::execute() call:
 *
 *   1. do_action( 'wp_ability_invoked', string $ability_name, mixed $input, WP_Ability $ability )
 *      — fires unconditionally, before validation/permission/execution, raw input.
 *   2. apply_filters( 'wp_pre_execute_ability', mixed $pre, string $ability_name, mixed $input, WP_Ability $ability )
 *      — short-circuit filter; if a filter changes $pre it returns immediately and nothing else below fires.
 *   3. apply_filters( 'wp_ability_permission_result', bool|WP_Error $permission, string $ability_name, mixed $input, WP_Ability $ability )
 *      — fires only if input validation passed; only fires when check_permissions() runs.
 *   4. apply_filters( 'wp_ability_execute_result', mixed $result, string $ability_name, mixed $input, WP_Ability $ability )
 *      — fires only if permission check passed; wraps the execute_callback's return (may be WP_Error).
 *   5. do_action( 'wp_after_execute_ability', string $ability_name, mixed $input, mixed $result, WP_Ability $ability )
 *      — fires only on a fully successful execute() (post output-validation).
 *
 * There is no action/filter that fires for every outcome AND carries a result/status, so this
 * listener tracks invocations on a per-ability-name stack (LIFO — correct for ability A calling
 * ability B synchronously inside its own execute_callback, since B always fully resolves before
 * control returns to A) started at (1) and finalized at whichever of (2)/(3)/(4) resolves it first.
 * Anything that never reaches (3) or (4) (e.g. a pre_execute_ability short-circuit, or a fatal
 * error inside a callback) is flushed as status=error at 'shutdown' so every invocation still
 * produces exactly one row (AUD-1 acceptance criterion) instead of silently vanishing.
 */
final class AbilityListener {

	/**
	 * @var array<int, array{ability:string, hash:string, actor:int, start:float}>
	 */
	private static array $stack = [];

	public static function register(): void {
		add_action('wp_ability_invoked', [self::class, 'onInvoked'], 10, 3);
		add_filter('wp_pre_execute_ability', [self::class, 'onPreExecute'], 10, 4);
		add_filter('wp_ability_permission_result', [self::class, 'onPermissionResult'], 10, 4);
		add_filter('wp_ability_execute_result', [self::class, 'onExecuteResult'], 10, 4);
		add_action('shutdown', [self::class, 'flushUnresolved']);
	}

	/**
	 * @return array<int, string>
	 */
	private static function ownedPrefixes(): array {
		// Both spellings during the rename alias window (#184): a call via
		// the folded `tomatio/` alias is still ours to audit.
		$prefixes = apply_filters('agent_brain/audit_ability_prefixes', ['agent-brain/', 'tomatio/']);

		return is_array($prefixes) ? array_values(array_filter($prefixes, 'is_string')) : ['agent-brain/', 'tomatio/'];
	}

	/**
	 * The canonical ability name for the audit record: the `tomatio/` alias
	 * spelling is recorded under its `agent-brain/` canonical name (#184),
	 * so grants and audit stay keyed on one ability id. Kept as a local
	 * mirror of Mcp\AbilityNames::canonicalFor() — the Audit module must
	 * not depend on the Mcp module.
	 */
	private static function canonicalName(string $abilityName): string {
		return str_starts_with($abilityName, 'tomatio/')
			? 'agent-brain/' . substr($abilityName, strlen('tomatio/'))
			: $abilityName;
	}

	private static function isOwned(string $abilityName): bool {
		foreach (self::ownedPrefixes() as $prefix) {
			if ($prefix !== '' && str_starts_with($abilityName, $prefix)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keyed digest of the call's arguments: HMAC-SHA-256 under the brain's
	 * erasure digest key (Forget\DigestKey, purpose "ability.args"), so equal
	 * arguments still show the same hash in the Audit Log while a short
	 * argument (a search for a name) cannot be recovered by guessing, as a
	 * plain SHA-256 could. Pure (no WP calls) so it is exercised directly
	 * from a unit test without booting WordPress.
	 *
	 * @param mixed $input
	 */
	public static function hashInput($input, string $key): string {
		$encoded = json_encode(self::stableSort($input), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return DigestKey::digestWith($key, 'ability.args', $encoded !== false ? $encoded : 'null');
	}

	/**
	 * Recursively sorts array keys so semantically identical args always hash
	 * the same regardless of key order.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function stableSort($value) {
		if (! is_array($value)) {
			return $value;
		}

		$sorted = [];
		foreach ($value as $key => $item) {
			$sorted[$key] = self::stableSort($item);
		}

		if (array_is_list($sorted)) {
			return $sorted;
		}

		ksort($sorted);

		return $sorted;
	}

	/**
	 * @param mixed $input
	 * @param mixed $ability
	 */
	public static function onInvoked(string $abilityName, $input, $ability): void {
		if (! self::isOwned($abilityName)) {
			return;
		}

		self::$stack[] = [
			'ability' => self::canonicalName($abilityName),
			'hash'    => self::hashInput($input, DigestKey::raw()),
			'actor'   => get_current_user_id(),
			'start'   => microtime(true),
		];
	}

	/**
	 * @param mixed $pre
	 * @param mixed $input
	 * @param mixed $ability
	 * @return mixed
	 */
	public static function onPreExecute($pre, string $abilityName, $input, $ability) {
		// Purely observational: a plugin other than us may still short-circuit
		// here. We don't finalize on this hook because we cannot tell whether
		// $pre was changed from inside a filter closure signature alone; the
		// shutdown flush below is the safety net for this path.
		return $pre;
	}

	/**
	 * @param bool|\WP_Error $permission
	 * @param mixed          $input
	 * @param mixed          $ability
	 * @return bool|\WP_Error
	 */
	public static function onPermissionResult($permission, string $abilityName, $input, $ability) {
		if (! self::isOwned($abilityName)) {
			return $permission;
		}

		if ($permission === true) {
			return $permission;
		}

		$entry = self::popMatching($abilityName);
		if ($entry !== null) {
			self::finalize($entry, Repository::STATUS_DENIED);
		}

		return $permission;
	}

	/**
	 * @param mixed $result
	 * @param mixed $input
	 * @param mixed $ability
	 * @return mixed
	 */
	public static function onExecuteResult($result, string $abilityName, $input, $ability) {
		if (! self::isOwned($abilityName)) {
			return $result;
		}

		$entry = self::popMatching($abilityName);
		if ($entry !== null) {
			self::finalize($entry, is_wp_error($result) ? Repository::STATUS_ERROR : Repository::STATUS_OK);
		}

		return $result;
	}

	/**
	 * Anything still on the stack at 'shutdown' never reached a resolving
	 * hook (pre_execute short-circuit by another plugin, or a fatal error
	 * mid-callback) — recorded as an error rather than dropped.
	 */
	public static function flushUnresolved(): void {
		while (($entry = array_pop(self::$stack)) !== null) {
			self::finalize($entry, Repository::STATUS_ERROR, 'no completion hook observed before shutdown');
		}
	}

	/**
	 * @return array{ability:string, hash:string, actor:int, start:float}|null
	 */
	private static function popMatching(string $abilityName): ?array {
		$abilityName = self::canonicalName($abilityName);

		for ($i = count(self::$stack) - 1; $i >= 0; $i--) {
			if (self::$stack[$i]['ability'] === $abilityName) {
				$entry = self::$stack[$i];
				array_splice(self::$stack, $i, 1);

				return $entry;
			}
		}

		return null;
	}

	/**
	 * @param array{ability:string, hash:string, actor:int, start:float} $entry
	 */
	private static function finalize(array $entry, string $status, ?string $note = null): void {
		$durationMs = (int) round((microtime(true) - $entry['start']) * 1000);

		$event = [
			'actor_id'    => $entry['actor'],
			'event'       => 'ability.invoked',
			'status'      => $status,
			'object_type' => 'ability',
			'object_id'   => $entry['ability'],
			'args_hash'   => $entry['hash'],
			'duration_ms' => $durationMs,
		];

		$meta = [];
		if ($note !== null) {
			$meta['note'] = $note;
		}
		// Delegated sessions: which person the agent was acting for.
		$via = \AgentBrain\Core\AuthorizedBy::id();
		if ($via > 0) {
			$meta['via'] = $via;
		}
		if ($meta !== []) {
			$event['meta'] = $meta;
		}

		do_action('agent_brain/audit', $event);
	}
}
