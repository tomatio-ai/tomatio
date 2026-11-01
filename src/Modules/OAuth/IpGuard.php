<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * Pure IP-range classifier used to SSRF-harden the CIMD fetcher (ID-5).
 * No network access here: callers resolve DNS themselves and pass the
 * resulting IP address(es) through isPublic().
 */
final class IpGuard {

	/**
	 * True only for an address that is neither loopback, private, link-local
	 * (including the 169.254.169.254 cloud-metadata address), CGNAT
	 * (100.64.0.0/10), nor any other PHP "reserved" range, for both IPv4 and
	 * IPv6 (including IPv4-mapped IPv6 and the IPv6 documentation range).
	 */
	public static function isPublic(string $ip): bool {
		$valid = filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);

		if ($valid === false) {
			return false;
		}

		if (self::isCgnat($ip)) {
			return false;
		}

		if (self::isIpv4MappedPrivate($ip)) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<int, string> $ips
	 */
	public static function allPublic(array $ips): bool {
		if ($ips === []) {
			return false;
		}

		foreach ($ips as $ip) {
			if (! self::isPublic($ip)) {
				return false;
			}
		}

		return true;
	}

	private static function isCgnat(string $ip): bool {
		$packed = inet_pton($ip);
		if ($packed === false || strlen($packed) !== 4) {
			return false;
		}

		$long = unpack('N', $packed);
		$long = $long === false ? 0 : $long[1];

		// 100.64.0.0/10
		$rangeStart = ip2long('100.64.0.0');
		$rangeEnd   = ip2long('100.127.255.255');

		return $rangeStart !== false && $rangeEnd !== false && $long >= $rangeStart && $long <= $rangeEnd;
	}

	/**
	 * ::ffff:0:0/96 IPv4-mapped IPv6 addresses inherit the IPv4 address's
	 * privacy: an IPv6 socket returning ::ffff:127.0.0.1 must be rejected
	 * even though the plain IPv6 filter flags don't know about the mapping.
	 */
	private static function isIpv4MappedPrivate(string $ip): bool {
		if (strpos($ip, '::ffff:') !== 0) {
			return false;
		}

		$mapped = substr($ip, 7);

		if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
			return false;
		}

		return ! self::isPublic($mapped);
	}
}
