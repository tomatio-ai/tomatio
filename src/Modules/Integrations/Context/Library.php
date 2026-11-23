<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Context;

/**
 * #991: the curator's picks. One row per source, in one option that is
 * not autoloaded. Item bodies are not stored here.
 */
final class Library {

	public const OPTION = 'ab_context_sources';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return [];
		}
		$raw = get_option( self::OPTION, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $id => $pick ) {
			if ( ! is_string( $id ) || ! is_array( $pick ) ) {
				continue;
			}
			$pick['id'] = $id;
			$out[ $id ] = $pick;
		}

		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();

		return $all[ $id ] ?? null;
	}

	/**
	 * @param array<string, mixed> $pick
	 */
	public static function save( array $pick ): void {
		$id = (string) ( $pick['id'] ?? '' );
		if ( $id === '' || ! function_exists( 'update_option' ) ) {
			return;
		}
		$all         = self::all();
		$pick['id']  = $id;
		$all[ $id ]  = $pick;
		update_option( self::OPTION, $all, false );
	}

	public static function remove( string $id ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find( string $service, string $kind, string $locator ): ?array {
		foreach ( self::all() as $pick ) {
			if ( ( $pick['service'] ?? '' ) === $service && ( $pick['kind'] ?? '' ) === $kind && ( $pick['locator'] ?? '' ) === $locator ) {
				return $pick;
			}
		}

		return null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function forService( string $service ): array {
		$out = [];
		foreach ( self::all() as $pick ) {
			if ( ( $pick['service'] ?? '' ) === $service ) {
				$out[] = $pick;
			}
		}

		return $out;
	}

	public static function newId(): string {
		return 'c' . bin2hex( random_bytes( 4 ) );
	}
}
