<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

use AgentBrain\Modules\OAuth\IpGuard;

/**
 * Validates the base URL of the OpenAI-compatible endpoint (SSRF guard).
 *
 * Normal mode: https only, no credentials in the URL, and the host must
 * resolve only to public addresses (no loopback, private, link-local or
 * metadata ranges), so a curator cannot point the site at its own network.
 *
 * Explicit local mode (the owner ticks "this endpoint is on my own network"):
 * private and loopback targets are allowed, over http or https, for a local
 * server such as Ollama or LM Studio. A host that resolves to any public
 * address still has to be https, so a key never crosses the internet in
 * clear text.
 *
 * check() is pure apart from the resolver, which tests inject.
 */
final class UrlGuard {

	public const ERR_EMPTY         = 'empty';
	public const ERR_INVALID       = 'invalid_url';
	public const ERR_SCHEME        = 'scheme';
	public const ERR_HTTPS         = 'https_required';
	public const ERR_USERINFO      = 'userinfo';
	public const ERR_QUERY         = 'query_or_fragment';
	public const ERR_UNRESOLVABLE  = 'unresolvable';
	public const ERR_PRIVATE       = 'private_network';

	/**
	 * Returns an ERR_* code, or null when the URL is acceptable.
	 *
	 * @param callable(string): array<int, string>|null $resolver host => IP list
	 */
	public static function check(string $url, bool $allowLocal, ?callable $resolver = null): ?string {
		return self::inspect($url, $allowLocal, $resolver)['error'];
	}

	/**
	 * @param callable(string): array<int, string>|null $resolver
	 * @return array{error: ?string, ips: array<int, string>}
	 */
	public static function inspect(string $url, bool $allowLocal, ?callable $resolver = null): array {
		$fail = static fn( string $code ): array => [ 'error' => $code, 'ips' => [] ];

		$url = trim($url);
		if ($url === '') {
			return $fail(self::ERR_EMPTY);
		}

		$parts = parse_url($url);
		if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
			return $fail(self::ERR_INVALID);
		}

		$scheme = strtolower($parts['scheme']);
		if ($scheme !== 'https' && $scheme !== 'http') {
			return $fail(self::ERR_SCHEME);
		}

		if (isset($parts['user']) || isset($parts['pass'])) {
			return $fail(self::ERR_USERINFO);
		}

		if (isset($parts['query']) || isset($parts['fragment'])) {
			return $fail(self::ERR_QUERY);
		}

		$host = strtolower(trim($parts['host'], '[]'));
		$ips  = filter_var($host, FILTER_VALIDATE_IP) !== false
			? [ $host ]
			: self::resolve($host, $resolver);

		if ($ips === []) {
			return $fail(self::ERR_UNRESOLVABLE);
		}

		$allPublic = IpGuard::allPublic($ips);
		$anyPublic = false;
		foreach ($ips as $ip) {
			if (IpGuard::isPublic($ip)) {
				$anyPublic = true;
			}
		}

		if (! $allowLocal) {
			if ($scheme !== 'https') {
				return $fail(self::ERR_HTTPS);
			}
			if (! $allPublic) {
				return $fail(self::ERR_PRIVATE);
			}
		} elseif ($scheme === 'http' && $anyPublic) {
			return $fail(self::ERR_HTTPS);
		}

		return [ 'error' => null, 'ips' => $ips ];
	}

	/**
	 * Trims and removes trailing slashes.
	 */
	public static function normalize(string $url): string {
		return rtrim(trim($url), '/');
	}

	/**
	 * Plain-language wording for an ERR_* code.
	 */
	public static function message(string $code): string {
		switch ($code) {
			case self::ERR_EMPTY:
				return __('Enter the endpoint base URL.', 'agent-brain');
			case self::ERR_SCHEME:
				return __('The endpoint URL must start with https://.', 'agent-brain');
			case self::ERR_HTTPS:
				return __('The endpoint URL must use https://. Plain http:// is only allowed for a server on your own network, with "This endpoint is on my own network" ticked.', 'agent-brain');
			case self::ERR_USERINFO:
				return __('Do not put a username or password in the URL; use the API key field.', 'agent-brain');
			case self::ERR_QUERY:
				return __('The endpoint URL must not contain a query string or fragment.', 'agent-brain');
			case self::ERR_UNRESOLVABLE:
				return __('The endpoint host name could not be resolved.', 'agent-brain');
			case self::ERR_PRIVATE:
				return __('That address is on a private or internal network. Tick "This endpoint is on my own network" only if you run the server yourself.', 'agent-brain');
			default:
				return __('The endpoint URL is not valid.', 'agent-brain');
		}
	}

	/**
	 * @param callable(string): array<int, string>|null $resolver
	 * @return array<int, string>
	 */
	public static function resolve(string $host, ?callable $resolver = null): array {
		if ($resolver !== null) {
			return array_values(array_filter($resolver($host), 'is_string'));
		}

		if (function_exists('apply_filters') && has_filter('agent_brain/endpoint_resolve_host')) {
			$resolved = apply_filters('agent_brain/endpoint_resolve_host', null, $host);
			if (is_array($resolved)) {
				return array_values(array_filter($resolved, 'is_string'));
			}
		}

		$ips = [];
		if (function_exists('dns_get_record')) {
			$records = @dns_get_record($host, DNS_A + DNS_AAAA);
			foreach (is_array($records) ? $records : [] as $record) {
				if (isset($record['ip'])) {
					$ips[] = (string) $record['ip'];
				} elseif (isset($record['ipv6'])) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}

		if ($ips === []) {
			$resolved = gethostbyname($host);
			if ($resolved !== $host) {
				$ips[] = $resolved;
			}
		}

		return $ips;
	}
}
