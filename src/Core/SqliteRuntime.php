<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * Playground / SQLite-drop-in detection (#402 epic, #405 patch set).
 *
 * WordPress Playground (php-wasm) serves the site through the
 * `sqlite-database-integration` drop-in, which swaps $wpdb for a
 * SQLite-backed class (`WP_SQLite_DB`) and translates MySQL queries to
 * SQLite. The translator does NOT implement everything this plugin uses:
 *
 *  - GET_LOCK()/RELEASE_LOCK() named locks (WriteLimiter, ArtifactService)
 *    return null, so every acquire fails and all MCP writes 429;
 *  - MATCH() ... AGAINST() FULLTEXT search (MemoryRepository,
 *    MemoryScopedSearch, Forget\Matcher) is a syntax error, silently
 *    swallowed to empty result sets;
 *  - UTC_TIMESTAMP() (Audit\Repository retention prune) is untranslated.
 *
 * Callers check isActive() and degrade those paths: locks become a
 * per-request, fail-open single-flight mutex (Playground serializes
 * requests, and the SQLite file itself is a single writer anyway, so no
 * cross-request race exists to guard against — documented fail-open per
 * the #402 audit), and search routes to the LIKE/BM25 fallback.
 *
 * Detection order: $wpdb instanceof \WP_SQLite_DB (the drop-in class; the
 * class may not exist on a pure-MySQL install, which instanceof handles on
 * its own — class_exists() is belt and braces), then a PDO-handle fallback
 * for drop-in rewrites that drive the stock wpdb class over PDO, and
 * finally the `agent_brain/sqlite_runtime` filter as the last word (also
 * the test seam: integration tests toggle it without a SQLite fixture).
 */
final class SqliteRuntime {

	public static function isActive(): bool {
		$detected = self::detect();

		if ( function_exists( 'apply_filters' ) ) {
			return (bool) apply_filters( 'agent_brain/sqlite_runtime', $detected );
		}

		return $detected;
	}

	private static function detect(): bool {
		// The global may be the drop-in's SQLite-backed class rather than
		// core wpdb, so it is deliberately analysed as mixed here.
		/** @var mixed $wpdb */
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return false;
		}

		if ( class_exists( 'WP_SQLite_DB' ) && $wpdb instanceof \WP_SQLite_DB ) {
			return true;
		}

		// PDO-backed rewrites of the drop-in keep the wpdb class but hold a
		// PDO connection instead of mysqli.
		if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof \PDO ) {
			return true;
		}

		return false;
	}
}
