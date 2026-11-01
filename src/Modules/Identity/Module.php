<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

use AgentBrain\Core\ModuleInterface;

/**
 * Identity & scopes module (PRD ID-1, ID-2, ID-3, ID-4, ID-6). ID-5
 * (OAuth/CIMD) is out of scope for this wave.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'identity';
	}

	public function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('init', [$this, 'registerScopeTaxonomy']);

		Capabilities::registerHooks();
		ForbiddenCapsNotice::registerHooks();

		add_action(AgentProposals::EXPIRE_HOOK, static function (): void {
			AgentProposals::pruneExpired();
		});

		// After the AgentId module (priority 20), so an agent's class is set.
		add_action('user_register', [DefaultScope::class, 'onUserChanged'], 25, 1);
		add_action('set_user_role', [DefaultScope::class, 'onUserChanged'], 25, 1);
		add_action('add_user_role', [DefaultScope::class, 'onUserChanged'], 25, 1);

		TransportAuth::registerHooks();

		(new AppPasswords())->registerHooks();
		(new ScopesUi())->registerHooks();
	}

	/**
	 * Registers the private ab_scope taxonomy. Attached object types come
	 * from the agent_brain/scope_object_types filter (default
	 * [ab_knowledge, ab_procedure]) so the CPT-owning module (Memory) can be
	 * added or reordered without Identity needing to know about it directly.
	 */
	public function registerScopeTaxonomy(): void {
		if (! function_exists('register_taxonomy')) {
			return;
		}

		$objectTypes = apply_filters('agent_brain/scope_object_types', ['ab_knowledge', 'ab_procedure']);
		$objectTypes = is_array($objectTypes) ? $objectTypes : ['ab_knowledge', 'ab_procedure'];

		register_taxonomy(Scopes::TAXONOMY, $objectTypes, [
			'public'            => false,
			'publicly_queryable' => false,
			'show_ui'           => false,
			'show_in_rest'      => false,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false,
			'show_admin_column' => false,
			'query_var'         => false,
			'rewrite'           => false,
			'hierarchical'      => false,
			'labels'            => [
				'name'          => 'Agent Scopes',
				'singular_name' => 'Agent Scope',
			],
		]);
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			// The team scope a brain with no scope at all starts with (#182).
			1 => static function (): void {
				DefaultScope::migrate();
			},
			// Agent proposals expire after 24 hours (#234).
			2 => static function (): void {
				AgentProposals::scheduleExpiry();
			},
		];
	}

	public function activate(): void {
		Capabilities::install();
		AgentProposals::scheduleExpiry();
	}

	public function deactivate(): void {
		Capabilities::uninstall();
		wp_clear_scheduled_hook(AgentProposals::EXPIRE_HOOK);
	}
}
