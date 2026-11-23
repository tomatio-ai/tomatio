<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Context;

use AgentBrain\Core\Hooks;
use AgentBrain\Modules\Integrations\LinearTarget;
use AgentBrain\Modules\Integrations\Planning\Pull;

/**
 * #991: preview, store, refresh and stop. Preview never writes a copy.
 * Store uses the scope saved at preview, not a scope posted later.
 */
final class Sync {

	/**
	 * @return array{ok: bool, text: string}
	 */
	public static function preview( string $service, string $kind, string $rawLocator, int $scopeId, int $actorId, string $scopeName ): array {
		if ( ! Read::kindOk( $service, $kind ) ) {
			return self::no( 'That is not a kind this service can read.' );
		}
		$locator = Read::locator( $service, $rawLocator );
		if ( $locator === '' && $service === Read::LINEAR && trim( $rawLocator ) !== '' ) {
			// B231: a team key, issue identifier, link or name is looked up.
			$found = LinearTarget::resolve( $rawLocator, $kind, static fn ( string $method, string $url, ?array $body ): array|string => Pull::dispatch( Read::LINEAR, $method, $url, $body ) );
			if ( $found['error'] !== '' ) {
				return self::no( $found['error'] );
			}
			$locator = $found['id'];
			$kind    = $found['kind'];
		}
		if ( $locator === '' ) {
			return self::no( 'Paste a page, database, team or project link or id.' );
		}
		if ( $scopeId <= 0 ) {
			return self::no( 'Pick a scope. The read belongs to one scope.' );
		}
		$existing = Library::find( $service, $kind, $locator );
		if ( $existing !== null && ( $existing['status'] ?? '' ) === 'stored' ) {
			$name = (string) ( $existing['scope_name'] ?? 'another scope' );

			return self::no( 'Already reading that into ' . $name . '. Stop it first to move it, or use Refresh now.' );
		}
		$read = Fetch::live( $service, $kind, $locator );
		if ( is_string( $read ) ) {
			return self::no( $read );
		}
		$summary = Read::summary( $read['items'], $scopeName, $read['truncated'] );
		$pick    = [
			'id'         => is_array( $existing ) ? (string) $existing['id'] : Library::newId(),
			'service'    => $service,
			'kind'       => $kind,
			'locator'    => $locator,
			'scope_id'   => $scopeId,
			'scope_name' => $scopeName,
			'actor_id'   => $actorId,
			'status'     => 'preview',
			'summary'    => $summary,
			'copies'     => [],
			'read_at'    => '',
		];
		Library::save( $pick );
		self::emit( 'integration.context_preview', $service, $actorId, (string) $pick['id'], $scopeId, true );

		return self::yes( Read::previewWords( $summary, $service ) );
	}

	/**
	 * @return array{ok: bool, text: string}
	 */
	public static function store( string $pickId, int $actorId ): array {
		$pick = Library::get( $pickId );
		if ( $pick === null || Read::storeTarget( $pick ) === null ) {
			return self::no( 'Preview a source first. Nothing was stored.' );
		}

		return self::write( $pick, $actorId, false );
	}

	/**
	 * @param array<string, mixed> $pick
	 * @return array{ok: bool, text: string}
	 */
	public static function refresh( array $pick ): array {
		if ( ( $pick['status'] ?? '' ) !== 'stored' ) {
			return self::no( 'That source is not stored yet.' );
		}

		return self::write( $pick, (int) ( $pick['actor_id'] ?? 0 ), true );
	}

	/**
	 * @return array{ok: bool, text: string}
	 */
	public static function remove( string $pickId, int $actorId ): array {
		$pick = Library::get( $pickId );
		if ( $pick === null ) {
			return self::no( 'That source is not on this brain.' );
		}
		$scopeId = (int) ( $pick['scope_id'] ?? 0 );
		$copies  = is_array( $pick['copies'] ?? null ) ? $pick['copies'] : [];
		Apply::drop( $scopeId, $copies );
		Library::remove( $pickId );
		self::emit( 'integration.context_remove', (string) ( $pick['service'] ?? '' ), $actorId, $pickId, $scopeId, true );

		return self::yes( 'Stopped reading that source. Nothing was written to the service.' );
	}

	/**
	 * @param array<string, mixed> $pick
	 * @return array{ok: bool, text: string}
	 */
	private static function write( array $pick, int $actorId, bool $refresh ): array {
		if ( $actorId <= 0 ) {
			$actorId = (int) ( $pick['actor_id'] ?? 0 );
		}
		if ( $actorId <= 0 ) {
			return self::no( 'This source has no owner, so it was not stored.' );
		}
		$service = (string) ( $pick['service'] ?? '' );
		$read    = Fetch::live( $service, (string) ( $pick['kind'] ?? '' ), (string) ( $pick['locator'] ?? '' ) );
		if ( is_string( $read ) ) {
			self::emit( $refresh ? 'integration.context_refresh' : 'integration.context_store', $service, $actorId, (string) ( $pick['id'] ?? '' ), (int) ( $pick['scope_id'] ?? 0 ), false );

			return self::no( $read . ' Nothing was changed.' );
		}
		$readAt  = gmdate( 'Y-m-d H:i:s' );
		$copies  = is_array( $pick['copies'] ?? null ) ? $pick['copies'] : [];
		$scopeId = (int) $pick['scope_id'];
		$result  = Apply::commit( $scopeId, $actorId, $service, $read, $copies, $readAt );
		$summary = Read::summary( $read['items'], (string) ( $pick['scope_name'] ?? '' ), $read['truncated'] );
		$pick['status']     = 'stored';
		$pick['actor_id']   = $actorId;
		$pick['summary']    = $summary;
		$pick['copies']     = $result['copies'];
		$pick['read_at']    = $readAt;
		$pick['truncated']  = $read['truncated'];
		Library::save( $pick );
		self::emit( $refresh ? 'integration.context_refresh' : 'integration.context_store', $service, $actorId, (string) $pick['id'], $scopeId, true );

		return self::yes( self::storedWords( $summary, $service, $result['removed'], $refresh ) );
	}

	/**
	 * @param array{count: int, scope: string, truncated?: bool} $summary
	 */
	private static function storedWords( array $summary, string $service, int $removed, bool $refresh ): string {
		$n    = (int) $summary['count'];
		$what = $service === Read::LINEAR
			? ( $n === 1 ? 'issue' : 'issues' )
			: ( $n === 1 ? 'page' : 'pages' );
		$verb = $refresh ? 'Refreshed' : 'Stored';
		$line = $verb . ' ' . $n . ' ' . $what . ' in ' . (string) $summary['scope'] . '.';
		if ( ! empty( $summary['truncated'] ) ) {
			$line .= ' The read stopped early, so nothing unseen was removed.';
		} elseif ( $removed > 0 ) {
			$line .= ' ' . $removed . ' no longer on the service ' . ( $removed === 1 ? 'was' : 'were' ) . ' removed here.';
		}

		return $line . ' Nothing was written to the service.';
	}

	/**
	 * Each event name is a literal in `'event' => '...'` so the audit-label
	 * scan sees it. A variable there is invisible to that scan.
	 */
	private static function emit( string $event, string $service, int $actorId, string $pickId, int $scopeId, bool $ok ): void {
		$base = [
			'actor_id'    => $actorId,
			'status'      => $ok ? 'ok' : 'error',
			'object_type' => 'integration',
			'object_id'   => $service,
			'meta'        => [
				'pick'     => $pickId,
				'scope_id' => $scopeId,
			],
		];
		if ( $event === 'integration.context_preview' ) {
			Hooks::action( 'audit', [ 'event' => 'integration.context_preview' ] + $base );

			return;
		}
		if ( $event === 'integration.context_store' ) {
			Hooks::action( 'audit', [ 'event' => 'integration.context_store' ] + $base );

			return;
		}
		if ( $event === 'integration.context_refresh' ) {
			Hooks::action( 'audit', [ 'event' => 'integration.context_refresh' ] + $base );

			return;
		}
		Hooks::action( 'audit', [ 'event' => 'integration.context_remove' ] + $base );
	}

	/**
	 * @return array{ok: bool, text: string}
	 */
	private static function yes( string $text ): array {
		return [ 'ok' => true, 'text' => $text ];
	}

	/**
	 * @return array{ok: bool, text: string}
	 */
	private static function no( string $text ): array {
		return [ 'ok' => false, 'text' => $text ];
	}
}
