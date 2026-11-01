<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * Application-wide degraded-state signal (L26.1/L26.2, fail-loud-over-default).
 *
 * If any module fails discovery, that is security-relevant: a failed
 * Identity or Memory module means scope enforcement silently vanishes.
 * ModuleRegistry never hides a failure, but something still has to shout
 * about it and give later code (the MCP transport, in a later wave) a
 * single place to ask "is it safe to serve requests right now?" — that is
 * this class. State is per-request (static, reset() by tests), never
 * persisted, and never contains secrets: only {dir, reason, message} facts
 * already public in the codebase layout.
 */
final class Health {

	private static bool $degraded = false;

	/** @var array<int, ModuleFailure> */
	private static array $failures = [];

	private static bool $logged = false;

	/**
	 * Record the result of a ModuleRegistry::discover() call. Safe to call
	 * more than once per request (e.g. boot() then activate()); only the
	 * first call with failures triggers the error_log.
	 *
	 * @param array<int, ModuleFailure> $failures
	 */
	public static function report(array $failures): void {
		self::$failures = $failures;
		self::$degraded = $failures !== [];

		if (! self::$degraded) {
			return;
		}

		self::logOnce();

		if (function_exists('add_action')) {
			add_action('admin_notices', [self::class, 'renderAdminNotice']);
		}
	}

	public static function status(): string {
		return self::$degraded ? 'degraded' : 'ok';
	}

	/**
	 * Machine-readable reasons for the current degraded state.
	 *
	 * @return array<int, array{dir: string, reason: string, message: string}>
	 */
	public static function reasons(): array {
		return array_map(
			static fn (ModuleFailure $failure): array => $failure->toArray(),
			self::$failures
		);
	}

	/**
	 * Fail-closed check: true when the site should refuse to serve
	 * agent-facing requests because a module failed to load. Gated through
	 * the `agent_brain/degraded` filter so operators can escalate (never
	 * silently downgrade past an actual failure) if they need to.
	 */
	public static function isDegraded(): bool {
		$filtered = function_exists('apply_filters')
			? (bool) apply_filters('agent_brain/degraded', false)
			: false;

		return self::$degraded || $filtered;
	}

	public static function renderAdminNotice(): void {
		if (! self::$degraded) {
			return;
		}

		if (! function_exists('current_user_can') || ! current_user_can('manage_options')) {
			return;
		}

		$reasons = array_map(
			static fn (ModuleFailure $failure): string => sprintf(
				'%s (%s)',
				$failure->dir(),
				$failure->reason()
			),
			self::$failures
		);

		$message = sprintf(
			'TomaTío: %d module(s) failed to load and were skipped: %s. Scope enforcement may be incomplete until this is fixed. See the PHP error log for details.',
			count(self::$failures),
			implode(', ', $reasons)
		);

		printf(
			'<div class="notice notice-error" role="alert"><p>%s</p></div>',
			esc_html($message)
		);
	}

	/**
	 * Test-only reset of the static request-scoped state.
	 */
	public static function reset(): void {
		self::$degraded = false;
		self::$failures = [];
		self::$logged   = false;
	}

	private static function logOnce(): void {
		if (self::$logged) {
			return;
		}

		self::$logged = true;

		$reasons = array_map(
			static fn (ModuleFailure $failure): string => sprintf(
				'%s [%s]: %s',
				$failure->dir(),
				$failure->reason(),
				$failure->message()
			),
			self::$failures
		);

		error_log(
			sprintf(
				'[agent-brain] degraded: %d module(s) failed to load: %s',
				count(self::$failures),
				implode('; ', $reasons)
			)
		);
	}
}
