<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentView;

use AgentBrain\Core\ModuleInterface;

/**
 * "Agent View" admin screen (issue #86): see exactly what one agent sees.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'agent_view';
	}

	public function register(): void {
		Page::register();
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
