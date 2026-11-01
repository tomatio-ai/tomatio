<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Ops;

/**
 * Daily `agent_brain_db_decay` cron running Decay::run().
 */
final class Cron {

	public const HOOK = 'agent_brain_db_decay';

	public static function register(): void {
		add_action(self::HOOK, static function (): void {
			Decay::run();
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
}
