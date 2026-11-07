<?php

declare(strict_types=1);

namespace AgentBrain;

use AgentBrain\Core\Health;
use AgentBrain\Core\Migrations;
use AgentBrain\Core\ModuleRegistry;
use AgentBrain\Core\Multisite;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * Plugin bootstrap. Do not add feature logic here — this file is shared
 * bootstrap that parallel workers must never need to touch; new
 * functionality belongs in a self-registering module under src/Modules/.
 */
class Plugin {

	private static ?Plugin $instance = null;

	private ModuleRegistry $registry;

	private function __construct() {
		$this->registry = new ModuleRegistry();
	}

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		$modules = $this->registry->discover();
		Health::report($this->registry->failures());
		$this->registry->registerAll();

		$storedVersion = get_option('agent_brain_version');
		if ($storedVersion !== AGENT_BRAIN_VERSION || Migrations::hasPending($modules)) {
			// Migrations may qualify users through role capabilities (the
			// portability backfill does), so roles and caps must exist first —
			// the same order Multisite::provisionBlog() guarantees at
			// activation, which boot() cannot assume (e.g. the test harness
			// loads the plugin without ever firing the activation hook).
			Capabilities::install();
			Migrations::runAll($modules);
			update_option('agent_brain_version', AGENT_BRAIN_VERSION);
		}
	}

	public function activate(bool $network_wide = false): void {
		$modules = $this->registry->discover();
		Health::report($this->registry->failures());

		Multisite::provisionSites($network_wide, $modules);
	}

	public function deactivate(): void {
		$modules = $this->registry->discover();

		foreach ($modules as $module) {
			$module->deactivate();
		}
	}

	public function registry(): ModuleRegistry {
		return $this->registry;
	}
}
