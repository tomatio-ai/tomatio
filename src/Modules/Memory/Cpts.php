<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Memory;

use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\PostTypeLabels;

/**
 * MEM-5: private `ab_knowledge` (facts) and `ab_procedure` (playbooks) post
 * types. Never public, never in REST, revisions on, map_meta_cap on so
 * Identity can grant the generated primitive caps to the curator role.
 *
 * The `ab_scope` taxonomy is registered elsewhere (Identity module); we only
 * reference it by name and attach it to our own post types. If it isn't
 * registered yet at our hook priority (or at all, e.g. in an isolated test),
 * that must never fatal this module.
 */
class Cpts {

	public const SCOPE_TAXONOMY = 'ab_scope';

	/** @return string[] */
	public static function postTypes(): array {
		return [ 'ab_knowledge', 'ab_procedure' ];
	}

	public static function register(): void {
		register_post_type( 'ab_knowledge', self::postTypeArgs(
			'ab_knowledge',
			'ab_knowledges',
			__( 'Knowledge', 'agent-brain' ),
			__( 'Knowledge', 'agent-brain' ),
			__( 'Knowledge', 'agent-brain' )
		) );

		register_post_type( 'ab_procedure', self::postTypeArgs(
			'ab_procedure',
			'ab_procedures',
			__( 'Procedure', 'agent-brain' ),
			__( 'Procedures', 'agent-brain' ),
			__( 'Procedures', 'agent-brain' )
		) );

		self::attachScopeTaxonomy();
	}

	private static function attachScopeTaxonomy(): void {
		if ( ! taxonomy_exists( self::SCOPE_TAXONOMY ) ) {
			// Identity module hasn't registered ab_scope (not loaded, or this
			// module's init hook ran first). Not fatal — attachment is
			// retried on every request until it succeeds.
			return;
		}

		foreach ( self::postTypes() as $postType ) {
			register_taxonomy_for_object_type( self::SCOPE_TAXONOMY, $postType );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function postTypeArgs( string $singularCap, string $pluralCap, string $singularLabel, string $pluralLabel, string $menuName ): array {
		return [
			'labels'              => array_merge( PostTypeLabels::extras( $pluralLabel, strtolower( $pluralLabel ), __( 'Agents propose items for review; approved ones appear here.', 'agent-brain' ) ), [
				'name'          => $pluralLabel,
				'singular_name' => $singularLabel,
				/* translators: %s: singular item label, e.g. "Knowledge" or "Procedure". */
				'add_new_item'  => sprintf( __( 'Add New %s', 'agent-brain' ), $singularLabel ),
				/* translators: %s: singular item label, e.g. "Knowledge" or "Procedure". */
				'new_item'      => sprintf( __( 'New %s', 'agent-brain' ), $singularLabel ),
				/* translators: %s: singular item label, e.g. "Knowledge" or "Procedure". */
				'edit_item'     => sprintf( __( 'Edit %s', 'agent-brain' ), $singularLabel ),
				'all_items'     => $pluralLabel,
				// Admin consistency wave (#78): sidebar label, list-screen H1 and
				// 'all_items' are all the same word ("Knowledge" / "Procedures").
				'menu_name'     => $menuName,
			] ),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => Menu::PARENT,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			'hierarchical'        => false,
			'supports'            => [ 'title', 'editor', 'revisions' ],
			'capability_type'     => [ $singularCap, $pluralCap ],
			'map_meta_cap'        => true,
		];
	}
}
