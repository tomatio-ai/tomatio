<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AskBrain;

/**
 * ART-26 (#166): daily cost caps for ask-the-brain calls, the CMP-5
 * Compression\SpendGuard pattern applied per day instead of per month.
 *
 * Two caps are enforced before every provider call, both projected with
 * THIS call's own estimate (never silently allows past the cap, L26):
 *
 * - per-artifact daily cap in USD of model tokens (post meta
 *   Mode::META_CAP, empty = DEFAULT_ARTIFACT_CAP_USD, 0 or less = unlimited);
 * - site-wide daily cap in USD (option OPT_SITE_CAP_USD).
 *
 * Accounting: checkAndReserve() adds the estimate to both current-day keys
 * and returns the reserved USD; after the call commit() reconciles the
 * reservation to the provider's reported usage. Day keys rotate with the
 * UTC date, so budgets renew automatically at midnight and a curator's cap
 * change takes effect on the very next call. evaluate()/estimateUsd() are
 * pure and WP-free so the cap math is unit-testable.
 */
final class Spend {

	public const REASON_ARTIFACT_CAP = 'ab_ask_artifact_cap_reached';
	public const REASON_SITE_CAP     = 'ab_ask_site_cap_reached';

	public const OPT_SITE_CAP_USD   = 'ab_ask_site_daily_cap_usd';
	public const OPT_PRICE_PER_1K   = 'ab_ask_price_per_1k_usd';

	public const DEFAULT_ARTIFACT_CAP_USD = 0.10;
	public const DEFAULT_SITE_CAP_USD     = 2.0;
	public const DEFAULT_PRICE_PER_1K_USD = 0.002;

	private const PREFIX_ART  = 'ab_ask_spend_art_';
	private const PREFIX_SITE = 'ab_ask_spend_site_';

	/**
	 * Pure decision: a cap of 0 or less means "unlimited".
	 *
	 * @return true|string true when allowed, otherwise one of the REASON_* constants.
	 */
	public static function evaluate( float $artifactDayUsd, float $artifactCapUsd, float $siteDayUsd, float $siteCapUsd ): bool|string {
		if ( $artifactCapUsd > 0 && $artifactDayUsd >= $artifactCapUsd ) {
			return self::REASON_ARTIFACT_CAP;
		}

		if ( $siteCapUsd > 0 && $siteDayUsd >= $siteCapUsd ) {
			return self::REASON_SITE_CAP;
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
	 * Rough token estimate for when a provider reports no usage: 4
	 * characters per token, at least 1.
	 */
	public static function estimateTokens( string $text ): int {
		$chars = mb_strlen( $text );

		return max( 1, (int) ceil( $chars / 4 ) );
	}

	/**
	 * Whether the option-backed accounting can run in this environment (the
	 * unit bootstrap has no WordPress); callers treat a false the same as
	 * "unlimited" because there is nothing to persist against.
	 */
	public static function accountingAvailable(): bool {
		return function_exists( 'get_option' ) && function_exists( 'update_option' );
	}

	/**
	 * The per-artifact cap: post meta override, default when unset, 0 when
	 * the stored value is 0 or less (unlimited).
	 */
	public static function artifactCap( int $artifactId ): float {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return 0.0;
		}

		$meta = get_post_meta( $artifactId, Mode::META_CAP, true );
		if ( $meta === '' || ! is_numeric( $meta ) ) {
			return self::DEFAULT_ARTIFACT_CAP_USD;
		}

		$value = (float) $meta;

		return $value <= 0 ? 0.0 : $value;
	}

	public static function siteCap(): float {
		if ( ! self::accountingAvailable() ) {
			return 0.0;
		}

		$value = (float) get_option( self::OPT_SITE_CAP_USD, self::DEFAULT_SITE_CAP_USD );

		return $value <= 0 ? 0.0 : $value;
	}

	public static function pricePer1k(): float {
		if ( ! self::accountingAvailable() ) {
			return self::DEFAULT_PRICE_PER_1K_USD;
		}

		$value = (float) get_option( self::OPT_PRICE_PER_1K, self::DEFAULT_PRICE_PER_1K_USD );

		return $value > 0 ? $value : self::DEFAULT_PRICE_PER_1K_USD;
	}

	/**
	 * WP-backed enforcement: projects current-day spend plus this call's
	 * own estimate against both caps BEFORE the caller invokes the provider,
	 * and reserves the estimate on both day keys. Returns the reserved USD
	 * for commit(); a WP_Error means the caller must not call the provider.
	 *
	 * @return array{reserved_usd:float}|true|\WP_Error true when the estimate is $0 (nothing to reserve).
	 */
	public static function checkAndReserve( int $artifactId, int $estimatedTokens, ?int $now = null ) {
		if ( ! self::accountingAvailable() ) {
			return true;
		}

		$reservedUsd = self::estimateUsd( $estimatedTokens, self::pricePer1k() );
		if ( $reservedUsd <= 0.0 ) {
			return true;
		}

		$artifactKey = self::artifactDayKey( $artifactId, $now );
		$siteKey     = self::siteDayKey( $now );

		$artifactDayUsd = (float) get_option( $artifactKey, 0.0 );
		$siteDayUsd     = (float) get_option( $siteKey, 0.0 );

		$result = self::evaluate(
			$artifactDayUsd + $reservedUsd,
			self::artifactCap( $artifactId ),
			$siteDayUsd + $reservedUsd,
			self::siteCap()
		);
		if ( $result !== true ) {
			return new \WP_Error( (string) $result, 'Ask-the-brain call blocked: ' . $result );
		}

		update_option( $artifactKey, $artifactDayUsd + $reservedUsd, false );
		update_option( $siteKey, $siteDayUsd + $reservedUsd, false );

		return [ 'reserved_usd' => $reservedUsd ];
	}

	/**
	 * Reconcile a reservation to the provider's reported usage: the day
	 * keys shift by (actual - reserved), never below 0. A provider that
	 * reports no usage leaves the (over-)estimate in place, erring toward
	 * the cap.
	 */
	public static function commit( int $artifactId, float $reservedUsd, int $actualTokens, ?int $now = null ): void {
		if ( $reservedUsd <= 0.0 || ! self::accountingAvailable() ) {
			return;
		}

		$actualUsd = self::estimateUsd( $actualTokens, self::pricePer1k() );
		$delta     = $actualUsd - $reservedUsd;

		foreach ( [ self::artifactDayKey( $artifactId, $now ), self::siteDayKey( $now ) ] as $key ) {
			$current = (float) get_option( $key, 0.0 );
			update_option( $key, max( 0.0, $current + $delta ), false );
		}
	}

	/**
	 * Current-day spend for tests/diagnostics.
	 */
	public static function artifactDaySpend( int $artifactId, ?int $now = null ): float {
		return (float) get_option( self::artifactDayKey( $artifactId, $now ), 0.0 );
	}

	public static function siteDaySpend( ?int $now = null ): float {
		return (float) get_option( self::siteDayKey( $now ), 0.0 );
	}

	private static function artifactDayKey( int $artifactId, ?int $now = null ): string {
		return self::PREFIX_ART . $artifactId . '_' . gmdate( 'Ymd', $now ?? time() );
	}

	private static function siteDayKey( ?int $now = null ): string {
		return self::PREFIX_SITE . gmdate( 'Ymd', $now ?? time() );
	}
}
