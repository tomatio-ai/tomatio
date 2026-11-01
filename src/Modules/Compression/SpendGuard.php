<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

/**
 * CMP-5: free-tier spend cap (monthly USD estimate from token counts x
 * configurable price per 1k tokens) and per-hour call limit, enforced
 * before each provider call. Fail closed: on cap/limit hit, the caller must
 * not call the provider.
 *
 * evaluate()/estimateUsd() are pure and WP-free so they're unit-testable;
 * checkAndReserve() is the WP-option-backed enforcement point used by Runner.
 */
final class SpendGuard {

	public const REASON_SPEND_CAP  = 'ab_compression_spend_cap_reached';
	public const REASON_HOUR_LIMIT = 'ab_compression_hourly_limit_reached';

	public const OPTION_MONTHLY_CAP_USD    = 'ab_compression_monthly_cap_usd';
	public const OPTION_PRICE_PER_1K_USD   = 'ab_compression_price_per_1k_usd';
	public const OPTION_HOURLY_CALL_LIMIT  = 'ab_compression_hourly_call_limit';

	public const DEFAULT_MONTHLY_CAP_USD   = 5.0;
	public const DEFAULT_PRICE_PER_1K_USD  = 0.002;
	public const DEFAULT_HOURLY_CALL_LIMIT = 20;

	/**
	 * Pure decision: given current usage and configured limits, decide
	 * whether a call is allowed. A cap/limit of 0 or less means "unlimited".
	 *
	 * @return true|string true when allowed, otherwise one of the REASON_* constants.
	 */
	public static function evaluate( float $monthUsd, float $capUsd, int $hourCalls, int $hourLimit ): bool|string {
		if ( $capUsd > 0 && $monthUsd >= $capUsd ) {
			return self::REASON_SPEND_CAP;
		}

		if ( $hourLimit > 0 && $hourCalls >= $hourLimit ) {
			return self::REASON_HOUR_LIMIT;
		}

		return true;
	}

	public static function estimateUsd( int $tokens, float $pricePer1k ): float {
		if ( $tokens <= 0 || $pricePer1k <= 0 ) {
			return 0.0;
		}

		return ( $tokens / 1000 ) * $pricePer1k;
	}

	/**
	 * WP-backed enforcement: projects what monthly spend WOULD be if this
	 * call goes ahead (current + this call's own estimate) and checks that
	 * projection — and the hourly count — against configured limits BEFORE
	 * the caller invokes the provider ("enforced before each call", CMP-5).
	 * Never silently allows past the cap (L26 fail loud): callers that get a
	 * WP_Error must not call the provider and must surface it.
	 */
	public static function checkAndReserve( int $estimatedTokens ): bool|\WP_Error {
		$capUsd     = (float) get_option( self::OPTION_MONTHLY_CAP_USD, self::DEFAULT_MONTHLY_CAP_USD );
		$pricePer1k = (float) get_option( self::OPTION_PRICE_PER_1K_USD, self::DEFAULT_PRICE_PER_1K_USD );
		$hourLimit  = (int) get_option( self::OPTION_HOURLY_CALL_LIMIT, self::DEFAULT_HOURLY_CALL_LIMIT );

		$monthKey = 'ab_compression_spend_' . gmdate( 'Ym' );
		$monthUsd = (float) get_option( $monthKey, 0.0 );

		$hourKey   = 'ab_compression_calls_' . gmdate( 'YmdH' );
		$hourCalls = (int) get_transient( $hourKey );

		$projectedMonthUsd = $monthUsd + self::estimateUsd( $estimatedTokens, $pricePer1k );

		$result = self::evaluate( $projectedMonthUsd, $capUsd, $hourCalls, $hourLimit );
		if ( $result !== true ) {
			return new \WP_Error( (string) $result, 'Compression call blocked: ' . $result );
		}

		set_transient( $hourKey, $hourCalls + 1, HOUR_IN_SECONDS );
		update_option( $monthKey, $projectedMonthUsd, false );

		return true;
	}
}
