<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * OPS-1: per-site concurrent write cap using a fixed pool of MySQL named
 * locks (`GET_LOCK`), one slot per allowed concurrent writer.
 *
 * Design notes (binding, the design contracts Wave 3):
 * - Pool size is `ab_max_concurrent_writes` (default 4).
 * - Slot names are `{DB_NAME}_{prefix}ab_w_{n}` for n in [0, N) — the DB
 *   name is folded into the lock name itself (not just the connection)
 *   because MySQL named locks are server-scoped, not connection- or
 *   database-scoped: two separate installs (or a multisite network)
 *   sharing one MySQL server would otherwise collide on the same slot
 *   names even though they are logically independent sites.
 * - acquire() tries every slot once with GET_LOCK(name, 0) (non-blocking),
 *   in order; if none are free it backs off and retries, spinning until a
 *   total of 2s has elapsed, then fails closed with WP_Error `ab_busy`
 *   (status 429, retry_after seconds).
 * - release() releases only the slot this instance most recently held
 *   (RELEASE_LOCK is per-name; calling it twice with no held slot is a
 *   harmless no-op both in MySQL and here).
 * - SQLite drop-in (WordPress Playground, #405): GET_LOCK is not
 *   translated, so no slot can ever be acquired. acquire() degrades to
 *   a per-request, fail-open success and release() stays a no-op
 *   (heldSlot is never set) — Playground serializes requests, so the
 *   concurrency this limiter caps cannot occur. See SqliteRuntime.
 */
final class WriteLimiter {

	public const OPTION_MAX_CONCURRENT = 'ab_max_concurrent_writes';
	public const DEFAULT_MAX_CONCURRENT = 4;

	private const TOTAL_TIMEOUT_SECONDS = 2.0;
	private const INITIAL_BACKOFF_SECONDS = 0.02;
	private const MAX_BACKOFF_SECONDS = 0.25;

	private ?string $heldSlot = null;

	/**
	 * Acquire one write slot. Returns true on success, or a WP_Error with
	 * code `ab_busy` (data: status 429, retry_after seconds) if every slot
	 * stayed held for the full 2s budget.
	 *
	 * @return true|\WP_Error
	 */
	public function acquire() {
		// SQLite drop-in (#405): named locks are untranslated and can never
		// be acquired, which would 429 every write. Degrade to a
		// per-request, fail-open success — documented in the class docblock.
		if ( SqliteRuntime::isActive() ) {
			return true;
		}

		$slots = self::slotNames();

		$deadline = microtime(true) + self::TOTAL_TIMEOUT_SECONDS;
		$backoff  = self::INITIAL_BACKOFF_SECONDS;

		do {
			foreach ($slots as $slot) {
				if (self::tryGetLock($slot)) {
					$this->heldSlot = $slot;

					return true;
				}
			}

			$remaining = $deadline - microtime(true);
			if ($remaining <= 0) {
				break;
			}

			$sleep = min($backoff, $remaining, self::MAX_BACKOFF_SECONDS);
			if ($sleep > 0) {
				usleep((int) ($sleep * 1_000_000));
			}

			$backoff = min($backoff * 2, self::MAX_BACKOFF_SECONDS);
		} while (microtime(true) < $deadline);

		$retryAfter = (int) ceil(self::TOTAL_TIMEOUT_SECONDS);

		return new \WP_Error(
			'ab_busy',
			'All write slots are busy; try again shortly.',
			[
				'status'      => 429,
				'retry_after' => $retryAfter,
			]
		);
	}

	/**
	 * Release the slot this instance holds, if any. Safe to call more than
	 * once: the second call is a no-op because $heldSlot is cleared after
	 * the first successful release attempt (MySQL's RELEASE_LOCK is itself
	 * also safe to call on an already-released / never-held name).
	 */
	public function release(): void {
		if ($this->heldSlot === null) {
			return;
		}

		$slot           = $this->heldSlot;
		$this->heldSlot = null;

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- routed through Db::prepare below.
		$wpdb->get_var(Db::prepare('SELECT RELEASE_LOCK(%s)', [$slot]));
	}

	/**
	 * @return array<int, string>
	 */
	public static function slotNames(): array {
		$max = self::maxConcurrent();

		global $wpdb;
		$dbName = defined('DB_NAME') ? (string) DB_NAME : (string) $wpdb->dbname;
		$prefix = (string) $wpdb->prefix;

		$slots = [];
		for ($n = 0; $n < $max; $n++) {
			$slots[] = "{$dbName}_{$prefix}ab_w_{$n}";
		}

		return $slots;
	}

	public static function maxConcurrent(): int {
		$configured = (int) get_option(self::OPTION_MAX_CONCURRENT, self::DEFAULT_MAX_CONCURRENT);

		return $configured > 0 ? $configured : self::DEFAULT_MAX_CONCURRENT;
	}

	private static function tryGetLock(string $slot): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- routed through Db::prepare below.
		$result = $wpdb->get_var(Db::prepare('SELECT GET_LOCK(%s, 0)', [$slot]));

		return (int) $result === 1;
	}
}
