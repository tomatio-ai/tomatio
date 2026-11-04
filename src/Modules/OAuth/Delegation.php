<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\Identity\Capabilities;
use WP_User;

/**
 * "Connect this AI as": who may authorize an AI client to act as whom.
 *
 * The person approving an OAuth request is usually signed in as themselves
 * (they came from wp-admin), but the client should act as an agent, with
 * the agent's scopes and its own audit trail. Instead of making them log out
 * and sign in with an agent password, a manager can pick the agent on the
 * consent screen and the code is issued for that agent.
 *
 * Rules, enforced on the server when the consent form is posted (the picker
 * is only a convenience):
 *  - Anyone may connect a client as themselves.
 *  - Only users with ab_manage may connect a client as someone else, and
 *    only as a pure agent: a user whose sole role is ab_agent and who holds
 *    neither ab_manage nor manage_options. Never as another person.
 *  - `agent_brain/oauth_delegable_agents` ( int[] $agentIds, int $userId )
 *    can narrow the list, for example to agents a person owns.
 */
final class Delegation {

	/** POST field carrying the chosen account's user ID. */
	public const FIELD = 'ab_connect_as';

	/**
	 * Agents $userId may connect an AI client as, by display name.
	 *
	 * @return WP_User[]
	 */
	public static function agentsFor(int $userId): array {
		if ($userId <= 0 || ! user_can($userId, Capabilities::CAP_MANAGE)) {
			return [];
		}

		$agents = array_values(array_filter(
			get_users(['role' => Capabilities::ROLE_AGENT, 'orderby' => 'display_name', 'order' => 'ASC']),
			static fn (WP_User $user): bool => self::isPureAgent($user) && ! AgentIssuer::isBlocked((int) $user->ID)
		));

		$ids     = array_map(static fn (WP_User $agent): int => (int) $agent->ID, $agents);
		$allowed = array_map('intval', (array) apply_filters('agent_brain/oauth_delegable_agents', $ids, $userId));

		return array_values(array_filter($agents, static fn (WP_User $agent): bool => in_array((int) $agent->ID, $allowed, true)));
	}

	public static function canActAs(int $userId, int $targetId): bool {
		if ($userId <= 0 || $targetId <= 0) {
			return false;
		}

		if ($targetId === $userId) {
			return true;
		}

		foreach (self::agentsFor($userId) as $agent) {
			if ((int) $agent->ID === $targetId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A machine identity, not a person: only the agent role, and no
	 * management or site-admin capability from anywhere.
	 */
	public static function isPureAgent(WP_User $user): bool {
		return array_values((array) $user->roles) === [Capabilities::ROLE_AGENT]
			&& ! user_can($user, Capabilities::CAP_MANAGE)
			&& ! user_can($user, 'manage_options');
	}
}
