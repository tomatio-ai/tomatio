<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

/**
 * Admin IA wave, Unit P (polish, item 4): adds a "Stage" and a "Sections"
 * (count) column to the Artifacts list table in wp-admin. Both are
 * sortable-free per the wave contract -- Stage is a free-text meta value,
 * not something curators expect to sort a list table by, and Sections is a
 * computed aggregate, not a stored/indexed column WordPress could sort on
 * without an expensive per-row subquery.
 *
 * Hooked from Cpts::register() (see that method's docblock) rather than
 * Artifacts\Module.php, which is out of this unit's target files.
 */
final class ListColumns {

	public const COLUMN_STAGE    = 'ab_stage';
	public const COLUMN_SECTIONS = 'ab_sections';

	public static function register(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		add_filter('manage_' . Cpts::ARTIFACT_POST_TYPE . '_posts_columns', [self::class, 'addColumns']);
		add_action('manage_' . Cpts::ARTIFACT_POST_TYPE . '_posts_custom_column', [self::class, 'renderColumn'], 10, 2);
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function addColumns(array $columns): array {
		$columns[self::COLUMN_STAGE]    = __('Stage', 'agent-brain');
		$columns[self::COLUMN_SECTIONS] = __('Sections', 'agent-brain');

		return $columns;
	}

	public static function renderColumn(string $column, int $postId): void {
		if ($column === self::COLUMN_STAGE) {
			echo esc_html(self::stageLabel($postId));

			return;
		}

		if ($column === self::COLUMN_SECTIONS) {
			echo esc_html((string) self::sectionCount($postId));
		}
	}

	/**
	 * Same "unknown/missing value falls back to the outline stage" rule
	 * Stages::currentStage() uses, so a freshly created artifact (no
	 * ab_stage meta written yet) shows "Outline", not a blank cell.
	 */
	private static function stageLabel(int $postId): string {
		$raw   = (string) get_post_meta($postId, Cpts::META_STAGE, true);
		$stage = in_array($raw, Cpts::stages(), true) ? $raw : Cpts::STAGE_OUTLINE;

		return ucwords(str_replace('_', ' ', $stage));
	}

	/**
	 * Count of ab_artifact_section children (post_parent = $postId), any
	 * status -- a curator on the list screen wants to know how many
	 * sections an artifact has regardless of whether each one is still
	 * drafting or already approved.
	 */
	private static function sectionCount(int $postId): int {
		if (! function_exists('get_posts')) {
			return 0;
		}

		$ids = get_posts([
			'post_type'      => Cpts::SECTION_POST_TYPE,
			'post_parent'    => $postId,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		return count($ids);
	}
}
