<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Modules\Admin\AgentsPage;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * wp-admin surfaces for OAuth grant management (ID-5): a panel on the user
 * profile screen listing that user's own OAuth grants with per-grant
 * revoke, plus a curator-only "OAuth Clients" UI to revoke a client
 * entirely (every user's grant to it) in one action.
 *
 * Admin IA wave (Unit U): the OAuth Clients UI used to be its own
 * `add_users_page()` submenu under Users. Per the shared contract ("Nothing
 * TomaTío may remain under Users, Tools or Settings"), it is now
 * rendered as the "Connected apps" tab of Admin\AgentsPage
 * (admin.php?page=agent-brain-agents&tab=oauth); this class no longer
 * registers a Users submenu itself. Its revoke redirect targets that tab.
 */
final class ProfileUi {

	public const REVOKE_GRANT_ACTION = 'ab_oauth_revoke_grant';
	public const REVOKE_CLIENT_ACTION = 'ab_oauth_revoke_client';
	public const REVOKE_DCR_CLIENT_ACTION = 'ab_oauth_revoke_dcr_client';
	public const DELEGATION_OFF_ACTION = 'ab_oauth_delegation_off';
	public const NONCE_ACTION = 'ab_oauth_revoke';
	public const NONCE_FIELD = 'ab_oauth_revoke_nonce';

	public function registerHooks(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('show_user_profile', [$this, 'renderProfilePanel']);
		add_action('edit_user_profile', [$this, 'renderProfilePanel']);
		add_action('admin_post_' . self::REVOKE_GRANT_ACTION, [$this, 'handleRevokeGrant']);
		add_action('admin_post_' . self::REVOKE_CLIENT_ACTION, [$this, 'handleRevokeClient']);
		add_action('admin_post_' . self::REVOKE_DCR_CLIENT_ACTION, [$this, 'handleRevokeDcrClient']);
		add_action('admin_post_' . self::DELEGATION_OFF_ACTION, [$this, 'handleDelegationOff']);
		add_action('admin_notices', [$this, 'renderResultNotice']);
	}

	public function renderProfilePanel(\WP_User $user): void {
		if (! function_exists('current_user_can') || ! current_user_can('edit_user', $user->ID)) {
			return;
		}

		$grants = TokenStore::grantsForUser($user->ID);
		// #629: agent connections this person authorized with delegated authority.
		$agentGrants = TokenStore::delegatedGrantsAuthorizedBy($user->ID);

		echo '<h2>' . esc_html__('MCP Agent Authorizations (OAuth)', 'tomatio') . '</h2>';

		if ($grants === [] && $agentGrants === []) {
			echo '<p>' . esc_html__('No active OAuth authorizations.', 'tomatio') . '</p>';

			return;
		}

		echo '<p class="description">' . esc_html__('"Acts with your rights" marks a connection you gave delegated authority when connecting it: when you ask, that AI can create, edit and publish without review. Turning it off takes effect at once and keeps the connection signed in.', 'tomatio') . '</p>';
		if (! DelegatedAuthority::allowed()) {
			echo '<p class="description"><strong>' . esc_html__('Delegated authority is turned off for this brain, so no connection uses it.', 'tomatio') . '</strong></p>';
		}

		echo '<div class="ab-table-wrap"><table class="widefat"><thead><tr><th scope="col">' . esc_html__('Client', 'tomatio') . '</th><th scope="col">'
			. esc_html__('Scope', 'tomatio') . '</th><th scope="col">' . esc_html__('Delegated authority', 'tomatio') . '</th><th scope="col">' . esc_html__('Authorized', 'tomatio') . '</th><th scope="col">'
			. esc_html__('Expires', 'tomatio') . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__('Actions', 'tomatio') . '</span></th></tr></thead><tbody>';

		foreach ($agentGrants as $grant) {
			$agent = get_userdata((int) $grant['user_id']);
			echo '<tr>';
			echo '<td>' . esc_html(sprintf(
				/* translators: 1: client id, 2: the agent the client connects as. */
				__('%1$s (as %2$s)', 'tomatio'),
				$grant['client_id'],
				$agent instanceof \WP_User ? $agent->display_name : (string) $grant['user_id']
			)) . '</td>';
			echo '<td>' . esc_html($grant['scope']) . '</td>';
			echo '<td>' . esc_html__('Acts with your rights', 'tomatio') . '</td>';
			echo '<td>' . esc_html($grant['created']) . '</td>';
			echo '<td>' . esc_html($grant['expires']) . '</td>';
			echo '<td>';
			self::renderDelegationOff((int) $user->ID, (string) $grant['family_id']);
			echo '</td></tr>';
		}

		foreach ($grants as $grant) {
			$delegated = DelegatedAuthority::scopeGrants((string) $grant['scope']);
			echo '<tr>';
			echo '<td>' . esc_html($grant['client_id']) . '</td>';
			echo '<td>' . esc_html($grant['scope']) . '</td>';
			echo '<td>' . esc_html($delegated ? __('Acts with your rights', 'tomatio') : __('Off', 'tomatio')) . '</td>';
			echo '<td>' . esc_html($grant['created']) . '</td>';
			echo '<td>' . esc_html($grant['expires']) . '</td>';
			echo '<td>';
			if ($delegated) {
				self::renderDelegationOff((int) $user->ID, (string) $grant['family_id']);
			}
			self::renderRevokeGrant((int) $user->ID, (string) $grant['family_id']);
			echo '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/** @var array<string, string> #975: row forms waiting to be printed after the profile form, by id. */
	private static array $deferredForms = [];

	private static int $formSeq = 0;

	/**
	 * #975: the panel renders inside WordPress's own #your-profile form,
	 * and HTML forms cannot nest. A <form> in a row was dropped by the
	 * browser, its fields joined the profile form (and its </form> closed
	 * the profile form early), so Revoke saved the profile instead. Each
	 * row action is now its own form, printed in the admin footer outside
	 * #your-profile; the row's button points at it with form="<id>".
	 */
	private static function deferForm(string $id, string $form): void {
		self::$deferredForms[$id] = $form;

		if (function_exists('add_action') && ! has_action('admin_footer', [self::class, 'printDeferredForms'])) {
			add_action('admin_footer', [self::class, 'printDeferredForms']);
		}
	}

	/** Print (once) the row forms the profile panel deferred. */
	public static function printDeferredForms(): void {
		foreach (self::$deferredForms as $form) {
			echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in renderRevokeGrant()/renderDelegationOff().
		}

		self::$deferredForms = [];
	}

	private static function nextFormId(): string {
		++self::$formSeq;

		return 'ab-oauth-action-' . self::$formSeq;
	}

	/** The hidden nonce field (no id attribute: one page carries many of these forms). */
	private static function nonceInput(): string {
		return '<input type="hidden" name="' . esc_attr(self::NONCE_FIELD) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '" />';
	}

	/** The "Revoke" button for one grant, and its form (deferred, see deferForm()). */
	private static function renderRevokeGrant(int $userId, string $familyId): void {
		$id    = self::nextFormId();
		$form  = '<form id="' . esc_attr($id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"'
			. \AgentBrain\Modules\Admin\Confirm::attr(\AgentBrain\Modules\Admin\Confirm::message(__('Revoke this AI client grant: the client is signed out', 'tomatio'), __('Connect the client again to restore access', 'tomatio'))) . '>';
		$form .= '<input type="hidden" name="action" value="' . esc_attr(self::REVOKE_GRANT_ACTION) . '" />';
		$form .= '<input type="hidden" name="user_id" value="' . esc_attr((string) $userId) . '" />';
		$form .= '<input type="hidden" name="family_id" value="' . esc_attr($familyId) . '" />';
		$form .= self::nonceInput() . '</form>';
		self::deferForm($id, $form);

		echo '<button type="submit" class="button" form="' . esc_attr($id) . '">' . esc_html__('Revoke', 'tomatio') . '</button>';
	}

	/** #629: the "Turn off delegated authority" button for one connection, and its form (deferred). */
	private static function renderDelegationOff(int $userId, string $familyId): void {
		$id    = self::nextFormId();
		$form  = '<form id="' . esc_attr($id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		$form .= '<input type="hidden" name="action" value="' . esc_attr(self::DELEGATION_OFF_ACTION) . '" />';
		$form .= '<input type="hidden" name="user_id" value="' . esc_attr((string) $userId) . '" />';
		$form .= '<input type="hidden" name="family_id" value="' . esc_attr($familyId) . '" />';
		$form .= self::nonceInput() . '</form>';
		self::deferForm($id, $form);

		echo '<button type="submit" class="button" form="' . esc_attr($id) . '">' . esc_html__('Turn off delegated authority', 'tomatio') . '</button> ';
	}

	/**
	 * #975: say what a row action did, on the profile page it returns to
	 * (the handlers redirect with ab_oauth_revoked / ab_oauth_delegation_off).
	 */
	public function renderResultNotice(): void {
		global $pagenow;

		if (! is_string($pagenow) || ! in_array($pagenow, ['profile.php', 'user-edit.php'], true)) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flags the handlers set; nothing is changed here.
		$revoked = isset($_GET['ab_oauth_revoked']) ? sanitize_key((string) wp_unslash($_GET['ab_oauth_revoked'])) : null;
		$deleg   = isset($_GET['ab_oauth_delegation_off']) ? sanitize_key((string) wp_unslash($_GET['ab_oauth_delegation_off'])) : null;
		// phpcs:enable

		if ($revoked !== null) {
			self::notice($revoked === '1', __('Access revoked: that AI client is signed out.', 'tomatio'), __('Nothing was revoked: that authorization was not found.', 'tomatio'));
		}

		if ($deleg !== null) {
			self::notice($deleg === '1', __('Delegated authority is off for that connection. It stays signed in.', 'tomatio'), __('Delegated authority was already off for that connection.', 'tomatio'));
		}
	}

	private static function notice(bool $ok, string $done, string $notDone): void {
		echo '<div class="notice notice-' . esc_attr($ok ? 'success' : 'warning') . ' is-dismissible"><p>' . esc_html($ok ? $done : $notDone) . '</p></div>';
	}

	public function handleDelegationOff(): void {
		self::requireValidNonce();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- requireValidNonce() above already verifies self::NONCE_FIELD or dies.
		$targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
		$familyId     = isset($_POST['family_id']) ? sanitize_text_field((string) wp_unslash($_POST['family_id'])) : '';
		// phpcs:enable

		if (! current_user_can('edit_user', $targetUserId)) {
			wp_die(esc_html__('You are not allowed to manage this user\'s authorizations.', 'tomatio'), 403);
		}

		wp_safe_redirect(self::processDelegationOff($targetUserId, $familyId));
		exit;
	}

	/**
	 * #629: turn delegated authority off for one connection (ownership-
	 * scoped: the person's own, or an agent connection they authorized).
	 * Split out so it is testable without the redirect and exit.
	 */
	public static function processDelegationOff(int $targetUserId, string $familyId): string {
		$changed = DelegatedAuthority::revokeFor($targetUserId, $familyId);

		return (string) add_query_arg('ab_oauth_delegation_off', $changed ? '1' : '0', get_edit_user_link($targetUserId));
	}

	public function handleRevokeGrant(): void {
		self::requireValidNonce();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- requireValidNonce() above already verifies self::NONCE_FIELD or dies.
		$targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
		$familyId     = isset($_POST['family_id']) ? sanitize_text_field((string) wp_unslash($_POST['family_id'])) : '';
		// phpcs:enable

		if (! current_user_can('edit_user', $targetUserId)) {
			wp_die(esc_html__('You are not allowed to manage this user\'s authorizations.', 'tomatio'), 403);
		}

		wp_safe_redirect(self::processRevokeGrant($targetUserId, $familyId));
		exit;
	}

	/**
	 * Ownership-scoped revoke + audit, split out from handleRevokeGrant() so
	 * it is directly testable without the trailing wp_safe_redirect()+exit
	 * (the capability check above it remains covered via wp_die() /
	 * WPDieException, which the WP test suite does not actually exit on).
	 */
	public static function processRevokeGrant(int $targetUserId, string $familyId): string {
		$revoked = TokenStore::revokeGrantForUser($targetUserId, $familyId);
		Audit::emit(get_current_user_id(), 'oauth.revoke', $revoked ? 'ok' : 'denied', ['target_user_id' => $targetUserId]);

		return (string) add_query_arg('ab_oauth_revoked', $revoked ? '1' : '0', get_edit_user_link($targetUserId));
	}

	private static function requireValidNonce(): void {
		if (! isset($_POST[self::NONCE_FIELD]) || ! wp_verify_nonce((string) wp_unslash($_POST[self::NONCE_FIELD]), self::NONCE_ACTION)) {
			wp_die(esc_html__('Security check failed.', 'tomatio'), 403);
		}
	}

	/**
	 * The OAuth Clients UI body. Rendered as the "Connected apps" tab of
	 * Admin\AgentsPage (admin.php?page=agent-brain-agents&tab=oauth); no
	 * longer registers its own admin_menu page (see class docblock).
	 * $skipWrap omits the outer `<div class="wrap"><h1>` when the caller
	 * (AgentsPage) already renders its own page chrome and nav-tab-wrapper,
	 * matching the renderBody() convention used by SettingsHub's tabs.
	 */
	public function renderClientsPage(bool $skipWrap = false): void {
		if (! current_user_can(Capabilities::CAP_MANAGE)) {
			wp_die(esc_html__('You do not have permission to view this page.', 'tomatio'), 403);
		}

		$clients = TokenStore::activeClients();

		if (! $skipWrap) {
			echo '<div class="wrap"><h1>' . esc_html__('MCP OAuth Clients', 'tomatio') . '</h1>';
		}

		if ($clients === []) {
			\AgentBrain\Modules\Admin\EmptyState::render(
				__('No connected apps', 'tomatio'),
				__('Connected apps are AI clients (such as Claude or ChatGPT) that a person has authorised to use this brain. None has connected yet.', 'tomatio'),
				['label' => __('Connect an AI', 'tomatio'), 'url' => admin_url('admin.php?page=agent-brain-connect')]
			);

			if (! $skipWrap) {
				echo '</div>';
			}

			return;
		}

		echo '<div class="ab-table-wrap"><table class="widefat"><thead><tr><th scope="col">' . esc_html__('Client', 'tomatio') . '</th><th scope="col">'
			. esc_html__('Active grants', 'tomatio') . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__('Actions', 'tomatio') . '</span></th></tr></thead><tbody>';

		foreach ($clients as $client) {
			echo '<tr><td>' . esc_html($client['client_id']) . '</td><td>' . esc_html((string) $client['grants']) . '</td><td>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . \AgentBrain\Modules\Admin\Confirm::attr(\AgentBrain\Modules\Admin\Confirm::message(__('Revoke every grant for this AI client: it is signed out everywhere', 'tomatio'), __('Connect the client again to restore access', 'tomatio'))) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Confirm::attr() escapes.
			echo '<input type="hidden" name="action" value="' . esc_attr(self::REVOKE_CLIENT_ACTION) . '" />';
			echo '<input type="hidden" name="client_id" value="' . esc_attr($client['client_id']) . '" />';
			wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
			echo '<button type="submit">' . esc_html__('Revoke all grants for this client', 'tomatio') . '</button>';
			echo '</form></td></tr>';
		}

		echo '</tbody></table></div>';

		self::renderDcrClients();

		if (! $skipWrap) {
			echo '</div>';
		}
	}

	/**
	 * #239: the dynamically registered clients (RFC 7591), listed for the
	 * curator with per-client revoke. Rendered below the grants table on
	 * the "Connected apps" tab; nothing renders when only CIMD clients
	 * have connected.
	 */
	private static function renderDcrClients(): void {
		$clients = Dcr::listClients();

		if ($clients === []) {
			return;
		}

		echo '<h2>' . esc_html__('Registered clients (dynamic registration)', 'tomatio') . '</h2>';
		echo '<p class="description">' . esc_html__('AI clients that registered themselves over OAuth (RFC 7591) instead of identifying with a metadata document. Revoking one signs it out everywhere; it can register again at its next connect.', 'tomatio') . '</p>';
		echo '<div class="ab-table-wrap"><table class="widefat"><thead><tr><th scope="col">' . esc_html__('Client', 'tomatio') . '</th><th scope="col">'
			. esc_html__('Registered', 'tomatio') . '</th><th scope="col">' . esc_html__('Expires', 'tomatio') . '</th><th scope="col">'
			. esc_html__('Active grants', 'tomatio') . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__('Actions', 'tomatio') . '</span></th></tr></thead><tbody>';

		foreach ($clients as $client) {
			$label = $client['client_name'] !== '' && $client['client_name'] !== $client['client_id']
				? sprintf('%1$s (%2$s)', $client['client_name'], $client['client_id'])
				: $client['client_id'];

			echo '<tr><td>' . esc_html($label) . '</td>';
			echo '<td>' . esc_html(mysql2date(get_option('date_format'), $client['created'])) . '</td>';
			echo '<td>' . esc_html(mysql2date(get_option('date_format'), $client['expires'])) . '</td>';
			echo '<td>' . esc_html((string) $client['grants']) . '</td><td>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . \AgentBrain\Modules\Admin\Confirm::attr(\AgentBrain\Modules\Admin\Confirm::message(__('Revoke this registered AI client: it is signed out everywhere', 'tomatio'), __('The client can register again at its next connect', 'tomatio'))) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Confirm::attr() escapes.
			echo '<input type="hidden" name="action" value="' . esc_attr(self::REVOKE_DCR_CLIENT_ACTION) . '" />';
			echo '<input type="hidden" name="client_id" value="' . esc_attr($client['client_id']) . '" />';
			wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
			echo '<button type="submit">' . esc_html__('Revoke this client', 'tomatio') . '</button>';
			echo '</form></td></tr>';
		}

		echo '</tbody></table></div>';
	}

	public function handleRevokeDcrClient(): void {
		if (! current_user_can(Capabilities::CAP_MANAGE)) {
			wp_die(esc_html__('You do not have permission to do this.', 'tomatio'), 403);
		}

		self::requireValidNonce();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- requireValidNonce() above already verifies self::NONCE_FIELD or dies.
		$clientId = isset($_POST['client_id']) ? sanitize_text_field((string) wp_unslash($_POST['client_id'])) : '';

		wp_safe_redirect(self::processRevokeDcrClient($clientId));
		exit;
	}

	/**
	 * Curator-scoped DCR client revoke + audit, split out from
	 * handleRevokeDcrClient() so it is directly testable without the
	 * trailing wp_safe_redirect()+exit.
	 */
	public static function processRevokeDcrClient(string $clientId): string {
		$revoked = Dcr::isManaged($clientId) ? Dcr::revoke($clientId) : false;

		$target = admin_url('admin.php?page=' . AgentsPage::SLUG . '&tab=' . AgentsPage::TAB_OAUTH);

		return (string) add_query_arg('ab_oauth_revoked', $revoked ? '1' : '0', $target);
	}

	public function handleRevokeClient(): void {
		if (! current_user_can(Capabilities::CAP_MANAGE)) {
			wp_die(esc_html__('You do not have permission to do this.', 'tomatio'), 403);
		}

		self::requireValidNonce();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- requireValidNonce() above already verifies self::NONCE_FIELD or dies.
		$clientId = isset($_POST['client_id']) ? esc_url_raw((string) wp_unslash($_POST['client_id'])) : '';

		wp_safe_redirect(self::processRevokeClient($clientId));
		exit;
	}

	/**
	 * Curator-scoped whole-client revoke + audit, split out from
	 * handleRevokeClient() so it is directly testable without the trailing
	 * wp_safe_redirect()+exit.
	 */
	public static function processRevokeClient(string $clientId): string {
		$count = $clientId === '' ? 0 : TokenStore::revokeAllForClient($clientId);

		Audit::emit(get_current_user_id(), 'oauth.revoke', $count > 0 ? 'ok' : 'denied', ['client_id' => $clientId, 'count' => $count]);

		$target = admin_url('admin.php?page=' . AgentsPage::SLUG . '&tab=' . AgentsPage::TAB_OAUTH);

		return (string) add_query_arg('ab_oauth_revoked', (string) $count, $target);
	}
}
