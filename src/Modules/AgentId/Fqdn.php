<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentId;

/**
 * The agent ID standard (docs/rfc/agent-id-fqdn.md section 2):
 *
 *   {agent_name}-{register_id}.{org}.{base}
 *   claude-code-a3f2c819.agnt.brain.example.org
 *
 * Pure functions, no WordPress calls, so it can be unit-tested and reused by
 * every module (registration, status gate, admin screens, briefing).
 */
final class Fqdn {

	public const NAME_MIN = 3;
	public const NAME_MAX = 32;

	private const PATTERN = '/^(?<name>[a-z][a-z0-9-]{1,30}[a-z0-9])-(?<id>[0-9a-f]{8})\.(?<org>[a-z][a-z0-9-]{1,31})\.(?<base>[a-z0-9]([a-z0-9.-]*[a-z0-9])?)$/';

	/**
	 * Trim, lowercase and drop one trailing dot.
	 */
	public static function normalize( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( substr( $value, -1 ) === '.' ) {
			$value = substr( $value, 0, -1 );
		}

		return $value;
	}

	/**
	 * @return array{name:string, register_id:string, org:string, base:string, login:string, fqdn:string}|null
	 */
	public static function parse( string $fqdn ): ?array {
		$fqdn = self::normalize( $fqdn );

		if ( $fqdn === '' || strlen( $fqdn ) > 253 ) {
			return null;
		}

		foreach ( explode( '.', $fqdn ) as $label ) {
			if ( $label === '' || strlen( $label ) > 63 ) {
				return null;
			}
		}

		if ( preg_match( self::PATTERN, $fqdn, $m ) !== 1 ) {
			return null;
		}

		if ( strpos( $m['name'], '--' ) !== false ) {
			return null;
		}

		return [
			'name'        => $m['name'],
			'register_id' => $m['id'],
			'org'         => $m['org'],
			'base'        => $m['base'],
			'login'       => $m['name'] . '-' . $m['id'],
			'fqdn'        => $fqdn,
		];
	}

	public static function isValid( string $fqdn ): bool {
		return self::parse( $fqdn ) !== null;
	}

	/**
	 * True only when the value is already canonical (normalizing changes
	 * nothing) and valid. Use for caller-supplied "canonical" values.
	 */
	public static function isCanonical( string $fqdn ): bool {
		return $fqdn === self::normalize( $fqdn ) && self::isValid( $fqdn );
	}

	public static function isValidName( string $name ): bool {
		$len = strlen( $name );

		return $len >= self::NAME_MIN
			&& $len <= self::NAME_MAX
			&& preg_match( '/^[a-z][a-z0-9-]*[a-z0-9]$/', $name ) === 1
			&& strpos( $name, '--' ) === false;
	}

	public static function isValidRegisterId( string $id ): bool {
		return preg_match( '/^[0-9a-f]{8}$/', $id ) === 1;
	}

	public static function isValidOrg( string $org ): bool {
		return preg_match( '/^[a-z][a-z0-9-]{1,31}$/', $org ) === 1;
	}

	/**
	 * Build the FQDN, or null when any part is invalid.
	 */
	public static function build( string $name, string $registerId, string $org, string $base ): ?string {
		$base = self::normalize( $base );

		if ( ! self::isValidName( $name ) || ! self::isValidRegisterId( $registerId ) || ! self::isValidOrg( $org ) ) {
			return null;
		}

		$fqdn = $name . '-' . $registerId . '.' . $org . '.' . $base;

		return self::isValid( $fqdn ) ? $fqdn : null;
	}

	/**
	 * 8 lowercase hex chars from a CSPRNG.
	 */
	public static function generateRegisterId(): string {
		return bin2hex( random_bytes( 4 ) );
	}

	/**
	 * The WordPress login for an agent: the FQDN's first label.
	 */
	public static function login( string $fqdn ): ?string {
		$parsed = self::parse( $fqdn );

		return $parsed === null ? null : $parsed['login'];
	}

	/**
	 * Turn a display name ("Flow Validation") into a valid agent_name slug,
	 * or null when nothing valid can be made of it.
	 */
	public static function slug( string $display ): ?string {
		$slug = strtolower( trim( $display ) );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( $slug, '-' );
		$slug = (string) preg_replace( '/^[^a-z]+/', '', $slug );
		$slug = substr( $slug, 0, self::NAME_MAX );
		$slug = rtrim( $slug, '-' );

		return self::isValidName( $slug ) ? $slug : null;
	}
}
