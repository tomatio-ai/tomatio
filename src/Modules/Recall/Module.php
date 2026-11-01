<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Recall;

use AgentBrain\Core\ModuleInterface;

/**
 * Recall module (REC-1..REC-5): scoped, ranked search over memory +
 * approved knowledge/procedures, and the token-budgeted session briefing.
 *
 * Owns no tables of its own (reads through Memory's ab_memory table and
 * WordPress's ab_knowledge/ab_procedure post types); migrations() is
 * therefore empty.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'recall';
	}

	public function register(): void {
		// No hooks of its own to register: Recall is a pull-model reader
		// consumed by MCP abilities (Wave 3). It DOES consume the
		// `agent_brain/recall_vector_candidates` filter (REC-4) at call time
		// inside Recall::search(), not via add_filter().
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [];
	}

	public function activate(): void {
		// No schema, no cron: nothing to do on activation.
	}

	public function deactivate(): void {
		// Nothing to do on deactivation.
	}
}
