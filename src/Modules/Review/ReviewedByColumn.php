<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

/**
 * "Reviewed by" column on the Knowledge and Procedures list screens, so in a
 * brain with several curators everyone can see who approved (or edited) each
 * item and when. Reads the attribution ReviewActions records.
 */
final class ReviewedByColumn {

	public const COLUMN = 'ab_reviewed_by';

	public static function register(): void {
		foreach (['ab_knowledge', 'ab_procedure'] as $postType) {
			add_filter("manage_{$postType}_posts_columns", [self::class, 'addColumn']);
			add_action("manage_{$postType}_posts_custom_column", [self::class, 'renderColumn'], 10, 2);
		}
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function addColumn(array $columns): array {
		$columns[self::COLUMN] = __('Reviewed by', 'agent-brain');

		return $columns;
	}

	public static function renderColumn(string $column, int $postId): void {
		if ($column !== self::COLUMN) {
			return;
		}

		echo esc_html(self::label($postId));
	}

	/**
	 * "Name, approved, 2026-10-19 08:30:00 UTC", or "Not reviewed" when nobody
	 * has (created directly, or reviewed before attribution existed).
	 */
	public static function label(int $postId): string {
		$reviewerId = (int) get_post_meta($postId, ReviewActions::META_REVIEWED_BY, true);
		$at         = (string) get_post_meta($postId, ReviewActions::META_REVIEWED_AT, true);

		// #658: closed by the expiry job, with nobody behind it.
		if ($reviewerId <= 0 && (int) get_post_meta($postId, ReviewActions::META_REVIEW_EXPIRED, true) === 1) {
			return trim(__('Expired, not made official', 'agent-brain') . ($at !== '' ? ', ' . $at . ' UTC' : ''), ', ');
		}

		if ($reviewerId <= 0) {
			return __('Not reviewed', 'agent-brain');
		}

		$user    = get_userdata($reviewerId);
		$name    = $user instanceof \WP_User ? $user->display_name : sprintf(
			/* translators: %d: user ID of a reviewer who no longer exists. */
			__('User #%d', 'agent-brain'),
			$reviewerId
		);
		$outcome = (string) get_post_meta($postId, ReviewActions::META_REVIEW_OUTCOME, true);
		if ((int) get_post_meta($postId, ReviewActions::META_REVIEWED_AUTO, true) === 1) {
			$outcome = __('approved automatically', 'agent-brain');
		}

		return trim($name . ', ' . $outcome . ($at !== '' ? ', ' . $at . ' UTC' : ''), ', ');
	}
}
