<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Comments;

use AgentBrain\Modules\Artifacts\Cpts;

/**
 * The Comments list screen's extra columns (mirrors Artifacts\ListColumns):
 * Artifact (linking to the artifact's edit screen), Version, Anchor
 * (section/element) and Status (open/resolved + resolver). Sortable-free:
 * every value is a meta lookup or a computed aggregate, not an indexed
 * column.
 */
final class ListColumns {

	public const COLUMN_ARTIFACT = 'ab_comment_artifact';
	public const COLUMN_VERSION  = 'ab_comment_version';
	public const COLUMN_ANCHOR   = 'ab_comment_anchor';
	public const COLUMN_STATUS   = 'ab_comment_status';

	public static function register(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		add_filter('manage_' . Cpt::POST_TYPE . '_posts_columns', [self::class, 'addColumns']);
		add_action('manage_' . Cpt::POST_TYPE . '_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function addColumns(array $columns): array {
		$columns[self::COLUMN_ARTIFACT] = __('Artifact', 'agent-brain');
		$columns[self::COLUMN_VERSION]  = __('Version', 'agent-brain');
		$columns[self::COLUMN_ANCHOR]   = __('Anchor', 'agent-brain');
		$columns[self::COLUMN_STATUS]   = __('Status', 'agent-brain');

		return $columns;
	}

	public static function renderColumn(string $column, int $postId): void {
		if ($column === self::COLUMN_ARTIFACT) {
			$artifactId = (int) get_post_meta($postId, Cpt::META_ARTIFACT, true);
			$title      = $artifactId > 0 ? get_the_title($artifactId) : '';
			if ($title !== '') {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_edit_post_link() is escaped.
				echo '<a href="' . esc_url((string) get_edit_post_link($artifactId, 'raw')) . '">' . esc_html($title) . '</a>';
			} else {
				echo esc_html((string) $artifactId);
			}

			return;
		}

		if ($column === self::COLUMN_VERSION) {
			echo esc_html((string) max(1, (int) get_post_meta($postId, Cpt::META_VERSION, true)));

			return;
		}

		if ($column === self::COLUMN_ANCHOR) {
			$parts = [];
			$sectionId = (int) get_post_meta($postId, Cpt::META_SECTION, true);
			if ($sectionId > 0) {
				$section = get_post($sectionId);
				$parts[] = $section instanceof \WP_Post && $section->post_type === Cpts::SECTION_POST_TYPE
					? $section->post_title
					: sprintf('section %d', $sectionId);
			}
			$element = trim((string) get_post_meta($postId, Cpt::META_ELEMENT, true));
			if ($element !== '') {
				$parts[] = $element;
			}
			echo esc_html(implode(' › ', $parts));

			return;
		}

		if ($column === self::COLUMN_STATUS) {
			$resolvedBy = (int) get_post_meta($postId, Cpt::META_RESOLVED_BY, true);
			if ((int) get_post_meta($postId, Cpt::META_RESOLVED, true) === 1) {
				$resolver = $resolvedBy > 0 ? get_userdata($resolvedBy) : false;
				/* translators: %s: resolver display name. */
				printf('%s', esc_html(sprintf(__('Resolved by %s', 'agent-brain'), $resolver instanceof \WP_User ? $resolver->display_name : (string) $resolvedBy)));
			} else {
				esc_html_e('Open', 'agent-brain');
			}
		}
	}
}
