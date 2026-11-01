<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * Sharing options with their defaults, plus request-scoped client IP helpers.
 */
final class Config {

	public const OPT_DISABLED         = 'ab_sharing_disabled';
	public const OPT_REQUIRE_APPROVAL = 'ab_share_require_approval';
	public const OPT_ENFORCE_IP       = 'ab_share_enforce_ip_binding';
	public const OPT_DEFAULT_IP_MODE  = 'ab_share_default_ip_mode';
	public const OPT_MINT_RATE       = 'ab_share_mint_rate_per_hour';
	public const OPT_TRUSTED_PROXIES  = 'ab_trusted_proxies';
	public const OPT_AGENT_CREATE     = 'ab_agent_create_enabled';

	public const DEFAULT_MINT_RATE = 20;

	public static function disabled(): bool {
		return (bool) get_option( self::OPT_DISABLED, false );
	}

	public static function requireApproval(): bool {
		return (bool) get_option( self::OPT_REQUIRE_APPROVAL, false );
	}

	/**
	 * Whether agents may create artifacts themselves (as lead, in a scope they
	 * can write). Off by default: artifact-create stays curator-only.
	 */
	public static function agentCreateEnabled(): bool {
		return (bool) get_option( self::OPT_AGENT_CREATE, false );
	}

	public static function enforceIpBinding(): bool {
		return (bool) get_option( self::OPT_ENFORCE_IP, false );
	}

	/**
	 * Mode applied to new links when the caller does not pass ip_bind.
	 */
	public static function defaultIpMode(): string {
		return Policy::normalizeIpMode( (string) get_option( self::OPT_DEFAULT_IP_MODE, Policy::IP_OFF ) );
	}

	/**
	 * Where the server sees the client IP from, for the settings diagnostic.
	 *
	 * @param array<string, mixed>|null $server Defaults to $_SERVER.
	 * @return array{ip:string,source:string}
	 */
	public static function ipDiagnostic( ?array $server = null ): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed and validated as an IP by ClientIp.
		$server = $server ?? $_SERVER;
		$ip     = self::clientIp( $server );
		$remote = trim( (string) ( $server['REMOTE_ADDR'] ?? '' ) );
		$source = 'REMOTE_ADDR';
		if ( $ip !== '' && $ip !== $remote ) {
			$source = trim( (string) ( $server['HTTP_CF_CONNECTING_IP'] ?? '' ) ) === $ip ? 'CF-Connecting-IP' : 'X-Forwarded-For';
		}

		return [ 'ip' => $ip, 'source' => $source ];
	}

	public static function mintRatePerHour(): int {
		$rate = (int) get_option( self::OPT_MINT_RATE, self::DEFAULT_MINT_RATE );

		return $rate > 0 ? $rate : self::DEFAULT_MINT_RATE;
	}

	/**
	 * @return array<int, string>
	 */
	public static function trustedProxies(): array {
		return ClientIp::parseTrusted( get_option( self::OPT_TRUSTED_PROXIES, [] ) );
	}

	/**
	 * Client IP for the current request (REMOTE_ADDR unless behind a trusted proxy).
	 *
	 * @param array<string, mixed>|null $server Defaults to $_SERVER.
	 */
	public static function clientIp( ?array $server = null ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed and validated as an IP by ClientIp.
		$server = $server ?? $_SERVER;

		return ClientIp::resolve( $server, self::trustedProxies() );
	}

	public static function ipHash( string $ip ): string {
		return ClientIp::hash( $ip, wp_salt( 'auth' ) . '|ab_share_ip' );
	}
}
