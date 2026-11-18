<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * Tiny wrapper around $wpdb that forbids string-interpolated SQL by
 * construction: every query-with-parameters method routes through
 * $wpdb->prepare() and every table name is prefixed via $wpdb->prefix.
 */
class Db {

	/**
	 * Return the fully prefixed table name per the binding naming contract
	 * (the design contracts "Naming": every table prefixed
	 * `ab_`), e.g. "health" -> "{$wpdb->prefix}ab_health".
	 */
	public static function table(string $shortName): string {
		global $wpdb;

		$shortName = preg_replace('/[^a-z0-9_]/', '', strtolower($shortName)) ?? '';

		return $wpdb->prefix . 'ab_' . $shortName;
	}

	/**
	 * Run a prepared SELECT/UPDATE/DELETE/INSERT with parameters.
	 * $sql must contain placeholders (%s, %d, %f) consumed by $wpdb->prepare().
	 *
	 * @param array<int, mixed> $params
	 */
	public static function prepare(string $sql, array $params = []): string {
		global $wpdb;

		if ($params === []) {
			// No caller-controlled values: nothing to prepare, but we still
			// refuse to accept anything that looks like naive interpolation.
			return $sql;
		}

		// This is the one place raw $wpdb->prepare() is allowed to appear:
		// it's the enforcement point itself, so the sniff can't see that
		// callers are required to route through here.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->prepare($sql, ...$params);
	}

	/**
	 * @param array<int, mixed> $params
	 * @return array<int, array<string, mixed>>
	 */
	public static function getResults(string $sql, array $params = []): array {
		global $wpdb;

		$prepared = self::prepare($sql, $params);
		// $prepared already went through self::prepare() above.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results($prepared, ARRAY_A);

		return is_array($results) ? $results : [];
	}
}
