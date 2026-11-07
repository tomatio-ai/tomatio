<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * The slug rename (#804, #822): the plugin moves from
 * `agent-brain/agent-brain.php` to `tomatio/tomatio.php`. WordPress treats the
 * new file as a different plugin. Its upgrader deletes the old folder,
 * installs the zip's top folder (`tomatio/`) and leaves the old file name
 * stale in `active_plugins`, so on most update paths (AJAX "update now",
 * bulk, auto-update) the brain would end up switched off.
 *
 * The bridge in the last release still in `agent-brain/` watches its own
 * update. When the old plugin was active and the update left
 * `tomatio/tomatio.php` in its place, it moves the entry in `active_plugins`
 * (or the network's list) and in `auto_update_plugins` to the new file, and
 * leaves a flag so the new plugin runs its activation once on first boot.
 * No data moves: both folders read the same tables and options.
 *
 * By the time `upgrader_process_complete` fires, the old files are gone, so
 * the bridge methods must already be loaded and must not use any other class
 * of the plugin. The old main file loads this class at include time through
 * registerBridge().
 *
 * The new copy (`tomatio/tomatio.php`) uses the other half through
 * registerNewCopy(): it runs the activation the handover left pending, drops
 * a stale old entry from the plugin lists, and keeps an old copy left behind
 * from erasing the shared data when someone deletes it.
 */
final class Handover {

	public const OLD = 'agent-brain/agent-brain.php';
	public const NEW = 'tomatio/tomatio.php';

	/**
	 * Set (per site, or network-wide) when the new plugin still has to run its
	 * activation. Autoloaded, so the new plugin checks it without a query.
	 */
	public const PENDING_OPTION = 'agent_brain_handover_pending';

	/** The running copy's own basename, once registerNewCopy() ran. */
	private static string $self = '';

	/** Whether OLD was active when its update started; null until upgrader_pre_install saw it. */
	private static ?bool $wasActive = null;

	private static bool $wasNetworkActive = false;

	public static function registerBridge(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		// Priority 1: before Plugin_Upgrader's own upgrader_pre_install
		// (priority 10), which silently deactivates the plugin on an
		// interactive update.
		add_filter('upgrader_pre_install', [self::class, 'rememberActive'], 1, 2);
		add_action('upgrader_process_complete', [self::class, 'afterUpgrade'], 20, 2);
	}

	/**
	 * `upgrader_pre_install`: remember whether the old plugin is active
	 * before WordPress switches it off for the update.
	 *
	 * @param mixed $response
	 * @param mixed $hookExtra
	 * @return mixed $response, unchanged.
	 */
	public static function rememberActive($response, $hookExtra) {
		if (is_array($hookExtra) && ($hookExtra['plugin'] ?? '') === self::OLD) {
			self::$wasActive        = in_array(self::OLD, self::activePlugins(), true);
			self::$wasNetworkActive = isset(self::networkPlugins()[self::OLD]);
		}

		return $response;
	}

	/**
	 * `upgrader_process_complete`: after an update of the old plugin, hand
	 * over to the new file when the update put it in place.
	 *
	 * @param mixed  $upgrader
	 * @param mixed  $hookExtra
	 * @param string $pluginsDir The plugins folder; WordPress passes nothing (WP_PLUGIN_DIR).
	 */
	public static function afterUpgrade($upgrader, $hookExtra, string $pluginsDir = ''): void {
		unset($upgrader);
		if (! is_array($hookExtra) || ($hookExtra['type'] ?? '') !== 'plugin' || ($hookExtra['action'] ?? '') !== 'update') {
			return;
		}
		$plugins = isset($hookExtra['plugins']) ? (array) $hookExtra['plugins'] : [(string) ($hookExtra['plugin'] ?? '')];
		if (! in_array(self::OLD, $plugins, true)) {
			return;
		}

		if ($pluginsDir === '' && defined('WP_PLUGIN_DIR')) {
			$pluginsDir = (string) WP_PLUGIN_DIR;
		}
		self::handOverAfterUpdate($pluginsDir);
	}

	/**
	 * The update moved the plugin when the old main file is gone and the new
	 * one exists in $pluginsDir. Then the old entries become the new ones,
	 * as active as the old plugin was before the update.
	 *
	 * @return bool Whether anything was handed over.
	 */
	public static function handOverAfterUpdate(string $pluginsDir): bool {
		$pluginsDir = rtrim($pluginsDir, '/\\');
		if ($pluginsDir === '' || file_exists($pluginsDir . '/' . self::OLD) || ! file_exists($pluginsDir . '/' . self::NEW)) {
			return false;
		}

		// Without upgrader_pre_install (an update started some other way),
		// the lists themselves still say whether it was active: a background
		// update never deactivates.
		$active  = self::$wasActive ?? in_array(self::OLD, self::activePlugins(), true);
		$network = self::$wasNetworkActive || isset(self::networkPlugins()[self::OLD]);

		self::$wasActive        = null;
		self::$wasNetworkActive = false;

		return self::swap(self::OLD, self::NEW, $active && ! $network, $network);
	}

	/**
	 * Move $from to $to in the plugin lists: `active_plugins` (when
	 * $activeOnSite), the network's `active_sitewide_plugins` (when
	 * $activeOnNetwork), and `auto_update_plugins` (whenever $from is in it,
	 * so the site's auto-update choice carries over). $from is always removed
	 * from the activation lists. It is a plain list edit: neither plugin's
	 * activation or deactivation hooks run, so cron events and transients
	 * stay as they are. When $to becomes active, the pending flag is set.
	 *
	 * @return bool Whether any list changed.
	 */
	public static function swap(string $from, string $to, bool $activeOnSite, bool $activeOnNetwork): bool {
		$changed = false;

		$active = self::activePlugins();
		$next   = array_values(array_filter($active, static fn($p): bool => $p !== $from));
		if ($activeOnSite && ! in_array($to, $next, true)) {
			$next[] = $to;
		}
		sort($next);
		$sortedActive = $active;
		sort($sortedActive);
		if ($next !== $sortedActive) {
			update_option('active_plugins', $next);
			$changed = true;
		}

		if (function_exists('is_multisite') && is_multisite()) {
			$network = self::networkPlugins();
			$nextNet = $network;
			$since   = $nextNet[$from] ?? time();
			unset($nextNet[$from]);
			if ($activeOnNetwork && ! isset($nextNet[$to])) {
				$nextNet[$to] = $since;
			}
			if ($nextNet !== $network) {
				update_site_option('active_sitewide_plugins', $nextNet);
				$changed = true;
			}
		}

		$auto = (array) get_site_option('auto_update_plugins', []);
		if (in_array($from, $auto, true)) {
			$nextAuto = array_values(array_filter($auto, static fn($p): bool => $p !== $from));
			if (! in_array($to, $nextAuto, true)) {
				$nextAuto[] = $to;
			}
			update_site_option('auto_update_plugins', $nextAuto);
			$changed = true;
		}

		if ($activeOnNetwork) {
			update_site_option(self::PENDING_OPTION, 1);
		} elseif ($activeOnSite) {
			update_option(self::PENDING_OPTION, 1, true);
		}

		return $changed;
	}

	/**
	 * The new copy's hooks. $self is its basename (normally NEW).
	 */
	public static function registerNewCopy(string $self): void {
		if (! function_exists('add_action') || $self === self::OLD) {
			return;
		}
		self::$self = $self;

		// Late on init: post types and roles are registered, as they are when
		// WordPress runs an activation from the Plugins screen.
		add_action('init', [self::class, 'finish'], 99);
		add_action('pre_uninstall_plugin', [self::class, 'guardOldUninstall'], 1);
		add_action('admin_notices', [self::class, 'leftoverNotice']);
		add_action('network_admin_notices', [self::class, 'leftoverNotice']);
	}

	/**
	 * Activation of the new copy: the old file leaves the plugin lists (it
	 * is gone, or this activation replaces it) and its auto-update choice
	 * carries over. WordPress itself adds the new file once the hook returns.
	 */
	public static function takeOver(string $self, bool $networkWide): void {
		unset($networkWide);
		if ($self === self::OLD) {
			return;
		}
		self::swap(self::OLD, $self, false, false);
	}

	/**
	 * `init`: run the activation a handover left pending (once), and drop an
	 * old entry still in the plugin lists. Free on an ordinary request: the
	 * flag is autoloaded and active_plugins is already in memory.
	 */
	public static function finish(): void {
		if (self::$self === '') {
			return;
		}

		if (in_array(self::OLD, self::activePlugins(), true) || isset(self::networkPlugins()[self::OLD])) {
			self::swap(self::OLD, self::$self, false, false);
		}

		$alloptions = wp_load_alloptions();
		if (isset($alloptions[self::PENDING_OPTION])) {
			delete_option(self::PENDING_OPTION);
			\AgentBrain\Plugin::instance()->activate(false);
		}
		if (is_multisite() && get_site_option(self::PENDING_OPTION)) {
			delete_site_option(self::PENDING_OPTION);
			\AgentBrain\Plugin::instance()->activate(true);
		}
	}

	/**
	 * `pre_uninstall_plugin`: deleting the old copy from the Plugins screen
	 * (or `wp plugin delete agent-brain`) runs its uninstall.php, which
	 * erases the brain on a site that opted in to data removal. The data is
	 * now this copy's, so the old copy reads the opt-in as off.
	 *
	 * @param mixed $plugin
	 */
	public static function guardOldUninstall($plugin): void {
		if ($plugin !== self::OLD || self::$self === '') {
			return;
		}
		add_filter('pre_option_ab_delete_data_on_uninstall', '__return_zero', 99);
	}

	/**
	 * `admin_notices` on the Plugins screen: an old copy left in
	 * wp-content/plugins/agent-brain/ is switched off and safe to delete.
	 */
	public static function leftoverNotice(): void {
		if (self::$self === '' || ! current_user_can('delete_plugins') || ! defined('WP_PLUGIN_DIR') || ! file_exists(WP_PLUGIN_DIR . '/' . self::OLD)) {
			return;
		}
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen === null || ! in_array($screen->id, ['plugins', 'plugins-network'], true)) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__('TomaTío now runs from its own tomatio folder, with all your data and connections. The old copy in the agent-brain folder is switched off. You can delete it here: deleting it never removes your data.', 'tomatio')
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function activePlugins(): array {
		return array_values(array_map('strval', (array) get_option('active_plugins', [])));
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function networkPlugins(): array {
		if (! function_exists('is_multisite') || ! is_multisite()) {
			return [];
		}

		return (array) get_site_option('active_sitewide_plugins', []);
	}
}
