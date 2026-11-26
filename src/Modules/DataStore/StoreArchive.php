<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

/**
 * #1058: any page's `archive` collection, where older items of its lists
 * live under "<collection>.<key>" (TioDayArchive fills a TíoDay's).
 *
 * Caps: an archive the page cannot write (it is not one of the page's
 * collections, or its rules allow no write) is server-only, so it gets
 * room for years (CAPS). One the page may write keeps the defaults, so a
 * page never gets a 64 MiB collection of its own. A TíoDay's archive
 * always gets CAPS, as before.
 *
 * Reads: a page that does not list `archive` may still read the archived
 * items of a collection it may read in full ('read' in its ops, visibility
 * "all"), and only those: by key ("<c>.<key>") or by a page `_query` with
 * the prefix "<c>.". The archived documents are shown as that collection's
 * would be. A page that lists `archive` itself gets its own rules there.
 */
final class StoreArchive {

	public const COLLECTION = 'archive';

	/** The caps of a server-only archive (the defaults are 500 documents and 1 MiB). */
	public const CAPS = [
		'docs'  => 20000,
		'bytes' => 67108864,
	];

	private const WRITES = [ 'create', 'replace', 'update', 'delete', 'increment', 'append' ];

	public static function register(): void {
		add_filter( 'tomatio/store_caps', [ self::class, 'caps' ], 10, 3 );
	}

	/**
	 * tomatio/store_caps.
	 *
	 * @param mixed $caps
	 * @return mixed
	 */
	public static function caps( $caps, int $artifactId, string $collection ) {
		if ( $collection !== self::COLLECTION ) {
			return $caps;
		}

		return TioDayIds::isTioDay( $artifactId ) || ! self::pageWritable( $artifactId ) ? self::CAPS : $caps;
	}

	/** Whether the page of $artifactId may write its `archive` collection. */
	public static function pageWritable( int $artifactId ): bool {
		$rules = StoreMode::rules( $artifactId, self::COLLECTION );

		return $rules !== null && array_intersect( self::WRITES, $rules['ops'] ) !== [];
	}

	/**
	 * The rules of the collection an archive key or prefix ("<c>." ...)
	 * belongs to, when the page may read that collection in full; null
	 * otherwise (then the archive stays closed to the page).
	 *
	 * @return array{0:string, 1:array<string, mixed>}|null [the source collection, its rules]
	 */
	public static function source( int $artifactId, string $keyOrPrefix ): ?array {
		$at = strpos( $keyOrPrefix, '.' );
		if ( $at === false || $at === 0 ) {
			return null;
		}
		$collection = StoreData::normalizeCollection( substr( $keyOrPrefix, 0, $at ) );
		if ( $collection === null || $collection === self::COLLECTION || $collection !== substr( $keyOrPrefix, 0, $at ) ) {
			return null;
		}
		$rules = StoreMode::rules( $artifactId, $collection );
		if ( $rules === null || ! in_array( 'read', $rules['ops'], true ) || $rules['visibility'] !== 'all' ) {
			return null;
		}

		return [ $collection, $rules ];
	}
}
