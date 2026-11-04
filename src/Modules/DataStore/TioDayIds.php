<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Admin\HomeWorkspace;

/**
 * #809: every TíoDay item has a short ID a person can quote to an AI: T12
 * for a task, D3 for a done item, C2 for a decision, F1 for work in flight.
 *
 * The store stamps it on the document, as `id`, the first time the document
 * is written in a TíoDay's tasks, done, decisions or inflight, whoever
 * writes it (a TíoDay tool, artifact-store-set, the page). The numbers come
 * from a counter per letter in meta/ids, so an ID is never given twice (an
 * in-flight item is deleted when its work lands, and its number stays
 * used), and a later write keeps it, even a replace that leaves `id` out.
 * Documents written before IDs existed get theirs, oldest first, the next
 * time their collection is given one.
 */
final class TioDayIds {

	public const FIELD = 'id';

	/** Collection => the letter of its IDs. */
	public const LETTERS = [
		'tasks'     => 'T',
		'done'      => 'D',
		'decisions' => 'C',
		'inflight'  => 'F',
	];

	/** Collection => its reference prefix, as comments use in `on`. */
	public const REFS = [
		'tasks'     => 't',
		'done'      => 'd',
		'decisions' => 'c',
		'inflight'  => 'f',
	];

	/** The counters: meta/ids, {T: 57, D: 210, ...}, the highest number given. */
	public const COUNTERS = 'ids';

	/** A short ID as people type it: T12, t12, T-12. */
	private const PATTERN = '/^\s*([TDCF])-?0*(\d{1,7})\s*$/i';

	private static bool $stamping = false;

	public static function register(): void {
		add_filter( 'agent_brain/store_value', [ self::class, 'stamp' ], 10, 6 );
	}

	/** Whether the store is numbering older documents right now (StoreMemory skips new memories then). */
	public static function backfilling(): bool {
		return self::$stamping;
	}

	/**
	 * agent_brain/store_value: give a TíoDay item its short ID, or keep the
	 * one it has.
	 *
	 * @param mixed                $value
	 * @param mixed                $before
	 * @param array<string, mixed> $by
	 * @return mixed
	 */
	public static function stamp( $value, int $artifactId, string $collection, string $key, $before, array $by ) {
		$letter = self::LETTERS[ $collection ] ?? null;
		if ( $letter === null || self::$stamping || ! self::isRecord( $value ) || ! self::isTioDay( $artifactId ) ) {
			return $value;
		}

		$kept = self::isRecord( $before ) ? self::number( $before[ self::FIELD ] ?? null, $letter ) : null;
		if ( $kept !== null ) {
			$value[ self::FIELD ] = $letter . $kept;

			return $value;
		}

		self::$stamping = true;
		try {
			$id = self::give( $artifactId, $collection, $key, $value, $by );
		} finally {
			self::$stamping = false;
		}

		if ( $id !== null ) {
			$value[ self::FIELD ] = $id;
		} else {
			unset( $value[ self::FIELD ] );
		}

		return $value;
	}

	/**
	 * The ID for $key: the one a restored document brings back when no
	 * other document has it, else the next number, after numbering the
	 * collection's older documents that have none.
	 *
	 * @param array<string, mixed> $value
	 * @param array<string, mixed> $by
	 */
	private static function give( int $artifactId, string $collection, string $key, array $value, array $by ): ?string {
		$letter = self::LETTERS[ $collection ];
		$docs   = StoreData::list( $artifactId, $collection );
		$used   = [];
		$older  = [];
		foreach ( $docs as $doc ) {
			if ( (string) $doc['key'] === $key || ! self::isRecord( $doc['value'] ) ) {
				continue;
			}
			$n = self::number( $doc['value'][ self::FIELD ] ?? null, $letter );
			if ( $n !== null ) {
				$used[ $n ] = true;
			} else {
				$older[] = $doc;
			}
		}

		$given = self::number( $value[ self::FIELD ] ?? null, $letter );
		if ( $given !== null && ! isset( $used[ $given ] ) && $given <= max( self::counter( $artifactId, $letter ), $used === [] ? 0 : max( array_keys( $used ) ) ) ) {
			return $letter . $given;
		}

		usort( $older, self::oldestFirst( $collection ) );
		$first = self::reserve( $artifactId, $letter, count( $older ) + 1, $used === [] ? 0 : max( array_keys( $used ) ), $by );
		if ( $first === null ) {
			return null;
		}

		$n = $first;
		foreach ( $older as $doc ) {
			$doc['value'][ self::FIELD ] = $letter . $n;
			// A document a parallel write changed meanwhile was numbered by that write.
			StoreData::write( $artifactId, $collection, (string) $doc['key'], $doc['value'], $by, 'update', [ 'patch' => [ self::FIELD => $letter . $n ] ], (int) $doc['version'] );
			++$n;
		}

		return $letter . $n;
	}

	/**
	 * Take $count numbers for $letter from meta/ids; the first of them, or
	 * null when the counter kept changing under us.
	 *
	 * @param array<string, mixed> $by
	 */
	private static function reserve( int $artifactId, string $letter, int $count, int $floor, array $by ): ?int {
		for ( $try = 0; $try < 5; $try++ ) {
			$doc      = StoreData::get( $artifactId, 'meta', self::COUNTERS );
			$counters = $doc !== null && self::isRecord( $doc['value'] ) ? $doc['value'] : [];
			$last     = max( $floor, is_numeric( $counters[ $letter ] ?? null ) ? (int) $counters[ $letter ] : 0 );

			$counters[ $letter ] = $last + $count;
			$stored              = StoreData::write( $artifactId, 'meta', self::COUNTERS, $counters, $by, '', null, $doc !== null ? (int) $doc['version'] : 0 );
			if ( ! $stored instanceof \WP_Error ) {
				return $last + 1;
			}
		}

		return null;
	}

	private static function counter( int $artifactId, string $letter ): int {
		$doc = StoreData::get( $artifactId, 'meta', self::COUNTERS );

		return $doc !== null && self::isRecord( $doc['value'] ) && is_numeric( $doc['value'][ $letter ] ?? null ) ? (int) $doc['value'][ $letter ] : 0;
	}

	/**
	 * Tasks by the day they were added, then their order; in flight by
	 * order; done and decisions by key (YYYY-MM-DD-NN).
	 *
	 * @return callable(array<string, mixed>, array<string, mixed>): int
	 */
	private static function oldestFirst( string $collection ): callable {
		return static function ( array $a, array $b ) use ( $collection ): int {
			$order = static fn ( array $doc ): int => is_numeric( $doc['value']['order'] ?? null ) ? (int) $doc['value']['order'] : PHP_INT_MAX;
			$added = static fn ( array $doc ): string => is_string( $doc['value']['added'] ?? null ) ? $doc['value']['added'] : '9999';

			switch ( $collection ) {
				case 'tasks':
					return [ $added( $a ), $order( $a ), $a['key'] ] <=> [ $added( $b ), $order( $b ), $b['key'] ];
				case 'inflight':
					return [ $order( $a ), $a['key'] ] <=> [ $order( $b ), $b['key'] ];
				default:
					return strcmp( (string) $a['key'], (string) $b['key'] );
			}
		};
	}

	// -----------------------------------------------------------------
	// Finding an item
	// -----------------------------------------------------------------

	/** Whether $text is a short ID (T12, d3, C-2). */
	public static function isId( string $text ): bool {
		return preg_match( self::PATTERN, $text ) === 1;
	}

	/** A short ID written the one way the store keeps it ("t-012" → "T12"), or null. */
	public static function normalize( string $text ): ?string {
		return preg_match( self::PATTERN, $text, $m ) === 1 ? strtoupper( $m[1] ) . (int) $m[2] : null;
	}

	/** The collection a short ID points into, or null. */
	public static function collectionOf( string $id ): ?string {
		$id = self::normalize( $id );
		$c  = $id !== null ? array_search( $id[0], self::LETTERS, true ) : false;

		return is_string( $c ) ? $c : null;
	}

	/**
	 * The item a reference names: a short ID (T12) or a key reference
	 * (t:<key>, d:<key>, c:<key>, f:<key>). Null when there is none.
	 *
	 * @return array{collection:string, key:string, ref:string, id:string|null, doc:array<string, mixed>}|null
	 */
	public static function find( int $artifactId, string $ref ): ?array {
		$ref = trim( $ref );
		$id  = self::normalize( $ref );
		if ( $id !== null ) {
			$collection = (string) self::collectionOf( $id );
			foreach ( StoreData::list( $artifactId, $collection ) as $doc ) {
				if ( self::isRecord( $doc['value'] ) && self::normalize( (string) ( $doc['value'][ self::FIELD ] ?? '' ) ) === $id ) {
					return self::found( $collection, $doc );
				}
			}

			return null;
		}

		if ( preg_match( '/^([tdcf]):(.+)$/', $ref, $m ) === 1 ) {
			$collection = (string) array_search( $m[1], self::REFS, true );
			$key        = StoreData::normalizeKey( $m[2] );
			$doc        = $key !== null ? StoreData::get( $artifactId, $collection, $key ) : null;

			return $doc !== null && self::isRecord( $doc['value'] ) ? self::found( $collection, $doc ) : null;
		}

		return null;
	}

	/**
	 * The key a tool's `slug` (or `clear_inflight`) names in $collection:
	 * the slug itself, or the key of the item with that short ID. Null when
	 * it is a short ID of no item there.
	 */
	public static function keyFor( int $artifactId, string $collection, string $slug ): ?string {
		if ( ! self::isId( $slug ) ) {
			return $slug;
		}
		if ( self::collectionOf( $slug ) !== $collection ) {
			return null;
		}
		$item = self::find( $artifactId, $slug );

		return $item !== null ? $item['key'] : null;
	}

	/**
	 * @param array<string, mixed> $doc
	 * @return array{collection:string, key:string, ref:string, id:string|null, doc:array<string, mixed>}
	 */
	private static function found( string $collection, array $doc ): array {
		$id = self::normalize( (string) ( $doc['value'][ self::FIELD ] ?? '' ) );

		return [
			'collection' => $collection,
			'key'        => (string) $doc['key'],
			'ref'        => self::REFS[ $collection ] . ':' . $doc['key'],
			'id'         => $id,
			'doc'        => $doc,
		];
	}

	// -----------------------------------------------------------------
	// Shapes
	// -----------------------------------------------------------------

	/**
	 * The number of an ID of $letter ("T12" → 12), or null.
	 *
	 * @param mixed $id
	 */
	private static function number( $id, string $letter ): ?int {
		if ( ! is_string( $id ) || preg_match( self::PATTERN, $id, $m ) !== 1 || strtoupper( $m[1] ) !== $letter || (int) $m[2] <= 0 ) {
			return null;
		}

		return (int) $m[2];
	}

	/** @param mixed $value */
	private static function isRecord( $value ): bool {
		return is_array( $value ) && $value !== [] && ! array_is_list( $value );
	}

	private static function isTioDay( int $artifactId ): bool {
		return $artifactId > 0 && (int) get_post_meta( $artifactId, HomeWorkspace::META_OWNER, true ) > 0;
	}
}
