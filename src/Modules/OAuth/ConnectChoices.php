<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\AgentId\Fqdn;
use AgentBrain\Modules\Identity\AgentHumans;
use AgentBrain\Modules\Identity\Scopes;
use WP_User;

/**
 * The choices on the consent screen's "Connect <client> as" picker, worded
 * so a person can tell them apart: each agent by a readable name (its
 * nickname, else the name part of its agent ID) with the agent ID in small
 * print, whose agent it is, and its description (the profile's
 * Biographical Info, or the role from its proposal); then the person
 * themselves, saying where their writes go. One agent is recommended and
 * preselected: the person's default agent (RoleSwitching::defaultAgentFor),
 * else an agent they issued. Only the wording and the preselection are
 * decided here; Delegation::canActAs re-checks the choice on the server.
 */
final class ConnectChoices {

	public const KIND_AGENT = 'agent';
	public const KIND_SELF  = 'self';

	/**
	 * @return array<int, array{id:int, kind:string, name:string, detail:string, owner:string, description:string, recommended:bool, writes_via:string, read_scopes:string[], write_scopes:string[]}>
	 */
	public static function for(int $userId, Scopes $scopes): array {
		$agents = Delegation::agentsFor($userId);
		if ($agents === []) {
			return [];
		}

		$recommended = self::recommended($userId, $agents);
		$options     = [];
		foreach ($agents as $agent) {
			$id    = (int) $agent->ID;
			$owner = AgentHumans::nameFor($id);

			$options[] = self::option($scopes, $id, [
				'kind'        => self::KIND_AGENT,
				'name'        => self::label($agent),
				'detail'      => (string) (AgentIssuer::fqdnFor($id) ?? $agent->display_name),
				'owner'       => (int) get_user_meta($id, AgentMeta::ISSUED_BY, true) === $userId ? __('you', 'agent-brain') : (string) $owner,
				'description' => trim((string) $agent->description),
				'recommended' => $id === $recommended,
			]);
		}

		// The recommended agent first; the others keep their order.
		usort($options, static fn (array $a, array $b): int => (int) $b['recommended'] <=> (int) $a['recommended']);

		$user      = get_userdata($userId);
		$writesVia = RoleSwitching::defaultAgentFor($userId);
		$via       = $writesVia > 0 ? get_userdata($writesVia) : false;

		$options[] = self::option($scopes, $userId, [
			'kind'        => self::KIND_SELF,
			/* translators: %s: the signed-in person's display name. */
			'name'        => sprintf(__('Me (%s)', 'agent-brain'), $user instanceof WP_User ? $user->display_name : ''),
			'detail'      => '',
			'owner'       => '',
			'description' => '',
			'recommended' => false,
			'writes_via'  => $via instanceof WP_User ? self::label($via) : '',
		]);

		return $options;
	}

	/**
	 * A name a person reads: the agent's nickname when someone gave it one
	 * (an approved proposal does), else the name part of its agent ID
	 * ("dennis-ai" from dennis-ai-6aae48f5.agnt.example.com), else its
	 * display name.
	 */
	public static function label(WP_User $agent): string {
		$fqdn     = (string) AgentIssuer::fqdnFor((int) $agent->ID);
		$nickname = trim((string) get_user_meta((int) $agent->ID, 'nickname', true));
		if ($nickname !== '' && ! in_array($nickname, [$fqdn, $agent->user_login], true)) {
			return $nickname;
		}

		$parts = $fqdn !== '' ? Fqdn::parse($fqdn) : null;
		if ($parts !== null) {
			return $parts['name'];
		}

		return (string) $agent->display_name;
	}

	/**
	 * The person's default agent if they may connect as it, else the first
	 * agent they issued themselves, else none (0).
	 *
	 * @param WP_User[] $agents
	 */
	private static function recommended(int $userId, array $agents): int {
		$ids     = array_map(static fn (WP_User $agent): int => (int) $agent->ID, $agents);
		$default = RoleSwitching::defaultAgentFor($userId);
		if ($default > 0 && in_array($default, $ids, true)) {
			return $default;
		}

		foreach ($ids as $id) {
			if ((int) get_user_meta($id, AgentMeta::ISSUED_BY, true) === $userId) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * @param array{kind:string, name:string, detail:string, owner:string, description:string, recommended:bool, writes_via?:string} $fields
	 * @return array{id:int, kind:string, name:string, detail:string, owner:string, description:string, recommended:bool, writes_via:string, read_scopes:string[], write_scopes:string[]}
	 */
	private static function option(Scopes $scopes, int $id, array $fields): array {
		return [
			'id'           => $id,
			'kind'         => $fields['kind'],
			'name'         => $fields['name'],
			'detail'       => $fields['detail'],
			'owner'        => $fields['owner'],
			'description'  => $fields['description'],
			'recommended'  => $fields['recommended'],
			'writes_via'   => $fields['writes_via'] ?? '',
			'read_scopes'  => self::scopeNames($scopes->readScopeIds($id)),
			'write_scopes' => self::scopeNames($scopes->writeScopeIds($id)),
		];
	}

	/**
	 * @param int[] $scopeIds
	 * @return string[]
	 */
	private static function scopeNames(array $scopeIds): array {
		$names = [];
		foreach ($scopeIds as $scopeId) {
			$term = get_term((int) $scopeId, Scopes::TAXONOMY);
			if ($term instanceof \WP_Term) {
				$names[] = $term->name;
			}
		}

		return $names;
	}
}
