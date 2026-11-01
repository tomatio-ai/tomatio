<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Core\ModuleInterface;

/**
 * Artifacts module (ART-1,2,3,4,5,7,8): curator-drafted multi-section
 * documents. ab_artifact (hierarchical, private) holds an outline of
 * brain/section blocks pointing at private ab_artifact_section child posts.
 * Outline edits are lead-agent/curator only; each section has exactly one
 * owner. Object-level permission checks live in ObjectAccess and are wired
 * through core's own map_meta_cap machinery so wp-admin editing (for
 * curators) and ArtifactService (for agents) enforce identical rules.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'artifacts';
	}

	public function register(): void {
		add_action( 'init', [ Cpts::class, 'register' ], 20 );
		add_action( 'init', [ StatusFields::class, 'registerMeta' ], 21 );
		add_action( 'rest_api_init', [ StatusFields::class, 'registerRestFields' ] );
		add_action( 'init', [ Block::class, 'register' ] );
		add_action( 'wp_insert_post', [ ArtifactService::class, 'initializeVersion' ], 10, 3 );
		add_filter( 'map_meta_cap', [ ObjectAccess::class, 'mapMetaCap' ], 10, 4 );
		// Per-request: add_filter() registrations don't persist, so the
		// wp-admin list caps must be wired here, not only on activation.
		ObjectAccess::installCapabilities();
		add_filter( 'rest_'. Cpts::ARTIFACT_POST_TYPE . '_query', [ Rest::class, 'filterArtifactQuery' ], 10, 2 );
		add_filter( 'rest_' . Cpts::SECTION_POST_TYPE . '_query', [ Rest::class, 'filterSectionQuery' ], 10, 2 );
		add_filter( 'rest_pre_insert_' . Cpts::ARTIFACT_POST_TYPE, [ Rest::class, 'refuseCoreWrites' ], 10, 2 );
		add_filter( 'rest_pre_insert_' . Cpts::SECTION_POST_TYPE, [ Rest::class, 'refuseCoreWrites' ], 10, 2 );
		add_action( 'wp_abilities_api_init', [ VersionAbilities::class, 'register' ] );
		AdminUi::register();
		EditScreen::register();
		HistoryBox::register();
		HtmlFiles::register();
		ReviewLock::register();
		add_action( 'template_redirect', [ Preview::class, 'maybeServe' ], 0 );
		add_action( 'wp_head', [ Styles::class, 'printForCompiledPost' ] );
		add_filter( 'agent_brain/forget_collect', [ Forget::class, 'collect' ], 10, 2 );
		add_action( 'agent_brain/forget_delete', [ Forget::class, 'delete' ], 10, 2 );
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		// No custom tables: ab_artifact / ab_artifact_section are ordinary
		// post types stored in wp_posts/wp_postmeta.
		return [];
	}

	public function activate(): void {
		ObjectAccess::installCapabilities();
	}

	public function deactivate(): void {
		// Nothing to unwind: no roles/capabilities are mutated by this
		// module (see ObjectAccess::installCapabilities) and no scheduled
		// jobs are registered.
	}
}
