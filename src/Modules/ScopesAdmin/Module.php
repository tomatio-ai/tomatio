<?php

declare(strict_types=1);

namespace AgentBrain\Modules\ScopesAdmin;

use AgentBrain\Core\ModuleInterface;

/**
 * "Scopes" admin screen (issue #85): manage ab_scope terms and who holds
 * read/write access to each, in one place.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'scopes_admin';
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
