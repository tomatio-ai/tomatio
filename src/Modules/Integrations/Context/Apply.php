<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Context;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Provenance\OutsideSources;

/**
 * #991: write the brain's copy of a read, and drop a copy the service
 * no longer has. Never calls the service.
 */
final class Apply {

	/**
	 * @param array{items: array<int, array{id: string, title: string, url: string, text: string}>, truncated: bool} $read
	 * @param array<string, array<string, mixed>> $copies
	 * @return array{copies: array<string, array{memory_id: int, source_id: int, hash: string}>, stored: int, removed: int}
	 */
	public static function commit( int $scopeId, int $actorId, string $service, array $read, array $copies, string $readAt ): array {
		$complete = empty( $read['truncated'] );
		$diff     = Read::diff( $read['items'], $copies, $scopeId, $complete );
		$next     = $complete ? [] : $copies;
		$stored   = 0;
		foreach ( $diff['upsert'] as $row ) {
			$id       = (string) $row['id'];
			$item     = is_array( $row['item'] ) ? $row['item'] : [];
			$memoryId = self::saveMemory( $scopeId, $actorId, $item, $readAt, (int) $row['memory_id'] );
			$sourceId = (int) $row['source_id'];
			if ( ! empty( $row['changed'] ) || $sourceId <= 0 ) {
				$sourceId = self::registerSource( $actorId, $service, $item, $readAt, (string) $row['hash'] );
			} else {
				self::touchSource( $sourceId, $actorId, $readAt );
			}
			( new OutsideSources() )->link( 'memory', $memoryId, [ $sourceId ] );
			$next[ $id ] = [
				'memory_id' => $memoryId,
				'source_id' => $sourceId,
				'hash'      => (string) $row['hash'],
			];
			++$stored;
		}
		$removed = 0;
		foreach ( $diff['remove'] as $row ) {
			if ( self::hide( (int) $row['memory_id'], $scopeId ) ) {
				++$removed;
			}
		}
		if ( ! $complete ) {
			foreach ( $copies as $id => $copy ) {
				if ( ! isset( $next[ $id ] ) && is_array( $copy ) ) {
					$next[ $id ] = [
						'memory_id' => (int) ( $copy['memory_id'] ?? 0 ),
						'source_id' => (int) ( $copy['source_id'] ?? 0 ),
						'hash'      => (string) ( $copy['hash'] ?? '' ),
					];
				}
			}
		}

		return [
			'copies'  => $next,
			'stored'  => $stored,
			'removed' => $removed,
		];
	}

	/**
	 * Drop every copy of a pick. Does not call the service.
	 *
	 * @param array<string, array<string, mixed>> $copies
	 */
	public static function drop( int $scopeId, array $copies ): int {
		$n = 0;
		foreach ( $copies as $copy ) {
			if ( is_array( $copy ) && self::hide( (int) ( $copy['memory_id'] ?? 0 ), $scopeId ) ) {
				++$n;
			}
		}

		return $n;
	}

	/**
	 * @param array{id?: string, title?: string, url?: string, text?: string} $item
	 */
	private static function saveMemory( int $scopeId, int $actorId, array $item, string $readAt, int $memoryId ): int {
		$content = Read::cite( $item, $readAt );
		$keys    = mb_substr( trim( (string) ( $item['title'] ?? '' ) ), 0, 200 );
		if ( $memoryId > 0 && self::rewrite( $memoryId, $scopeId, $content, $keys ) ) {
			return $memoryId;
		}
		$repo = new MemoryRepository();

		return $repo->insert( [
			'agent_id'      => $actorId,
			'scope_id'      => $scopeId,
			'type'          => 'fact',
			'content'       => $content,
			'search_keys'   => $keys,
			'importance'    => 40,
			'status'        => 'active',
			'agent_written' => 0,
		] );
	}

	/**
	 * @param array{title?: string, url?: string, text?: string} $item
	 */
	private static function registerSource( int $actorId, string $service, array $item, string $readAt, string $hash ): int {
		$title = trim( (string) ( $item['title'] ?? '' ) );
		if ( $title === '' ) {
			$title = $service === Read::LINEAR ? 'Linear issue' : 'Notion page';
		}
		$url = trim( (string) ( $item['url'] ?? '' ) );
		if ( $url === '' ) {
			$url = $service . ':' . (string) ( $item['text'] ?? $title );
		}

		return ( new OutsideSources() )->register( [
			'kind'         => 'url',
			'title'        => $title,
			'locator'      => $url,
			'publisher'    => $service === Read::LINEAR ? 'Linear' : 'Notion',
			'retrieved_at' => $readAt,
			'hash'         => $hash,
			'agent_id'     => $actorId,
		] );
	}

	private static function touchSource( int $sourceId, int $actorId, string $readAt ): void {
		global $wpdb;

		if ( $sourceId <= 0 || ! isset( $wpdb ) ) {
			return;
		}
		$table = OutsideSources::sourcesTable();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query( Db::prepare(
			"UPDATE {$table} SET retrieved_at = %s WHERE id = %d AND agent_id = %d",
			[ $readAt, $sourceId, $actorId ]
		) );
		// phpcs:enable
	}

	private static function rewrite( int $memoryId, int $scopeId, string $content, string $keys ): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$table = MemoryRepository::memoryTable();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$found = $wpdb->get_var( Db::prepare(
			"SELECT id FROM {$table} WHERE id = %d AND scope_id = %d",
			[ $memoryId, $scopeId ]
		) );
		if ( $found === null ) {
			return false;
		}
		$wpdb->query( Db::prepare(
			"UPDATE {$table} SET content = %s, search_keys = %s, status = %s WHERE id = %d AND scope_id = %d",
			[ $content, $keys, 'active', $memoryId, $scopeId ]
		) );
		// phpcs:enable

		return true;
	}

	private static function hide( int $memoryId, int $scopeId ): bool {
		global $wpdb;

		if ( $memoryId <= 0 || $scopeId <= 0 || ! isset( $wpdb ) ) {
			return false;
		}
		$table = MemoryRepository::memoryTable();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$done = $wpdb->query( Db::prepare(
			"UPDATE {$table} SET status = %s WHERE id = %d AND scope_id = %d",
			[ 'deleted', $memoryId, $scopeId ]
		) );
		// phpcs:enable

		return $done !== false && (int) $done > 0;
	}
}
