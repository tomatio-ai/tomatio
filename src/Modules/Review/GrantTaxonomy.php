<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Identity\Capabilities;

/**
 * REV-3: private `ab_grant` taxonomy with the single term `public-grant`.
 * Owned by Review. Assigning the term requires `ab_publish`; agents never
 * hold that capability (see contracts.md, Naming), and
 * GrantTaxonomy::assignPublicGrant() is the only sanctioned code path that
 * assigns it — PublishGuard re-checks the term + actor at publish time
 * regardless of how it was assigned.
 */
class GrantTaxonomy {

	public const TAXONOMY   = 'ab_grant';
	public const TERM_SLUG  = 'public-grant';

	public static function register(): void {
		if (! function_exists('register_taxonomy')) {
			return;
		}

		if (taxonomy_exists(self::TAXONOMY)) {
			return;
		}

		// §1.9 (artifact-sharing-design.md): PublishGuard already enforces
		// the public-grant gate mechanically on every 'ab_*' post type and
		// on any post compiled from an artifact (`ab_compiled_from`),
		// which includes plain 'post' rows (ArtifactService::compile()/
		// assemble() both create post_type 'post'). Registering the
		// taxonomy against 'post' too closes the declared-vs-enforced gap:
		// before this, wp_set_object_terms() could still be called and
		// the term would attach, but 'post' items never showed the term
		// in taxonomy UI/queries scoped to registered object types.
		register_taxonomy(self::TAXONOMY, ['ab_knowledge', 'ab_procedure', 'post'], [
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_rest'       => false,
			'show_in_nav_menus'  => false,
			'show_tagcloud'      => false,
			'show_admin_column'  => true,
			'query_var'          => false,
			'rewrite'            => false,
			'hierarchical'       => false,
			// Wave 6 (#77): a checkbox list with TomaTío wording instead of
			// the native tag box ("Separate tags with commas").
			'meta_box_cb'        => [\AgentBrain\Modules\Admin\GrantsScreen::class, 'renderMetaBox'],
			'labels'             => [
				'name'          => 'Grants',
				'singular_name' => 'Grant',
				'menu_name'     => 'Grants',
				'all_items'     => 'All Grants',
				'search_items'  => 'Search Grants',
				'not_found'     => 'No grants yet. The Public grant term is created on activation; assign it to an item to make it publishable.',
				'no_terms'      => 'No grants',
				'items_list'    => 'Grants list',
				'back_to_items' => '&larr; Back to Grants',
				'add_new_item'  => 'Add Grant',
				'new_item_name' => 'New grant name',
				'edit_item'     => 'Edit Grant',
				'update_item'   => 'Update Grant',
				'view_item'     => 'View Grant',
				'name_field_description'   => '',
				'slug_field_description'   => '',
				'parent_field_description' => '',
				'desc_field_description'   => '',
			],
			// Belt-and-braces for UI/REST paths that do honour taxonomy
			// capabilities; GrantTaxonomy::assignPublicGrant() is the only
			// path Review itself uses and re-checks the same capability.
			'capabilities'       => [
				'manage_terms' => Capabilities::CAP_PUBLISH,
				'edit_terms'   => Capabilities::CAP_PUBLISH,
				'delete_terms' => Capabilities::CAP_PUBLISH,
				'assign_terms' => Capabilities::CAP_PUBLISH,
			],
		]);
	}

	/**
	 * Idempotently create the `public-grant` term. Safe to call repeatedly
	 * (e.g. on every activation).
	 */
	public static function ensureTerm(): void {
		if (! function_exists('term_exists')) {
			return;
		}

		if (term_exists(self::TERM_SLUG, self::TAXONOMY)) {
			return;
		}

		wp_insert_term('Public grant', self::TAXONOMY, ['slug' => self::TERM_SLUG]);
	}

	public static function termId(): ?int {
		if (! function_exists('get_term_by')) {
			return null;
		}

		$term = get_term_by('slug', self::TERM_SLUG, self::TAXONOMY);

		return $term instanceof \WP_Term ? (int) $term->term_id : null;
	}

	/**
	 * The only sanctioned way to assign the public-grant term to a post.
	 * Requires the acting user to hold `ab_publish`; agents never do.
	 * Fails closed (false) on any invalid input or missing capability.
	 */
	public static function assignPublicGrant(int $actingUserId, int $postId): bool {
		if (! function_exists('user_can')) {
			return false;
		}

		try {
			if (! user_can($actingUserId, Capabilities::CAP_PUBLISH)) {
				return false;
			}
		} catch (\Throwable $e) {
			return false;
		}

		self::ensureTerm();
		$termId = self::termId();
		if ($termId === null) {
			return false;
		}

		$result = wp_set_object_terms($postId, [$termId], self::TAXONOMY, false);

		return ! is_wp_error($result);
	}

	public static function hasPublicGrant(int $postId): bool {
		if (! function_exists('has_term')) {
			return false;
		}

		return (bool) has_term(self::TERM_SLUG, self::TAXONOMY, $postId);
	}
}
