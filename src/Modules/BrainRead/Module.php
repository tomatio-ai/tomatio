<?php

declare(strict_types=1);

namespace AgentBrain\Modules\BrainRead;

use AgentBrain\Core\ModuleInterface;

/**
 * ART-25 (#165): read-only live brain data for sandboxed artifact pages.
 *
 * The endpoints live below the validated share link and are dispatched from
 * Sharing\ShareRoute (the ART-24 ViewerRoute pattern), so the module itself
 * only registers the curator toggle UI; the route, grants, search and
 * shaping are plain classes used by that dispatch. No migrations (the share
 * rate-limit table is reused) and no main-site REST surface.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'brainread';
	}

	public function register(): void {
		MetaBox::register();
	}

	public function migrations(): array {
		return [];
	}

	public function activate(): void {
		// Nothing: the feature is post-meta state plus read-only endpoints.
	}

	public function deactivate(): void {
		// Read-only feature; nothing to tear down.
	}
}
