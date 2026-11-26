<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

use AgentBrain\Core\SecretBox;
use AgentBrain\Core\SecretBoxException;

/**
 * #990: one sealed credential per service. The public view never carries
 * the key, the refresh key or the app secret. Opening needs the site's
 * secret-box material and is for server code only.
 */
final class Vault {

	public const PURPOSE = 'integration';

	/**
	 * @return array<string, mixed>
	 */
	public static function blank(): array {
		return [
			'auth'            => '',
			'sealed'          => '',
			'refresh_sealed'  => '',
			'expires'         => 0,
			'account'         => '',
			'failing'         => false,
			'message'         => '',
			'reads'           => 0,
			'writes'          => 0,
			'window'          => 0,
			'window_count'    => 0,
			'limited_until'   => 0,
			'failures'        => 0,
			'last_test'       => 0,
			'connected_by'    => 0,
			'app_id'          => '',
			'app_secret'      => '',
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function normalize( array $row ): array {
		$out = self::blank();
		foreach ( $out as $key => $default ) {
			if ( ! array_key_exists( $key, $row ) ) {
				continue;
			}
			if ( is_bool( $default ) ) {
				$out[ $key ] = (bool) $row[ $key ];
			} elseif ( is_int( $default ) ) {
				$out[ $key ] = (int) $row[ $key ];
			} elseif ( is_string( $row[ $key ] ) ) {
				$out[ $key ] = $row[ $key ];
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function sealToken( array $row, string $token, string $material, int $by ): array {
		$row              = self::normalize( $row );
		$row['auth']      = 'token';
		$row['sealed']    = SecretBox::seal( trim( $token ), $material, self::PURPOSE );
		$row['refresh_sealed'] = '';
		$row['expires']   = 0;
		$row['connected_by'] = $by;

		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function sealOAuth( array $row, string $access, string $refresh, int $expires, string $material, int $by ): array {
		$row                   = self::normalize( $row );
		$row['auth']           = 'oauth';
		$row['sealed']         = SecretBox::seal( $access, $material, self::PURPOSE );
		$row['refresh_sealed'] = $refresh !== '' ? SecretBox::seal( $refresh, $material, self::PURPOSE . '-refresh' ) : '';
		$row['expires']        = $expires;
		$row['connected_by']   = $by;

		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function sealApp( array $row, string $clientId, string $secret, string $material, bool $keepSecret ): array {
		$row            = self::normalize( $row );
		$row['app_id']  = trim( $clientId );
		if ( ! $keepSecret ) {
			$row['app_secret'] = $secret !== '' ? SecretBox::seal( $secret, $material, self::PURPOSE . '-app' ) : '';
		}

		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function clearCredential( array $row ): array {
		$row                   = self::normalize( $row );
		$row['auth']           = '';
		$row['sealed']         = '';
		$row['refresh_sealed'] = '';
		$row['expires']        = 0;
		$row['account']        = '';
		$row['failing']        = false;
		$row['message']        = '';
		$row['limited_until']  = 0;
		$row['failures']       = 0;
		$row['connected_by']   = 0;

		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function open( array $row, string $material, ?string $previous = null ): ?string {
		$sealed = is_string( $row['sealed'] ?? null ) ? $row['sealed'] : '';

		return $sealed === '' ? null : self::unseal( $sealed, $material, $previous, self::PURPOSE );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function openAppSecret( array $row, string $material, ?string $previous = null ): ?string {
		$sealed = is_string( $row['app_secret'] ?? null ) ? $row['app_secret'] : '';

		return $sealed === '' ? null : self::unseal( $sealed, $material, $previous, self::PURPOSE . '-app' );
	}

	/**
	 * What a page, an ability or a log may see. No sealed field is copied.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function publicRow( array $row, string $id, string $name ): array {
		$row = self::normalize( $row );

		return [
			'id'             => $id,
			'name'           => $name,
			'connected'      => $row['sealed'] !== '',
			'auth'           => $row['sealed'] !== '' ? (string) $row['auth'] : '',
			'account'        => (string) $row['account'],
			'failing'        => (bool) $row['failing'],
			'message'        => (string) $row['message'],
			'reads'          => (int) $row['reads'],
			'writes'         => (int) $row['writes'],
			'limited_until'  => (int) $row['limited_until'],
			'last_test'      => (int) $row['last_test'],
			'app_configured' => (string) $row['app_id'] !== '' && (string) $row['app_secret'] !== '',
		];
	}

	/**
	 * True when $secret appears anywhere in a value that would be shown.
	 *
	 * @param array<string, mixed> $public
	 */
	public static function leaks( array $public, string $secret ): bool {
		if ( $secret === '' ) {
			return false;
		}
		$encoded = function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $public ) : (string) json_encode( $public );

		return str_contains( $encoded, $secret ) || str_contains( $encoded, 'ab1.' );
	}

	private static function unseal( string $sealed, string $material, ?string $previous, string $purpose ): ?string {
		try {
			return SecretBox::open( $sealed, $material, $purpose );
		} catch ( SecretBoxException ) {
			if ( $previous === null || $previous === '' ) {
				return null;
			}
			try {
				return SecretBox::open( $sealed, $previous, $purpose );
			} catch ( SecretBoxException ) {
				return null;
			}
		}
	}
}
