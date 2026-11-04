<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Core\Db;

/**
 * Dynamic Client Registration (RFC 7591) for the MCP OAuth server (#239),
 * alongside CIMD: a public client (`token_endpoint_auth_method: none`, PKCE
 * S256) POSTs its metadata to /oauth/register and receives an opaque
 * client_id it then uses exactly like a CIMD URL — the consent screen,
 * exact redirect-URI matching and the token endpoint are shared.
 *
 * Hardening (contract: OAuth/DCR):
 *
 *  - redirect_uris is REQUIRED here (RFC 7591 makes it optional) and every
 *    URI is enforced by EXACT string match at authorize time. Schemes:
 *    http(s) (host required) or an app-specific scheme (cursor://, …),
 *    but never javascript:/data:/vbscript:.
 *  - metadata limits: at most MAX_REDIRECT_URIS redirect URIs, name/URI
 *    length caps, read-only RFC 7591 response fields in a request are
 *    rejected (invalid_client_metadata).
 *  - rate limit: at most `max` registrations per IP per `window` seconds
 *    (transient counter), plus a site-wide cap on live registrations.
 *    Both are curator-configurable (options + filters, see maxTotal() and
 *    rateLimit()).
 *  - expiry: a registration lives ttlSeconds() from its LAST issued grant
 *    (noteGrant() slides it, mirroring #225's sliding refresh tokens) — an
 *    unused client expires; resolve() fails closed on expiry/revocation.
 *  - revocation: RFC 7592-style — the registration response carries a
 *    registration_access_token; DELETE /oauth/register/<client_id> with
 *    that token removes the client AND revokes every grant it had.
 *    Curators can also revoke from wp-admin (ProfileUi).
 *  - secrets: only the sha256 of the registration access token is stored;
 *    the plaintext is returned once, like TokenStore's tokens.
 */
final class Dcr {

	public const CLIENT_ID_PREFIX = 'dcr_';

	/** The REST route base for registration and RFC 7592 client management. */
	public const ROUTE_BASE = '/oauth/register';

	public const MAX_REDIRECT_URIS = 10;
	public const MAX_NAME_LENGTH   = 255;
	public const MAX_URI_LENGTH    = 2048;
	public const MAX_SCOPE_LENGTH  = 512;

	public const DEFAULT_MAX_TOTAL     = 100;
	public const DEFAULT_RATE_MAX      = 5;
	public const DEFAULT_RATE_WINDOW   = 600; // 10 minutes, seconds.

	/** Daily cron (scheduled in Module::activate) that deletes dead rows. */
	public const PRUNE_HOOK = 'ab_oauth_dcr_prune';

	public static function table(): string {
		return Db::table('oauth_clients');
	}

	public static function isManaged(string $clientId): bool {
		return str_starts_with($clientId, self::CLIENT_ID_PREFIX);
	}

	/**
	 * The maximum number of live (non-revoked, non-expired) registrations
	 * this site accepts. Curator-configurable: the ab_oauth_dcr_max_total
	 * option overrides the DEFAULT_MAX_TOTAL default; the
	 * agent_brain/oauth_dcr_max_total filter gets the final word.
	 */
	public static function maxTotal(): int {
		$max = (int) get_option('ab_oauth_dcr_max_total', self::DEFAULT_MAX_TOTAL);

		return (int) apply_filters('agent_brain/oauth_dcr_max_total', max(0, $max));
	}

	/**
	 * Per-IP rate limit as ['max' => int, 'window' => int] (registrations
	 * per window seconds). Curator-configurable via the
	 * ab_oauth_dcr_rate_max / ab_oauth_dcr_rate_window options and the
	 * agent_brain/oauth_dcr_rate_limit filter.
	 *
	 * @return array{max:int, window:int}
	 */
	public static function rateLimit(): array {
		$max    = max(1, (int) get_option('ab_oauth_dcr_rate_max', self::DEFAULT_RATE_MAX));
		$window = max(60, (int) get_option('ab_oauth_dcr_rate_window', self::DEFAULT_RATE_WINDOW));

		$limited = apply_filters('agent_brain/oauth_dcr_rate_limit', ['max' => $max, 'window' => $window]);

		if (is_array($limited)) {
			$max    = max(1, (int) ($limited['max'] ?? $max));
			$window = max(60, (int) ($limited['window'] ?? $window));
		}

		return ['max' => $max, 'window' => $window];
	}

	/**
	 * How long a registered client lives after its last issued grant, in
	 * seconds (sliding). Curator-configurable via the ab_oauth_dcr_ttl_days
	 * option (whole days) and the agent_brain/oauth_dcr_ttl_seconds filter.
	 */
	public static function ttlSeconds(): int {
		$days = max(1, (int) get_option('ab_oauth_dcr_ttl_days', 30));

		return (int) apply_filters('agent_brain/oauth_dcr_ttl_seconds', $days * DAY_IN_SECONDS);
	}

	/**
	 * Registers a new public client (RFC 7591 §3). $metadata is the decoded
	 * JSON body of the POST; $ip attributes the registration for the rate
	 * limit ('' skips rate limiting, e.g. when no address is known).
	 *
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>|\WP_Error the 201 registration response on success.
	 */
	public static function register(array $metadata, string $ip = '') {
		$validated = self::validateMetadata($metadata);
		if (is_wp_error($validated)) {
			Audit::emit(0, 'oauth.client_register', 'denied', ['reason' => $validated->get_error_code()]);

			return $validated;
		}

		if ($ip !== '' && ! self::rateLimitAllows($ip)) {
			Audit::emit(0, 'oauth.client_register', 'denied', ['reason' => 'rate_limited']);

			return new \WP_Error('ab_oauth_dcr_rate_limited', 'Too many registrations from this address. Try again later.', ['status' => 429]);
		}

		if (self::liveCount() >= self::maxTotal()) {
			Audit::emit(0, 'oauth.client_register', 'denied', ['reason' => 'total_cap']);

			return new \WP_Error('ab_oauth_dcr_total_cap', 'This site is not accepting new AI client registrations.', ['status' => 429]);
		}

		global $wpdb;

		$clientId = self::CLIENT_ID_PREFIX . bin2hex(random_bytes(16));
		$rat      = self::randomToken();
		$now      = time();
		$expires  = $now + self::ttlSeconds();

		$inserted = $wpdb->insert(
			self::table(),
			[
				'client_id'     => $clientId,
				'client_name'   => $validated['client_name'],
				'client_uri'    => $validated['client_uri'],
				'redirect_uris' => (string) wp_json_encode($validated['redirect_uris']),
				'rat_hash'      => hash('sha256', $rat),
				'created'       => gmdate('Y-m-d H:i:s', $now),
				'expires'       => gmdate('Y-m-d H:i:s', $expires),
				'revoked'       => 0,
			],
			['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
		);

		if ($inserted === false || (int) $inserted < 1) {
			return new \WP_Error('ab_oauth_dcr_store_failed', 'Could not store the client registration.', ['status' => 500]);
		}

		if ($ip !== '') {
			self::recordRateLimitHit($ip);
		}

		Audit::emit(0, 'oauth.client_register', 'ok', ['client_id' => $clientId, 'client_name' => $validated['client_name']]);

		return self::responseFor([
			'client_id'      => $clientId,
			'client_name'    => $validated['client_name'],
			'client_uri'     => $validated['client_uri'],
			'redirect_uris'  => $validated['redirect_uris'],
			'created'        => gmdate('Y-m-d H:i:s', $now),
			'expires'        => gmdate('Y-m-d H:i:s', $expires),
		], $rat);
	}

	/**
	 * Resolves a client_id at authorize/token time. Returns null when the
	 * id is not one of ours (caller falls back to CIMD), a normalized
	 * client document when valid, or a WP_Error when the registration is
	 * unknown, revoked or expired (fail closed).
	 *
	 * @return array{client_id:string, client_name:string, client_uri:string, redirect_uris:array<int, string>}|\WP_Error|null
	 */
	public static function resolve(string $clientId) {
		if (! self::isManaged($clientId)) {
			return null;
		}

		$row = self::row($clientId);
		if (! is_array($row) || (int) $row['revoked'] === 1) {
			return new \WP_Error('ab_oauth_invalid_client', 'Unknown or revoked client_id.', ['status' => 400]);
		}

		if (strtotime((string) $row['expires'] . ' UTC') <= time()) {
			return new \WP_Error('ab_oauth_dcr_client_expired', 'This client registration has expired; register the client again.', ['status' => 400]);
		}

		return self::documentFor($row);
	}

	/**
	 * RFC 7592 read: the current client configuration, gated by the
	 * registration access token.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function configuration(string $clientId, string $rat) {
		if (! self::verifyAccessToken($clientId, $rat)) {
			Audit::emit(0, 'oauth.client_read', 'denied', ['client_id' => $clientId, 'reason' => 'bad_registration_access_token']);

			return new \WP_Error('ab_oauth_dcr_unauthorized', 'Invalid registration access token.', ['status' => 403]);
		}

		$row = self::row($clientId);
		if (! is_array($row) || (int) $row['revoked'] === 1) {
			return new \WP_Error('ab_oauth_invalid_client', 'Unknown or revoked client_id.', ['status' => 404]);
		}

		return self::responseFor($row, $rat);
	}

	/**
	 * Revokes a registration and every grant issued to it. With $rat set
	 * this is an RFC 7592 self-revocation (the token must match); without
	 * it, a curator action from wp-admin (ProfileUi).
	 */
	public static function revoke(string $clientId, ?string $rat = null): bool {
		if ($rat !== null && ! self::verifyAccessToken($clientId, $rat)) {
			Audit::emit(0, 'oauth.client_revoke', 'denied', ['client_id' => $clientId, 'reason' => 'bad_registration_access_token']);

			return false;
		}

		global $wpdb;

		$updated = $wpdb->update(
			self::table(),
			['revoked' => 1, 'expires' => gmdate('Y-m-d H:i:s', time())],
			['client_id' => $clientId, 'revoked' => 0],
			['%d', '%s'],
			['%s', '%d']
		);

		if ($updated === false || (int) $updated < 1) {
			return false;
		}

		// The client is gone: its tokens must stop working too.
		TokenStore::revokeAllForClient($clientId);

		Audit::emit(get_current_user_id(), 'oauth.client_revoke', 'ok', ['client_id' => $clientId]);

		return true;
	}

	/**
	 * Slides a live registration's expiry: called whenever this client
	 * completes a grant, so a used client never ages out (mirrors #225's
	 * sliding refresh-token expiry).
	 */
	public static function noteGrant(string $clientId): void {
		if (! self::isManaged($clientId)) {
			return;
		}

		global $wpdb;

		$wpdb->update(
			self::table(),
			['expires' => gmdate('Y-m-d H:i:s', time() + self::ttlSeconds())],
			['client_id' => $clientId, 'revoked' => 0],
			['%s'],
			['%s', '%d']
		);
	}

	/**
	 * Every live registration, for the wp-admin listing (ProfileUi).
	 *
	 * @return array<int, array{client_id:string, client_name:string, client_uri:string, redirect_uris:array<int, string>, created:string, expires:string, grants:int}>
	 */
	public static function listClients(): array {
		global $wpdb;

		$table = self::table();
		$now   = gmdate('Y-m-d H:i:s');

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = $wpdb->get_results(
			Db::prepare("SELECT * FROM {$table} WHERE revoked = 0 AND expires > %s ORDER BY created ASC", [$now]),
			ARRAY_A
		);
		// phpcs:enable

		$clients = [];
		foreach ((array) $rows as $row) {
			if (! is_array($row)) {
				continue;
			}

			$doc         = self::documentFor($row);
			$doc['created'] = (string) $row['created'];
			$doc['expires'] = (string) $row['expires'];
			$doc['grants']  = count(TokenStore::grantsForClient((string) $row['client_id']));

			$clients[] = $doc;
		}

		return $clients;
	}

	/**
	 * Deletes registrations that are expired or revoked-and-past-expiry
	 * (the daily PRUNE_HOOK cron). Returns the number of rows removed.
	 */
	public static function pruneExpired(): int {
		global $wpdb;

		$now = gmdate('Y-m-d H:i:s');

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$deleted = $wpdb->query(
			Db::prepare('DELETE FROM ' . self::table() . ' WHERE expires <= %s', [$now])
		);
		// phpcs:enable

		return is_numeric($deleted) ? (int) $deleted : 0;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array{client_name:string, client_uri:string, redirect_uris:array<int, string>}|\WP_Error
	 */
	private static function validateMetadata(array $metadata) {
		// Read-only response fields must not appear in a registration
		// request (RFC 7591 §3): they are chosen by the server.
		foreach (['client_id', 'client_secret', 'registration_access_token', 'registration_client_uri', 'client_id_issued_at', 'client_secret_expires_at'] as $readOnly) {
			if (array_key_exists($readOnly, $metadata)) {
				return new \WP_Error('invalid_client_metadata', sprintf('%s is a read-only field and must not be sent in a registration request.', $readOnly), ['status' => 400]);
			}
		}

		$redirectUris = $metadata['redirect_uris'] ?? null;
		if (! is_array($redirectUris) || $redirectUris === []) {
			return new \WP_Error('invalid_client_metadata', 'redirect_uris is required and must be a non-empty array.', ['status' => 400]);
		}

		if (count($redirectUris) > self::MAX_REDIRECT_URIS) {
			return new \WP_Error('invalid_client_metadata', sprintf('At most %d redirect URIs are allowed.', self::MAX_REDIRECT_URIS), ['status' => 400]);
		}

		foreach ($redirectUris as $uri) {
			if (! is_string($uri) || ! self::isValidRedirectUri($uri)) {
				return new \WP_Error('invalid_redirect_uri', sprintf('redirect_uris entries must be absolute URIs of at most %d characters; http(s) URIs need a host, and script schemes are not allowed.', self::MAX_URI_LENGTH), ['status' => 400]);
			}
		}

		$clientName = isset($metadata['client_name']) ? (string) $metadata['client_name'] : '';
		if (strlen($clientName) > self::MAX_NAME_LENGTH) {
			return new \WP_Error('invalid_client_metadata', sprintf('client_name is limited to %d characters.', self::MAX_NAME_LENGTH), ['status' => 400]);
		}

		$clientUri = isset($metadata['client_uri']) ? (string) $metadata['client_uri'] : '';
		if ($clientUri !== '' && ! self::isHttpUrl($clientUri)) {
			return new \WP_Error('invalid_client_metadata', 'client_uri must be an absolute http(s) URL.', ['status' => 400]);
		}

		$scope = isset($metadata['scope']) ? (string) $metadata['scope'] : '';
		if (strlen($scope) > self::MAX_SCOPE_LENGTH) {
			return new \WP_Error('invalid_client_metadata', 'scope is too long.', ['status' => 400]);
		}

		$authMethod = $metadata['token_endpoint_auth_method'] ?? null;
		if ($authMethod !== null && $authMethod !== 'none') {
			return new \WP_Error('invalid_client_metadata', 'Only token_endpoint_auth_method "none" (public clients) is supported.', ['status' => 400]);
		}

		$grantTypes = $metadata['grant_types'] ?? null;
		if (is_array($grantTypes)) {
			foreach ($grantTypes as $grant) {
				if (! in_array($grant, ['authorization_code', 'refresh_token'], true)) {
					return new \WP_Error('invalid_client_metadata', 'Only the authorization_code and refresh_token grant types are supported.', ['status' => 400]);
				}
			}
		}

		$responseTypes = $metadata['response_types'] ?? null;
		if (is_array($responseTypes) && array_values($responseTypes) !== ['code']) {
			return new \WP_Error('invalid_client_metadata', 'Only the "code" response type is supported.', ['status' => 400]);
		}

		return [
			'client_name'   => $clientName,
			'client_uri'    => $clientUri,
			'redirect_uris' => array_values($redirectUris),
		];
	}

	/**
	 * An absolute redirect URI: any scheme in the RFC 3986 sense except the
	 * script schemes, with http(s) requiring a host. Custom app schemes
	 * (cursor://callback, vscode://…, com.example.app:/oauth) are how
	 * desktop MCP apps come back from the browser, so they are allowed;
	 * relative references and javascript: are not.
	 */
	private static function isValidRedirectUri(string $uri): bool {
		if ($uri === '' || strlen($uri) > self::MAX_URI_LENGTH) {
			return false;
		}

		if (! preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $uri, $matches)) {
			return false;
		}

		$scheme = strtolower($matches[1]);
		if (in_array($scheme, ['javascript', 'data', 'vbscript'], true)) {
			return false;
		}

		if ($scheme === 'http' || $scheme === 'https') {
			$host = wp_parse_url($uri, PHP_URL_HOST);

			return is_string($host) && $host !== '';
		}

		return true;
	}

	private static function isHttpUrl(string $url): bool {
		if (strlen($url) > self::MAX_URI_LENGTH) {
			return false;
		}

		$scheme = wp_parse_url($url, PHP_URL_SCHEME);
		if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
			return false;
		}

		$host = wp_parse_url($url, PHP_URL_HOST);

		return is_string($host) && $host !== '';
	}

	private static function rateLimitAllows(string $ip): bool {
		$limit = self::rateLimit();
		$hits  = (int) get_transient(self::rateLimitKey($ip));

		return $hits < $limit['max'];
	}

	private static function recordRateLimitHit(string $ip): void {
		$limit = self::rateLimit();
		$key   = self::rateLimitKey($ip);
		$hits  = (int) get_transient($key);

		set_transient($key, $hits + 1, $limit['window']);
	}

	private static function rateLimitKey(string $ip): string {
		return 'ab_oauth_dcr_rl_' . hash('sha256', $ip);
	}

	private static function liveCount(): int {
		global $wpdb;

		$table = self::table();
		$now   = gmdate('Y-m-d H:i:s');

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$count = $wpdb->get_var(
			Db::prepare("SELECT COUNT(*) FROM {$table} WHERE revoked = 0 AND expires > %s", [$now])
		);
		// phpcs:enable

		return is_numeric($count) ? (int) $count : 0;
	}

	private static function verifyAccessToken(string $clientId, string $rat): bool {
		$row = self::row($clientId);

		return is_array($row)
			&& (int) $row['revoked'] === 0
			&& is_string($row['rat_hash'] ?? null)
			&& hash_equals((string) $row['rat_hash'], hash('sha256', $rat));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function row(string $clientId): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$row = $wpdb->get_row(
			Db::prepare("SELECT * FROM {$table} WHERE client_id = %s", [$clientId]),
			ARRAY_A
		);
		// phpcs:enable

		return is_array($row) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{client_id:string, client_name:string, client_uri:string, redirect_uris:array<int, string>}
	 */
	private static function documentFor(array $row): array {
		$clientId = (string) $row['client_id'];
		$raw      = $row['redirect_uris'] ?? [];
		$uris     = is_array($raw) ? $raw : json_decode((string) $raw, true);

		return [
			'client_id'     => $clientId,
			'client_name'   => is_string($row['client_name'] ?? null) && $row['client_name'] !== '' ? (string) $row['client_name'] : $clientId,
			'client_uri'    => (string) ($row['client_uri'] ?? ''),
			'redirect_uris' => is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [],
		];
	}

	/**
	 * The RFC 7591 §3.2.1 / RFC 7592 registration response for one stored
	 * client. $rat is the plaintext registration access token, included
	 * only in responses to the registering client (it is never stored in
	 * plaintext).
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function responseFor(array $row, string $rat): array {
		$doc = self::documentFor($row);

		$issuedAt = strtotime(((string) $row['created']) . ' UTC');

		return array_merge($doc, [
			'client_id_issued_at'          => $issuedAt !== false ? $issuedAt : time(),
			'client_secret_expires_at'     => 0, // Public client: no secret, never expires.
			'token_endpoint_auth_method'   => 'none',
			'response_types'               => ['code'],
			'grant_types'                  => ['authorization_code', 'refresh_token'],
			'registration_access_token'    => $rat,
			'registration_client_uri'      => rest_url(self::ROUTE_BASE . '/' . $doc['client_id']),
		]);
	}

	private static function randomToken(): string {
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}
}
