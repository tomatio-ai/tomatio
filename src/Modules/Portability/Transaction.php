<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Runs restore and import writes in one database transaction: a savepoint
 * when one is already open (autocommit off, as in the test suite), else
 * START TRANSACTION. A throw rolls everything back and clears the object
 * cache, which may hold values the rollback undid.
 */
final class Transaction {

	/**
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	public static function run(callable $work) {
		global $wpdb;

		$nested = (string) $wpdb->get_var('SELECT @@autocommit') === '0';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- fixed statements, no values.
		$wpdb->query($nested ? 'SAVEPOINT ab_pcp_import' : 'START TRANSACTION');

		try {
			$result = $work();
		} catch (\Throwable $e) {
			$wpdb->query($nested ? 'ROLLBACK TO SAVEPOINT ab_pcp_import' : 'ROLLBACK');
			wp_cache_flush();

			throw $e;
		}

		$wpdb->query($nested ? 'RELEASE SAVEPOINT ab_pcp_import' : 'COMMIT');
		// phpcs:enable

		return $result;
	}
}
