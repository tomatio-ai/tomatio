<?php

declare(strict_types=1);

namespace AgentBrain\Modules\ScopesAdmin;

/**
 * Pure inversion of "agent -> scopes" into "scope -> agents" for the Scopes
 * screen (no WordPress calls, unit-testable).
 */
final class Holders {

	/**
	 * @param array<int, array{read: array<int, int>, write: array<int, int>}> $byAgent agent id => scope ids per list.
	 * @return array<int, array{read: array<int, int>, write: array<int, int>}> scope id => agent ids per list (sorted ascending).
	 */
	public static function invert(array $byAgent): array {
		$out = [];

		foreach ($byAgent as $agentId => $lists) {
			foreach (['read', 'write'] as $list) {
				foreach ($lists[$list] as $scopeId) {
					$out[(int) $scopeId] ??= ['read' => [], 'write' => []];
					$out[(int) $scopeId][$list][] = (int) $agentId;
				}
			}
		}

		foreach ($out as $scopeId => $lists) {
			sort($out[$scopeId]['read']);
			sort($out[$scopeId]['write']);
		}

		return $out;
	}

	/**
	 * Distinct agent ids holding a scope in either list.
	 *
	 * @param array{read: array<int, int>, write: array<int, int>} $holders
	 * @return array<int, int>
	 */
	public static function agents(array $holders): array {
		return array_values(array_unique(array_merge($holders['read'], $holders['write'])));
	}
}
