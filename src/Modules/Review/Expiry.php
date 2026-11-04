<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

/**
 * #658: the review queue does not wait forever. A daily job closes every
 * proposal (status ab_proposed) and every open handoff nobody has touched
 * for N days: a proposal as not made official (ReviewActions::expire(),
 * rejected with an expiry note), a handoff as dismissed with a note
 * (HandoffQueue::expire()). Untouched means not modified (post_modified_gmt)
 * since the cutoff, so editing a proposal in the editor restarts its clock.
 * The memories behind them are never deleted.
 *
 * N is the `ab_review_expiry_days` option (Settings > Review, default 14),
 * with the `agent_brain/review_expiry_days` filter as the final say; 0 turns
 * expiry off.
 */
final class Expiry {

	public const HOOK         = 'agent_brain_review_expiry';
	public const OPTION_DAYS  = 'ab_review_expiry_days';
	public const DEFAULT_DAYS = 14;
	public const MAX_DAYS     = 365;

	public static function register(): void {
		add_action(self::HOOK, static function (): void {
			self::run();
		});
	}

	public static function schedule(): void {
		if (! wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook(self::HOOK);
	}

	/** Days an untouched item stays in the queue; 0 means it never expires. */
	public static function days(): int {
		$stored = (int) get_option(self::OPTION_DAYS, self::DEFAULT_DAYS);
		$days   = (int) apply_filters('agent_brain/review_expiry_days', $stored);

		return max(0, min(self::MAX_DAYS, $days));
	}

	/**
	 * When an item last modified at $modifiedGmt (Y-m-d H:i:s, UTC) expires,
	 * as Y-m-d H:i:s UTC, or null when expiry is off.
	 */
	public static function expiresAt(string $modifiedGmt): ?string {
		$days = self::days();
		$time = strtotime($modifiedGmt . ' UTC');
		if ($days === 0 || $time === false) {
			return null;
		}

		return gmdate('Y-m-d H:i:s', $time + $days * DAY_IN_SECONDS);
	}

	/**
	 * One sweep. $now (UTC, Y-m-d H:i:s) is for tests.
	 *
	 * @return array{proposals:int, handoffs:int}
	 */
	public static function run(?string $now = null): array {
		$counts = ['proposals' => 0, 'handoffs' => 0];
		$days   = self::days();
		if ($days === 0) {
			return $counts;
		}

		$now    = $now ?? gmdate('Y-m-d H:i:s');
		$cutoff = gmdate('Y-m-d H:i:s', (int) strtotime($now . ' UTC') - $days * DAY_IN_SECONDS);
		$before = [['column' => 'post_modified_gmt', 'before' => $cutoff, 'inclusive' => true]];

		$proposals = get_posts([
			'post_type'        => ['ab_knowledge', 'ab_procedure'],
			'post_status'      => PostStatuses::PROPOSED,
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'date_query'       => $before,
		]);

		$actions = new ReviewActions();
		foreach ($proposals as $postId) {
			if ($actions->expire((int) $postId, $days) === true) {
				++$counts['proposals'];
			}
		}

		$handoffs = get_posts([
			'post_type'        => HandoffCpt::POST_TYPE,
			'post_status'      => 'private',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'date_query'       => $before,
			'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one daily sweep over open handoffs.
				[
					'key'     => HandoffQueue::META_STATUS,
					'compare' => 'NOT EXISTS',
				],
			],
		]);

		foreach ($handoffs as $handoffId) {
			if (HandoffQueue::expire((int) $handoffId, $days) === true) {
				++$counts['handoffs'];
			}
		}

		return $counts;
	}
}
