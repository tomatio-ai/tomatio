<?php

declare(strict_types=1);

namespace AgentBrain\Core;

use AgentBrain\Plugin;

/**
 * Multisite provisioning (#338). Everything the plugin stores is per-blog —
 * ab_* tables, options, CPTs, crons, transients — so "activation" on a
 * network means running the ordinary per-blog activation in every blog's
 * context. Core switch discipline applies throughout: every switch_to_blog()
 * is paired with restore_current_blog() (provisionSite()), and nothing here
 * caches table or option values across a switch — Db::table() reads
 * $wpdb->prefix per call, Migrations re-reads its version option per blog,
 * and WordPress itself re-initializes the roles object on switch_blog.
 */
class Multisite {

	/**
	 * Pure decision over which blog ids activation must provision: a plain
	 * (or per-blog) activation only ever touches the current blog, even on a
	 * network; a network-wide activation touches every site. Kept free of
	 * WordPress calls so the matrix is unit-testable.
	 *
	 * @param array<int, mixed> $siteIds blog ids on the network (already
	 * fetched, may contain strings); ignored unless network-wide.
	 * @return int[]
	 */
	public static function selectSiteIds(bool $isMultisite, bool $networkWide, int $currentBlogId, array $siteIds): array {
		if (! $isMultisite || ! $networkWide) {
			return [$currentBlogId];
		}

		$ids = array_values(array_unique(array_map('intval', $siteIds)));
		$ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));

		return $ids === [] ? [$currentBlogId] : $ids;
	}

	/**
	 * Provision the blogs activation must cover. Fetches the network's site
	 * list only for a network-wide activation; 'number' => 0 lifts the
	 * default 100-site cap so large networks are fully provisioned.
	 *
	 * @param array<string, ModuleInterface> $modules id => module
	 */
	public static function provisionSites(bool $networkWide, array $modules): void {
		$siteIds = [];

		if ($networkWide && is_multisite()) {
			$siteIds = get_sites(['fields' => 'ids', 'number' => 0]);
		}

		foreach (self::selectSiteIds(is_multisite(), $networkWide, (int) get_current_blog_id(), $siteIds) as $blogId) {
			self::provisionSite($blogId, $modules);
		}
	}

	/**
	 * Run the ordinary per-blog activation in $blogId's context. Skips the
	 * switch when the blog is already current (including all single-site
	 * calls), and always restores the previous blog after a switch.
	 *
	 * @param array<string, ModuleInterface> $modules id => module
	 */
	public static function provisionSite(int $blogId, array $modules): void {
		if (! is_multisite() || $blogId === (int) get_current_blog_id()) {
			self::provisionBlog($modules);

			return;
		}

		switch_to_blog($blogId);

		try {
			self::provisionBlog($modules);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Register the new-blog provisioning hook. Called from agent-brain.php at
	 * plugin load, not from boot(), so it exists from muplugins_loaded on.
	 */
	public static function registerHooks(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('wp_initialize_site', [self::class, 'onInitializeSite']);
	}

	/**
	 * wp_initialize_site: a blog created after activation gets its brain
	 * (tables, roles, options) immediately, instead of waiting for the first
	 * request that boots the plugin on it. Core fires the action with the new
	 * blog already current; provisionSite() re-checks, so this is also safe
	 * under WP-CLI where no switch has happened.
	 *
	 * @param mixed $newSite WP_Site instance.
	 */
	public static function onInitializeSite($newSite): void {
		$blogId = isset($newSite->blog_id) ? (int) $newSite->blog_id : 0;
		if ($blogId <= 0) {
			return;
		}

		$modules = Plugin::instance()->registry()->discover();
		self::provisionSite($blogId, $modules);
	}

	/**
	 * The exact per-blog work of Plugin::activate(): every module's
	 * activation (Identity installs the ab_agent/ab_curator roles and merges
	 * curator capabilities into the blog's administrator role — roles are
	 * per-blog data), then migrations, then the version stamp. Must only be
	 * called in the target blog's context.
	 *
	 * @param array<string, ModuleInterface> $modules id => module
	 */
	private static function provisionBlog(array $modules): void {
		foreach ($modules as $module) {
			$module->activate();
		}

		Migrations::runAll($modules);
		update_option('agent_brain_version', AGENT_BRAIN_VERSION);
	}
}
