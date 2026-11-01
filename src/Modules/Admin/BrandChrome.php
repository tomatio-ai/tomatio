<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * #519, #520, #521 (epic #522): the brain's own name and mark wherever a
 * person meets it.
 *
 * - TomaTío admin screens: a slim brand bar (mark, name, the TomaTío
 *   lockup) above the page and its group tabs.
 * - Dedicated brains: the sign-in page shows the brain's mark linked home
 *   instead of the WordPress logo, and the sign-in page and wp-admin get the
 *   TomaTío favicons when the site has no Site Icon (with one, WordPress
 *   prints it), as does /favicon.ico (#590), which core would answer with
 *   the WordPress logo. Every public address that is not the front page, a
 *   real post or a plugin route gets a designed page: a branded "not
 *   found", and WordPress's leftover sample content and archives send
 *   people home.
 * - Any site (#590): the TomaTío admin screens get the favicons too; the
 *   rest of a website's admin, its sign-in page and /favicon.ico stay its
 *   own.
 *
 * The favicons print early (priority 1), so a host's own icon tags printed
 * later on the same hook win; `agent_brain/favicon_tags` (an HTML string,
 * '' for none) replaces them.
 *
 * The brand comes from HomePage::view() (site title, Site Icon or the
 * TomaTío mark, accent), so the brand filters apply here too.
 */
final class BrandChrome {

	/** WordPress's first-install sample content, by post type and slug. */
	public const SAMPLE_CONTENT = [
		'post' => ['hello-world'],
		'page' => ['sample-page', 'privacy-policy'],
	];

	public static function register(): void {
		add_action('all_admin_notices', [self::class, 'renderBar'], 0);
		add_action('admin_head', [self::class, 'printAdminFavicons'], 1);
		add_action('login_head', [self::class, 'printFavicons'], 1);
		add_action('do_favicon', [self::class, 'serveFavicon'], 6);
		add_action('login_enqueue_scripts', [self::class, 'loginStyle']);
		add_filter('login_headerurl', [self::class, 'loginHeaderUrl']);
		add_filter('login_headertext', [self::class, 'loginHeaderText']);
		add_action('template_redirect', [self::class, 'publicPages'], 3);
	}

	// Admin --------------------------------------------------------------

	/** The brand bar's HTML (escaped). */
	public static function barHtml(): string {
		$view = HomePage::view();

		return '<div class="ab-brandbar" style="--ab-brand:' . esc_attr($view['accent']) . '">'
			. '<img src="' . esc_url($view['logo'], ['https', 'http', 'data']) . '" alt="" width="28" height="28" />'
			. '<span><strong>' . esc_html($view['name']) . '</strong>'
			. ($view['tagline'] !== '' ? '<span class="ab-brandbar-by">' . esc_html($view['tagline']) . '</span>' : '')
			. '</span></div>';
	}

	public static function renderBar(): void {
		if (! Menu::isAgentBrainScreen()) {
			return;
		}

		// Connect an AI carries the same lockup in its own header.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		if (isset($_GET['page']) && sanitize_key(wp_unslash((string) $_GET['page'])) === ConnectPage::SLUG) {
			return;
		}

		echo self::barHtml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- barHtml() escapes.
	}

	/**
	 * The brain's favicons (its monogram) on a dedicated brain's sign-in
	 * page when the site has no Site Icon; a Site Icon is printed by
	 * WordPress itself.
	 */
	public static function printFavicons(): void {
		if (! DedicatedSite::enabled() || has_site_icon()) {
			return;
		}

		echo self::faviconTags(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- faviconTags() escapes.
	}

	/**
	 * The brain's favicons in wp-admin when the site has no Site Icon: on
	 * every screen of a dedicated brain, on the TomaTío screens of a website.
	 */
	public static function printAdminFavicons(): void {
		if (! DedicatedSite::enabled() && ! Menu::isAgentBrainScreen()) {
			return;
		}
		if (has_site_icon()) {
			return;
		}

		echo self::faviconTags(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- faviconTags() escapes.
	}

	/**
	 * The brain's favicon set when it has no Site Icon: its monogram as SVG
	 * and, with GD, as a 32 px PNG and the 180 px apple-touch-icon on an
	 * opaque square (iOS ignores SVG and transparency) (BrandIcon). Filtered
	 * by `agent_brain/favicon_tags`.
	 */
	public static function faviconTags(): string {
		return (string) apply_filters('agent_brain/favicon_tags', BrandIcon::faviconTags());
	}

	/** URL of one of the shipped brand files (assets/brand/). */
	public static function markUrl(string $file): string {
		return (string) plugins_url('assets/brand/' . $file, \AGENT_BRAIN_PLUGIN_FILE);
	}

	/**
	 * What /favicon.ico answers with on a dedicated brain without a Site
	 * Icon: its 32 px monogram (with GD), else the bundled 32 px mark; null
	 * otherwise (WordPress as usual: a redirect to the Site Icon, or to the
	 * WordPress logo on a website).
	 */
	public static function faviconBytes(): ?string {
		if (! DedicatedSite::enabled() || has_site_icon()) {
			return null;
		}

		$png = BrandIcon::png(32);
		if ($png !== null) {
			return $png;
		}

		$file = \AGENT_BRAIN_PLUGIN_DIR . '/assets/brand/tomatio-32.png';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a bundled plugin file, read-only.
		$bytes = is_readable($file) ? file_get_contents($file) : false;

		return is_string($bytes) ? $bytes : null;
	}

	/**
	 * /favicon.ico (do_favicon, after the preview host's handler and before
	 * core's redirect at priority 10).
	 */
	public static function serveFavicon(): void {
		$bytes = self::faviconBytes();
		if ($bytes === null) {
			return;
		}

		status_header(200);
		header('Content-Type: image/png');
		header('Cache-Control: public, max-age=86400');
		header('X-Content-Type-Options: nosniff');
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image bytes.
		echo $bytes;
		exit;
	}

	/** The brain's mark in place of the WordPress logo on the sign-in page. */
	public static function loginStyle(): void {
		if (! DedicatedSite::enabled()) {
			return;
		}

		$view = HomePage::view();
		$logo = esc_url_raw($view['logo'], ['https', 'http', 'data']);

		wp_register_style('agent-brain-login', false, [], null);
		wp_enqueue_style('agent-brain-login');
		wp_add_inline_style(
			'agent-brain-login',
			'#login h1 a{background-image:url("' . str_replace(['"', '\\'], ['%22', '%5C'], $logo) . '");background-size:contain;'
			. 'background-position:center;width:84px;height:84px;border-radius:18px;margin-bottom:12px}'
		);
	}

	/**
	 * @param string $url
	 */
	public static function loginHeaderUrl($url): string {
		return DedicatedSite::enabled() ? home_url('/') : (string) $url;
	}

	/**
	 * @param string $text
	 */
	public static function loginHeaderText($text): string {
		return DedicatedSite::enabled() ? HomePage::view()['name'] : (string) $text;
	}

	// Public pages on a dedicated brain ------------------------------------

	/**
	 * What a public request on a dedicated brain gets: 'not-found' (the
	 * branded page, status 404), 'home' (a redirect to the front page), or
	 * '' (WordPress as usual: the front page, which HomePage serves, a real
	 * post, feeds and everything that is not a theme page).
	 */
	public static function decide(): string {
		if (is_admin() || ! DedicatedSite::enabled() || is_front_page() || is_feed() || is_robots() || is_favicon()) {
			return '';
		}
		if (is_404()) {
			return 'not-found';
		}
		if (is_search() || is_archive() || is_home() || is_attachment()) {
			return 'home';
		}
		if (is_singular()) {
			$post = get_queried_object();
			if ($post instanceof \WP_Post && in_array($post->post_name, self::SAMPLE_CONTENT[$post->post_type] ?? [], true)) {
				return 'home';
			}
		}

		return '';
	}

	public static function publicPages(): void {
		$decision = self::decide();
		if ($decision === 'home') {
			wp_safe_redirect(home_url('/'), 302);
			exit;
		}
		if ($decision === 'not-found') {
			nocache_headers();
			status_header(404);
			header('Content-Type: text/html; charset=utf-8');
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- notFound() escapes every dynamic value.
			echo HomePage::notFound(HomePage::view());
			exit;
		}
	}
}
