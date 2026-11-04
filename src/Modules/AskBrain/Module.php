<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AskBrain;

use AgentBrain\Core\ModuleInterface;

/**
 * ART-26 (#166): ask the brain a question from a page.
 *
 * The endpoint lives below the validated share link and is dispatched from
 * Sharing\ShareRoute (the ART-25 BrainRead pattern), so the module itself
 * only registers the curator toggle UI and the agent-brain/ask MCP ability;
 * the route, grants, spend caps, provider selection and the question engine
 * are plain classes used by those entry points. No migrations (the share
 * rate-limit table is reused; cost caps live in dated options) and no
 * main-site REST surface.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'askbrain';
	}

	public function register(): void {
		MetaBox::register();
		add_action( 'wp_abilities_api_init', [ Abilities::class, 'register' ] );
	}

	public function migrations(): array {
		return [];
	}

	public function activate(): void {
		// Nothing: the feature is post-meta state plus endpoint classes.
	}

	public function deactivate(): void {
		// Read-only feature; nothing to tear down.
	}
}
