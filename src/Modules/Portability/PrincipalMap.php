<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * User ids to principal ids for one export, read from the registry
 * (Principals::exportIdentity()) and cached. Ids are never derived here: a
 * live user the brain's data names but who has no id yet gets one through
 * Principals::ensure() first, the same way PageIndex assigns a missing page;
 * a deleted user resolves through the registry (status removed), or not at
 * all when no id was ever issued.
 */
final class PrincipalMap {

	/** @var array<int, ?string> */
	private array $ids = [];

	public function idFor(int $userId): ?string {
		if ($userId <= 0) {
			return null;
		}

		if (array_key_exists($userId, $this->ids)) {
			return $this->ids[$userId];
		}

		$identity = Principals::exportIdentity($userId);
		$id       = $identity['id'] ?? null;

		if ($id === null && get_userdata($userId) !== false) {
			$id = Principals::ensure($userId, true);
		}

		$this->ids[$userId] = $id !== null && Names::isPrincipal($id) ? $id : null;

		return $this->ids[$userId];
	}

	/**
	 * The principal holding an agent FQDN (Principals::byFqdn()), or null.
	 */
	public function idForFqdn(string $fqdn): ?string {
		$row = Principals::byFqdn($fqdn);

		return $row !== null && Names::isPrincipal($row['principal_id']) ? $row['principal_id'] : null;
	}
}
