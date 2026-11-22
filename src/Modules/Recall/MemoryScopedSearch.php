<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Recall;

use AgentBrain\Core\Db;
use AgentBrain\Core\SqliteRuntime;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * REC-1 / REC-3: scope-filtered search over the raw `ab_memory` table,
 * automatically switching between FULLTEXT and an escaped LIKE fallback
 * depending on how many rows are visible to the caller.
 *
 * Reads the table name from MemoryRepository::memoryTable() (a public
 * accessor Memory already exposes) rather than duplicating schema
 * knowledge; this module never writes to Memory's files.
 */
class MemoryScopedSearch {

	public const DEFAULT_FULLTEXT_MIN_ROWS = 300;
	public const DEFAULT_LIMIT             = 20;
	public const MAX_LIMIT                 = 200;

	/**
	 * BM25 (Follow-up wave Unit H): standard Okapi constants — k1 controls
	 * term-frequency saturation, b controls how strongly document length is
	 * normalized against the average. Unchanged from the textbook defaults;
	 * no test motivated a deviation.
	 */
	public const BM25_K1 = 1.2;
	public const BM25_B  = 0.75;

	/**
	 * Candidate pool cap for the LIKE fallback's BM25 ranking pass (Unit H).
	 * searchLike() is only ever reached when the caller's visible row count
	 * is below fulltextMinRows() (default 300, REC-3) — that is the whole
	 * reason it exists. fulltextMinRows() is clamped (fix round 2) to never
	 * return more than this constant, so in the default and every
	 * configured setup, visibleCount() < fulltextMinRows() <=
	 * BM25_POOL_LIMIT: the candidate pool can hold every visible row and
	 * truncation should never actually happen. It remains a hard ceiling
	 * (defense in depth) so the PHP-side ranking pass below never runs over
	 * an unbounded pool even if that invariant is ever violated — and for
	 * that reason, searchLike()'s SQL candidate query orders by a
	 * relevance-proxy (distinct matched-term count) before this LIMIT
	 * applies, not by recency, so the strongest lexical candidates are what
	 * survive truncation if it ever does happen.
	 */
	public const BM25_POOL_LIMIT = 500;

	public static function table(): string {
		return MemoryRepository::memoryTable();
	}

	/**
	 * Configurable threshold (REC-3): below this many caller-visible active
	 * rows, search() below uses LIKE instead of FULLTEXT.
	 *
	 * Clamped (fix round 2, Unit H) to never exceed BM25_POOL_LIMIT: the
	 * LIKE fallback's BM25 candidate pool is bounded at BM25_POOL_LIMIT
	 * rows (searchLike()), and that bound is only safe because the fallback
	 * is documented to run below this threshold. An operator raising
	 * ab_fulltext_min_rows past the pool limit would silently let the true
	 * best match fall outside the candidate pool before BM25 ever sees it
	 * (reviewer-reproduced: 1 precise old row + 600 newer weak matches,
	 * threshold raised above 500 → the precise row dropped). Clamping here
	 * keeps that invariant true by construction instead of relying on every
	 * caller to configure both values consistently.
	 */
	public function fulltextMinRows(): int {
		// SQLite drop-in (#405): MATCH ... AGAINST is untranslated and
		// errors, so the FULLTEXT branch can never run. Report an
		// unreachable threshold so usesLikeFallback() always routes to the
		// LIKE/BM25 path, which is SQLite-safe.
		if ( SqliteRuntime::isActive() ) {
			return PHP_INT_MAX;
		}

		if ( ! function_exists( 'get_option' ) ) {
			return self::DEFAULT_FULLTEXT_MIN_ROWS;
		}

		$value = (int) get_option( 'ab_fulltext_min_rows', self::DEFAULT_FULLTEXT_MIN_ROWS );
		$value = $value > 0 ? $value : self::DEFAULT_FULLTEXT_MIN_ROWS;

		return min( $value, self::BM25_POOL_LIMIT );
	}

	/**
	 * Count of active rows visible to the caller across their allowed
	 * scopes. Empty allowed scopes ⇒ 0 without issuing a query.
	 *
	 * @param int[] $allowedScopeIds
	 */
	public function visibleCount( array $allowedScopeIds ): int {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		if ( $allowedScopeIds === [] ) {
			return 0;
		}

		$table        = self::table();
		$placeholders = self::placeholders( $allowedScopeIds );

		$rows = Db::getResults(
			"SELECT COUNT(*) AS c FROM {$table} WHERE scope_id IN ({$placeholders}) AND status = %s",
			array_merge( $allowedScopeIds, [ 'active' ] )
		);

		return isset( $rows[0]['c'] ) ? (int) $rows[0]['c'] : 0;
	}

	/**
	 * @param int[] $allowedScopeIds
	 */
	public function usesLikeFallback( array $allowedScopeIds ): bool {
		return $this->visibleCount( $allowedScopeIds ) < $this->fulltextMinRows();
	}

	/**
	 * Scope-filtered content search. Empty allowed scopes or blank query
	 * return [] without issuing a query (fail closed, REC-1).
	 *
	 * @param int[] $allowedScopeIds
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $q, array $allowedScopeIds, int $limit = self::DEFAULT_LIMIT, bool $useLike = false ): array {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		if ( $allowedScopeIds === [] || trim( $q ) === '' ) {
			return [];
		}

		$limit        = max( 1, min( self::MAX_LIMIT, $limit ) );
		$table        = self::table();
		$placeholders = self::placeholders( $allowedScopeIds );

		// Fix round 3 (reviewer HIGH, guard #3): usesLikeFallback()/
		// fulltextMinRows() already keep the normal caller path
		// (visibleCount() < fulltextMinRows() <= BM25_POOL_LIMIT) safe, but
		// $useLike is a caller-supplied flag — a direct call (tests, or any
		// future caller) can pass useLike=true independent of that check.
		// If it does so while more rows are visible than the BM25 candidate
		// pool can hold, searchLike()'s SQL LIMIT would still be the one
		// deciding which rows are even considered, which is exactly the
		// silent-truncation failure mode fixed elsewhere in this class —
		// refuse to run the bounded-pool path in that case and fall through
		// to FULLTEXT instead, which has no such bound and is always
		// complete over the caller's visible rows. This only changes
		// behavior for a caller that mis-declares useLike; the documented,
		// gated path (usesLikeFallback()) never hits it. Exception: under
		// the SQLite drop-in (#405) FULLTEXT is untranslated and would
		// error, so the bounded pool is the only working path there and
		// always wins over a broken FULLTEXT.
		if ( $useLike && ( SqliteRuntime::isActive() || $this->visibleCount( $allowedScopeIds ) <= self::BM25_POOL_LIMIT ) ) {
			return $this->searchLike( $q, $allowedScopeIds, $limit, $placeholders, $table );
		}

		$sql = "SELECT *, MATCH(content, search_keys) AGAINST (%s IN NATURAL LANGUAGE MODE) AS relevance
			FROM {$table}
			WHERE MATCH(content, search_keys) AGAINST (%s IN NATURAL LANGUAGE MODE)
			AND scope_id IN ({$placeholders}) AND status = %s
			ORDER BY relevance DESC, id DESC LIMIT %d";

		$params = array_merge( [ $q, $q ], $allowedScopeIds, [ 'active', $limit ] );

		return Db::getResults( $sql, $params );
	}

	/**
	 * REC-3 / Unit H (follow-up wave): tokenized LIKE fallback, ranked with a
	 * BM25-style score instead of a raw count of matched terms.
	 *
	 * Why: the plain "count of distinct matched terms" relevance let any
	 * long document containing every query word always outrank a short,
	 * precise one — live-observed on the demo, where a 27 KB paper and a
	 * provenance memo (both mentioning every word of "Recall@5 evaluation
	 * results" somewhere in their bulk) outranked the one short note that
	 * actually held the answer. BM25 fixes this by weighting each matched
	 * term by how rare it is (IDF) and saturating repeated occurrences
	 * (TF with k1), then normalizing for document length (b) so long
	 * documents don't win purely by being long.
	 *
	 * Two-phase approach (deliberately NOT computed in one SQL query):
	 *  1. SQL still does candidate selection AND the scope/status
	 *     enforcement boundary: one prepared statement filters by the same
	 *     OR-across-terms whole-word predicate as before, AND scope_id IN
	 *     (...) AND status = 'active', capped at BM25_POOL_LIMIT rows.
	 *     Nothing outside the caller's scope is ever fetched into PHP —
	 *     scope filtering happens in SQL, before any ranking. The pool is
	 *     ordered by a relevance proxy (distinct matched-term count,
	 *     match_count) DESC then created DESC/id DESC for determinism —
	 *     NOT by recency alone — so that if the pool ever needs to
	 *     truncate, the strongest lexical candidates are what survive to
	 *     be BM25-scored in step 2, rather than merely the newest ones
	 *     (fix round 2: a recency-only pool order could silently drop the
	 *     true best match behind hundreds of newer weak matches).
	 *  2. PHP computes the BM25 score for each candidate row and re-sorts.
	 *     This needs three extra scoped SQL queries beyond the candidate
	 *     fetch: visibleCount() (N, reused — already public), one AVG(...)
	 *     query for avgdl, and one COUNT(...) query per query term (document
	 *     frequency n_t) — all filtered to the caller's own visible rows,
	 *     never another scope, so IDF cannot leak a cross-scope term-rarity
	 *     signal. That is at most ~1 + 1 + MAX_TERMS (12) = 14 small queries,
	 *     acceptable ONLY because this path is reached exclusively when the
	 *     caller has fewer than fulltextMinRows() (default 300) visible
	 *     rows — the same condition that already gates the whole fallback.
	 *     Complexity: O(terms) extra queries + O(pool * terms) regex work in
	 *     PHP, bounded by BM25_POOL_LIMIT and Tokenizer::MAX_TERMS.
	 *
	 * An empty-after-tokenizing query (blank, or only stopwords/too-short
	 * tokens) returns [] rather than every row (fail closed, never "match
	 * everything").
	 *
	 * Row inclusion still requires ANY tokenized term to match (OR across
	 * terms): a row qualifies if at least one term hits (content or
	 * search_keys) — this is what lets a natural-language question retrieve
	 * a memory that shares only some of its words. scope_id filtering (and
	 * status = 'active') stays in the same prepared statement and is the
	 * actual enforcement boundary; the fix in commit 3e78798 corrected
	 * CrossAgentLeakTest to assert "no row from another scope, no foreign
	 * canary" rather than "empty result set" — a neighbouring row in the
	 * caller's own scope legitimately sharing a query word was never the
	 * leak, cross-scope visibility was, and that is governed by scope_id
	 * IN (...), not by term-match mode.
	 *
	 * Whole-word matching (fix for the "new" substring-matching "renew"
	 * false positive): a bare LIKE '%term%' matches a term as a substring
	 * of a longer, unrelated word ("new" inside "re-new"/"renew"), which let
	 * an irrelevant row tie on relevance and win on recency. Each term now
	 * requires BOTH the cheap escaped LIKE (kept as a sargable-ish
	 * prefilter — it still can't use the FULLTEXT/BTREE index on LONGTEXT,
	 * but narrows before the regex evaluates) AND a REGEXP boundary check,
	 * bound via $wpdb->prepare like every other parameter here — never
	 * interpolated.
	 *
	 * The boundary pattern is '(?<![\p{L}\p{N}])term(?![\p{L}\p{N}])'
	 * (negative lookbehind/lookahead), NOT plain '\b': REGEXP's built-in
	 * '\b' treats '_' as a word character (PCRE/ICU \w includes '_'), so
	 * '\bbonus\b' fails to match "100%_bonus" even though the tokenizer
	 * already treats '_' as a separator (splits on `[^\p{L}\p{N}]+}`,
	 * excluding '_') — that mismatch was caught by
	 * test_wildcard_characters_in_query_are_escaped_not_interpreted, which
	 * inserts literal '_' next to a real term. The lookaround pattern
	 * matches the tokenizer's own definition of a "word": bounded by
	 * anything that is NOT a Unicode letter or number, so '_' (like '-' or
	 * any other punctuation) counts as a boundary and "checkout" still
	 * matches inside "checkout-v2". Not the MySQL-5.7-only
	 * '[[:<:]]'/'[[:>:]]' word-boundary operators, which this project never
	 * targets. Verified directly against this project's MySQL 8.0
	 * container (REGEXP is ICU-based since 8.0.4 and supports Unicode
	 * property escapes and lookaround; case-insensitive under the default
	 * utf8mb4_unicode_ci collation this table is created with —
	 * Module::migrations(), migration 1 — matching LIKE's own
	 * case-insensitivity under the same collation). MariaDB 10.6's
	 * PCRE-based REGEXP also supports `\p{L}`/`\p{N}` and lookaround (not
	 * verified live here — only the MySQL 8 container was available — but
	 * documented in MariaDB's PCRE regex syntax reference). Terms come from
	 * Tokenizer::tokenize(), which splits on `[^\p{L}\p{N}]+`, so a term can
	 * only ever contain Unicode letters/numbers — no regex metacharacters
	 * ever reach the pattern, so no preg_quote()-style escaping is needed
	 * for the regex itself (only the existing esc_like() escaping for the
	 * LIKE prefilter, and $wpdb->prepare()'s own quoting for both).
	 *
	 * Public since #405: MemoryRepository::search() routes here verbatim
	 * when the SQLite drop-in is active (FULLTEXT is untranslated there);
	 * the $scopePlaceholders/$table parameters exist for the internal
	 * caller, which already computed them, and default for everyone else.
	 *
	 * @param int[] $allowedScopeIds
	 * @return array<int, array<string, mixed>>
	 */
	public function searchLike( string $q, array $allowedScopeIds, int $limit, ?string $scopePlaceholders = null, ?string $table = null ): array {
		$table             = $table ?? self::table();
		$scopePlaceholders = $scopePlaceholders ?? self::placeholders( $allowedScopeIds );

		$terms = Tokenizer::tokenize( $q );
		if ( $terms === [] ) {
			return [];
		}

		global $wpdb;

		$caseParts   = [];
		$caseParams  = [];
		$whereParts  = [];
		$whereParams = [];

		foreach ( $terms as $term ) {
			$likeParam  = '%' . $wpdb->esc_like( $term ) . '%';
			$regexParam = self::wholeWordRegexFragment( $term );

			$caseParts[]  = 'CASE WHEN ((content LIKE %s AND content REGEXP %s) OR (search_keys LIKE %s AND search_keys REGEXP %s)) THEN 1 ELSE 0 END';
			$caseParams[] = $likeParam;
			$caseParams[] = $regexParam;
			$caseParams[] = $likeParam;
			$caseParams[] = $regexParam;

			$whereParts[]  = '((content LIKE %s AND content REGEXP %s) OR (search_keys LIKE %s AND search_keys REGEXP %s))';
			$whereParams[] = $likeParam;
			$whereParams[] = $regexParam;
			$whereParams[] = $likeParam;
			$whereParams[] = $regexParam;
		}

		// OR across terms, not AND: any one tokenized term matching
		// (content or search_keys) qualifies the row. See the docblock
		// above for why this is safe (scope_id is the actual boundary).
		$whereExpr = '(' . implode( ' OR ', $whereParts ) . ')';

		// Fix round 2 (reviewer HIGH finding): when the number of matching
		// candidates exceeds BM25_POOL_LIMIT, the SQL LIMIT below is what
		// actually decides which rows ever reach the BM25 pass — a
		// candidate cut here can never be recovered by PHP-side ranking.
		// Ordering purely by recency (created DESC) before that cut could
		// silently drop the true best lexical match in favor of newer weak
		// ones. So the pool is ordered by the SAME distinct-matched-term
		// count the pre-BM25 code used as its whole relevance signal
		// (match_count, a SUM of per-term CASE expressions — cheap, index-
		// free, but a reasonable proxy for "worth keeping in the pool").
		//
		// Fix round 3 (reviewer HIGH, probe-verified): match_count alone
		// still ties whenever every candidate matches the same single
		// term exactly once (match_count = 1 for all of them) — e.g. a
		// one-word query against 500+ approved rows that each mention it
		// once. The old tiebreak (created DESC) then collapsed to
		// "newest wins", which can drop an old, short, precise row behind
		// hundreds of newer, longer, equally-1-term-matching rows. BM25
		// itself favors a SHORTER document at equal term frequency (that
		// is what the length-normalization term (1 - b + b*dl/avgdl) in
		// the denominator does), so ordering the pool by document length
		// ASCENDING as the next tiebreak — after match_count, before
		// recency — keeps the candidates BM25 would actually prefer in
		// the pool instead of discarding them for being merely older.
		// CHAR_LENGTH(content) mirrors (a cheap proxy for) the same text
		// rankByBm25() measures dl from; the two don't need to be
		// byte-identical (that one also includes search_keys) for this to
		// be a correct pool-survival heuristic — it only has to keep the
		// row BM25 would prefer from being cut before BM25 ever runs.
		// match_count and doc length here never reach PHP as the final
		// relevance — rankByBm25() below recomputes real BM25 scores for
		// every row that survives into the pool and re-sorts. See
		// fulltextMinRows()'s docblock and search()'s useLike guard for the
		// companion fixes (clamping the threshold, and refusing to run
		// this pool path at all when more rows are visible than it can
		// hold) that keep this pool from needing to truncate in the first
		// place under the documented, gated call path.
		$matchCountExpr = '(' . implode( ' + ', $caseParts ) . ')';
		$poolLimit      = max( $limit, self::BM25_POOL_LIMIT );

		$sql = "SELECT *, {$matchCountExpr} AS match_count FROM {$table}
			WHERE {$whereExpr}
			AND scope_id IN ({$scopePlaceholders}) AND status = %s
			ORDER BY match_count DESC, CHAR_LENGTH(content) ASC, created DESC, id DESC LIMIT %d";

		$params = array_merge( $caseParams, $whereParams, $allowedScopeIds, [ 'active', $poolLimit ] );

		$candidates = Db::getResults( $sql, $params );
		if ( $candidates === [] ) {
			return [];
		}

		$ranked = $this->rankByBm25( $candidates, $terms, $allowedScopeIds, $scopePlaceholders, $table );

		return array_slice( $ranked, 0, $limit );
	}

	/**
	 * Unit H: BM25-style re-ranking of a candidate pool already filtered (in
	 * SQL) to the caller's own visible, active, term-matching rows.
	 *
	 * IDF is computed from document frequency (df) counted with a dedicated
	 * COUNT query per term, scoped to the SAME allowedScopeIds/status
	 * predicate as every other query here — it counts only rows the caller
	 * can see, never rows from another scope, so a term being common in a
	 * different scope can never suppress (or a term being rare elsewhere
	 * inflate) this caller's ranking. That is the leak boundary for this
	 * feature: IDF must be scope-blind to everything outside
	 * $allowedScopeIds, exactly like every other query in this class.
	 *
	 * @param array<int, array<string, mixed>> $candidates
	 * @param string[]                         $terms
	 * @param int[]                            $allowedScopeIds
	 * @return array<int, array<string, mixed>>
	 */
	private function rankByBm25( array $candidates, array $terms, array $allowedScopeIds, string $scopePlaceholders, string $table ): array {
		$visibleCount = $this->visibleCount( $allowedScopeIds );
		$avgdl        = $this->averageVisibleDocLength( $allowedScopeIds, $scopePlaceholders, $table );
		$docFreq      = $this->termDocumentFrequencies( $terms, $allowedScopeIds, $scopePlaceholders, $table );

		$idf = [];
		foreach ( $terms as $term ) {
			$idf[ $term ] = self::idf( $visibleCount, $docFreq[ $term ] ?? 0 );
		}

		$patterns = [];
		foreach ( $terms as $term ) {
			$patterns[ $term ] = '/' . self::wholeWordRegexFragment( $term ) . '/iu';
		}

		foreach ( $candidates as $i => $row ) {
			$doc = ( (string) ( $row['content'] ?? '' ) ) . ' ' . ( (string) ( $row['search_keys'] ?? '' ) );
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

			$createdA = (string) ( $a['created'] ?? '' );
			$createdB = (string) ( $b['created'] ?? '' );

			if ( $createdA !== $createdB ) {
				return strcmp( $createdB, $createdA );
			}

			return ( (int) ( $b['id'] ?? 0 ) ) <=> ( (int) ( $a['id'] ?? 0 ) );
		} );

		return array_values( $candidates );
	}

	/**
	 * Average document length (chars of content + search_keys, space
	 * joined — same shape rankByBm25() measures each candidate's dl in) over
	 * every row visible to the caller, NOT just the term-matching
	 * candidates — real BM25 length normalization compares a document
	 * against the corpus it lives in, not against the result set. Scoped
	 * identically to visibleCount()/search() (scope_id IN (...) AND status
	 * = 'active'): never leaks another scope's document lengths into this
	 * caller's avgdl. A guard against 0 (empty/all-blank visible rows)
	 * avoids a division-by-zero in the BM25 length-norm term.
	 *
	 * @param int[] $allowedScopeIds
	 */
	private function averageVisibleDocLength( array $allowedScopeIds, string $scopePlaceholders, string $table ): float {
		$sql = "SELECT AVG(CHAR_LENGTH(CONCAT(content, ' ', search_keys))) AS avgdl FROM {$table}
			WHERE scope_id IN ({$scopePlaceholders}) AND status = %s";

		$params = array_merge( $allowedScopeIds, [ 'active' ] );
		$rows   = Db::getResults( $sql, $params );
		$avgdl  = isset( $rows[0]['avgdl'] ) ? (float) $rows[0]['avgdl'] : 0.0;

		return $avgdl > 0.0 ? $avgdl : 1.0;
	}

	/**
	 * Document frequency per term: how many of the caller's VISIBLE, active
	 * rows (across their allowed scopes only — same predicate as everywhere
	 * else in this class) contain that term, whole-word, in content or
	 * search_keys. One COUNT query per term (bounded by
	 * Tokenizer::MAX_TERMS = 12) — acceptable because this whole method is
	 * only reached below fulltextMinRows() visible rows (REC-3's own gate).
	 *
	 * This is the leak-sensitive part of Unit H: it must NEVER count rows
	 * outside $allowedScopeIds. A term that happens to be common in another
	 * scope must score exactly as it would if that other scope didn't
	 * exist.
	 *
	 * @param string[] $terms
	 * @param int[]    $allowedScopeIds
	 * @return array<string, int>
	 */
	private function termDocumentFrequencies( array $terms, array $allowedScopeIds, string $scopePlaceholders, string $table ): array {
		global $wpdb;

		$freqs = [];

		foreach ( $terms as $term ) {
			$likeParam  = '%' . $wpdb->esc_like( $term ) . '%';
			$regexParam = self::wholeWordRegexFragment( $term );

			$sql = "SELECT COUNT(*) AS c FROM {$table}
				WHERE ((content LIKE %s AND content REGEXP %s) OR (search_keys LIKE %s AND search_keys REGEXP %s))
				AND scope_id IN ({$scopePlaceholders}) AND status = %s";

			$params = array_merge( [ $likeParam, $regexParam, $likeParam, $regexParam ], $allowedScopeIds, [ 'active' ] );
			$rows   = Db::getResults( $sql, $params );

			$freqs[ $term ] = isset( $rows[0]['c'] ) ? (int) $rows[0]['c'] : 0;
		}

		return $freqs;
	}

	/**
	 * Okapi BM25 IDF (the "+1" / Lucene-style variant, which stays
	 * non-negative for any 0 <= df <= n, unlike the classic Robertson-Spärck
	 * Jones form that can go negative for very common terms): a term
	 * present in every visible row still contributes a small positive
	 * weight rather than penalizing rows that contain it.
	 */
	private static function idf( int $visibleCount, int $documentFrequency ): float {
		$n  = max( 0, $visibleCount );
		$df = max( 0, min( $documentFrequency, $n ) );

		return log( ( ( $n - $df + 0.5 ) / ( $df + 0.5 ) ) + 1 );
	}

	/**
	 * The whole-word regex fragment shared by the SQL REGEXP predicate and
	 * the PHP-side TF count in rankByBm25() — kept in exactly one place so
	 * "how do we define a word boundary" can never drift between the SQL
	 * candidate filter and the PHP scorer. See the searchLike() docblock for
	 * why this pattern (not plain '\b') is correct here.
	 */
	private static function wholeWordRegexFragment( string $term ): string {
		return '(?<![\\p{L}\\p{N}])' . $term . '(?![\\p{L}\\p{N}])';
	}

	/**
	 * REC-5 "working memory" section: an agent's own active working rows,
	 * scoped, most recent first.
	 *
	 * @param int[] $allowedScopeIds
	 * @return array<int, array<string, mixed>>
	 */
	public function recentByAgentAndType( int $agentId, string $type, array $allowedScopeIds, int $limit ): array {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		if ( $allowedScopeIds === [] || $agentId <= 0 || ! in_array( $type, MemoryRepository::TYPES, true ) ) {
			return [];
		}

		$limit        = max( 1, min( self::MAX_LIMIT, $limit ) );
		$table        = self::table();
		$placeholders = self::placeholders( $allowedScopeIds );

		$sql = "SELECT * FROM {$table}
			WHERE agent_id = %d AND type = %s AND status = %s AND scope_id IN ({$placeholders})
			ORDER BY created DESC, id DESC LIMIT %d";

		$params = array_merge( [ $agentId, $type, 'active' ], $allowedScopeIds, [ $limit ] );

		return Db::getResults( $sql, $params );
	}

	/**
	 * REC-5 "top episodes" section: caller-scoped episodes ranked by
	 * importance then recency (no free-text query involved).
	 *
	 * @param int[] $allowedScopeIds
	 * @return array<int, array<string, mixed>>
	 */
	public function recentByType( string $type, array $allowedScopeIds, int $limit ): array {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		if ( $allowedScopeIds === [] || ! in_array( $type, MemoryRepository::TYPES, true ) ) {
			return [];
		}

		$limit        = max( 1, min( self::MAX_LIMIT, $limit ) );
		$table        = self::table();
		$placeholders = self::placeholders( $allowedScopeIds );

		$sql = "SELECT * FROM {$table}
			WHERE type = %s AND status = %s AND scope_id IN ({$placeholders})
			ORDER BY importance DESC, created DESC, id DESC LIMIT %d";

		$params = array_merge( [ $type, 'active' ], $allowedScopeIds, [ $limit ] );

		return Db::getResults( $sql, $params );
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function sanitizeIds( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		return array_values( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) );
	}

	/** @param int[] $ids */
	private static function placeholders( array $ids ): string {
		return implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
	}
}
