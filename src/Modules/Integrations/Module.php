<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

use AgentBrain\Core\ModuleInterface;

/**
 * #990: Settings > Integrations, the sealed credential, and the two
 * abilities. Linear and Notion ship as folders under extensions/; a
 * third service is another folder, not a change here.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'integrations';
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', [ Abilities::class, 'register' ] );
		Actions::register();
		SetupStep::register();
		\AgentBrain\Modules\Integrations\Context\Actions::register();
		\AgentBrain\Modules\Integrations\Context\Cron::register();
		\AgentBrain\Modules\Integrations\Planning\Actions::register();
		\AgentBrain\Modules\Integrations\Planning\Cron::register();
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
