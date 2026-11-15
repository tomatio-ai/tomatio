<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Core\ModuleInterface;

/**
 * #987: the extension switches (TíoDay, Tomagic): Settings > Extensions and
 * the extension-list / extension-enable tools. The packages themselves are
 * bundled (Package, PageMaker, PageRefresh); this module only turns them
 * on and off for the brain.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'extensions';
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', [ ExtensionAbilities::class, 'register' ] );
		SettingsTab::register();
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [];
	}

	public function activate(): void {
	}

	public function deactivate(): void {
	}
}
