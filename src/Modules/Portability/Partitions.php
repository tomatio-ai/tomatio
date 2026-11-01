<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Identity\Scopes;

/**
 * Partition names (PCP v0.3 SPEC section 5.3) for ab_scope terms, in term
 * meta ab_partition_name. Set when the term is created, backfilled from the
 * slug for older terms, and never derived again: renaming a scope or
 * changing its slug keeps the name, so an export, restore, export round trip
 * names partitions the same way.
 *
 * "default" is the synthetic partition every PCP context has. A new scope
 * never gets it; only the backfill gives it to an existing scope whose slug
 * already says default.
 *
 * Reads go through SQL rather than get_terms(), because the backfill runs at
 * plugins_loaded, before the taxonomy is registered on init.
 */
final class Partitions {

	public const META         = 'ab_partition_name';
	public const DEFAULT_NAME = 'default';

	private const MAX_ATTEMPTS = 1000;

	private static bool $writing = false;

	/** The stored name, or null when the term has none (or is not a scope). */
	public static function nameFor(int $termId): ?string {
		$name = get_term_meta($termId, self::META, true);

		return is_string($name) && Names::isPartition($name) ? $name : null;
	}

	/**
	 * Names a scope term once. Returns the stored name, or null when the
	 * term is not an ab_scope term.
	 */
	public static function assign(int $termId, bool $allowDefault = false): ?string {
		$existing = self::nameFor($termId);
		if ($existing !== null) {
			return $existing;
		}

		$slug = self::slugOf($termId);
		if ($slug === null) {
			return null;
		}

		$base  = Names::partitionBase($slug);
		$taken = array_flip(self::takenNames());
		if (! $allowDefault) {
			$taken[self::DEFAULT_NAME] = true;
		}

		for ($n = 1; $n <= self::MAX_ATTEMPTS; $n++) {
			$candidate = Names::partitionCandidate($base, $n);
			if (isset($taken[$candidate])) {
				continue;
			}

			self::$writing = true;
			try {
				update_term_meta($termId, self::META, $candidate);
			} finally {
				self::$writing = false;
			}

			return $candidate;
		}

		return null;
	}

	/**
	 * Every ab_scope term with its stored name (null when missing), in term
	 * id order.
	 *
	 * @return array<int, array{term_id:int, name:?string}>
	 */
	public static function all(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT tt.term_id, tm.meta_value AS name
				FROM {$wpdb->term_taxonomy} tt
				LEFT JOIN {$wpdb->termmeta} tm ON tm.term_id = tt.term_id AND tm.meta_key = %s
				WHERE tt.taxonomy = %s
				ORDER BY tt.term_id ASC",
			[self::META, Scopes::TAXONOMY]
		);
		// phpcs:enable

		return array_map(static fn (array $row): array => [
			'term_id' => (int) $row['term_id'],
			'name'    => $row['name'] !== null ? (string) $row['name'] : null,
		], $rows);
	}

	/**
	 * Backfill, in term id order, so the first scope keeps the plain name
	 * when two slugs reduce to the same one.
	 *
	 * @return int Number of names set.
	 */
	public static function backfill(): int {
		$set = 0;
		foreach (self::all() as $row) {
			if ($row['name'] === null && self::assign($row['term_id'], true) !== null) {
				++$set;
			}
		}

		return $set;
	}

	/** created_ab_scope. */
	public static function onCreated(int $termId): void {
		self::assign($termId);
	}

	/**
	 * add_term_metadata / update_term_metadata / delete_term_metadata: the
	 * name is written only by this class.
	 *
	 * @param mixed $check
	 * @param mixed $objectId
	 * @param mixed $metaKey
	 * @return mixed
	 */
	public static function guardMeta($check, $objectId, $metaKey) {
		unset($objectId);

		return $metaKey === self::META && ! self::$writing ? false : $check;
	}

	/**
	 * @return array<int, string>
	 */
	private static function takenNames(): array {
		$names = [];
		foreach (self::all() as $row) {
			if ($row['name'] !== null) {
				$names[] = $row['name'];
			}
		}

		return $names;
	}

	private static function slugOf(int $termId): ?string {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT t.slug FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				WHERE t.term_id = %d AND tt.taxonomy = %s
				LIMIT 1",
			[$termId, Scopes::TAXONOMY]
		);
		// phpcs:enable

		return isset($rows[0]) ? (string) $rows[0]['slug'] : null;
	}
}
