<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Forget;

use AgentBrain\Core\Db;
use AgentBrain\Core\SqliteRuntime;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Portability\PageExtra;

/**
 * Finds every row matching a subject string, across every scope (a curator
 * forget is deliberately NOT scope-filtered — see FGT-1), by FULLTEXT exact
 * phrase match plus an escaped case-insensitive LIKE fallback (FULLTEXT
 * alone misses short subjects and stopwords). The FULLTEXT side must be a
 * phrase: NATURAL LANGUAGE MODE matches rows containing ANY of the words, so
 * forgetting "John Smith" would delete every memory mentioning "John". Used both to collect deletion targets and,
 * after deletion, to re-run the same search for the FGT-3 residue check.
 *
 * A page also matches on the frontmatter an import kept without a column
 * (PageExtra: ab_memory.pcp_extra, post meta ab_pcp_extra), such as a
 * memory's own title, tags or the imported source: that is part of the
 * page, and the next export writes it again.
 */
final class Matcher {

	/**
	 * Own post types Forget matches title/content on directly (knowledge,
	 * procedure, handoff). Types not yet registered (e.g. ab_handoff before
	 * the Review module ships) are skipped, never fatal.
	 *
	 * @return string[]
	 */
	public static function ownedPostTypes(): array {
		return array_values( array_filter(
			[ 'ab_knowledge', 'ab_procedure', 'ab_handoff' ],
			'post_type_exists'
		) );
	}

	/**
	 * LIKE patterns for the subject inside stored JSON: as typed, and as
	 * JSON writes it within a string (quotes and backslashes escaped, and
	 * with wp_json_encode()'s default flags "/" and non-ASCII characters
	 * too). Case-insensitive, like the other matches.
	 *
	 * @return string[]
	 */
	public static function jsonLikes( string $subject ): array {
		global $wpdb;

		$forms = [ $subject ];
		foreach ( [ JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 0 ] as $flags ) {
			$json = json_encode( $subject, $flags );
			if ( is_string( $json ) ) {
				$forms[] = substr( $json, 1, -1 );
			}
		}

		return array_values( array_unique( array_map(
			static fn( string $form ): string => '%' . $wpdb->esc_like( $form ) . '%',
			$forms
		) ) );
	}

	/**
	 * Raw ab_memory row ids whose content, search_keys or kept frontmatter
	 * (pcp_extra) match $subject.
	 *
	 * @return int[]
	 */
	public static function matchMemoryIds( string $subject ): array {
		global $wpdb;

		$table  = MemoryRepository::memoryTable();
		$like   = '%' . $wpdb->esc_like( $subject ) . '%';
		$phrase = trim( str_replace( '"', ' ', $subject ) );
		$kept   = self::jsonLikes( $subject );
		$keptOr = implode( ' OR ', array_fill( 0, count( $kept ), 'pcp_extra LIKE %s' ) );

		// SQLite drop-in (#405): the combined FULLTEXT+LIKE statement is
		// untranslatable — MATCH ... AGAINST is a syntax error there that
		// fails the whole statement, losing even the LIKE matches — so
		// short-circuit to the LIKE-only branch, which still matches the
		// escaped subject (and its JSON forms) as a substring.
		if ( $phrase === '' || SqliteRuntime::isActive() ) {
			$rows = Db::getResults(
				"SELECT id FROM {$table} WHERE content LIKE %s OR search_keys LIKE %s OR {$keptOr}",
				array_merge( [ $like, $like ], $kept )
			);
		} else {
			$rows = Db::getResults(
				"SELECT id FROM {$table}
					WHERE MATCH(content, search_keys) AGAINST (%s IN BOOLEAN MODE)
					OR content LIKE %s
					OR search_keys LIKE %s
					OR {$keptOr}",
				array_merge( [ '"' . $phrase . '"', $like, $like ], $kept )
			);
		}

		return array_map( static fn( array $row ): int => (int) $row['id'], $rows );
	}

	/**
	 * Post ids (knowledge/procedure/handoff, including revisions of any of
	 * those) whose title or content match $subject, and those whose kept
	 * frontmatter (ab_pcp_extra) does, with their revisions.
	 *
	 * @return int[]
	 */
	public static function matchPostIds( string $subject ): array {
		global $wpdb;

		$types = self::ownedPostTypes();
		if ( $types === [] ) {
			return [];
		}

		$like              = '%' . $wpdb->esc_like( $subject ) . '%';
		$typePlaceholders  = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		$sql = "SELECT ID FROM {$wpdb->posts}
			WHERE (
				post_type IN ({$typePlaceholders})
				OR (
					post_type = 'revision'
					AND post_parent IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$typePlaceholders}) )
				)
			)
			AND ( post_title LIKE %s OR post_content LIKE %s )";

		$params = array_merge( $types, $types, [ $like, $like ] );

		$rows = Db::getResults( $sql, $params );
		$ids  = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );

		$kept   = self::jsonLikes( $subject );
		$keptOr = implode( ' OR ', array_fill( 0, count( $kept ), 'pm.meta_value LIKE %s' ) );
		$rows   = Db::getResults(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type IN ({$typePlaceholders}) AND ( {$keptOr} )",
			array_merge( [ PageExtra::META ], $types, $kept )
		);
		$byKept = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );

		return array_values( array_unique( array_merge( $ids, $byKept, self::revisionsOf( $byKept ) ) ) );
	}

	/**
	 * Full collection for a forget run: matched memory rows expanded via the
	 * ab_memory_links closure (cycle-safe, MemoryRepository::derivedFrom())
	 * to every derived memory row and derived post (unless $includeDerived
	 * is false), unioned with directly matched knowledge/procedure/handoff
	 * posts (+ revisions).
	 *
	 * @return array{memory_ids: int[], post_ids: int[]}
	 */
	public static function collect( string $subject, ?MemoryRepository $memoryRepository = null, bool $includeDerived = true ): array {
		$memoryIds = self::matchMemoryIds( $subject );
		$postIds   = self::matchPostIds( $subject );

		if ( $includeDerived ) {
			[ $memoryIds, $postIds ] = self::withDerived( $memoryIds, $postIds, $memoryRepository );
		}

		return [
			'memory_ids' => array_values( array_unique( $memoryIds ) ),
			'post_ids'   => array_values( array_unique( $postIds ) ),
		];
	}

	/**
	 * Adds every memory row and post derived from the memory rows, through
	 * the ab_memory_links closure (cycle-safe, MemoryRepository::derivedFrom()),
	 * and the revisions of every post added.
	 *
	 * @param int[] $memoryIds
	 * @param int[] $postIds
	 * @return array{0: int[], 1: int[]}
	 */
	public static function withDerived( array $memoryIds, array $postIds, ?MemoryRepository $memoryRepository = null ): array {
		$memoryRepository = $memoryRepository ?? new MemoryRepository();
		$derivedPosts     = [];

		foreach ( $memoryIds as $memoryId ) {
			foreach ( $memoryRepository->derivedFrom( (int) $memoryId ) as $pair ) {
				if ( $pair['kind'] === 'memory' ) {
					$memoryIds[] = $pair['id'];
				} else {
					$derivedPosts[] = $pair['id'];
				}
			}
		}

		return [
			array_values( array_unique( array_map( 'intval', $memoryIds ) ) ),
			array_values( array_unique( array_map( 'intval', array_merge( $postIds, $derivedPosts, self::revisionsOf( $derivedPosts ) ) ) ) ),
		];
	}

	/**
	 * Revision ids of the given posts.
	 *
	 * @param int[] $postIds
	 * @return int[]
	 */
	public static function revisionsOf( array $postIds ): array {
		global $wpdb;

		$postIds = array_values( array_unique( array_map( 'intval', $postIds ) ) );
		if ( $postIds === [] ) {
			return [];
		}

		$in   = implode( ', ', array_fill( 0, count( $postIds ), '%d' ) );
		$rows = Db::getResults(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent IN ({$in})",
			$postIds
		);

		return array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
	}

	/**
	 * Principal erasure: memory rows the user wrote, or an agent wrote on
	 * their behalf (via).
	 *
	 * @return int[]
	 */
	public static function memoryIdsBy( int $userId ): array {
		$table = MemoryRepository::memoryTable();
		$rows  = Db::getResults(
			"SELECT id FROM {$table} WHERE agent_id = %d OR via = %d",
			[ $userId, $userId ]
		);

		return array_map( static fn( array $row ): int => (int) $row['id'], $rows );
	}

	/**
	 * Principal erasure: knowledge, procedure, handoff, artifact and section
	 * posts the user authored or proposed as an agent, with their revisions.
	 *
	 * @return int[]
	 */
	public static function postIdsBy( int $userId ): array {
		global $wpdb;

		$types = array_values( array_filter(
			array_merge( self::ownedPostTypes(), [ 'ab_artifact', 'ab_artifact_section' ] ),
			'post_type_exists'
		) );
		if ( $types === [] ) {
			return [];
		}

		$in   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$rows = Db::getResults(
			"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type IN ({$in})
				AND (
					p.post_author = %d
					OR EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} pm
						WHERE pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s
					)
				)",
			array_merge( $types, [ $userId, 'ab_proposing_agent', (string) $userId ] )
		);

		$ids = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );

		return array_values( array_unique( array_merge( $ids, self::revisionsOf( $ids ) ) ) );
	}

	/**
	 * @param int[] $ids
	 * @return int[] The ids of those memory rows that still exist.
	 */
	public static function existingMemoryIds( array $ids ): array {
		if ( $ids === [] ) {
			return [];
		}

		$table = MemoryRepository::memoryTable();
		$in    = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows  = Db::getResults( "SELECT id FROM {$table} WHERE id IN ({$in})", array_map( 'intval', $ids ) );

		return array_map( static fn( array $row ): int => (int) $row['id'], $rows );
	}

	/**
	 * @param int[] $ids
	 * @return int[] The ids of those posts that still exist.
	 */
	public static function existingPostIds( array $ids ): array {
		global $wpdb;

		if ( $ids === [] ) {
			return [];
		}

		$in   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows = Db::getResults( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$in})", array_map( 'intval', $ids ) );

		return array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
	}

	/**
	 * FGT-3 residue check: re-run the same matching Forget owns, post
	 * deletion. External stores are re-checked by Forgetter, which runs the
	 * `agent_brain/forget_collect` filter a second time.
	 *
	 * @return array{memory: int, posts: int}
	 */
	public static function residueCounts( string $subject ): array {
		return [
			'memory' => count( self::matchMemoryIds( $subject ) ),
			'posts'  => count( self::matchPostIds( $subject ) ),
		];
	}
}
