<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

/**
 * #885, #1088: which TíoDay items are about the same issue or pull request.
 * One matcher for the Merge log (a merged pull request clears its in-flight
 * item), tomatio-tioday-done (a done item clears the in-flight item that
 * links the same one) and the tracker pass (TioDaySelfClose).
 *
 * An address is reduced to "host/owner/name#number" when it is an issue or
 * a pull request ("/issues/12", "/pulls/12", "/pull/12"): the two share
 * their numbers, so either spelling matches.
 */
final class TioDayLinks {

	/** What an address points at, as "host/owner/name#number"; null for any other address. */
	public static function ref( string $url ): ?string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( $host === '' || preg_match( '#^/(.+?)/(?:issues|pulls|pull)/(\d+)/?$#i', $path, $m ) !== 1 ) {
			return null;
		}

		return $host . '/' . strtolower( $m[1] ) . '#' . (int) $m[2];
	}

	/**
	 * The issues and pull requests a list of links ([{label, url}]) points at.
	 *
	 * @param mixed $links
	 * @return string[]
	 */
	public static function refs( $links ): array {
		$out = [];
		foreach ( is_array( $links ) ? $links : [] as $link ) {
			$ref = is_array( $link ) && is_string( $link['url'] ?? null ) ? self::ref( $link['url'] ) : null;
			if ( $ref !== null && ! in_array( $ref, $out, true ) ) {
				$out[] = $ref;
			}
		}

		return $out;
	}

	/**
	 * The documents in $collection with a link to any of $refs.
	 *
	 * @param string[] $refs
	 * @return array<int, array<string, mixed>>
	 */
	public static function docs( int $artifactId, string $collection, array $refs ): array {
		$out = [];
		if ( $refs === [] ) {
			return $out;
		}
		foreach ( StoreData::list( $artifactId, $collection ) as $doc ) {
			$links = is_array( $doc['value'] ) ? ( $doc['value']['links'] ?? null ) : null;
			if ( array_intersect( self::refs( $links ), $refs ) !== [] ) {
				$out[] = $doc;
			}
		}

		return $out;
	}

	/**
	 * Keys of the documents in $collection with a link to any of $refs.
	 *
	 * @param string[] $refs
	 * @return string[]
	 */
	public static function linking( int $artifactId, string $collection, array $refs ): array {
		return array_map( static fn ( array $doc ): string => (string) $doc['key'], self::docs( $artifactId, $collection, $refs ) );
	}
}
