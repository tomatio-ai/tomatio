<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Modules\Identity\Scopes;

/**
 * The human-facing authorize page (ID-5): a logged-in WP user consents for
 * themselves or, if they manage the brain, for one of its agents (Delegation:
 * "Connect this AI as"). Two admin-post actions:
 *
 *  - ab_oauth_authorize (GET): requires a logged-in user (redirects to
 *    wp-login.php?redirect_to=<back here> otherwise), resolves the client
 *    (ClientDirectory: CIMD document or DCR registration), validates the
 *    request, and renders an escaped consent form.
 *  - ab_oauth_consent (POST): CSRF-nonce-checked; on approve, re-checks the
 *    chosen account (Delegation::canActAs), issues a code bound to that
 *    user+client+redirect_uri+code_challenge and 302s back to the
 *    client's redirect_uri; on deny, 302s back with error=access_denied.
 */
final class AuthorizeController {

	public const ACTION = 'ab_oauth_authorize';
	public const CONSENT_ACTION = 'ab_oauth_consent';
	public const NONCE_ACTION = 'ab_oauth_consent_nonce';
	public const NONCE_FIELD = '_ab_oauth_nonce';

	public function registerHooks(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_post_' . self::ACTION, [$this, 'handleAuthorize']);
		add_action('admin_post_nopriv_' . self::ACTION, [$this, 'handleAuthorize']);
		add_action('admin_post_' . self::CONSENT_ACTION, [$this, 'handleConsent']);
		add_action('admin_post_nopriv_' . self::CONSENT_ACTION, [$this, 'handleConsent']);
	}

	public function handleAuthorize(): void {
		$params = self::readAuthorizeParams($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if (! is_user_logged_in()) {
			$returnUrl = add_query_arg(array_map('rawurlencode', $params), admin_url('admin-post.php?action=' . self::ACTION));
			wp_safe_redirect(wp_login_url($returnUrl));
			exit;
		}

		$this->renderConsent($params);
		exit;
	}

	public function handleConsent(): void {
		if (! is_user_logged_in()) {
			wp_die(esc_html__('You must be logged in to approve this request.', 'agent-brain'), 401);
		}

		if (! isset($_POST[self::NONCE_FIELD]) || ! wp_verify_nonce((string) wp_unslash($_POST[self::NONCE_FIELD]), self::NONCE_ACTION)) {
			wp_die(esc_html__('Security check failed. Please try again.', 'agent-brain'), 403);
		}

		$params   = self::readAuthorizeParams($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$userId   = get_current_user_id();
		$decision = isset($_POST['ab_oauth_decision']) ? (string) wp_unslash($_POST['ab_oauth_decision']) : 'deny';
		$actAsId  = isset($_POST[Delegation::FIELD]) ? absint(wp_unslash($_POST[Delegation::FIELD])) : 0;
		$delegate = isset($_POST[DelegatedAuthority::FIELD]) && (string) wp_unslash($_POST[DelegatedAuthority::FIELD]) === '1'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to a literal.

		$destination = $this->decideConsent($params, $decision, $userId, $actAsId, $delegate);
		if (is_wp_error($destination)) {
			wp_die(esc_html($destination->get_error_message()), 400);
		}

		self::allowRedirectHostFor($destination);
		wp_safe_redirect($destination);
		exit;
	}

	/**
	 * wp_safe_redirect() only allows this site's own host, so without this
	 * the client's redirect_uri silently falls back to admin_url() and the
	 * client never receives its code. decideConsent() only returns a
	 * destination after validate() matched redirect_uri exactly against the
	 * client's CIMD document, so allowing that one host is safe.
	 */
	public static function allowRedirectHostFor(string $destination): void {
		$host = wp_parse_url($destination, PHP_URL_HOST);
		if (! is_string($host) || $host === '') {
			return;
		}

		add_filter('allowed_redirect_hosts', static function (array $hosts) use ($host): array {
			$hosts[] = $host;

			return $hosts;
		});
	}

	/**
	 * Pure decision logic (no header output, no exit) so it is directly
	 * testable: validates the request, then either issues a code and
	 * returns the client's redirect_uri with ?code=&state=, or (on deny)
	 * returns it with ?error=access_denied&state=.
	 *
	 * $actAsId is the account the client will act as: 0 or $userId for the
	 * approver themselves, otherwise an agent they may delegate to. It is
	 * re-checked here, never trusted from the form.
	 *
	 * $delegate is the consent screen's delegated-authority box (#629,
	 * unticked by default): ticked, and allowed on this brain, the
	 * connection's scope carries DelegatedAuthority::SCOPE. The client
	 * cannot ask for it itself: the scope is built here.
	 *
	 * @param array{response_type:string, client_id:string, redirect_uri:string, code_challenge:string, code_challenge_method:string, state:string, scope:string, resource?:string} $params
	 * @return string|\WP_Error redirect URL, or a WP_Error for a malformed/invalid request (never redirected).
	 */
	public function decideConsent(array $params, string $decision, int $userId, int $actAsId = 0, bool $delegate = false) {
		$validated = $this->validate($params);
		if (is_wp_error($validated)) {
			Audit::emit($userId, 'oauth.authorize', 'error', ['client_id' => $params['client_id'], 'reason' => $validated->get_error_code()]);

			return $validated;
		}

		$redirectUri = $params['redirect_uri'];

		if ($decision !== 'approve') {
			Audit::emit($userId, 'oauth.authorize', 'denied', ['client_id' => $params['client_id']]);

			return self::withQueryArgs($redirectUri, ['error' => 'access_denied', 'state' => $params['state'], 'iss' => Metadata::issuer()]);
		}

		// The role-switching address always connects as the person; the client
		// then picks agents per task (act-as). The scope marks the connection.
		$rolesMode = self::isRolesMode($params);
		$actAsId   = $rolesMode ? $userId : ($actAsId > 0 ? $actAsId : $userId);
		if (! Delegation::canActAs($userId, $actAsId)) {
			Audit::emit($userId, 'oauth.authorize', 'denied', ['client_id' => $params['client_id'], 'reason' => 'delegation_forbidden', 'on_behalf_of' => $actAsId]);

			return new \WP_Error('ab_oauth_delegation_forbidden', __('You cannot connect an AI client as that account.', 'agent-brain'), ['status' => 403]);
		}

		$scope    = $rolesMode ? 'mcp ' . RoleSwitching::SCOPE : 'mcp';
		$delegate = $delegate && DelegatedAuthority::allowed();
		if ($delegate) {
			$scope = DelegatedAuthority::withScope($scope);
		}
		$code  = TokenStore::issueCode($actAsId, $params['client_id'], $redirectUri, $params['code_challenge'], $scope, $actAsId !== $userId ? $userId : 0);
		if (is_wp_error($code)) {
			Audit::emit($userId, 'oauth.authorize', 'error', ['client_id' => $params['client_id'], 'reason' => $code->get_error_code()]);

			return $code;
		}

		$meta = ['client_id' => $params['client_id']];
		if ($actAsId !== $userId) {
			$meta['on_behalf_of'] = $actAsId;
		}
		if ($rolesMode) {
			$meta['role_switching'] = true;
		}
		if ($delegate) {
			$meta['delegated_authority'] = true;
		}
		Audit::emit($userId, 'oauth.grant', 'ok', $meta);

		// RFC 9207: iss lets the client check which server answered (mix-up defense).
		return self::withQueryArgs($redirectUri, ['code' => $code, 'state' => $params['state'], 'iss' => Metadata::issuer()]);
	}

	/**
	 * The authorize parameters as the authorize and consent requests carry
	 * them (slashed, from $_GET or $_POST). A CIMD client_id is a URL; a
	 * DCR client_id is opaque (`dcr_…`) and is kept as text, because
	 * esc_url_raw() would turn it into `http://dcr_…`, which no
	 * registration matches (#581).
	 *
	 * @param array<string, mixed> $source
	 * @return array{response_type:string, client_id:string, redirect_uri:string, code_challenge:string, code_challenge_method:string, state:string, scope:string, resource?:string}
	 */
	public static function readAuthorizeParams(array $source): array {
		$clientId = isset($source['client_id']) ? trim((string) wp_unslash($source['client_id'])) : '';

		return [
			'response_type'         => isset($source['response_type']) ? sanitize_text_field((string) wp_unslash($source['response_type'])) : '',
			'client_id'             => Dcr::isManaged($clientId) ? sanitize_text_field($clientId) : esc_url_raw($clientId),
			'redirect_uri'          => isset($source['redirect_uri']) ? esc_url_raw((string) wp_unslash($source['redirect_uri'])) : '',
			'code_challenge'        => isset($source['code_challenge']) ? sanitize_text_field((string) wp_unslash($source['code_challenge'])) : '',
			'code_challenge_method' => isset($source['code_challenge_method']) ? sanitize_text_field((string) wp_unslash($source['code_challenge_method'])) : '',
			'state'                 => isset($source['state']) ? sanitize_text_field((string) wp_unslash($source['state'])) : '',
			'scope'                 => isset($source['scope']) ? sanitize_text_field((string) wp_unslash($source['scope'])) : 'mcp',
			// RFC 8707 resource indicator: which connector address this is
			// (the role-switching address is .../mcp-roles, or the older
			// .../mcp?roles=1). Kept through the login and "Not you?" redirects.
			'resource'              => isset($source['resource']) ? esc_url_raw((string) wp_unslash($source['resource'])) : '',
		];
	}

	/**
	 * @param array<string, string> $params
	 * @return true|\WP_Error
	 */
	private function validate(array $params) {
		if ($params['response_type'] !== 'code') {
			return new \WP_Error('ab_oauth_unsupported_response_type', 'Only response_type=code is supported.');
		}

		if (! Pkce::isSupportedMethod($params['code_challenge_method']) || $params['code_challenge'] === '') {
			return new \WP_Error('ab_oauth_invalid_pkce', 'A code_challenge with method S256 is required.');
		}

		if ($params['client_id'] === '' || $params['redirect_uri'] === '') {
			return new \WP_Error('ab_oauth_invalid_request', 'client_id and redirect_uri are required.');
		}

		$doc = ClientDirectory::fetch($params['client_id']);
		if (is_wp_error($doc)) {
			return $doc;
		}

		if (! in_array($params['redirect_uri'], $doc['redirect_uris'], true)) {
			return new \WP_Error('ab_oauth_redirect_uri_mismatch', 'redirect_uri does not exactly match a value in the client metadata document.');
		}

		return true;
	}

	/**
	 * @param array{response_type:string, client_id:string, redirect_uri:string, code_challenge:string, code_challenge_method:string, state:string, scope:string, resource?:string} $params
	 */
	private function renderConsent(array $params): void {
		$validated = $this->validate($params);

		nocache_headers();

		if (is_wp_error($validated)) {
			status_header(400);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ConsentPage escapes every value it renders.
			echo ConsentPage::renderError($validated->get_error_message());

			return;
		}

		$html = $this->consentHtml($params);

		status_header(200);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ConsentPage escapes every value it renders.
		echo $html;
	}

	/**
	 * The consent page for an already validated request, for the signed-in
	 * user. Pure (no headers, no output), so it is directly testable.
	 *
	 * @param array{response_type:string, client_id:string, redirect_uri:string, code_challenge:string, code_challenge_method:string, state:string, scope:string, resource?:string} $params
	 */
	public function consentHtml(array $params): string {
		$doc  = ClientDirectory::fetch($params['client_id']);
		// What the consent screen calls the "verified host": for a CIMD
		// client that is the host of the metadata document URL; for a DCR
		// client the client_id is opaque, so it is the host of the (exact-
		// matched) redirect URI the client is actually coming back to.
		$host = Dcr::isManaged($params['client_id'])
			? wp_parse_url($params['redirect_uri'], PHP_URL_HOST)
			: wp_parse_url($params['client_id'], PHP_URL_HOST);
		$userId = get_current_user_id();
		$user   = wp_get_current_user();
		$scopes = new Scopes();
		$here   = add_query_arg(array_map('rawurlencode', $params), admin_url('admin-post.php?action=' . self::ACTION));

		$hidden = ['action' => self::CONSENT_ACTION];
		foreach ($params as $key => $value) {
			$hidden[$key] = (string) $value;
		}

		// From the role-switching address the client connects as the person
		// and picks agents per task (act-as): no picker, just the list.
		$rolesMode  = self::isRolesMode($params);
		$switchable = $rolesMode ? array_map(static fn (\WP_User $agent): string => $agent->display_name, RoleSwitching::agentsFor($userId)) : [];

		$options = $rolesMode ? [] : ConnectChoices::for($userId, $scopes);

		return ConsentPage::render([
			'site_name'     => (string) get_bloginfo('name'),
			'client_name'   => is_array($doc) ? (string) $doc['client_name'] : '',
			'verified_host' => is_string($host) ? $host : $params['client_id'],
			'user_name'     => $user->display_name,
			'switch_url'    => wp_logout_url($here),
			'revoke_url'    => admin_url('profile.php'),
			'read_scopes'   => self::scopeNames($scopes->readScopeIds($userId)),
			'write_scopes'  => self::scopeNames($scopes->writeScopeIds($userId)),
			'action_url'    => admin_url('admin-post.php'),
			'nonce_field'   => wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD, true, false),
			'hidden'        => $hidden,
			'connect_as'    => $options,
			'switchable'    => $switchable,
			'delegate'      => DelegatedAuthority::allowed(),
		]);
	}

	/**
	 * Whether this authorization request is for a role-switching address
	 * (RoleSwitching::isRolesResource(): .../mcp-roles, or the older
	 * .../mcp?roles=1). Then the consent screen shows no agent picker, the
	 * code is issued to the person, and the scope is "mcp roles".
	 *
	 * @param array{response_type:string, client_id:string, redirect_uri:string, code_challenge:string, code_challenge_method:string, state:string, scope:string, resource?:string} $params
	 */
	public static function isRolesMode(array $params): bool {
		return RoleSwitching::enabled() && RoleSwitching::isRolesResource($params['resource'] ?? '');
	}

	/**
	 * @param int[] $scopeIds
	 * @return string[]
	 */
	private static function scopeNames(array $scopeIds): array {
		$names = [];
		foreach ($scopeIds as $scopeId) {
			$term = get_term((int) $scopeId, Scopes::TAXONOMY);
			if ($term instanceof \WP_Term) {
				$names[] = $term->name;
			}
		}

		return $names;
	}

	/**
	 * @param array<string, string> $args
	 */
	private static function withQueryArgs(string $url, array $args): string {
		return add_query_arg(array_map('rawurlencode', $args), $url);
	}
}
