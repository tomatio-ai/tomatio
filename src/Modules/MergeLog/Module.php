<?php

declare(strict_types=1);

namespace AgentBrain\Modules\MergeLog;

use AgentBrain\Core\ModuleInterface;

/**
 * Merge log (#870, TíoDay T199): a repository's webhook tells the brain
 * when a pull request is merged, and an important one (it adds a
 * changelog.d/ fragment) is saved as one memory, so the record does not
 * depend on a session remembering to log it.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'merge-log';
	}

	public function register(): void {
		add_action('rest_api_init', [Receiver::class, 'registerRoutes']);
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
