<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

/**
 * #992: after a person approves the first dry run, sync runs hourly.
 * A failing run leaves the last links in place.
 */
final class Cron {

	public const HOOK = 'tomatio_planning_sync';

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
		add_action( 'init', [ self::class, 'schedule' ], 40 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 20 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	public static function run(): void {
		foreach ( Library::all() as $service => $row ) {
			if ( empty( $row['live'] ) ) {
				continue;
			}
			Apply::cron( $service );
		}
	}
}
