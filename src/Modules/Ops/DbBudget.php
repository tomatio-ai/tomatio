<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Ops;

use AgentBrain\Core\Db;

/**
 * OPS-3: size of every `{prefix}ab_*` table read from information_schema
 * (not SHOW TABLE STATUS estimates from data dictionaries we don't control),
 * compared against the `ab_db_budget_mb` option. Also drives the >=60%
 * admin notice.
 */
final class DbBudget {

	public const OPTION_BUDGET_MB = 'ab_db_budget_mb';
	public const DEFAULT_BUDGET_MB = 500;
	public const WARN_THRESHOLD_RATIO = 0.6;
	public const DECAY_TARGET_RATIO = 0.5;

	public static function budgetMb(): int {
		$configured = (int) get_option(self::OPTION_BUDGET_MB, self::DEFAULT_BUDGET_MB);

		return $configured > 0 ? $configured : self::DEFAULT_BUDGET_MB;
	}

	/**
	 * Total bytes used by every table whose name starts with "{prefix}ab_",
	 * per information_schema (accurate after ANALYZE TABLE / InnoDB stats
	 * refresh; this is the same source MySQL itself uses for planning, and
	 * is the documented way to answer "how big is my schema" without
	 * shelling out).
	 *
	 * Filterable via `agent_brain/db_used_bytes` — real InnoDB byte
	 * accounting is coarse (page-granular, not updated instantly after a
	 * DELETE, and some managed-DB hosts restrict information_schema access
	 * entirely), so tests and hosts that need a deterministic or
	 * alternative source can override the raw query result here rather than
	 * faking information_schema itself.
	 */
	public static function usedBytes(): int {
		global $wpdb;

		$dbName = defined('DB_NAME') ? (string) DB_NAME : (string) $wpdb->dbname;
		$like   = $wpdb->esc_like($wpdb->prefix . 'ab_') . '%';

		$rows = Db::getResults(
			'SELECT SUM(data_length + index_length) AS total_bytes
				FROM information_schema.tables
				WHERE table_schema = %s AND table_name LIKE %s',
			[$dbName, $like]
		);

		$total = $rows[0]['total_bytes'] ?? null;
		$bytes = $total !== null ? (int) $total : 0;

		if (function_exists('apply_filters')) {
			$bytes = (int) apply_filters('agent_brain/db_used_bytes', $bytes);
		}

		return $bytes;
	}

	public static function usedMb(): float {
		return self::usedBytes() / (1024 * 1024);
	}

	public static function percentUsed(): float {
		$budget = self::budgetMb();
		if ($budget <= 0) {
			return 0.0;
		}

		return (self::usedMb() / $budget) * 100.0;
	}

	public static function isAtWarnThreshold(): bool {
		return self::percentUsed() >= (self::WARN_THRESHOLD_RATIO * 100.0);
	}

	public static function isUnderDecayTarget(): bool {
		return self::percentUsed() < (self::DECAY_TARGET_RATIO * 100.0);
	}

	public static function registerAdminNotice(): void {
		add_action('admin_notices', [self::class, 'renderAdminNotice']);
	}

	public static function renderAdminNotice(): void {
		if (! current_user_can('ab_manage')) {
			return;
		}

		if (! self::isAtWarnThreshold()) {
			return;
		}

		printf(
			'<div class="notice notice-warning" role="status"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: used MB, 2: budget MB, 3: percent used */
					__('TomaTío: memory storage is using %1$.1f MB of the %2$d MB budget (%3$.0f%%). Daily decay will prune low-importance rows once usage reaches 60%%; approved knowledge is never pruned.', 'agent-brain'),
					self::usedMb(),
					self::budgetMb(),
					self::percentUsed()
				)
			)
		);
	}
}
