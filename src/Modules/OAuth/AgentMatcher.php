<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Modules\AgentId\AgentMeta;
use WP_User;

/**
 * Which agent act-as means (#371). Voice clients rarely say the full agent
 * id (fm-designer-027fd774.agnt.example.com), so besides the exact name,
 * login, agent id and user id this accepts a role word ("designer", from the
 * login fm-designer or the role "Designer: plans the game") and any unique
 * prefix ("des"). Case, accents, spaces and a leading "the" are ignored.
 *
 * Tried in order, first hit wins: exact, role word, prefix. A word that fits
 * more than one agent at the first tier it hits is ambiguous: the caller
 * lists the matches instead of guessing.
 */
final class AgentMatcher {

	public const ME        = 'me';
	public const AGENT     = 'agent';
	public const AMBIGUOUS = 'ambiguous';
	public const NONE      = 'none';

	/** Shortest prefix that may pick an agent, so "a" or "f" never does. */
	private const MIN_PREFIX = 2;

	/**
	 * @param WP_User[] $agents The agents the person may switch into.
	 * @return array{status: string, agent?: WP_User, by?: string, matches?: WP_User[]}
	 */
	public static function match(string $wanted, int $person, array $agents): array {
		$key = self::spoken($wanted);
		if ($key === '') {
			return ['status' => self::NONE];
		}

		$self = get_userdata($person);
		if (in_array($key, ['me', 'myself', 'yourself'], true)
			|| ($self instanceof WP_User && in_array($key, [self::canon($self->display_name), self::canon($self->user_login)], true))) {
			return ['status' => self::ME];
		}

		$tiers = [
			'name'   => static fn (WP_User $agent): bool => $key === (string) $agent->ID || in_array($key, self::exactKeys($agent), true),
			'role'   => static fn (WP_User $agent): bool => in_array($key, self::roleWords($agent), true),
			'prefix' => static function (WP_User $agent) use ($key): bool {
				if (strlen($key) < self::MIN_PREFIX) {
					return false;
				}
				foreach (array_merge(self::exactKeys($agent), self::roleWords($agent)) as $candidate) {
					if (str_starts_with($candidate, $key)) {
						return true;
					}
				}

				return false;
			},
		];

		foreach ($tiers as $by => $fits) {
			$hits = array_values(array_filter($agents, $fits));
			if (count($hits) === 1) {
				return ['status' => self::AGENT, 'agent' => $hits[0], 'by' => $by];
			}
			if (count($hits) > 1) {
				return ['status' => self::AMBIGUOUS, 'matches' => $hits, 'by' => $by];
			}
		}

		return ['status' => self::NONE];
	}

	/**
	 * The short title in an agent's role, "Designer" from "Designer: plans
	 * the game", or '' when the role has no such title.
	 */
	public static function roleTitle(WP_User $agent): string {
		$role = trim((string) $agent->description);
		$cut  = strpos($role, ':');

		return $cut !== false && $cut > 0 && $cut <= 40 ? trim(substr($role, 0, $cut)) : '';
	}

	/**
	 * Display name, login, agent id and the agent id's first label.
	 *
	 * @return string[]
	 */
	private static function exactKeys(WP_User $agent): array {
		$fqdn = (string) get_user_meta((int) $agent->ID, AgentMeta::FQDN, true);
		$keys = [self::canon($agent->display_name), self::canon($agent->user_login)];
		foreach ([$fqdn, $agent->display_name] as $id) {
			if (str_contains($id, '.')) {
				$keys[] = self::canon($id);
				$keys[] = self::canon(strstr($id, '.', true));
			}
		}

		return array_values(array_unique(array_filter($keys)));
	}

	/**
	 * The words of the login (fm-designer: "fm", "designer") and of the
	 * role title, plus the whole title.
	 *
	 * @return string[]
	 */
	private static function roleWords(WP_User $agent): array {
		$title = self::canon(self::roleTitle($agent));
		$words = array_merge(
			explode('-', self::canon($agent->user_login)),
			explode('-', $title),
			[$title]
		);

		return array_values(array_unique(array_filter($words)));
	}

	/** What the person said, minus a leading "the" or "my". */
	private static function spoken(string $wanted): string {
		return self::canon((string) preg_replace('/^\s*(the|my|a|an|el|la)\s+/i', '', $wanted));
	}

	/** Lower case, no accents, runs of spaces and underscores as one hyphen. */
	private static function canon(string $s): string {
		return trim((string) preg_replace('/[\s_]+/', '-', strtolower(remove_accents(trim($s)))), '-');
	}
}
