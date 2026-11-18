<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentRegistration;

/**
 * The registration key (docs/rfc/agent-id-fqdn.md section 6.1). Pure helpers:
 * generate, hash, shape check and the failed-attempt window. The plaintext is
 * returned to the caller once and never stored, logged or audited.
 */
final class RegistrationKey {

	public const PREFIX = 'abreg_';

	public const MAX_FAILURES   = 5;
	public const FAILURE_WINDOW = 3600;

	/** Failures per client bucket and window for keys that do not exist. */
	public const MAX_UNKNOWN_FAILURES = 20;
	public const UNKNOWN_BUCKETS      = 64;

	/** 32 random bytes, base64url without padding = 43 characters. */
	private const BODY_LENGTH = 43;

	public static function generate(): string {
		return self::PREFIX . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	public static function hash( string $key ): string {
		return hash( 'sha256', $key );
	}

	/** True when the value has the shape of a registration key (not that it is valid). */
	public static function looksLikeKey( string $value ): bool {
		return strlen( $value ) === strlen( self::PREFIX ) + self::BODY_LENGTH
			&& strncmp( $value, self::PREFIX, strlen( self::PREFIX ) ) === 0
			&& preg_match( '/^[A-Za-z0-9_-]+$/', substr( $value, strlen( self::PREFIX ) ) ) === 1;
	}

	/**
	 * Pure lockout rule. $state is the stored failure window (count and the
	 * unix time of its first failure) or null.
	 *
	 * @param array{count:int, start:int}|null $state
	 */
	public static function isLocked( ?array $state, int $now, int $max = self::MAX_FAILURES ): bool {
		return $state !== null
			&& $state['count'] >= $max
			&& $now - $state['start'] < self::FAILURE_WINDOW;
	}

	/**
	 * Pure: the window after one more failure. A window older than the limit
	 * restarts.
	 *
	 * @param array{count:int, start:int}|null $state
	 * @return array{count:int, start:int}
	 */
	public static function afterFailure( ?array $state, int $now ): array {
		if ( $state === null || $now - $state['start'] >= self::FAILURE_WINDOW ) {
			return [ 'count' => 1, 'start' => $now ];
		}

		return [ 'count' => $state['count'] + 1, 'start' => $state['start'] ];
	}
}
