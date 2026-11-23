<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentInbox;

/**
 * #1060: the inbox's scheduler.
 *
 * Every minute (a WP-Cron schedule of its own): fire due timers into the
 * inbox and put the events of expired leases back (or fail them). Daily:
 * prune done and failed events, and done and cancelled timers, after
 * Inbox::RETENTION_DAYS (filter tomatio/agent_inbox_retention_days).
 *
 * WP-Cron runs on site traffic, so a quiet site fires late; a server cron
 * that calls wp-cron.php every minute makes it punctual.
 */
final class Cron {

	public const SCHEDULE   = 'tomatio_every_minute';
	public const HOOK_TICK  = 'agent_brain_agent_inbox_tick';
	public const HOOK_PRUNE = 'agent_brain_agent_inbox_prune';

	public static function register(): void {
		add_filter( 'cron_schedules', [ self::class, 'schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- timers are minute-precise by design.
		// WP-Cron passes '' when an event has no arguments: no $now here.
		add_action( self::HOOK_TICK, static function (): void {
			self::tick();
		} );
		add_action( self::HOOK_PRUNE, static function (): void {
			self::prune();
		} );
	}

	/**
	 * @param mixed $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function schedules( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : [];
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = [ 'interval' => MINUTE_IN_SECONDS, 'display' => 'Every minute (TomaTío agent timers)' ];
		}

		return $schedules;
	}

	public static function schedule(): void {
		if ( ! has_filter( 'cron_schedules', [ self::class, 'schedules' ] ) ) {
			add_filter( 'cron_schedules', [ self::class, 'schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		}
		if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK_TICK );
		}
		if ( ! wp_next_scheduled( self::HOOK_PRUNE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_PRUNE );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK_TICK );
		wp_clear_scheduled_hook( self::HOOK_PRUNE );
		wp_clear_scheduled_hook( Wake::HOOK );
	}

	/** @return array{fired: int, expired: int} */
	public static function tick( ?int $now = null ): array {
		$now = $now ?? time();

		return [ 'fired' => Timers::fire( $now ), 'expired' => Inbox::expire( 0, $now ) ];
	}

	/** @return array{events: int, timers: int} */
	public static function prune( ?int $now = null ): array {
		$days = (int) \AgentBrain\Core\Hooks::filter( 'agent_inbox_retention_days', Inbox::RETENTION_DAYS );

		return [ 'events' => Inbox::prune( $days, $now ), 'timers' => Timers::prune( $days, $now ) ];
	}
}
