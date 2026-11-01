<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\PostTypeLabels;

/**
 * Private `ab_handoff` CPT (REV-1..3 scope: registration only; the
 * handoff.request_review ability lands in Wave 3). Never public, never in
 * REST, revisions on, map_meta_cap so the curator role's primitive caps
 * (already installed generically for ab_* capability_type pairs it knows
 * about) can be extended to it by Identity in a later wave without Review
 * needing to change.
 *
 * Admin IA wave: `show_in_menu` moves from `true` (its own top-level menu)
 * to `Menu::PARENT`, so "Handoffs" lists under the single "TomaTío"
 * menu instead of getting its own sidebar entry -- same shared contract
 * every other TomaTío CPT (Memory\Cpts, Artifacts\Cpts) already
 * follows. Full labels (add_new_item/new_item/edit_item/all_items/menu_name)
 * are required by that same contract so "Add Post" never appears anywhere
 * in the CPT's admin UI.
 */
class HandoffCpt {

	public const POST_TYPE = 'ab_handoff';

	public static function register(): void {
		if (! function_exists('register_post_type')) {
			return;
		}

		register_post_type(self::POST_TYPE, [
			'labels'              => array_merge(PostTypeLabels::extras(__('Handoffs', 'agent-brain'), 'handoffs', __('Agents create them when they ask a curator to share memory.', 'agent-brain')), [
				'name'          => __('Handoffs', 'agent-brain'),
				'singular_name' => __('Handoff', 'agent-brain'),
				'add_new_item'  => __('Add New Handoff', 'agent-brain'),
				'new_item'      => __('New Handoff', 'agent-brain'),
				'edit_item'     => __('Edit Handoff', 'agent-brain'),
				'all_items'     => __('Handoffs', 'agent-brain'),
				'menu_name'     => __('Handoffs', 'agent-brain'),
			]),
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
			'supports'            => ['title', 'editor', 'revisions'],
			'capability_type'     => ['ab_handoff', 'ab_handoffs'],
			'map_meta_cap'        => true,
		]);
	}
}
