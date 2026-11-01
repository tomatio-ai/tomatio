<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Maps an ab_audit row to a PCP audit entry (PCP v0.3 SPEC section 7.5,
 * schemas/0.3/audit.entry.schema.json). Pure: principals are resolved by
 * the callables the caller passes, so the unit suite checks every rule.
 *
 * Categories, never content:
 *  - args_hash is an unkeyed SHA-256 of the call's arguments (for forget,
 *    of the subject), which a guess can reverse, so it is never exported;
 *  - meta is not copied: only whole-number counts are kept, through an
 *    allowlist onto counts (matched, returned, written, deleted), and the
 *    person an agent acted for (meta.via) becomes on_behalf_of;
 *  - object ids are kept only when they are numbers or UUIDs, as
 *    "ab-<kind>-<id>" (a user becomes its principal id), never names,
 *    paths or slugs; an erasure receipt keeps its own id, as in
 *    erasures/receipts.jsonl (SPEC 8.9.2 item 2);
 *  - erasure entries are erase.request and erase.complete (earlier
 *    versions wrote forget.requested and forget.completed, mapped here);
 *    the retention sweep runs as service:retention (SPEC 8.10 item 2).
 */
final class AuditMapper {

	/** meta key => counts member. */
	public const COUNT_KEYS = [
		'matched'      => 'matched',
		'target_count' => 'matched',
		'count'        => 'returned',
		'returned'     => 'returned',
		'results'      => 'returned',
		'pages'        => 'returned',
		'written'      => 'written',
		'files'        => 'written',
		'deleted'      => 'deleted',
		'removed'      => 'deleted',
	];

	/** Event names of earlier versions => the PCP action. */
	public const LEGACY_ACTIONS = [
		'forget.requested' => 'erase.request',
		'forget.completed' => 'erase.complete',
	];

	/** System jobs that run as a service principal. */
	public const SERVICE_PRINCIPALS = [
		'retention.sweep'       => 'service:retention',
		'provenance.retention'  => 'service:retention',
	];

	/** The categories a full export holds, sorted. */
	public const EXPORT_CATEGORIES = ['audit', 'erasure', 'export', 'memory', 'partition', 'principal', 'profile.team', 'review'];

	private const ID_PATTERN     = '/^[A-Za-z0-9._:-]{1,128}$/';
	private const ACTION_PATTERN = '/^[a-z][a-z0-9_.-]{0,63}$/';
	private const KIND_PATTERN   = '/^[a-z][a-z0-9_]{0,40}$/';

	/**
	 * @param array<string, mixed>     $row        id, ts, actor_id, event, status, object_type, object_id, meta, duration_ms.
	 * @param callable(int): ?string    $principal  User id to principal id.
	 * @param callable(string): ?string $byFqdn     Agent FQDN to principal id.
	 * @return array<string, mixed>
	 */
	public static function entry(array $row, callable $principal, callable $byFqdn): array {
		$event = (string) ($row['event'] ?? '');
		$meta  = json_decode((string) ($row['meta'] ?? ''), true);
		$meta  = is_array($meta) ? $meta : [];

		$actorId = (int) ($row['actor_id'] ?? 0);
		$actor   = $actorId > 0 ? $principal($actorId) : (self::SERVICE_PRINCIPALS[$event] ?? null);

		$onBehalfOf = null;
		if (isset($meta['via']) && is_numeric($meta['via']) && (int) $meta['via'] > 0) {
			$onBehalfOf = $principal((int) $meta['via']);
		}

		$entry = [
			'id'           => 'ab-audit-' . (int) ($row['id'] ?? 0),
			'at'           => ExportFormat::utc((string) ($row['ts'] ?? '')) ?? '1970-01-01T00:00:00Z',
			'principal'    => $actor,
			'on_behalf_of' => $onBehalfOf !== $actor ? $onBehalfOf : null,
			'channel'      => self::channel($event, $actorId),
			'action'       => self::action($event, (string) ($row['object_id'] ?? '')),
			'categories'   => self::categories($event, (string) ($row['object_id'] ?? ''), (string) ($row['object_type'] ?? '')),
			'outcome'      => self::outcome((string) ($row['status'] ?? '')),
			'counts'       => self::counts($meta),
			'object_ids'   => self::objectIds($event, (string) ($row['object_type'] ?? ''), (string) ($row['object_id'] ?? ''), $meta, $principal, $byFqdn),
			'duration_ms'  => isset($row['duration_ms']) && is_numeric($row['duration_ms']) && (int) $row['duration_ms'] >= 0 ? (int) $row['duration_ms'] : null,
		];

		return array_filter(
			$entry,
			static fn ($value, string $key): bool => $key === 'categories' || ($value !== null && $value !== []),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/** Ability calls come over MCP; exports are exports; jobs without a person are the system. */
	public static function channel(string $event, int $actorId): string {
		if ($event === 'ability.invoked') {
			return 'mcp';
		}
		if (str_starts_with($event, 'export.')) {
			return 'export';
		}
		if (str_starts_with($event, 'import.')) {
			return 'import';
		}

		return $actorId > 0 ? 'management' : 'system';
	}

	/**
	 * The tool name for ability calls (the ability "agent-brain/memory-write"
	 * is the MCP tool "agent-brain-memory-write"), else the event name.
	 */
	public static function action(string $event, string $objectId): string {
		$action = $event === 'ability.invoked' && $objectId !== '' ? str_replace('/', '-', $objectId) : (self::LEGACY_ACTIONS[$event] ?? $event);
		$action = strtolower((string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', $action));
		$action = ltrim($action, '0123456789_.-');
		$action = substr($action, 0, 64);

		return preg_match(self::ACTION_PATTERN, $action) === 1 ? $action : 'event';
	}

	/**
	 * @return array<int, string>
	 */
	public static function categories(string $event, string $objectId, string $objectType): array {
		if ($event === Exports::AUDIT_EVENT || $event === Restores::AUDIT_EVENT) {
			// What a full export bundles (SPEC 8.1 item 5), and so what a restore brings back.
			return self::EXPORT_CATEGORIES;
		}
		if ($event === Imports::AUDIT_EVENT) {
			// Pages, as proposals.
			return ['memory', 'review'];
		}
		if ($event === Moves::AUDIT_EVENT) {
			// Scopes with their pages, people and access.
			return ['memory', 'partition', 'principal', 'review'];
		}

		$subject = $event === 'ability.invoked' ? $objectId : $event . ' ' . $objectType;
		$subject = strtolower($subject);

		$rules = [
			'erasure'     => ['forget', 'erase'],
			'export'      => ['export.', 'backup.'],
			'review'      => ['review', 'proposal', 'approve', 'reject', 'publish'],
			'partition'   => ['scope'],
			'principal'   => ['agent.', 'agent_', 'user', 'principal', 'registration'],
			'key'         => ['backup.key'],
			'grant'       => ['grant.', 'app_password', 'oauth', 'token'],
			'ext.doc'     => ['artifact', 'section', 'stage'],
			'ext.handoff' => ['handoff'],
			'memory'      => ['memory', 'recall', 'knowledge', 'procedure', 'briefing', 'context', 'search', 'compression', 'rollover', 'decay', 'whoami', 'act-as'],
		];

		$out = [];
		foreach ($rules as $category => $needles) {
			foreach ($needles as $needle) {
				if (str_contains($subject, $needle)) {
					$out[] = $category;
					break;
				}
			}
		}

		sort($out, SORT_STRING);

		return $out;
	}

	public static function outcome(string $status): string {
		return match ($status) {
			'ok'     => 'ok',
			'denied' => 'forbidden',
			default  => 'internal',
		};
	}

	/**
	 * @param array<string, mixed> $meta
	 * @return array<string, int>|null
	 */
	public static function counts(array $meta): ?array {
		$counts = [];
		foreach (self::COUNT_KEYS as $key => $member) {
			if (isset($meta[$key]) && is_int($meta[$key]) && $meta[$key] >= 0 && ! isset($counts[$member])) {
				$counts[$member] = $meta[$key];
			}
		}

		if ($counts === []) {
			return null;
		}

		$ordered = [];
		foreach (['matched', 'returned', 'written', 'deleted'] as $member) {
			if (isset($counts[$member])) {
				$ordered[$member] = $counts[$member];
			}
		}

		return $ordered;
	}

	/**
	 * @param array<string, mixed>      $meta
	 * @param callable(int): ?string    $principal
	 * @param callable(string): ?string $byFqdn
	 * @return array<int, string>
	 */
	public static function objectIds(string $event, string $objectType, string $objectId, array $meta, callable $principal, callable $byFqdn): array {
		$ids = [];

		if ($event !== 'ability.invoked' && $objectId !== '') {
			if ($objectType === 'user' && ctype_digit($objectId)) {
				$id = $principal((int) $objectId);
				if ($id !== null) {
					$ids[] = $id;
				}
			} elseif ($objectType === 'ab_forget_receipt' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $objectId) === 1) {
				$ids[] = strtolower($objectId);
			} elseif (ctype_digit($objectId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $objectId) === 1) {
				$kind = self::kind($objectType);
				if ($kind !== null) {
					$ids[] = 'ab-' . $kind . '-' . strtolower($objectId);
				}
			}
		}

		if (isset($meta['fqdn']) && is_string($meta['fqdn']) && $meta['fqdn'] !== '') {
			$id = $byFqdn($meta['fqdn']);
			if ($id !== null) {
				$ids[] = $id;
			}
		}

		return array_values(array_unique(array_filter($ids, static fn (string $id): bool => preg_match(self::ID_PATTERN, $id) === 1)));
	}

	private static function kind(string $objectType): ?string {
		$kind = match ($objectType) {
			'ab_memory', 'memory'     => 'memory',
			'ab_knowledge'            => 'knowledge',
			'ab_procedure'            => 'procedure',
			'ab_forget_receipt'       => 'receipt',
			'ab_scope'                => 'scope',
			default                   => (string) preg_replace('/^ab_/', '', strtolower($objectType)),
		};

		return preg_match(self::KIND_PATTERN, $kind) === 1 ? $kind : null;
	}
}
