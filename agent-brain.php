<?php
/**
 * Plugin Name:       TomaTío
 * Plugin URI:        https://tomatio.ai/self-host
 * Description:       Private, scoped, curator-reviewed memory for external MCP agents.
 * Version:           1.9.9
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Update URI:        https://github.com/tomatio-ai/tomatio
 * Author:            Dennis Kittrell, Roger Gentry
 * Author URI:        https://tomatio.ai
 * License:           GPL-2.0-or-later
 * Text Domain:       agent-brain
 *
 * @package AgentBrain
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('AGENT_BRAIN_VERSION', '1.9.9');
define('AGENT_BRAIN_PLUGIN_FILE', __FILE__);
define('AGENT_BRAIN_PLUGIN_DIR', __DIR__);

$agent_brain_autoload = AGENT_BRAIN_PLUGIN_DIR . '/vendor/autoload.php';
if (file_exists($agent_brain_autoload)) {
	require_once $agent_brain_autoload;
}

register_activation_hook(__FILE__, static function (bool $network_wide = false): void {
	\AgentBrain\Plugin::instance()->activate($network_wide);
});

// #338: provision the brain (tables, roles, options) for blogs created
// after activation, on multisite networks.
\AgentBrain\Core\Multisite::registerHooks();

register_deactivation_hook(__FILE__, static function (): void {
	\AgentBrain\Plugin::instance()->deactivate();
});

add_action('plugins_loaded', static function (): void {
	\AgentBrain\Plugin::instance()->boot();
});
