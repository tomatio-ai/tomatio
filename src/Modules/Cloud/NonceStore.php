<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Cloud;

use AgentBrain\Core\Db;

/**
 * Replay-protection store for the cloud signing protocol. Keys are scoped
 * "<site_id>:<nonce>" (matching the cloud worker's InMemoryNonceStore key
 * shape) so the same nonce value from two different sites never collides.
 *
 * Backed by a small dedicated table (not a transient) so replay protection
 * survives object-cache eviction, which a transient would not guarantee.
 */
final class NonceStore {

	public const REPLAY_WINDOW_SECONDS = 600;

	public static function tableName(): string {
		return Db::table('cloud_nonces');
	}

	/**
	 * Attempts to claim the nonce. Returns true if it was unseen within the
	 * replay window (and is now recorded), false if it was already used —
	 * a bad signature must never reach this call (see Envelope::verify),
	 * so a claim is only ever spent on an otherwise-valid request.
	 */
	public static function claim(string $siteId, string $nonce, int $ttlSeconds = self::REPLAY_WINDOW_SECONDS): bool {
		global $wpdb;

		self::maybeCleanup();

		$key       = $siteId . ':' . $nonce;
		$table     = self::tableName();
		$expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

		// A duplicate-key error on nonce_key is an expected outcome here (it
		// IS the replay signal, not a bug) so DB error output is suppressed
		// only around this single call; the WP_Error the caller sees still
		// surfaces the rejection (fail loud without noisy false-alarm logs).
		$previousSuppress = $wpdb->suppress_errors(true);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $wpdb->insert() prepares internally.
		$inserted = $wpdb->insert(
			$table,
			[
				'nonce_key'  => $key,
				'expires_at' => $expiresAt,
			],
			['%s', '%s']
		);

		$wpdb->suppress_errors($previousSuppress);

		// A duplicate-key error (unique index on nonce_key) means the nonce
		// was already claimed inside the replay window: reject as a replay.
		return $inserted !== false;
	}

	private static function maybeCleanup(): void {
		global $wpdb;

		// Opportunistic, cheap cleanup: roughly 1 in 20 calls sweeps expired
		// rows so the table doesn't grow unbounded without a dedicated cron.
		if (random_int(1, 20) !== 1) {
			return;
		}

		$table   = self::tableName();
		$deleteSql = Db::prepare("DELETE FROM {$table} WHERE expires_at < %s", [gmdate('Y-m-d H:i:s')]);

		// $deleteSql was built via Db::prepare() above (no caller-controlled values).
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query($deleteSql);
	}
}
