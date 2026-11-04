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
 * this class must already be loaded and must not use any other class of the
 * plugin. The main file loads it at include time through registerBridge().
 */
final class Handover {

	public const OLD = 'agent-brain/agent-brain.php';
	public const NEW = 'tomatio/tomatio.php';

	/**
	 * Set (per site, or network-wide) when the new plugin still has to run its
	 * activation. Autoloaded, so the new plugin checks it without a query.
	 */
	public const PENDING_OPTION = 'agent_brain_handover_pending';

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
