<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Recall;

use AgentBrain\Core\Text;
use AgentBrain\Core\Untrusted;
use AgentBrain\Modules\Identity\Scopes;
use Throwable;

/**
 * REC-5: `Briefing::build()` returns one token-budgeted block for session
 * start — working memory, top episodes, approved knowledge and relevant
 * procedures, in that fixed order, every item wrapped as untrusted content.
 *
 * Fails closed exactly like Recall::search(): any exception in scope
 * resolution, or an empty scope list, returns an empty briefing rather than
 * throwing or falling back to unfiltered content.
 */
class Briefing {

	public const DEFAULT_TOKEN_BUDGET = 2000;
	public const SECTION_LIMIT        = 5;
	public const WORKING_LIMIT        = 10;

	/**
	 * Floor of the per-item truncation ALLOWANCE: a truncated excerpt may
	 * spend at most max(this, 25% of the overall token budget) on itself --
	 * never "everything left" -- so one oversized item can never crowd out
	 * the smaller items scanned after it. Live evidence this corrects: an
	 * 8,000-token briefing spent ~6,600 tokens truncating a single 27 KB
	 * memory and skipped both approved-knowledge items that would otherwise
	 * have fit.
	 *
	 * This is NOT a minimum-remaining-budget gate for whether to attempt
	 * truncation at all -- that used to be a separate `budgetLeft >= 200`
	 * pre-check, which was wrong: it could reject a truncation that would
	 * actually have fit (e.g. a single CJK character plus the fixed
	 * wrap/marker overhead can cost well under 200 tokens). Feasibility is
	 * decided by truncatedWrap() itself, whichever script is involved.
	 */
	private const MIN_TRUNCATION_TOKENS = 200;

	/** @see self::MIN_TRUNCATION_TOKENS */
	private const TRUNCATION_ALLOWANCE_FRACTION = 0.25;

	private const SECTION_LABELS = [
		'working_memory'      => 'Working memory',
		'top_episodes'        => 'Recent episodes',
		'approved_knowledge'  => 'Approved knowledge',
		'relevant_procedures' => 'Relevant procedures',
	];

	/** Section order is fixed regardless of packing pass order (Unit E). */
	private const MEMORY_SECTION_KEYS = [ 'working_memory', 'top_episodes' ];

	/** @see self::MEMORY_SECTION_KEYS */
	private const KNOWLEDGE_SECTION_KEYS = [ 'approved_knowledge', 'relevant_procedures' ];

	/**
	 * Fraction of the post-preamble budget that may be reserved for
	 * approved_knowledge/relevant_procedures so a busy working-memory +
	 * recent-episodes pool (skip-not-break lets it use the whole budget
	 * before knowledge is even scanned) can never crowd approved knowledge
	 * out entirely (live: agent-a's default 2,000-token briefing contained
	 * no approved knowledge at all).
	 */
	private const RESERVE_FRACTION = 0.35;

	private Scopes $scopes;
	private MemoryScopedSearch $memorySearch;
	private KnowledgeSearch $knowledgeSearch;

	public function __construct(
		?Scopes $scopes = null,
		?MemoryScopedSearch $memorySearch = null,
		?KnowledgeSearch $knowledgeSearch = null
	) {
		$this->scopes          = $scopes ?? new Scopes();
		$this->memorySearch    = $memorySearch ?? new MemoryScopedSearch();
		$this->knowledgeSearch = $knowledgeSearch ?? new KnowledgeSearch();
	}

	/** Option holding the default briefing budget (Settings > Onboarding). */
	public const OPTION_BUDGET = 'ab_briefing_default_tokens';
	public const MIN_BUDGET    = 500;
	public const MAX_BUDGET    = 32000;
	/** Pinned items per section: a pin is for essentials, not a dump. */
	public const PINNED_LIMIT  = 20;

	public static function defaultTokenBudget(): int {
		if ( ! function_exists( 'get_option' ) ) {
			return self::DEFAULT_TOKEN_BUDGET;
		}

		$budget = (int) get_option( self::OPTION_BUDGET, self::DEFAULT_TOKEN_BUDGET );

		return $budget > 0 ? $budget : self::DEFAULT_TOKEN_BUDGET;
	}

	/**
	 * @return array{text:string, tokens:int, items:array<int, array{kind:string, id:int}>, reason?:string, min_tokens?:int}
	 */
	public function build( int $userId, ?int $tokenBudget = null, string $focus = '' ): array {
		$tokenBudget = max( 0, $tokenBudget ?? self::defaultTokenBudget() );

		try {
			$allowedScopeIds = $this->scopes->readScopeIds( $userId );
		} catch ( Throwable $e ) {
			return [ 'text' => '', 'tokens' => 0, 'items' => [] ];
		}

		if ( $allowedScopeIds === [] ) {
			return [ 'text' => '', 'tokens' => 0, 'items' => [] ];
		}

		$sections = [
			'working_memory'      => $this->workingMemory( $userId, $allowedScopeIds ),
			'top_episodes'        => $this->topEpisodes( $allowedScopeIds ),
			'approved_knowledge'  => $this->approvedKnowledge( $allowedScopeIds, $focus ),
			'relevant_procedures' => $this->relevantProcedures( $allowedScopeIds, $focus ),
		];

		return $this->assemble( $sections, $tokenBudget );
	}

	/**
	 * A partial safety preamble is worse than none: it is meaningless (and
	 * therefore misleading) as a security instruction once truncated, so a
	 * budget that cannot fit the complete, untruncated
	 * {@see Untrusted::preamble()} yields an empty briefing rather than a
	 * mangled one. Individual items are different: one item not fitting must
	 * never block later, smaller items from the same or a later section
	 * (scan continues, it never stops at the first miss -- see the loop
	 * below), and an item that alone exceeds what's left is included as a
	 * truncated plain-text excerpt (capped at {@see self::MIN_TRUNCATION_TOKENS}
	 * or 25% of the overall budget, whichever is larger -- never "everything
	 * left", so one huge item can't crowd out the smaller ones scanned after
	 * it) plus a pointer to read the full item, rather than dropped outright,
	 * whenever any budget at all remains for it. No item -- whole or
	 * truncated -- is ever emitted without the complete preamble ahead of it.
	 *
	 * @param array<string, array<int, array{content:string, provenance:array<string, mixed>, kind:string, id:int}>> $sections
	 * @return array{text:string, tokens:int, items:array<int, array{kind:string, id:int}>, reason?:string, min_tokens?:int, truncated_ids?:array<int, array{kind:string, id:int}>, skipped?:int, reserved_tokens?:int} `reason` is present (as `'budget_too_small'`) and `min_tokens` gives the token count the complete preamble needs, only when the budget could not even fit the preamble; in that case `text` is `''`, `tokens` is `0`, and `items` is `[]`. Otherwise `truncated_ids` lists items included as a truncated excerpt and `skipped` counts items that fit nowhere. `reserved_tokens` (Unit E) is present when approved_knowledge/relevant_procedures had items and reports the token reserve R planned for them.
	 */
	private function assemble( array $sections, int $tokenBudget ): array {
		$preamble       = Untrusted::preamble();
		$preambleTokens = self::tokenEstimate( $preamble );

		if ( $preambleTokens > $tokenBudget ) {
			// The fixed preamble alone doesn't fit: never emit a truncated
			// preamble (a partial safety instruction is worse than none) and
			// never emit memory items without the complete preamble ahead of
			// them, so return an empty briefing instead.
			return [
				'text'       => '',
				'tokens'     => 0,
				'items'      => [],
				'reason'     => 'budget_too_small',
				'min_tokens' => $preambleTokens,
			];
		}

		$truncationAllowance = max(
			self::MIN_TRUNCATION_TOKENS,
			(int) floor( $tokenBudget * self::TRUNCATION_ALLOWANCE_FRACTION )
		);

		// Per-section packing state, kept separate so the final text can
		// always be assembled in the fixed section order (working memory,
		// recent episodes, approved knowledge, relevant procedures)
		// regardless of which order the passes below actually pack items in.
		// Each pending item is tagged with its original scan index (its
		// position within its section's input list) so that, however many
		// passes it takes to place an item -- pass 1, or a later pass 3
		// recovery once knowledge returns unused budget -- the final output
		// is always reassembled in that original scan order, never pass
		// order (see self::packPass() and the sort below).
		$state = [];
		foreach ( self::SECTION_LABELS as $sectionKey => $label ) {
			$pending = [];
			foreach ( ( $sections[ $sectionKey ] ?? [] ) as $scanIndex => $item ) {
				$item['index'] = $scanIndex;
				$pending[]     = $item;
			}

			$state[ $sectionKey ] = [
				'header'          => "\n\n## " . $label . "\n",
				'headerTokens'    => self::tokenEstimate( "\n\n## " . $label . "\n" ),
				'headerCommitted' => false,
				'entries'         => [],
				'pending'         => $pending,
			];
		}

		$tokensUsed = $preambleTokens;
		$available  = $tokenBudget - $preambleTokens;

		$hasKnowledgeItems = false;
		foreach ( self::KNOWLEDGE_SECTION_KEYS as $key ) {
			if ( $state[ $key ]['pending'] !== [] ) {
				$hasKnowledgeItems = true;
				break;
			}
		}

		$reserved = 0;
		if ( $hasKnowledgeItems ) {
			$need = self::wrappedNeed( $state );
			$reserved = min( $need, (int) floor( $available * self::RESERVE_FRACTION ) );
			// Pinned items are essentials: reserve enough to carry them whole
			// even when that exceeds the usual share.
			$reserved = max( $reserved, min( self::wrappedNeed( $state, true ), $available ) );
		}

		// Pass 1: working memory + recent episodes, capped so approved
		// knowledge/relevant procedures are guaranteed at least $reserved
		// tokens of what remains.
		$memoryCap = $tokenBudget - $reserved;
		foreach ( self::MEMORY_SECTION_KEYS as $key ) {
			self::packPass( $state[ $key ], $memoryCap, $tokensUsed, $truncationAllowance );
		}

		// Pass 2: approved knowledge + relevant procedures fill from
		// whatever remains (>= $reserved -- memory pass 1 may not have used
		// its whole cap).
		foreach ( self::KNOWLEDGE_SECTION_KEYS as $key ) {
			self::packPass( $state[ $key ], $tokenBudget, $tokensUsed, $truncationAllowance );
		}

		// Pass 3: if knowledge needed less than the reserve, the unused
		// share returns to memory -- retry whatever working memory/episode
		// items didn't fit in pass 1 against the real leftover budget.
		if ( $tokenBudget - $tokensUsed > 0 ) {
			foreach ( self::MEMORY_SECTION_KEYS as $key ) {
				if ( $state[ $key ]['pending'] !== [] ) {
					self::packPass( $state[ $key ], $tokenBudget, $tokensUsed, $truncationAllowance );
				}
			}
		}

		$textParts    = [ $preamble ];
		$items        = [];
		$truncatedIds = [];
		$skipped      = 0;

		foreach ( self::SECTION_LABELS as $sectionKey => $label ) {
			// Entries carry the header (index -1, always first) and each
			// placed item tagged with its ORIGINAL scan index regardless of
			// which pass placed it -- sorting here, not append order,
			// decides the emitted order, so a pass-3 recovery never lands
			// after items that were scanned later than it (B1's
			// deterministic-scan-order rule still holds across passes).
			$entries = $state[ $sectionKey ]['entries'];
			usort( $entries, static fn ( array $a, array $b ): int => $a['index'] <=> $b['index'] );

			foreach ( $entries as $entry ) {
				$textParts[] = $entry['text'];

				if ( $entry['item'] !== null ) {
					$items[] = $entry['item'];

					if ( $entry['truncated'] ) {
						$truncatedIds[] = $entry['item'];
					}
				}
			}

			$skipped += count( $state[ $sectionKey ]['pending'] );
		}

		$result = [
			'text'          => implode( '', $textParts ),
			'tokens'        => $tokensUsed,
			'items'         => $items,
			'truncated_ids' => $truncatedIds,
			'skipped'       => $skipped,
		];

		if ( $hasKnowledgeItems ) {
			$result['reserved_tokens'] = $reserved;
		}

		return $result;
	}

	/**
	 * The token cost of including every approved_knowledge/relevant_procedures
	 * item in full (header + all items, untruncated) -- used only to size the
	 * reserve R, never to actually pack anything.
	 *
	 * @param array<string, array{header:string, headerTokens:int, pending:array<int, array{content:string, provenance:array<string, mixed>, kind:string, id:int, index:int, pinned?:bool}>}> $state
	 */
	private static function wrappedNeed( array $state, bool $pinnedOnly = false ): int {
		$total = 0;

		foreach ( self::KNOWLEDGE_SECTION_KEYS as $key ) {
			$pending = $state[ $key ]['pending'];
			if ( $pending === [] ) {
				continue;
			}

			$counted = false;
			foreach ( $pending as $item ) {
				if ( $pinnedOnly && empty( $item['pinned'] ) ) {
					continue;
				}
				if ( ! $counted ) {
					$total  += $state[ $key ]['headerTokens'];
					$counted = true;
				}
				$wrapped = Untrusted::wrap( $item['content'], $item['provenance'] ) . "\n";
				$total  += self::tokenEstimate( $wrapped );
			}
		}

		return $total;
	}

	/**
	 * Scans $section['pending'] once against the given cap (shared,
	 * cumulative $tokensUsed), moving every item that fits -- whole or as a
	 * truncated excerpt -- into $section['entries'] (tagged with the item's
	 * original scan index, never pass/append order -- see the sort in
	 * self::assemble()), and leaving whatever still doesn't fit in
	 * $section['pending'] for a possible later pass. Skip, not break: a huge
	 * item never blocks a smaller one later in the same pending list.
	 *
	 * @param array{header:string, headerTokens:int, headerCommitted:bool, entries:array<int, array{index:int, text:string, item:array{kind:string, id:int}|null, truncated:bool}>, pending:array<int, array{content:string, provenance:array<string, mixed>, kind:string, id:int, index:int, pinned?:bool}>} $section
	 */
	private static function packPass( array &$section, int $cap, int &$tokensUsed, int $truncationAllowance ): void {
		$stillPending = [];

		foreach ( $section['pending'] as $item ) {
			$wrapped       = Untrusted::wrap( $item['content'], $item['provenance'] ) . "\n";
			$wrappedTokens = self::tokenEstimate( $wrapped );
			$pendingHeader = $section['headerCommitted'] ? 0 : $section['headerTokens'];
			$budgetLeft    = $cap - $tokensUsed - $pendingHeader;

			if ( $wrappedTokens <= $budgetLeft ) {
				if ( ! $section['headerCommitted'] ) {
					$section['entries'][]       = [ 'index' => -1, 'text' => $section['header'], 'item' => null, 'truncated' => false ];
					$tokensUsed                += $section['headerTokens'];
					$section['headerCommitted']  = true;
				}

				$section['entries'][] = [
					'index'     => $item['index'],
					'text'      => $wrapped,
					'item'      => [ 'kind' => $item['kind'], 'id' => $item['id'] ],
					'truncated' => false,
				];
				$tokensUsed += $wrappedTokens;
				continue;
			}

			if ( $budgetLeft > 0 ) {
				// Never hand a truncated item more than the allowance, even
				// when far more than that remains in the overall budget --
				// that's what let one oversized item crowd out every smaller
				// item scanned after it. truncatedWrap() itself is the real
				// feasibility test (it returns null the moment not even a
				// single character plus the fixed wrap/marker overhead
				// fits).
				// Pinned items are exempt from the allowance: they get
				// everything that is left (they are few and curated).
				$itemTokenBudget = empty( $item['pinned'] ) ? min( $budgetLeft, $truncationAllowance ) : $budgetLeft;
				$truncated       = self::truncatedWrap( $item, $itemTokenBudget );
				if ( $truncated !== null ) {
					if ( ! $section['headerCommitted'] ) {
						$section['entries'][]       = [ 'index' => -1, 'text' => $section['header'], 'item' => null, 'truncated' => false ];
						$tokensUsed                += $section['headerTokens'];
						$section['headerCommitted']  = true;
					}

					$section['entries'][] = [
						'index'     => $item['index'],
						'text'      => $truncated['wrapped'],
						'item'      => [ 'kind' => $item['kind'], 'id' => $item['id'] ],
						'truncated' => true,
					];
					$tokensUsed += $truncated['tokens'];
					continue;
				}
			}

			// Doesn't fit, whole or truncated, at this cap: leave it pending
			// -- a later pass (or the final accounting as "skipped") decides
			// its fate, scan order is otherwise preserved.
			$stillPending[] = $item;
		}

		$section['pending'] = $stillPending;
	}

	/**
	 * Build a truncated, plain-text excerpt of $item's content (cut on a word
	 * boundary) followed by a pointer to the full item via the matching MCP
	 * read ability, wrapped exactly like a whole item -- or null if even a
	 * single character of excerpt plus the pointer cannot fit in
	 * $budgetTokens.
	 *
	 * The excerpt length (in characters) is found by BISECTING against the
	 * real token estimate of the fully wrapped output (Untrusted::wrap() +
	 * marker), not by guessing a byte-based character budget and stepping
	 * down by a fixed amount -- a fixed step can never converge for scripts
	 * where a handful of characters swings the byte count a lot (CJK,
	 * emoji): a prior version of this method returned null (skipping the
	 * item outright) at every tested budget for no-space CJK content while
	 * the same logic worked fine for ASCII. Bisection needs only
	 * O(log(content length)) iterations and is correct for any script at any
	 * budget, because it always measures the actual wrapped byte length of
	 * each candidate rather than estimating it.
	 *
	 * @param array{content:string, provenance:array<string, mixed>, kind:string, id:int} $item
	 * @return array{wrapped:string, tokens:int}|null
	 */
	private static function truncatedWrap( array $item, int $budgetTokens ): ?array {
		$refAbility = $item['kind'] === 'memory' ? 'memory-get' : 'knowledge-read';
		$marker     = sprintf( ' [truncated — full text: %s id %d]', $refAbility, $item['id'] );
		$provenance = $item['provenance'];
		$content    = $item['content'];

		/** @return array{wrapped:string, tokens:int}|null */
		$candidateAt = static function ( int $chars ) use ( $content, $marker, $provenance ): ?array {
			if ( $chars <= 0 ) {
				return null;
			}

			$excerpt = Text::trimToWordBoundary( $content, $chars );
			if ( $excerpt === '' ) {
				return null;
			}

			$wrapped = Untrusted::wrap( $excerpt . $marker, $provenance ) . "\n";

			return [ 'wrapped' => $wrapped, 'tokens' => self::tokenEstimate( $wrapped ) ];
		};

		// Even a single character (plus the fixed marker/wrap overhead)
		// doesn't fit: no truncation is possible at this budget.
		$best = $candidateAt( 1 );
		if ( $best === null || $best['tokens'] > $budgetTokens ) {
			return null;
		}

		$contentLength = mb_strlen( $content, 'UTF-8' );
		$lo            = 1;
		$hi            = $contentLength;
		// Bounded by content length (log2(n) plus a small constant margin
		// for the word-boundary snap not being perfectly monotonic) rather
		// than a fixed iteration count tied to a fixed step size.
		$maxIterations = $contentLength > 1 ? ( (int) ceil( log( $contentLength, 2 ) ) + 4 ) : 0;

		for ( $i = 0; $i < $maxIterations && $lo < $hi; $i++ ) {
			$mid       = (int) floor( ( $lo + $hi + 1 ) / 2 );
			$candidate = $candidateAt( $mid );

			if ( $candidate !== null && $candidate['tokens'] <= $budgetTokens ) {
				$lo   = $mid;
				$best = $candidate;
			} else {
				$hi = $mid - 1;
			}
		}

		return $best;
	}

	/**
	 * @param int[] $allowedScopeIds
	 * @return array<int, array{content:string, provenance:array<string, mixed>, kind:string, id:int}>
	 */
	private function workingMemory( int $userId, array $allowedScopeIds ): array {
		$rows = $this->memorySearch->recentByAgentAndType( $userId, 'working', $allowedScopeIds, self::WORKING_LIMIT );

		return array_map( [ self::class, 'memoryRowToSectionItem' ], $rows );
	}

	/**
	 * @param int[] $allowedScopeIds
	 * @return array<int, array{content:string, provenance:array<string, mixed>, kind:string, id:int}>
	 */
	private function topEpisodes( array $allowedScopeIds ): array {
		$rows = $this->memorySearch->recentByType( 'episode', $allowedScopeIds, self::SECTION_LIMIT );

		return array_map( [ self::class, 'memoryRowToSectionItem' ], $rows );
	}

	/**
	 * @param int[] $allowedScopeIds
	 * @return array<int, array{content:string, provenance:array<string, mixed>, kind:string, id:int}>
	 */
	private function approvedKnowledge( array $allowedScopeIds, string $focus ): array {
		return $this->postSection( 'ab_knowledge', $allowedScopeIds, $focus );
	}

	/**
	 * @param int[] $allowedScopeIds
	 * @return array<int, array{content:string, provenance:array<string, mixed>, kind:string, id:int}>
	 */
	private function relevantProcedures( array $allowedScopeIds, string $focus ): array {
		return $this->postSection( 'ab_procedure', $allowedScopeIds, $focus );
	}

	/**
	 * @param int[] $allowedScopeIds
	 * @return array<int, array{content:string, provenance:array<string, mixed>, kind:string, id:int}>
	 */
	private function postSection( string $postType, array $allowedScopeIds, string $focus ): array {
		// Curator-pinned items come first and are always briefed (whole when
		// the budget allows), whatever the focus; the rest follow, deduped.
		$pinnedRows = $this->knowledgeSearch->pinned( $postType, $allowedScopeIds, self::PINNED_LIMIT );
		$pinnedIds  = array_map( static fn ( array $row ): int => (int) ( $row['ID'] ?? 0 ), $pinnedRows );

		if ( trim( $focus ) !== '' ) {
			$useLike = $this->memorySearch->usesLikeFallback( $allowedScopeIds );
			$rows    = $this->knowledgeSearch->search( $focus, $allowedScopeIds, self::SECTION_LIMIT, $useLike, $postType );
		} else {
			$rows = $this->knowledgeSearch->recent( $postType, $allowedScopeIds, self::SECTION_LIMIT );
		}

		$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => ! in_array( (int) ( $row['ID'] ?? 0 ), $pinnedIds, true ) ) );

		// #594 (PCP 5.5 item 2): reference pages (lifecycle stale) are briefed
		// only when no current item matches the focus.
		$current = array_values( array_filter( $rows, static fn ( array $row ): bool => empty( $row['lifecycle'] ) ) );
		if ( $current !== [] ) {
			$rows = $current;
		}

		$items = array_map( [ self::class, 'postRowToSectionItem' ], $pinnedRows );
		foreach ( $items as $i => $item ) {
			$items[ $i ]['pinned'] = true;
		}

		return array_merge( $items, array_map( [ self::class, 'postRowToSectionItem' ], $rows ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{content:string, provenance:array<string, mixed>, kind:string, id:int}
	 */
	private static function memoryRowToSectionItem( array $row ): array {
		$id      = (int) ( $row['id'] ?? 0 );
		$content = (string) ( $row['content'] ?? '' );

		// Result-shaping only: memory rows are almost always already plain
		// text, but legacy outcome memories (pre-dating the plain-text write
		// path) can carry raw Gutenberg block markup the same as
		// knowledge/procedure posts do. Only run those through
		// Text::plainTextFromBlocks() -- gated on the literal block-comment
		// marker, same as Recall::memoryRowToCandidate() -- so ordinary
		// plain-text memory rows reach the briefing byte-for-byte identically
		// to before this fix.
		if ( str_contains( $content, '<!-- wp:' ) ) {
			$content = self::plainText( $content );
		}

		return [
			'content' => $content,
			'kind'    => 'memory',
			'id'      => $id,
			'provenance' => [
				'source'        => (string) ( $row['type'] ?? 'memory' ),
				'agent_id'      => (int) ( $row['agent_id'] ?? 0 ),
				'memory_id'     => $id,
				'agent_written' => (bool) ( (int) ( $row['agent_written'] ?? 1 ) ),
			],
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{content:string, provenance:array<string, mixed>, kind:string, id:int}
	 */
	private static function postRowToSectionItem( array $row ): array {
		$kind    = ( (string) ( $row['post_type'] ?? '' ) ) === 'ab_procedure' ? 'procedure' : 'knowledge';
		$title   = (string) ( $row['post_title'] ?? '' );
		$content = (string) ( $row['post_content'] ?? '' );
		$id      = (int) ( $row['ID'] ?? 0 );

		return [
			// B1 (agentbrain-contracts.md "Fix wave -- long-form paper
			// test"): knowledge/procedure post_content is always Gutenberg
			// block markup; carrying it raw into the briefing wasted budget
			// on markup ("TomaTío Launch Brief: <!-- wp:heading
			// --><h2...") and violated the "a truncated excerpt is plain
			// text" contract before any truncation even happened. Run it
			// through the same plain-text extraction Recall/ReviewActions
			// already use before building the "title: text" item -- only
			// what an item's text CONTAINS changes; budgeting, ordering,
			// reservation, truncation and the Untrusted wrap are untouched.
			'content' => trim( $title . ': ' . self::plainText( $content ) ),
			'kind'    => $kind,
			'id'      => $id,
			'provenance' => [
				'source'        => $kind,
				'agent_id'      => (int) ( $row['post_author'] ?? 0 ),
				'memory_id'     => $id,
				'agent_written' => false,
				'approved_by'   => self::approvedBy( $id ),
				'lifecycle'     => is_string( $row['lifecycle'] ?? null ) && $row['lifecycle'] !== '' ? $row['lifecycle'] : null,
				'as_of'         => substr( (string) ( $row['post_modified_gmt'] ?? '' ), 0, 10 ),
			],
		];
	}

	/**
	 * Who approved this knowledge, so agents see a person's decision behind
	 * the text (docs/rfc/people-write-through-agents.md). Null when unknown.
	 */
	private static function approvedBy( int $postId ): ?string {
		if ( $postId <= 0 || ! function_exists( 'get_post_meta' ) || ! function_exists( 'get_userdata' ) ) {
			return null;
		}

		$reviewer = get_userdata( (int) get_post_meta( $postId, \AgentBrain\Modules\Review\ReviewActions::META_REVIEWED_BY, true ) );

		return $reviewer instanceof \WP_User ? (string) $reviewer->display_name : null;
	}

	/**
	 * Result-shaping helper: strip Gutenberg block markup down to readable
	 * plain text via Core\Text::plainTextFromBlocks(), the same extraction
	 * Recall/ReviewActions already use. Text::plainTextFromBlocks() relies on
	 * WordPress's wp_strip_all_tags()/parse_blocks(), neither of which exists
	 * under the no-WP unit-test bootstrap (BriefingBuildTest exercises
	 * postRowToSectionItem()/memoryRowToSectionItem() with fakes there) --
	 * guarded the same way Recall::plainText() already guards this, so unit
	 * coverage keeps running against plain fixture content unchanged while
	 * the real (integration/production) path gets the fix.
	 */
	private static function plainText( string $content ): string {
		if ( ! function_exists( 'wp_strip_all_tags' ) ) {
			return $content;
		}

		return Text::plainTextFromBlocks( $content );
	}

	/**
	 * Documented, swappable token estimate: ceil(strlen/4), filterable via
	 * `agent_brain/token_estimate` ( int $estimate, string $text ).
	 */
	private static function tokenEstimate( string $text ): int {
		$estimate = (int) ceil( strlen( $text ) / 4 );

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'agent_brain/token_estimate', $estimate, $text );
			if ( is_int( $filtered ) || is_float( $filtered ) ) {
				return (int) $filtered;
			}
		}

		return $estimate;
	}
}
