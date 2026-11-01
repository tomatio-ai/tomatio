<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Core\RestNamespaces;

/**
 * REST surface for OAuth (ID-5): metadata mirrors of the .well-known
 * documents (for clients that prefer a namespaced fetch), the token
 * endpoint (code+PKCE exchange and refresh rotation), and revocation.
 * These are all public-client endpoints - token_endpoint_auth_methods is
 * "none" - so permission_callback is __return_true and every check happens
 * inside the handler against the request body itself.
 */
final class Rest {

	public const NAMESPACE = 'agent-brain/v1';

	public static function registerRoutes(): void {
		RestNamespaces::register( '/oauth/authorization-server-metadata', [
			'methods'             => 'GET',
			'callback'            => static fn () => new \WP_REST_Response(Metadata::authorizationServer(), 200),
			'permission_callback' => '__return_true',
		]);

		RestNamespaces::register( '/oauth/protected-resource-metadata', [
			'methods'             => 'GET',
			'callback'            => static fn () => new \WP_REST_Response(Metadata::protectedResource(), 200),
			'permission_callback' => '__return_true',
		]);

		RestNamespaces::register( '/oauth/token', [
			'methods'             => 'POST',
			'callback'            => [self::class, 'handleToken'],
			'permission_callback' => '__return_true',
		]);

		RestNamespaces::register( '/oauth/revoke', [
			'methods'             => 'POST',
			'callback'            => [self::class, 'handleRevoke'],
			'permission_callback' => '__return_true',
		]);

		RestNamespaces::register(Dcr::ROUTE_BASE, [
			'methods'             => 'POST',
			'callback'            => [self::class, 'handleRegister'],
			'permission_callback' => '__return_true',
		]);

		// RFC 7592 client configuration: read (GET) and delete/revoke
		// (DELETE), both gated by the registration access token.
		RestNamespaces::register(Dcr::ROUTE_BASE . '/(?P<client_id>[a-z0-9_]{4,80})', [
			[
				'methods'             => 'GET',
				'callback'            => [self::class, 'handleRegisterRead'],
				'permission_callback' => '__return_true',
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [self::class, 'handleRegisterDelete'],
				'permission_callback' => '__return_true',
			],
		]);
	}

	public static function handleToken(\WP_REST_Request $request): \WP_REST_Response {
		$grantType = (string) $request->get_param('grant_type');
		$clientId  = (string) $request->get_param('client_id');

		if ($grantType === 'authorization_code') {
			return self::handleAuthorizationCodeGrant($request, $clientId);
		}

		if ($grantType === 'refresh_token') {
			return self::handleRefreshGrant($request, $clientId);
		}

		return self::errorResponse('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.', 400);
	}

	private static function handleAuthorizationCodeGrant(\WP_REST_Request $request, string $clientId): \WP_REST_Response {
		$code         = (string) $request->get_param('code');
		$redirectUri  = (string) $request->get_param('redirect_uri');
		$codeVerifier = (string) $request->get_param('code_verifier');

		if ($code === '' || $redirectUri === '' || $clientId === '' || $codeVerifier === '') {
			return self::errorResponse('invalid_request', 'code, redirect_uri, client_id and code_verifier are all required.', 400);
		}

		$consumed = TokenStore::consumeCode($code, $clientId, $redirectUri);
		if (is_wp_error($consumed)) {
			Audit::emit(0, 'oauth.token', 'denied', ['client_id' => $clientId, 'reason' => $consumed->get_error_code()]);

			return self::wpErrorResponse($consumed);
		}

		if (! Pkce::verify($codeVerifier, $consumed['code_challenge'])) {
			Audit::emit($consumed['user_id'], 'oauth.token', 'denied', ['client_id' => $clientId, 'reason' => 'pkce_mismatch']);

			return self::errorResponse('invalid_grant', 'PKCE verification failed.', 400);
		}

		$tokens = TokenStore::issueTokenPair($consumed['user_id'], $clientId, $consumed['scope'], null, $consumed['authorized_by']);
		if (is_wp_error($tokens)) {
			Audit::emit($consumed['user_id'], 'oauth.token', 'error', ['client_id' => $clientId, 'reason' => $tokens->get_error_code()]);

			return self::wpErrorResponse($tokens);
		}

		Dcr::noteGrant($clientId);

		Audit::emit($consumed['user_id'], 'oauth.token', 'ok', ['client_id' => $clientId, 'grant_type' => 'authorization_code']);

		return new \WP_REST_Response($tokens, 200);
	}

	private static function handleRefreshGrant(\WP_REST_Request $request, string $clientId): \WP_REST_Response {
		$refreshToken = (string) $request->get_param('refresh_token');

		if ($refreshToken === '' || $clientId === '') {
			return self::errorResponse('invalid_request', 'refresh_token and client_id are required.', 400);
		}

		$tokens = TokenStore::rotateRefreshToken($refreshToken, $clientId);
		if (is_wp_error($tokens)) {
			Audit::emit(0, 'oauth.token', 'denied', ['client_id' => $clientId, 'reason' => $tokens->get_error_code()]);

			return self::wpErrorResponse($tokens);
		}

		Dcr::noteGrant($clientId);

		Audit::emit(0, 'oauth.token', 'ok', ['client_id' => $clientId, 'grant_type' => 'refresh_token']);

		return new \WP_REST_Response($tokens, 200);
	}

	public static function handleRevoke(\WP_REST_Request $request): \WP_REST_Response {
		$token    = (string) $request->get_param('token');
		$clientId = (string) $request->get_param('client_id');

		if ($token !== '') {
			$revoked = TokenStore::revokeToken($token);
			Audit::emit(0, 'oauth.revoke', $revoked ? 'ok' : 'denied', ['client_id' => $clientId]);
		}

		// RFC 7009: always respond 200, even for an unknown/already-revoked token.
		return new \WP_REST_Response([], 200);
	}

	/**
	 * RFC 7591: register a new public client. Anonymous by design; the
	 * limits live in Dcr (metadata validation, per-IP rate limit, total
	 * cap), and every outcome is audited there.
	 */
	public static function handleRegister(\WP_REST_Request $request): \WP_REST_Response {
		$metadata = json_decode((string) $request->get_body(), true);
		if (! is_array($metadata)) {
			Audit::emit(0, 'oauth.client_register', 'denied', ['reason' => 'invalid_json']);

			return self::errorResponse('invalid_request', 'The request body must be a JSON object.', 400);
		}

		$result = Dcr::register($metadata, self::requestIp($request));
		if (is_wp_error($result)) {
			$data   = $result->get_error_data();
			$status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

			return self::errorResponse($result->get_error_code(), $result->get_error_message(), $status);
		}

		return new \WP_REST_Response($result, 201);
	}

	/** RFC 7592 read: the client fetches its own configuration back. */
	public static function handleRegisterRead(\WP_REST_Request $request): \WP_REST_Response {
		$result = Dcr::configuration((string) $request->get_param('client_id'), self::bearerToken($request));
		if (is_wp_error($result)) {
			$data   = $result->get_error_data();
			$status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

			return self::errorResponse($result->get_error_code(), $result->get_error_message(), $status);
		}

		return new \WP_REST_Response($result, 200);
	}

	/** RFC 7592 delete: the client revokes its own registration. */
	public static function handleRegisterDelete(\WP_REST_Request $request): \WP_REST_Response {
		$revoked = Dcr::revoke((string) $request->get_param('client_id'), self::bearerToken($request));

		return new \WP_REST_Response([], $revoked ? 204 : 403);
	}

	/**
	 * The caller's address for the registration rate limit. REMOTE_ADDR
	 * under a reverse proxy is a deployment concern; the
	 * agent_brain/oauth_dcr_client_ip filter is the override point (and
	 * what the tests use). An empty result disables rate limiting.
	 */
	private static function requestIp(\WP_REST_Request $request): string {
		unset($request); // The rate limit attributes by transport address, not by request body.

		return (string) apply_filters(
			'agent_brain/oauth_dcr_client_ip',
			isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : ''
		);
	}

	private static function bearerToken(\WP_REST_Request $request): string {
		$header = (string) $request->get_header('authorization');
		if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
			return trim($matches[1]);
		}

		return '';
	}

	private static function errorResponse(string $error, string $description, int $status): \WP_REST_Response {
		return new \WP_REST_Response(['error' => $error, 'error_description' => $description], $status);
	}

	private static function wpErrorResponse(\WP_Error $error): \WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

		return self::errorResponse($error->get_error_code(), $error->get_error_message(), $status);
	}
}
