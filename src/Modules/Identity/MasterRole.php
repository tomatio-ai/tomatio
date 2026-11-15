<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\OAuth\Delegation;
use WP_Error;

/**
 * The master role (#921): who may set up the brain's agents, their jobs and
 * scopes over MCP (Mcp\MasterAbilities), so a person can say "set up a
 * writer and a coder" in their AI and it is done without wp-admin.
 *
 * Two kinds of master:
 *  - A person master: a person (never an agent account) who can add agents
 *    in the Review Queue (AgentProposals::canReview: ab_manage,
 *    create_users and ab_grant_scopes). What they ask for takes effect at
 *    once: their explicit ask is the approval.
 *  - A master agent: a pure agent marked with META = '1' by a person
 *    master (META_BY keeps who), for an always-on setup agent. Its new
 *    agents wait in the Review Queue like agent-propose; it may only give
 *    or take away scopes it holds itself, only on agents that are not
 *    masters, never on itself or a person.
 *
 * Only a person master can make or unmake a master agent, and a new agent
 * is never a master. Every change is audited.
 */
final class MasterRole {

	/** User meta on a pure agent: '1' when it is a master agent. */
	public const META = 'ab_master';

	/** User meta: the person master who made the agent a master. */
	public const META_BY = 'ab_master_by';

	/**
	 * Term meta on an ab_scope term a master agent just created: the
	 * master agent's id, until its one-time creator grant
	 * (Scopes::grantNewScopeAsMaster) has run.
	 */
	public const TERM_META_PENDING_CREATOR = 'ab_scope_pending_creator';

	/** A pure agent account: only the ab_agent role, no management capability (Delegation). */
	public static function isAgentAccount(int $userId): bool {
		if ($userId <= 0) {
			return false;
		}

		$user = get_userdata($userId);

		return $user instanceof \WP_User && Delegation::isPureAgent($user);
	}

	/** A pure agent a person master has marked as a master. */
	public static function isMasterAgent(int $userId): bool {
		return self::isAgentAccount($userId) && (string) get_user_meta($userId, self::META, true) === '1';
	}

	/** A person (not an agent account) who can add agents to this brain. */
	public static function isPersonMaster(int $userId): bool {
		if ($userId <= 0) {
			return false;
		}

		$user = get_userdata($userId);
		if (! $user instanceof \WP_User || in_array(Capabilities::ROLE_AGENT, (array) $user->roles, true)) {
			return false;
		}

		return AgentProposals::canReview($userId);
	}

	public static function isMaster(int $userId): bool {
		return self::isPersonMaster($userId) || self::isMasterAgent($userId);
	}

	/** The person who made $agentId a master (0: none, or not a master). */
	public static function masterBy(int $agentId): int {
		return self::isMasterAgent($agentId) ? (int) get_user_meta($agentId, self::META_BY, true) : 0;
	}

	/**
	 * Make $agentId a master agent ($on) or an ordinary one again. Only a
	 * person master can, and only for a pure agent that is not revoked.
	 *
	 * @return true|WP_Error
	 */
	public static function set(int $personId, int $agentId, bool $on) {
		if (! self::isPersonMaster($personId)) {
			return new WP_Error('ab_forbidden', __('Only a person who can add agents to this brain can make or unmake a master agent.', 'tomatio'), ['status' => 403]);
		}

		if (! self::isAgentAccount($agentId)) {
			return new WP_Error('ab_not_an_agent', __('Only an agent can be a master agent; a person\'s account never is.', 'tomatio'), ['status' => 422]);
		}

		if ($on && AgentIssuer::statusOf($agentId) === AgentMeta::STATUS_REVOKED) {
			return new WP_Error('ab_agent_revoked', __('That agent is revoked, so it cannot be a master agent.', 'tomatio'), ['status' => 409]);
		}

		if ($on === self::isMasterAgent($agentId)) {
			return true;
		}

		if ($on) {
			update_user_meta($agentId, self::META, '1');
			update_user_meta($agentId, self::META_BY, $personId);
			self::audit($personId, 'agent.master_set', $agentId);
		} else {
			delete_user_meta($agentId, self::META);
			delete_user_meta($agentId, self::META_BY);
			self::audit($personId, 'agent.master_cleared', $agentId);
		}

		return true;
	}

	/**
	 * Whether master agent $masterId may give ($list) scope $scopeId to, or
	 * take it from, $targetId: the target is a pure agent that is neither
	 * the master itself nor another master, and the master holds that scope
	 * on the same list itself.
	 */
	public static function mayChangeScope(int $masterId, int $targetId, string $list, int $scopeId): bool {
		if (! self::isMasterAgent($masterId) || $targetId === $masterId || ! self::isAgentAccount($targetId) || self::isMasterAgent($targetId)) {
			return false;
		}

		$scopes = new Scopes();
		$own    = match ($list) {
			'read'  => $scopes->readScopeIds($masterId),
			'write' => $scopes->writeScopeIds($masterId),
			default => [],
		};

		return in_array($scopeId, $own, true);
	}

	private static function audit(int $actorId, string $event, int $agentId): void {
		\AgentBrain\Core\Hooks::action('audit', [
			'actor_id'    => $actorId,
			'event'       => $event,
			'status'      => 'ok',
			'object_type' => 'user',
			'object_id'   => (string) $agentId,
			'meta'        => ['fqdn' => AgentIssuer::fqdnFor($agentId)],
		]);
	}
}
