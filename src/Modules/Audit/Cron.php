<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Audit;

/**
 * Daily retention prune, per `ab_audit_retention_days` (default 90).
 */
final class Cron {

	public const HOOK           = 'agent_brain_audit_prune';
	public const OPTION_RETENTION_DAYS = 'ab_audit_retention_days';
	public const DEFAULT_RETENTION_DAYS = 90;

	public static function register(): void {
		add_action(self::HOOK, static function (): void {
			self::run();
		});
	}

	public static function activate(): void {
		if (! wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time(), 'daily', self::HOOK);
		}
	}

	public static function deactivate(): void {
		$timestamp = wp_next_scheduled(self::HOOK);
		if ($timestamp !== false) {
			wp_unschedule_event($timestamp, self::HOOK);
		}
	}

	public static function run(): int {
		$days = (int) get_option(self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS);

		return Repository::pruneOlderThan($days);
	}
}
