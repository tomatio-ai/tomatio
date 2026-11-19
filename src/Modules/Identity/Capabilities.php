<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

/**
 * Canonical capability sets for the Identity module (PRD ID-1, ID-3, ID-4).
 *
 * Kept as pure static data + application helpers (no WP role objects) so a
 * unit test can assert the exact capability set without booting WordPress.
 */
class Capabilities {

	public const ROLE_AGENT   = 'ab_agent';
	public const ROLE_CURATOR = 'ab_curator';

	public const CAP_USE_MCP         = 'ab_use_mcp';
	public const CAP_APPROVE_KNOWLEDGE = 'ab_approve_knowledge';
	public const CAP_PUBLISH         = 'ab_publish';
	public const CAP_GRANT_SCOPES    = 'ab_grant_scopes';
	public const CAP_VIEW_AUDIT      = 'ab_view_audit';
	public const CAP_MANAGE_ARTIFACTS = 'ab_manage_artifacts';
	public const CAP_MANAGE          = 'ab_manage';

	/**
	 * capability_type pairs [singular, plural] that curators get full
	 * primitive capabilities for. The CPTs themselves are registered by
	 * other modules (out of scope here); we only need the capability
	 * strings, which are fully determined by the capability_type.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function curatorPostTypeCapabilityTypes(): array {
		return [
			['ab_knowledge', 'ab_knowledges'],
			['ab_procedure', 'ab_procedures'],
		];
	}

	/**
	 * #230: true when $userId is an agent acting on its own authority — it
	 * carries the ab_agent role and no person is driving it this request.
	 * A person acting through the agent (a delegated OAuth token, or act-as
	 * role switching) sets Core\AuthorizedBy, and the pair counts as the
	 * person, not a pure agent.
	 */
	public static function isPureAgent( int $userId ): bool {
		if ( $userId <= 0 || ! function_exists( 'get_userdata' ) ) {
			return false;
		}

		$user = get_userdata( $userId );
		if ( ! $user instanceof \WP_User || ! in_array( self::ROLE_AGENT, (array) $user->roles, true ) ) {
			return false;
		}

		return \AgentBrain\Core\AuthorizedBy::id() === 0;
	}

	/**
	 * The exact capability set granted to the ab_agent role.
	 *
	 * @return array<string, bool>
	 */
	public static function agentCapabilities(): array {
		return [
			'read'              => true,
			self::CAP_USE_MCP   => true,
		];
	}

	/**
	 * The exact capability set granted to the ab_curator role (and merged
	 * into the administrator role). Includes the primitive capabilities for
	 * every capability_type in curatorPostTypeCapabilityTypes().
	 *
	 * @return array<string, bool>
	 */
	public static function curatorCapabilities(): array {
		$caps = [
			'read'                        => true,
			self::CAP_USE_MCP             => true,
			self::CAP_APPROVE_KNOWLEDGE   => true,
			self::CAP_PUBLISH             => true,
			self::CAP_GRANT_SCOPES        => true,
			self::CAP_VIEW_AUDIT          => true,
			self::CAP_MANAGE_ARTIFACTS    => true,
			self::CAP_MANAGE              => true,
		];

		foreach (self::postTypePrimitiveCaps() as $cap) {
			$caps[$cap] = true;
		}

		return $caps;
	}

	/**
	 * The flat list of every primitive capability string derived from
	 * curatorPostTypeCapabilityTypes(), mirroring WordPress's own
	 * get_post_type_capabilities() derivation for a custom capability_type.
	 *
	 * @return array<int, string>
	 */
	public static function postTypePrimitiveCaps(): array {
		$caps = [];

		foreach (self::curatorPostTypeCapabilityTypes() as [$singular, $plural]) {
			$caps[] = "edit_{$singular}";
			$caps[] = "read_{$singular}";
			$caps[] = "delete_{$singular}";
			$caps[] = "edit_{$plural}";
			$caps[] = "edit_others_{$plural}";
			$caps[] = "publish_{$plural}";
			$caps[] = "read_private_{$plural}";
			$caps[] = "delete_{$plural}";
			$caps[] = "delete_private_{$plural}";
			$caps[] = "delete_published_{$plural}";
			$caps[] = "delete_others_{$plural}";
			$caps[] = "edit_private_{$plural}";
			$caps[] = "edit_published_{$plural}";
		}

		return $caps;
	}

	/**
	 * Capabilities an agent must NEVER hold, per contract. Used both to
	 * assert the exact agent set (by absence) and as a defensive check.
	 *
	 * @return array<int, string>
	 */
	public static function agentForbiddenCapabilities(): array {
		return [
			'publish_posts',
			'edit_others_posts',
			'edit_posts',
			'list_users',
			'delete_posts',
			'edit_users',
			self::CAP_GRANT_SCOPES,
			self::CAP_APPROVE_KNOWLEDGE,
			self::CAP_PUBLISH,
			self::CAP_MANAGE,
		];
	}

	/**
	 * Add the ab_agent and ab_curator roles (idempotent), and merge curator
	 * capabilities into the administrator role.
	 */
	public static function install(): void {
		if (! function_exists('add_role')) {
			return;
		}

		if (get_role(self::ROLE_AGENT) === null) {
			add_role(self::ROLE_AGENT, 'Agent', self::agentCapabilities());
		} else {
			self::syncRoleCaps(self::ROLE_AGENT, self::agentCapabilities());
		}

		// Issue #231: agentForbiddenCapabilities() used to be asserted only
		// by a unit test — nothing stopped the ab_agent role itself from
		// accumulating a forbidden cap (a bad merge, a hand-edited role
		// manager, another plugin's add_cap). Activation is the natural
		// repair point: strip every forbidden cap from the stored role,
		// unconditionally, on every install/upgrade.
		$agentRole = get_role(self::ROLE_AGENT);
		if ($agentRole !== null) {
			foreach (self::agentForbiddenCapabilities() as $cap) {
				if ($agentRole->has_cap($cap)) {
					$agentRole->remove_cap($cap);
				}
			}
		}

		if (get_role(self::ROLE_CURATOR) === null) {
			add_role(self::ROLE_CURATOR, 'Curator', self::curatorCapabilities());
		} else {
			self::syncRoleCaps(self::ROLE_CURATOR, self::curatorCapabilities());
		}

		$admin = get_role('administrator');
		if ($admin !== null) {
			foreach (self::curatorCapabilities() as $cap => $grant) {
				if (! $admin->has_cap($cap)) {
					$admin->add_cap($cap, true);
				}
			}
		}
	}

	/**
	 * Issue #231 runtime guards: make agentForbiddenCapabilities() a runtime
	 * contract, not just a unit-test assertion. Three layers, all wired here
	 * so they run on every request (not only on activation):
	 *
	 * 1. A late-priority 'user_has_cap' filter denies every forbidden cap to
	 *    any user carrying the ab_agent role on EVERY capability check
	 *    (defense in depth: it also covers caps granted individually to the
	 *    user, which bypass the role entirely).
	 * 2. 'set_user_role' / 'add_user_role' purge individually-granted
	 *    forbidden caps the moment a user's roles change to include
	 *    ab_agent, so the stored user-level grant never survives a role
	 *    assignment either.
	 * 3. install() (above) strips forbidden caps from the stored role itself.
	 * 4. A 'map_meta_cap' filter turns any check that needs a forbidden cap
	 *    into do_not_allow for an ab_agent user. WordPress grants a
	 *    multisite super admin everything before 'user_has_cap' runs, but
	 *    still honours do_not_allow, so this closes that path.
	 *
	 * ForbiddenCapsNotice shows people with ab_manage any forbidden cap that
	 * is stored anyway, with a button that removes it.
	 *
	 * The filter runs late (priority 99) so it sees the final $allcaps after
	 * every grant-style filter (e.g. Review\HandoffCapabilities,
	 * Artifacts\ObjectAccess) has run; it only ever unsets, and only for
	 * ab_agent-role users, so it cannot interfere with curators or
	 * administrators (who never carry the ab_agent role).
	 */
	public static function registerHooks(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		add_filter('user_has_cap', [self::class, 'denyForbiddenCapsForAgents'], 99, 4);
		add_filter('map_meta_cap', [self::class, 'refuseForbiddenCapsForAgents'], 99, 4);
		add_action('set_user_role', [self::class, 'purgeForbiddenUserCaps'], 20, 1);
		add_action('add_user_role', [self::class, 'purgeForbiddenUserCaps'], 20, 1);
	}

	/**
	 * 'user_has_cap' filter: any user with the ab_agent role loses every
	 * forbidden capability on every check, whatever the source (role,
	 * individual grant, or another plugin's filter).
	 *
	 * @param array<string, bool> $allcaps
	 * @param string[]            $caps
	 * @param array<int, mixed>   $args
	 * @param mixed               $user
	 * @return array<string, bool>
	 */
	public static function denyForbiddenCapsForAgents(array $allcaps, array $caps, array $args, $user = null): array {
		unset($caps, $args);

		if (! $user instanceof \WP_User || ! in_array(self::ROLE_AGENT, (array) $user->roles, true)) {
			return $allcaps;
		}

		foreach (self::agentForbiddenCapabilities() as $cap) {
			unset($allcaps[$cap]);
		}

		return $allcaps;
	}

	/**
	 * 'map_meta_cap' filter: a check whose primitive caps include a
	 * forbidden one is do_not_allow for an ab_agent user (layer 4 above).
	 *
	 * @param string[]          $caps
	 * @param string            $cap
	 * @param int               $userId
	 * @param array<int, mixed> $args
	 * @return string[]
	 */
	public static function refuseForbiddenCapsForAgents($caps, $cap, $userId, $args = []): array {
		unset($cap, $args);
		$caps = (array) $caps;

		if ((int) $userId <= 0 || array_intersect($caps, self::agentForbiddenCapabilities()) === [] || ! function_exists('get_userdata')) {
			return $caps;
		}

		$user = get_userdata((int) $userId);
		if (! $user instanceof \WP_User || ! in_array(self::ROLE_AGENT, (array) $user->roles, true)) {
			return $caps;
		}

		return ['do_not_allow'];
	}

	/**
	 * 'set_user_role' / 'add_user_role' action: when a user's resulting roles
	 * include ab_agent, remove any individually-assigned forbidden caps
	 * ($user->caps, i.e. grants made via WP_User::add_cap() that survive role
	 * changes). Role-derived caps are install()'s job (layer 3); runtime
	 * checks are denyForbiddenCapsForAgents()' (layer 1) — this layer makes
	 * the stored per-user state itself clean.
	 */
	public static function purgeForbiddenUserCaps(int $userId): void {
		if (! function_exists('get_userdata')) {
			return;
		}

		$user = get_userdata($userId);
		if (! $user instanceof \WP_User || ! in_array(self::ROLE_AGENT, (array) $user->roles, true)) {
			return;
		}

		foreach (self::agentForbiddenCapabilities() as $cap) {
			if (isset($user->caps[$cap])) {
				$user->remove_cap($cap);
			}
		}
	}

	/**
	 * Remove ab_agent / ab_curator roles and strip curator caps from
	 * administrator. Safe to call on both deactivate and uninstall.
	 */
	public static function uninstall(): void {
		if (! function_exists('remove_role')) {
			return;
		}

		$admin = get_role('administrator');
		if ($admin !== null) {
			foreach (array_keys(self::curatorCapabilities()) as $cap) {
				if ($cap === 'read') {
					continue; // Never strip 'read' from administrator.
				}

				$admin->remove_cap($cap);
			}
		}

		remove_role(self::ROLE_AGENT);
		remove_role(self::ROLE_CURATOR);
	}

	/**
	 * @param array<string, bool> $caps
	 */
	private static function syncRoleCaps(string $roleName, array $caps): void {
		$role = get_role($roleName);
		if ($role === null) {
			return;
		}

		foreach ($caps as $cap => $grant) {
			if (! $role->has_cap($cap)) {
				$role->add_cap($cap, $grant);
			}
		}
	}
}
