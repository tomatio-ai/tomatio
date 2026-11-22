<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Sharing\MembersAddress;

/**
 * #891 (615 section 626): linked stores. A page can read a collection of
 * another page's store, such as a TíoDay reading its team's backlog items,
 * without a copy. The link is set per page by a curator
 * (`wp tomatio backlog link-tioday`): meta ab_store_links maps an alias to
 * the other artifact, and the page names the collection "<alias>--<name>"
 * (window.tomatio.store.list('project--items')).
 *
 * Read only, and for named viewers only: the viewer must be able to see
 * the other page's members address, its store must be on, and its own
 * rules for that collection must let everyone who can see it read it.
 * Anonymous links never read through a link. Writes through a link come
 * later (step 3) and are refused.
 */
final class StoreLinks {

	public const META      = 'ab_store_links';
	public const SEPARATOR = '--';

	private const ALIAS = '/^[a-z][a-z0-9]{0,15}$/';

	/**
	 * The linked artifact and collection a page's collection name means,
	 * or null when it isn't a link of $artifactId.
	 *
	 * @return array{0:int, 1:string}|null
	 */
	public static function split( int $artifactId, string $name ): ?array {
		$at = strpos( $name, self::SEPARATOR );
		if ( $at === false || $at === 0 ) {
			return null;
		}
		$alias      = substr( $name, 0, $at );
		$collection = StoreData::normalizeCollection( (string) substr( $name, $at + strlen( self::SEPARATOR ) ) );
		$target     = self::all( $artifactId )[ $alias ] ?? 0;
		if ( $collection === null || $target <= 0 || $target === $artifactId ) {
			return null;
		}

		return [ $target, $collection ];
	}

	/**
	 * Whether viewer $principal may read $collection of $target through a link.
	 */
	public static function canRead( int $principal, int $target, string $collection ): bool {
		if ( $principal <= 0 || ! StoreMode::isActive( $target ) || ! MembersAddress::canSeeAddress( $principal, $target ) ) {
			return false;
		}
		$rules = StoreMode::rules( $target, $collection );

		return $rules !== null && in_array( 'read', $rules['ops'], true ) && $rules['visibility'] === 'all';
	}

	/** @return array<string, int> alias => artifact id */
	public static function all( int $artifactId ): array {
		$raw = get_post_meta( $artifactId, self::META, true );
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $alias => $target ) {
			if ( is_string( $alias ) && preg_match( self::ALIAS, $alias ) === 1 && (int) $target > 0 ) {
				$out[ $alias ] = (int) $target;
			}
		}

		return $out;
	}

	/** Link $artifactId's alias to $target (0 removes it). False for a bad alias. */
	public static function set( int $artifactId, string $alias, int $target ): bool {
		if ( preg_match( self::ALIAS, $alias ) !== 1 || $target === $artifactId ) {
			return false;
		}
		$all = self::all( $artifactId );
		if ( $target > 0 ) {
			$all[ $alias ] = $target;
		} else {
			unset( $all[ $alias ] );
		}
		update_post_meta( $artifactId, self::META, $all );

		return true;
	}
}
