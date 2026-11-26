<?php

declare(strict_types=1);

namespace AgentBrain\Modules\MemoriesAdmin;

use AgentBrain\Core\ModuleInterface;

/**
 * Curator "Memories" admin screen (issue #84): browse, filter, inspect, edit
 * importance/search keys, and delete raw agent memories.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'memories_admin';
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
