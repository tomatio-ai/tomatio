<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * Redirect URI matching for the authorize step.
 *
 * A redirect_uri must match a registered one exactly, with one exception from
 * RFC 8252 section 7.3: a native app on a loopback address picks a free port at
 * run time, so a loopback `http` redirect may differ from the registered one in
 * the port alone. Scheme, host, path and query must still be equal, and the
 * host must be literally `localhost`, `127.0.0.1` or `[::1]` (never a lookalike).
 */
final class RedirectUri {

	private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

	/**
	 * Whether a URI host is literally a loopback name (never a lookalike such
	 * as `localhost.evil.example`). Case-insensitive; an IPv6 host keeps its
	 * brackets, as parse_url() returns it.
	 */
	public static function isLoopbackHost(string $host): bool {
		return in_array(strtolower($host), self::LOOPBACK_HOSTS, true);
	}

	/**
	 * @param array<int, string> $registered The client's registered redirect URIs.
	 */
	public static function matches(string $requested, array $registered): bool {
		if ($requested === '') {
			return false;
		}

		if (in_array($requested, $registered, true)) {
			return true;
		}

		$want = self::loopbackParts($requested);
		if ($want === null) {
			return false;
		}

		foreach ($registered as $candidate) {
			if (! is_string($candidate)) {
				continue;
			}
			$have = self::loopbackParts($candidate);
			if ($have !== null && $have === $want) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The parts that must be equal (everything but the port) for an `http`
	 * loopback URI, or null when the URI is not one, or is malformed, or
	 * carries credentials, a fragment or an out-of-range port.
	 *
	 * @return array{host:string, path:string, query:string}|null
	 */
	private static function loopbackParts(string $uri): ?array {
		$parts = parse_url($uri);
		if (! is_array($parts)) {
			return null;
		}

		if (($parts['scheme'] ?? '') !== 'http') {
			return null;
		}

		if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
			return null;
		}

		$host = strtolower((string) ($parts['host'] ?? ''));
		if (! in_array($host, self::LOOPBACK_HOSTS, true)) {
			return null;
		}

		// parse_url() already returns false for a port above 65535 or a non-number;
		// it does return 0 for ":0", which is not a usable port.
		if (isset($parts['port']) && $parts['port'] < 1) {
			return null;
		}

		return [
			'host'  => $host,
			'path'  => (string) ($parts['path'] ?? ''),
			'query' => (string) ($parts['query'] ?? ''),
		];
	}
}
