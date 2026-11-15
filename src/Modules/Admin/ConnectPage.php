<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Audit\Repository as AuditRepository;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Mcp\AdapterBridge;
use AgentBrain\Modules\Mcp\ServerIdentity;
use AgentBrain\Modules\Mcp\ToolHints;
use AgentBrain\Modules\OAuth\Connections;
use AgentBrain\Modules\Review\InboxItems;

/**
 * TomaTío > Connect an AI: everything a person needs to point an AI
 * client at this brain, so nobody has to know the MCP route by heart. Three
 * steps (#751): pick the AI, add the connector (the address with a copy
 * button and that AI's own steps), and check it works (green once an AI has
 * called the brain). Then the person's connections and the agents to sign in
 * as, each with links to set a password or create an application password.
 */
final class ConnectPage {

	public const CAPABILITY = Capabilities::CAP_MANAGE;
	public const SLUG       = 'agent-brain-connect';

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addMenu']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
		add_action('admin_notices', [self::class, 'renderAdminNotice']);
	}

	public static function addMenu(): void {
		if (! function_exists('add_submenu_page')) {
			return;
		}

		add_submenu_page(
			Menu::PARENT,
			__('Connect an AI', 'tomatio'),
			__('Connect an AI', 'tomatio'),
			self::CAPABILITY,
			self::SLUG,
			[self::class, 'render']
		);
	}

	public static function mcpUrl(): string {
		return \AgentBrain\Core\RestNamespaces::mcpUrl();
	}

	/** A short connector name for commands, from the site title. */
	public static function connectorSlug(): string {
		$slug = sanitize_title((string) get_bloginfo('name'));

		return $slug !== '' ? $slug . '-brain' : 'agent-brain';
	}

	/** The AIs step 1 offers; each shows only its own steps (#751). */
	public const CLIENTS = ['claude', 'claude-code', 'chatgpt', 'other'];

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to view this page.', 'tomatio'), 403);
		}

		$url    = self::mcpUrl();
		$brand  = HomePage::view();
		$client = self::client();

		// #853: how far people get toward connecting, once a day.
		ConnectProgress::pageViewed((int) get_current_user_id(), $client);

		/* translators: %s: the brain's name. */
		$about = '<p>' . esc_html(sprintf(__('Give your AI the memory of %s. Each AI signs in as one agent: it reads and writes only that agent\'s scopes, and everything it does appears in Activity.', 'tomatio'), $brand['name'])) . '</p>';
		$head  = Ui::head(__('Connect an AI', 'tomatio'), esc_html__('Your Tío signs in as one agent and sees only its scopes', 'tomatio'), '', $about);

		echo '<div class="wrap ab-admin ab-admin-connect">';
		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		// #473: the copy button keeps the brain's accent.
		echo '<section class="ab-ui-card ab-connect-card" style="--ab-brand:' . esc_attr($brand['accent']) . '">';
		echo '<h2 class="ab-ui-kicker">' . esc_html__('1 · Which AI?', 'tomatio') . '</h2>';
		self::renderPick($client);

		echo '<h2 class="ab-ui-kicker">' . esc_html__('2 · Add the connector', 'tomatio') . '</h2>';
		echo '<div class="ab-copy-row ab-copy-hero"><code id="ab-mcp-url">' . esc_html($url) . '</code>';
		echo '<button type="button" class="button button-primary button-hero" data-ab-copy="' . esc_attr($url) . '" data-ab-copy-kind="address">' . esc_html__('Copy address', 'tomatio') . '</button></div>';
		self::renderSteps($client);
		// #962: one address. Connecting as yourself on it switches agents per
		// task, so the role-switching address is no longer offered here; the
		// mcp-roles route keeps working for connectors that use it.

		echo '<h2 class="ab-ui-kicker">' . esc_html__('3 · Check it works', 'tomatio') . '</h2>';
		echo self::checkHtml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo '</section>';

		self::renderConnections();
		self::renderAgents();

		echo '</div>';
	}

	private static function client(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view choice.
		$client = isset($_GET['ai']) ? sanitize_key(wp_unslash((string) $_GET['ai'])) : '';

		return in_array($client, self::CLIENTS, true) ? $client : 'claude';
	}

	private static function renderPick(string $current): void {
		$tiles = [
			'claude'      => [__('Claude', 'tomatio'), __('claude.ai and Claude Desktop', 'tomatio')],
			'claude-code' => [__('Claude Code', 'tomatio'), __('In the terminal', 'tomatio')],
			'chatgpt'     => [__('ChatGPT', 'tomatio'), __('Once for your workspace', 'tomatio')],
			'other'       => [__('Other MCP client', 'tomatio'), __('Any client over HTTP', 'tomatio')],
		];

		echo '<ul class="ab-connect-pick" aria-label="' . esc_attr__('Which AI?', 'tomatio') . '">';
		foreach ($tiles as $key => [$label, $hint]) {
			$url = add_query_arg(['page' => self::SLUG, 'ai' => $key], admin_url('admin.php'));
			echo '<li><a href="' . esc_url($url) . '"' . ($key === $current ? ' aria-current="true"' : '') . '><b>' . esc_html($label) . '</b><small>' . esc_html($hint) . '</small></a></li>';
		}
		echo '</ul>';
	}

	private static function renderSteps(string $client): void {
		$name = (string) get_bloginfo('name');
		$slug = self::connectorSlug();
		$url  = self::mcpUrl();

		if ($client === 'claude-code') {
			$command = sprintf('claude mcp add --transport http %s %s --header "Authorization: Basic BASE64"', $slug, $url);
			echo '<p>' . esc_html__('Claude Code signs in as one agent with an application password, sent as a header.', 'tomatio') . '</p><ol class="ab-connect-steps">';
			echo '<li>' . esc_html__('Create an application password for the agent (link under Agents to sign in as, below).', 'tomatio') . '</li>';
			echo '<li>' . esc_html__('Encode "login:application password" in base64, for example:', 'tomatio') . ' <code>printf \'login:xxxx xxxx xxxx\' | base64</code></li>';
			echo '<li>' . esc_html__('Add the server, replacing BASE64 with the result:', 'tomatio') . '<div class="ab-copy-row"><code>' . esc_html($command) . '</code>';
			echo '<button type="button" class="button" data-ab-copy="' . esc_attr($command) . '" data-ab-copy-kind="claude_code_command">' . esc_html__('Copy', 'tomatio') . '</button></div></li>';
			echo '</ol>';
			/* translators: %s: the connector slug used in the claude mcp commands. */
			echo '<p class="description">' . esc_html(sprintf(__('To refresh the tool list after a brain update: run `claude mcp remove %s`, then add the server again with the command above.', 'tomatio'), $slug)) . '</p>';

			return;
		}

		if ($client === 'chatgpt' || $client === 'other') {
			self::renderAppNotes($name, $client === 'chatgpt');

			return;
		}

		echo '<ol class="ab-connect-steps">';
		echo '<li>' . esc_html__('In claude.ai, open Customize, then Connectors, click Add and choose Custom connector. (In Claude Desktop: Settings, then Connectors.)', 'tomatio') . '</li>';
		/* translators: %s: site name, suggested as the connector name. */
		echo '<li>' . esc_html(sprintf(__('Name it "%s brain" and paste the connector address.', 'tomatio'), $name)) . '</li>';
		echo '<li>' . esc_html__('Click Connect, sign in to this site if asked, and click Allow access. You connect as yourself; to connect a bot as its own agent, open Advanced, or choose "Not you?" and sign in as that agent.', 'tomatio') . '</li>';
		// #674: Claude starts every tool on Needs approval, and the brain can't change that.
		echo '<li>' . esc_html__('Claude starts every tool on "Needs approval". Open the connector, and under Tool permissions set both Read-only tools and Write/delete tools to Always allow. The brain keeps its own history and review step, so that is safe.', 'tomatio');
		echo '<details class="ab-connect-advanced"><summary>' . esc_html__('Tools to keep on approval or block', 'tomatio') . '</summary>';
		echo esc_html__('Set these to Needs approval:', 'tomatio') . '<ul class="ul-disc">';
		foreach (ToolHints::ASK_FIRST as $tool) {
			echo '<li>' . esc_html(ToolHints::title($tool)) . '</li>';
		}
		echo '</ul>' . esc_html__('And set this one to Blocked:', 'tomatio') . '<ul class="ul-disc">';
		foreach (ToolHints::BLOCKED as $tool) {
			echo '<li>' . esc_html(ToolHints::title($tool)) . '</li>';
		}
		echo '</ul></details></li>';
		echo '</ol>';
		echo '<p class="description">' . esc_html__('After a brain update the connector keeps the tool list it fetched when it connected. To refresh it: remove the connector here and add it again (Customize → Connectors → Add → Custom connector), then set the tool permissions again.', 'tomatio') . '</p>';
	}

	/**
	 * Step 3: green once an AI has called the brain, otherwise what to ask it.
	 */
	public static function checkHtml(): string {
		$rows = Db::getResults(
			'SELECT actor_id, ts FROM ' . AuditRepository::tableName() . " WHERE event = 'ability.invoked' AND status = 'ok' AND actor_id > 0 ORDER BY id DESC LIMIT %d",
			[1]
		);
		$activity = '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=agent-brain-audit')) . '">' . esc_html__('See Activity', 'tomatio') . '</a>';

		if ($rows !== []) {
			$who = InboxItems::who((int) $rows[0]['actor_id']);

			return Ui::callout(
				'ok',
				/* translators: 1: how long ago, 2: agent or person name */
				sprintf(__('It works: the last call came %1$s, as %2$s.', 'tomatio'), wp_strip_all_tags(DateFormat::ago((string) $rows[0]['ts'])), $who['name']),
				'',
				$activity
			);
		}

		$again = '<a class="button button-small" href="' . esc_url(add_query_arg(['page' => self::SLUG, 'ai' => self::client()], admin_url('admin.php'))) . '">' . esc_html__('Check again', 'tomatio') . '</a>';

		return Ui::callout(
			'info',
			__('Waiting for the first call…', 'tomatio'),
			__('Ask your AI: “What does the brain know about this project?” This turns green once it answers from the brain.', 'tomatio'),
			$again
		);
	}

	/**
	 * #239: short per-app notes for the MCP apps the product wants to
	 * support, so the Connect an AI page answers "how do I point THIS app
	 * at my brain". Generic by design: the per-app verification matrix
	 * (sign-in, tools listed, memory write, act-as, reconnect) is a human
	 * follow-up with the real apps — these notes only describe the
	 * connection mechanics this server offers (OAuth discovery, dynamic
	 * client registration, Basic header).
	 */
	private static function renderAppNotes(string $name, bool $chatgptOnly): void {
		$oauth = __('Add the server at the connector address above and connect: the app discovers the sign-in automatically (OAuth 2.1 with dynamic client registration) and you click Allow access on the consent screen, as yourself.', 'tomatio');
		$basic = __('Add the server at the connector address above with an Authorization header: create an application password for the agent (link under Agents to sign in as, below), then base64-encode "login:application password" into "Authorization: Basic …". This path has no consent screen: the client acts as that one agent.', 'tomatio');

		/* translators: %s: site name, suggested as the MCP server name. */
		$named = sprintf(__('Name it "%s brain". ', 'tomatio'), $name);

		if ($chatgptOnly) {
			self::renderChatGpt($oauth);

			return;
		}

		$apps = [
			__('Cursor', 'tomatio')                   => $named . $oauth,
			__('VS Code (GitHub Copilot)', 'tomatio') => $named . $oauth,
			__('Gemini CLI', 'tomatio')               => $named . $oauth,
			__('OpenAI Codex', 'tomatio')             => $named . $oauth,
			__('Windsurf', 'tomatio')                 => $named . $oauth,
			__('Zed (no OAuth sign-in)', 'tomatio')   => $named . $basic . ' ' . __('If the app supports an mcp-remote-style helper that runs the OAuth browser flow for you, that works too.', 'tomatio'),
		];

		echo '<p>' . esc_html__('Any client that speaks MCP over HTTP can connect at the connector address: Streamable HTTP, with OAuth 2.1 sign-in (discovery and dynamic client registration) or HTTP Basic with an agent\'s application password.', 'tomatio') . '</p>';
		echo '<details class="ab-connect-advanced"><summary>' . esc_html__('How popular apps connect', 'tomatio') . '</summary>';
		echo '<p class="description">' . esc_html__('These notes describe the connection mechanics only; which sign-in an app offers is decided by the app itself.', 'tomatio') . '</p><ul>';
		foreach ($apps as $app => $note) {
			echo '<li><strong>' . esc_html($app) . ':</strong> ' . esc_html($note) . '</li>';
		}
		echo '</ul></details>';
		echo '<p class="description">' . esc_html__('After a brain update, remove the server in the client\'s settings and add it again to refresh the tool list.', 'tomatio') . '</p>';
	}

	/**
	 * #778: ChatGPT shows the app name, description and icon entered in its
	 * "Add custom MCP server" form, never what the brain sends
	 * (serverInfo), so the steps follow that form field by field. The icon
	 * comes first and is its own step: the field is optional, nothing
	 * prompts for it, and without it the app has a blank icon.
	 *
	 * #784: like a workspace connection in Claude, a workspace admin adds
	 * the brain once and publishes it to the workspace; every member then
	 * signs in as themselves, and nobody else fills in the form. Adding it
	 * just for yourself is the fallback. Website, Category and Version on
	 * ChatGPT's app page come from a directory listing only.
	 */
	private static function renderChatGpt(string $oauth): void {
		$icon = ServerIdentity::iconUrl();
		$copy = static function (string $value, string $kind): string {
			return '<div class="ab-copy-row"><code>' . esc_html($value) . '</code>'
				. '<button type="button" class="button" data-ab-copy="' . esc_attr($value) . '" data-ab-copy-kind="' . esc_attr($kind) . '">' . esc_html__('Copy', 'tomatio') . '</button></div>';
		};

		echo '<p>' . esc_html__('Add the brain once for your whole ChatGPT workspace, then everyone on the team connects as themselves. This takes a workspace admin. ChatGPT shows only what you enter in its form, not the brain\'s own name, description or icon, so fill in every field, the icon too.', 'tomatio') . '</p>';
		echo '<ol class="ab-connect-steps">';
		echo '<li>' . esc_html__('In ChatGPT, open Plugins, click +, and choose Add custom MCP server.', 'tomatio') . '</li>';
		if ($icon !== '') {
			echo '<li><strong>' . esc_html__('Icon:', 'tomatio') . '</strong> ' . esc_html__('download this icon and upload it in the form\'s Icon field. ChatGPT does not ask for it, and without it the app has a blank icon.', 'tomatio');
			echo '<div class="ab-connect-icon"><img src="' . esc_url($icon) . '" alt="" width="48" height="48" />';
			echo '<a class="button button-primary" href="' . esc_url($icon) . '" download="' . esc_attr(self::connectorSlug() . '-icon') . '">' . esc_html__('Download icon', 'tomatio') . '</a></div></li>';
		}
		echo '<li><strong>' . esc_html__('Name:', 'tomatio') . '</strong>' . $copy(self::chatGptName(), 'chatgpt_name') . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $copy escapes.
		echo '<li><strong>' . esc_html__('Description:', 'tomatio') . '</strong>' . $copy(ServerIdentity::description(), 'chatgpt_description') . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $copy escapes.
		echo '<li><strong>' . esc_html__('Server URL:', 'tomatio') . '</strong> ' . esc_html__('the connector address above.', 'tomatio') . '</li>';
		echo '<li><strong>' . esc_html__('Authentication:', 'tomatio') . '</strong> ' . esc_html__('OAuth. Then choose Create as a plugin and sign in.', 'tomatio') . ' ' . esc_html($oauth) . '</li>';
		echo '<li><strong>' . esc_html__('Publish it to your workspace:', 'tomatio') . '</strong> ' . esc_html__('in Plugins, open Personal, click the plugin\'s ⋯ menu, choose Publish, and pick the workspace roles that should have it.', 'tomatio') . '</li>';
		echo '</ol>';
		echo '<p>' . esc_html__('Everyone in those roles then finds the brain among the workspace\'s plugins, with this name, description and icon. Each person connects and signs in with their own account, so the brain knows who is asking.', 'tomatio') . '</p>';
		// #929: ChatGPT holds back every tool that writes until the person allows all
		// tools on the plugin's page: without it nothing reaches TíoDay, memories,
		// comments or documents, and ChatGPT may still say it saved.
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Then allow all tools, or ChatGPT cannot save.', 'tomatio') . '</strong> ' . esc_html__('On the brain\'s page in ChatGPT, set Permissions to Allow all tools and New tools to Enable all new tools. Until then ChatGPT reads the brain but holds back every tool that writes: nothing reaches TíoDay, memories, comments or documents. Each person checks this on their own account, after a workspace admin has published it too.', 'tomatio') . '</p></div>';
		echo '<details class="ab-connect-advanced"><summary>' . esc_html__('Not a workspace admin, or no workspace?', 'tomatio') . '</summary>';
		echo '<p class="description">' . esc_html__('Add it just for yourself with the same steps, and skip Publish: it stays in your personal plugins. If ChatGPT does not offer custom MCP servers, turn on Developer mode first (Settings, then Apps, then Advanced settings).', 'tomatio') . '</p></details>';
		echo '<p class="description">' . esc_html__('ChatGPT\'s app page shows Website, Category and Version only for apps listed in its directory. For a brain added this way they stay "Unavailable", "Other" and 1.0.0, and the brain cannot change them.', 'tomatio') . '</p>';
		echo '<p class="description">' . esc_html__('After a brain update, remove the plugin and add it again to refresh the tool list.', 'tomatio') . '</p>';
	}

	/** The app name to type in ChatGPT: the brain's name, then TomaTío, accent and all. */
	public static function chatGptName(): string {
		$name = ServerIdentity::brandName();

		/* translators: %s: the brain's name. */
		return $name === 'TomaTío' ? $name : sprintf(__('%s - TomaTío', 'tomatio'), $name);
	}

	/**
	 * Copy buttons, only on this screen. A click also tells the brain which
	 * button it was (#853, ConnectProgress), never what was copied.
	 */
	public static function enqueue(string $hook): void {
		if (! Menu::isPageHook($hook, self::SLUG)) {
			return;
		}

		wp_register_script('agent-brain-connect', false, [], '1', true);
		wp_enqueue_script('agent-brain-connect');
		wp_add_inline_script('agent-brain-connect', self::copyScript());
	}

	/** The inline copy-button script. */
	public static function copyScript(): string {
		$beacon = [
			'url'    => admin_url('admin-ajax.php'),
			'action' => ConnectProgress::AJAX_ACTION,
			'nonce'  => wp_create_nonce(ConnectProgress::NONCE_ACTION),
		];

		return 'var abCopyBeacon=' . wp_json_encode($beacon) . ';'
			. 'document.querySelectorAll("[data-ab-copy]").forEach(function(b){b.addEventListener("click",function(){'
			. 'if(b.dataset.abCopyKind&&navigator.sendBeacon){var f=new FormData();f.append("action",abCopyBeacon.action);f.append("_ajax_nonce",abCopyBeacon.nonce);f.append("kind",b.dataset.abCopyKind);navigator.sendBeacon(abCopyBeacon.url,f);}'
			. 'navigator.clipboard.writeText(b.dataset.abCopy).then(function(){var t=b.textContent;b.textContent='
			. wp_json_encode(__('Copied', 'tomatio'))
			. ';setTimeout(function(){b.textContent=t;},1500);});});});';
	}

	/**
	 * #225: the wp-admin notice for a person whose connections need
	 * attention (out-of-date tool list, reconnect-required marker, or
	 * sign-in expiring within 7 days). Links to the Connect an AI page for
	 * site managers, to the profile otherwise.
	 */
	public static function renderAdminNotice(): void {
		$userId = (int) get_current_user_id();
		if ($userId <= 0 || ! Connections::needsAttention($userId, AdapterBridge::currentSurfaceHash())) {
			return;
		}

		$url = current_user_can(self::CAPABILITY)
			? admin_url('admin.php?page=' . self::SLUG)
			: admin_url('profile.php');

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__('One or more of your AI connections needs attention: its tool list is out of date or its sign-in is expiring. Reconnect the client in its connector settings.', 'tomatio');
		echo ' <a href="' . esc_url($url) . '">' . esc_html__('Review your connections', 'tomatio') . '</a>';
		echo '</p></div>';
	}

	/**
	 * #225: one row per connection (OAuth client as the current user) with
	 * connected date, last seen, tool-set hash and status. Rendered only
	 * when the person has at least one grant or recorded connection.
	 */
	private static function renderConnections(): void {
		$statuses = Connections::statusesFor((int) get_current_user_id(), AdapterBridge::currentSurfaceHash());
		if ($statuses === []) {
			return;
		}

		echo '<section class="ab-ui-card"><h2 class="ab-ui-kicker">' . esc_html__('Your connections', 'tomatio') . '</h2>';
		echo '<p class="description">' . esc_html__('One row per AI client connected as you. When a status is not "Current", reconnect the client in its own connector settings (the per-client steps above) to refresh it.', 'tomatio') . '</p>';
		echo '<div class="ab-table-wrap"><table class="widefat striped ab-connect-connections"><thead><tr>';
		foreach ([__('Client', 'tomatio'), __('Connected', 'tomatio'), __('Last seen', 'tomatio'), __('Tool list', 'tomatio'), __('Status', 'tomatio')] as $heading) {
			echo '<th scope="col">' . esc_html($heading) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ($statuses as $status) {
			echo '<tr><td><code>' . esc_html($status['client_id']) . '</code></td>';
			echo '<td>' . esc_html($status['connected'] !== '' ? mysql2date(get_option('date_format'), $status['connected']) : '—') . '</td>';
			echo '<td>' . esc_html($status['last_seen'] !== null && $status['last_seen'] !== '' ? mysql2date(get_option('date_format'), $status['last_seen']) : __('Never', 'tomatio')) . '</td>';
			echo '<td><code>' . esc_html($status['tool_set_hash'] !== null ? substr($status['tool_set_hash'], 0, 12) : '—') . '</code>';
			if ($status['out_of_date']) {
				echo ' <span class="description">' . esc_html__('out of date', 'tomatio') . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html(self::statusLabel($status));
			if ($status['notice'] !== null && $status['notice'] !== '') {
				echo '<br><span class="description">' . esc_html($status['notice']) . '</span>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table></div></section>';
	}

	/**
	 * @param array{status:string, reconnect_required:?string, expires_in_days:?int, expired:bool} $status
	 */
	private static function statusLabel(array $status): string {
		switch ($status['status']) {
			case 'reconnect-required':
				return sprintf(
					/* translators: %s: the plugin version that requires a reconnect. */
					__('Reconnect required since version %s', 'tomatio'),
					(string) $status['reconnect_required']
				);
			case 'update-available':
				return __('Update available — reconnect to refresh the tool list', 'tomatio');
			case 'expiring':
				return $status['expired']
					? __('Sign-in expired — reconnect to sign in again', 'tomatio')
					: sprintf(
						/* translators: %d: whole days until the sign-in expires. */
						__('Sign-in expires in %d days — reconnect to stay signed in', 'tomatio'),
						(int) $status['expires_in_days']
					);
			default:
				return __('Current', 'tomatio');
		}
	}

	private static function renderAgents(): void {
		$agents = get_users(['role' => Capabilities::ROLE_AGENT, 'orderby' => 'display_name', 'order' => 'ASC']);

		echo '<section class="ab-ui-card"><h2 class="ab-ui-kicker">' . esc_html__('Agents to sign in as', 'tomatio') . '</h2>';

		if ($agents === []) {
			echo '<p>' . esc_html__('No agents yet. Add one under Agents, then give it scopes.', 'tomatio') . '</p></section>';
			return;
		}

		echo '<div class="ab-table-wrap"><table class="widefat striped ab-connect-agents"><thead><tr>';
		foreach ([__('Agent', 'tomatio'), __('Login', 'tomatio'), __('Scopes (read / write)', 'tomatio'), __('Credentials', 'tomatio')] as $heading) {
			echo '<th scope="col">' . esc_html($heading) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ($agents as $agent) {
			$edit = get_edit_user_link($agent->ID);
			echo '<tr><td><strong>' . esc_html($agent->display_name) . '</strong>';
			if ($agent->description !== '') {
				echo '<br><span class="description">' . esc_html($agent->description) . '</span>';
			}
			echo '</td><td><code>' . esc_html($agent->user_login) . '</code></td>';
			echo '<td>' . esc_html(self::scopeNames($agent->ID, Scopes::META_READ_SCOPES)) . ' / ' . esc_html(self::scopeNames($agent->ID, Scopes::META_WRITE_SCOPES)) . '</td>';
			echo '<td><a href="' . esc_url($edit . '#password') . '">' . esc_html__('Set password', 'tomatio') . '</a>'
				. ' &middot; <a href="' . esc_url($edit . '#application-passwords-section') . '">' . esc_html__('Application password', 'tomatio') . '</a></td></tr>';
		}

		echo '</tbody></table></div></section>';
	}

	private static function scopeNames(int $userId, string $metaKey): string {
		$names = [];
		foreach ((array) get_user_meta($userId, $metaKey, true) as $termId) {
			$term = get_term((int) $termId, Scopes::TAXONOMY);
			if ($term instanceof \WP_Term) {
				$names[] = $term->name;
			}
		}

		return $names !== [] ? implode(', ', $names) : __('none', 'tomatio');
	}
}
