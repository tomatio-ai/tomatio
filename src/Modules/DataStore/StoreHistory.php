<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\Db;
use WP_Error;

/**
 * #384: recovery for what rules cannot stop. Every store write (page or MCP)
 * leaves a history row: the document's value after the write (null for a
 * delete), the operation and its arguments, who wrote it and through which
 * share link. Kept for RETENTION_DAYS, at most MAX_PER_DOC rows a document.
 *
 * Members read a document's history, restore a version, and undo every
 * write one share link made (after revoking a leaked link): each document
 * the link touched is replayed from its last state before the link's first
 * write, applying every later write except the link's own. Increments and
 * appends replay as operations, so other viewers' counts survive; a whole
 * value written by someone else is kept as written.
 */
final class StoreHistory {

	public const RETENTION_DAYS = 30;
	public const MAX_PER_DOC    = 200;

	public static function table(): string {
		return Db::table( 'datastore_history' );
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
			version INT UNSIGNED NOT NULL,
			op VARCHAR(16) NOT NULL,
			value LONGTEXT NULL,
			delta LONGTEXT NULL,
			by_type VARCHAR(16) NOT NULL DEFAULT 'anonymous',
			by_id BIGINT UNSIGNED NULL,
			by_name VARCHAR(190) NULL,
			via_link BIGINT UNSIGNED NULL,
			at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY doc (artifact_id, collection, doc_key, id),
			KEY link (artifact_id, via_link),
			KEY at (at)
		) {$charset};";
	}

	/**
	 * @param string|null                                                                $valueJson The value after the write, as stored; null for a delete.
	 * @param array<string, mixed>|null                                                  $delta     The operation's arguments (patch, field, by, item).
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null,link?:int} $by
	 */
	public static function record( int $artifactId, string $collection, string $key, int $version, string $op, ?string $valueJson, ?array $delta, array $by, int $now ): void {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			[
				'artifact_id' => $artifactId,
				'collection'  => $collection,
				'doc_key'     => $key,
				'version'     => $version,
				'op'          => $op,
				'value'       => $valueJson,
				'delta'       => $delta === null ? null : (string) wp_json_encode( $delta ),
				'by_type'     => $by['type'],
				'by_id'       => $by['id'],
				'by_name'     => $by['name'],
				'via_link'    => $by['link'] ?? null,
				'at'          => gmdate( 'Y-m-d H:i:s', $now ),
			]
		);

		self::prune( $artifactId, $collection, $key, $now );
	}

	/**
	 * A document's history, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function forDoc( int $artifactId, string $collection, string $key, int $limit = self::MAX_PER_DOC ): array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT * FROM {$table} WHERE artifact_id = %d AND collection = %s AND doc_key = %s ORDER BY id DESC LIMIT %d", [ $artifactId, $collection, $key, max( 1, $limit ) ] );

		return array_map( [ self::class, 'present' ], $rows );
	}

	/**
	 * The newest history row of one version of a document, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function version( int $artifactId, string $collection, string $key, int $version ): ?array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT * FROM {$table} WHERE artifact_id = %d AND collection = %s AND doc_key = %s AND version = %d ORDER BY id DESC LIMIT 1", [ $artifactId, $collection, $key, $version ] );

		return isset( $rows[0] ) ? self::present( $rows[0] ) : null;
	}

	/**
	 * Undo every write made through $linkId (in $collection, or the whole
	 * artifact). Returns what changed per document.
	 *
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null} $by The member undoing.
	 * @return array{undone:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>}|WP_Error
	 */
	public static function undoLink( int $artifactId, int $linkId, ?string $collection, array $by, ?int $now = null ) {
		$now   = $now ?? time();
		$table = self::table();
		$sql   = "SELECT DISTINCT collection, doc_key FROM {$table} WHERE artifact_id = %d AND via_link = %d";
		$args  = [ $artifactId, $linkId ];
		if ( $collection !== null ) {
			$sql   .= ' AND collection = %s';
			$args[] = $collection;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$docs = Db::getResults( $sql . ' ORDER BY collection ASC, doc_key ASC', $args );

		$undone  = [];
		$skipped = [];
		foreach ( $docs as $doc ) {
			$c = (string) $doc['collection'];
			$k = (string) $doc['doc_key'];
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$result = self::undoDoc( $artifactId, $c, $k, $linkId, $by, $now );
				if ( ! ( $result instanceof WP_Error && $result->get_error_code() === 'ab_store_version_conflict' ) ) {
					break;
				}
			}

			if ( $result instanceof WP_Error ) {
				$skipped[] = [ 'collection' => $c, 'key' => $k, 'reason' => $result->get_error_message() ];
			} elseif ( $result !== null ) {
				$undone[] = [ 'collection' => $c, 'key' => $k ] + $result;
			}
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => (int) ( $by['id'] ?? 0 ),
				'event'       => 'datastore_link_undone',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'share_link_id' => $linkId, 'collection' => $collection, 'undone' => count( $undone ), 'skipped' => count( $skipped ) ],
			]
		);

		return [ 'undone' => $undone, 'skipped' => $skipped ];
	}

	/**
	 * Replays one document without the link's writes and stores the result.
	 *
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null} $by
	 * @return array<string, mixed>|null|WP_Error What changed, null for nothing to do.
	 */
	private static function undoDoc( int $artifactId, string $collection, string $key, int $linkId, array $by, int $now ) {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT * FROM {$table} WHERE artifact_id = %d AND collection = %s AND doc_key = %s ORDER BY id ASC", [ $artifactId, $collection, $key ] );

		$first = null;
		foreach ( $rows as $i => $row ) {
			if ( (int) ( $row['via_link'] ?? 0 ) === $linkId && $row['op'] !== 'undo' ) {
				$first = $i;
				break;
			}
		}
		if ( $first === null ) {
			return null;
		}

		if ( $first > 0 ) {
			$state = self::decode( $rows[ $first - 1 ]['value'] );
		} elseif ( (int) $rows[0]['version'] === 1 ) {
			$state = null; // The link created the document.
		} else {
			return new WP_Error( 'ab_store_history_pruned', __( 'The history before this link\'s first write is no longer kept.', 'agent-brain' ) );
		}

		for ( $i = $first, $n = count( $rows ); $i < $n; $i++ ) {
			$row = $rows[ $i ];
			if ( (int) ( $row['via_link'] ?? 0 ) === $linkId && $row['op'] !== 'undo' ) {
				continue;
			}
			$state = self::replay( $state, $row );
		}

		$current = StoreData::get( $artifactId, $collection, $key );
		$by      = $by + [ 'link' => null ];
		if ( $state === null ) {
			if ( $current === null ) {
				return null;
			}
			if ( ! StoreData::delete( $artifactId, $collection, $key, (int) $current['version'], $by, 'undo', $now ) ) {
				return new WP_Error( 'ab_store_version_conflict', __( 'The document changed during the undo.', 'agent-brain' ) );
			}

			return [ 'result' => 'deleted' ];
		}

		if ( $current !== null && $current['value'] === $state ) {
			return null;
		}

		$written = StoreData::write( $artifactId, $collection, $key, $state, $by, 'undo', [ 'share_link_id' => $linkId ], $current === null ? 0 : (int) $current['version'], null, $now );

		return $written instanceof WP_Error ? $written : [ 'result' => $current === null ? 'restored' : 'reverted', 'version' => $written['version'] ];
	}

	/**
	 * One history row applied to a state during an undo replay.
	 *
	 * @param mixed                $state
	 * @param array<string, mixed> $row
	 * @return mixed
	 */
	private static function replay( $state, array $row ) {
		$delta = self::decode( $row['delta'] );
		$delta = is_array( $delta ) ? $delta : [];

		switch ( (string) $row['op'] ) {
			case 'update':
				return $state === null ? null : StoreData::merge( $state, $delta['patch'] ?? null );
			case 'increment':
				$state = is_array( $state ) ? $state : [];
				$field = (string) ( $delta['field'] ?? '' );
				if ( $field !== '' ) {
					$state[ $field ] = ( is_int( $state[ $field ] ?? null ) ? $state[ $field ] : 0 ) + (int) ( $delta['by'] ?? 0 );
				}

				return $state;
			case 'append':
				$state = is_array( $state ) ? $state : [];
				$field = (string) ( $delta['field'] ?? '' );
				if ( $field !== '' ) {
					$list            = is_array( $state[ $field ] ?? null ) ? $state[ $field ] : [];
					$list[]          = $delta['item'] ?? null;
					$state[ $field ] = $list;
				}

				return $state;
			case 'delete':
				return null;
		}

		// create, replace, restore, undo: the value as written.
		return self::decode( $row['value'] );
	}

	/**
	 * @param mixed $json
	 * @return mixed
	 */
	private static function decode( $json ) {
		return $json === null ? null : json_decode( (string) $json, true );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function present( array $row ): array {
		$type = in_array( $row['by_type'], [ 'person', 'agent' ], true ) ? (string) $row['by_type'] : 'anonymous';
		$link = $row['via_link'] !== null ? (int) $row['via_link'] : null;
		$name = $row['by_name'] !== null ? (string) $row['by_name'] : null;
		if ( $type === 'anonymous' && $name === null && $link !== null ) {
			/* translators: %d: share link id */
			$name = sprintf( __( 'viewer via share link %d', 'agent-brain' ), $link );
		}

		return [
			'version' => (int) $row['version'],
			'op'      => (string) $row['op'],
			'value'   => self::decode( $row['value'] ),
			'deleted' => $row['value'] === null,
			'delta'   => self::decode( $row['delta'] ),
			'by'      => [ 'anonymous' => $type === 'anonymous', 'type' => $type, 'id' => $row['by_id'] !== null ? (int) $row['by_id'] : null, 'name' => $name, 'share_link_id' => $link ],
			'at'      => (string) $row['at'],
		];
	}

	private static function prune( int $artifactId, string $collection, string $key, int $now ): void {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$keep = $wpdb->get_var( Db::prepare( "SELECT id FROM {$table} WHERE artifact_id = %d AND collection = %s AND doc_key = %s ORDER BY id DESC LIMIT 1 OFFSET %d", [ $artifactId, $collection, $key, self::MAX_PER_DOC - 1 ] ) );
		if ( $keep !== null ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- see above.
			$wpdb->query( Db::prepare( "DELETE FROM {$table} WHERE artifact_id = %d AND collection = %s AND doc_key = %s AND id < %d", [ $artifactId, $collection, $key, (int) $keep ] ) );
		}

		if ( wp_rand( 1, 100 ) === 1 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- see above.
			$wpdb->query( Db::prepare( "DELETE FROM {$table} WHERE at < %s", [ gmdate( 'Y-m-d H:i:s', $now - self::RETENTION_DAYS * 86400 ) ] ) );
		}
	}

	public static function deleteForArtifact( int $artifactId ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$wpdb->query( Db::prepare( 'DELETE FROM ' . self::table() . ' WHERE artifact_id = %d', [ $artifactId ] ) );
	}
}
