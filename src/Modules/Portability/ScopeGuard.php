<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\Cpts;

/**
 * One scope per knowledge or procedure post, so every exported page sits in
 * exactly one partition (PCP v0.3 SPEC section 5.3).
 *
 * WordPress has no filter that can refuse a term assignment, so the guard
 * runs on set_object_terms, which every write path reaches (proposals,
 * assembled artifacts, wp_insert_post and wp_update_post with tax_input from
 * the editor or REST, wp_set_object_terms, wp_set_post_terms and
 * wp_add_object_terms): when a change would leave a post with more than one
 * scope and adds one it did not have, the post's previous scopes are put
 * back and the refusal is audited.
 *
 * Posts that already have several scopes keep them: saving the same set, or
 * narrowing it, is allowed. Only adding is refused.
 */
final class ScopeGuard {

	public const AUDIT_EVENT = 'scope.second_refused';

	private static bool $reverting = false;

	/**
	 * Scope relationships added during the current wp_set_object_terms()
	 * call, per object. With $append, WordPress passes no previous terms,
	 * so the guard works out the previous set from what was added.
	 *
	 * @var array<int, array<int, int>>
	 */
	private static array $added = [];

	/**
	 * Pure: may a post go from scopes $before to scopes $after (term taxonomy
	 * ids or term ids, the same kind on both sides)?
	 *
	 * @param array<int, int> $before
	 * @param array<int, int> $after
	 */
	public static function allows(array $before, array $after): bool {
		$after = array_values(array_unique(array_map('intval', $after)));
		if (count($after) <= 1) {
			return true;
		}

		return array_diff($after, array_map('intval', $before)) === [];
	}

	/** added_term_relationship. */
	public static function onAddedRelationship(int $objectId, int $ttId, string $taxonomy): void {
		if ($taxonomy === Scopes::TAXONOMY) {
			self::$added[$objectId][] = $ttId;
		}
	}

	/**
	 * set_object_terms.
	 *
	 * @param mixed $terms
	 * @param mixed $ttIds
	 * @param mixed $append
	 * @param mixed $oldTtIds
	 */
	public static function onSetObjectTerms(int $objectId, $terms, $ttIds, string $taxonomy, $append, $oldTtIds): void {
		unset($terms, $ttIds);

		if ($taxonomy !== Scopes::TAXONOMY) {
			return;
		}

		$added = self::$added[$objectId] ?? [];
		unset(self::$added[$objectId]);

		if (self::$reverting || ! in_array(get_post_type($objectId), Cpts::postTypes(), true)) {
			return;
		}

		$now = wp_get_object_terms($objectId, Scopes::TAXONOMY, ['fields' => 'tt_ids']);
		if (! is_array($now)) {
			return;
		}

		$now    = array_map('intval', $now);
		$before = $append
			? array_values(array_diff($now, $added))
			: array_map('intval', is_array($oldTtIds) ? $oldTtIds : []);
		if (self::allows($before, $now)) {
			return;
		}

		self::$reverting = true;
		try {
			wp_set_object_terms($objectId, self::termIds($before), Scopes::TAXONOMY, false);
		} finally {
			self::$reverting = false;
		}

		do_action('agent_brain/audit', [
			'actor_id'    => get_current_user_id(),
			'event'       => self::AUDIT_EVENT,
			'status'      => 'denied',
			'object_type' => (string) get_post_type($objectId),
			'object_id'   => (string) $objectId,
			'meta'        => ['scopes_before' => count($before), 'scopes_requested' => count($now)],
		]);
	}

	/**
	 * Knowledge and procedure posts with more than one scope, by post id
	 * (written before the rule existed).
	 *
	 * @return array<int, int>
	 */
	public static function multiScopePostIds(): array {
		global $wpdb;

		$types = Cpts::postTypes();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				WHERE tt.taxonomy = %s AND p.post_type IN (%s, %s)
				GROUP BY tr.object_id
				HAVING COUNT(*) > 1
				ORDER BY tr.object_id ASC",
			[Scopes::TAXONOMY, $types[0], $types[1]]
		);
		// phpcs:enable

		return array_map(static fn (array $row): int => (int) $row['object_id'], $rows);
	}

	/**
	 * @param array<int, int> $ttIds
	 * @return array<int, int>
	 */
	private static function termIds(array $ttIds): array {
		if ($ttIds === []) {
			return [];
		}

		$ids = get_terms([
			'taxonomy'         => Scopes::TAXONOMY,
			'term_taxonomy_id' => $ttIds,
			'hide_empty'       => false,
			'fields'           => 'ids',
		]);

		return is_array($ids) ? array_map('intval', $ids) : [];
	}
}
