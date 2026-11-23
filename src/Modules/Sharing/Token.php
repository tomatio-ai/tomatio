<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * Share-link token primitives (pure, no WordPress): 128-bit CSPRNG token in
 * base64url, stored only as a sha256 hex digest.
 */
final class Token {

	public const BYTES  = 16;
	public const LENGTH = 22;

	public static function generate(): string {
		return rtrim( strtr( base64_encode( random_bytes( self::BYTES ) ), '+/', '-_' ), '=' );
	}

	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	public static function isWellFormed( string $token ): bool {
		return preg_match( '/^[A-Za-z0-9_-]{' . self::LENGTH . '}$/', $token ) === 1;
	}
}
