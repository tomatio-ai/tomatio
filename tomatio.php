<?php
/**
 * Plugin Name:       TomaTío
 * Plugin URI:        https://tomatio.ai/self-host
 * Description:       Private, scoped, curator-reviewed memory for external MCP agents.
 * Version:           1.9.14
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Update URI:        https://github.com/tomatio-ai/tomatio
 * Author:            Dennis Kittrell, Roger Gentry
 * Author URI:        https://tomatio.ai
 * License:           GPL-2.0-or-later
 * Text Domain:       tomatio
 *
 * @package AgentBrain
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

// #804: another copy of TomaTío is already loaded in this request. That is the
// old agent-brain/ folder, which sorts first in active_plugins. Both copies
// declare the same classes, so this one loads nothing. It only hands over:
// the old file leaves the plugin lists, and the next request runs this copy
// alone. Self-contained on purpose: no class of this copy may load here.
if (defined('AGENT_BRAIN_PLUGIN_FILE')) {
	$tomatio_hand_over = static function (bool $networkWide): void {
		$old  = plugin_basename((string) constant('AGENT_BRAIN_PLUGIN_FILE'));
		$new  = plugin_basename(__FILE__);
		$list = array_values(array_filter((array) get_option('active_plugins', []), static fn($p): bool => $p !== $old));
		update_option('active_plugins', $list);
		if ($networkWide && is_multisite()) {
			$network = (array) get_site_option('active_sitewide_plugins', []);
			unset($network[$old]);
			update_site_option('active_sitewide_plugins', $network);
		}
		$auto = (array) get_site_option('auto_update_plugins', []);
		if (in_array($old, $auto, true)) {
			$auto   = array_values(array_filter($auto, static fn($p): bool => $p !== $old && $p !== $new));
			$auto[] = $new;
			update_site_option('auto_update_plugins', $auto);
		}
		// The new copy runs its activation once, on its first request alone.
		if ($networkWide) {
			update_site_option('agent_brain_handover_pending', 1);
		} else {
			update_option('agent_brain_handover_pending', 1, false);
		}
	};

	register_activation_hook(__FILE__, static function (bool $network_wide = false) use ($tomatio_hand_over): void {
		$tomatio_hand_over($network_wide);
	});

	// Both copies already active (set some other way than activation).
	$tomatio_self = plugin_basename(__FILE__);
	if (in_array($tomatio_self, (array) get_option('active_plugins', []), true)) {
		$tomatio_hand_over(false);
	} elseif (is_multisite() && isset(((array) get_site_option('active_sitewide_plugins', []))[$tomatio_self])) {
		$tomatio_hand_over(true);
	}

	return;
}

define('AGENT_BRAIN_VERSION', '1.9.14');
define('AGENT_BRAIN_PLUGIN_FILE', __FILE__);
define('AGENT_BRAIN_PLUGIN_DIR', __DIR__);
define('TOMATIO_VERSION', AGENT_BRAIN_VERSION);
define('TOMATIO_PLUGIN_FILE', __FILE__);
define('TOMATIO_PLUGIN_DIR', __DIR__);

$agent_brain_autoload = AGENT_BRAIN_PLUGIN_DIR . '/vendor/autoload.php';
if (file_exists($agent_brain_autoload)) {
	require_once $agent_brain_autoload;
}

register_activation_hook(__FILE__, static function (bool $network_wide = false): void {
	\AgentBrain\Plugin::instance()->activate($network_wide);
	// #804: a site moving from agent-brain/ drops the old file from its
	// plugin lists (no deactivation hook runs, so its cron events stay).
	\AgentBrain\Core\Handover::takeOver(plugin_basename(__FILE__), $network_wide);
});

// #804: finish a handover from agent-brain/ (activation once, stale entries),
// and keep an old copy left behind from ever erasing the shared data.
\AgentBrain\Core\Handover::registerNewCopy(plugin_basename(__FILE__));

// #338: provision the brain (tables, roles, options) for blogs created
// after activation, on multisite networks.
\AgentBrain\Core\Multisite::registerHooks();

register_deactivation_hook(__FILE__, static function (): void {
	\AgentBrain\Plugin::instance()->deactivate();
});

add_action('plugins_loaded', static function (): void {
	\AgentBrain\Plugin::instance()->boot();
});
