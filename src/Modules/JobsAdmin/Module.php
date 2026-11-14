<?php

declare(strict_types=1);

namespace AgentBrain\Modules\JobsAdmin;

use AgentBrain\Core\ModuleInterface;

/**
 * "Jobs" admin screen (issue #87): compression / cloud job history, provider
 * state, and a retry action.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'jobs_admin';
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
