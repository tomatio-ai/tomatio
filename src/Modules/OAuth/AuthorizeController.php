<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Core\RestNamespaces;
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
 *    "Connect as a new agent" (NewAgent, #807) makes the agent and issues
 *    the code for it in one transaction; a problem with the new agent's
 *    fields (a name clash, a scope the person does not hold) shows the
 *    consent screen again with the message and what they typed.
 *
 * #942: the metadata advertises .../oauth/authorize, a REST route with no
 * query string that 302s to ab_oauth_authorize with the same parameters,
 * because some clients add theirs after a second "?". A request that
 * already arrives mangled on the old address is repaired in admin_init.
 *
 * #853: the steps before a grant leave light audit rows too, so a brain
 * can tell where a connection stopped: `oauth.signin_asked` when the
 * authorize address sends a signed-out visitor to sign in, and
 * `oauth.consent_shown` when the consent screen (status ok) or its error
 * page (status error) is shown.
 */
final class AuthorizeController {

	public const ACTION = 'ab_oauth_authorize';
	/** #942: the query-free authorize address, under each REST namespace. */
	public const ROUTE = '/oauth/authorize';
	public const CONSENT_ACTION = 'ab_oauth_consent';
	public const NONCE_ACTION = 'ab_oauth_consent_nonce';
	public const NONCE_FIELD = '_ab_oauth_nonce';

	/** #853: at most this many oauth.signin_asked rows a day, and one per client an hour. */
	public const SIGNIN_DAILY_CAP = 50;
	public const SIGNIN_COUNT_OPTION = 'ab_oauth_signin_asked_today';

	public function registerHooks(): void {
		if (! function_exists('add_action')) {
			return;
		}

		// admin-post.php fires admin_init before it looks the action up.
		add_action('admin_init', [self::class, 'repairMangledAction'], 0);
		add_action('admin_post_' . self::ACTION, [$this, 'handleAuthorize']);
		add_action('admin_post_nopriv_' . self::ACTION, [$this, 'handleAuthorize']);
		add_action('admin_post_' . self::CONSENT_ACTION, [$this, 'handleConsent']);
		add_action('admin_post_nopriv_' . self::CONSENT_ACTION, [$this, 'handleConsent']);
	}

	/**
	 * The authorize address the metadata advertises (#942): a REST route
	 * with no query string of its own, because some clients (Grok) add
	 * their parameters after a second "?" when the address already has
	 * one. It forwards to the admin-post handler with every parameter.
	 */
	public static function authorizeUrl(): string {
		return rest_url(RestNamespaces::ADVERTISED . self::ROUTE);
	}

	public static function handleRestAuthorize(\WP_REST_Request $request): \WP_REST_Response {
		$response = new \WP_REST_Response(null, 302);
		$response->header('Location', self::adminPostUrl($request->get_query_params()));
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * The admin-post authorize URL carrying $query (unslashed values).
	 * Our action always wins; a plain-permalink rest_route is dropped.
	 *
	 * @param array<array-key, mixed> $query
	 */
	public static function adminPostUrl(array $query): string {
		$pairs = ['action' => self::ACTION];
		foreach ($query as $key => $value) {
			if (! is_string($key) || $key === 'action' || $key === 'rest_route' || ! is_scalar($value)) {
				continue;
			}
			$pairs[$key] = (string) $value;
		}

		return admin_url('admin-post.php') . '?' . http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * #942: a client that already discovered the old admin-post address and
	 * appends with a second "?" sends action=ab_oauth_authorize?response_type=…,
	 * which no hook matches, so WordPress answers with an empty 400 page.
	 * Send it to the same query with that "?" read as "&".
	 */
	public static function repairMangledAction(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared, never used.
		$action = isset($_GET['action']) && is_string($_GET['action']) ? (string) wp_unslash($_GET['action']) : '';
		if (! str_starts_with($action, self::ACTION . '?')) {
			return;
		}

		$raw   = isset($_SERVER['QUERY_STRING']) ? (string) wp_unslash($_SERVER['QUERY_STRING']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- rewritten below, then passed through wp_safe_redirect().
		$fixed = self::repairedQuery($raw);
		if ($fixed === null) {
			return;
		}

		wp_safe_redirect(admin_url('admin-post.php') . '?' . $fixed);
		exit;
	}

	/**
	 * $raw with the "?" right after our action turned into "&", or null when
	 * there is nothing to repair. Works on the raw query, so values are
	 * never decoded twice.
	 */
	public static function repairedQuery(string $raw): ?string {
		$fixed = preg_replace('/(^|&)action=' . preg_quote(self::ACTION, '/') . '(?:\?|%3F)/i', '$1action=' . self::ACTION . '&', $raw, 1);

		return is_string($fixed) && $fixed !== $raw ? $fixed : null;
	}

	public function handleAuthorize(): void {
		$params = self::readAuthorizeParams($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if (! is_user_logged_in()) {
			self::recordSignInAsked($params);
			$returnUrl = add_query_arg(array_map('rawurlencode', $params), admin_url('admin-post.php?action=' . self::ACTION));
			wp_safe_redirect(wp_login_url($returnUrl));
			exit;
		}

		$this->renderConsent($params);
		exit;
	}

	public function handleConsent(): void {
		if (! is_user_logged_in()) {
			wp_die(esc_html__('You must be logged in to approve this request.', 'tomatio'), 401);
		}

		if (! isset($_POST[self::NONCE_FIELD]) || ! wp_verify_nonce((string) wp_unslash($_POST[self::NONCE_FIELD]), self::NONCE_ACTION)) {
			wp_die(esc_html__('Security check failed. Please try again.', 'tomatio'), 403);
		}

		$params   = self::readAuthorizeParams($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$userId   = get_current_user_id();
		$decision = isset($_POST['ab_oauth_decision']) ? (string) wp_unslash($_POST['ab_oauth_decision']) : 'deny';
		$choice   = isset($_POST[Delegation::FIELD]) ? sanitize_key((string) wp_unslash($_POST[Delegation::FIELD])) : '';
		$newAgent = $choice === NewAgent::CHOICE ? NewAgent::readFields($_POST) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$actAsId  = $newAgent === null ? absint($choice) : 0;
		$delegate = isset($_POST[DelegatedAuthority::FIELD]) && (string) wp_unslash($_POST[DelegatedAuthority::FIELD]) === '1'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to a literal.

		$destination = $this->decideConsent($params, $decision, $userId, $actAsId, $delegate, $newAgent);
		if (is_wp_error($destination)) {
			if ($newAgent !== null && self::isNewAgentProblem($destination)) {
				// Show the screen again, with the message and what they typed.
				nocache_headers();
				status_header((int) ($destination->get_error_data()['status'] ?? 422));
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ConsentPage escapes every value it renders.
				echo $this->consentHtml($params, ['message' => $destination->get_error_message(), 'fields' => $newAgent]);
				exit;
			}

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
	 * $newAgent is "Connect as a new agent" (#807): the posted name, class
	 * and scopes (NewAgent::readFields()), or null. Given, and not on the
	 * role-switching address, it wins over $actAsId: the agent is made and
	 * the code issued for it in one transaction (NewAgent::createAndBind).
	 *
	 * @param array{response_type:string, client_id:string, redirect_uri:string, code_challenge:string, code_challenge_method:string, state:string, scope:string, resource?:string} $params
	 * @param array{name:string, class:string, read:int[], write:int[]}|null $newAgent
	 * @return string|\WP_Error redirect URL, or a WP_Error for a malformed/invalid request (never redirected).
	 */
	public function decideConsent(array $params, string $decision, int $userId, int $actAsId = 0, bool $delegate = false, ?array $newAgent = null) {
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
		if ($newAgent !== null && ! $rolesMode) {
			return $this->approveNewAgent($params, $userId, $newAgent, $delegate);
		}

		$actAsId   = $rolesMode ? $userId : ($actAsId > 0 ? $actAsId : $userId);
		if (! Delegation::canActAs($userId, $actAsId)) {
			Audit::emit($userId, 'oauth.authorize', 'denied', ['client_id' => $params['client_id'], 'reason' => 'delegation_forbidden', 'on_behalf_of' => $actAsId]);

			return new \WP_Error('ab_oauth_delegation_forbidden', __('You cannot connect an AI client as that account.', 'tomatio'), ['status' => 403]);
		}

		// #962: one address. Connecting as yourself on the main address works
		// like the role-switching address (act-as included), and saves work
		// from the first message: a person with no agent to write through
		// gets their own one now, when they may add agents.
		$asSelf = $actAsId === $userId;
		if ($asSelf && ! $rolesMode) {
			OwnAgent::ensureFor($userId);
			$rolesMode = RoleSwitching::enabled();
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
	 * "Connect as a new agent": checks the fields, then makes the agent and
	 * issues the code for it in one transaction, so the token family the
	 * client gets is the new agent's (with the person as authorized_by,
	 * like any agent connection they approve).
	 *
	 * @param array{response_type:string, client_id:string, redirect_uri:string, code_challenge:string, code_challenge_method:string, state:string, scope:string, resource?:string} $params
	 * @param array{name:string, class:string, read:int[], write:int[]} $fields
	 * @return string|\WP_Error
	 */
	private function approveNewAgent(array $params, int $userId, array $fields, bool $delegate) {
		$agent = NewAgent::validate($userId, $fields);
		if (is_wp_error($agent)) {
			Audit::emit($userId, 'oauth.authorize', 'denied', ['client_id' => $params['client_id'], 'reason' => $agent->get_error_code(), 'new_agent' => true]);

			return $agent;
		}

		$scope    = 'mcp';
		$delegate = $delegate && DelegatedAuthority::allowed();
		if ($delegate) {
			$scope = DelegatedAuthority::withScope($scope);
		}

		$made = NewAgent::createAndBind(
			$userId,
			$params['client_id'],
			$agent,
			static fn (int $agentId) => TokenStore::issueCode($agentId, $params['client_id'], $params['redirect_uri'], $params['code_challenge'], $scope, $userId)
		);
		if (is_wp_error($made)) {
			return $made;
		}

		// The agent.issue row (AgentIssuer) records the new agent; this one
		// records the connection and what the person gave it.
		$meta = ['client_id' => $params['client_id'], 'on_behalf_of' => $made['agent_id'], 'new_agent' => $made['fqdn'], 'read' => $agent['read'], 'write' => $agent['write']];
		if ($delegate) {
			$meta['delegated_authority'] = true;
		}
		Audit::emit($userId, 'oauth.grant', 'ok', $meta);

		return self::withQueryArgs($params['redirect_uri'], ['code' => $made['code'], 'state' => $params['state'], 'iss' => Metadata::issuer()]);
	}

	/**
	 * A problem the person can fix on the consent screen (the new agent's
	 * name, class or scopes), as opposed to a broken request.
	 */
	public static function isNewAgentProblem(\WP_Error $error): bool {
		return NewAgent::isFixable((string) $error->get_error_code());
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
		$page = $this->consentResponse($params, get_current_user_id());

		nocache_headers();
		status_header($page['status']);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ConsentPage escapes every value it renders.
		echo $page['html'];
	}

	/**
	 * What GET authorize shows a signed-in person: the consent screen, or
	 * the error page for a request that does not validate. Records which
	 * one in `oauth.consent_shown` (#853). No headers, no output.
	 *
	 * @param array{response_type:string, client_id:string, redirect_uri:string, code_challenge:string, code_challenge_method:string, state:string, scope:string, resource?:string} $params
	 * @return array{status:int, html:string}
	 */
	public function consentResponse(array $params, int $userId): array {
		$validated = $this->validate($params);
		$clientId  = self::shortClientId($params['client_id']);

		if (is_wp_error($validated)) {
			Audit::emit($userId, 'oauth.consent_shown', 'error', ['client_id' => $clientId, 'reason' => $validated->get_error_code()]);

			return ['status' => 400, 'html' => ConsentPage::renderError($validated->get_error_message())];
		}

		$meta = ['client_id' => $clientId];
		if (self::isRolesMode($params)) {
			$meta['role_switching'] = true;
		}
		Audit::emit($userId, 'oauth.consent_shown', 'ok', $meta);

		return ['status' => 200, 'html' => $this->consentHtml($params)];
	}

	/**
	 * #853: the authorize address reached while signed out, about to send
	 * the visitor to sign in. Anyone can call that address, so only a
	 * well-formed request counts (response_type code, a PKCE challenge, a
	 * client and a redirect address), once per client an hour and at most
	 * SIGNIN_DAILY_CAP a day. Nothing is fetched and no address is kept:
	 * the row holds the client_id. True when a row was written.
	 *
	 * @param array{response_type:string, client_id:string, redirect_uri:string, code_challenge:string, code_challenge_method:string, state:string, scope:string, resource?:string} $params
	 */
	public static function recordSignInAsked(array $params): bool {
		if ($params['response_type'] !== 'code' || $params['code_challenge'] === '' || $params['client_id'] === '' || $params['redirect_uri'] === '') {
			return false;
		}

		$clientId = self::shortClientId($params['client_id']);
		$seenKey  = 'ab_oauth_signin_' . md5($clientId);
		if (get_transient($seenKey) !== false) {
			return false;
		}

		$today = gmdate('Y-m-d');
		$count = get_option(self::SIGNIN_COUNT_OPTION);
		$count = is_array($count) && ($count['day'] ?? '') === $today ? (int) ($count['n'] ?? 0) : 0;
		if ($count >= self::SIGNIN_DAILY_CAP) {
			return false;
		}

		update_option(self::SIGNIN_COUNT_OPTION, ['day' => $today, 'n' => $count + 1], false);
		set_transient($seenKey, 1, HOUR_IN_SECONDS);

		$meta = ['client_id' => $clientId];
		if (self::isRolesMode($params)) {
			$meta['role_switching'] = true;
		}
		Audit::emit(0, 'oauth.signin_asked', 'ok', $meta);

		return true;
	}

	/** A client_id as the audit row keeps it (object_id holds 191 characters). */
	private static function shortClientId(string $clientId): string {
		return function_exists('mb_substr') ? mb_substr($clientId, 0, 191) : substr($clientId, 0, 191);
	}

	/**
	 * The consent page for an already validated request, for the signed-in
	 * user. Pure (no headers, no output), so it is directly testable.
	 *
	 * $retry is set when "Connect as a new agent" was refused: the message
	 * to show and the fields as posted, so the person can fix them.
	 *
	 * @param array{response_type:string, client_id:string, redirect_uri:string, code_challenge:string, code_challenge_method:string, state:string, scope:string, resource?:string} $params
	 * @param array{message:string, fields:array{name:string, class:string, read:int[], write:int[]}}|null $retry
	 */
	public function consentHtml(array $params, ?array $retry = null): string {
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

		// From the fixed-agent address a manager may also make a new agent
		// for this client (#807).
		$newAgent = ! $rolesMode && NewAgent::canCreate($userId) ? NewAgent::formFor($userId, $scopes, $retry['fields'] ?? null) : null;
		$options  = $rolesMode ? [] : ConnectChoices::for($userId, $scopes, $newAgent !== null);

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
			'new_agent'     => $newAgent,
			'notice'        => $retry['message'] ?? '',
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
