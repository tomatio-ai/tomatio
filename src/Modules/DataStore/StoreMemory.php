<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Sharing\ViewerToken;

/**
 * #678: what lands on a page store reaches the brain. Brain search and
 * briefings read memories, never artifact stores, so a done entry ticked on
 * a TíoDay stayed invisible to every AI that did not also write a memory
 * (and a tick made on the page itself has no AI behind it at all). The
 * store mirrors the collections an artifact names into one episode memory
 * per document, in the artifact's scope:
 *
 *  - a TíoDay remembers `done`, `decisions` and `comments` on every write,
 *    and `tasks` once a task is closed (status done); other extensions name
 *    theirs with the agent_brain/store_remember filter;
 *  - the memory is written by the writing agent, or for a person (a tick on
 *    the page, a delegated connection) by the artifact's lead agent with
 *    agent_written 0; anonymous link writes are not remembered;
 *  - editing the document rewrites its memory; deleting it, or reopening the
 *    task, retires it (status deleted); an undo or restore brings it back.
 *
 * The map table holds which memory belongs to which document; it outlives
 * a document's delete so its restore reuses the same memory.
 */
final class StoreMemory {

	/** Remember every write of the collection. */
	public const EACH = 'each';

	/** Remember a document only while its status is done (a closed task). */
	public const WHEN_DONE = 'when_done';

	/** What a TíoDay remembers (collection => rule). */
	public const TIODAY = [
		'done'      => self::EACH,
		'decisions' => self::EACH,
		'comments'  => self::EACH,
		'tasks'     => self::WHEN_DONE,
	];

	private const IMPORTANCE = [
		'decisions' => 80,
		'done'      => 70,
		'tasks'     => 60,
		'comments'  => 50,
	];

	/** Comment targets ("t:<key>") and the collection they point into. */
	private const TARGETS = [
		't' => 'tasks',
		'd' => 'done',
		'c' => 'decisions',
		'f' => 'inflight',
	];

	private const MAX_CONTENT = 4000;

	public static function table(): string {
		return Db::table( 'datastore_memory' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			artifact_id BIGINT UNSIGNED NOT NULL,
			collection VARCHAR(64) NOT NULL,
			doc_key VARCHAR(128) NOT NULL,
			memory_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY doc (artifact_id, collection, doc_key),
			KEY memory (memory_id)
		) {$charset};";
	}

	/**
	 * The collections an artifact remembers, as collection => rule.
	 *
	 * @return array<string, string>
	 */
	public static function rules( int $artifactId ): array {
		$rules = (int) get_post_meta( $artifactId, HomeWorkspace::META_OWNER, true ) > 0 ? self::TIODAY : [];

		/**
		 * Filters which page-store collections of an artifact become brain
		 * memories (collection => StoreMemory::EACH or StoreMemory::WHEN_DONE).
		 *
		 * @param array<string, string> $rules
		 * @param int                   $artifactId
		 */
		$rules = (array) apply_filters( 'agent_brain/store_remember', $rules, $artifactId );

		return array_filter( $rules, static fn ( $rule ): bool => in_array( $rule, [ self::EACH, self::WHEN_DONE ], true ) );
	}

	/**
	 * agent_brain/store_written: a document was created or replaced.
	 *
	 * @param mixed                                                                          $value
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null,link?:int|null} $by
	 */
	public static function written( int $artifactId, string $collection, string $key, $value, array $by ): void {
		$rule = self::rules( $artifactId )[ $collection ] ?? null;
		if ( $rule === null ) {
			return;
		}

		if ( $rule === self::WHEN_DONE && ! ( is_array( $value ) && ( $value['status'] ?? '' ) === 'done' ) ) {
			self::retire( $artifactId, $collection, $key );
			return;
		}

		if ( ! empty( $by['anonymous'] ) || (int) ( $by['id'] ?? 0 ) <= 0 ) {
			return;
		}

		$agent = self::agentFor( $artifactId, $by );
		$scope = (int) get_post_meta( $artifactId, Cpts::META_SCOPE_ID, true );
		if ( $agent <= 0 || $scope <= 0 ) {
			return;
		}

		$content = self::content( $artifactId, $collection, $key, is_array( $value ) ? $value : [ 'text' => is_scalar( $value ) ? (string) $value : '' ] );
		$keys    = sprintf( 'page store %s %s artifact-%d %s', $collection, $key, $artifactId, $rule === self::WHEN_DONE ? 'closed' : '' );

		$memoryId = self::memoryOf( $artifactId, $collection, $key );
		if ( $memoryId > 0 && self::rewrite( $memoryId, $content, trim( $keys ) ) ) {
			return;
		}

		try {
			$memoryId = ( new MemoryRepository() )->insert( [
				'agent_id'      => $agent,
				'scope_id'      => $scope,
				'type'          => 'episode',
				'content'       => $content,
				'search_keys'   => trim( $keys ),
				'importance'    => self::IMPORTANCE[ $collection ] ?? 50,
				'agent_written' => $agent === (int) $by['id'] ? 1 : 0,
			] );
		} catch ( \Throwable $e ) {
			// The store write stands; the brain misses this one rather than
			// failing the page.
			return;
		}

		self::map( $artifactId, $collection, $key, $memoryId );
	}

	/**
	 * agent_brain/store_deleted: a document was deleted; its memory retires.
	 */
	public static function deleted( int $artifactId, string $collection, string $key ): void {
		if ( isset( self::rules( $artifactId )[ $collection ] ) ) {
			self::retire( $artifactId, $collection, $key );
		}
	}

	/**
	 * The artifact is gone for good: its map rows go. The memories stay,
	 * as the brain's record of what happened.
	 */
	public static function deleteForArtifact( int $artifactId ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$wpdb->query( Db::prepare( 'DELETE FROM ' . self::table() . ' WHERE artifact_id = %d', [ $artifactId ] ) );
	}

	public static function memoryOf( int $artifactId, string $collection, string $key ): int {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT memory_id FROM {$table} WHERE artifact_id = %d AND collection = %s AND doc_key = %s", [ $artifactId, $collection, $key ] );

		return isset( $rows[0] ) ? (int) $rows[0]['memory_id'] : 0;
	}

	/**
	 * Who the memory is written by: the writing agent, else the artifact's
	 * lead agent (a person's own write).
	 *
	 * @param array{type:string,id:int|null} $by
	 */
	private static function agentFor( int $artifactId, array $by ): int {
		if ( $by['type'] === ViewerToken::TYPE_AGENT ) {
			return (int) $by['id'];
		}

		return (int) get_post_meta( $artifactId, Cpts::META_LEAD_AGENT, true );
	}

	/**
	 * The memory text: what happened, in the shape each collection holds.
	 *
	 * @param array<string, mixed> $value
	 */
	private static function content( int $artifactId, string $collection, string $key, array $value ): string {
		$s = static fn ( string $field ): string => isset( $value[ $field ] ) && is_scalar( $value[ $field ] ) ? trim( (string) $value[ $field ] ) : '';

		switch ( $collection ) {
			case 'done':
				$date = $s( 'date' ) !== '' ? $s( 'date' ) : substr( $key, 0, 10 );
				$text = $s( 'stream' ) !== ''
					/* translators: 1: date, 2: work stream, 3: title */
					? sprintf( __( '%1$s, done (%2$s): %3$s.', 'agent-brain' ), $date, $s( 'stream' ), rtrim( $s( 'title' ), '.' ) )
					/* translators: 1: date, 2: title */
					: sprintf( __( '%1$s, done: %2$s.', 'agent-brain' ), $date, rtrim( $s( 'title' ), '.' ) );
				$text .= self::sentence( $s( 'detail' ) ) . self::byLine( __( 'By %s.', 'agent-brain' ), $s( 'by' ) );
				break;
			case 'decisions':
				$text = sprintf(
					/* translators: 1: date, 2: title */
					__( '%1$s, decision: %2$s.', 'agent-brain' ),
					$s( 'date' ) !== '' ? $s( 'date' ) : substr( $key, 0, 10 ),
					rtrim( $s( 'title' ), '.' )
				);
				$text .= self::sentence( $s( 'detail' ) ) . self::byLine( __( 'Decided by %s.', 'agent-brain' ), $s( 'by' ) );
				break;
			case 'tasks':
				$text = sprintf(
					/* translators: 1: date, 2: title */
					__( '%1$s, closed: %2$s.', 'agent-brain' ),
					$s( 'done_on' ) !== '' ? $s( 'done_on' ) : gmdate( 'Y-m-d' ),
					rtrim( $s( 'title' ), '.' )
				);
				$text .= self::sentence( $s( 'why' ) ) . self::byLine( __( 'Closed by %s.', 'agent-brain' ), $s( 'done_by' ) );
				break;
			case 'comments':
				$text = sprintf(
					/* translators: 1: date, 2: who commented, 3: what the comment is on, 4: the comment */
					__( '%1$s, comment by %2$s on %3$s: %4$s', 'agent-brain' ),
					substr( $s( 'at' ), 0, 10 ),
					$s( 'by' ) !== '' ? $s( 'by' ) : __( 'someone', 'agent-brain' ),
					self::target( $artifactId, $s( 'on' ) ),
					$s( 'text' )
				);
				break;
			default:
				$title = $s( 'title' ) !== '' ? $s( 'title' ) : $key;
				$text  = $title . '.' . self::sentence( $s( 'detail' ) !== '' ? $s( 'detail' ) : $s( 'text' ) );
		}

		$links = [];
		foreach ( is_array( $value['links'] ?? null ) ? $value['links'] : [] as $link ) {
			if ( is_array( $link ) && isset( $link['url'] ) && is_string( $link['url'] ) ) {
				$links[] = $link['url'];
			}
		}
		if ( $links !== [] ) {
			$text .= ' ' . implode( ' ', $links );
		}

		$text = sprintf(
			/* translators: 1: artifact title, 2: artifact id, 3: what happened */
			__( 'From %1$s (artifact %2$d): %3$s', 'agent-brain' ),
			// The stored title: get_the_title() would add "Private: ".
			(string) get_post_field( 'post_title', $artifactId ),
			$artifactId,
			$text
		);

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_CONTENT ) : substr( $text, 0, self::MAX_CONTENT );
	}

	/**
	 * What a comment is on: the item's title ("the task «…»"), or the page.
	 */
	private static function target( int $artifactId, string $on ): string {
		$parts = explode( ':', $on, 2 );
		$coll  = self::TARGETS[ $parts[0] ] ?? null;
		if ( $coll === null || ! isset( $parts[1] ) ) {
			return __( 'the page', 'agent-brain' );
		}

		$doc   = StoreData::get( $artifactId, $coll, $parts[1] );
		$title = is_array( $doc ) && is_array( $doc['value'] ) && isset( $doc['value']['title'] ) && is_scalar( $doc['value']['title'] )
			? (string) $doc['value']['title']
			: $parts[1];

		return sprintf( '%s «%s»', $coll, $title );
	}

	private static function sentence( string $text ): string {
		return $text === '' ? '' : ' ' . $text;
	}

	private static function byLine( string $format, string $name ): string {
		return $name === '' ? '' : ' ' . sprintf( $format, $name );
	}

	/**
	 * Rewrite a mapped memory in place and make it active again. False when
	 * the memory is gone (then a new one is written).
	 */
	private static function rewrite( int $memoryId, string $content, string $keys ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			MemoryRepository::memoryTable(),
			[ 'content' => $content, 'search_keys' => $keys, 'status' => 'active' ],
			[ 'id' => $memoryId ]
		);
		if ( $updated === false ) {
			return false;
		}

		$table = MemoryRepository::memoryTable();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from MemoryRepository.
		return Db::getResults( "SELECT id FROM {$table} WHERE id = %d", [ $memoryId ] ) !== [];
	}

	private static function retire( int $artifactId, string $collection, string $key ): void {
		global $wpdb;

		$memoryId = self::memoryOf( $artifactId, $collection, $key );
		if ( $memoryId > 0 ) {
			$wpdb->update( MemoryRepository::memoryTable(), [ 'status' => 'deleted' ], [ 'id' => $memoryId ] );
		}
	}

	private static function map( int $artifactId, string $collection, string $key, int $memoryId ): void {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$wpdb->query( Db::prepare( "INSERT INTO {$table} (artifact_id, collection, doc_key, memory_id) VALUES (%d, %s, %s, %d) ON DUPLICATE KEY UPDATE memory_id = VALUES(memory_id)", [ $artifactId, $collection, $key, $memoryId ] ) );
	}
}
