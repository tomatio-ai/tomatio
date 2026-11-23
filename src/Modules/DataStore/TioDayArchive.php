<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

/**
 * T376: a TíoDay's lists never fill up. When done, decisions, closed tasks
 * or comments reach the store's document cap, the items older than
 * KEEP_DAYS move into the page's `archive` collection, so the next write
 * goes through. When too few are that old, the oldest AT_LEAST movable
 * ones go instead. An open task never moves.
 *
 * The archive keeps each item whole, under "<collection>.<key>", with its
 * short ID (D12 stays D12). The page does not load it (it is not one of
 * the page's collections), so the page stays as fast as before; #1058: it
 * reads older entries a page at a time (StoreArchive, "Load older"); the tools
 * still read it: tomatio-tioday-get finds an archived item by its ID and
 * an archived day by its date. The brain memory of an archived item stays.
 */
final class TioDayArchive {

	public const COLLECTION = StoreArchive::COLLECTION;

	/** Items newer than this many days stay on the page. */
	public const KEEP_DAYS = 30;

	/** When too few items are old enough, at least this many move. */
	public const AT_LEAST = 50;

	/** What may roll over. */
	public const ROLLS = [ 'done', 'decisions', 'tasks', 'comments' ];

	/** The archive's caps on a TíoDay (the defaults are 500 documents and 1 MiB); #1058: StoreArchive's. */
	public const CAPS = StoreArchive::CAPS;

	private static bool $moving = false;

	public static function register(): void {
		add_action( 'tomatio/store_full', [ self::class, 'onFull' ], 10, 2 );
	}

	/** Whether an item is moving into the archive right now (StoreMemory keeps its memory then). */
	public static function moving(): bool {
		return self::$moving;
	}

	/**
	 * Room for years of a TíoDay's archive (#1058: StoreArchive::caps, which
	 * gives any page's server-only archive the same).
	 *
	 * @param mixed $caps
	 * @return mixed
	 */
	public static function caps( $caps, int $artifactId, string $collection ) {
		return StoreArchive::caps( $caps, $artifactId, $collection );
	}

	/** tomatio/store_full: make room in a full TíoDay list. */
	public static function onFull( int $artifactId, string $collection ): void {
		self::makeRoom( $artifactId, $collection );
	}

	/**
	 * $collection of TíoDay $artifactId is full; move its older items
	 * into the archive.
	 *
	 * @return int How many items moved.
	 */
	public static function makeRoom( int $artifactId, string $collection, ?int $now = null ): int {
		if ( ! in_array( $collection, self::ROLLS, true ) || ! TioDayIds::isTioDay( $artifactId ) ) {
			return 0;
		}

		$movable = [];
		foreach ( StoreData::list( $artifactId, $collection ) as $doc ) {
			$day = self::day( $collection, $doc );
			if ( $day !== null ) {
				$movable[] = [ 'day' => $day, 'doc' => $doc ];
			}
		}
		usort( $movable, static fn ( array $a, array $b ): int => [ $a['day'], (string) $a['doc']['key'] ] <=> [ $b['day'], (string) $b['doc']['key'] ] );

		$cutoff = gmdate( 'Y-m-d', ( $now ?? time() ) - self::KEEP_DAYS * DAY_IN_SECONDS );
		$old    = array_values( array_filter( $movable, static fn ( array $item ): bool => $item['day'] < $cutoff ) );
		$move   = count( $old ) >= self::AT_LEAST ? $old : array_slice( $movable, 0, self::AT_LEAST );

		$moved = 0;
		foreach ( $move as $item ) {
			if ( self::move( $artifactId, $collection, $item['doc'] ) ) {
				++$moved;
			}
		}

		return $moved;
	}

	/**
	 * An archived item by short ID (D12) or reference (d:<key>), shaped as
	 * TioDayIds::find shapes a live one. Null when the archive has none.
	 *
	 * @return array{collection:string, key:string, ref:string, id:string|null, doc:array<string, mixed>}|null
	 */
	public static function find( int $artifactId, string $ref ): ?array {
		$ref = trim( $ref );
		$id  = TioDayIds::normalize( $ref );
		if ( $id !== null ) {
			$collection = (string) TioDayIds::collectionOf( $id );
			foreach ( StoreData::list( $artifactId, self::COLLECTION ) as $doc ) {
				if ( str_starts_with( (string) $doc['key'], $collection . '.' ) && is_array( $doc['value'] ) && TioDayIds::normalize( (string) ( $doc['value'][ TioDayIds::FIELD ] ?? '' ) ) === $id ) {
					return self::found( $collection, $doc );
				}
			}

			return null;
		}

		if ( preg_match( '/^([tdcf]):(.+)$/', $ref, $m ) !== 1 ) {
			return null;
		}
		$collection = (string) array_search( $m[1], TioDayIds::REFS, true );
		$doc        = StoreData::get( $artifactId, self::COLLECTION, $collection . '.' . trim( $m[2] ) );

		return $doc !== null && is_array( $doc['value'] ) ? self::found( $collection, $doc ) : null;
	}

	/**
	 * The archived items of $collection dated $date, each its value with
	 * its original `key`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function onDay( int $artifactId, string $collection, string $date ): array {
		$out    = [];
		$prefix = $collection . '.';
		foreach ( StoreData::list( $artifactId, self::COLLECTION ) as $doc ) {
			$key = (string) $doc['key'];
			if ( str_starts_with( $key, $prefix ) && is_array( $doc['value'] ) && ( $doc['value']['date'] ?? '' ) === $date ) {
				$out[] = [ 'key' => substr( $key, strlen( $prefix ) ) ] + $doc['value'];
			}
		}

		return $out;
	}

	/**
	 * The day an item counts from, or null when it may not move (an open
	 * task, or an item with no day).
	 *
	 * @param array<string, mixed> $doc
	 */
	private static function day( string $collection, array $doc ): ?string {
		$value = is_array( $doc['value'] ) ? $doc['value'] : [];
		switch ( $collection ) {
			case 'tasks':
				$day = ( $value['status'] ?? 'open' ) === 'done' ? (string) ( $value['done_on'] ?? '' ) : '';
				break;
			case 'comments':
				$day = substr( (string) ( $value['at'] ?? '' ), 0, 10 );
				break;
			default:
				$day = (string) ( $value['date'] ?? substr( (string) $doc['key'], 0, 10 ) );
		}

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) === 1 ? $day : null;
	}

	/**
	 * Copy one item into the archive, then delete it from its collection.
	 *
	 * @param array<string, mixed> $doc
	 */
	private static function move( int $artifactId, string $collection, array $doc ): bool {
		$by    = [ 'anonymous' => false, 'type' => 'system', 'id' => null, 'name' => 'TíoDay archive' ];
		$key   = (string) $doc['key'];
		$value = is_array( $doc['value'] ) ? $doc['value'] + [ 'archived_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ] : $doc['value'];

		$stored = StoreData::set( $artifactId, self::COLLECTION, $collection . '.' . $key, $value, $by );
		if ( $stored instanceof \WP_Error ) {
			return false;
		}

		self::$moving = true;
		try {
			return StoreData::delete( $artifactId, $collection, $key, null, $by );
		} finally {
			self::$moving = false;
		}
	}

	/**
	 * @param array<string, mixed> $doc
	 * @return array{collection:string, key:string, ref:string, id:string|null, doc:array<string, mixed>}
	 */
	private static function found( string $collection, array $doc ): array {
		$key        = substr( (string) $doc['key'], strlen( $collection ) + 1 );
		$doc['key'] = $key;

		return [
			'collection' => $collection,
			'key'        => $key,
			'ref'        => TioDayIds::REFS[ $collection ] . ':' . $key,
			'id'         => is_array( $doc['value'] ) ? TioDayIds::normalize( (string) ( $doc['value'][ TioDayIds::FIELD ] ?? '' ) ) : null,
			'doc'        => $doc,
		];
	}
}
