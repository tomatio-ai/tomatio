<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Core\Db;

/**
 * Persistence for OAuth authorization codes and access/refresh tokens
 * (ID-5). Every secret (code, access token, refresh token) is stored ONLY
 * as its sha256 hash - the plaintext value is returned to the caller once,
 * at issuance, and never persisted or logged.
 *
 * Refresh rotation: each refresh token belongs to a "family" (a random id
 * shared by every token descended from one authorization). Using a token
 * marks it rotated and issues a new one in the same family. Presenting an
 * already-rotated (or already-revoked) refresh token revokes the entire
 * family - a strong signal that a refresh token was stolen and replayed.
 */
final class TokenStore {

	public const CODE_TTL_SECONDS = 60;
	public const ACCESS_TOKEN_TTL_SECONDS = 3600; // 1h
	/**
	 * 30 days from the LAST refresh, not from authorization: issueTokenPair()
	 * always stamps `expires` relative to now, and rotateRefreshToken()
	 * issues the next pair through issueTokenPair() — so every refresh
	 * (every use of the connection) slides the window and an actively used
	 * connector never expires (#225). Only a connection unused for 30 days
	 * actually expires. OAuth\Connections warns from 7 days before expiry.
	 */
	public const REFRESH_TOKEN_TTL_SECONDS = 30 * DAY_IN_SECONDS; // 30d, sliding

	public static function codesTable(): string {
		return Db::table('oauth_codes');
	}

	public static function tokensTable(): string {
		return Db::table('oauth_tokens');
	}

	/**
	 * Issues a one-time authorization code bound to user, client, redirect
	 * URI and PKCE challenge. Returns the plaintext code, or an error when
	 * the code was not stored (it would not exchange).
	 *
	 * @return string|\WP_Error
	 */
	public static function issueCode(
		int $userId,
		string $clientId,
		string $redirectUri,
		string $codeChallenge,
		string $scope,
		int $authorizedBy = 0
	): string|\WP_Error {
		global $wpdb;

		$code     = self::randomToken();
		$codeHash = hash('sha256', $code);
		$now      = current_time('mysql', true);
		$expires  = gmdate('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS);

		$stored = $wpdb->insert(
			self::codesTable(),
			[
				'code_hash'      => $codeHash,
				'user_id'        => $userId,
				'client_id'      => $clientId,
				'redirect_uri'   => $redirectUri,
				'code_challenge' => $codeChallenge,
				'scope'          => $scope,
				'used'           => 0,
				'created'        => $now,
				'expires'        => $expires,
				'authorized_by'  => max(0, $authorizedBy),
			],
			['%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d']
		);

		if ($stored === false || (int) $stored < 1) {
			return self::notStored('authorization code');
		}

		return $code;
	}

	/**
	 * Atomically consumes a one-time code: an UPDATE guarded by used=0 means
	 * two concurrent exchanges of the same code can never both succeed
	 * (affected-rows check), which is what makes reuse detection reliable
	 * instead of a check-then-act race.
	 *
	 * @return array{user_id:int, client_id:string, redirect_uri:string, code_challenge:string, scope:string, authorized_by:int}|\WP_Error
	 */
	public static function consumeCode(string $code, string $clientId, string $redirectUri) {
		global $wpdb;

		$codeHash = hash('sha256', $code);
		$table    = self::codesTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$row = $wpdb->get_row(
			Db::prepare("SELECT * FROM {$table} WHERE code_hash = %s", [$codeHash]),
			ARRAY_A
		);
		// phpcs:enable

		if (! is_array($row)) {
			return new \WP_Error('ab_oauth_invalid_grant', 'Authorization code is invalid.', ['status' => 400]);
		}

		$updated = $wpdb->update(
			$table,
			['used' => 1],
			['code_hash' => $codeHash, 'used' => 0],
			['%d'],
			['%s', '%d']
		);

		if ((int) $updated !== 1) {
			// Already used: this is a reuse attempt.
			return new \WP_Error('ab_oauth_code_reused', 'Authorization code has already been used.', ['status' => 400]);
		}

		if (strtotime((string) $row['expires'] . ' UTC') < time()) {
			return new \WP_Error('ab_oauth_invalid_grant', 'Authorization code has expired.', ['status' => 400]);
		}

		if (! hash_equals((string) $row['client_id'], $clientId)) {
			return new \WP_Error('ab_oauth_invalid_grant', 'client_id does not match the authorization code.', ['status' => 400]);
		}

		if (! hash_equals((string) $row['redirect_uri'], $redirectUri)) {
			return new \WP_Error('ab_oauth_invalid_grant', 'redirect_uri does not match the authorization code.', ['status' => 400]);
		}

		return [
			'user_id'        => (int) $row['user_id'],
			'client_id'      => (string) $row['client_id'],
			'redirect_uri'   => (string) $row['redirect_uri'],
			'code_challenge' => (string) $row['code_challenge'],
			'scope'          => (string) $row['scope'],
			'authorized_by'  => (int) ($row['authorized_by'] ?? 0),
		];
	}

	/**
	 * Stores and returns a new access and refresh token. A token that was
	 * not stored is never handed out: if either insert fails, the other is
	 * removed and an error is returned (#183).
	 *
	 * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, scope:string}|\WP_Error
	 */
	public static function issueTokenPair(int $userId, string $clientId, string $scope, ?string $familyId = null, int $authorizedBy = 0): array|\WP_Error {
		global $wpdb;

		$familyId = $familyId ?? wp_generate_uuid4();
		$now      = current_time('mysql', true);

		$access  = self::randomToken();
		$refresh = self::randomToken();

		$storedAccess = $wpdb->insert(
			self::tokensTable(),
			[
				'token_hash' => hash('sha256', $access),
				'token_type' => 'access',
				'user_id'    => $userId,
				'client_id'  => $clientId,
				'scope'      => $scope,
				'family_id'  => $familyId,
				'rotated'    => 0,
				'revoked'    => 0,
				'created'       => $now,
				'expires'       => gmdate('Y-m-d H:i:s', time() + self::ACCESS_TOKEN_TTL_SECONDS),
				'authorized_by' => max(0, $authorizedBy),
			],
			['%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d']
		);

		$storedRefresh = $wpdb->insert(
			self::tokensTable(),
			[
				'token_hash' => hash('sha256', $refresh),
				'token_type' => 'refresh',
				'user_id'    => $userId,
				'client_id'  => $clientId,
				'scope'      => $scope,
				'family_id'  => $familyId,
				'rotated'    => 0,
				'revoked'    => 0,
				'created'       => $now,
				'expires'       => gmdate('Y-m-d H:i:s', time() + self::REFRESH_TOKEN_TTL_SECONDS),
				'authorized_by' => max(0, $authorizedBy),
			],
			['%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d']
		);

		if ($storedAccess === false || (int) $storedAccess < 1 || $storedRefresh === false || (int) $storedRefresh < 1) {
			$wpdb->delete(self::tokensTable(), ['token_hash' => hash('sha256', $access)], ['%s']);
			$wpdb->delete(self::tokensTable(), ['token_hash' => hash('sha256', $refresh)], ['%s']);

			return self::notStored('token');
		}

		return [
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TOKEN_TTL_SECONDS,
			'scope'         => $scope,
		];
	}

	/**
	 * Exchanges a refresh token for a new access+refresh pair, rotating the
	 * refresh token. Reuse of an already-rotated or already-revoked refresh
	 * token revokes the whole family and fails closed.
	 *
	 * Sliding expiry (#225): the new pair's refresh token lives
	 * REFRESH_TOKEN_TTL_SECONDS from THIS exchange, so the window slides on
	 * every use; a connector the person actively uses never ages out.
	 *
	 * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, scope:string}|\WP_Error
	 */
	public static function rotateRefreshToken(string $refreshToken, string $clientId) {
		global $wpdb;

		$table     = self::tokensTable();
		$tokenHash = hash('sha256', $refreshToken);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$row = $wpdb->get_row(
			Db::prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s AND token_type = 'refresh'",
				[$tokenHash]
			),
			ARRAY_A
		);
		// phpcs:enable

		if (! is_array($row)) {
			return new \WP_Error('ab_oauth_invalid_grant', 'Refresh token is invalid.', ['status' => 400]);
		}

		if (! hash_equals((string) $row['client_id'], $clientId)) {
			return new \WP_Error('ab_oauth_invalid_grant', 'client_id does not match the refresh token.', ['status' => 400]);
		}

		if ((int) $row['revoked'] === 1 || (int) $row['rotated'] === 1) {
			// Reuse of a rotated/revoked refresh token: revoke the family.
			self::revokeFamily((string) $row['family_id']);

			return new \WP_Error('ab_oauth_refresh_reuse', 'Refresh token reuse detected; all tokens for this grant were revoked.', ['status' => 400]);
		}

		if (strtotime((string) $row['expires'] . ' UTC') < time()) {
			return new \WP_Error('ab_oauth_invalid_grant', 'Refresh token has expired.', ['status' => 400]);
		}

		// Atomically claim this refresh token for rotation (guards concurrent replay).
		$claimed = $wpdb->update(
			$table,
			['rotated' => 1],
			['token_hash' => $tokenHash, 'rotated' => 0],
			['%d'],
			['%s', '%d']
		);

		if ((int) $claimed !== 1) {
			self::revokeFamily((string) $row['family_id']);

			return new \WP_Error('ab_oauth_refresh_reuse', 'Refresh token reuse detected; all tokens for this grant were revoked.', ['status' => 400]);
		}

		return self::issueTokenPair((int) $row['user_id'], (string) $row['client_id'], (string) $row['scope'], (string) $row['family_id'], (int) ($row['authorized_by'] ?? 0));
	}

	public static function revokeFamily(string $familyId): void {
		global $wpdb;

		$wpdb->update(
			self::tokensTable(),
			['revoked' => 1],
			['family_id' => $familyId],
			['%d'],
			['%s']
		);
	}

	/**
	 * @return array{user_id:int, client_id:string, scope:string, authorized_by:int, family_id:string}|null
	 */
	public static function findValidAccessToken(string $accessToken): ?array {
		global $wpdb;

		$table = self::tokensTable();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$row   = $wpdb->get_row(
			Db::prepare(
				"SELECT user_id, client_id, scope, revoked, expires, authorized_by, family_id FROM {$table} WHERE token_hash = %s AND token_type = 'access'",
				[hash('sha256', $accessToken)]
			),
			ARRAY_A
		);
		// phpcs:enable

		if (! is_array($row)) {
			return null;
		}

		if ((int) $row['revoked'] === 1) {
			return null;
		}

		if (strtotime((string) $row['expires'] . ' UTC') < time()) {
			return null;
		}

		return [
			'user_id'       => (int) $row['user_id'],
			'client_id'     => (string) $row['client_id'],
			'scope'         => (string) $row['scope'],
			'authorized_by' => (int) ($row['authorized_by'] ?? 0),
			'family_id'     => (string) ($row['family_id'] ?? ''),
		];
	}

	/**
	 * Revokes a single token (access or refresh) by its plaintext value.
	 * Returns true only if a row existed and was updated.
	 */
	public static function revokeToken(string $token): bool {
		global $wpdb;

		$updated = $wpdb->update(
			self::tokensTable(),
			['revoked' => 1],
			['token_hash' => hash('sha256', $token)],
			['%d'],
			['%s']
		);

		return (int) $updated === 1;
	}

	/**
	 * Distinct, still-relevant (unrevoked) grants for a user, one row per
	 * client_id/family_id, for the wp-admin profile UI.
	 *
	 * @return array<int, array{family_id:string, client_id:string, scope:string, created:string, expires:string}>
	 */
	public static function grantsForUser(int $userId): array {
		global $wpdb;

		$table = self::tokensTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = $wpdb->get_results(
			Db::prepare(
				"SELECT family_id, client_id, scope, MIN(created) AS created, MAX(expires) AS expires
				 FROM {$table}
				 WHERE user_id = %d AND revoked = 0 AND token_type = 'refresh'
				 GROUP BY family_id, client_id, scope
				 ORDER BY MIN(created) DESC",
				[$userId]
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array($rows) ? $rows : [];
	}

	/**
	 * #629: unrevoked connections (one row per token family) that a person
	 * authorized for one of their agents ("Connect this AI as"), which
	 * carry the delegated-authority scope. Their tokens belong to the agent,
	 * so grantsForUser() of the person does not list them.
	 *
	 * @return array<int, array{family_id:string, user_id:int, client_id:string, scope:string, created:string, expires:string}>
	 */
	public static function delegatedGrantsAuthorizedBy(int $personId): array {
		global $wpdb;

		$table = self::tokensTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = $wpdb->get_results(
			Db::prepare(
				"SELECT family_id, user_id, client_id, scope, MIN(created) AS created, MAX(expires) AS expires
				 FROM {$table}
				 WHERE authorized_by = %d AND user_id <> %d AND revoked = 0 AND token_type = 'refresh'
				 GROUP BY family_id, user_id, client_id, scope
				 ORDER BY MIN(created) DESC",
				[$personId, $personId]
			),
			ARRAY_A
		);
		// phpcs:enable

		if (! is_array($rows)) {
			return [];
		}

		return array_values(array_filter($rows, static fn (array $row): bool => DelegatedAuthority::scopeGrants((string) $row['scope'])));
	}

	/**
	 * #629: removes one scope word from every token of a family that
	 * belongs to $personId: their own connection, or an agent connection
	 * they authorized. Access and refresh tokens alike, so the next request
	 * and every later refresh no longer carry it. Returns true when a row
	 * changed.
	 */
	public static function dropScopeForFamily(int $personId, string $familyId, string $word): bool {
		global $wpdb;

		$table = self::tokensTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = $wpdb->get_results(
			Db::prepare(
				"SELECT id, scope FROM {$table} WHERE family_id = %s AND (user_id = %d OR authorized_by = %d)",
				[$familyId, $personId, $personId]
			),
			ARRAY_A
		);
		// phpcs:enable

		$changed = false;
		foreach (is_array($rows) ? $rows : [] as $row) {
			$parts = preg_split('/\s+/', trim((string) $row['scope'])) ?: [];
			if (! in_array($word, $parts, true)) {
				continue;
			}

			$scope   = implode(' ', array_filter($parts, static fn (string $part): bool => $part !== '' && $part !== $word));
			$updated = $wpdb->update($table, ['scope' => $scope], ['id' => (int) $row['id']], ['%s'], ['%d']);
			$changed = $changed || (int) $updated > 0;
		}

		return $changed;
	}

	public static function revokeGrantForUser(int $userId, string $familyId): bool {
		global $wpdb;

		$table = self::tokensTable();

		// Ownership-scoped: only ever revokes rows that belong to $userId,
		// even if a caller somehow supplied a family_id belonging to someone else.
		$updated = $wpdb->update(
			$table,
			['revoked' => 1],
			['family_id' => $familyId, 'user_id' => $userId],
			['%d'],
			['%s', '%d']
		);

		return (int) $updated > 0;
	}

	/**
	 * @return array<int, array{family_id:string, user_id:int, client_id:string, scope:string, created:string}>
	 */
	public static function grantsForClient(string $clientId): array {
		global $wpdb;

		$table = self::tokensTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = $wpdb->get_results(
			Db::prepare(
				"SELECT family_id, user_id, client_id, scope, MIN(created) AS created
				 FROM {$table}
				 WHERE client_id = %s AND revoked = 0 AND token_type = 'refresh'
				 GROUP BY family_id, user_id, client_id, scope
				 ORDER BY MIN(created) DESC",
				[$clientId]
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array($rows) ? $rows : [];
	}

	/**
	 * Distinct active (unrevoked) clients across every user, for the
	 * curator-facing "revoke per client" admin page.
	 *
	 * @return array<int, array{client_id:string, grants:int}>
	 */
	public static function activeClients(): array {
		global $wpdb;

		$table = self::tokensTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no caller-controlled values.
		$rows = $wpdb->get_results(
			"SELECT client_id, COUNT(DISTINCT family_id) AS grants
			 FROM {$table}
			 WHERE revoked = 0 AND token_type = 'refresh'
			 GROUP BY client_id
			 ORDER BY client_id ASC",
			ARRAY_A
		);
		// phpcs:enable

		return is_array($rows) ? $rows : [];
	}

	public static function revokeAllForClient(string $clientId): int {
		global $wpdb;

		$updated = $wpdb->update(
			self::tokensTable(),
			['revoked' => 1],
			['client_id' => $clientId],
			['%d'],
			['%s']
		);

		return (int) $updated;
	}

	private static function notStored(string $what): \WP_Error {
		return new \WP_Error('ab_oauth_server_error', sprintf('The %s could not be stored; try again.', $what), ['status' => 500]);
	}

	private static function randomToken(): string {
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}
}
