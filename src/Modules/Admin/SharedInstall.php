<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * #357: a TomaTío brain is meant to run on its own, NEW WordPress install
 * (e.g. brain.yourcompany.com), never as one more plugin on an existing
 * website. This module detects "shared site" signs and warns — it never
 * blocks anything:
 *
 * · an admin notice, shown to administrators while the verdict is "shared"
 *   (which covers the activation flow: the first admin page after activating
 *   the plugin renders it). The notice is dismissible per site: dismissing
 *   stores a fingerprint of the signs that were present, so it stays away
 *   until the facts change (a new sign appears) — that is the re-check.
 * · a "dedicated install" line in the Get started checklist (OverviewPage),
 * · a WordPress Site Health test (Tools > Site Health) with the same checks.
 *
 * Sign weighting: other active plugins, published content beyond the fresh-
 * install samples, and administrators beyond the installing user are each
 * strong enough alone to declare the site "shared" — each means third parties
 * (code or people) already reach the PHP process and database that hold the
 * brain's private context, agent tokens and audit log. A non-default theme is
 * deliberately weaker: switching the theme is also step one of repurposing a
 * fresh install, and a theme alone brings no third-party code into wp-admin,
 * so it only ever contributes to the explanation, never triggers the verdict
 * on its own. On a site dedicated to TomaTío (DedicatedSite) extra
 * administrators are the team, not a sign (#467).
 */
final class SharedInstall {

	public const OPTION_DISMISSED = 'agent_brain_shared_install_dismissed';
	public const ACTION_DISMISS   = 'agent_brain_dismiss_shared_install';
	public const SITE_HEALTH_TEST = 'agent_brain_dedicated_install';

	/** Plugins that never count as "another plugin on this site". */
	public const PLUGIN_ALLOWLIST = [
		'agent-brain/agent-brain.php',
	];

	/** Fresh installs ship exactly one sample post and one sample page. */
	public const DEFAULT_SAMPLE_POSTS = 1;
	public const DEFAULT_SAMPLE_PAGES = 1;

	/**
	 * Request-scoped memo for detect() (admin_notices + Site Health share it).
	 *
	 * @var array{shared: bool, signs: array<string, bool>, other_plugins: array<int, string>}|null
	 */
	private static ?array $detected = null;

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_notices', [self::class, 'renderNotice']);
		add_action('admin_post_' . self::ACTION_DISMISS, [self::class, 'handleDismiss']);
		add_filter('site_status_tests', [self::class, 'registerSiteHealthTest']);
	}

	/**
	 * Pure verdict over gathered facts (unit-tested without WordPress).
	 *
	 * @param array{other_plugins: array<int, string>, published_posts: int, published_pages: int, extra_admins: int, non_default_theme: bool} $facts extra_admins = administrators other than the installing/current user.
	 * @return array{shared: bool, signs: array{plugins: bool, content: bool, admins: bool, theme: bool}}
	 */
	public static function assess(array $facts): array {
		$signs = [
			'plugins' => $facts['other_plugins'] !== [],
			'content' => (int) $facts['published_posts'] > self::DEFAULT_SAMPLE_POSTS
				|| (int) $facts['published_pages'] > self::DEFAULT_SAMPLE_PAGES,
			'admins'  => (int) $facts['extra_admins'] > 0,
			'theme'   => (bool) $facts['non_default_theme'],
		];

		// Theme is a contributing signal only: never sufficient alone.
		return [
			'shared' => $signs['plugins'] || $signs['content'] || $signs['admins'],
			'signs'  => $signs,
		];
	}

	/**
	 * Gather the facts from WordPress and assess them. Cached per request.
	 *
	 * @return array{shared: bool, signs: array{plugins: bool, content: bool, admins: bool, theme: bool}, other_plugins: array<int, string>}
	 */
	public static function detect(): array {
		if (self::$detected !== null) {
			return self::$detected;
		}

		$otherPlugins = array_values(array_diff(self::activePlugins(), self::pluginAllowlist()));

		$posts = function_exists('wp_count_posts') ? wp_count_posts('post') : null;
		$pages = function_exists('wp_count_posts') ? wp_count_posts('page') : null;

		$adminIds = function_exists('get_users')
			? get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 200])
			: [];
		$currentId = function_exists('get_current_user_id') ? get_current_user_id() : 0;

		// "Beyond the installing user": at activation that is the current
		// user, so count administrators other than them. On a site dedicated
		// to TomaTío more admins are just the team (#467), so they do not count.
		$extraAdmins = 0;
		if (! DedicatedSite::enabled()) {
			foreach ($adminIds as $adminId) {
				if ((int) $adminId !== $currentId) {
					$extraAdmins++;
				}
			}
		}

		$stylesheet = function_exists('get_option') ? (string) get_option('stylesheet') : '';
		$default    = defined('WP_DEFAULT_THEME') ? WP_DEFAULT_THEME : '';

		$assessment = self::assess([
			'other_plugins'     => $otherPlugins,
			'published_posts'   => isset($posts->publish) ? (int) $posts->publish : 0,
			'published_pages'   => isset($pages->publish) ? (int) $pages->publish : 0,
			'extra_admins'      => $extraAdmins,
			'non_default_theme' => $default !== '' && $stylesheet !== '' && $stylesheet !== $default,
		]);

		return self::$detected = $assessment + ['other_plugins' => $otherPlugins];
	}

	/**
	 * Test-only reset of the request-scoped memo.
	 */
	public static function reset(): void {
		self::$detected = null;
	}

	/**
	 * True when the site shows shared-site signs that the operator has not
	 * dismissed for the current combination of signs.
	 */
	public static function warningActive(): bool {
		$result = self::detect();

		if (! $result['shared']) {
			return false;
		}

		$dismissed = function_exists('get_option')
			? (string) get_option(self::OPTION_DISMISSED, '')
			: '';

		return $dismissed === '' || $dismissed !== self::fingerprint($result['signs']);
	}

	/**
	 * Stable per-sign fingerprint: dismissing hides the notice only while the
	 * sign combination stays the same, so a newly appeared sign re-warns.
	 *
	 * @param array<string, bool> $signs
	 */
	public static function fingerprint(array $signs): string {
		ksort($signs);

		return md5(json_encode($signs) ?: '');
	}

	public static function renderNotice(): void {
		if (! function_exists('current_user_can') || ! current_user_can('manage_options')) {
			return;
		}

		if (! self::warningActive()) {
			return;
		}

		$dismissUrl = wp_nonce_url(
			admin_url('admin-post.php?action=' . self::ACTION_DISMISS),
			self::ACTION_DISMISS
		);

		printf(
			'<div class="notice notice-warning ab-notice" role="status"><p><strong>%s</strong> %s</p><p>%s</p><p><a href="%s">%s</a></p></div>',
			esc_html__('TomaTío: this looks like an existing website, not a dedicated install.', 'agent-brain'),
			esc_html__('The brain’s private context, agent tokens and the audit log share this WordPress install — its PHP process and database — with everything else on the site, and the site’s existing admins get capabilities that reach the brain.', 'agent-brain'),
			esc_html__('Recommended: install TomaTío on its own, new WordPress site (for example brain.yourcompany.com) and move there. This warning never blocks anything; dismissing it remembers your choice for this site.', 'agent-brain'),
			esc_url($dismissUrl),
			esc_html__('I understand, dismiss this warning', 'agent-brain')
		);
	}

	public static function handleDismiss(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to manage TomaTío settings.', 'agent-brain'), 403);
		}

		check_admin_referer(self::ACTION_DISMISS);

		$result = self::detect();
		if ($result['shared']) {
			update_option(self::OPTION_DISMISSED, self::fingerprint($result['signs']));
		}

		wp_safe_redirect(function_exists('wp_get_referer') && wp_get_referer()
			? (string) wp_get_referer()
			: admin_url());
		exit;
	}

	/**
	 * WordPress Site Health (Tools > Site Health) test with the same checks.
	 *
	 * @param array<string, mixed> $tests
	 * @return array<string, mixed>
	 */
	public static function registerSiteHealthTest(array $tests): array {
		$tests[self::SITE_HEALTH_TEST] = [
			'label' => __('TomaTío: dedicated install', 'agent-brain'),
			'test'  => [self::class, 'siteHealthResult'],
		];

		return $tests;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function siteHealthResult(): array {
		$result = self::detect();
		$shared = $result['shared'];

		return [
			'label'       => $shared
				? __('This site appears to be an existing website, not a dedicated TomaTío install', 'agent-brain')
				: __('TomaTío runs on its own dedicated WordPress install', 'agent-brain'),
			'status'      => $shared ? 'critical' : 'good',
			'badge'       => [
				'label' => __('TomaTío', 'agent-brain'),
				'color' => $shared ? 'red' : 'green',
			],
			'description' => '<p>' . esc_html(self::whyText()) . '</p>'
				. ( $shared ? '<p>' . esc_html(self::signsText($result)) . '</p>' : '' ),
			'actions'     => $shared
				? '<p>' . esc_html__('Recommended: install TomaTío on its own, new WordPress site (for example brain.yourcompany.com) and connect your agents there instead.', 'agent-brain') . '</p>'
				: '',
			'test'        => self::SITE_HEALTH_TEST,
		];
	}

	public static function whyText(): string {
		return __('A TomaTío brain holds a team’s private context, agents’ OAuth tokens and application passwords, and the audit log. On a site that already runs a website, every other plugin executes in the same PHP process with full database access, and the site’s existing administrators, editors and authors get capabilities that reach the brain.', 'agent-brain');
	}

	/**
	 * @param array{signs: array<string, bool>} $result
	 */
	public static function signsText(array $result): string {
		$labels = [
			'plugins' => __('other active plugins', 'agent-brain'),
			'content' => __('published content beyond the fresh-install samples', 'agent-brain'),
			'admins'  => __('administrators beyond the installing user', 'agent-brain'),
			'theme'   => __('a non-default theme', 'agent-brain'),
		];

		$present = [];
		foreach ($result['signs'] as $sign => $on) {
			if ($on && isset($labels[$sign])) {
				$present[] = $labels[$sign];
			}
		}

		return $present === []
			? ''
			: sprintf(
				/* translators: %s: list of detected signs, e.g. "other active plugins, administrators beyond the installing user". */
				__('Signs detected on this site: %s.', 'agent-brain'),
				implode(', ', $present)
			);
	}

	/**
	 * @return array<int, string>
	 */
	private static function activePlugins(): array {
		if (! function_exists('get_option')) {
			return [];
		}

		$active = (array) get_option('active_plugins', []);

		if (function_exists('is_multisite') && is_multisite()) {
			$sitewide = function_exists('get_site_option')
				? (array) get_site_option('active_sitewide_plugins', [])
				: [];
			$active   = array_merge($active, array_keys($sitewide));
		}

		// mu-plugins are deliberately not read here: they are part of the
		// host's stack, not "another plugin on this site".
		return array_map('strval', $active);
	}

	/**
	 * @return array<int, string>
	 */
	private static function pluginAllowlist(): array {
		$allowlist = self::PLUGIN_ALLOWLIST;

		/**
		 * agent_brain/shared_install_plugin_allowlist filter:
		 * ( array<int, string> $allowlist ) => array<int, string>. Plugin
		 * basenames that never count as a shared-site sign (e.g. a host's
		 * own companion plugin).
		 */
		return function_exists('apply_filters')
			? array_map('strval', (array) apply_filters('agent_brain/shared_install_plugin_allowlist', $allowlist))
			: $allowlist;
	}
}
