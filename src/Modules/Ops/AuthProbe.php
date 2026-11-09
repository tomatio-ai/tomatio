<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Ops;

use AgentBrain\Core\RestNamespaces;

/**
 * OPS-2 Authorization-header probe: a tiny REST route that reports whether
 * the `Authorization` header reached PHP at all (some hosts strip it before
 * it gets to $_SERVER, which silently breaks MCP bearer-token auth). We
 * probe our own site via a loopback `wp_remote_post()` carrying a marker
 * Authorization header, then check whether the route saw it.
 *
 * The route itself discloses nothing sensitive: it only echoes back
 * booleans for whether HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION were
 * present, never the header value.
 */
final class AuthProbe {

	public const NAMESPACE = 'agent-brain/v1';
	public const ROUTE     = '/ops/auth-probe';

	public static function registerRoutes(): void {
		RestNamespaces::register(
			
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [self::class, 'handle'],
				'permission_callback' => '__return_true',
			]
		);
	}

	public static function handle(\WP_REST_Request $request): \WP_REST_Response {
		return new \WP_REST_Response([
			'authorization_header_present' => isset($_SERVER['HTTP_AUTHORIZATION']),
			'redirect_header_present'      => isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
		], 200);
	}

	/**
	 * Run the loopback probe. Returns true when either header arrived,
	 * false when neither did, or a WP_Error when the loopback request
	 * itself failed (host/firewall blocking loopback, unrelated to the
	 * Authorization-header question).
	 *
	 * @return bool|\WP_Error
	 */
	public static function run() {
		$url = rest_url(self::NAMESPACE . self::ROUTE);

		$response = wp_remote_post($url, [
			'timeout' => 5,
			'headers' => [
				'Authorization' => 'Bearer ab-ops-self-check-probe',
			],
			'body'    => [],
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			return new \WP_Error(
				'ab_probe_failed',
				sprintf('Auth probe route returned HTTP %d.', $code),
				['status' => $code]
			);
		}

		$body = json_decode((string) wp_remote_retrieve_body($response), true);

		if (! is_array($body)) {
			return new \WP_Error('ab_probe_failed', 'Auth probe route returned an unreadable body.');
		}

		return (bool) ($body['authorization_header_present'] ?? false)
			|| (bool) ($body['redirect_header_present'] ?? false);
	}

	/**
	 * .htaccess snippet offered to curators when the probe reports the
	 * header did not arrive (typical on Apache/mod_fastcgi/mod_php setups
	 * that don't forward Authorization by default).
	 */
	public static function htaccessSnippet(): string {
		return "RewriteEngine On\n" .
			"RewriteCond %{HTTP:Authorization} .\n" .
			"RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n";
	}
}
