<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Context;

/**
 * #991: hourly refresh of stored sources. A failing read leaves the
 * current copy in place.
 */
final class Cron {

	public const HOOK = 'tomatio_context_sources';

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
		add_action( 'init', [ self::class, 'schedule' ], 40 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	public static function run(): void {
		foreach ( Library::all() as $pick ) {
			if ( ( $pick['status'] ?? '' ) !== 'stored' ) {
				continue;
			}
			Sync::refresh( $pick );
		}
	}
}
