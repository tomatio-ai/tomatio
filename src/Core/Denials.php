<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * §1.4 (artifact-sharing-design.md): one shared permission-denied message so
 * every denial across the plugin (ArtifactService::forbidden(),
 * Abilities::denied() — already matches this string, see that class's own
 * docblock — plus new share/embed gates added by later units) reads
 * identically instead of drifting into a dozen near-duplicate literals.
 *
 * PHP class constants cannot call `__()` (not a constant expression), so
 * PERMISSION_DENIED holds the untranslated source string and
 * permissionDenied() is the call site every consumer actually uses.
 */
final class Denials {

	public const PERMISSION_DENIED = 'You do not have permission to do this.';

	public static function permissionDenied(): string {
		return __( self::PERMISSION_DENIED, 'agent-brain' );
	}
}
