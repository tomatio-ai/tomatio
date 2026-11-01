<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

/**
 * Per-agent read/write scope lists (PRD ID-4). Scopes are terms in the
 * private `ab_scope` taxonomy; each agent's allowed read/write scope term
 * IDs live in the `ab_read_scopes` / `ab_write_scopes` user meta keys.
 *
 * Fail closed everywhere: missing meta, corrupted meta, or any internal
 * error resolves to "no access" rather than throwing.
 */
class Scopes {

	public const TAXONOMY        = 'ab_scope';
	public const META_READ_SCOPES  = 'ab_read_scopes';
	public const META_WRITE_SCOPES = 'ab_write_scopes';

	/**
	 * @return array<int, int>
	 */
	public function readScopeIds(int $userId): array {
		return $this->scopeIdsFromMeta($userId, self::META_READ_SCOPES);
	}

	/**
	 * @return array<int, int>
	 */
	public function writeScopeIds(int $userId): array {
		return $this->scopeIdsFromMeta($userId, self::META_WRITE_SCOPES);
	}

	public function canRead(int $userId, int $scopeId): bool {
		return in_array($scopeId, $this->readScopeIds($userId), true);
	}

	public function canWrite(int $userId, int $scopeId): bool {
		return in_array($scopeId, $this->writeScopeIds($userId), true);
	}

	/**
	 * Grant a scope to a user's read or write list. Requires the acting
	 * user to hold ab_grant_scopes and the term to exist in ab_scope.
	 * Emits agent_brain/audit either way. Fails closed (returns false) on
	 * any invalid input.
	 */
	public function grant(int $actingUserId, int $targetUserId, string $list, int $scopeId): bool {
		return $this->mutate('grant', $actingUserId, $targetUserId, $list, $scopeId);
	}

	public function revoke(int $actingUserId, int $targetUserId, string $list, int $scopeId): bool {
		return $this->mutate('revoke', $actingUserId, $targetUserId, $list, $scopeId);
	}

	/**
	 * Grant made by the plugin itself (actor 0), only for the default team
	 * scope (DefaultScope). Skips the acting-user check; audited like any
	 * other grant.
	 */
	public function grantBySystem(int $targetUserId, string $list, int $scopeId): bool {
		return $this->mutate('grant', 0, $targetUserId, $list, $scopeId, true);
	}

	private function mutate(string $action, int $actingUserId, int $targetUserId, string $list, int $scopeId, bool $system = false): bool {
		$metaKey = $this->metaKeyForList($list);

		if ($metaKey === null) {
			$this->audit($action, $actingUserId, $targetUserId, $scopeId, 'error');

			return false;
		}

		if (! $system && ! $this->actingUserCanGrantScopes($actingUserId)) {
			$this->audit($action, $actingUserId, $targetUserId, $scopeId, 'denied');

			return false;
		}

		if (! $this->scopeTermExists($scopeId)) {
			$this->audit($action, $actingUserId, $targetUserId, $scopeId, 'error');

			return false;
		}

		$current = $this->scopeIdsFromMeta($targetUserId, $metaKey);

		if ($action === 'grant') {
			if (! in_array($scopeId, $current, true)) {
				$current[] = $scopeId;
			}
		} else {
			$current = array_values(array_filter($current, static fn (int $id): bool => $id !== $scopeId));
		}

		$updated = function_exists('update_user_meta')
			? update_user_meta($targetUserId, $metaKey, array_values(array_unique($current)))
			: false;

		if ($updated === false && function_exists('update_user_meta')) {
			// update_user_meta() returns false both on failure and when the
			// stored value is unchanged; treat "unchanged" as success by
			// re-reading rather than assuming failure.
			$updated = $this->scopeIdsFromMeta($targetUserId, $metaKey) === array_values(array_unique($current));
		}

		$this->audit($action, $actingUserId, $targetUserId, $scopeId, $updated ? 'ok' : 'error');

		return (bool) $updated;
	}

	private function metaKeyForList(string $list): ?string {
		return match ($list) {
			'read'  => self::META_READ_SCOPES,
			'write' => self::META_WRITE_SCOPES,
			default => null,
		};
	}

	private function actingUserCanGrantScopes(int $actingUserId): bool {
		if (! function_exists('user_can')) {
			return false;
		}

		try {
			return (bool) user_can($actingUserId, Capabilities::CAP_GRANT_SCOPES);
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function scopeTermExists(int $scopeId): bool {
		if (! function_exists('get_term')) {
			return false;
		}

		try {
			$term = get_term($scopeId, self::TAXONOMY);

			return $term !== null && ! is_wp_error($term) && $term !== false;
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * @return array<int, int>
	 */
	private function scopeIdsFromMeta(int $userId, string $metaKey): array {
		if (! function_exists('get_user_meta')) {
			return [];
		}

		try {
			$raw = get_user_meta($userId, $metaKey, true);
		} catch (\Throwable $e) {
			return [];
		}

		if (! is_array($raw)) {
			return [];
		}

		$ids = [];
		foreach ($raw as $value) {
			if (is_int($value) || (is_string($value) && ctype_digit($value))) {
				$ids[] = (int) $value;
			}
			// Any non-integer-like entry is dropped rather than causing a
			// fatal or partial-trust result: corrupted meta degrades to
			// "whatever we could safely parse", never to "allow all".
		}

		return array_values(array_unique($ids));
	}

	private function audit(string $action, int $actingUserId, int $targetUserId, int $scopeId, string $status): void {
		if (! function_exists('do_action')) {
			return;
		}

		do_action('agent_brain/audit', [
			'actor_id'    => $actingUserId,
			'event'       => 'scope_' . $action,
			'status'      => $status,
			'object_type' => self::TAXONOMY,
			'object_id'   => (string) $scopeId,
			'meta'        => ['target_user_id' => $targetUserId],
		]);
	}
}
