<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

use AgentBrain\Core\ModuleInterface;

/**
 * MCP module (Wave 3 "MCP abilities (v1)", extended by the multi-agent
 * authoring pipeline fix wave): registers the Abilities API abilities in
 * AbilityNames::ALL under the `agent-brain` category and, through
 * AdapterBridge, the one custom MCP server that exposes them.
 *
 * This module owns no tables (migrations() is empty) and references
 * `wordpress/mcp-adapter` (WP\MCP\*) classes ONLY via AdapterBridge — every
 * other file in this module, and every other module in the plugin, talks to
 * the adapter exclusively through that wrapper (binding contract).
 */
class Module implements ModuleInterface {

	public const CATEGORY = 'agent-brain';

	public function id(): string {
		return 'mcp';
	}

	public function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('wp_abilities_api_categories_init', [self::class, 'registerCategory']);
		add_action('wp_abilities_api_init', [Abilities::class, 'register']);

		// AdapterBridge hooks 'mcp_adapter_init' itself (it is the sole file
		// allowed to reference WP\MCP\* classes); Module only needs to make
		// sure that hook exists, which the mcp-adapter package's own
		// bootstrap (autoloaded via Composer) provides once it is loaded.
		AdapterBridge::registerHooks();
		ServerIdentity::register();
	}

	public static function registerCategory(): void {
		if (! function_exists('wp_register_ability_category')) {
			return;
		}

		// `wp_abilities_api_categories_init` is a plain WordPress action:
		// besides the registry's own one-time lazy init, any code (tests
		// included) may fire it again directly. Re-registering the same
		// category would trigger a core _doing_it_wrong() notice for no
		// benefit, so this guards the same way core's own examples do.
		if (function_exists('wp_has_ability_category') && wp_has_ability_category(self::CATEGORY)) {
			return;
		}

		wp_register_ability_category(self::CATEGORY, [
			'label'       => __('TomaTío', 'agent-brain'),
			'description' => __('TomaTío memory, knowledge, handoff, and artifact abilities exposed over MCP.', 'agent-brain'),
		]);
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [];
	}

	public function activate(): void {
		// Nothing to do: abilities/server are registered on every request
		// via the hooks above; there is no schema or cron for this module.
	}

	public function deactivate(): void {
		// Nothing to do.
	}
}
