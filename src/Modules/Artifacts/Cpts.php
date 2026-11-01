<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Modules\Admin\Menu;

/**
 * ART-1: private post types for artifacts and their sections.
 *
 * ab_artifact is hierarchical (curators may nest artifacts); ab_artifact_section
 * is a flat post type whose post_parent is always the owning artifact. Both
 * are non-public but ARE exposed in REST (Artifact fix wave, 2026-09-27 —
 * required for the block editor / useEntityBlockEditor to work at all), and
 * map_meta_cap is enabled so ObjectAccess' map_meta_cap filter (lead agent /
 * section owner / curator) governs every edit/read/delete check, including
 * from core wp-admin screens and the REST controller's single-item routes.
 * Neither post type grants its 'create_posts'/'delete_posts' primitive caps
 * to any role (see ObjectAccess::installCapabilities' docblock), so nobody —
 * agent or curator — can create or delete either post type through the
 * generic REST controller; creation goes through ArtifactService only.
 * Collection queries are further narrowed for non-curators by Rest.php.
 */
class Cpts {

	public const ARTIFACT_POST_TYPE = 'ab_artifact';
	public const SECTION_POST_TYPE  = 'ab_artifact_section';

	public const META_LEAD_AGENT     = 'ab_lead_agent';
	public const META_SECTION_OWNER  = 'ab_section_owner';
	public const META_VERSION        = 'ab_version';
	public const META_ALLOWED_BLOCKS = 'ab_allowed_blocks';
	public const META_LEASE_EXPIRES  = 'ab_lease_expires';
	public const META_COMPILED_FROM  = 'ab_compiled_from';

	// Multi-agent authoring pipeline (outline -> drafting -> revising -> ready -> assembled).
	public const META_SCOPE_ID      = 'ab_scope_id';
	public const META_STAGE         = 'ab_stage';
	public const META_SECTION_STATE = 'ab_section_state';
	public const META_REVIEW_LOG    = 'ab_review_log';
	public const META_SECTION_BRIEF = 'ab_section_brief';
	public const META_LAST_WRITER   = 'ab_last_writer';

	// PROV-6 (#175): per-write action context on a section — one entry per
	// content write (version, actor, client/via/AI label, read set).
	public const META_ACTION_LOG    = 'ab_action_log';

	public const STAGE_OUTLINE   = 'outline';
	public const STAGE_DRAFTING  = 'drafting';
	public const STAGE_REVISING  = 'revising';
	public const STAGE_READY     = 'ready';
	public const STAGE_ASSEMBLED = 'assembled';

	/** @return string[] valid ab_stage values, in pipeline order. */
	public static function stages(): array {
		return [ self::STAGE_OUTLINE, self::STAGE_DRAFTING, self::STAGE_REVISING, self::STAGE_READY, self::STAGE_ASSEMBLED ];
	}

	public const SECTION_STATE_DRAFTING          = 'drafting';
	public const SECTION_STATE_SUBMITTED         = 'submitted';
	public const SECTION_STATE_APPROVED          = 'approved';
	public const SECTION_STATE_CHANGES_REQUESTED = 'changes_requested';

	/** @return string[] valid ab_section_state values. */
	public static function sectionStates(): array {
		return [
			self::SECTION_STATE_DRAFTING,
			self::SECTION_STATE_SUBMITTED,
			self::SECTION_STATE_APPROVED,
			self::SECTION_STATE_CHANGES_REQUESTED,
		];
	}

	/** @return string[] */
	public static function defaultAllowedBlocks(): array {
		return [
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/list-item',
			'core/quote',
			'core/code',
			'core/table',
			'core/image',
			'core/group',
			'core/columns',
			'core/column',
		];
	}

	public static function register(): void {
		register_post_type( self::ARTIFACT_POST_TYPE, [
			// Polish wave, item 4: the search box on the Artifacts list
			// screen read "Search Pages" (WordPress falls back to the
			// generic 'Post'/'Page' labels for any label key left unset).
			// Adding these fills every label WordPress consults for the
			// list screen's search/empty states without touching the
			// menu/heading labels above (unchanged, and asserted verbatim
			// by LibraryAdminCapsTest, outside this unit's scope).
			'labels'              => [
				// #751: people see "Documents"; "artifact" stays in the code,
				// the tools and the docs' technical pages.
				'name'                => __( 'Documents', 'agent-brain' ),
				'singular_name'       => __( 'Document', 'agent-brain' ),
				'add_new_item'        => __( 'Add New Document', 'agent-brain' ),
				'new_item'            => __( 'New Document', 'agent-brain' ),
				'edit_item'           => __( 'Edit Document', 'agent-brain' ),
				'view_item'           => __( 'View Document', 'agent-brain' ),
				'all_items'           => __( 'Documents', 'agent-brain' ),
				'search_items'        => __( 'Search Documents', 'agent-brain' ),
				'not_found'           => __( 'No documents yet. A curator creates one and sets an agent as lead.', 'agent-brain' ),
				'not_found_in_trash'  => __( 'No documents found in Trash.', 'agent-brain' ),
				'items_list'          => __( 'Documents list', 'agent-brain' ),
				'items_list_navigation' => __( 'Documents list navigation', 'agent-brain' ),
				'filter_items_list'   => __( 'Filter documents list', 'agent-brain' ),
				'view_items'          => __( 'View Documents', 'agent-brain' ),
				'menu_name'           => __( 'Documents', 'agent-brain' ),
				// Editor chrome (block editor reads these from the REST type
				// endpoint): never "Post".
				'item_published'           => __( 'Document published.', 'agent-brain' ),
				'item_published_privately' => __( 'Document saved privately.', 'agent-brain' ),
				'item_reverted_to_draft'   => __( 'Document reverted to draft.', 'agent-brain' ),
				'item_scheduled'           => __( 'Document scheduled.', 'agent-brain' ),
				'item_updated'             => __( 'Document updated.', 'agent-brain' ),
				'item_link'                => __( 'Document Link', 'agent-brain' ),
				'item_link_description'    => __( 'A link to a document.', 'agent-brain' ),
				'attributes'               => __( 'Document Attributes', 'agent-brain' ),
				'name_admin_bar'           => __( 'Document', 'agent-brain' ),
				'archives'                 => __( 'Document Archives', 'agent-brain' ),
				'insert_into_item'         => __( 'Insert into document', 'agent-brain' ),
				'uploaded_to_this_item'    => __( 'Uploaded to this document', 'agent-brain' ),
				'parent_item_colon'        => __( 'Parent Document:', 'agent-brain' ),
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => Menu::PARENT,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => true,
			'rest_base'           => 'ab-artifacts',
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			'hierarchical'        => true,
			'supports'            => [ 'title', 'editor', 'revisions', 'custom-fields' ],
			'capability_type'     => [ 'ab_artifact', 'ab_artifacts' ],
			'map_meta_cap'        => true,
			// 'create_posts' defaults to the SAME capability string as
			// 'edit_posts' when a capability_type is used without an
			// explicit override (WP_Post_Type::set_cap()). ObjectAccess'
			// user_has_cap filter grants edit_ab_artifacts to curators so
			// wp-admin's list/edit screens work; without this override that
			// grant would also silently unlock "Add New" and the generic
			// REST controller's create endpoint for curators, contradicting
			// this class's own docblock guarantee that artifact creation
			// only ever happens through ArtifactService. Pinning
			// 'create_posts' to the always-false 'do_not_allow' pseudo-cap
			// keeps creation locked regardless of what edit_posts grants.
			'capabilities'        => [
				'create_posts' => 'do_not_allow',
			],
		] );

		register_post_type( self::SECTION_POST_TYPE, [
			'labels'              => [
				'name'          => __( 'Artifact Sections', 'agent-brain' ),
				'singular_name' => __( 'Artifact Section', 'agent-brain' ),
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => true,
			'rest_base'           => 'ab-artifact-sections',
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			'hierarchical'        => false,
			'supports'            => [ 'title', 'editor', 'revisions', 'custom-fields' ],
			'capability_type'     => [ 'ab_artifact_section', 'ab_artifact_sections' ],
			'map_meta_cap'        => true,
		] );

		// Polish wave, item 4: the "Stage"/"Sections" list-table columns
		// belong with post-type registration (this class), not
		// Artifacts\Module.php (out of this unit's scope -- see the wave
		// contract's note that a per-request 'admin_menu'/hook wiring change
		// there is a separate unit's concern). register_post_type() always
		// runs on every request this class's own register() runs on, so
		// hooking the columns here guarantees they're wired whenever the
		// post type itself is.
		ListColumns::register();
	}
}
