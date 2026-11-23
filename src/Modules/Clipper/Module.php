<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Clipper;

use AgentBrain\Core\ModuleInterface;

/**
 * B228 web clipper, the brain side.
 *
 * - Clips: save a clip (an outside url source linked to an episode memory
 *   row in the scope the person picks) and look an address up; each
 *   person's Read later page (the bundled extensions/readlater package).
 * - Abilities: tomatio-clip-save and tomatio-clip-lookup.
 *
 * The browser extension itself lives in the repository's clipper/ folder
 * and connects like any MCP client, through the brain's OAuth consent.
 * No table of its own, so no migration.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'clipper';
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', [ Abilities::class, 'register' ] );
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
