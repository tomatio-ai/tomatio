<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Preview;

/**
 * #285: the cached preview-host reachability record used at mint time, so
 * artifact-share-create never does a live HTTP call per mint. The record is
 * refreshed at most once per TTL (10 minutes) and invalidated whenever the
 * Sharing settings are saved.
 *
 * Fail-safe (see PreviewCheck): only a finished check that proved the host
 * wrong blocks minting; UNKNOWN (check could not run) allows it, flagged.
 */
final class HostReachability {

	public const OPTION = 'ab_preview_host_check';

	public const TTL = 600;

	/**
	 * @return array{host:string,result:string,checked_at:int}
	 */
	public static function check( ?int $now = null ): array {
		$now    = $now ?? time();
		$host   = Preview::configuredHost();
		$cached = get_option( self::OPTION, null );

		if ( is_array( $cached )
			&& (string) ( $cached['host'] ?? '' ) === $host
			&& in_array( (string) ( $cached['result'] ?? '' ), [ PreviewCheck::OK, PreviewCheck::UNKNOWN, PreviewCheck::DNS, PreviewCheck::TLS, PreviewCheck::UNREACHED, PreviewCheck::NOT_PLUGIN ], true )
			&& $now - (int) ( $cached['checked_at'] ?? 0 ) < self::TTL
		) {
			return [
				'host'       => $host,
				'result'     => (string) $cached['result'],
				'checked_at' => (int) $cached['checked_at'],
			];
		}

		$record = [
			'host'       => $host,
			'result'     => PreviewCheck::run( $host ),
			'checked_at' => $now,
		];
		update_option( self::OPTION, $record, false );

		return $record;
	}

	public static function invalidate(): void {
		delete_option( self::OPTION );
	}

	/**
	 * @param array{host:string,result:string,checked_at:int} $check
	 */
	public static function allowsMint( array $check ): bool {
		return in_array( $check['result'], [ PreviewCheck::OK, PreviewCheck::UNKNOWN ], true );
	}
}
