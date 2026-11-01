<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Identity\Capabilities;

/**
 * The single "TomaTío" admin menu (admin IA wave, ledger ecc6ce42).
 *
 * Every other page under the TomaTío surface keeps its own slug and
 * CAPABILITY constant and re-parents to Menu::PARENT via its own
 * add_submenu_page() call at the default admin_menu priority (10). This
 * class owns only: the top-level slot itself (registered at priority 5, so
 * WordPress has somewhere to attach submenu items registered at the default
 * priority), the Grants submenu (no other unit owns a "page" class for the
 * ab_grant taxonomy screen), the final sort + badge pass (priority 999, so
 * it runs after every submenu has been registered), and the visibility rule
 * that fully hides the top-level entry for a user who cannot open a single
 * page under it.
 */
final class Menu {

	public const PARENT = 'agent-brain';

	public const ORDER = [
		// Overview.
		'agent-brain',
		// Review.
		'agent-brain-review',
		'edit.php?post_type=ab_handoff',
		// Library.
		'agent-brain-memories',
		'edit.php?post_type=ab_knowledge',
		'edit.php?post_type=ab_procedure',
		'edit.php?post_type=ab_artifact',
		'edit-tags.php?taxonomy=ab_grant&post_type=ab_knowledge',
		// Agents (Connect an AI is the first-run step).
		'agent-brain-agents',
		'agent-brain-agent-view',
		'agent-brain-scopes',
		'agent-brain-connect',
		// Operations.
		'agent-brain-audit',
		'agent-brain-jobs',
		// Admin.
		'agent-brain-forget',
		'agent-brain-export',
		'agent-brain-settings',
	];

	/**
	 * First slug of each group after the first; its row gets a separator class.
	 */
	public const GROUP_STARTS = [
		'agent-brain-review',
		'agent-brain-memories',
		'agent-brain-agents',
		'agent-brain-audit',
		'agent-brain-forget',
	];

	public const SEPARATOR_CLASS = 'ab-menu-sep';

	public const GRANTS_SLUG ='edit-tags.php?taxonomy=ab_grant&post_type=ab_knowledge';

	/**
	 * Nested-CPT submenu slug -> post type (polish wave, item 2). WordPress's
	 * own `_add_post_type_submenus()` (wp-includes/post.php) always labels a
	 * nested CPT's submenu row with `labels->all_items`, never `menu_name` --
	 * that constant is only consulted when a CPT registers its own top-level
	 * menu. Knowledge/Procedures/Artifacts deliberately keep a longer
	 * `all_items` ("Knowledge Items") for the list screen's own heading while
	 * wanting a shorter sidebar entry ("Knowledge"); rather than mutate
	 * `all_items` in Cpts.php (which would break LibraryAdminCapsTest's
	 * existing, out-of-scope assertion that `all_items === 'Knowledge
	 * Items'`), the short label is applied here, at render time, sourced from
	 * each post type's own `labels->menu_name` (already correct per the Unit
	 * L contract).
	 *
	 * @var array<string, string>
	 */
	private const NESTED_CPT_SLUGS = [
		'edit.php?post_type=ab_handoff'   => 'ab_handoff',
		'edit.php?post_type=ab_artifact'  => 'ab_artifact',
		'edit.php?post_type=ab_knowledge' => 'ab_knowledge',
		'edit.php?post_type=ab_procedure' => 'ab_procedure',
	];

	/**
	 * Capabilities that gate at least one TomaTío admin surface. Used
	 * both by the "does this user see anything at all" hide rule below and
	 * documented in the contract as the same set OverviewPage itself
	 * requires one of.
	 *
	 * @return array<int, string>
	 */
	public static function surfaceCapabilities(): array {
		return [
			Capabilities::CAP_APPROVE_KNOWLEDGE,
			Capabilities::CAP_VIEW_AUDIT,
			Capabilities::CAP_MANAGE,
		];
	}

	public static function registerTopLevel(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addTopLevel'], 5);
		add_action('admin_menu', [self::class, 'reorder'], 999);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueMenuStyle']);
	}

	/**
	 * Registers the top-level slot only. Its capability is deliberately the
	 * loose 'read' (per contract): the *page* itself (OverviewPage::render())
	 * enforces the real gate with wp_die(), and reorder() below removes the
	 * whole top-level entry outright for a user who holds none of
	 * surfaceCapabilities() — 'read' alone (which every ab_agent has) would
	 * otherwise leave the entry visible in the sidebar.
	 */
	public static function addTopLevel(): void {
		if (! function_exists('add_menu_page')) {
			return;
		}

		add_menu_page(
			__('TomaTío', 'agent-brain'),
			__('TomaTío', 'agent-brain'),
			'read',
			self::PARENT,
			[OverviewPage::class, 'render'],
			self::iconDataUri(),
			3
		);

		add_submenu_page(
			self::PARENT,
			__('Grants', 'agent-brain'),
			__('Grants', 'agent-brain'),
			Capabilities::CAP_PUBLISH,
			self::GRANTS_SLUG
		);
	}

	/**
	 * Sorts $submenu[PARENT] by ORDER (unknown slugs keep their relative
	 * order at the end), stamps badge counts onto the Review Queue and
	 * Handoffs rows and the top-level label, and — because WordPress does
	 * not do this on its own — removes the entire top-level entry for a
	 * user who cannot open a single TomaTío screen.
	 */
	public static function reorder(): void {
		global $menu, $submenu;

		if (! self::currentUserCanSeeAnySurface()) {
			if (function_exists('remove_menu_page')) {
				remove_menu_page(self::PARENT);
			}

			return;
		}

		$counts = self::badgeCounts();
		$total  = $counts['review'] + $counts['handoffs'];

		if (isset($submenu[self::PARENT]) && is_array($submenu[self::PARENT])) {
			$order = array_flip(self::ORDER);
			$items = self::dedupeParentSlugEntries($submenu[self::PARENT]);

			usort($items, static function (array $a, array $b) use ($order): int {
				$slugA = (string) ($a[2] ?? '');
				$slugB = (string) ($b[2] ?? '');

				return ($order[$slugA] ?? PHP_INT_MAX) <=> ($order[$slugB] ?? PHP_INT_MAX);
			});

			foreach ($items as &$item) {
				$slug = (string) ($item[2] ?? '');

				if (isset(self::NESTED_CPT_SLUGS[$slug])) {
					$item[0] = self::shortMenuLabel(self::NESTED_CPT_SLUGS[$slug], (string) $item[0]);
				}

				if (in_array($slug, self::GROUP_STARTS, true)) {
					$item[4] = trim((string) ($item[4] ?? '') . ' ' . self::SEPARATOR_CLASS);
				}

				if ($slug === 'agent-brain-review') {
					$item[0] .= self::badgeMarkup($counts['review'] + $counts['handoffs']);
				} elseif ($slug === 'edit.php?post_type=ab_handoff') {
					$item[0] .= self::badgeMarkup($counts['handoffs']);
				}
			}
			unset($item);

			$submenu[self::PARENT] = $items;
		}

		if (is_array($menu)) {
			foreach ($menu as &$menuItem) {
				if (($menuItem[2] ?? null) === self::PARENT) {
					$menuItem[0] = __('TomaTío', 'agent-brain') . self::badgeMarkup($total);
					break;
				}
			}
			unset($menuItem);
		}
	}

	/**
	 * Counted for the current user: only items within their scopes (#552).
	 *
	 * @return array{review: int, handoffs: int}
	 */
	public static function badgeCounts(): array {
		return [
			'review'   => self::countProposedKnowledgeAndProcedures(),
			'handoffs' => self::countOpenHandoffs(),
		];
	}

	/**
	 * Whether an admin_enqueue_scripts $hook is the screen of the TomaTío
	 * submenu page $slug. WordPress names that screen after the top-level
	 * menu's title ("tomatio_page_{slug}" since the rename; "agent-brain_page_"
	 * before it, and translated titles change it again), so match the
	 * "_page_{slug}" ending rather than one spelling.
	 */
	public static function isPageHook(string $hook, string $slug): bool {
		return $slug !== '' && str_ends_with($hook, '_page_' . $slug);
	}

	/**
	 * True while the current screen is anywhere under the TomaTío admin
	 * surface: any admin.php?page=agent-brain* screen, any TomaTío CPT
	 * edit/list screen, or the Grants taxonomy screen. Public per contract
	 * (Menu owns and enqueues assets.php only on TomaTío screens).
	 */
	public static function isAgentBrainScreen(): bool {
		if (! function_exists('get_current_screen')) {
			return false;
		}

		$screen = get_current_screen();
		if (! $screen instanceof \WP_Screen) {
			return false;
		}

		if (in_array($screen->post_type, [
			'ab_handoff',
			'ab_artifact',
			'ab_knowledge',
			'ab_procedure',
		], true)) {
			return true;
		}

		if ($screen->taxonomy === 'ab_grant') {
			return true;
		}

		return strpos($screen->id, self::PARENT) !== false;
	}

	/**
	 * The sidebar shows on every admin screen, so the group separator style
	 * is inlined globally rather than shipped in the TomaTío-only CSS.
	 */
	public static function enqueueMenuStyle(): void {
		if (! function_exists('wp_register_style')) {
			return;
		}

		wp_register_style('agent-brain-menu', false, [], null);
		wp_enqueue_style('agent-brain-menu');
		wp_add_inline_style(
			'agent-brain-menu',
			self::menuCss()
		);
	}

	/**
	 * Group separator plus the count pill. Core restyles `.awaiting-mod`
	 * inside the current menu item, which made the badge lose its pill;
	 * pin one look for every state (#92).
	 */
	public static function menuCss(): string {
		$top = '#toplevel_page_' . self::PARENT;

		// Scoped to the TomaTío menu: core and other plugins keep their own badge colours.
		// Blue pill by default; white pill with blue text where the top-level row itself is
		// blue (current, hover, open), so the badge never blends into its background.
		return '#adminmenu .wp-submenu li.' . self::SEPARATOR_CLASS . '{border-top:1px solid rgba(255,255,255,.15);margin-top:4px;padding-top:4px}'
			. $top . ' .awaiting-mod{background-color:#2271b1;color:#fff}'
			. $top . '.current .awaiting-mod,' . $top . '.wp-has-current-submenu .awaiting-mod,'
			. $top . ':hover .awaiting-mod,' . $top . '.opensub .awaiting-mod{background-color:#fff;color:#2271b1}'
			. $top . ' .wp-submenu .awaiting-mod,' . $top . ' .wp-submenu li.current .awaiting-mod,'
			. $top . ' .wp-submenu a:hover .awaiting-mod{background-color:#2271b1;color:#fff}';
	}

	public static function enqueueAssets(): void {
		if (! self::isAgentBrainScreen()) {
			return;
		}

		if (! function_exists('wp_enqueue_style') || ! defined('AGENT_BRAIN_PLUGIN_FILE')) {
			return;
		}

		wp_enqueue_style(
			'agent-brain-admin',
			plugins_url('assets/admin.css', AGENT_BRAIN_PLUGIN_FILE),
			[],
			\AgentBrain\Core\AssetVersion::of('assets/admin.css')
		);

		// #751: j, k and / on the list-and-detail pages; it does nothing elsewhere.
		wp_enqueue_script(
			'agent-brain-admin-ui',
			plugins_url('assets/admin-ui.js', AGENT_BRAIN_PLUGIN_FILE),
			[],
			\AgentBrain\Core\AssetVersion::of('assets/admin-ui.js'),
			true
		);
	}

	/**
	 * Polish wave, item 1: `add_submenu_page()` auto-prepends a link back to
	 * the parent (label = the top-level menu's own `menu_title`, slug =
	 * PARENT) the *first* time it is ever called for a parent slug with no
	 * existing submenu (see wp-admin/includes/plugin.php's
	 * `add_submenu_page()`, the `! isset( $submenu[$parent_slug] ) &&
	 * $menu_slug !== $parent_slug` branch). Because Menu::addTopLevel()
	 * registers the Grants submenu (priority 5) before OverviewPage's own
	 * self-referencing "Overview" entry (default priority 10) is ever
	 * registered, that auto-link is created first -- live screenshots showed
	 * both "TomaTío" (the auto-link) and "Overview" (the real entry)
	 * pointing at the same page. Only one registration can ever be the
	 * genuine self-referencing entry (add_submenu_page() short-circuits the
	 * auto-link when $menu_slug === $parent_slug), so collapsing duplicate
	 * PARENT-slug rows down to the *last* one registered always keeps the
	 * real entry, regardless of which unit's admin_menu callback happens to
	 * run first.
	 *
	 * @param array<int, array<int, mixed>> $items
	 * @return array<int, array<int, mixed>>
	 */
	private static function dedupeParentSlugEntries(array $items): array {
		$bySlug = [];

		foreach ($items as $item) {
			$slug = (string) ($item[2] ?? '');

			if ($slug === '') {
				$bySlug[] = $item;

				continue;
			}

			// Last write wins: only the genuine self-referencing entry can
			// share PARENT's own slug with the auto-added link, and it is
			// always registered after the auto-link (see docblock above).
			$bySlug[$slug] = $item;
		}

		return array_values($bySlug);
	}

	/**
	 * Polish wave, item 2: WordPress's `_add_post_type_submenus()` labels a
	 * nested CPT's sidebar entry with `labels->all_items`, never
	 * `labels->menu_name` (that field is only used when a CPT registers its
	 * own top-level menu). Knowledge/Procedures/Artifacts each carry a
	 * shorter `menu_name` for exactly this sidebar slot; this substitutes it
	 * in at render time without touching the CPT's own `all_items` label
	 * (used elsewhere, e.g. the list screen heading), which other units'
	 * tests assert against directly.
	 */
	private static function shortMenuLabel(string $postType, string $fallback): string {
		if (! function_exists('get_post_type_object')) {
			return $fallback;
		}

		$object = get_post_type_object($postType);
		if (! $object instanceof \WP_Post_Type || empty($object->labels->menu_name)) {
			return $fallback;
		}

		return (string) $object->labels->menu_name;
	}

	private static function currentUserCanSeeAnySurface(): bool {
		if (! function_exists('current_user_can')) {
			return false;
		}

		foreach (self::surfaceCapabilities() as $cap) {
			if (current_user_can($cap)) {
				return true;
			}
		}

		return false;
	}

	private static function countProposedKnowledgeAndProcedures(): int {
		if (! function_exists('get_posts')) {
			return 0;
		}

		$ids = get_posts([
			'post_type'      => ['ab_knowledge', 'ab_procedure'],
			'post_status'    => 'ab_proposed',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		return count(ScopeVisibility::filterPostIds(get_current_user_id(), $ids));
	}

	private static function countOpenHandoffs(): int {
		if (! function_exists('get_posts')) {
			return 0;
		}

		$ids = get_posts([
			'post_type'      => 'ab_handoff',
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'     => 'ab_handoff_status',
					'compare' => 'NOT EXISTS',
				],
			],
		]);

		return count(ScopeVisibility::filterPostIds(get_current_user_id(), $ids));
	}

	/**
	 * `<span class="awaiting-mod count-N"><span class="pending-count">N</span></span>`,
	 * matching WordPress core's own "Comments"-style menu badge markup so it
	 * inherits core admin CSS; empty string when $count is 0 (hidden).
	 */
	public static function badgeMarkup(int $count): string {
		if ($count <= 0) {
			return '';
		}

		return sprintf(
			' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>',
			$count
		);
	}

	/**
	 * The tomatillo mark as a data: URI so no extra HTTP request is added
	 * for the sidebar icon. The 32 px PNG is read from the shipped brand
	 * assets and inlined; on any read failure it falls back to the mark
	 * served over plugins_url() rather than breaking the menu.
	 */
	private static function iconDataUri(): string {
		$file = \AGENT_BRAIN_PLUGIN_DIR . '/assets/brand/tomatio-32.png';
		$png  = is_readable($file) ? file_get_contents($file) : false;

		if (is_string($png) && $png !== '') {
			return 'data:image/png;base64,' . base64_encode($png);
		}

		return (string) plugins_url('assets/brand/tomatio-32.png', \AGENT_BRAIN_PLUGIN_FILE);
	}
}
