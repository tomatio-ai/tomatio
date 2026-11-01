<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * The person behind the current request when an agent acts on their
 * authority: a manager chose "Connect this AI as <agent>" on the consent
 * screen, so the access token belongs to the agent and records who
 * authorized it. OAuth\Bearer sets this per request; memory writes and the
 * audit log stamp it as "via", so a shared role agent (one designer used by
 * several people) still shows which person was driving.
 *
 * 0 when the request is not delegated (the token's own user is a person, or
 * an application password / cookie session is in use).
 */
final class AuthorizedBy {

	private static int $userId = 0;

	public static function set(int $userId): void {
		self::$userId = max(0, $userId);
	}

	public static function id(): int {
		return self::$userId;
	}

	public static function reset(): void {
		self::$userId = 0;
	}
}
