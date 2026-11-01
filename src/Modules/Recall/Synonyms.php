<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Recall;

/**
 * #64: cheap, safe query expansion. Pure lookup over a small synonym map
 * (synonym-map.php, filterable via `agent_brain/recall_synonyms`). Recall uses
 * the expansion only as a lower-weight any-keyword fallback; it never
 * replaces the caller's own terms.
 */
class Synonyms {

	/** Relevance multiplier applied to rows found only through a synonym. */
	public const WEIGHT = 0.5;

	/** Cap on added terms so a long query cannot fan out. */
	public const MAX_EXTRA = 8;

	/**
	 * @return array<int, string[]>
	 */
	public static function groups(): array {
		$groups = require __DIR__ . '/synonym-map.php';

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'agent_brain/recall_synonyms', $groups );
			if ( is_array( $filtered ) ) {
				$groups = $filtered;
			}
		}

		$clean = [];
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$terms = array_values( array_unique( array_filter( array_map(
				static fn ( $t ): string => is_string( $t ) ? mb_strtolower( trim( $t ), 'UTF-8' ) : '',
				$group
			), static fn ( string $t ): bool => $t !== '' ) ) );
			if ( count( $terms ) > 1 ) {
				$clean[] = $terms;
			}
		}

		return $clean;
	}

	/**
	 * Terms that are synonyms of any of $terms but not already in $terms.
	 *
	 * @param string[] $terms tokenized, lowercase query terms.
	 * @return string[]
	 */
	public static function expand( array $terms ): array {
		$have  = array_flip( $terms );
		$extra = [];

		foreach ( self::groups() as $group ) {
			if ( array_intersect( $group, $terms ) === [] ) {
				continue;
			}
			foreach ( $group as $candidate ) {
				if ( ! isset( $have[ $candidate ] ) && ! isset( $extra[ $candidate ] ) ) {
					$extra[ $candidate ] = true;
				}
			}
		}

		return array_slice( array_keys( $extra ), 0, self::MAX_EXTRA );
	}
}
