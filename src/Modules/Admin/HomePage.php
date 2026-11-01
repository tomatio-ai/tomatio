<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * Public home page for a site dedicated to TomaTío (DedicatedSite on).
 * Instead of an empty theme front page, visitors see who the brain is for,
 * a Sign in button, and how to connect an AI client. It shows no brain
 * content: memory, knowledge and documents all still need a login.
 *
 * Branding, all optional:
 *  - Name: the site title (`agent_brain/brand_name` filter).
 *  - Logo: the site's Site Icon, else TomaTío's tomatillo mark
 *    (`agent_brain/brand_logo_url` filter).
 *  - Accent: `agent_brain/brand_accent` filter (six-digit hex).
 *  - Favicon links: the Site Icon set, else the default mark
 *    (`agent_brain/home_icons` filter, an HTML string).
 *  - `agent_brain/home_head` action for extra <style>/<link> tags.
 */
final class HomePage {

	public const DEFAULT_ACCENT = BrandIcon::DEFAULT_ACCENT;

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('template_redirect', [self::class, 'maybeRender'], 2);
	}

	public static function shouldRender(): bool {
		return ! is_admin() && DedicatedSite::enabled() && is_front_page();
	}

	public static function maybeRender(): void {
		if (! self::shouldRender()) {
			return;
		}

		nocache_headers();
		status_header(200);
		header('Content-Type: text/html; charset=utf-8');
		// Signed in: the team's space (HomeWorkspace); otherwise the public welcome.
		$userId = get_current_user_id();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both renderers escape every dynamic value.
		echo $userId > 0 ? HomeWorkspace::render(HomeWorkspace::view($userId), self::view()) : self::render(self::view());
		exit;
	}

	/**
	 * @return array{name:string, tagline:string, accent:string, logo:string, icons:string, signed_in:bool, cta_url:string, mcp_url:string, roles_url?:string, app_url?:string, app_label?:string}
	 */
	public static function view(): array {
		$name   = BrandIcon::name();
		$accent = BrandIcon::accent();
		$logo   = self::logo($accent);

		$signedIn = is_user_logged_in();
		$overview = admin_url('admin.php?page=' . Menu::PARENT);
		$appLink  = AppLink::get();

		return [
			'name'      => $name,
			'tagline'   => (string) apply_filters('agent_brain/brand_tagline', $name === 'TomaTío' ? '' : 'TomaTío'),
			'accent'    => strtolower($accent),
			'logo'      => $logo,
			'icons'     => (string) apply_filters('agent_brain/home_icons', self::iconTags($logo)),
			'signed_in' => $signedIn,
			'cta_url'   => $signedIn ? $overview : wp_login_url($overview),
			'mcp_url'   => ConnectPage::mcpUrl(),
			'roles_url' => \AgentBrain\Modules\OAuth\RoleSwitching::enabled() ? \AgentBrain\Modules\OAuth\RoleSwitching::rolesUrl() : '',
			'app_url'   => $appLink['url'] ?? '',
			'app_label' => $appLink['label'] ?? '',
		];
	}

	/**
	 * #590: the brain's logo wherever it shows (home page, sign-in page,
	 * OAuth consent page): the Site Icon when set, else the brain's own
	 * monogram (BrandIcon), through the `agent_brain/brand_logo_url` filter.
	 * The brain is its team's, so TomaTío's mark is not the default here.
	 */
	public static function logo(string $accent = self::DEFAULT_ACCENT): string {
		unset($accent); // BrandIcon reads the brand accent itself.
		$logo = has_site_icon() ? (string) get_site_icon_url(512) : BrandIcon::uri();

		return (string) apply_filters('agent_brain/brand_logo_url', $logo);
	}

	/**
	 * TomaTío's tomatillo mark (the admin menu icon), from the shipped brand
	 * assets. Returns a URL (not a data URI) because it also feeds the MCP
	 * serverInfo icons, which external clients must be able to fetch.
	 */
	public static function defaultMark(string $accent): string {
		unset($accent); // The mark is a fixed brand asset; the accent no longer tints it.

		return (string) plugins_url('assets/brand/tomatio-192.png', \AGENT_BRAIN_PLUGIN_FILE);
	}

	/**
	 * The Site Icon's full favicon set when the site has one; the
	 * monogram's set for the default logo; otherwise the brand's logo as
	 * the favicon.
	 */
	private static function iconTags(string $logo): string {
		if (has_site_icon()) {
			ob_start();
			wp_site_icon();

			return (string) ob_get_clean();
		}
		if ($logo === BrandIcon::uri()) {
			return BrandChrome::faviconTags();
		}

		return '<link rel="icon" type="image/png" href="' . esc_url($logo, ['https', 'http', 'data']) . '">';
	}

	/**
	 * @param array{name:string, tagline:string, accent:string, logo:string, icons:string, signed_in:bool, cta_url:string, mcp_url:string, roles_url?:string, app_url?:string, app_label?:string} $view
	 */
	public static function render(array $view): string {
		$name   = esc_html($view['name']);
		$logo   = esc_url($view['logo'], ['https', 'http', 'data']);
		$mcp    = esc_html($view['mcp_url']);
		$cta    = '<a class="btn primary" href="' . esc_url($view['cta_url']) . '">' . esc_html($view['signed_in'] ? __('Open the brain', 'agent-brain') : __('Sign in', 'agent-brain')) . '</a>';
		$part   = $view['tagline'] !== '' ? '<span class="part">' . esc_html($view['tagline']) . '</span>' : '';
		// #449: the link back to the team's own app or site, when set.
		$app    = ($view['app_url'] ?? '') !== '' && ($view['app_label'] ?? '') !== ''
			? '<a class="applink" href="' . esc_url((string) $view['app_url'], ['http', 'https']) . '" rel="noopener">' . esc_html((string) $view['app_label']) . '</a>'
			: '';
		$head   = '';
		if (has_action('agent_brain/home_head')) {
			ob_start();
			do_action('agent_brain/home_head');
			$head = (string) ob_get_clean();
		}

		$html  = '<!DOCTYPE html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
			/* translators: %s: site or brand name. */
			. '<title>' . esc_html(sprintf(__('%s brain', 'agent-brain'), $view['name'])) . '</title>'
			. $view['icons'] . '<style>' . self::css($view['accent']) . '</style>' . $head . '</head><body>';

		$html .= '<div class="wrap"><header><a class="lockup" href="' . esc_url(home_url('/')) . '"><img src="' . $logo . '" alt=""><span class="wordmark">' . $name . '</span>' . $part . '</a>'
			. '<span class="end">' . $app . '<span class="lock">' . esc_html__('Private · sign-in required', 'agent-brain') . '</span></span></header>';

		$html .= '<div class="hero"><img class="mark" src="' . $logo . '" alt="' . $name . '"><div>'
			. '<p class="kicker">' . esc_html__('Private team brain', 'agent-brain') . '</p>'
			/* translators: %s: site or brand name, wrapped in emphasis. */
			. '<h1>' . sprintf(esc_html__('The brain behind %s.', 'agent-brain'), '<em>' . $name . '</em>') . '</h1>'
			. '<p class="lede">' . esc_html__('Where the team keeps what it knows: ideas, rules, decisions and plans. Its AI agents remember here, and the people on the team decide what becomes official.', 'agent-brain') . '</p>'
			. $cta . '</div></div></div>';

		$html .= '<section><div class="wrap connect"><div>'
			. '<p class="label">' . esc_html__('Connect your AI', 'agent-brain') . '</p>'
			. '<h2>' . esc_html__('Bring claude.ai, Claude Code or any MCP client.', 'agent-brain') . '</h2>'
			. '<p>' . esc_html__('Your AI signs in as one of the team\'s agents, so it only sees and writes what that agent may. Ask the brain\'s admin which agent to use.', 'agent-brain') . '</p>'
			. '<div class="copy"><code>' . $mcp . '</code><button type="button" data-copy="' . esc_attr($view['mcp_url']) . '">' . esc_html__('Copy', 'agent-brain') . '</button></div>'
			. (($view['roles_url'] ?? '') !== ''
				? '<p class="roles-label">' . esc_html__('Or let your AI switch between agents per task (connects as you):', 'agent-brain') . '</p>'
					. '<div class="copy"><code>' . esc_html((string) $view['roles_url']) . '</code><button type="button" data-copy="' . esc_attr((string) $view['roles_url']) . '">' . esc_html__('Copy', 'agent-brain') . '</button></div>'
				: '')
			. '</div>'
			. '<ol class="steps">'
			/* translators: %s: site or brand name, used to name the connector. */
			. '<li><strong>' . esc_html__('Add a custom connector', 'agent-brain') . '</strong> ' . esc_html(sprintf(__('with the address on the left: in claude.ai under Customize, Connectors, Add, Custom connector; in Claude Desktop under Settings, Connectors. Name it "%s brain".', 'agent-brain'), $view['name'])) . '</li>'
			. '<li><strong>' . esc_html__('Choose who it acts as.', 'agent-brain') . '</strong> ' . esc_html__('On the consent screen, pick the agent this AI should be (or use "Not you?" to sign in as that agent), then click Allow access. On the switching address, just allow it: your AI picks the agent per task.', 'agent-brain') . '</li>'
			. '<li><strong>' . esc_html__('Ask it something,', 'agent-brain') . '</strong> ' . esc_html__('like "What have we decided so far?". For Claude Code and other clients, sign in and open TomaTío, Connect an AI.', 'agent-brain') . '</li>'
			. '</ol></div></section>';

		$html .= '<div class="wrap"><footer><span>'
			/* translators: %s: site or brand name. */
			. esc_html(sprintf(__('%s · a private TomaTío. TomaTío is an open-source project by Roger Gentry and Dennis Kittrell.', 'agent-brain'), $view['name']))
			. '</span></footer></div>';

		$html .= '<script>document.querySelectorAll("[data-copy]").forEach(function(b){b.addEventListener("click",function(){navigator.clipboard.writeText(b.dataset.copy).then(function(){var t=b.textContent;b.textContent='
			. wp_json_encode(__('Copied', 'agent-brain'))
			. ';setTimeout(function(){b.textContent=t;},1500);});});});</script></body></html>';

		return $html;
	}

	/**
	 * #521: the not-found page of a dedicated brain, in the home page's look
	 * (any public address that is not the front page, a real post or a route
	 * of the plugin).
	 *
	 * @param array{name:string, tagline:string, accent:string, logo:string, icons:string, signed_in:bool, cta_url:string, mcp_url:string, roles_url?:string, app_url?:string, app_label?:string} $view
	 */
	public static function notFound(array $view): string {
		$name = esc_html($view['name']);
		$logo = esc_url($view['logo'], ['https', 'http', 'data']);
		$part = $view['tagline'] !== '' ? '<span class="part">' . esc_html($view['tagline']) . '</span>' : '';

		return '<!DOCTYPE html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
			/* translators: %s: site or brand name. */
			. '<title>' . esc_html(sprintf(__('Not found · %s brain', 'agent-brain'), $view['name'])) . '</title>'
			. $view['icons'] . '<style>' . self::css($view['accent']) . '</style></head><body>'
			. '<div class="wrap"><header><a class="lockup" href="' . esc_url(home_url('/')) . '"><img src="' . $logo . '" alt=""><span class="wordmark">' . $name . '</span>' . $part . '</a>'
			. '<span class="end"><span class="lock">' . esc_html__('Private · sign-in required', 'agent-brain') . '</span></span></header>'
			. '<div class="hero"><img class="mark" src="' . $logo . '" alt=""><div>'
			. '<p class="kicker">' . esc_html__('Not found', 'agent-brain') . '</p>'
			. '<h1>' . esc_html__('Nothing lives at this address.', 'agent-brain') . '</h1>'
			/* translators: %s: site or brand name, wrapped in emphasis. */
			. '<p class="lede">' . sprintf(esc_html__('This is the private brain of %s. The page you asked for does not exist here, or it moved.', 'agent-brain'), '<em>' . $name . '</em>') . '</p>'
			. '<a class="btn primary" href="' . esc_url(home_url('/')) . '">' . esc_html__('Go to the home page', 'agent-brain') . '</a>'
			. '</div></div></div>'
			. '<div class="wrap"><footer><span>'
			/* translators: %s: site or brand name. */
			. esc_html(sprintf(__('%s · a private TomaTío. TomaTío is an open-source project by Roger Gentry and Dennis Kittrell.', 'agent-brain'), $view['name']))
			. '</span></footer></div></body></html>';
	}

	private static function css(string $accent): string {
		return ':root{--bg:#0b0d12;--card:#12151c;--ink:#eef1f6;--soft:#9aa3b2;--mute:#8b95a5;--rule:#1b2029;--rule-hi:#283040;--accent:' . $accent . '}'
			. '*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--ink)}'
			. 'body{font:17px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif;-webkit-font-smoothing:antialiased;min-height:100vh;display:flex;flex-direction:column}'
			. 'a{color:inherit}.wrap{max-width:960px;margin:0 auto;padding:0 24px;width:100%}'
			. 'header{display:flex;align-items:center;justify-content:space-between;padding:22px 0;border-bottom:1px solid var(--rule)}'
			. '.lockup{display:flex;align-items:center;gap:12px;text-decoration:none}.lockup img{width:32px;height:32px;border-radius:7px}'
			. '.wordmark{font-weight:800;font-size:19px;letter-spacing:-.01em}.part{font-size:14px;color:var(--soft)}'
			. '.end{display:flex;align-items:center;gap:20px}.applink{font-size:14px;font-weight:500;color:var(--accent);text-decoration:none}.applink:hover{text-decoration:underline}'
			. '.lock{font:500 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.12em;text-transform:uppercase;color:var(--soft)}'
			. '.hero{padding:88px 0 56px;display:grid;grid-template-columns:auto 1fr;gap:40px;align-items:center}'
			. '.mark{width:132px;height:132px;border-radius:28px;box-shadow:0 40px 90px -30px color-mix(in srgb,var(--accent) 60%,transparent)}'
			. '.kicker{font-weight:600;font-size:13px;line-height:1;letter-spacing:.08em;text-transform:uppercase;color:var(--accent);margin:0 0 16px}'
			. 'h1{font-weight:800;font-size:clamp(36px,5.5vw,58px);line-height:1.04;letter-spacing:-.03em;margin:0 0 18px}h1 em{font-style:normal;color:var(--accent)}'
			. '.lede{font-size:18px;color:var(--soft);max-width:560px;margin:0 0 28px}'
			. '.btn{display:inline-flex;align-items:center;padding:13px 22px;border-radius:10px;font-weight:600;text-decoration:none;border:1px solid var(--rule-hi)}'
			. '.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.btn.primary:hover{filter:brightness(.92)}'
			. 'section{padding:56px 0;border-top:1px solid var(--rule)}'
			. '.label{font-weight:600;font-size:13px;line-height:1;letter-spacing:.08em;text-transform:uppercase;color:var(--soft);margin:0 0 22px}'
			. '.connect{display:grid;grid-template-columns:1fr 1.2fr;gap:40px;align-items:start}'
			. '.connect h2{font-weight:800;font-size:28px;line-height:1.15;letter-spacing:-.02em;margin:0 0 12px}.connect p{color:var(--soft);margin:0 0 12px}'
			. '.copy{display:flex;gap:8px;align-items:stretch;margin:10px 0 0}'
			. 'code{font:500 13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;background:var(--card);border:1px solid var(--rule-hi);border-radius:8px;padding:11px 13px;display:block;color:var(--ink);word-break:break-all;flex:1}'
			. '.copy button{font-weight:600;font-size:14px;font-family:inherit;background:var(--card);color:var(--ink);border:1px solid var(--rule-hi);border-radius:8px;padding:0 16px;cursor:pointer}'
			. '.copy button:hover{border-color:var(--accent);color:var(--accent)}'
			. '.roles-label{font-size:14px;margin:18px 0 0}'
			. 'ol.steps{margin:0;padding:0;list-style:none;counter-reset:s}'
			. 'ol.steps li{counter-increment:s;padding:14px 0 14px 44px;border-top:1px solid var(--rule);position:relative;color:var(--soft)}'
			. 'ol.steps li:first-child{border-top:0;padding-top:0}ol.steps li:first-child::before{top:0}'
			. 'ol.steps li::before{content:"0" counter(s);position:absolute;left:0;top:14px;font:500 12px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--accent)}'
			. 'ol.steps strong{color:var(--ink)}'
			. 'footer{margin-top:auto;border-top:1px solid var(--rule);padding:28px 0 40px;color:var(--mute);font-size:14px}'
			. '@media (max-width:760px){.hero{grid-template-columns:1fr;padding:56px 0 40px;gap:24px}.mark{width:88px;height:88px;border-radius:20px}.connect{grid-template-columns:1fr}.lock{display:none}}';
	}
}
