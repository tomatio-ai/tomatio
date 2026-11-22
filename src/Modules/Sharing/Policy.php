<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * Pure share-link policy helpers (no WordPress): TTL clamping, IP-mode
 * resolution, hostname validation, expiry. Kept free of WP so they are
 * unit-testable.
 */
final class Policy {

	public const IP_OFF        = 'off';
	public const IP_FIRST_OPEN = 'first_open';
	public const IP_FIXED      = 'fixed';

	public const IP_MODES = [ self::IP_OFF, self::IP_FIRST_OPEN, self::IP_FIXED ];

	public const DEFAULT_TTL = 259200;   // 72 hours.
	public const MIN_TTL     = 60;
	public const MAX_TTL     = 31536000; // 1 year ceiling.

	public static function clampTtl( ?int $seconds ): int {
		if ( $seconds === null || $seconds <= 0 ) {
			return self::DEFAULT_TTL;
		}

		return max( self::MIN_TTL, min( self::MAX_TTL, $seconds ) );
	}

	public static function normalizeIpMode( string $mode ): string {
		return in_array( $mode, self::IP_MODES, true ) ? $mode : self::IP_OFF;
	}

	/**
	 * The site option ab_share_enforce_ip_binding forces at least first_open.
	 */
	public static function effectiveIpMode( string $mode, bool $enforce ): string {
		$mode = self::normalizeIpMode( $mode );

		return ( $enforce && $mode === self::IP_OFF ) ? self::IP_FIRST_OPEN : $mode;
	}

	/**
	 * Whether a request from $clientHash may open a link bound to $boundHash.
	 * An empty client hash never matches a bound link.
	 */
	public static function ipMatches( string $mode, ?string $boundHash, string $clientHash ): bool {
		if ( $mode === self::IP_OFF ) {
			return true;
		}

		if ( $boundHash === null || $boundHash === '' ) {
			// first_open with nothing bound yet: the caller binds atomically.
			return $mode === self::IP_FIRST_OPEN && $clientHash !== '';
		}

		return $clientHash !== '' && hash_equals( $boundHash, $clientHash );
	}

	public static function isExpired( string $expiresAtUtc, int $now ): bool {
		$ts = strtotime( $expiresAtUtc . ' UTC' );

		return $ts === false || $ts <= $now;
	}

	/**
	 * Validate a bare hostname (no scheme, port, path) that differs from the
	 * site hosts. Returns the lowercase host, or "error:<code>".
	 *
	 * @param string[] $siteHosts
	 */
	public static function validatePreviewHost( string $input, array $siteHosts ): string {
		$host = strtolower( trim( $input ) );

		if ( $host === '' ) {
			return 'error:empty';
		}

		if ( preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host ) !== 1
			|| filter_var( $host, FILTER_VALIDATE_IP ) !== false ) {
			return 'error:format';
		}

		foreach ( $siteHosts as $site ) {
			if ( $site !== '' && $host === strtolower( $site ) ) {
				return 'error:same_as_site';
			}
		}

		return $host;
	}
}
