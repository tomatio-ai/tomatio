<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

use AgentBrain\Modules\Cloud\Connector;

/**
 * Daily `agent_brain_backup` cron. The event is always scheduled (so
 * enabling backups later doesn't require re-activating the plugin), but the
 * handler is a strict no-op — no network call, nothing built — unless the
 * cloud is connected AND `ab_backup_enabled` is true, matching the
 * no-network-unless-opted-in posture used by Cloud\Client.
 */
final class Cron {

	public const HOOK           = 'agent_brain_backup';
	public const OPTION_ENABLED = 'ab_backup_enabled';

	public static function register(): void {
		add_action(self::HOOK, [self::class, 'run']);
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

	public static function shouldRun(): bool {
		return (bool) get_option(self::OPTION_ENABLED, false) && Connector::isConnected();
	}

	public static function run(): void {
		if (! self::shouldRun()) {
			return;
		}

		// ops/F3: the daily job only starts/advances the resumable
		// BackupStateMachine — no single PHP execution has to complete an
		// entire export+dump+tar+encrypt+upload run any more.
		BackupStateMachine::start();
	}
}
