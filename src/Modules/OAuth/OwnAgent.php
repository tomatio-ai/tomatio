<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Modules\Identity\AgentProposals;

/**
 * #962: a person who connects an AI as themselves gets their own agent (their
 * Tío) the moment they allow access, when they have no agent to write
 * through yet. People write through an agent, never as themselves
 * (RoleSwitching::ROUTED_WRITES), so without one a fresh brain's first
 * connection could read but not save, and the AI told the person it could
 * not keep anything.
 *
 * Only for someone who may add agents (AgentProposals::canReview, as on a
 * new brain's administrator); everyone else keeps the agents a curator gives
 * them. The new agent gets the person's own write scopes and becomes their
 * default agent.
 */
final class OwnAgent {

	/**
	 * The agent this person's AI writes as: their default agent when they
	 * have one, else a new one made now when they may add agents, else 0.
	 */
	public static function ensureFor(int $personId): int {
		$current = RoleSwitching::defaultAgentFor($personId);
		if ($current > 0 || $personId <= 0) {
			return $current;
		}

		if (Delegation::agentsFor($personId) !== [] || ! AgentProposals::canReview($personId)) {
			return 0;
		}

		$agentId = self::create($personId);
		if ($agentId <= 0) {
			return 0;
		}

		update_user_meta($personId, RoleSwitching::DEFAULT_AGENT_META, $agentId);

		return RoleSwitching::defaultAgentFor($personId);
	}

	/** Whether ensureFor() would make a new agent for this person. */
	public static function wouldCreate(int $personId): bool {
		return $personId > 0
			&& RoleSwitching::defaultAgentFor($personId) === 0
			&& Delegation::agentsFor($personId) === []
			&& AgentProposals::canReview($personId);
	}

	private static function create(int $personId): int {
		$user  = get_userdata($personId);
		$first = $user instanceof \WP_User ? trim((string) ($user->first_name !== '' ? $user->first_name : $user->display_name)) : '';
		// The agent ID is made from the name, so it is plain ASCII ("dana tio"
		// gives dana-tio-…); people see the nickname, "Dana's Tío".
		$plain = trim(remove_accents($first !== '' ? $first . ' tio' : 'my tio'));
		/* translators: %s: the person's first name or display name. */
		$shown = $first !== '' ? sprintf(__('%s\'s Tío', 'tomatio'), $first) : __('My Tío', 'tomatio');
		$role  = __('Your own AI: what your AI apps save goes in as this agent, logged as done through you.', 'tomatio');

		for ($n = 1; $n <= 5; $n++) {
			$result = AgentProposals::createNow($personId, $personId, $n === 1 ? $plain : $plain . ' ' . $n, $role, null, null);
			if (is_int($result) && $result > 0) {
				update_user_meta($result, 'nickname', $n === 1 ? $shown : $shown . ' ' . $n);

				return $result;
			}
		}

		return 0;
	}
}
