<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Ops;

use AgentBrain\Core\ModuleInterface;

/**
 * Ops module (Wave 3, OPS-1..3): WriteLimiter concurrency cap (Core, used
 * directly by MCP write abilities), the curator self-check admin page, the
 * Authorization-header loopback probe route, and the DB budget/decay cron.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'ops';
	}

	public function register(): void {
		add_action('rest_api_init', [AuthProbe::class, 'registerRoutes']);

		SelfCheckPage::register();
		DbBudget::registerAdminNotice();
		Cron::register();
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [];
	}

	public function activate(): void {
		// ab_manage itself is installed by the Identity module; this module
		// only depends on it, never installs it.
		Cron::activate();
	}

	public function deactivate(): void {
		Cron::deactivate();
	}
}
