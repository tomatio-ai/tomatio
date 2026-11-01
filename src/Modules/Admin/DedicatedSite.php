<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Identity\Capabilities;

/**
 * "This site is dedicated to TomaTío": an opt-in focused wp-admin for
 * installs that exist only to be a brain (the recommended setup is its own
 * subdomain, not inside an existing website). When on, the website screens
 * (Posts, Media, Pages, Comments, Appearance) leave the menu, the dashboard
 * and login land on the TomaTío Overview, the toolbar drops its
 * content shortcuts, and comments are closed.
 *
 * Menus are hidden, not capabilities removed, and nothing is deleted:
 * turning it off restores the full admin. Plugins, Tools, Users and
 * Settings stay, so updates and site health keep working.
 *
 * Off by default. Settings > Site tab, or force it from a host's mu-plugin
 * or config with the `agent_brain/dedicated_site` filter.
 */
final class DedicatedSite {

	public const OPTION = 'agent_brain_dedicated_site';
	public const ACTION = 'agent_brain_save_dedicated_site';

	/** Website screens hidden from the menu, by menu slug. */
	public const HIDDEN_MENUS = [
		'index.php',
		'edit.php',
		'upload.php',
		'edit.php?post_type=page',
		'edit-comments.php',
		'themes.php',
	];

	/** Toolbar nodes that only make sense on a website. */
	public const HIDDEN_TOOLBAR_NODES = [ 'wp-logo', 'comments', 'new-content', 'customize' ];

	public static function register(): void {
		add_action('admin_menu', [self::class, 'hideWebsiteMenus'], 999);
		add_action('load-index.php', [self::class, 'redirectDashboard']);
		add_filter('login_redirect', [self::class, 'loginRedirect'], 10, 2);
		add_action('admin_bar_menu', [self::class, 'trimToolbar'], 999);
		add_filter('comments_open', [self::class, 'commentsOpen'], 20);
		add_filter('pings_open', [self::class, 'commentsOpen'], 20);
		add_action('admin_post_' . self::ACTION, [self::class, 'handleSave']);
	}

	public static function enabled(): bool {
		/**
		 * agent_brain/dedicated_site filter: ( bool $enabled ) => bool. Lets a
		 * host force the focused admin on (or off) regardless of the setting.
		 */
		return (bool) apply_filters('agent_brain/dedicated_site', (bool) get_option(self::OPTION, false));
	}

	/** True when the filter, not the saved option, decides. */
	public static function forced(): bool {
		return self::enabled() !== (bool) get_option(self::OPTION, false);
	}

	public static function hideWebsiteMenus(): void {
		if (! self::enabled() || ! function_exists('remove_menu_page')) {
			return;
		}

		foreach (self::HIDDEN_MENUS as $slug) {
			remove_menu_page($slug);
		}
	}

	public static function redirectDashboard(): void {
		if (! self::enabled() || ! self::currentUserCanSeeOverview()) {
			return;
		}

		wp_safe_redirect(self::overviewUrl());
		exit;
	}

	/**
	 * Default logins (no explicit redirect_to, or one to the dashboard) land
	 * on the Overview; any other requested destination is kept.
	 *
	 * @param string $redirect
	 * @param string $requested
	 */
	public static function loginRedirect($redirect, $requested): string {
		$redirect  = (string) $redirect;
		$requested = (string) $requested;

		if (! self::enabled() || ($requested !== '' && $requested !== admin_url())) {
			return $redirect;
		}

		return self::overviewUrl();
	}

	public static function trimToolbar(\WP_Admin_Bar $bar): void {
		if (! self::enabled()) {
			return;
		}

		foreach (self::HIDDEN_TOOLBAR_NODES as $node) {
			$bar->remove_node($node);
		}
	}

	/**
	 * @param bool $open
	 */
	public static function commentsOpen($open): bool {
		return self::enabled() ? false : (bool) $open;
	}

	public static function overviewUrl(): string {
		return admin_url('admin.php?page=' . Menu::PARENT);
	}

	/**
	 * Settings > Site tab body (SettingsHub already checked the capability).
	 */
	public static function renderBody(): void {
		$saved   = (bool) get_option(self::OPTION, false);
		$forced  = self::forced();
		$enabled = self::enabled();

		$on = $forced ? $enabled : $saved;

		$now = $on
			? esc_html__('On: the website screens are hidden, signing in opens the Overview and the front page is TomaTío\'s own.', 'agent-brain')
			: esc_html__('Off: this is also a normal WordPress website.', 'agent-brain');
		if ($forced) {
			$now .= ' <em>' . esc_html__('Your host sets this for the site, so it cannot be changed here.', 'agent-brain') . '</em>';
		}

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__('This site', 'agent-brain') . '</h3>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::ACTION);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::setting(
			__('This site is dedicated to TomaTío', 'agent-brain'),
			$now,
			Ui::toggle('ab_dedicated_site', 'ab_dedicated_site', $on, $forced),
			'ab_dedicated_site',
			'<p>' . esc_html__('Shows a designed home page (sign in and how to connect an AI, no brain content) instead of the theme\'s front page, hides the website screens (Posts, Media, Pages, Comments, Appearance), opens the TomaTío Overview instead of the dashboard, trims the toolbar and closes comments. Set a Site Icon (Settings, General) to use your own logo and favicons. Nothing is deleted, and turning it off restores the full admin. Recommended when TomaTío runs on its own subdomain.', 'agent-brain') . '</p>'
		);
		if (! $forced) {
			echo Ui::saveBar('ab_dedicated_site_submit');
		}
		// phpcs:enable

		echo '</form></section>';
	}

	public static function handleSave(): void {
		if (! current_user_can(SettingsHub::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage TomaTío settings.', 'agent-brain'), 403);
		}

		check_admin_referer(self::ACTION);

		update_option(self::OPTION, isset($_POST['ab_dedicated_site']) && $_POST['ab_dedicated_site'] === '1');

		wp_safe_redirect(SettingsHub::url(SettingsHub::TAB_SITE, ['ab_site_saved' => '1']));
		exit;
	}

	private static function currentUserCanSeeOverview(): bool {
		foreach (Menu::surfaceCapabilities() as $cap) {
			if (current_user_can($cap)) {
				return true;
			}
		}

		return current_user_can(Capabilities::CAP_MANAGE);
	}
}
