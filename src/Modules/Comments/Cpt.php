<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Comments;

use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\PostTypeLabels;

/**
 * ART-28 (#168): private `ab_comment` CPT — comment threads attached to an
 * artifact version, optionally anchored to a section or element.
 *
 * Never public, never in REST. `hierarchical` is on so replies are ordinary
 * child posts (post_parent = the thread's root comment; roots have
 * post_parent 0) — one mechanism for both MCP replies and the wp-admin
 * "Attributes" parent picker, no second storage shape. map_meta_cap is on
 * and the primitive caps are granted to curators/administrators only (see
 * Capabilities), so the core list/edit screens stay curator-only; agents
 * reach comments exclusively through CommentService (MCP), which enforces
 * the artifact's own read gate (ObjectAccess) instead of these caps.
 *
 * post_status is 'private' for every comment (open and resolved alike),
 * mirroring ab_handoff: Review's PublishGuard reverts any 'publish'
 * transition on ab_* posts, and a non-public CPT has no use for 'publish'.
 * Open vs resolved is the `ab_comment_resolved` meta (1 = resolved), with
 * `ab_comment_resolved_by` / `ab_comment_resolved_at` attribution.
 */
class Cpt {

	public const POST_TYPE = 'ab_comment';

	/** The artifact (ab_artifact post id) this thread is attached to. */
	public const META_ARTIFACT = 'ab_comment_artifact';
	/** Optional anchor: ab_artifact_section post id, a child of the artifact. */
	public const META_SECTION  = 'ab_comment_section';
	/** Optional anchor: free-text element selector/name inside the section. */
	public const META_ELEMENT  = 'ab_comment_element';
	/** The artifact version the comment was made against (int >= 1). */
	public const META_VERSION  = 'ab_comment_version';
	/** 1 when the thread is resolved; absent = open. */
	public const META_RESOLVED     = 'ab_comment_resolved';
	public const META_RESOLVED_BY  = 'ab_comment_resolved_by';
	public const META_RESOLVED_AT  = 'ab_comment_resolved_at';
	/** Set on a handoff or proposed knowledge post created from a comment. */
	public const META_SOURCE = 'ab_comment_source';

	public const STATUS_OPEN     = 'open';
	public const STATUS_RESOLVED = 'resolved';

	/** @return string[] */
	public static function statuses(): array {
		return [self::STATUS_OPEN, self::STATUS_RESOLVED];
	}

	public static function register(): void {
		if (! function_exists('register_post_type')) {
			return;
		}

		register_post_type(self::POST_TYPE, [
			'labels'              => array_merge(PostTypeLabels::extras(__('Comments', 'agent-brain'), 'comments', __('Agents and curators comment on an artifact version without editing it.', 'agent-brain')), [
				'name'          => __('Comments', 'agent-brain'),
				'singular_name' => __('Comment', 'agent-brain'),
				'add_new_item'  => __('Add New Comment', 'agent-brain'),
				'new_item'      => __('New Comment', 'agent-brain'),
				'edit_item'     => __('Edit Comment', 'agent-brain'),
				'all_items'     => __('Comments', 'agent-brain'),
				'menu_name'     => __('Comments', 'agent-brain'),
				'parent_item_colon' => __('Thread:', 'agent-brain'),
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
			'hierarchical'        => true,
			'supports'            => ['title', 'editor', 'revisions'],
			'capability_type'     => ['ab_comment', 'ab_comments'],
			'map_meta_cap'        => true,
		]);

		ListColumns::register();
	}
}
