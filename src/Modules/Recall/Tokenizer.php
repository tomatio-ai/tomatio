<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Recall;

/**
 * REC-3: turns a free-text query into the set of terms the LIKE fallback
 * matches on. Pure function, no WP/DB dependency, so it is fully unit
 * testable without the integration bootstrap.
 *
 * Pipeline: lowercase -> unicode-aware word split -> drop stopwords and
 * sub-2-char tokens -> dedupe (order-preserving) -> cap to MAX_TERMS.
 */
class Tokenizer {

	public const MAX_TERMS   = 12;
	public const MIN_LENGTH  = 2;

	/**
	 * Small built-in English stopword list: common function words that add
	 * noise to a substring-match scorer without carrying topical meaning.
	 * Deliberately conservative — short and unambiguous — so real content
	 * words are never accidentally dropped.
	 */
	public const STOPWORDS = [
		'a', 'an', 'the', 'and', 'or', 'but', 'if', 'then', 'else', 'for',
		'nor', 'so', 'yet', 'of', 'to', 'in', 'on', 'at', 'by', 'from',
		'with', 'about', 'as', 'into', 'like', 'through', 'after', 'over',
		'between', 'out', 'against', 'during', 'without', 'before', 'under',
		'around', 'among', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
		'do', 'does', 'did', 'doing', 'have', 'has', 'had', 'having',
		'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she', 'it',
		'we', 'they', 'me', 'him', 'her', 'us', 'them', 'my', 'your', 'his',
		'its', 'our', 'their', 'what', 'which', 'who', 'whom', 'whose',
		'when', 'where', 'why', 'how', 'will', 'would', 'shall', 'should',
		'can', 'could', 'may', 'might', 'must', 'not', 'no', 'yes', 'up',
		'down', 'off', 'again', 'further', 'once', 'here', 'there', 'all',
		'any', 'both', 'each', 'few', 'more', 'most', 'other', 'some',
		'such', 'only', 'own', 'same', 'than', 'too', 'very', 'just',
		'did', 'does', 'am',
	];

	/**
	 * @return string[] deduped, ordered terms, each length >= MIN_LENGTH,
	 *   count <= MAX_TERMS. Empty input, or input reduced to nothing by
	 *   stopword/length filtering, returns [].
	 */
	public static function tokenize( string $q ): array {
		$lower = mb_strtolower( $q, 'UTF-8' );

		// \p{L} letters and \p{N} numbers, unicode-aware ('u' modifier);
		// everything else (punctuation, whitespace, symbols) is a splitter.
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );
		if ( $parts === false || $parts === [] ) {
			return [];
		}

		$stopwords = array_flip( self::STOPWORDS );
		$seen      = [];
		$terms     = [];

		foreach ( $parts as $part ) {
			if ( mb_strlen( $part, 'UTF-8' ) < self::MIN_LENGTH ) {
				continue;
			}

			if ( isset( $stopwords[ $part ] ) ) {
				continue;
			}

			if ( isset( $seen[ $part ] ) ) {
				continue;
			}

			$seen[ $part ] = true;
			$terms[]       = $part;

			if ( count( $terms ) >= self::MAX_TERMS ) {
				break;
			}
		}

		return $terms;
	}
}
