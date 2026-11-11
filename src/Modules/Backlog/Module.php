<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Core\ModuleInterface;

/**
 * The team backlog (#891, note 643 step 1) and its first reads from the
 * tools a team already uses (#892, step 2). Built in core, shaped like an
 * extension (C184): the page and its store schemas are the bundled
 * package extensions/backlog; this module reads and writes them.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'backlog';
	}

	public function register(): void {
		Backlog::register();
		add_action( 'wp_abilities_api_init', [ ProjectAbilities::class, 'register' ] );
		Sources\SettingsTab::register();
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'tomatio backlog', BacklogCli::class );
			\WP_CLI::add_command( 'agent-brain backlog', BacklogCli::class );
		}
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
