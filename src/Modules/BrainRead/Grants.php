<?php

declare(strict_types=1);

namespace AgentBrain\Modules\BrainRead;

/**
 * Pure grant resolution for the brain-read endpoints (ART-25, #165), kept
 * free of WordPress so it is unit-testable:
 *
 * - the data boundary is exactly the artifact's one scope; nothing else is
 *   ever queried.
 * - known viewer: the viewer's read grants intersected with the artifact's
 *   scope — a member without a read grant on it gets no scopes (fail
 *   closed, empty results, never an existence-probing error).
 * - anonymous viewer: the artifact's share policy — the artifact's scope,
 *   read-only (see docs/design-art-25-brain-read.md "Grant model").
 *
 * Callers pass the resolved ids in; reading user meta / post meta is the
 * caller's job (Route).
 */
final class Grants {

	/**
	 * @param int[]|null $viewerReadScopeIds The known viewer's read scope ids, or null for an anonymous viewer.
	 * @param int        $artifactScopeId    The artifact's scope (ab_scope_id), 0 when unset.
	 * @return array{allowed_scope_ids:array<int,int>, scope_granted:bool, anonymous:bool}
	 */
	public static function resolve( ?array $viewerReadScopeIds, int $artifactScopeId ): array {
		if ( $artifactScopeId <= 0 ) {
			return [ 'allowed_scope_ids' => [], 'scope_granted' => false, 'anonymous' => $viewerReadScopeIds === null ];
		}

		if ( $viewerReadScopeIds === null ) {
			// Anonymous fallback: the artifact's share policy is "the artifact
			// and the scope it was built from", readable by any link holder.
			return [ 'allowed_scope_ids' => [ $artifactScopeId ], 'scope_granted' => true, 'anonymous' => true ];
		}

		$granted = in_array( $artifactScopeId, array_map( 'intval', $viewerReadScopeIds ), true );

		return [
			'allowed_scope_ids' => $granted ? [ $artifactScopeId ] : [],
			'scope_granted'     => $granted,
			'anonymous'         => false,
		];
	}
}
