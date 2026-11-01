<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * Specific list-screen wording for the TomaTío post types (issue #77):
 * WordPress falls back to "Posts" / "No posts found." for every label key a
 * post type leaves unset, so each CPT merges these in.
 */
final class PostTypeLabels {

	/**
	 * @param string $plural      Display plural, e.g. "Handoffs" or "Knowledge".
	 * @param string $pluralLower Lowercase plural used mid-sentence, e.g. "handoffs".
	 * @param string $emptyHint   One sentence appended to the not-found text, or ''.
	 * @return array<string, string>
	 */
	public static function extras(string $plural, string $pluralLower, string $emptyHint = ''): array {
		/* translators: %s: lowercase plural item name, e.g. "handoffs". */
		$notFound = sprintf(__('No %s yet.', 'agent-brain'), $pluralLower);

		return [
			/* translators: %s: plural item name, e.g. "Handoffs". */
			'search_items'          => sprintf(__('Search %s', 'agent-brain'), $plural),
			'not_found'             => trim($notFound . ' ' . $emptyHint),
			/* translators: %s: lowercase plural item name. */
			'not_found_in_trash'    => sprintf(__('No %s in the Trash.', 'agent-brain'), $pluralLower),
			/* translators: %s: plural item name. */
			'items_list'            => sprintf(__('%s list', 'agent-brain'), $plural),
			/* translators: %s: plural item name. */
			'items_list_navigation' => sprintf(__('%s list navigation', 'agent-brain'), $plural),
			/* translators: %s: lowercase plural item name. */
			'filter_items_list'     => sprintf(__('Filter %s list', 'agent-brain'), $pluralLower),
			/* translators: %s: plural item name. */
			'view_items'            => sprintf(__('View %s', 'agent-brain'), $plural),
		];
	}
}
