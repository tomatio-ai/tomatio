<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Artifacts\Stages;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Mcp\Present;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Recall\KnowledgeSearch;
use AgentBrain\Modules\Review\ReviewActions;

/**
 * PROV-4 (#173): the provenance block — one shape summarising where an item
 * came from and who approved it, shared by the MCP reads (memory-get,
 * knowledge-read, section-get attach it as a `provenance` key) and the
 * wp-admin provenance panel (which renders the same data with the
 * curator-only extras: action context and blame).
 *
 * Shape, per item kind:
 *
 *  - sources:      the outside-source records the item names (PROV-3;
 *                  memory rows only today — the link table's item kinds).
 *  - cites:        the brain items the author cited (PROV-2): identifiers
 *                  and versions only, never content.
 *  - derived_from: the artifact / section versions the item was built from
 *                  (PROV-1): identifiers and versions only.
 *  - approvals:    who approved it, through which client (PROV-6): reviewer
 *                  id, time, client label, via (the person behind a
 *                  delegated token) and whether an AI client relayed it.
 *                  Memories are never curator-approved, so a memory block
 *                  carries an empty list.
 *
 * Read scoping: every builder takes an optional $readerId. Pass null from
 * trusted, scope-agnostic callers (the wp-admin panel — curators see the
 * unfiltered view). Pass the MCP actor id from the read abilities: cites
 * and derived-from entries the reader could not fetch itself are dropped,
 * so the block can never confirm the existence of an item outside the
 * caller's read authority (a dropped entry is indistinguishable from one
 * that never existed). Outside-source records ride along only as attributes
 * of an item the caller may already read. Approvals are the published
 * decision record of readable content, exactly as the review stamps and
 * the blame abilities already expose them.
 */
final class Provenance {

	/**
	 * The provenance block for a memory row (ab_memory).
	 *
	 * @return array{sources: array<int, array<string, mixed>>, cites: array<int, array{kind:string, id:int, version:?int}>, derived_from: array<int, array{kind:string, id:int, version:?int}>, approvals: array<int, array<string, mixed>>}
	 */
	public static function forMemory( int $memoryId, ?int $readerId = null ): array {
		return [
			'sources'      => array_map(
				static fn( array $row ): array => self::sourceBlock( $row ),
				( new OutsideSources() )->sourcesOf( 'memory', $memoryId )
			),
			'cites'        => self::visibleLinks( ( new ItemCites() )->citesOf( 'memory', $memoryId ), $readerId ),
			'derived_from' => self::visibleLinks( ( new DerivedFrom() )->sourcesOf( 'memory', $memoryId ), $readerId ),
			'approvals'    => [],
		];
	}

	/**
	 * The provenance block for a knowledge / procedure / compiled post
	 * (ab_knowledge, ab_procedure, or the post artifact-compile wrote).
	 *
	 * @return array{sources: array<int, array<string, mixed>>, cites: array<int, array{kind:string, id:int, version:?int}>, derived_from: array<int, array{kind:string, id:int, version:?int}>, approvals: array<int, array<string, mixed>>}
	 */
	public static function forPost( int $postId, ?int $readerId = null ): array {
		return [
			'sources'      => [],
			'cites'        => self::visibleLinks( ( new ItemCites() )->citesOf( 'knowledge', $postId ), $readerId ),
			'derived_from' => self::visibleLinks( ( new DerivedFrom() )->sourcesOf( 'post', $postId ), $readerId ),
			'approvals'    => self::postApprovals( $postId ),
		];
	}

	/**
	 * The provenance block for an artifact section.
	 *
	 * @return array{sources: array<int, array<string, mixed>>, cites: array<int, array{kind:string, id:int, version:?int}>, derived_from: array<int, array{kind:string, id:int, version:?int}>, approvals: array<int, array<string, mixed>>}
	 */
	public static function forSection( int $sectionId, ?int $readerId = null ): array {
		return [
			'sources'      => [],
			'cites'        => self::visibleLinks( ( new ItemCites() )->citesOf( 'section', $sectionId ), $readerId ),
			'derived_from' => [],
			'approvals'    => self::sectionApprovals( $sectionId ),
		];
	}

	// ------------------------------------------------------------------
	// Approvals
	// ------------------------------------------------------------------

	/**
	 * The review stamp on an approved knowledge/procedure post (PROV-6):
	 * who approved it, when, through which client, on whose behalf (via)
	 * and whether an AI client relayed it. Empty when the post was never
	 * approved (or predates the stamps).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function postApprovals( int $postId ): array {
		if ( $postId <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return [];
		}

		$reviewerId = (int) get_post_meta( $postId, ReviewActions::META_REVIEWED_BY, true );
		if ( $reviewerId <= 0 ) {
			return [];
		}

		return [
			[
				'by'     => $reviewerId,
				'at'     => self::isoStamp( (string) get_post_meta( $postId, ReviewActions::META_REVIEWED_AT, true ) ),
				'client' => self::metaString( $postId, ReviewActions::META_REVIEWED_CLIENT ),
				'via'    => self::metaIntOrNull( $postId, ReviewActions::META_REVIEWED_VIA ),
				'ai'     => (bool) (int) get_post_meta( $postId, ReviewActions::META_REVIEWED_AI, true ),
			],
		];
	}

	/**
	 * The approving reviews of a section (PROV-6), from its review log:
	 * one entry per `approve` verdict, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function sectionApprovals( int $sectionId ): array {
		$out = [];

		// Stored JSON, not a guaranteed shape: entries written before the
		// PROV-6 stamps lack the client/via/ai keys.
		/** @var array<int, array<string, mixed>> $log */
		$log = Stages::reviewLog( $sectionId );

		foreach ( $log as $entry ) {
			if ( (string) ( $entry['verdict'] ?? '' ) !== 'approve' ) {
				continue;
			}

			$client = $entry['client'] ?? null;
			$via    = $entry['via'] ?? null;

			$out[] = [
				'by'      => (int) ( $entry['reviewer_id'] ?? 0 ),
				'at'      => self::isoStamp( (string) ( $entry['at'] ?? '' ) ),
				'client'  => is_string( $client ) && $client !== '' ? $client : null,
				'via'     => is_numeric( $via ) ? (int) $via : null,
				'ai'      => ! empty( $entry['ai'] ),
				'version' => isset( $entry['version'] ) && is_numeric( $entry['version'] ) ? (int) $entry['version'] : null,
			];
		}

		return $out;
	}

	// ------------------------------------------------------------------
	// Visibility filter (MCP read scoping)
	// ------------------------------------------------------------------

	/**
	 * Normalizes cites / derived-from entries to the block shape
	 * ({kind, id, version} — no repository extras like item_version) and
	 * drops any $readerId could not fetch itself. Null $readerId (wp-admin
	 * panel) returns the list unfiltered.
	 *
	 * @param array<int, array{kind:string, id:int, version:?int}> $links
	 * @return array<int, array{kind:string, id:int, version:?int}>
	 */
	private static function visibleLinks( array $links, ?int $readerId ): array {
		$out = [];

		foreach ( $links as $link ) {
			if ( $readerId !== null && ! self::isVisible( $link['kind'], (int) $link['id'], $readerId ) ) {
				continue;
			}

			$out[] = [
				'kind'    => (string) $link['kind'],
				'id'      => (int) $link['id'],
				'version' => $link['version'] !== null ? (int) $link['version'] : null,
			];
		}

		return $out;
	}

	/**
	 * The same per-kind read guard the cite-validation in Mcp\Abilities
	 * uses, so the block can only ever confirm what the caller could have
	 * read itself. 'artifact' (a derived-from source kind) gates on the
	 * artifact-level roles a section read implies minus section ownership.
	 */
	private static function isVisible( string $kind, int $id, int $readerId ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		switch ( $kind ) {
			case 'memory':
				$scopes = ( new Scopes() )->readScopeIds( $readerId );

				return $scopes !== [] && ( new MemoryRepository() )->get( $id, $scopes ) !== null;
			case 'knowledge':
				$scopes = ( new Scopes() )->readScopeIds( $readerId );

				return $scopes !== [] && ( new KnowledgeSearch() )->getScoped( $id, $scopes ) !== null;
			case 'section':
				return ObjectAccess::canReadSection( $readerId, $id );
			case 'artifact':
				return ObjectAccess::userIsCurator( $readerId )
					|| ObjectAccess::userIsLead( $readerId, $id )
					|| ObjectAccess::userCanReviewAndAssemble( $readerId, $id );
			default:
				return false;
		}
	}

	// ------------------------------------------------------------------
	// Small shape helpers
	// ------------------------------------------------------------------

	/**
	 * One outside-source record as it appears in the block: the reference
	 * fields only (never content), with ISO timestamps.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function sourceBlock( array $row ): array {
		return [
			'id'           => (int) $row['id'],
			'kind'         => (string) $row['kind'],
			'title'        => (string) $row['title'],
			'locator'      => (string) $row['locator'],
			'publisher'    => (string) ( $row['publisher'] ?? '' ) !== '' ? (string) $row['publisher'] : null,
			'retrieved_at' => self::isoStamp( (string) ( $row['retrieved_at'] ?? '' ) ),
			'hash'         => (string) ( $row['hash'] ?? '' ) !== '' ? (string) $row['hash'] : null,
			'agent_id'     => (int) ( $row['agent_id'] ?? 0 ),
		];
	}

	private static function isoStamp( string $mysql ): ?string {
		return $mysql !== '' ? Present::iso( $mysql ) : null;
	}

	private static function metaString( int $postId, string $key ): ?string {
		$value = (string) get_post_meta( $postId, $key, true );

		return $value !== '' ? $value : null;
	}

	private static function metaIntOrNull( int $postId, string $key ): ?int {
		$value = get_post_meta( $postId, $key, true );

		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}
}
