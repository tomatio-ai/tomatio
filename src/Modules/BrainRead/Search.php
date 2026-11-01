<?php

declare(strict_types=1);

namespace AgentBrain\Modules\BrainRead;

use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Recall\KnowledgeSearch;
use AgentBrain\Modules\Recall\MemoryScopedSearch;

/**
 * ART-25 (#165): the scoped read behind the brain-read endpoints. Takes an
 * already-resolved allowed scope list (see Grants::resolve — the caller
 * decided what the viewer may read) and never sees a user id, so the grant
 * intersection cannot be bypassed from inside the search.
 *
 * Read-only: this class never writes. Fail closed everywhere, the Recall
 * way: empty allowed scopes or a blank query return nothing without
 * touching the database.
 */
class Search {

	public const DEFAULT_LIMIT = 10;
	public const MAX_LIMIT     = 50;

	public const KIND_MEMORY    = 'memory';
	public const KIND_KNOWLEDGE = 'knowledge';
	public const KIND_PROCEDURE = 'procedure';

	private MemoryScopedSearch $memorySearch;
	private KnowledgeSearch $knowledgeSearch;
	private MemoryRepository $memoryRepository;

	public function __construct(
		?MemoryScopedSearch $memorySearch = null,
		?KnowledgeSearch $knowledgeSearch = null,
		?MemoryRepository $memoryRepository = null
	) {
		$this->memorySearch     = $memorySearch ?? new MemoryScopedSearch();
		$this->knowledgeSearch  = $knowledgeSearch ?? new KnowledgeSearch();
		$this->memoryRepository = $memoryRepository ?? new MemoryRepository();
	}

	/**
	 * @param int[] $allowedScopeIds
	 * @return array<int, array<string, mixed>> Items-shaped results, best relevance first.
	 */
	public function search( string $q, array $allowedScopeIds, int $limit = self::DEFAULT_LIMIT ): array {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		$limit           = max( 1, min( self::MAX_LIMIT, $limit ) );

		if ( $allowedScopeIds === [] || trim( $q ) === '' ) {
			return [];
		}

		$useLike = $this->memorySearch->usesLikeFallback( $allowedScopeIds );

		$items = [];
		foreach ( $this->memorySearch->search( $q, $allowedScopeIds, $limit, $useLike ) as $row ) {
			$items[] = Items::fromMemoryRow( $row );
		}
		foreach ( $this->knowledgeSearch->search( $q, $allowedScopeIds, $limit, $useLike ) as $row ) {
			$items[] = Items::fromPostRow( $row );
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				if ( $a['relevance'] !== $b['relevance'] ) {
					return $b['relevance'] <=> $a['relevance'];
				}

				return $a['id'] <=> $b['id'];
			}
		);

		return array_slice( $items, 0, $limit );
	}

	/**
	 * Read one row by id and kind, or null when it does not exist, is not
	 * active/approved, or is outside the caller's allowed scopes — one
	 * uniform null so ids can never be probed for existence.
	 *
	 * @param int[] $allowedScopeIds
	 * @return array<string, mixed>|null
	 */
	public function get( int $id, string $kind, array $allowedScopeIds ): ?array {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		if ( $allowedScopeIds === [] || $id <= 0 ) {
			return null;
		}

		if ( $kind === self::KIND_MEMORY ) {
			$row = $this->memoryRepository->get( $id, $allowedScopeIds );
			if ( $row === null || (string) ( $row['status'] ?? '' ) !== 'active' ) {
				return null;
			}

			return Items::fromMemoryRow( $row );
		}

		if ( $kind === self::KIND_KNOWLEDGE || $kind === self::KIND_PROCEDURE ) {
			$row = $this->knowledgeSearch->getScoped( $id, $allowedScopeIds, 'ab_' . $kind );
			if ( $row === null ) {
				return null;
			}

			return Items::fromPostRow( $row );
		}

		return null;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function sanitizeIds( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		return array_values( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) );
	}
}
