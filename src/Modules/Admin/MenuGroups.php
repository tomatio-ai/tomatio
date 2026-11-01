<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * #472: the TomaTío sidebar shows six groups (Overview, Review, Library,
 * Agents, Activity, Settings) instead of every screen, and each group's
 * screens get a tab row on top.
 *
 * Only the sidebar changes. Every screen keeps its submenu entry and its
 * address, so access checks, bookmarks, docs and the Menu::reorder() order
 * stay as they are: the grouping is applied just before the sidebar is
 * drawn (the parent_file filter in menu-header.php), when the first screen
 * of each group the user may open becomes the group's row and the others
 * are hidden. A group's tabs list only the screens the user may open.
 */
final class MenuGroups {

	/** Group key => member slugs, in tab order. The first one the user may open leads. */
	public const GROUPS = [
		'overview' => ['agent-brain'],
		'review'   => ['agent-brain-review', 'edit.php?post_type=ab_handoff'],
		'library'  => ['agent-brain-memories', 'edit.php?post_type=ab_knowledge', 'edit.php?post_type=ab_procedure', 'edit.php?post_type=ab_artifact', 'edit.php?post_type=ab_comment'],
		'agents'   => ['agent-brain-agents', 'agent-brain-agent-view', 'agent-brain-scopes', Menu::GRANTS_SLUG, 'agent-brain-connect'],
		'activity' => ['agent-brain-audit', 'agent-brain-jobs'],
		'settings' => ['agent-brain-settings', 'agent-brain-export', 'agent-brain-forget', 'agent-brain-provenance'],
	];

	public const HIDDEN_CLASS = 'ab-menu-hidden';

	/**
	 * Pages without a menu row of their own => the row they belong to
	 * (#751: Knowledge, Procedures, Documents and Comments open LibraryPage).
	 */
	public const ALIASES = [
		'agent-brain-knowledge'  => 'edit.php?post_type=ab_knowledge',
		'agent-brain-procedures' => 'edit.php?post_type=ab_procedure',
		'agent-brain-documents'  => 'edit.php?post_type=ab_artifact',
		GrantsPage::SLUG         => Menu::GRANTS_SLUG,
		HandoffsPage::SLUG       => 'edit.php?post_type=ab_handoff',
		'agent-brain-comments'   => 'edit.php?post_type=ab_comment',
	];

	/** @var array<string, string> slug => menu label before grouping (tab labels). */
	private static array $labels = [];

	public static function register(): void {
		add_filter('parent_file', [self::class, 'groupSidebar'], 99);
		add_filter('submenu_file', [self::class, 'highlightGroup'], 99);
		add_action('all_admin_notices', [self::class, 'renderTabs'], 1);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueStyle']);
	}

	/** @return array<string, string> */
	public static function labels(): array {
		return [
			'overview' => __('Overview', 'agent-brain'),
			'review'   => __('Review', 'agent-brain'),
			'library'  => __('Library', 'agent-brain'),
			'agents'   => __('Agents', 'agent-brain'),
			'activity' => __('Activity', 'agent-brain'),
			'settings' => __('Settings', 'agent-brain'),
		];
	}

	public static function groupOf(string $slug): ?string {
		foreach (self::GROUPS as $group => $slugs) {
			if (in_array($slug, $slugs, true)) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * Group the sidebar rows. A filter only for its timing: $parentFile is
	 * returned unchanged.
	 *
	 * @param mixed $parentFile
	 * @return mixed
	 */
	public static function groupSidebar($parentFile) {
		global $submenu;

		if (isset($submenu[Menu::PARENT]) && is_array($submenu[Menu::PARENT])) {
			$submenu[Menu::PARENT] = self::group($submenu[Menu::PARENT]);
		}

		return $parentFile;
	}

	/**
	 * The grouped rows: the lead of each group takes the group's name (and
	 * keeps a count pill), the other members are hidden. Slugs outside every
	 * group stay as they are.
	 *
	 * @param array<int, array<int, mixed>> $items
	 * @return array<int, array<int, mixed>>
	 */
	public static function group(array $items): array {
		$present = array_map(static fn (array $item): string => (string) ($item[2] ?? ''), $items);
		$leads   = [];
		foreach (self::GROUPS as $group => $slugs) {
			foreach ($slugs as $slug) {
				if (in_array($slug, $present, true)) {
					$leads[$slug] = $group;
					break;
				}
			}
		}

		$labels = self::labels();
		foreach ($items as &$item) {
			$slug  = (string) ($item[2] ?? '');
			$group = self::groupOf($slug);
			if ($group === null) {
				continue;
			}

			self::$labels[$slug] ??= (string) ($item[0] ?? '');
			$classes = array_diff(preg_split('/\s+/', trim((string) ($item[4] ?? ''))) ?: [], [Menu::SEPARATOR_CLASS, self::HIDDEN_CLASS, '']);

			if (isset($leads[$slug])) {
				$label   = (string) ($item[0] ?? '');
				$pill    = ($at = strpos($label, '<span')) !== false ? ' ' . substr($label, $at) : '';
				$item[0] = $labels[$group] . $pill;
				if ($group !== 'overview') {
					$classes[] = Menu::SEPARATOR_CLASS;
				}
			} else {
				$classes[] = self::HIDDEN_CLASS;
			}

			$item[4] = implode(' ', $classes);
		}
		unset($item);

		return $items;
	}

	/**
	 * On any screen of a group, highlight the group's row.
	 *
	 * @param mixed $submenuFile
	 * @return mixed
	 */
	public static function highlightGroup($submenuFile) {
		global $submenu;

		// WordPress leaves it unset on plugin pages (it matches $plugin_page instead).
		$slug  = is_string($submenuFile) && $submenuFile !== '' ? $submenuFile : self::currentSlug();
		$group = $slug !== '' ? self::groupOf($slug) : null;
		if ($group === null) {
			return $submenuFile;
		}

		$present = array_map(static fn (array $item): string => (string) ($item[2] ?? ''), (array) ($submenu[Menu::PARENT] ?? []));
		foreach (self::GROUPS[$group] as $member) {
			if (in_array($member, $present, true)) {
				return $member;
			}
		}

		return $submenuFile;
	}

	/**
	 * The current screen's slug in GROUPS terms, or '' when it is not a
	 * group's list or page screen (edit screens of one item get no tabs).
	 */
	public static function currentSlug(): string {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (! $screen instanceof \WP_Screen) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
		if ($page !== '' && self::groupOf($page) !== null) {
			return $page;
		}
		if (isset(self::ALIASES[$page])) {
			return self::ALIASES[$page];
		}
		if ($screen->base === 'edit' && $screen->post_type !== '') {
			return 'edit.php?post_type=' . $screen->post_type;
		}
		if ($screen->base === 'edit-tags' && $screen->taxonomy === 'ab_grant') {
			return Menu::GRANTS_SLUG;
		}

		return '';
	}

	/**
	 * The tab row for $current's group: links to the members the user may
	 * open, or '' for a group with one such screen.
	 *
	 * @param array<int, array<int, mixed>> $items The TomaTío submenu rows.
	 */
	public static function tabsHtml(string $current, array $items): string {
		$group = self::groupOf($current);
		if ($group === null) {
			return '';
		}

		$rows = [];
		foreach ($items as $item) {
			$rows[(string) ($item[2] ?? '')] = $item;
		}

		$links = [];
		foreach (self::GROUPS[$group] as $slug) {
			if (! isset($rows[$slug])) {
				continue;
			}
			$label   = wp_strip_all_tags(self::$labels[$slug] ?? (string) $rows[$slug][0]);
			$label   = trim((string) preg_replace('/\s*\d+$/', '', $label));
			$url     = str_contains($slug, '.php') ? admin_url($slug) : admin_url('admin.php?page=' . $slug);
			$active  = $slug === $current;
			$links[] = '<a href="' . esc_url($url) . '" class="ab-group-tab' . ($active ? ' is-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
		}

		if (count($links) < 2) {
			return '';
		}

		$labels = self::labels();

		/* translators: %s: the menu group, for example Library. */
		return '<nav class="ab-group-tabs" aria-label="' . esc_attr(sprintf(__('%s screens', 'agent-brain'), $labels[$group])) . '">' . implode('', $links) . '</nav>';
	}

	public static function renderTabs(): void {
		global $submenu;

		$current = self::currentSlug();
		if ($current === '') {
			return;
		}

		echo self::tabsHtml($current, (array) ($submenu[Menu::PARENT] ?? [])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tabsHtml() escapes.
	}

	/** The sidebar shows everywhere, so the hide rule and tab look are inlined globally. */
	public static function enqueueStyle(): void {
		if (! function_exists('wp_register_style')) {
			return;
		}

		wp_register_style('agent-brain-menu-groups', false, [], null);
		wp_enqueue_style('agent-brain-menu-groups');
		wp_add_inline_style('agent-brain-menu-groups', self::css());
	}

	public static function css(): string {
		return '#adminmenu .wp-submenu li.' . self::HIDDEN_CLASS . '{display:none}'
			. '.ab-group-tabs{display:flex;flex-wrap:wrap;gap:4px;margin:14px 20px 0 2px;padding:0 0 10px;border-bottom:1px solid #dcdcde}'
			. '.ab-group-tabs .ab-group-tab{padding:6px 12px;border-radius:999px;color:#1d2327;text-decoration:none;font-weight:500}'
			. '.ab-group-tabs .ab-group-tab:hover{background:#f0f0f1;color:#1d2327}'
			. '.ab-group-tabs .ab-group-tab:focus{box-shadow:0 0 0 2px #2271b1;outline:0}'
			. '.ab-group-tabs .ab-group-tab.is-active{background:#1d2327;color:#fff}';
	}
}
