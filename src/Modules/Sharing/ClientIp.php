<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * Client IP resolution (pure, no WordPress). REMOTE_ADDR is authoritative
 * unless it belongs to a configured trusted proxy CIDR; only then are
 * CF-Connecting-IP / X-Forwarded-For consulted. There are NO default trusted
 * ranges (loopback and RFC1918 included): an operator must opt in.
 */
final class ClientIp {

	/**
	 * @param array<string, mixed> $server       $_SERVER-shaped array.
	 * @param array<int, string>   $trustedCidrs CIDRs or bare IPs.
	 */
	public static function resolve( array $server, array $trustedCidrs ): string {
		$remote = self::valid( (string) ( $server['REMOTE_ADDR'] ?? '' ) );
		if ( $remote === '' ) {
			return '';
		}

		if ( $trustedCidrs === [] || ! self::inAny( $remote, $trustedCidrs ) ) {
			return $remote;
		}

		$cf = self::valid( (string) ( $server['HTTP_CF_CONNECTING_IP'] ?? '' ) );
		if ( $cf !== '' ) {
			return $cf;
		}

		$xff = (string) ( $server['HTTP_X_FORWARDED_FOR'] ?? '' );
		if ( $xff !== '' ) {
			// Walk right to left, skipping our own trusted proxies; the first
			// untrusted hop is the client. Left-most entries are spoofable.
			$hops = array_reverse( array_map( 'trim', explode( ',', $xff ) ) );
			foreach ( $hops as $hop ) {
				$ip = self::valid( $hop );
				if ( $ip === '' ) {
					return $remote;
				}
				if ( ! self::inAny( $ip, $trustedCidrs ) ) {
					return $ip;
				}
			}
		}

		return $remote;
	}

	/**
	 * Parse the ab_trusted_proxies option (array, or newline/comma separated
	 * string) into a list of valid CIDRs / IPs; invalid entries are dropped.
	 *
	 * @param mixed $raw
	 * @return array<int, string>
	 */
	public static function parseTrusted( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $entry ) {
			$entry = trim( (string) $entry );
			if ( $entry !== '' && self::isValidCidr( $entry ) ) {
				$out[] = $entry;
			}
		}

		return array_values( array_unique( $out ) );
	}

	public static function isValidCidr( string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );
		$bin   = @inet_pton( $parts[0] );
		if ( $bin === false ) {
			return false;
		}
		if ( count( $parts ) === 1 ) {
			return true;
		}

		return ctype_digit( $parts[1] ) && (int) $parts[1] <= strlen( $bin ) * 8;
	}

	/**
	 * @param array<int, string> $cidrs
	 */
	public static function inAny( string $ip, array $cidrs ): bool {
		foreach ( $cidrs as $cidr ) {
			if ( self::inCidr( $ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	public static function inCidr( string $ip, string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );
		$addr  = @inet_pton( $ip );
		$net   = @inet_pton( $parts[0] );

		if ( $addr === false || $net === false || strlen( $addr ) !== strlen( $net ) ) {
			return false;
		}

		$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
		if ( $bits < 0 || $bits > strlen( $net ) * 8 ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );
		if ( $bytes > 0 && substr( $addr, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			return false;
		}

		$rem = $bits % 8;
		if ( $rem === 0 ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rem ) ) & 0xFF;

		return ( ord( $addr[ $bytes ] ) & $mask ) === ( ord( $net[ $bytes ] ) & $mask );
	}

	/**
	 * Canonical form used for IP binding. IPv4 stays as is; an IPv4-mapped
	 * IPv6 address (::ffff:a.b.c.d) collapses to its IPv4; any other IPv6
	 * address is reduced to its /64 network prefix (as "<prefix>::/64"), so
	 * privacy-address rotation inside one /64 does not break a first_open
	 * link. Unparseable input gives ''.
	 */
	public static function normalize( string $ip ): string {
		$bin = @inet_pton( trim( $ip ) );
		if ( $bin === false ) {
			return '';
		}

		if ( strlen( $bin ) === 4 ) {
			return (string) inet_ntop( $bin );
		}

		if ( substr( $bin, 0, 12 ) === str_repeat( "\0", 10 ) . "\xff\xff" ) {
			return (string) inet_ntop( substr( $bin, 12 ) );
		}

		return inet_ntop( substr( $bin, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * Keyed hash of the normalized IP (HMAC-SHA256). Unparseable IP gives ''.
	 */
	public static function hash( string $ip, string $key ): string {
		$normal = self::normalize( $ip );
		if ( $normal === '' ) {
			return '';
		}

		return hash_hmac( 'sha256', $normal, $key );
	}

	private static function valid( string $ip ): string {
		$ip = trim( $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) !== false ? $ip : '';
	}
}
