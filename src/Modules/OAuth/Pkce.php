<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * PKCE (RFC 7636) S256-only helpers. OAuth 2.1 removes the "plain" method
 * for public clients; we simply never implement it.
 */
final class Pkce {

	public const METHOD_S256 = 'S256';

	public static function isSupportedMethod(?string $method): bool {
		return $method === self::METHOD_S256;
	}

	/**
	 * Base64url-encoded SHA-256 digest of the verifier, per RFC 7636 §4.2.
	 */
	public static function challengeFromVerifier(string $verifier): string {
		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}

	public static function verify(string $verifier, string $expectedChallenge): bool {
		if ($verifier === '' || $expectedChallenge === '') {
			return false;
		}

		return hash_equals($expectedChallenge, self::challengeFromVerifier($verifier));
	}
}
