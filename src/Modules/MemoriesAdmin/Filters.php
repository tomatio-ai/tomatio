<?php

declare(strict_types=1);

namespace AgentBrain\Modules\MemoriesAdmin;

/**
 * Pure sanitiser for the Memories screen's GET filters (no WordPress calls so
 * it is unit-testable). Anything invalid collapses to "no filter".
 */
final class Filters {

	public const TYPES    = ['working', 'episode', 'fact'];
	public const STATUSES = ['active', 'rolled_up', 'proposed', 'deleted'];

	/**
	 * @param array<string, mixed> $raw Already-unslashed request values.
	 * @return array{q:string, scope_id:int, agent_id:int, type:string, status:string, imp_min:int, imp_max:int, date_from:string, date_to:string}
	 */
	public static function fromArray(array $raw): array {
		$impMin = self::clampInt($raw['imp_min'] ?? null, 0, 100, 0);
		$impMax = self::clampInt($raw['imp_max'] ?? null, 0, 100, 100);

		if ($impMin > $impMax) {
			[$impMin, $impMax] = [$impMax, $impMin];
		}

		$type   = is_string($raw['type'] ?? null) ? $raw['type'] : '';
		$status = is_string($raw['status'] ?? null) ? $raw['status'] : '';

		$q = is_string($raw['q'] ?? null) ? trim($raw['q']) : '';

		return [
			'q'         => substr($q, 0, 200),
			'scope_id'  => max(0, (int) ($raw['scope_id'] ?? 0)),
			'agent_id'  => max(0, (int) ($raw['agent_id'] ?? 0)),
			'type'      => in_array($type, self::TYPES, true) ? $type : '',
			'status'    => in_array($status, self::STATUSES, true) ? $status : '',
			'imp_min'   => $impMin,
			'imp_max'   => $impMax,
			'date_from' => self::date($raw['date_from'] ?? null),
			'date_to'   => self::date($raw['date_to'] ?? null),
		];
	}

	/**
	 * UTC "Y-m-d H:i:s" stamp as ISO 8601 with Z (e.g. 2026-10-21T12:00:00Z).
	 */
	public static function iso(string $mysqlUtc): string {
		$ts = strtotime($mysqlUtc . ' UTC');

		return $ts === false ? '' : gmdate('Y-m-d\\TH:i:s\\Z', $ts);
	}

	/**
	 * @param mixed $value
	 */
	private static function clampInt($value, int $min, int $max, int $default): int {
		if (! is_scalar($value) || ! preg_match('/^-?\d+$/', (string) $value)) {
			return $default;
		}

		return max($min, min($max, (int) $value));
	}

	/**
	 * @param mixed $value
	 */
	private static function date($value): string {
		if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
			return '';
		}

		return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
	}
}
