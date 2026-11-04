<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Recall;

/**
 * REC-2: ranking = weighted blend of normalized FULLTEXT relevance, recency
 * decay and importance, with a fixed boost for approved knowledge so it
 * outranks raw memory at equal relevance/recency/importance.
 *
 * Weights are documented here and filterable via
 * `agent_brain/recall_ranking_weights` ( array $weights ) — the filtered
 * array is merged over the defaults key-by-key, so a filter only needs to
 * override the keys it cares about.
 */
class Ranker {

	/**
	 * Default weights, summing to 1.0:
	 * - relevance:       normalized (min-max within the current result batch) FULLTEXT/LIKE score.
	 * - recency:         0.5 ^ (age_days / half_life_days), see recencyScore().
	 * - importance:      the row's 0-100 importance, normalized to 0-1.
	 * - knowledge_boost: fixed 1.0 for any item that is approved ('private') knowledge/procedure, else 0.0.
	 */
	public const DEFAULT_WEIGHTS = [
		'relevance'       => 0.50,
		'recency'         => 0.25,
		'importance'      => 0.15,
		'knowledge_boost' => 0.10,
	];

	public const DEFAULT_HALF_LIFE_DAYS = 14;
	private const SECONDS_PER_DAY       = 86400;

	/**
	 * @return array<string, float>
	 */
	public static function weights(): array {
		$weights = self::DEFAULT_WEIGHTS;

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'agent_brain/recall_ranking_weights', $weights );

			if ( is_array( $filtered ) ) {
				foreach ( array_keys( $weights ) as $key ) {
					if ( isset( $filtered[ $key ] ) && is_numeric( $filtered[ $key ] ) ) {
						$weights[ $key ] = (float) $filtered[ $key ];
					}
				}
			}
		}

		return $weights;
	}

	public static function halfLifeDays(): float {
		if ( ! function_exists( 'get_option' ) ) {
			return (float) self::DEFAULT_HALF_LIFE_DAYS;
		}

		$days = (float) get_option( 'ab_recall_half_life_days', self::DEFAULT_HALF_LIFE_DAYS );

		return $days > 0.0 ? $days : (float) self::DEFAULT_HALF_LIFE_DAYS;
	}

	/**
	 * Exponential recency decay in (0, 1]: 1.0 at age 0, 0.5 at one half-life,
	 * 0.25 at two half-lives, etc. A blank/unparseable created timestamp is
	 * treated as "now" (no unfair penalty for missing data).
	 */
	public static function recencyScore( string $createdGmt, ?int $nowTimestamp = null ): float {
		$now     = $nowTimestamp ?? time();
		$created = $createdGmt !== '' ? strtotime( $createdGmt . ' UTC' ) : false;
		$created = $created !== false ? $created : $now;

		$ageDays  = max( 0.0, ( $now - $created ) / self::SECONDS_PER_DAY );
		$halfLife = self::halfLifeDays();

		return (float) ( 0.5 ** ( $ageDays / $halfLife ) );
	}

	/** Floor for a literal (keyword) match: min-max normalisation must never report it as 0. */
	public const KEYWORD_FLOOR = 0.5;

	/**
	 * Map a min-max normalised relevance (0-1) to the reported relevance. The
	 * lowest-scoring hit of a batch normalises to 0 although it still matched
	 * literally, so keyword matches are lifted into [0.5, 1]. Synonym matches
	 * are scaled by the synonym weight on top, giving [0.25, 0.5].
	 */
	public static function floorRelevance( float $normalized, string $match, float $synonymWeight ): float {
		$floored = self::KEYWORD_FLOOR + ( 1.0 - self::KEYWORD_FLOOR ) * max( 0.0, min( 1.0, $normalized ) );

		return $match === 'synonym' ? $floored * $synonymWeight : $floored;
	}

	/**
	 * Min-max normalize the 'relevance' key across a batch of candidates into
	 * a new 'relevance_norm' key in [0, 1]. A batch where every relevance is
	 * equal normalizes to 1.0 if that value is > 0, else 0.0 (an all-zero
	 * batch — e.g. every row came from the LIKE fallback with a synthetic 0
	 * relevance — must not be treated as "maximally relevant").
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	public static function normalizeRelevance( array $items ): array {
		if ( $items === [] ) {
			return [];
		}

		$values = array_map( static fn ( array $item ): float => (float) ( $item['relevance'] ?? 0.0 ), $items );
		$min    = min( $values );
		$max    = max( $values );
		$range  = $max - $min;

		foreach ( $items as $i => $item ) {
			if ( $range > 0.0 ) {
				$items[ $i ]['relevance_norm'] = ( $values[ $i ] - $min ) / $range;
			} else {
				$items[ $i ]['relevance_norm'] = $max > 0.0 ? 1.0 : 0.0;
			}
		}

		return $items;
	}

	/**
	 * @param array{relevance_norm?:float, created?:string, importance?:int, kind?:string, status?:string} $item
	 */
	public static function score( array $item ): float {
		$weights = self::weights();

		$relevance  = (float) ( $item['relevance_norm'] ?? 0.0 );
		$recency    = self::recencyScore( (string) ( $item['created'] ?? '' ) );
		$importance = max( 0.0, min( 100.0, (float) ( $item['importance'] ?? 0 ) ) ) / 100.0;

		$kind             = (string) ( $item['kind'] ?? '' );
		$isApprovedKnowledge = in_array( $kind, [ 'knowledge', 'procedure' ], true ) && ( (string) ( $item['status'] ?? '' ) ) === 'private';
		$boost            = $isApprovedKnowledge ? 1.0 : 0.0;

		return ( $weights['relevance'] * $relevance )
			+ ( $weights['recency'] * $recency )
			+ ( $weights['importance'] * $importance )
			+ ( $weights['knowledge_boost'] * $boost );
	}
}
