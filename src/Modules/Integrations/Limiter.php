<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

/**
 * #990: a per-service cap and a backoff after the service says to slow
 * down. Reads are counted on the row; they are not an audit event.
 */
final class Limiter {

	/**
	 * Seconds to wait before the next call, or 0 when a call is allowed.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $spec
	 */
	public static function wait( array $row, array $spec, int $now ): int {
		$until = (int) ( $row['limited_until'] ?? 0 );
		if ( $until > $now ) {
			return $until - $now;
		}
		$window = (int) ( $row['window'] ?? 0 );
		$count  = (int) ( $row['window_count'] ?? 0 );
		$cap    = (int) ( $spec['per_minute'] ?? 30 );
		if ( $window === intdiv( $now, 60 ) && $count >= $cap ) {
			return 60 - ( $now % 60 );
		}

		return 0;
	}

	/**
	 * Count one read in the current minute.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function countRead( array $row, int $now ): array {
		$minute = intdiv( $now, 60 );
		if ( (int) ( $row['window'] ?? 0 ) !== $minute ) {
			$row['window']       = $minute;
			$row['window_count'] = 0;
		}
		$row['window_count'] = (int) $row['window_count'] + 1;
		$row['reads']        = (int) ( $row['reads'] ?? 0 ) + 1;

		return $row;
	}

	/**
	 * The service asked us to slow down, or we hit our own cap.
	 *
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>
	 */
	public static function limit( array $row, array $spec, int $now, int $retryAfter ): array {
		$backoff = is_array( $spec['backoff'] ?? null ) ? $spec['backoff'] : [ 60 ];
		$index   = min( count( $backoff ) - 1, max( 0, (int) ( $row['failures'] ?? 0 ) ) );
		$wait    = $retryAfter > 0 ? $retryAfter : (int) $backoff[ $index ];
		$row['limited_until'] = $now + max( 1, $wait );
		$row['failures']      = (int) ( $row['failures'] ?? 0 ) + 1;

		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function clear( array $row ): array {
		$row['limited_until'] = 0;
		$row['failures']      = 0;

		return $row;
	}
}
