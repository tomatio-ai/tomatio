<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Cloud;

use AgentBrain\Modules\Admin\Confirm;
use AgentBrain\Modules\Admin\Notices;
use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Compression\Endpoint;
use AgentBrain\Modules\Compression\EndpointSettings;
use AgentBrain\Modules\JobsAdmin\Page as JobsPage;
use AgentBrain\Modules\JobsAdmin\Runs;

/**
 * Admin settings screen: the only place a curator/admin can opt into the
 * cloud connector. Every state-changing action requires the ab_manage
 * capability AND a matching nonce — this is the sole gate that lets
 * ab_cloud_enabled ever become true.
 *
 * Admin IA wave (Unit S): this used to be its own admin_options page. It
 * is now the default tab of SettingsHub (agent-brain-settings); the old
 * slug (ab-cloud) stays registered as a hidden page under the core-hidden
 * `options.php` parent purely so old bookmarks/links redirect to the hub
 * instead of 404ing.
 */
final class Settings {

	public const CAPABILITY        = 'ab_manage';
	public const PAGE_SLUG         = 'ab-cloud';
	public const ACTION_CONNECT    = 'ab_cloud_connect';
	public const ACTION_DISCONNECT = 'ab_cloud_disconnect';

	public static function registerPage(): void {
		$hook = add_submenu_page(
			'options.php',
			__('TomaTío Cloud', 'agent-brain'),
			__('TomaTío Cloud', 'agent-brain'),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[self::class, 'renderPage']
		);

		if (is_string($hook)) {
			add_action('load-' . $hook, [self::class, 'redirectToHub']);
		}
	}

	/**
	 * Fired on `load-{hook}` for the old ab-cloud slug, before any output —
	 * forwards straight to the Cloud tab of the settings hub, preserving
	 * whatever query args the old link carried (minus `page`).
	 */
	public static function redirectToHub(): void {
		wp_safe_redirect(self::hubRedirectUrl());
		exit;
	}

	public static function hubRedirectUrl(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect, no state change.
		$args = wp_unslash($_GET);
		unset($args['page']);

		/** @var array<string, string> $args */
		return SettingsHub::url(SettingsHub::TAB_CLOUD, $args);
	}

	public static function renderPage(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage the cloud connection.', 'agent-brain'), 403);
		}

		echo '<div class="wrap"><h1>' . esc_html__('TomaTío Cloud', 'agent-brain') . '</h1>';
		self::renderBody();
		echo '</div>';
	}

	/**
	 * The tab body, without the outer `<div class="wrap"><h1>` — called by
	 * SettingsHub for the Summarising tab (capability already checked
	 * there).
	 *
	 * #751: a problem first, then the four ways old memories can be
	 * summarised with the one in use marked, then each way's own settings.
	 * The long explanation folds away.
	 */
	public static function renderBody(): void {
		$connected = Connector::isConnected();
		$state     = JobsPage::providerState();
		$inUse     = $state['provider'];

		self::renderResultNotice();

		if ($inUse !== null && $state['failing']) {
			$latest = '';
			foreach (JobsPage::recentRuns() as $run) {
				if ($run['outcome'] === 'failed') {
					$latest = Runs::describe($run['reason'], $run['message']);
					break;
				}
			}
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::callout() escapes what it emits.
			echo Ui::callout(
				'bad',
				__('Summarising is failing.', 'agent-brain'),
				esc_html(trim(sprintf(
					/* translators: %s: the way memories are summarised, such as "Your OpenAI-compatible endpoint" */
					__('%s rejected the recent runs.', 'agent-brain'),
					$state['label']
				) . ' ' . $latest . ' ' . __('Check its settings below, or set up another way. Nothing is lost: memories wait for the next good run.', 'agent-brain'))),
				'<a class="button" href="' . esc_url(admin_url('admin.php?page=' . JobsPage::SLUG)) . '">' . esc_html__('Show the runs', 'agent-brain') . '</a>'
			);
			// phpcs:enable
		} elseif ($inUse === null) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::callout() escapes what it emits.
			echo Ui::callout('info', __('Old memories are not summarised.', 'agent-brain'), esc_html__('Nothing is lost: they roll into a daily digest word for word. Set up one of the ways below to summarise them.', 'agent-brain'));
		}

		$tile = static function (string $title, string $text, string $tag, string $tone, bool $current): string {
			return '<li class="ab-ui-tile"' . ($current ? ' aria-current="true"' : '') . '><strong>' . esc_html($title) . '</strong>'
				. ($tag !== '' ? Ui::tag($tag, $tone) : '') . '<small>' . esc_html($text) . '</small></li>';
		};
		$inUseTag = __('In use', 'agent-brain');

		$tiles  = '<ul class="ab-ui-tiles">';
		$tiles .= $tile(
			__('Your site\'s AI provider', 'agent-brain'),
			__('Uses the WordPress AI client with your own key; nothing to set up here. Note that WordPress core stores that key unencrypted.', 'agent-brain'),
			$inUse === 'wp_ai_client' ? $inUseTag : ($state['wp_ai'] ? __('Available', 'agent-brain') : __('Not available', 'agent-brain')),
			$inUse === 'wp_ai_client' ? ($state['failing'] ? 'bad' : 'ok') : '',
			$inUse === 'wp_ai_client'
		);
		$tiles .= $tile(
			__('An OpenAI-compatible endpoint', 'agent-brain'),
			__('OpenAI, Cloudflare Workers AI, OpenRouter or a server on your own network. Your key is stored encrypted. When set up, it is used first.', 'agent-brain'),
			$inUse === 'openai_compatible' ? $inUseTag : (Endpoint::isSet() ? __('Set up', 'agent-brain') : __('Not set up', 'agent-brain')),
			$inUse === 'openai_compatible' ? ($state['failing'] ? 'bad' : 'ok') : '',
			$inUse === 'openai_compatible'
		);
		$tiles .= $tile(
			__('TomaTío Cloud', 'agent-brain'),
			__('Optional hosted service. Adds managed summarising, encrypted off-site backups and cloud-assisted jobs.', 'agent-brain'),
			$inUse === 'cloud' ? $inUseTag : ($connected ? __('Connected', 'agent-brain') : __('Not connected', 'agent-brain')),
			$inUse === 'cloud' ? ($state['failing'] ? 'bad' : 'ok') : '',
			$inUse === 'cloud'
		);
		$tiles .= $tile(
			__('Don\'t summarise', 'agent-brain'),
			__('With none of the above, memories are kept word for word and rolled into a daily digest that loses nothing.', 'agent-brain'),
			$inUse === null ? $inUseTag : '',
			$inUse === null ? 'ok' : '',
			$inUse === null
		);
		$tiles .= '</ul>';
		$tiles .= '<p class="description">' . esc_html__('If more than one is available, TomaTío uses your OpenAI-compatible endpoint, then TomaTío Cloud, then the site\'s AI provider.', 'agent-brain') . '</p>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::card() escapes what it emits.
		echo Ui::card(__('How old memories are summarised', 'agent-brain'), $tiles, '', 3);

		EndpointSettings::renderSection();

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__('TomaTío Cloud (optional)', 'agent-brain') . '</h3>';
		if ($connected) {
			$form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . Confirm::attr(Confirm::message(__('Disconnect from TomaTío Cloud: compression and backups stop until you reconnect. Nothing on this site is deleted', 'agent-brain'), __('Reconnect here at any time', 'agent-brain'))) . '>'
				. wp_nonce_field(self::ACTION_DISCONNECT, '_wpnonce', true, false)
				. '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_DISCONNECT) . '" />'
				. '<button type="submit" name="ab_cloud_disconnect_button" value="1" class="button">' . esc_html__('Disconnect', 'agent-brain') . '</button></form>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::setting() escapes what it emits.
			echo Ui::setting(__('Status', 'agent-brain'), '<b>' . esc_html__('Connected.', 'agent-brain') . '</b> ' . esc_html__('This site is connected to the cloud; summarising and backups can use it.', 'agent-brain'), $form);
		} else {
			$form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
				. wp_nonce_field(self::ACTION_CONNECT, '_wpnonce', true, false)
				. '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_CONNECT) . '" />'
				. '<input type="url" required="required" id="ab_cloud_endpoint" name="ab_cloud_endpoint" class="regular-text" placeholder="https://" value="' . esc_attr((string) get_option('ab_cloud_endpoint', '')) . '" /> '
				. '<button type="submit" name="ab_cloud_connect_button" value="1" class="button button-primary">' . esc_html__('Connect', 'agent-brain') . '</button></form>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::setting() escapes what it emits.
			echo Ui::setting(__('Cloud endpoint URL', 'agent-brain'), '<b>' . esc_html__('Not connected.', 'agent-brain') . '</b> ' . esc_html__('Nothing is sent anywhere.', 'agent-brain'), $form, 'ab_cloud_endpoint', '', true);
		}
		echo '</section>';

		echo '<details class="ab-ui-about"><summary>' . esc_html__('About summarising', 'agent-brain') . '</summary><div class="ab-ui-about-body">'
			. '<p>' . esc_html__('TomaTío is complete on its own: memory, recall, MCP, the review queue, artifacts, forget and export all work without any account or service. This tab is only about how old memories get summarised (compression). Pick whichever of these suits you; each one is optional.', 'agent-brain') . '</p>'
			. '<p>' . esc_html__('Summarising turns raw working memory into short episodes and pulls out facts for Review. Without it nothing is lost; memories are only longer.', 'agent-brain') . '</p>'
			. '<p>' . esc_html__('TomaTío Cloud is an optional hosted service that adds managed summarising, encrypted off-site backups and cloud-assisted jobs. The free tier needs no connection and loses nothing without it. Your memories are only sent to it for those features, and you can disconnect at any time.', 'agent-brain') . '</p>'
			. '</div></details>';
	}

	/**
	 * Result notice for the connect / disconnect redirects, in the shared
	 * notice markup (#81, #80).
	 */
	private static function renderResultNotice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only result flags set by our own redirects.
		if (isset($_GET['ab_cloud_connected'])) {
			$notice = ['success', __('Connected to TomaTío Cloud.', 'agent-brain')];
		} elseif (isset($_GET['ab_cloud_disconnected'])) {
			$notice = ['success', __('Disconnected from TomaTío Cloud. Nothing on this site was deleted.', 'agent-brain')];
		} elseif (isset($_GET['ab_cloud_error'])) {
			$code   = sanitize_key(wp_unslash((string) $_GET['ab_cloud_error']));
			$notice = ['error', $code === 'missing_endpoint'
				? __('Enter the cloud endpoint URL to connect.', 'agent-brain')
				: __('Could not connect to TomaTío Cloud. Check the URL and try again.', 'agent-brain')];
		} else {
			return;
		}
		// phpcs:enable

		echo Notices::html($notice[0], $notice[1]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notices::html() escapes.
	}

	public static function handleConnect(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage the cloud connection.', 'agent-brain'), 403);
		}

		check_admin_referer(self::ACTION_CONNECT);

		$rawEndpoint = $_POST['ab_cloud_endpoint'] ?? '';
		$endpoint    = is_string($rawEndpoint) ? esc_url_raw(wp_unslash($rawEndpoint)) : '';

		if ($endpoint === '') {
			self::redirectBack(['ab_cloud_error' => 'missing_endpoint']);

			return;
		}

		$result = Connector::connect($endpoint);

		if (is_wp_error($result)) {
			self::redirectBack(['ab_cloud_error' => $result->get_error_code()]);

			return;
		}

		update_option('ab_cloud_enabled', true);

		self::redirectBack(['ab_cloud_connected' => '1']);
	}

	public static function handleDisconnect(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage the cloud connection.', 'agent-brain'), 403);
		}

		check_admin_referer(self::ACTION_DISCONNECT);

		Connector::disconnect();

		self::redirectBack(['ab_cloud_disconnected' => '1']);
	}

	/**
	 * @param array<string, string> $args
	 */
	private static function redirectBack(array $args): void {
		wp_safe_redirect(SettingsHub::url(SettingsHub::TAB_CLOUD, $args));
		exit;
	}
}
