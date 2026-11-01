<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Audit\Repository;
use AgentBrain\Modules\Identity\AgentHumans;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * One snapshot of the Overview page, shared by the server-rendered page and
 * the live REST endpoint (OverviewRest) so the two can never drift.
 *
 * Memory excerpts are filtered by the viewer's own read scopes in SQL, the
 * same "scope before rank" rule as Recall: a curator only sees memory they
 * could read anyway. Activity comes from the audit log and never carries
 * memory content. Every query is bounded.
 */
final class OverviewData {

	public const ACTIVITY_LIMIT = 12;
	public const MEMORY_LIMIT   = 8;

	/** Audit rows scanned to fill ACTIVITY_LIMIT visible events after hiding internal noise. */
	private const ACTIVITY_SCAN = 60;

	private const EXCERPT_CHARS = 180;

	/**
	 * @return array{
	 *   counts: array{review:int, handoffs:int, active_agents:int, memories:int},
	 *   stages: array<string, int>,
	 *   activity: array<int, array{id:int, agent:string, agent_id:int, action:string, status:string, ts:string}>,
	 *   memories: array<int, array{id:int, agent:string, agent_id:int, scope:string, type:string, excerpt:string, ts:string}>,
	 *   health: string,
	 *   scopeless: bool
	 * }
	 */
	public static function snapshot(int $userId): array {
		$badges   = Menu::badgeCounts();
		$scopeIds = self::readScopeIds($userId);
		// A curator who holds no scopes still manages the brain: show real
		// totals (counts only, never content) instead of a false "nothing yet".
		$scopeless = $scopeIds === [] && $userId > 0 && user_can($userId, Capabilities::CAP_MANAGE);

		$snapshot = [
			'counts'   => [
				'review'        => (int) $badges['review'],
				'handoffs'      => (int) $badges['handoffs'],
				'active_agents' => OverviewPage::activeAgentsLast7Days(),
				'memories'      => $scopeless ? self::memoryCount([], true) : self::memoryCount($scopeIds),
			],
			'stages'   => OverviewPage::artifactCountsByStage(),
			'activity' => self::activity(),
			'memories' => self::latestMemories($scopeIds),
			'health'   => class_exists(\AgentBrain\Core\Health::class) ? \AgentBrain\Core\Health::status() : 'unknown',
			'scopeless' => $scopeless,
		];

		/**
		 * agent_brain/overview_snapshot filter: ( array $snapshot, int $userId )
		 * => array. Runs for both the first render and the live REST poll, so a
		 * site can tailor what a given viewer sees (for example, a read-only
		 * demo role that should only see some agents' activity). Memory
		 * excerpts are already limited to the viewer's read scopes before this.
		 */
		$filtered = apply_filters('agent_brain/overview_snapshot', $snapshot, $userId);

		return is_array($filtered) ? $filtered : $snapshot;
	}

	/**
	 * Null for events kept out of the live feed.
	 */
	public static function label(string $event, string $objectId): ?string {
		return EventLabels::feed($event, $objectId);
	}

	/**
	 * @return array<int, array{id:int, agent:string, agent_id:int, action:string, status:string, ts:string}>
	 */
	private static function activity(): array {
		$out = [];

		foreach (Repository::recent(self::ACTIVITY_SCAN, 0) as $row) {
			$label = self::label((string) ($row['event'] ?? ''), (string) ($row['object_id'] ?? ''));
			if ($label === null) {
				continue;
			}

			$actorId = (int) ($row['actor_id'] ?? 0);
			$meta    = json_decode((string) ($row['meta'] ?? ''), true);
			$meta    = is_array($meta) ? $meta : [];
			if (($row['event'] ?? '') === 'agent.switch' && (int) ($meta['to'] ?? 0) > 0) {
				/* translators: %s: agent (or person) the connection now acts as. */
				$label = sprintf(__('switched to acting as %s', 'agent-brain'), self::userName((int) $meta['to']));
			}
			if (($row['event'] ?? '') === 'oauth.grant' && (int) ($meta['on_behalf_of'] ?? 0) > 0) {
				/* translators: %s: agent the AI client was connected as. */
				$label = sprintf(__('connected an AI client as %s', 'agent-brain'), self::userName((int) $meta['on_behalf_of']));
			}

			$out[]   = [
				'id'       => (int) ($row['id'] ?? 0),
				'agent'    => self::displayName($actorId, (int) ($meta['via'] ?? 0)),
				'agent_id' => $actorId,
				'action'   => $label,
				'status'   => (string) ($row['status'] ?? ''),
				'ts'       => self::isoUtc((string) ($row['ts'] ?? '')),
			];

			if (count($out) >= self::ACTIVITY_LIMIT) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param int[] $scopeIds
	 * @return array<int, array{id:int, agent:string, agent_id:int, scope:string, type:string, excerpt:string, ts:string}>
	 */
	private static function latestMemories(array $scopeIds): array {
		if ($scopeIds === []) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($scopeIds), '%d'));
		$sql          = 'SELECT id, agent_id, scope_id, type, content, via, created FROM ' . MemoryRepository::memoryTable()
			. " WHERE status = 'active' AND scope_id IN ({$placeholders}) ORDER BY created DESC, id DESC LIMIT %d";

		$rows = Db::getResults($sql, array_merge($scopeIds, [self::MEMORY_LIMIT]));

		$out = [];
		foreach ($rows as $row) {
			$agentId = (int) ($row['agent_id'] ?? 0);
			$out[]   = [
				'id'       => (int) ($row['id'] ?? 0),
				'agent'    => self::displayName($agentId, (int) ($row['via'] ?? 0)),
				'agent_id' => $agentId,
				'scope'    => self::scopeName((int) ($row['scope_id'] ?? 0)),
				'type'     => (string) ($row['type'] ?? ''),
				'excerpt'  => self::excerpt((string) ($row['content'] ?? '')),
				'ts'       => self::isoUtc((string) ($row['created'] ?? '')),
			];
		}

		return $out;
	}

	/**
	 * Active memories the person may read: their read scopes, or every
	 * scope for a curator who holds none (counts only, as on the overview).
	 */
	public static function memoryCountFor(int $userId): int {
		$scopeIds = self::readScopeIds($userId);

		return $scopeIds === [] && $userId > 0 && user_can($userId, Capabilities::CAP_MANAGE)
			? self::memoryCount([], true)
			: self::memoryCount($scopeIds);
	}

	/**
	 * @param int[] $scopeIds
	 */
	private static function memoryCount(array $scopeIds, bool $all = false): int {
		if ($all) {
			$rows = Db::getResults('SELECT COUNT(*) AS c FROM ' . MemoryRepository::memoryTable() . " WHERE status = 'active'", []);

			return isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
		}

		if ($scopeIds === []) {
			return 0;
		}

		$placeholders = implode(', ', array_fill(0, count($scopeIds), '%d'));
		$rows         = Db::getResults(
			'SELECT COUNT(*) AS c FROM ' . MemoryRepository::memoryTable() . " WHERE status = 'active' AND scope_id IN ({$placeholders})",
			$scopeIds
		);

		return isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
	}

	/**
	 * Fails closed: any error resolving scopes means no memory is shown.
	 *
	 * @return int[]
	 */
	private static function readScopeIds(int $userId): array {
		if ($userId <= 0) {
			return [];
		}

		try {
			return array_values(array_filter(array_map('intval', (new Scopes())->readScopeIds($userId)), static fn (int $id): bool => $id > 0));
		} catch (\Throwable $e) {
			return [];
		}
	}

	/**
	 * "Atlas", or "Atlas (via Dana)" when the agent was acting for a
	 * person through a delegated connection. With no via, an agent with a
	 * known human shows "Atlas (Dana's Tío)" so the FQDN never stands
	 * alone where a name is expected (#139).
	 */
	public static function displayName(int $userId, int $viaId = 0): string {
		if ($userId <= 0) {
			return __('System', 'agent-brain');
		}

		$name = self::userName($userId);
		if ($viaId > 0 && $viaId !== $userId) {
			/* translators: 1: agent name, 2: person the agent acted for. */
			$name = sprintf(__('%1$s (via %2$s)', 'agent-brain'), $name, self::userName($viaId));
		} else {
			$human = AgentHumans::nameFor($userId);
			if ($human !== null) {
				/* translators: 1: agent name, 2: person the agent works for. Tío is TomaTío's word for a person's AI agent. */
				$name = sprintf(__('%1$s (%2$s\'s Tío)', 'agent-brain'), $name, $human);
			}
		}

		return $name;
	}

	private static function userName(int $userId): string {
		$user = function_exists('get_userdata') ? get_userdata($userId) : false;

		return $user instanceof \WP_User ? $user->display_name : (string) $userId;
	}

	private static function scopeName(int $scopeId): string {
		$term = function_exists('get_term') ? get_term($scopeId, Scopes::TAXONOMY) : null;

		return $term instanceof \WP_Term ? $term->name : '';
	}

	private static function excerpt(string $content): string {
		$text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($content)));

		if (mb_strlen($text) > self::EXCERPT_CHARS) {
			$text = rtrim(mb_substr($text, 0, self::EXCERPT_CHARS - 1)) . '…';
		}

		return $text;
	}

	private static function isoUtc(string $mysqlUtc): string {
		$timestamp = $mysqlUtc !== '' ? strtotime($mysqlUtc . ' UTC') : false;

		return $timestamp === false ? '' : gmdate('c', $timestamp);
	}
}
