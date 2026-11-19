<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Core\Db;
use InvalidArgumentException;

/**
 * Data access for derived-from links (ab_derived_from), PROV-1 (#170).
 *
 * An item produced from other items keeps a list of its sources, as
 * identifiers only — {kind, id, version?} per source, never content
 * (epic #169, design artifact 73: record identifiers, versions and hashes,
 * never copies of content):
 *
 *  - a rolled-up or summarised memory lists the rows it replaced (that
 *    predates this table: raw-memory -> raw-memory links live in
 *    ab_memory_links, see MemoryRepository::link());
 *  - knowledge proposed from an assembled artifact lists the artifact and
 *    the section versions it was assembled from;
 *  - a compiled (or assembled) post lists the artifact and the section
 *    versions it was built from;
 *  - an imported page keeps its original id, brain and principal in its
 *    x_import frontmatter (Portability), not here.
 *
 * The table is generic over the item (item_kind: 'memory' | 'post') and
 * the source (source_kind: 'memory' | 'section' | 'artifact') so the
 * PROV-4 provenance panel and PCP export read one shape. Sections carry
 * their ab_version at the moment the item was built; unversioned kinds
 * store NULL. Links are idempotent per (item, source) and keep citation
 * order via position.
 */
class DerivedFrom {

	public const ITEM_KINDS   = [ 'memory', 'post' ];
	public const SOURCE_KINDS = [ 'memory', 'section', 'artifact' ];

	public static function table(): string {
		return Db::table( 'derived_from' );
	}

	/**
	 * Link $sources to an item. Idempotent: re-linking an existing pair is a
	 * no-op, and new links are appended after the current highest position
	 * so build order is stable.
	 *
	 * @param array<int, array{kind:string, id:int, version?:int}> $sources
	 */
	public function link( string $itemKind, int $itemId, array $sources ): void {
		global $wpdb;

		self::assertItemKind( $itemKind );
		$sources = self::sanitizeSources( $sources );
		if ( $itemId <= 0 || $sources === [] ) {
			return;
		}

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		foreach ( $sources as $source ) {
			$position = (int) $wpdb->get_var( Db::prepare(
				"SELECT COALESCE(MAX(position), 0) + 1 FROM {$table} WHERE item_kind = %s AND item_id = %d",
				[ $itemKind, $itemId ]
			) );

			$version       = $source['version'] ?? null;
			$versionColumn = $version === null ? 'NULL' : '%d';

			$params = [ $itemKind, $itemId, $source['kind'], $source['id'] ];
			if ( $version !== null ) {
				$params[] = $version;
			}
			$params[] = $position;

			$wpdb->query( Db::prepare(
				"INSERT IGNORE INTO {$table} (item_kind, item_id, source_kind, source_id, source_version, position) VALUES (%s, %d, %s, %d, {$versionColumn}, %d)",
				$params
			) );
		}
		// phpcs:enable
	}

	/**
	 * Replace an item's derived-from links with exactly $sources (delete
	 * all, re-link in order). Used when an item is REBUILT from a fresh set
	 * of sources (compile() updating an existing compiled draft), where
	 * append would leave stale sources in place.
	 *
	 * @param array<int, array{kind:string, id:int, version?:int}> $sources
	 */
	public function replace( string $itemKind, int $itemId, array $sources ): void {
		global $wpdb;

		self::assertItemKind( $itemKind );
		$sources = self::sanitizeSources( $sources );

		if ( $itemId <= 0 ) {
			return;
		}

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query( Db::prepare(
			"DELETE FROM {$table} WHERE item_kind = %s AND item_id = %d",
			[ $itemKind, $itemId ]
		) );
		// phpcs:enable

		$this->link( $itemKind, $itemId, $sources );
	}

	/**
	 * The sources an item was derived from, in build (position) order.
	 *
	 * @return array<int, array{kind:string, id:int, version:?int}>
	 */
	public function sourcesOf( string $itemKind, int $itemId ): array {
		self::assertItemKind( $itemKind );

		$rows = Db::getResults(
			'SELECT source_kind, source_id, source_version FROM ' . self::table() . ' WHERE item_kind = %s AND item_id = %d ORDER BY position, source_kind, source_id',
			[ $itemKind, $itemId ]
		);

		return array_map(
			static fn( array $row ): array => [
				'kind'    => (string) $row['source_kind'],
				'id'      => (int) $row['source_id'],
				'version' => $row['source_version'] === null ? null : (int) $row['source_version'],
			],
			$rows
		);
	}

	/**
	 * Every item a source feeds (the reverse direction; kept for the
	 * provenance panel, PROV-4, and PCP export batching).
	 *
	 * @return array<int, array{item_kind:string, item_id:int}>
	 */
	public function itemsOf( string $sourceKind, int $sourceId ): array {
		self::assertSourceKind( $sourceKind );

		$rows = Db::getResults(
			'SELECT item_kind, item_id FROM ' . self::table() . ' WHERE source_kind = %s AND source_id = %d ORDER BY item_kind, item_id',
			[ $sourceKind, $sourceId ]
		);

		return array_map(
			static fn( array $row ): array => [
				'item_kind' => (string) $row['item_kind'],
				'item_id'   => (int) $row['item_id'],
			],
			$rows
		);
	}

	private static function assertItemKind( string $itemKind ): void {
		if ( ! in_array( $itemKind, self::ITEM_KINDS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static enum list, not user output.
			throw new InvalidArgumentException( 'itemKind must be one of: ' . implode( ', ', self::ITEM_KINDS ) );
		}
	}

	private static function assertSourceKind( string $sourceKind ): void {
		if ( ! in_array( $sourceKind, self::SOURCE_KINDS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static enum list, not user output.
			throw new InvalidArgumentException( 'sourceKind must be one of: ' . implode( ', ', self::SOURCE_KINDS ) );
		}
	}

	/**
	 * Identifiers only: kind whitelisted, id a positive int, version an
	 * optional positive int. Anything else is dropped (a link is a hint
	 * for provenance, never a write gate).
	 *
	 * @param array<int, mixed> $sources raw, untrusted link rows (the runtime
	 * guards below exist because the keys may be missing or malformed).
	 * @return array<int, array{kind:string, id:int, version?:int}>
	 */
	private static function sanitizeSources( array $sources ): array {
		$out  = [];
		$seen = [];

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			$kind = (string) ( $source['kind'] ?? '' );
			$id   = (int) ( $source['id'] ?? 0 );
			if ( $id <= 0 || ! in_array( $kind, self::SOURCE_KINDS, true ) ) {
				continue;
			}

			$key = $kind . ':' . $id;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$entry = [ 'kind' => $kind, 'id' => $id ];

			$version = isset( $source['version'] ) ? (int) $source['version'] : null;
			if ( $version !== null && $version > 0 ) {
				$entry['version'] = $version;
			}

			$out[] = $entry;
		}

		return $out;
	}
}
