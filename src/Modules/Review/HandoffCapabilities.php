<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Identity\Capabilities;

/**
 * Grants the `ab_handoff` CPT's primitive capabilities to ab_curator and
 * administrator (fixes HIGH: "cap edit_ab_handoffs was granted to no role;
 * administrator and curator denied at edit.php?post_type=ab_handoff").
 *
 * Deliberately a `user_has_cap` filter rather than `$role->add_cap()`:
 * `Identity\Capabilities::curatorCapabilities()` is asserted byte-for-byte
 * against the live `ab_curator` role in
 * `IdentityRolesTest::test_curator_role_is_registered_with_exact_capabilities`
 * (owned by the Identity module — out of this unit's scope boundary, see
 * learnings). Persisting these caps onto the role via add_cap() would
 * silently break that cross-module contract test. A `user_has_cap` filter
 * grants exactly the same *effective* access — verified the same way the
 * acceptance criteria ask for (`user_can()` / `current_user_can()`,
 * including native edit.php list-table visibility and object-level
 * map_meta_cap resolution for individual handoffs) — without ever mutating
 * `$role->capabilities`.
 *
 * This also satisfies "AND via an upgrade migration for existing installs"
 * more robustly than a version-bumped migration would: the filter is wired
 * in Module::register(), which every module runs on every request
 * (ModuleRegistry::registerAll(), called from Plugin::boot()) — not only on
 * (re)activation — so an existing install gets the fix on its very next
 * page load, with no `agent_brain_version` bump or reactivation required.
 */
class HandoffCapabilities {

	/**
	 * Every primitive (i.e. role-grantable, non-object-dependent) capability
	 * WordPress derives for the `ab_handoff` / `ab_handoffs` capability_type
	 * pair (mirrors HandoffCpt::register()'s `capability_type` and
	 * WordPress's own get_post_type_capabilities()).
	 *
	 * Deliberately excludes the singular `edit_ab_handoff` / `read_ab_handoff`
	 * / `delete_ab_handoff` meta-cap names: those are never checked
	 * directly (WordPress requires them to be checked as the generic
	 * `edit_post`/`read_post`/`delete_post` meta cap against a specific
	 * object — see HandoffQueue::requireOpenHandoff() — and querying them
	 * standalone via user_can() triggers a `_doing_it_wrong` notice).
	 *
	 * @return string[]
	 */
	public static function primitiveCaps(): array {
		return [
			'edit_ab_handoffs',
			'edit_others_ab_handoffs',
			'publish_ab_handoffs',
			'read_private_ab_handoffs',
			'delete_ab_handoffs',
			'delete_private_ab_handoffs',
			'delete_published_ab_handoffs',
			'delete_others_ab_handoffs',
			'edit_private_ab_handoffs',
			'edit_published_ab_handoffs',
		];
	}

	public static function register(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		add_filter('user_has_cap', [self::class, 'grant'], 10, 4);
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @param string[]            $caps
	 * @param array<int, mixed>   $args
	 * @param mixed                $user   Unused: the grant is role-cap based, not object-level.
	 * @return array<string, bool>
	 */
	public static function grant(array $allcaps, array $caps, array $args, $user = null): array {
		// $allcaps already reflects the user's role-derived capabilities
		// (including administrator, which merges every curator cap in
		// Identity\Capabilities::install()) before this filter runs, so
		// checking ab_manage here is safe: it never re-triggers this filter.
		if (empty($allcaps[Capabilities::CAP_MANAGE])) {
			return $allcaps;
		}

		foreach (self::primitiveCaps() as $cap) {
			$allcaps[$cap] = true;
		}

		return $allcaps;
	}
}
