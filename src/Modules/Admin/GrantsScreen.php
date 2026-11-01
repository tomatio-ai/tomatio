<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Review\GrantTaxonomy;

/**
 * The Grants screen (issue #77): the native tag UI minus everything that makes
 * no sense for the single `public-grant` term. Explainer and empty state sit
 * where the "Add" form used to start; slug, description and parent are hidden;
 * the Count column reports real usage across the TomaTío post types.
 */
final class GrantsScreen {

	public const ACTION_RESTORE = 'ab_grant_restore';

	/** Post types a grant can sit on (count and breakdown). */
	private const USAGE_TYPES = [
		'ab_knowledge' => 'knowledge',
		'ab_procedure' => 'procedures',
		'ab_artifact'  => 'artifacts',
		'post'         => 'posts',
	];

	public static function register(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		$tax = GrantTaxonomy::TAXONOMY;
		add_filter('manage_edit-' . $tax . '_columns', [self::class, 'columns']);
		add_filter('manage_' . $tax . '_custom_column', [self::class, 'renderColumn'], 10, 3);
		add_action($tax . '_pre_add_form', [self::class, 'renderIntro']);
		add_action('admin_head', [self::class, 'printCss']);
		add_action('admin_post_' . self::ACTION_RESTORE, [self::class, 'handleRestore']);
	}

	private static function onScreen(): bool {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		return $screen instanceof \WP_Screen && $screen->taxonomy === GrantTaxonomy::TAXONOMY;
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function columns(array $columns): array {
		return [
			'cb'       => $columns['cb'] ?? '<input type="checkbox" />',
			'name'     => __('Grant', 'agent-brain'),
			'ab_usage' => __('Count', 'agent-brain'),
		];
	}

	public static function renderColumn(string $content, string $column, int $termId): string {
		if ($column !== 'ab_usage') {
			return $content;
		}

		$counts = self::usage($termId);
		$total  = array_sum($counts);
		if ($total === 0) {
			return '0';
		}

		$parts = [];
		foreach ($counts as $label => $n) {
			if ($n > 0) {
				$parts[] = $n . ' ' . $label;
			}
		}

		return esc_html((string) $total . ' (' . implode(', ', $parts) . ')');
	}

	/**
	 * Items carrying the grant, any status, per type label, counting only
	 * those the current user can see (#552).
	 *
	 * @return array<string, int>
	 */
	public static function usage(int $termId): array {
		$out = [];
		foreach (self::USAGE_TYPES as $postType => $label) {
			$ids = get_posts([
				'post_type'        => $postType,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one term, admin-only screen.
				'tax_query'        => [['taxonomy' => GrantTaxonomy::TAXONOMY, 'field' => 'term_id', 'terms' => [$termId]]],
			]);
			$out[$label] = count(ScopeVisibility::filterPostIds(get_current_user_id(), $ids));
		}

		return $out;
	}

	public static function introHtml(bool $hasGrant, string $restoreUrl): string {
		$html = '<div class="ab-list-hint"><p>' . esc_html__('A public grant is the permission that lets an approved item be published outside the brain, as a share link or a public post. Only people who can publish (curators and administrators) can assign it; agents never can.', 'agent-brain') . '</p></div>';

		if (! $hasGrant) {
			$html .= EmptyState::html(
				__('No grants yet', 'agent-brain'),
				__('The public grant is created when TomaTío is activated, and nothing can be published outside the brain without it. Restore it to continue.', 'agent-brain'),
				['label' => __('Restore the public grant', 'agent-brain'), 'url' => $restoreUrl]
			);
		}

		return $html;
	}

	/**
	 * The grant box on item edit screens: one checkbox per grant, in Agent
	 * Brain wording. Values are term slugs (the taxonomy is non-hierarchical,
	 * so core would read numeric ids as names); the hidden empty entry makes
	 * unchecking everything clear the grant.
	 *
	 * @param array<string, mixed> $box
	 */
	public static function renderMetaBox(\WP_Post $post, array $box = []): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- metaBoxHtml() escapes every value it emits.
		echo self::metaBoxHtml($post->ID, current_user_can(Capabilities::CAP_PUBLISH));
	}

	public static function metaBoxHtml(int $postId, bool $canAssign): string {
		$terms = get_terms(['taxonomy' => GrantTaxonomy::TAXONOMY, 'hide_empty' => false]);
		$terms = is_array($terms) ? $terms : [];
		$have  = wp_get_object_terms($postId, GrantTaxonomy::TAXONOMY, ['fields' => 'slugs']);
		$have  = is_array($have) ? $have : [];
		$name  = 'tax_input[' . GrantTaxonomy::TAXONOMY . '][]';

		$html = '<div class="ab-grants-box">';
		if ($terms === []) {
			return $html . '<p class="description">' . esc_html__('No grants exist yet. Restore the public grant on the Grants screen.', 'agent-brain') . '</p></div>';
		}

		$html .= '<input type="hidden" name="' . esc_attr($name) . '" value="" />';
		foreach ($terms as $term) {
			if (! $term instanceof \WP_Term) {
				continue;
			}
			$html .= '<p><label><input type="checkbox" name="' . esc_attr($name) . '" value="' . esc_attr($term->slug) . '"'
				. checked(in_array($term->slug, $have, true), true, false) . disabled(! $canAssign, true, false) . ' /> '
				. esc_html($term->name) . '</label></p>';
		}

		$hint = $canAssign
			? __('A public grant lets this item be published outside the brain. Only curators and administrators can give it.', 'agent-brain')
			: __('Only curators and administrators can give or remove a grant.', 'agent-brain');

		return $html . '<p class="description">' . esc_html($hint) . '</p></div>';
	}

	public static function renderIntro(): void {
		$url = wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION_RESTORE), self::ACTION_RESTORE);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- introHtml() escapes every value it emits.
		echo self::introHtml(GrantTaxonomy::termId() !== null, $url);
	}

	/** Hide what a single fixed term has no use for; keep the add form only when the term is gone. */
	public static function printCss(): void {
		if (! self::onScreen()) {
			return;
		}

		$css = '.term-slug-wrap,.term-description-wrap,.term-parent-wrap,#name-description,#slug-description,#description-description{display:none!important}';
		if (GrantTaxonomy::termId() !== null) {
			$css .= '#addtag,.form-wrap h2{display:none}';
		}

		echo '<style>' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal.
	}

	public static function handleRestore(): void {
		if (! current_user_can(Capabilities::CAP_PUBLISH)) {
			wp_die(esc_html__('You do not have permission to restore the public grant.', 'agent-brain'), '', ['response' => 403]);
		}
		check_admin_referer(self::ACTION_RESTORE);

		GrantTaxonomy::ensureTerm();
		wp_safe_redirect(GrantsPage::url());
		exit;
	}
}
