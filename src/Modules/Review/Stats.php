<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

/**
 * REV-1 success metric: approval-rate stats, persisted as a small counts
 * option (per contract: "option or small table"). Counts are per outcome
 * ('approved'|'edited'|'rejected'); approval rate treats an edit as a
 * successful (curator-endorsed) outcome, matching the PRD's "approve /
 * edit-and-approve / reject" framing.
 */
class Stats {

	public const OPTION = 'ab_review_stats';

	public const OUTCOMES = ['approved', 'edited', 'rejected'];

	public static function record(string $outcome): void {
		if (! in_array($outcome, self::OUTCOMES, true)) {
			return;
		}

		if (! function_exists('get_option')) {
			return;
		}

		$counts = self::counts();
		$counts[$outcome]++;

		update_option(self::OPTION, $counts, false);
	}

	/** @return array<string, int> */
	public static function counts(): array {
		$defaults = array_fill_keys(self::OUTCOMES, 0);

		if (! function_exists('get_option')) {
			return $defaults;
		}

		$stored = get_option(self::OPTION, []);
		if (! is_array($stored)) {
			return $defaults;
		}

		foreach (self::OUTCOMES as $outcome) {
			$defaults[$outcome] = (int) ($stored[$outcome] ?? 0);
		}

		return $defaults;
	}

	/**
	 * Pure calculation: fraction of outcomes that were approved or edited
	 * (curator-endorsed), of all recorded outcomes. 0.0 when nothing is
	 * recorded yet, never a division-by-zero.
	 *
	 * @param array<string, int> $counts
	 */
	public static function approvalRate(array $counts): float {
		$approved = (int) ($counts['approved'] ?? 0);
		$edited   = (int) ($counts['edited'] ?? 0);
		$rejected = (int) ($counts['rejected'] ?? 0);

		$total = $approved + $edited + $rejected;
		if ($total <= 0) {
			return 0.0;
		}

		return ($approved + $edited) / $total;
	}
}
