<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Comments;

use AgentBrain\Core\ModuleInterface;

/**
 * Comments module (ART-28, #168): comment threads on an artifact version,
 * reachable over MCP (CommentService + the Mcp module's comment-* abilities)
 * and in wp-admin (curators: the ab_comment CPT's core list/edit screens
 * plus resolve/reopen row actions), exportable as PCP extension data
 * (Portability\ExtensionWriter) and covered by the Forget cascade.
 *
 * No tables (comments are ordinary posts), so migrations() is empty and
 * activation only needs the per-request capability filter, exactly like
 * the Artifacts module.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'comments';
	}

	public function register(): void {
		add_action('init', [Cpt::class, 'register'], 20);
		CommentCapabilities::register();
		AdminActions::register();
		add_filter('agent_brain/forget_collect', [Forget::class, 'collect'], 10, 2);
		add_action('agent_brain/forget_delete', [Forget::class, 'delete'], 10, 2);
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		// ab_comment lives in wp_posts/wp_postmeta; nothing to migrate.
		return [];
	}

	public function activate(): void {
		CommentCapabilities::register();
	}

	public function deactivate(): void {
		// Nothing to unwind: the capability filter is per-request and no
		// scheduled jobs exist.
	}
}
