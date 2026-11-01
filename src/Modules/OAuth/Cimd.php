<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * Client ID Metadata Document fetcher (ID-5). A CIMD client_id is itself an
 * https URL; the document at that URL declares the client's name, URI and
 * redirect_uris. Hardening here is MANDATORY (contract: OAuth/CIMD):
 *
 *  - https only, no redirects, 3s timeout, 5KB cap.
 *  - DNS is resolved ourselves (via the agent_brain/oauth_cimd_resolve_host
 *    filter, default gethostbyname/dns_get_record) and EVERY resolved
 *    address must be public (IpGuard) before the request is issued -
 *    rejects loopback/private/link-local/CGNAT/metadata targets and their
 *    IPv6 equivalents, including a hostname that resolves to a private IP.
 *  - required fields: client_id (must equal the fetch URL) and
 *    redirect_uris (non-empty array of strings).
 *  - cached (transient) with a TTL so repeated authorize requests for the
 *    same client don't refetch.
 */
final class Cimd {

	public const MAX_BYTES = 5 * 1024; // 5 KB
	public const TIMEOUT_SECONDS = 3;
	public const CACHE_TTL_SECONDS = 3600;

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function fetch(string $url) {
		$cacheKey = 'ab_oauth_cimd_' . md5($url);
		$cached   = get_transient($cacheKey);
		if (is_array($cached)) {
			return $cached;
		}

		$validatedIps = self::validateUrl($url);
		if (is_wp_error($validatedIps)) {
			return $validatedIps;
		}

		$response = self::fetchPinnedToValidatedIps($url, $validatedIps);

		if (is_wp_error($response)) {
			return new \WP_Error('ab_oauth_cimd_fetch_failed', 'Could not fetch client metadata document.');
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code >= 300 && $code < 400) {
			return new \WP_Error('ab_oauth_cimd_redirect', 'Client metadata document responses may not redirect.');
		}

		if ($code !== 200) {
			return new \WP_Error('ab_oauth_cimd_http_error', 'Client metadata document fetch returned HTTP ' . $code . '.');
		}

		$body = (string) wp_remote_retrieve_body($response);
		if (strlen($body) > self::MAX_BYTES) {
			return new \WP_Error('ab_oauth_cimd_too_large', 'Client metadata document exceeds the 5KB size cap.');
		}

		$decoded = json_decode($body, true);
		if (! is_array($decoded)) {
			return new \WP_Error('ab_oauth_cimd_invalid_json', 'Client metadata document is not valid JSON.');
		}

		$validatedDoc = self::validateDocument($decoded, $url);
		if (is_wp_error($validatedDoc)) {
			return $validatedDoc;
		}

		set_transient($cacheKey, $validatedDoc, self::CACHE_TTL_SECONDS);

		return $validatedDoc;
	}

	/**
	 * Resolves the host and validates every resolved address is public.
	 *
	 * @return array<int, string>|\WP_Error the validated IP address(es), for the
	 *                                      caller to pin the actual connection to
	 *                                      (see fetchPinnedToValidatedIps()) - this
	 *                                      check is otherwise a TOCTOU no-op, since
	 *                                      the HTTP transport would re-resolve DNS
	 *                                      independently for the real connection.
	 */
	private static function validateUrl(string $url) {
		$parts = wp_parse_url($url);
		if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return new \WP_Error('ab_oauth_cimd_bad_url', 'client_id must be a well-formed URL.');
		}

		if (strtolower((string) $parts['scheme']) !== 'https') {
			return new \WP_Error('ab_oauth_cimd_https_required', 'client_id must use https.');
		}

		$host = (string) $parts['host'];
		$ips  = self::resolveHost($host);

		if (! IpGuard::allPublic($ips)) {
			return new \WP_Error('ab_oauth_cimd_ssrf_blocked', 'Client metadata document host does not resolve to a public address.');
		}

		return $ips;
	}

	/**
	 * Set only while/after a real `http_api_curl` callback has actually run
	 * for the most recent fetch (i.e. the curl transport was in fact used
	 * and the CURLOPT_RESOLVE pin was actually applied to the handle). This
	 * is a same-request-scoped static: fetchPinnedToValidatedIps() resets it
	 * before every call. It is diagnostic (read via lastFetchWasPinned(),
	 * e.g. by an Ops self-check), not a second gate on the response itself
	 * -- see fetchPinnedToValidatedIps()'s doc comment for why the hard gate
	 * lives entirely in the preflight check instead.
	 */
	private static bool $pinWasApplied = false;

	/**
	 * Issues the real HTTP request pinned to the already-validated IP
	 * address(es), so the underlying transport cannot independently
	 * re-resolve DNS and connect to a different (unvalidated) address
	 * between the check and the request (DNS rebinding / TOCTOU).
	 *
	 * Fails closed (ID-5 / audit finding): pinning is not "best effort" any
	 * more. Before issuing the request, pinningWillBeApplied() verifies
	 * pinning WILL be applied (curl extension + CURLOPT_RESOLVE available,
	 * and the curl transport is the one WordPress's HTTP API will actually
	 * select) — if not, the fetch is refused outright with a WP_Error and an
	 * audit event, instead of silently falling through to an unpinned
	 * request that leaves a DNS-rebinding window open.
	 *
	 * The `http_api_curl` hook additionally records (self::$pinWasApplied,
	 * see lastFetchWasPinned()) whether it was actually invoked against a
	 * real curl handle. That is surfaced for diagnostics rather than used to
	 * discard an otherwise-successful response: `pre_http_request` filters
	 * (used throughout this class's own test suite, and by any other code
	 * that fully short-circuits WP_Http::request()) legitimately bypass the
	 * transport layer — and therefore `http_api_curl` — entirely, which is
	 * not itself a pinning failure. The preflight check above is what
	 * actually stands between an attacker and an unpinned real network
	 * fetch.
	 *
	 * Uses CURLOPT_RESOLVE via the `http_api_curl` filter: this pins the TCP
	 * connection to the validated IP(s) while leaving the TLS SNI/hostname-
	 * verification target as the original hostname, so `sslverify` still
	 * checks the real certificate.
	 *
	 * @param array<int, string> $validatedIps
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function fetchPinnedToValidatedIps(string $url, array $validatedIps) {
		if (! self::pinningWillBeApplied()) {
			Audit::emit(0, 'oauth.cimd_pin_unavailable', 'denied', [
				'url'    => $url,
				'reason' => 'pinning_preflight_failed',
			]);

			return new \WP_Error(
				'ab_oauth_cimd_pin_unavailable',
				'Client metadata document fetch refused: IP pinning against DNS rebinding is unavailable in this environment.'
			);
		}

		$resolveEntry = self::buildCurlResolveEntry($url, $validatedIps);

		self::$pinWasApplied = false;

		$pinCurl = static function ($handle) use ($resolveEntry): void {
			if (defined('CURLOPT_RESOLVE')) {
				curl_setopt($handle, CURLOPT_RESOLVE, [$resolveEntry]);
				self::$pinWasApplied = true;
			}
		};

		add_action('http_api_curl', $pinCurl, 10, 1);

		try {
			return wp_remote_get($url, [
				'timeout'              => self::TIMEOUT_SECONDS,
				'redirection'          => 0,
				'sslverify'            => true,
				'limit_response_size'  => self::MAX_BYTES,
				'headers'              => ['Accept' => 'application/json'],
			]);
		} finally {
			remove_action('http_api_curl', $pinCurl, 10);
		}
	}

	/**
	 * Whether the `http_api_curl` hook actually fired (and therefore
	 * CURLOPT_RESOLVE was actually applied to a real curl handle) for the
	 * most recent fetch. False before any fetch has run in this request.
	 * Diagnostic only — see fetchPinnedToValidatedIps()'s doc comment.
	 */
	public static function lastFetchWasPinned(): bool {
		return self::$pinWasApplied;
	}

	/**
	 * Preflight check: will pinning actually be applied to the request
	 * WordPress is about to issue? True only when the curl extension and
	 * CURLOPT_RESOLVE are both available AND the Requests library will
	 * select its curl transport (the only transport this class knows how to
	 * pin) for the current request. Overridable via the
	 * `agent_brain/oauth_cimd_pinning_available` filter (default null =
	 * real detection) so tests can simulate unavailability of either
	 * condition without needing to fake the PHP curl extension itself.
	 */
	private static function pinningWillBeApplied(): bool {
		if (function_exists('apply_filters')) {
			$override = apply_filters('agent_brain/oauth_cimd_pinning_available', null);
			if (is_bool($override)) {
				return $override;
			}
		}

		if (! defined('CURLOPT_RESOLVE') || ! extension_loaded('curl') || ! function_exists('curl_setopt')) {
			return false;
		}

		return self::curlTransportSelected();
	}

	/**
	 * Whether WordPress's HTTP API (the Requests library) will select its
	 * curl transport for the current request. Delegates to WP_Http_Curl's
	 * own capability probe when available; if that class doesn't exist at
	 * all (WP not loaded, or a WordPress build that dropped it) there is no
	 * curl transport to pin against, so this fails closed to false.
	 */
	private static function curlTransportSelected(): bool {
		if (! class_exists('\WP_Http_Curl') || ! method_exists('\WP_Http_Curl', 'test')) {
			return false;
		}

		return (bool) \WP_Http_Curl::test([]);
	}

	/**
	 * Machine-readable pinning-availability status, for the Ops self-check
	 * (or any other diagnostic surface) to report without duplicating the
	 * detection logic. Deliberately has no side effects and performs no
	 * network I/O.
	 *
	 * @return array{available:bool, curl_extension:bool, curlopt_resolve:bool, curl_transport_selected:bool}
	 */
	public static function pinningStatus(): array {
		$curlExtension  = extension_loaded('curl') && function_exists('curl_setopt');
		$curloptResolve = defined('CURLOPT_RESOLVE');
		$transport      = self::curlTransportSelected();

		return [
			'available'                => self::pinningWillBeApplied(),
			'curl_extension'           => $curlExtension,
			'curlopt_resolve'          => $curloptResolve,
			'curl_transport_selected'  => $transport,
		];
	}

	/**
	 * Builds the `CURLOPT_RESOLVE` entry ("host:port:ip1,ip2") that pins the
	 * TCP connection for $url to the already-validated IP address(es),
	 * without touching the TLS SNI/hostname-verification target (which
	 * still uses $url's own host). Pure/no I/O so it is directly unit
	 * testable.
	 *
	 * @param array<int, string> $validatedIps
	 */
	public static function buildCurlResolveEntry(string $url, array $validatedIps): string {
		$host = (string) parse_url($url, PHP_URL_HOST);
		$port = parse_url($url, PHP_URL_PORT);
		$port = is_int($port) ? $port : 443;

		return sprintf('%s:%d:%s', $host, $port, implode(',', $validatedIps));
	}

	/**
	 * @return array<int, string>
	 */
	private static function resolveHost(string $host): array {
		if (function_exists('apply_filters') && has_filter('agent_brain/oauth_cimd_resolve_host')) {
			$resolved = apply_filters('agent_brain/oauth_cimd_resolve_host', null, $host);
			if (is_array($resolved)) {
				return array_values(array_filter($resolved, 'is_string'));
			}
		}

		// A bare IPv4/IPv6 literal host resolves to itself.
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return [$host];
		}

		$ips = [];

		if (function_exists('dns_get_record')) {
			$records = @dns_get_record($host, DNS_A + DNS_AAAA);
			if (is_array($records)) {
				foreach ($records as $record) {
					if (isset($record['ip'])) {
						$ips[] = (string) $record['ip'];
					} elseif (isset($record['ipv6'])) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
		}

		if ($ips === [] && function_exists('gethostbyname')) {
			$resolved = gethostbyname($host);
			if ($resolved !== $host) {
				$ips[] = $resolved;
			}
		}

		return $ips;
	}

	/**
	 * @param array<string, mixed> $doc
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function validateDocument(array $doc, string $fetchUrl) {
		$clientId = $doc['client_id'] ?? null;
		if (! is_string($clientId) || $clientId === '' || $clientId !== $fetchUrl) {
			return new \WP_Error('ab_oauth_cimd_client_id_mismatch', 'client_id field must exactly equal the fetch URL.');
		}

		$redirectUris = $doc['redirect_uris'] ?? null;
		if (! is_array($redirectUris) || $redirectUris === []) {
			return new \WP_Error('ab_oauth_cimd_missing_redirect_uris', 'redirect_uris is required and must be non-empty.');
		}

		foreach ($redirectUris as $uri) {
			if (! is_string($uri) || $uri === '') {
				return new \WP_Error('ab_oauth_cimd_invalid_redirect_uris', 'redirect_uris entries must be non-empty strings.');
			}
		}

		return [
			'client_id'      => $clientId,
			'redirect_uris'  => array_values($redirectUris),
			'client_name'    => isset($doc['client_name']) && is_string($doc['client_name']) ? $doc['client_name'] : $clientId,
			'client_uri'     => isset($doc['client_uri']) && is_string($doc['client_uri']) ? $doc['client_uri'] : '',
		];
	}
}
