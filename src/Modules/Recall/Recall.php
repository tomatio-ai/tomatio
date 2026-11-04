<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Recall;

use AgentBrain\Core\Text;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Review\ProposalService;
use Throwable;

/**
 * REC-1/REC-2/REC-3/REC-4: scoped, ranked search across raw memory and
 * approved knowledge/procedures.
 *
 * Every dependency is constructor-injectable so tests can substitute e.g. a
 * Scopes double that throws, or a poisoned vector-candidate filter, without
 * touching global state.
 */
class Recall {

	public const DEFAULT_LIMIT = 20;

	private Scopes $scopes;
	private MemoryScopedSearch $memorySearch;
	private KnowledgeSearch $knowledgeSearch;
	private MemoryRepository $memoryRepository;

	public function __construct(
		?Scopes $scopes = null,
		?MemoryScopedSearch $memorySearch = null,
		?KnowledgeSearch $knowledgeSearch = null,
		?MemoryRepository $memoryRepository = null
	) {
		$this->scopes           = $scopes ?? new Scopes();
		$this->memorySearch     = $memorySearch ?? new MemoryScopedSearch();
		$this->knowledgeSearch  = $knowledgeSearch ?? new KnowledgeSearch();
		$this->memoryRepository = $memoryRepository ?? new MemoryRepository();
	}

	/**
	 * @param array{limit?:int} $opts
	 * @return array<int, array{kind:string, id:int, scope_id:int, score:float, relevance:float, match:string, search_keys:?string, excerpt:string, created:string, importance:int, agent_id:int, client:?string, lifecycle:?string, updated:?string}>
	 */
	public function search( int $userId, string $q, array $opts = [] ): array {
		try {
			$allowedScopeIds = $this->scopes->readScopeIds( $userId );
		} catch ( Throwable $e ) {
			// REC-1: any exception in scope resolution ⇒ empty result, fail
			// closed. Never let the exception propagate to the caller and
			// never fall back to "unfiltered".
			$this->audit( $userId, 'error' );

			return [];
		}

		if ( $allowedScopeIds === [] ) {
			$this->audit( $userId, 'denied' );

			return [];
		}

		if ( trim( $q ) === '' ) {
			return [];
		}

		$limit   = max( 1, min( MemoryScopedSearch::MAX_LIMIT, (int) ( $opts['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$useLike = $this->memorySearch->usesLikeFallback( $allowedScopeIds );

		$candidates = [];

		foreach ( $this->memorySearch->search( $q, $allowedScopeIds, $limit, $useLike ) as $row ) {
			$candidates[] = $this->memoryRowToCandidate( $row );
		}

		foreach ( $this->knowledgeSearch->search( $q, $allowedScopeIds, $limit, $useLike ) as $row ) {
			$candidates[] = $this->postRowToCandidate( $row );
		}

		foreach ( $this->vectorCandidates( $userId, $q, $allowedScopeIds ) as $candidate ) {
			$candidates[] = $candidate;
		}

		$candidates = $this->withSynonymFallback( $candidates, $q, $allowedScopeIds, $limit, $useLike );

		$candidates = Ranker::normalizeRelevance( $candidates );

		foreach ( $candidates as $i => $candidate ) {
			// Floor after normalisation so an exact keyword match is never 0
			// (keyword 0.5-1, synonym 0.25-0.5); a synonym-only batch is still
			// ranked below an equal literal match.
			$candidates[ $i ]['relevance_norm'] = Ranker::floorRelevance( (float) $candidate['relevance_norm'], (string) ( $candidate['match'] ?? 'keyword' ), Synonyms::WEIGHT );
		}

		foreach ( $candidates as $i => $candidate ) {
			$candidates[ $i ]['score'] = Ranker::score( $candidate );
		}

		usort( $candidates, static function ( array $a, array $b ): int {
			// #594 (PCP 5.5 item 2): reference pages (lifecycle stale) come
			// after every current result, whatever their score.
			$byLifecycle = (int) ( ( $a['lifecycle'] ?? null ) !== null ) <=> (int) ( ( $b['lifecycle'] ?? null ) !== null );
			if ( $byLifecycle !== 0 ) {
				return $byLifecycle;
			}
			if ( $a['score'] === $b['score'] ) {
				// Equal combined rank: better match first, then stable by id.
				$byRelevance = $b['relevance_norm'] <=> $a['relevance_norm'];

				return $byRelevance !== 0 ? $byRelevance : $a['id'] <=> $b['id'];
			}

			return $b['score'] <=> $a['score'];
		} );

		$candidates = array_slice( $candidates, 0, $limit );

		$this->audit( $userId, 'ok' );

		return array_values( array_map( static function ( array $c ): array {
			return [
				'kind'       => $c['kind'],
				'id'         => $c['id'],
				'scope_id'   => $c['scope_id'],
				// score is the combined rank (relevance, recency, importance,
				// knowledge boost; see Ranker). relevance is match quality only
				// (0.5-1 for a keyword match, 0.25-0.5 for a synonym match: normalised within this result set, then floored so a literal match is never 0); importance is the
				// stored 0-100 value. Callers judging "does this match?" should
				// read relevance, not score.
				'score'      => round( $c['score'], 6 ),
				'relevance'  => round( (float) $c['relevance_norm'], 6 ),
				'match'      => (string) ( $c['match'] ?? 'keyword' ),
				'search_keys' => isset( $c['search_keys'] ) && $c['search_keys'] !== '' ? (string) $c['search_keys'] : null,
				'excerpt'    => $c['excerpt'],
				'created'    => $c['created'],
				'importance' => $c['importance'],
				// C1 (agentbrain-contracts.md "section-get / all Untrusted
				// wrappers carry the real agent_id"): memoryRowToCandidate()
				// and postRowToCandidate() already resolve this (memory rows:
				// agent_id column; knowledge/procedure rows: post_author) —
				// it was being computed and then dropped here before ever
				// reaching Abilities::registerMemorySearch()'s Untrusted::wrap().
				'agent_id'   => (int) ( $c['agent_id'] ?? 0 ),
				// P3 (the design contracts "Fix wave — mixed
				// Kimi + Claude team test", Unit P): result-shaping only —
				// which MCP client wrote the row, null for system-written
				// memories/rows with no client (all knowledge/procedure
				// posts, and any memory row written before this column
				// existed). No change to matching, ranking, or scope
				// filtering.
				'client'     => isset( $c['client'] ) && $c['client'] !== '' ? (string) $c['client'] : null,
				// #594: null for current items; "stale" for a reference page,
				// with updated, when its source last changed it.
				'lifecycle'  => $c['lifecycle'] ?? null,
				'updated'    => isset( $c['lifecycle'] ) ? (string) ( $c['updated'] ?? '' ) : null,
			];
		}, $candidates ) );
	}

	/**
	 * #64: when the literal query leaves room in the result set, retry with
	 * synonym terms only and merge the new rows in, marked match=synonym
	 * (their normalised relevance is down-weighted by Synonyms::WEIGHT).
	 * Rows already found by the literal query are never replaced or boosted.
	 *
	 * @param array<int, array<string, mixed>> $candidates
	 * @param int[]                            $allowedScopeIds
	 * @return array<int, array<string, mixed>>
	 */
	private function withSynonymFallback( array $candidates, string $q, array $allowedScopeIds, int $limit, bool $useLike ): array {
		if ( count( $candidates ) >= $limit ) {
			return $candidates;
		}

		$extra = Synonyms::expand( Tokenizer::tokenize( $q ) );
		if ( $extra === [] ) {
			return $candidates;
		}

		$seen = [];
		foreach ( $candidates as $c ) {
			$seen[ $c['kind'] . ':' . $c['id'] ] = true;
		}

		$expanded = implode( ' ', $extra );
		$found    = [];

		foreach ( $this->memorySearch->search( $expanded, $allowedScopeIds, $limit, $useLike ) as $row ) {
			$found[] = $this->memoryRowToCandidate( $row );
		}
		foreach ( $this->knowledgeSearch->search( $expanded, $allowedScopeIds, $limit, $useLike ) as $row ) {
			$found[] = $this->postRowToCandidate( $row );
		}

		foreach ( $found as $c ) {
			$key = $c['kind'] . ':' . $c['id'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ]   = true;
			$c['match']     = 'synonym';
			$candidates[]   = $c;
		}

		return $candidates;
	}

	/**
	 * REC-4: resolve+re-check every candidate the vector-search seam offers
	 * before it can ever reach the merged result set. A candidate whose id
	 * doesn't exist, or exists but isn't tagged with one of the caller's
	 * allowed scopes, is silently dropped here — this is the enforcement
	 * point a poisoned/compromised vector index cannot bypass.
	 *
	 * @param int[] $allowedScopeIds
	 * @return array<int, array<string, mixed>>
	 */
	private function vectorCandidates( int $userId, string $q, array $allowedScopeIds ): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return [];
		}

		$raw = apply_filters( 'agent_brain/recall_vector_candidates', [], $userId, $q );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$resolved = [];

		foreach ( $raw as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$kind        = (string) ( $candidate['kind'] ?? '' );
			$id          = (int) ( $candidate['id'] ?? 0 );
			$vectorScore = (float) ( $candidate['score'] ?? 0.0 );

			if ( $id <= 0 ) {
				continue;
			}

			if ( $kind === 'memory' ) {
				$row = $this->memoryRepository->get( $id, $allowedScopeIds );
				if ( $row === null ) {
					continue; // Out of scope or nonexistent: dropped, never merged.
				}
				if ( (string) ( $row['status'] ?? '' ) !== 'active' ) {
					continue; // Rolled up/deleted/proposed: invisible everywhere else in Recall, so dropped here too.
				}
				$row = $this->memoryRowToCandidate( $row );
			} elseif ( $kind === 'knowledge' || $kind === 'procedure' ) {
				$postType = $kind === 'procedure' ? 'ab_procedure' : 'ab_knowledge';
				$row      = $this->knowledgeSearch->getScoped( $id, $allowedScopeIds, $postType );
				if ( $row === null ) {
					continue; // Out of scope, not approved, or nonexistent: dropped.
				}
				$row = $this->postRowToCandidate( $row );
			} else {
				continue;
			}

			$row['relevance'] = max( $row['relevance'], $vectorScore );
			$resolved[]       = $row;
		}

		return $resolved;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function memoryRowToCandidate( array $row ): array {
		$content = (string) ( $row['content'] ?? '' );

		// Result-shaping only: memory rows are almost always already plain
		// text, but legacy outcome memories (pre-dating the plain-text
		// write path) can carry raw Gutenberg block markup the same as
		// knowledge/procedure posts do. Only run those through
		// Text::plainTextFromBlocks() -- gated on the literal block-comment
		// marker -- so ordinary plain-text memory rows are excerpted
		// byte-for-byte identically to before this fix.
		$excerptSource = str_contains( $content, '<!-- wp:' )
			? self::plainText( $content )
			: $content;

		return [
			'kind'          => 'memory',
			'id'            => (int) ( $row['id'] ?? 0 ),
			'scope_id'      => (int) ( $row['scope_id'] ?? 0 ),
			'relevance'     => (float) ( $row['relevance'] ?? 0.0 ),
			'importance'    => (int) ( $row['importance'] ?? 0 ),
			'created'       => (string) ( $row['created'] ?? '' ),
			'excerpt'       => self::excerpt( $excerptSource ),
			'content'       => $content,
			'status'        => (string) ( $row['status'] ?? '' ),
			'agent_id'      => (int) ( $row['agent_id'] ?? 0 ),
			'agent_written' => (int) ( $row['agent_written'] ?? 1 ),
			'search_keys'   => isset( $row['search_keys'] ) && is_string( $row['search_keys'] ) ? $row['search_keys'] : null,
			'client'        => isset( $row['client'] ) && $row['client'] !== '' ? (string) $row['client'] : null,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function postRowToCandidate( array $row ): array {
		$kind    = ( (string) ( $row['post_type'] ?? '' ) ) === 'ab_procedure' ? 'procedure' : 'knowledge';
		$title   = (string) ( $row['post_title'] ?? '' );
		$content = (string) ( $row['post_content'] ?? '' );
		$id      = (int) ( $row['ID'] ?? 0 );

		$importance = 70;
		if ( function_exists( 'get_post_meta' ) && $id > 0 ) {
			$meta = get_post_meta( $id, 'ab_importance', true );
			if ( is_numeric( $meta ) ) {
				$importance = max( 0, min( 100, (int) $meta ) );
			}
		}

		return [
			'kind'          => $kind,
			'id'            => $id,
			'scope_id'      => (int) ( $row['scope_id'] ?? 0 ),
			'relevance'     => (float) ( $row['relevance'] ?? 0.0 ),
			'importance'    => $importance,
			'created'       => (string) ( $row['post_date_gmt'] ?? '' ),
			// Knowledge/procedure post_content is always Gutenberg block
			// markup; excerpting the raw markup leaked `<!-- wp:paragraph
			// --><p>...` straight into agent-visible search results. Run it
			// through the same plain-text extraction Briefing/ReviewActions
			// already use before building the "title: text" excerpt.
			'excerpt'       => self::excerpt( trim( $title . ': ' . self::plainText( $content ) ) ),
			'content'       => $content !== '' ? $content : $title,
			'status'        => (string) ( $row['post_status'] ?? '' ),
			'agent_id'      => self::resolveKnowledgeAgentId( $id, (int) ( $row['post_author'] ?? 0 ) ),
			'agent_written' => 0,
			'lifecycle'     => isset( $row['lifecycle'] ) && is_string( $row['lifecycle'] ) && $row['lifecycle'] !== '' ? $row['lifecycle'] : null,
			'updated'       => (string) ( $row['post_modified_gmt'] ?? '' ),
		];
	}

	/**
	 * C1 fix-round: single source of truth for "who authored this
	 * knowledge/procedure post" (agentbrain-contracts.md "knowledge:
	 * proposing agent meta ab_proposing_agent else post_author"), so
	 * memory-search (via postRowToCandidate() above) and
	 * Mcp\Abilities::registerKnowledgeRead() can never diverge on the
	 * answer. The proposing agent (set when a fact is proposed into
	 * ab_knowledge via the review pipeline — see
	 * ProposalService::META_PROPOSING_AGENT) is the real author;
	 * post_author is only a fallback for rows that predate/bypass that
	 * pipeline.
	 */
	public static function resolveKnowledgeAgentId( int $postId, int $postAuthor ): int {
		if ( $postId <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return $postAuthor;
		}

		$proposingAgent = (int) get_post_meta( $postId, ProposalService::META_PROPOSING_AGENT, true );

		return $proposingAgent > 0 ? $proposingAgent : $postAuthor;
	}

	/**
	 * Result-shaping helper: strip Gutenberg block markup down to readable
	 * plain text via Core\Text::plainTextFromBlocks(), the same extraction
	 * Briefing/ReviewActions already use. Text::plainTextFromBlocks() relies
	 * on WordPress's wp_strip_all_tags()/parse_blocks(), neither of which
	 * exists under the no-WP unit-test bootstrap (RecallSearchTest exercises
	 * postRowToCandidate() with fakes there) -- guarded the same way the
	 * rest of this class already guards optional WP functions
	 * (function_exists( 'get_post_meta' ) above), so unit coverage keeps
	 * running against plain fixture content unchanged while the real
	 * (integration/production) path gets the fix.
	 */
	private static function plainText( string $content ): string {
		if ( ! function_exists( 'wp_strip_all_tags' ) ) {
			return $content;
		}

		return Text::plainTextFromBlocks( $content );
	}

	private static function excerpt( string $text, int $maxLen = 200 ): string {
		$normalized = preg_replace( '/\s+/', ' ', $text );
		$normalized = trim( $normalized ?? $text );

		if ( mb_strlen( $normalized ) <= $maxLen ) {
			return $normalized;
		}

		return mb_substr( $normalized, 0, $maxLen - 1 ) . '…';
	}

	private function audit( int $userId, string $status ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action( 'agent_brain/audit', [
			'actor_id' => $userId,
			'event'    => 'recall_search',
			'status'   => $status,
		] );
	}
}
