<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Recall;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Review\Lifecycle;

/**
 * REC-1: scope-filtered search over approved (`private` status) knowledge
 * and procedure posts. Deliberately raw prepared SQL rather than WP_Query:
 * WP_Query's `s` search does not restrict by taxonomy in the same query, so
 * a caller could observe partial/unfiltered results in that split-query
 * window. Everything here is one prepared statement with the scope tax
 * filter and the status filter baked in.
 *
 * Always LIKE, never MATCH: WordPress core's own `wp_posts` schema (see
 * wp-admin/includes/schema.php) does not ship a FULLTEXT index on
 * (post_title, post_content) — unlike Memory's purpose-built `ab_memory`
 * table, this is a shared core table this module doesn't own and cannot
 * assume an index onto. `$useLike` is still accepted (Recall/Briefing pass
 * the same REC-3 threshold decision uniformly to both stores) but is
 * currently a no-op here; kept as part of the public signature so a future
 * wave can add a real FULLTEXT-backed path without an API break.
 *
 * REC-3 / Unit H (follow-up wave): relevance is scored with the same
 * BM25-style approach MemoryScopedSearch's LIKE fallback uses (see
 * MemoryScopedSearch::searchLike()/rankByBm25()) rather than a single
 * whole-query substring or a raw count of matched terms. Kept in sync
 * deliberately: Recall's Ranker normalizes relevance across the *merged*
 * memory+knowledge batch, so both sides need to produce relevance on a
 * comparable scale — if one side scored real BM25 and the other a constant
 * 0, the constant-0 side would always normalize to the batch minimum
 * regardless of how well it actually matched — silently defeating the
 * REC-2 knowledge boost's intent to break ties, not override real relevance
 * differences. IDF/avgdl here are computed only over this caller's own
 * visible, approved posts (same scope/status/post_type predicate as every
 * other query in this class) — never another scope's posts.
 *
 * Row inclusion requires ANY tokenized term to match (OR across terms),
 * mirroring MemoryScopedSearch::searchLike(): a row qualifies with a single
 * term hit, and `relevance` (the SUM(CASE) count of distinct matched terms)
 * ranks multi-term matches above single-term ones. See
 * MemoryScopedSearch::searchLike()'s docblock for the full rationale —
 * scope_id / taxonomy filtering in the same prepared statement is the
 * actual leak boundary, not term-match mode; commit 3e78798 corrected
 * CrossAgentLeakTest to assert that invariant directly instead of asserting
 * an empty result set.
 */
class KnowledgeSearch {

	public const POST_TYPES = [ 'ab_knowledge', 'ab_procedure' ];
	public const STATUS     = 'private';
	/** Post meta set by curators: briefed whole, ahead of recents. */
	public const META_PINNED = 'ab_pinned';
	public const TAXONOMY   = 'ab_scope';
	public const DEFAULT_LIMIT = 20;
	public const MAX_LIMIT     = 200;

	/** Unit H: same BM25 constants as MemoryScopedSearch (kept identical — no test motivated divergence). */
	public const BM25_K1 = 1.2;
	public const BM25_B  = 0.75;

	/**
	 * Candidate pool cap for the BM25 ranking pass, mirroring
	 * MemoryScopedSearch::BM25_POOL_LIMIT — see that constant's docblock.
	 * This class has no row-count fallback threshold of its own (it's
	 * always LIKE, per the class docblock) and therefore nothing to clamp
	 * the way MemoryScopedSearch::fulltextMinRows() now is, and no
	 * FULLTEXT alternative to fall through to the way
	 * MemoryScopedSearch::search() now can (fix round 3's useLike guard) —
	 * a scope can, in principle, have an unbounded number of approved
	 * knowledge/procedure posts, so this class leans harder on (a) pool
	 * size and (b) pool ordering than MemoryScopedSearch does.
	 *
	 * (a) Raised from 500 to 2000 (fix round 3) so truncation is a last
	 *     resort for realistic scopes. Complexity: rankByBm25() does O(pool
	 *     * terms) whole-word regex work in PHP plus ~1 + 1 + MAX_TERMS(12)
	 *     extra small SQL queries (visible count, avgdl, per-term document
	 *     frequency) — all bounded by this constant and
	 *     Tokenizer::MAX_TERMS, never by the scope's real size.
	 *     RecallBm25FallbackTest::test_knowledge_bm25_scales_within_generous_bound_at_2000_rows
	 *     measures this directly: 2000 approved posts of ~2 KB each, median
	 *     KnowledgeSearch::search() time logged to STDERR and asserted
	 *     under a generous bound (see that test for the measured number).
	 * (b) The SQL candidate pool is sorted by a relevance proxy (distinct
	 *     matched-term count, match_count) DESC, then document length ASC,
	 *     then recency, before the LIMIT applies — see search()'s docblock
	 *     — so if a scope ever does exceed the cap, the candidates BM25
	 *     would actually prefer are what survive, never merely the newest
	 *     ones. Dedicated regression tests (RecallBm25FallbackTest) prove
	 *     an old, precise post still wins both against 500+ newer
	 *     multi-term-weaker matches and against 2000+ newer single-term
	 *     ties (the exact shape a match_count-only tiebreak still missed).
	 */
	public const BM25_POOL_LIMIT = 2000;

	/**
	 * @param int[] $allowedScopeIds
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $q, array $allowedScopeIds, int $limit = self::DEFAULT_LIMIT, bool $useLike = false, ?string $postType = null ): array {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		if ( $allowedScopeIds === [] || trim( $q ) === '' ) {
			return [];
		}

		$terms = Tokenizer::tokenize( $q );
		if ( $terms === [] ) {
			return [];
		}

		$limit     = max( 1, min( self::MAX_LIMIT, $limit ) );
		$postTypes = self::resolvePostTypes( $postType );

		global $wpdb;

		$postsTable = $wpdb->posts;
		$trTable    = $wpdb->term_relationships;
		$ttTable    = $wpdb->term_taxonomy;

		$typePlaceholders  = self::placeholders( $postTypes, '%s' );
		$scopePlaceholders = self::placeholders( $allowedScopeIds, '%d' );

		$caseParts   = [];
		$caseParams  = [];
		$whereParts  = [];
		$whereParams = [];

		// Whole-word matching, mirroring MemoryScopedSearch::searchLike():
		// each term must satisfy both the cheap escaped LIKE prefilter and a
		// REGEXP boundary check '(?<![\p{L}\p{N}])term(?![\p{L}\p{N}])', so
		// a term like "new" no longer matches as a substring of "renew".
		// Plain '\b' is deliberately NOT used — it treats '_' as a word
		// character, unlike Tokenizer::tokenize()'s own definition of a
		// separator, so it would fail to match a term directly adjacent to
		// a literal underscore. See MemoryScopedSearch::searchLike()'s
		// docblock for the full rationale (verified against this project's
		// MySQL 8 container; also supported by MariaDB 10.6's PCRE regex)
		// and why no additional escaping is needed — Tokenizer::tokenize()
		// terms only ever contain Unicode letters/numbers.
		foreach ( $terms as $term ) {
			$likeParam  = '%' . $wpdb->esc_like( $term ) . '%';
			$regexParam = self::wholeWordRegexFragment( $term );

			$caseParts[]  = 'CASE WHEN ((p.post_title LIKE %s AND p.post_title REGEXP %s) OR (p.post_content LIKE %s AND p.post_content REGEXP %s)) THEN 1 ELSE 0 END';
			$caseParams[] = $likeParam;
			$caseParams[] = $regexParam;
			$caseParams[] = $likeParam;
			$caseParams[] = $regexParam;

			$whereParts[]  = '((p.post_title LIKE %s AND p.post_title REGEXP %s) OR (p.post_content LIKE %s AND p.post_content REGEXP %s))';
			$whereParams[] = $likeParam;
			$whereParams[] = $regexParam;
			$whereParams[] = $likeParam;
			$whereParams[] = $regexParam;
		}

		// OR across terms, not AND — see docblock above.
		$whereExpr = '(' . implode( ' OR ', $whereParts ) . ')';

		// Fix round 2 (reviewer HIGH finding): candidate pool only, NOT the
		// final ranking — but when matching posts exceed BM25_POOL_LIMIT,
		// the SQL LIMIT below decides which ones ever reach the BM25 pass,
		// and a plain recency ordering could drop the true best lexical
		// match for newer weak ones with no way to recover it in PHP. Same
		// fix as MemoryScopedSearch::searchLike(): order by the distinct
		// matched-term count (match_count) DESC first. match_count is a
		// pool-survival heuristic only — rankByBm25() below recomputes real
		// BM25 scores for the whole pool and re-sorts.
		//
		// Fix round 3 (reviewer HIGH, probe-verified): match_count alone
		// still ties whenever every candidate matches the same single term
		// exactly once — e.g. a one-word query against 500+ approved posts
		// that each mention it once, match_count = 1 for all of them. The
		// old tiebreak (post_date_gmt DESC) then collapsed to "newest
		// wins", dropping an old, short, precise post behind hundreds of
		// newer, longer, equally-1-term-matching ones. BM25 itself favors
		// a SHORTER document at equal term frequency (the length-
		// normalization term in the denominator), so the next tiebreak —
		// after match_count, before recency — is document length
		// (CHAR_LENGTH(post_title)+CHAR_LENGTH(post_content)) ASCENDING,
		// keeping the candidates BM25 would actually prefer in the pool.
		// See MemoryScopedSearch::searchLike()'s docblock for the same fix
		// there.
		$matchCountExpr = '(' . implode( ' + ', $caseParts ) . ')';
		$poolLimit      = max( $limit, self::BM25_POOL_LIMIT );

		$lifecycle = self::lifecycleColumn();
		$archived  = self::without( [ Lifecycle::ARCHIVED ] );

		$sql = "SELECT p.ID, p.post_type, p.post_title, p.post_content, p.post_status, p.post_date_gmt, p.post_modified_gmt, p.post_author,
				MIN(tt.term_id) AS scope_id, {$matchCountExpr} AS match_count, {$lifecycle}
			FROM {$postsTable} p
			INNER JOIN {$trTable} tr ON tr.object_id = p.ID
			INNER JOIN {$ttTable} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			WHERE p.post_type IN ({$typePlaceholders}) AND p.post_status = %s
			AND tt.term_id IN ({$scopePlaceholders})
			AND {$whereExpr}{$archived}
			GROUP BY p.ID
			ORDER BY match_count DESC, CHAR_LENGTH(CONCAT(p.post_title, ' ', p.post_content)) ASC, p.post_date_gmt DESC, p.ID DESC LIMIT %d";

		$params = array_merge(
			$caseParams,
			[ self::TAXONOMY ],
			$postTypes,
			[ self::STATUS ],
			$allowedScopeIds,
			$whereParams,
			[ $poolLimit ]
		);

		$candidates = Db::getResults( $sql, $params );
		if ( $candidates === [] ) {
			return [];
		}

		$ranked = $this->rankByBm25( $candidates, $terms, $allowedScopeIds, $postTypes, $typePlaceholders, $scopePlaceholders );

		return array_slice( $ranked, 0, $limit );
	}

	/**
	 * Unit H: BM25-style re-ranking, mirroring
	 * MemoryScopedSearch::rankByBm25() — see that method's docblock for the
	 * general rationale. The leak-sensitive part is identical here: IDF
	 * (via termDocumentFrequencies()) and avgdl (via
	 * averageVisibleDocLength()) are both computed with the same
	 * post_type/status/taxonomy/scope predicate as every other query in
	 * this class — never counting or measuring a post outside
	 * $allowedScopeIds.
	 *
	 * @param array<int, array<string, mixed>> $candidates
	 * @param string[]                         $terms
	 * @param int[]                            $allowedScopeIds
	 * @param string[]                         $postTypes
	 * @return array<int, array<string, mixed>>
	 */
	private function rankByBm25( array $candidates, array $terms, array $allowedScopeIds, array $postTypes, string $typePlaceholders, string $scopePlaceholders ): array {
		$visibleCount = $this->totalVisibleCount( $allowedScopeIds, $postTypes, $typePlaceholders, $scopePlaceholders );
		$avgdl        = $this->averageVisibleDocLength( $allowedScopeIds, $postTypes, $typePlaceholders, $scopePlaceholders );
		$docFreq      = $this->termDocumentFrequencies( $terms, $allowedScopeIds, $postTypes, $typePlaceholders, $scopePlaceholders );

		$idf = [];
		foreach ( $terms as $term ) {
			$idf[ $term ] = self::idf( $visibleCount, $docFreq[ $term ] ?? 0 );
		}

		$patterns = [];
		foreach ( $terms as $term ) {
			$patterns[ $term ] = '/' . self::wholeWordRegexFragment( $term ) . '/iu';
		}

		foreach ( $candidates as $i => $row ) {
			$doc = ( (string) ( $row['post_title'] ?? '' ) ) . ' ' . ( (string) ( $row['post_content'] ?? '' ) );
			$dl  = (float) mb_strlen( $doc, 'UTF-8' );

			$score = 0.0;
			foreach ( $terms as $term ) {
				$tf = preg_match_all( $patterns[ $term ], $doc );
				$tf = $tf === false ? 0 : $tf;

				if ( $tf === 0 ) {
					continue;
				}

				$denominator = $tf + self::BM25_K1 * ( 1 - self::BM25_B + self::BM25_B * ( $dl / $avgdl ) );
				$score      += $idf[ $term ] * ( ( $tf * ( self::BM25_K1 + 1 ) ) / $denominator );
			}

			$candidates[ $i ]['relevance'] = $score;
		}

		usort( $candidates, static function ( array $a, array $b ): int {
			$scoreA = (float) $a['relevance'];
			$scoreB = (float) $b['relevance'];

			if ( $scoreA !== $scoreB ) {
				return $scoreB <=> $scoreA;
			}

			$dateA = (string) ( $a['post_date_gmt'] ?? '' );
			$dateB = (string) ( $b['post_date_gmt'] ?? '' );

			if ( $dateA !== $dateB ) {
				return strcmp( $dateB, $dateA );
			}

			return ( (int) ( $b['ID'] ?? 0 ) ) <=> ( (int) ( $a['ID'] ?? 0 ) );
		} );

		return array_values( $candidates );
	}

	/**
	 * Total count of approved posts of the resolved type(s) visible to the
	 * caller across their allowed scopes — the BM25 "N" for this class,
	 * scoped identically to search() itself (post_type/status/taxonomy/
	 * scope), never counting another scope's posts.
	 *
	 * @param int[]    $allowedScopeIds
	 * @param string[] $postTypes
	 */
	private function totalVisibleCount( array $allowedScopeIds, array $postTypes, string $typePlaceholders, string $scopePlaceholders ): int {
		global $wpdb;

		$postsTable = $wpdb->posts;
		$trTable    = $wpdb->term_relationships;
		$ttTable    = $wpdb->term_taxonomy;

		$sql = "SELECT COUNT(*) AS c FROM (
				SELECT p.ID FROM {$postsTable} p
				INNER JOIN {$trTable} tr ON tr.object_id = p.ID
				INNER JOIN {$ttTable} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				WHERE p.post_type IN ({$typePlaceholders}) AND p.post_status = %s
				AND tt.term_id IN ({$scopePlaceholders})
				GROUP BY p.ID
			) t";

		$params = array_merge( [ self::TAXONOMY ], $postTypes, [ self::STATUS ], $allowedScopeIds );
		$rows   = Db::getResults( $sql, $params );

		return isset( $rows[0]['c'] ) ? (int) $rows[0]['c'] : 0;
	}

	/**
	 * Average document length (chars of post_title + post_content, space
	 * joined) over every post visible to the caller — not just the
	 * term-matching candidates. Same scope/status/taxonomy/post_type
	 * predicate as totalVisibleCount(); a guard against 0 avoids a
	 * division-by-zero in the BM25 length-norm term.
	 *
	 * @param int[]    $allowedScopeIds
	 * @param string[] $postTypes
	 */
	private function averageVisibleDocLength( array $allowedScopeIds, array $postTypes, string $typePlaceholders, string $scopePlaceholders ): float {
		global $wpdb;

		$postsTable = $wpdb->posts;
		$trTable    = $wpdb->term_relationships;
		$ttTable    = $wpdb->term_taxonomy;

		$sql = "SELECT AVG(len) AS avgdl FROM (
				SELECT CHAR_LENGTH(CONCAT(p.post_title, ' ', p.post_content)) AS len FROM {$postsTable} p
				INNER JOIN {$trTable} tr ON tr.object_id = p.ID
				INNER JOIN {$ttTable} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				WHERE p.post_type IN ({$typePlaceholders}) AND p.post_status = %s
				AND tt.term_id IN ({$scopePlaceholders})
				GROUP BY p.ID
			) t";

		$params = array_merge( [ self::TAXONOMY ], $postTypes, [ self::STATUS ], $allowedScopeIds );
		$rows   = Db::getResults( $sql, $params );
		$avgdl  = isset( $rows[0]['avgdl'] ) ? (float) $rows[0]['avgdl'] : 0.0;

		return $avgdl > 0.0 ? $avgdl : 1.0;
	}

	/**
	 * Document frequency per term over the caller's visible, approved posts
	 * only (never another scope's) — mirrors
	 * MemoryScopedSearch::termDocumentFrequencies(). One COUNT query per
	 * term, bounded by Tokenizer::MAX_TERMS = 12.
	 *
	 * @param string[] $terms
	 * @param int[]    $allowedScopeIds
	 * @param string[] $postTypes
	 * @return array<string, int>
	 */
	private function termDocumentFrequencies( array $terms, array $allowedScopeIds, array $postTypes, string $typePlaceholders, string $scopePlaceholders ): array {
		global $wpdb;

		$postsTable = $wpdb->posts;
		$trTable    = $wpdb->term_relationships;
		$ttTable    = $wpdb->term_taxonomy;

		$freqs = [];

		foreach ( $terms as $term ) {
			$likeParam  = '%' . $wpdb->esc_like( $term ) . '%';
			$regexParam = self::wholeWordRegexFragment( $term );

			$sql = "SELECT COUNT(*) AS c FROM (
					SELECT p.ID FROM {$postsTable} p
					INNER JOIN {$trTable} tr ON tr.object_id = p.ID
					INNER JOIN {$ttTable} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
					WHERE p.post_type IN ({$typePlaceholders}) AND p.post_status = %s
					AND tt.term_id IN ({$scopePlaceholders})
					AND ((p.post_title LIKE %s AND p.post_title REGEXP %s) OR (p.post_content LIKE %s AND p.post_content REGEXP %s))
					GROUP BY p.ID
				) t";

			$params = array_merge(
				[ self::TAXONOMY ],
				$postTypes,
				[ self::STATUS ],
				$allowedScopeIds,
				[ $likeParam, $regexParam, $likeParam, $regexParam ]
			);

			$rows = Db::getResults( $sql, $params );

			$freqs[ $term ] = isset( $rows[0]['c'] ) ? (int) $rows[0]['c'] : 0;
		}

		return $freqs;
	}

	/** Okapi BM25 IDF ("+1" variant) — identical formula to MemoryScopedSearch::idf(). */
	private static function idf( int $visibleCount, int $documentFrequency ): float {
		$n  = max( 0, $visibleCount );
		$df = max( 0, min( $documentFrequency, $n ) );

		return log( ( ( $n - $df + 0.5 ) / ( $df + 0.5 ) ) + 1 );
	}

	/** Identical fragment to MemoryScopedSearch::wholeWordRegexFragment() — kept in sync deliberately. */
	private static function wholeWordRegexFragment( string $term ): string {
		return '(?<![\\p{L}\\p{N}])' . $term . '(?![\\p{L}\\p{N}])';
	}

	/**
	 * REC-4 re-check: fetch a single candidate post, only returning it if it
	 * is approved (`private`) AND tagged with one of $allowedScopeIds. Used
	 * to re-validate vector-search candidates before they are ever merged
	 * into results — a poisoned candidate for an out-of-scope or
	 * non-existent post resolves to null and is dropped by the caller.
	 *
	 * @param int[] $allowedScopeIds
	 * @return array<string, mixed>|null
	 */
	public function getScoped( int $id, array $allowedScopeIds, ?string $postType = null ): ?array {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		if ( $allowedScopeIds === [] || $id <= 0 ) {
			return null;
		}

		$postTypes = self::resolvePostTypes( $postType );

		global $wpdb;

		$postsTable = $wpdb->posts;
		$trTable    = $wpdb->term_relationships;
		$ttTable    = $wpdb->term_taxonomy;

		$typePlaceholders  = self::placeholders( $postTypes, '%s' );
		$scopePlaceholders = self::placeholders( $allowedScopeIds, '%d' );

		$lifecycle = self::lifecycleColumn();

		$sql = "SELECT p.ID, p.post_type, p.post_title, p.post_content, p.post_status, p.post_date_gmt, p.post_modified_gmt, p.post_author,
				MIN(tt.term_id) AS scope_id, {$lifecycle}
			FROM {$postsTable} p
			INNER JOIN {$trTable} tr ON tr.object_id = p.ID
			INNER JOIN {$ttTable} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			WHERE p.ID = %d AND p.post_type IN ({$typePlaceholders}) AND p.post_status = %s
			AND tt.term_id IN ({$scopePlaceholders})
			GROUP BY p.ID LIMIT 1";

		$params = array_merge(
			[ self::TAXONOMY, $id ],
			$postTypes,
			[ self::STATUS ],
			$allowedScopeIds
		);

		$rows = Db::getResults( $sql, $params );

		return $rows[0] ?? null;
	}

	/**
	 * REC-5 "approved knowledge" / "relevant procedures" sections when no
	 * focus text is supplied: most recently updated approved posts of the
	 * given type, scoped.
	 *
	 * @param int[] $allowedScopeIds
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( string $postType, array $allowedScopeIds, int $limit = self::DEFAULT_LIMIT ): array {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		if ( $allowedScopeIds === [] || ! in_array( $postType, self::POST_TYPES, true ) ) {
			return [];
		}

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );

		global $wpdb;

		$postsTable = $wpdb->posts;
		$trTable    = $wpdb->term_relationships;
		$ttTable    = $wpdb->term_taxonomy;

		$scopePlaceholders = self::placeholders( $allowedScopeIds, '%d' );

		// #594: recent items are current knowledge; reference and archived
		// items are found by search, not briefed.
		$current = self::without( Lifecycle::STORED );

		$sql = "SELECT p.ID, p.post_type, p.post_title, p.post_content, p.post_status, p.post_date_gmt, p.post_author,
				MIN(tt.term_id) AS scope_id, 0 AS relevance
			FROM {$postsTable} p
			INNER JOIN {$trTable} tr ON tr.object_id = p.ID
			INNER JOIN {$ttTable} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			WHERE p.post_type = %s AND p.post_status = %s
			AND tt.term_id IN ({$scopePlaceholders}){$current}
			GROUP BY p.ID
			ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT %d";

		$params = array_merge(
			[ self::TAXONOMY, $postType, self::STATUS ],
			$allowedScopeIds,
			[ $limit ]
		);

		return Db::getResults( $sql, $params );
	}

	/**
	 * Curator-pinned approved posts of one type (post meta `ab_pinned` = 1),
	 * same scope/status predicate as recent(). Oldest pinned first so the
	 * order is stable and a project's essentials read in the order pinned.
	 *
	 * @param int[] $allowedScopeIds
	 * @return array<int, array<string, mixed>>
	 */
	public function pinned( string $postType, array $allowedScopeIds, int $limit = self::DEFAULT_LIMIT ): array {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		if ( $allowedScopeIds === [] || ! in_array( $postType, self::POST_TYPES, true ) ) {
			return [];
		}

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );

		global $wpdb;

		$postsTable = $wpdb->posts;
		$metaTable  = $wpdb->postmeta;
		$trTable    = $wpdb->term_relationships;
		$ttTable    = $wpdb->term_taxonomy;

		$scopePlaceholders = self::placeholders( $allowedScopeIds, '%d' );

		$sql = "SELECT p.ID, p.post_type, p.post_title, p.post_content, p.post_status, p.post_date_gmt, p.post_modified_gmt, p.post_author,
				MIN(tt.term_id) AS scope_id, 0 AS relevance, " . self::lifecycleColumn() . "
			FROM {$postsTable} p
			INNER JOIN {$metaTable} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = '1'
			INNER JOIN {$trTable} tr ON tr.object_id = p.ID
			INNER JOIN {$ttTable} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			WHERE p.post_type = %s AND p.post_status = %s
			AND tt.term_id IN ({$scopePlaceholders})" . self::without( [ Lifecycle::ARCHIVED ] ) . "
			GROUP BY p.ID
			ORDER BY p.post_date_gmt ASC, p.ID ASC LIMIT %d";

		$params = array_merge(
			[ self::META_PINNED, self::TAXONOMY, $postType, self::STATUS ],
			$allowedScopeIds,
			[ $limit ]
		);

		return Db::getResults( $sql, $params );
	}

	/**
	 * #594: the item's stored lifecycle (Review\Lifecycle) as a `lifecycle`
	 * column, null when current. Constants only, nothing from the caller.
	 */
	private static function lifecycleColumn(): string {
		global $wpdb;

		return "(SELECT lm.meta_value FROM {$wpdb->postmeta} lm WHERE lm.post_id = p.ID AND lm.meta_key = '" . Lifecycle::META . "' LIMIT 1) AS lifecycle";
	}

	/**
	 * #594: an AND clause leaving out items stored with one of these
	 * lifecycles. Constants only, nothing from the caller.
	 *
	 * @param string[] $lifecycles Values of Lifecycle::STORED.
	 */
	private static function without( array $lifecycles ): string {
		global $wpdb;

		$values = implode( ', ', array_map( static fn ( string $v ): string => "'" . $v . "'", array_values( array_intersect( $lifecycles, Lifecycle::STORED ) ) ) );

		return " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} lx WHERE lx.post_id = p.ID AND lx.meta_key = '" . Lifecycle::META . "' AND lx.meta_value IN ({$values}))";
	}

	/** @return string[] */
	private static function resolvePostTypes( ?string $postType ): array {
		if ( $postType !== null && in_array( $postType, self::POST_TYPES, true ) ) {
			return [ $postType ];
		}

		return self::POST_TYPES;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function sanitizeIds( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		return array_values( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) );
	}

	/**
	 * @param array<int, mixed> $values
	 */
	private static function placeholders( array $values, string $placeholder ): string {
		return implode( ', ', array_fill( 0, count( $values ), $placeholder ) );
	}
}
