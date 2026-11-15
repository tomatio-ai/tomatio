<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Core\Hooks;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\OAuth\Delegation;
use WP_User;

/**
 * #958: open review items live in the person's TíoDay, not only on a
 * WordPress page. Nothing closes on its own (Expiry is off by default), so
 * the person is reminded instead: every curator with a TíoDay gets one task,
 * "Review: N waiting", with one line per item and a link to the Review page.
 * The task is updated when the queue changes and closed when nothing waits.
 *
 * It runs shortly after any request that changed the queue (a proposal,
 * a held memory or a handoff appeared or was decided), through a one-off
 * WP-Cron event, and hourly as a backstop. It writes only when the task would change, so a quiet queue
 * costs no store writes. People without a TíoDay are skipped: this never
 * creates one.
 */
final class ReviewReminder {

	/** The task's fixed key in each TíoDay's `tasks` collection. */
	public const TASK_KEY = 'review-waiting';

	public const HOOK = 'agent_brain_review_reminder';

	/** One-off run soon after the queue changed (WP-Cron dedupes it). */
	public const HOOK_SOON = 'agent_brain_review_reminder_soon';

	/** At most this many item lines in the task; the rest are counted. */
	public const MAX_LINES = 10;

	/** Audit events that change what waits for review. */
	private const EVENTS = [
		'provenance.scope_hold',
		'provenance.scope_self_cleared',
		MemoryProposalQueue::EVENT_APPROVED,
		MemoryProposalQueue::EVENT_REJECTED,
	];

	public static function register(): void {
		$run = static function (): void {
			self::syncAll();
		};
		add_action(self::HOOK, $run);
		add_action(self::HOOK_SOON, $run);
		add_action(Hooks::PREFIX . 'audit', static function ($entry): void {
			if (is_array($entry) && in_array((string) ($entry['event'] ?? ''), self::EVENTS, true)) {
				self::queue();
			}
		});
		add_action('transition_post_status', static function ($new, $old, $post): void {
			if (! $post instanceof \WP_Post || $new === $old) {
				return;
			}
			if (in_array($post->post_type, ['ab_knowledge', 'ab_procedure'], true) && ($new === PostStatuses::PROPOSED || $old === PostStatuses::PROPOSED)) {
				self::queue();
			}
			if ($post->post_type === HandoffCpt::POST_TYPE) {
				self::queue();
			}
		}, 20, 3);
		add_action('updated_post_meta', static function ($metaId, $postId, $key): void {
			if ($key === HandoffQueue::META_STATUS) {
				self::queue();
			}
		}, 10, 3);
		add_action('added_post_meta', static function ($metaId, $postId, $key): void {
			if ($key === HandoffQueue::META_STATUS) {
				self::queue();
			}
		}, 10, 3);
	}

	public static function schedule(): void {
		if (! wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK);
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook(self::HOOK);
		wp_clear_scheduled_hook(self::HOOK_SOON);
	}

	/** Syncs every TíoDay once, shortly, outside this request. */
	public static function queue(): void {
		if (function_exists('wp_next_scheduled') && ! wp_next_scheduled(self::HOOK_SOON)) {
			wp_schedule_single_event(time(), self::HOOK_SOON);
		}
	}

	/** @return int how many TíoDays were written */
	public static function syncAll(): int {
		$written = 0;
		foreach (self::people() as $personId) {
			if (self::sync($personId)) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Brings $personId's review task in line with what waits for them.
	 * True when it wrote.
	 */
	public static function sync(int $personId): bool {
		$artifactId = TioDay::forPerson($personId);
		if ($artifactId <= 0) {
			return false;
		}

		$lines    = ReviewChat::lines($personId);
		$existing = StoreData::get($artifactId, 'tasks', self::TASK_KEY);
		$current  = $existing !== null && is_array($existing['value']) ? $existing['value'] : null;
		$open     = $current !== null && ($current['status'] ?? '') !== 'done';

		if ($lines === []) {
			if (! $open) {
				return false;
			}

			return self::write($artifactId, $personId, ['slug' => self::TASK_KEY, 'close' => true, 'done_by' => 'TomaTío']);
		}

		$wanted = self::task($lines);
		$how    = TioDay::text($wanted['how'], TioDay::MAX_TEXT, true);
		if ($open && ($current['title'] ?? '') === TioDay::text($wanted['title'], TioDay::MAX_TITLE) && ($current['how'] ?? '') === $how) {
			return false;
		}

		return self::write($artifactId, $personId, $wanted + ['slug' => self::TASK_KEY, 'status' => 'open', 'group' => 'now']);
	}

	/**
	 * The task for these item lines.
	 *
	 * @param string[] $lines
	 * @return array{title:string, why:string, how:string, links:list<array{label:string, url:string}>}
	 */
	public static function task(array $lines): array {
		$count = count($lines);
		$shown = array_slice($lines, 0, self::MAX_LINES);
		$how   = '- ' . implode("\n- ", $shown);
		if ($count > count($shown)) {
			/* translators: %d: number of further items */
			$how .= "\n" . sprintf(__('and %d more', 'tomatio'), $count - count($shown));
		}
		$how .= "\n\n" . __('Say "review" to your AI to go through them in chat, or open Review.', 'tomatio');

		// TíoDay links are https only; a site on plain http gets no link.
		$url = admin_url('admin.php?page=' . ReviewQueuePage::SLUG);

		return [
			/* translators: %d: number of items waiting for review */
			'title' => sprintf(__('Review: %d waiting', 'tomatio'), $count),
			'why'   => __('Your Tíos suggested these for the team. Nothing is lost if you say no.', 'tomatio'),
			'how'   => $how,
			'links' => str_starts_with(strtolower($url), 'https://') ? [['label' => __('Review', 'tomatio'), 'url' => $url]] : [],
		];
	}

	/**
	 * People (not agents) who may approve knowledge.
	 *
	 * @return int[]
	 */
	private static function people(): array {
		$users = get_users(['capability' => Capabilities::CAP_APPROVE_KNOWLEDGE, 'number' => 500, 'count_total' => false]);
		$out   = [];
		foreach ($users as $user) {
			if ($user instanceof WP_User && ! Delegation::isPureAgent($user)) {
				$out[] = (int) $user->ID;
			}
		}

		return $out;
	}

	/**
	 * Writes the task as the person (so the TíoDay shows it as theirs, not
	 * an anonymous change), restoring the current user after.
	 *
	 * @param array<string, mixed> $input
	 */
	private static function write(int $artifactId, int $personId, array $input): bool {
		$before = get_current_user_id();
		wp_set_current_user($personId);
		try {
			$result = TioDay::task($artifactId, $personId, $input);
		} finally {
			wp_set_current_user($before);
		}

		return ! $result instanceof \WP_Error;
	}
}
