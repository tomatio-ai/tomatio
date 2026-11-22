<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

use AgentBrain\Core\Config;
use AgentBrain\Core\SecretBox;

/**
 * #990: the brain's integrations, one row per service. The sealed key
 * is never autoloaded onto the front of the site, and bearer() is for
 * server code only. Abilities and the settings page use publicRow().
 */
final class Store {

	public const OPTION = 'ab_integrations';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, [] );
		$raw = is_array( $raw ) ? $raw : [];
		$out = [];
		foreach ( $raw as $id => $row ) {
			if ( is_string( $id ) && is_array( $row ) ) {
				$out[ $id ] = Vault::normalize( $row );
			}
		}

		return $out;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function row( string $id ): array {
		$all = self::all();

		return $all[ $id ] ?? Vault::blank();
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function save( string $id, array $row ): void {
		$all        = self::all();
		$all[ $id ] = Vault::normalize( $row );
		update_option( self::OPTION, $all, false );
	}

	public static function bearer( string $id ): ?string {
		return Vault::open( self::row( $id ), SecretBox::currentMaterial(), SecretBox::previousMaterial() );
	}

	/**
	 * The OAuth app for a service: wp-config.php constants win (hosted),
	 * otherwise the sealed pair a curator saved (self-hosted).
	 *
	 * @param array<string, mixed> $row
	 * @return array{id: string, secret: string, from_config: bool}
	 */
	public static function app( string $id, array $row ): array {
		$name   = 'INTEGRATION_' . strtoupper( str_replace( '-', '_', $id ) );
		$client = Config::string( $name . '_CLIENT_ID' );
		$secret = Config::string( $name . '_CLIENT_SECRET' );
		if ( $client !== '' && $secret !== '' ) {
			return [ 'id' => $client, 'secret' => $secret, 'from_config' => true ];
		}

		return [
			'id'          => (string) ( $row['app_id'] ?? '' ),
			'secret'      => (string) ( Vault::openAppSecret( $row, SecretBox::currentMaterial(), SecretBox::previousMaterial() ) ?? '' ),
			'from_config' => false,
		];
	}

	/**
	 * Count a read. Returns the seconds to wait when the service is limited,
	 * otherwise 0. Does not return the key.
	 */
	public static function noteRead( string $id ): int {
		$spec = ( new Catalog() )->get( $id );
		if ( $spec === null ) {
			return 0;
		}
		$row  = self::row( $id );
		$now  = time();
		$wait = Limiter::wait( $row, $spec, $now );
		if ( $wait > 0 ) {
			return $wait;
		}
		self::save( $id, Limiter::countRead( $row, $now ) );

		return 0;
	}

	/**
	 * A write to the service, for context and planning sync to call.
	 * Audited once; the key is not part of the event.
	 */
	public static function noteWrite( string $id, int $actorId ): void {
		$row            = self::row( $id );
		$row['writes']  = (int) $row['writes'] + 1;
		self::save( $id, $row );
		\AgentBrain\Core\Hooks::action( 'audit', [
			'actor_id'    => $actorId,
			'event'       => 'integration.write',
			'status'      => 'ok',
			'object_type' => 'integration',
			'object_id'   => $id,
		] );
	}

	/**
	 * Names of connected services that are failing, for the settings dot.
	 *
	 * @return array<int, string>
	 */
	public static function failingLabels(): array {
		$catalog = new Catalog();
		$out     = [];
		foreach ( self::all() as $id => $row ) {
			if ( empty( $row['failing'] ) || ( $row['sealed'] ?? '' ) === '' ) {
				continue;
			}
			$spec  = $catalog->get( $id );
			$out[] = $spec !== null ? (string) $spec['name'] : $id;
		}

		return $out;
	}
}
