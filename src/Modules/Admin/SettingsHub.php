<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Backup\MarkdownStage;
use AgentBrain\Modules\Backup\SettingsPage as BackupSettingsPage;
use AgentBrain\Modules\Cloud\Settings as CloudSettings;
use AgentBrain\Modules\JobsAdmin\Page as JobsPage;
use AgentBrain\Modules\Ops\SelfCheck;
use AgentBrain\Modules\Ops\SelfCheckPage;

/**
 * Admin IA wave (Unit S): the single "Settings" page under the TomaTío
 * menu (Menu::PARENT), with tabs Summarising (slug cloud, the default) ·
 * Backups · Health (slug self-check) · Site (DedicatedSite) · Briefing
 * (slug onboarding, OnboardingSettings) · Sharing · Review (auto-approval
 * and expiry, #658) · Privacy (action context and provenance, PROV-7 #176).
 * Since #751 the tabs are a side list with a dot where a section needs
 * attention, and the page head says what needs fixing.
 * Each tab calls the existing page's renderBody() — the same
 * class that used to own its own standalone admin_options/admin_management
 * page. The old standalone slugs (ab-cloud, ab-backups, agent-brain-ops)
 * stay registered as hidden pages (owned by their respective classes) that
 * redirect here so old bookmarks/redirects keep working.
 */
final class SettingsHub {

	public const CAPABILITY = 'ab_manage';
	public const PAGE_SLUG  = 'agent-brain-settings';

	public const TAB_CLOUD      = 'cloud';
	public const TAB_BACKUPS    = 'backups';
	public const TAB_SELF_CHECK = 'self-check';
	public const TAB_SITE       = 'site';
	public const TAB_ONBOARDING = 'onboarding';
	public const TAB_SHARING    = 'sharing';
	public const TAB_PRIVACY    = 'privacy';
	public const TAB_REVIEW     = 'review';

	/**
	 * Tab slugs (kept from before #751, so old links work) and their
	 * untranslated names; labels() has the translated ones.
	 *
	 * @var array<string, string>
	 */
	public const TABS = [
		self::TAB_CLOUD      => 'Summarising',
		self::TAB_BACKUPS    => 'Backups',
		self::TAB_SELF_CHECK => 'Health',
		self::TAB_SITE       => 'Site',
		self::TAB_ONBOARDING => 'Briefing',
		self::TAB_SHARING    => 'Sharing',
		self::TAB_REVIEW     => 'Review',
		self::TAB_PRIVACY    => 'Privacy',
	];

	/**
	 * The last Health results as counts, so the side list can show a dot
	 * without running the checks (one makes a loopback HTTP request).
	 */
	public const HEALTH_TRANSIENT = 'ab_settings_health';

	/** @var array<int, array{id:string, label:string, status:string, message:string, remediation:string}>|null */
	private static ?array $checks = null;

	/**
	 * @return array<string, string> tab slug => translated label
	 */
	public static function labels(): array {
		return [
			self::TAB_CLOUD      => __('Summarising', 'agent-brain'),
			self::TAB_BACKUPS    => __('Backups', 'agent-brain'),
			self::TAB_SELF_CHECK => __('Health', 'agent-brain'),
			self::TAB_SITE       => __('Site', 'agent-brain'),
			self::TAB_ONBOARDING => __('Briefing', 'agent-brain'),
			self::TAB_SHARING    => __('Sharing', 'agent-brain'),
			self::TAB_REVIEW     => __('Review', 'agent-brain'),
			self::TAB_PRIVACY    => __('Privacy', 'agent-brain'),
		];
	}

	/**
	 * One-sentence explainer shown at the top of every Settings tab.
	 */
	public static function explainer(string $tab): string {
		switch ($tab) {
			case self::TAB_BACKUPS:
				return __('Backups keep an encrypted copy of this brain in TomaTío Cloud. Restoring one is not available yet, so keep a full export as well.', 'agent-brain');
			case self::TAB_SELF_CHECK:
				return __('Health tests that this site is set up correctly for TomaTío and tells you how to fix anything that is not.', 'agent-brain');
			case self::TAB_SITE:
				return __('Site settings decide whether this WordPress install is used only for TomaTío or also as a normal website.', 'agent-brain');
			case self::TAB_ONBOARDING:
				return __('Briefing settings shape what every agent is told when it connects: what this brain is for, your house rules and how much each briefing carries.', 'agent-brain');
			case self::TAB_SHARING:
				return __('Sharing settings control where share links to documents are served and who may open them.', 'agent-brain');
			case self::TAB_PRIVACY:
				return __('Privacy settings control what action context and provenance TomaTío records and how long it keeps it.', 'agent-brain');
			case self::TAB_REVIEW:
				return __('Review settings decide which facts your Tíos suggest become official team knowledge without waiting in the Review Queue, and how long the rest wait.', 'agent-brain');
			case self::TAB_CLOUD:
			default:
				return __('TomaTío works fully without any service. Here you choose how old memories are summarised: your site\'s AI provider, your own OpenAI-compatible endpoint, or the optional TomaTío Cloud, which also adds backups.', 'agent-brain');
		}
	}

	public static function register(): void {
		add_action('admin_menu', [self::class, 'registerPage']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
	}

	/**
	 * #751: assets/settings.js (the Save bar) on this page only.
	 */
	public static function enqueueAssets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check.
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
		if (! in_array($page, [self::PAGE_SLUG, \AgentBrain\Modules\Portability\ExportPage::SLUG], true) || ! function_exists('wp_enqueue_script') || ! defined('AGENT_BRAIN_PLUGIN_FILE')) {
			return;
		}

		wp_enqueue_script('agent-brain-settings', plugins_url('assets/settings.js', AGENT_BRAIN_PLUGIN_FILE), [], \AgentBrain\Core\AssetVersion::of('assets/settings.js'), true);
	}

	/**
	 * The self-check results, run once per request on the Health tab. The
	 * counts are kept for a week for the side list's dot.
	 *
	 * @return array<int, array{id:string, label:string, status:string, message:string, remediation:string}>
	 */
	public static function checks(): array {
		if (self::$checks === null) {
			self::$checks = SelfCheck::run();

			$count = ['fail' => 0, 'warn' => 0];
			foreach (self::$checks as $check) {
				$count['fail'] += $check['status'] === SelfCheck::STATUS_FAIL ? 1 : 0;
				$count['warn'] += $check['status'] === SelfCheck::STATUS_WARN ? 1 : 0;
			}
			set_transient(self::HEALTH_TRANSIENT, $count, WEEK_IN_SECONDS);
		}

		return self::$checks;
	}

	/**
	 * What needs attention, per tab: tone (bad or warn) and one line naming
	 * it. Only cheap, local state: options, the audit log and the counts the
	 * Health tab kept from its last run.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function attention(): array {
		$out = [];

		$provider = JobsPage::providerState();
		if ($provider['provider'] !== null && $provider['failing']) {
			$out[self::TAB_CLOUD] = ['bad', __('Summarising is failing', 'agent-brain')];
		}

		$warning = get_option(MarkdownStage::OPTION_LAST_WARNING);
		if (is_string($warning) && $warning !== '') {
			$out[self::TAB_BACKUPS] = ['warn', __('The last backup reported a problem', 'agent-brain')];
		}

		$health = get_transient(self::HEALTH_TRANSIENT);
		$fail   = is_array($health) ? (int) ($health['fail'] ?? 0) : 0;
		$warn   = is_array($health) ? (int) ($health['warn'] ?? 0) : 0;
		if ($fail > 0) {
			/* translators: %d: number of failing health checks */
			$out[self::TAB_SELF_CHECK] = ['bad', sprintf(_n('%d health check fails', '%d health checks fail', $fail, 'agent-brain'), $fail)];
		} elseif ($warn > 0) {
			/* translators: %d: number of health checks with a warning */
			$out[self::TAB_SELF_CHECK] = ['warn', sprintf(_n('%d health check warns', '%d health checks warn', $warn, 'agent-brain'), $warn)];
		}

		return $out;
	}

	/**
	 * The head's status line: how many things need fixing and the first.
	 *
	 * @param array<string, array{0: string, 1: string}> $attention
	 */
	public static function statusLine(array $attention): string {
		if ($attention === []) {
			return esc_html__('Nothing needs you', 'agent-brain');
		}

		$first = reset($attention);
		$count = count($attention);

		/* translators: %s: number of things to fix, in bold */
		return sprintf(esc_html(_n('%s thing to fix', '%s things to fix', $count, 'agent-brain')), '<b>' . esc_html(number_format_i18n($count)) . '</b>')
			. ' · ' . esc_html($first[1]);
	}

	public static function registerPage(): void {
		add_submenu_page(
			Menu::PARENT,
			__('Settings', 'agent-brain'),
			__('Settings', 'agent-brain'),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[self::class, 'render']
		);
	}

	/**
	 * Resolve the requested tab from $_GET['tab'], falling back to the
	 * default (Cloud) for anything unrecognized or absent.
	 */
	public static function currentTab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector, no state change.
		$requested = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : '';

		return array_key_exists($requested, self::TABS) ? $requested : self::TAB_CLOUD;
	}

	/**
	 * Build a URL to a specific hub tab, optionally with extra query args
	 * (e.g. a result flag from a just-handled admin-post action).
	 *
	 * @param array<string, string> $args
	 */
	public static function url(string $tab, array $args = []): string {
		$args['page'] = self::PAGE_SLUG;
		$args['tab']  = array_key_exists($tab, self::TABS) ? $tab : self::TAB_CLOUD;

		return add_query_arg($args, admin_url('admin.php'));
	}

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage TomaTío settings.', 'agent-brain'), 403);
		}

		self::$checks = null;
		$tab          = self::currentTab();
		$labels       = self::labels();
		if ($tab === self::TAB_SELF_CHECK) {
			self::checks();
		}
		$attention = self::attention();

		$items = [];
		foreach (array_keys(self::TABS) as $slug) {
			$items[] = ['label' => $labels[$slug], 'url' => self::url($slug), 'current' => $slug === $tab, 'tone' => $attention[$slug][0] ?? ''];
		}

		// Polish wave, item 5: the heading does not repeat the menu name
		// ("TomaTío Settings"); add_submenu_page()'s $page_title still sets
		// the browser <title>.
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo '<div class="wrap ab-admin ab-admin-settings">' . Ui::head(__('Settings', 'agent-brain'), self::statusLine($attention))
			. '<div class="ab-ui-setlayout">' . Ui::sideNav($items, __('Settings sections', 'agent-brain')) . '<div class="ab-ui-setbody">';
		// phpcs:enable

		echo '<div class="ab-ui-subhead"><h2>' . esc_html($labels[$tab]) . '</h2>';
		if ($tab === self::TAB_SELF_CHECK) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SelfCheckPage escapes.
			echo SelfCheckPage::statusHtml(self::checks()) . '<div class="ab-ui-tools"><a class="button" href="' . esc_url(self::url(self::TAB_SELF_CHECK)) . '">' . esc_html__('Check again', 'agent-brain') . '</a></div>';
		}
		echo '</div>';
		echo '<p class="ab-tab-intro description">' . esc_html(self::explainer($tab)) . '</p>';

		switch ($tab) {
			case self::TAB_BACKUPS:
				BackupSettingsPage::renderBody();
				break;
			case self::TAB_SELF_CHECK:
				SelfCheckPage::renderBody(self::checks());
				break;
			case self::TAB_SITE:
				DedicatedSite::renderBody();
				AppLink::renderBody();
				break;
			case self::TAB_ONBOARDING:
				OnboardingSettings::renderBody();
				break;
			case self::TAB_SHARING:
				\AgentBrain\Modules\Sharing\SettingsTab::renderBody();
				break;
			case self::TAB_PRIVACY:
				\AgentBrain\Modules\Provenance\SettingsTab::renderBody();
				break;
			case self::TAB_REVIEW:
				\AgentBrain\Modules\Review\SettingsTab::renderBody();
				break;
			case self::TAB_CLOUD:
			default:
				CloudSettings::renderBody();
				break;
		}

		echo '</div></div></div>';
	}
}
