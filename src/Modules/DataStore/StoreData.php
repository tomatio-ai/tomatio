<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Sharing\ViewerIdentity;
use AgentBrain\Modules\Sharing\ViewerToken;
use WP_Error;

/**
 * ART-23 (#163): the per-artifact document store — one table, one row per
 * document, attribution on every write (ART-24 viewer descriptor or the
 * anonymous share link). Size limits are enforced here so the JS API route,
 * the MCP abilities and any future caller all share one enforcement point.
 *
 * Documents are JSON values keyed by (artifact, collection, key). Names are
 * restricted to what the preview-host path grammar and the private-mode
 * allowlist regex already admit; `_`-prefixed keys are reserved so the
 * collection-level `_query` endpoint can never collide with a document.
 */
final class StoreData {

	public const MAX_VALUE_BYTES       = 16384;   // 16 KiB per document value.
	public const MAX_COLLECTION_BYTES  = 1048576; // 1 MiB per collection.
	public const MAX_DOCS              = 500;     // Documents per collection.
	public const MAX_COLLECTIONS       = 32;      // Collections per artifact.
	public const QUERY_MAX_LIMIT       = 100;

	private const COLLECTION_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
	// #384: spaces inside a key (nicknames such as "ANA MARIA"); keys are
	// trimmed first, so a key never starts or ends with one.
	private const KEY_PATTERN        = '/^[A-Za-z0-9][A-Za-z0-9._ -]{0,127}$/';

	public static function table(): string {
		return Db::table( 'datastore' );
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
			value LONGTEXT NOT NULL,
			size INT UNSIGNED NOT NULL,
			created_by_type VARCHAR(16) NOT NULL DEFAULT 'anonymous',
			created_by_id BIGINT UNSIGNED NULL,
			created_by_name VARCHAR(190) NULL,
			created_at DATETIME NOT NULL,
			updated_by_type VARCHAR(16) NOT NULL DEFAULT 'anonymous',
			updated_by_id BIGINT UNSIGNED NULL,
			updated_by_name VARCHAR(190) NULL,
			updated_at DATETIME NOT NULL,
			artifact_version INT UNSIGNED NOT NULL DEFAULT 0,
			revision INT UNSIGNED NOT NULL DEFAULT 1,
			created_via_link BIGINT UNSIGNED NULL,
			updated_via_link BIGINT UNSIGNED NULL,
			owner VARCHAR(64) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY doc (artifact_id, collection, doc_key),
			KEY artifact (artifact_id)
		) {$charset};";
	}

	/**
	 * @return array{anonymous:true,type:string,id:int|null,name:string|null}
	 */
	public static function anonymousAttribution(): array {
		return [ 'anonymous' => true, 'type' => 'anonymous', 'id' => null, 'name' => null ];
	}

	/**
	 * #375: the share link a page write came through, recorded next to the
	 * writer (anonymous or a verified viewer) so members can tell the links
	 * apart in artifact-store-get.
	 *
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null} $by
	 * @return array{anonymous:bool,type:string,id:int|null,name:string|null,link:int}
	 */
	public static function viaLink( array $by, int $linkId ): array {
		return $by + [ 'link' => $linkId ];
	}

	/**
	 * Attribution from verified ART-24 viewer token claims.
	 *
	 * @param array{principal_id:int,principal_type:string,name:string} $claims
	 * @return array{anonymous:false,type:string,id:int,name:string}
	 */
	public static function attributionFromClaims( array $claims ): array {
		return [ 'anonymous' => false, 'type' => $claims['principal_type'], 'id' => $claims['principal_id'], 'name' => $claims['name'] ];
	}

	/**
	 * Attribution for a logged-in brain actor (the MCP abilities).
	 *
	 * @return array{anonymous:bool,type:string,id:int|null,name:string|null}
	 */
	public static function attributionFromUser( int $userId, int $artifactId ): array {
		$viewer = ViewerIdentity::describe( $userId, $artifactId );
		if ( $viewer === null ) {
			return self::anonymousAttribution();
		}

		return [ 'anonymous' => false, 'type' => $viewer['principal_type'], 'id' => $viewer['principal_id'], 'name' => $viewer['name'] ];
	}

	public static function normalizeCollection( string $collection ): ?string {
		$collection = trim( $collection );

		return preg_match( self::COLLECTION_PATTERN, $collection ) === 1 ? $collection : null;
	}

	public static function normalizeKey( string $key ): ?string {
		$key = trim( $key );

		return preg_match( self::KEY_PATTERN, $key ) === 1 && ! str_starts_with( $key, '_' ) ? $key : null;
	}

	/**
	 * Encodes a document value as JSON and enforces the per-value size limit.
	 *
	 * @param mixed $value
	 * @return string|WP_Error The encoded value, or an error (too large / not encodable).
	 */
	public static function encodeValue( $value ) {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return new WP_Error( 'ab_store_invalid_value', __( 'The value must be JSON-encodable.', 'agent-brain' ), [ 'status' => 400 ] );
		}

		if ( strlen( $json ) > self::MAX_VALUE_BYTES ) {
			return new WP_Error(
				'ab_store_value_too_large',
				sprintf(
					/* translators: %d: maximum document size in bytes */
					__( 'The value exceeds the %d-byte per-document limit.', 'agent-brain' ),
					self::MAX_VALUE_BYTES
				),
				[ 'status' => 413 ]
			);
		}

		return $json;
	}

	/**
	 * One document with its attribution, value decoded.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $artifactId, string $collection, string $key ): ?array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT * FROM {$table} WHERE artifact_id = %d AND collection = %s AND doc_key = %s", [ $artifactId, $collection, $key ] );

		return isset( $rows[0] ) ? self::present( $rows[0] ) : null;
	}

	/**
	 * Every document of a collection, in key order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( int $artifactId, string $collection ): array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT * FROM {$table} WHERE artifact_id = %d AND collection = %s ORDER BY doc_key ASC", [ $artifactId, $collection ] );

		return array_map( [ self::class, 'present' ], $rows );
	}

	/**
	 * Documents of a collection whose value matches every `field: scalar`
	 * predicate (dot paths into nested arrays are supported).
	 *
	 * @param array<string, mixed> $where
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( int $artifactId, string $collection, array $where, int $limit, int $offset ): array {
		$limit  = max( 1, min( $limit, self::QUERY_MAX_LIMIT ) );
		$offset = max( 0, $offset );
		$docs   = array_values(
			array_filter(
				self::list( $artifactId, $collection ),
				static function ( array $doc ) use ( $where ): bool {
					foreach ( $where as $field => $expected ) {
						if ( ! is_string( $field ) || ! self::fieldMatches( $doc['value'], $field, $expected ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);

		return array_slice( $docs, $offset, $limit );
	}

	/**
	 * Insert or replace a document (op create or replace). Attributed to
	 * $by; the artifact version is snapshotted at write time.
	 *
	 * #375: every write bumps the document's `version` (1 on create). With
	 * $expectedVersion the write only lands while the stored version still
	 * matches (0: the document must not exist yet); otherwise it is the
	 * ab_store_version_conflict error (409) carrying the current document.
	 *
	 * @param mixed                                                                     $value
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null,link?:int} $by
	 * @return array<string, mixed>|WP_Error The stored document.
	 */
	public static function set( int $artifactId, string $collection, string $key, $value, array $by, ?int $now = null, ?int $expectedVersion = null ) {
		return self::write( $artifactId, $collection, $key, $value, $by, '', null, $expectedVersion, null, $now );
	}

	/**
	 * #384: the one write path. $op is recorded in the history ('' picks
	 * create or replace); $delta holds the operation's arguments (patch,
	 * field and step, item) so an undo can replay it. $owner is set when the
	 * write creates the document and never changes afterwards.
	 *
	 * @param mixed                                                                          $value
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null,link?:int|null} $by
	 * @param array<string, mixed>|null                                                      $delta
	 * @return array<string, mixed>|WP_Error The stored document.
	 */
	public static function write( int $artifactId, string $collection, string $key, $value, array $by, string $op = '', ?array $delta = null, ?int $expectedVersion = null, ?string $owner = null, ?int $now = null ) {
		global $wpdb;

		$now    = $now ?? time();
		$json   = self::encodeValue( $value );
		if ( $json instanceof WP_Error ) {
			return $json;
		}

		$existing = self::raw( $artifactId, $collection, $key );
		$current  = $existing === null ? 0 : (int) $existing['revision'];
		if ( $expectedVersion !== null && $expectedVersion !== $current ) {
			return self::conflict( $artifactId, $collection, $key );
		}

		if ( $existing === null ) {
			$allowed = self::checkCreate( $artifactId, $collection, strlen( $json ) );
			if ( $allowed instanceof WP_Error ) {
				return $allowed;
			}
		} else {
			$allowed = self::checkBudget( $artifactId, $collection, strlen( $json ) - (int) $existing['size'] );
			if ( $allowed instanceof WP_Error ) {
				return $allowed;
			}
		}

		$row = [
			'artifact_id'      => $artifactId,
			'collection'       => $collection,
			'doc_key'          => $key,
			'value'            => $json,
			'size'             => strlen( $json ),
			'created_by_type'  => $by['type'],
			'created_by_id'    => $by['id'],
			'created_by_name'  => $by['name'],
			'created_at'       => gmdate( 'Y-m-d H:i:s', $now ),
			'updated_by_type'  => $by['type'],
			'updated_by_id'    => $by['id'],
			'updated_by_name'  => $by['name'],
			'updated_at'       => gmdate( 'Y-m-d H:i:s', $now ),
			'artifact_version' => self::artifactVersion( $artifactId ),
			'revision'         => $current + 1,
			'created_via_link' => $by['link'] ?? null,
			'updated_via_link' => $by['link'] ?? null,
			'owner'            => $owner,
		];

		if ( $existing === null ) {
			// The unique (artifact, collection, key) index decides a race
			// between two creates: the loser is the conflict.
			$suppress = $wpdb->suppress_errors( true );
			$written  = $wpdb->insert( self::table(), $row );
			$wpdb->suppress_errors( $suppress );
		} else {
			unset( $row['created_by_type'], $row['created_by_id'], $row['created_by_name'], $row['created_at'], $row['created_via_link'], $row['owner'] );
			// Compare-and-swap on the version read above, so a concurrent
			// write between that read and this one is never overwritten
			// unnoticed when the caller asked for a version.
			$written = $wpdb->update( self::table(), $row, [ 'id' => (int) $existing['id'], 'revision' => $current ] );
		}

		if ( ! $written ) {
			return $expectedVersion !== null
				? self::conflict( $artifactId, $collection, $key )
				: new WP_Error( 'ab_store_write_failed', __( 'The document changed while it was being written. Try again.', 'agent-brain' ), [ 'status' => 409 ] );
		}

		StoreHistory::record( $artifactId, $collection, $key, $current + 1, $op !== '' ? $op : ( $existing === null ? 'create' : 'replace' ), $json, $delta, $by, $now );

		$stored = self::get( $artifactId, $collection, $key );

		/**
		 * #678: a document was created or replaced (StoreMemory listens).
		 *
		 * @param int    $artifactId
		 * @param string $collection
		 * @param string $key
		 * @param mixed  $value The stored value.
		 * @param array  $by    The writer's attribution.
		 */
		do_action( 'agent_brain/store_written', $artifactId, $collection, $key, $stored['value'] ?? $value, $by );

		return $stored;
	}

	/**
	 * #375: the 409 for a stale expected_version, with the document as it
	 * is now (null when it does not exist) so the page can merge and retry.
	 */
	private static function conflict( int $artifactId, string $collection, string $key ): WP_Error {
		return new WP_Error(
			'ab_store_version_conflict',
			__( 'The document was changed by someone else. Read it again and retry.', 'agent-brain' ),
			[ 'status' => 409, 'doc' => self::get( $artifactId, $collection, $key ) ]
		);
	}

	/**
	 * Deep-merge $patch into an existing document (which must exist — a
	 * missing document is the route's 404, not a create).
	 *
	 * @param mixed                                                                     $patch
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null,link?:int} $by
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( int $artifactId, string $collection, string $key, $patch, array $by, ?int $now = null, ?int $expectedVersion = null ) {
		$existing = self::get( $artifactId, $collection, $key );
		if ( $existing === null ) {
			return new WP_Error( 'ab_store_not_found', __( 'Document not found.', 'agent-brain' ), [ 'status' => 404 ] );
		}

		$merged = self::merge( $existing['value'], $patch );

		// The merge read the document at this version: the write must land
		// on that same version, asked for or not.
		return self::write( $artifactId, $collection, $key, $merged, $by, 'update', [ 'patch' => $patch ], $expectedVersion ?? (int) $existing['version'], null, $now );
	}

	/**
	 * #375: with $expectedVersion only a document still at that version is
	 * deleted, in the same statement. #384: the delete is recorded in the
	 * history (attributed to $by when given), so it can be undone.
	 *
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null,link?:int|null}|null $by
	 * @return bool True when a document was deleted.
	 */
	public static function delete( int $artifactId, string $collection, string $key, ?int $expectedVersion = null, ?array $by = null, string $op = 'delete', ?int $now = null ): bool {
		global $wpdb;

		$existing = self::raw( $artifactId, $collection, $key );
		if ( $existing === null ) {
			return false;
		}

		$sql  = 'DELETE FROM ' . self::table() . ' WHERE id = %d AND revision = %d';
		$args = [ (int) $existing['id'], $expectedVersion ?? (int) $existing['revision'] ];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$wpdb->query( Db::prepare( $sql, $args ) );
		if ( (int) $wpdb->rows_affected === 0 ) {
			return false;
		}

		StoreHistory::record( $artifactId, $collection, $key, (int) $existing['revision'] + 1, $op, null, null, $by ?? self::anonymousAttribution(), $now ?? time() );

		/** #678: a document was deleted (StoreMemory listens). */
		do_action( 'agent_brain/store_deleted', $artifactId, $collection, $key );

		return true;
	}

	/**
	 * Every document of a collection (a member's wipe), each recorded in the
	 * history so an undo or restore can bring it back.
	 *
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null}|null $by
	 * @return int Documents deleted.
	 */
	public static function deleteCollection( int $artifactId, string $collection, ?array $by = null ): int {
		$deleted = 0;
		foreach ( self::list( $artifactId, $collection ) as $doc ) {
			if ( self::delete( $artifactId, $collection, (string) $doc['key'], null, $by ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Rows of an artifact (its deletion cascade).
	 *
	 * @return int Documents deleted.
	 */
	public static function deleteForArtifact( int $artifactId ): int {
		global $wpdb;

		if ( $artifactId <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$wpdb->query( Db::prepare( 'DELETE FROM ' . self::table() . ' WHERE artifact_id = %d', [ $artifactId ] ) );
		$deleted = (int) $wpdb->rows_affected;

		StoreHistory::deleteForArtifact( $artifactId );

		return $deleted;
	}

	/**
	 * Collection statistics of an artifact (the wp-admin summary, the store
	 * index endpoint and the PCP export all read this shape).
	 *
	 * @return array<int, array{collection:string, docs:int, bytes:int, updated_at:string|null}>
	 */
	public static function collections( int $artifactId ): array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT collection, COUNT(*) AS docs, SUM(size) AS bytes, MAX(updated_at) AS updated_at FROM {$table} WHERE artifact_id = %d GROUP BY collection ORDER BY collection ASC", [ $artifactId ] );

		return array_map(
			static fn ( array $row ): array => [
				'collection' => (string) $row['collection'],
				'docs'       => (int) $row['docs'],
				'bytes'      => (int) $row['bytes'],
				'updated_at' => $row['updated_at'],
			],
			$rows
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function raw( int $artifactId, string $collection, string $key ): ?array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT * FROM {$table} WHERE artifact_id = %d AND collection = %s AND doc_key = %s", [ $artifactId, $collection, $key ] );

		return $rows[0] ?? null;
	}

	/**
	 * New-document guard: collection count and per-collection budget.
	 *
	 * @return true|WP_Error
	 */
	private static function checkCreate( int $artifactId, string $collection, int $additionalBytes ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$docs = (int) $wpdb->get_var( Db::prepare( "SELECT COUNT(*) FROM {$table} WHERE artifact_id = %d AND collection = %s", [ $artifactId, $collection ] ) );
		if ( $docs >= self::MAX_DOCS ) {
			return new WP_Error( 'ab_store_collection_full', __( 'The collection is full (document limit reached).', 'agent-brain' ), [ 'status' => 413 ] );
		}

		if ( $docs === 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
			$collections = (int) $wpdb->get_var( Db::prepare( "SELECT COUNT(DISTINCT collection) FROM {$table} WHERE artifact_id = %d", [ $artifactId ] ) );
			if ( $collections >= self::MAX_COLLECTIONS ) {
				return new WP_Error( 'ab_store_too_many_collections', __( 'The artifact store is full (collection limit reached).', 'agent-brain' ), [ 'status' => 413 ] );
			}
		}

		return self::checkBudget( $artifactId, $collection, $additionalBytes );
	}

	/**
	 * Per-collection byte budget.
	 *
	 * @return true|WP_Error
	 */
	private static function checkBudget( int $artifactId, string $collection, int $additionalBytes ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$bytes = (int) $wpdb->get_var( Db::prepare( "SELECT COALESCE(SUM(size), 0) FROM {$table} WHERE artifact_id = %d AND collection = %s", [ $artifactId, $collection ] ) );
		if ( $bytes + $additionalBytes > self::MAX_COLLECTION_BYTES ) {
			return new WP_Error(
				'ab_store_collection_too_large',
				sprintf(
					/* translators: %d: maximum collection size in bytes */
					__( 'The collection exceeds the %d-byte limit.', 'agent-brain' ),
					self::MAX_COLLECTION_BYTES
				),
				[ 'status' => 413 ]
			);
		}

		return true;
	}

	/**
	 * #384: a document as a share page sees it — its data, version and
	 * times, and whether the asking identity owns it. Writers' names, ids
	 * and links stay with members (MCP).
	 *
	 * @param array<string, mixed> $doc As get() / list() return it.
	 * @return array<string, mixed>
	 */
	public static function pageDoc( array $doc, string $identity ): array {
		return [
			'collection' => $doc['collection'],
			'key'        => $doc['key'],
			'value'      => $doc['value'],
			'version'    => $doc['version'],
			'created_at' => $doc['created_at'],
			'updated_at' => $doc['updated_at'],
			'mine'       => $doc['owner'] !== null && $doc['owner'] === $identity,
		];
	}

	/**
	 * #384: a fresh key for collections whose rules generate them: 16
	 * lowercase letters and digits, never starting with "_".
	 */
	public static function generateKey(): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$key      = '';
		for ( $i = 0; $i < 16; $i++ ) {
			$key .= $alphabet[ random_int( 0, 35 ) ];
		}

		return $key;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function present( array $row ): array {
		$decoded = json_decode( (string) $row['value'], true );

		return [
			'collection'       => (string) $row['collection'],
			'key'              => (string) $row['doc_key'],
			'value'            => $decoded,
			'size'             => (int) $row['size'],
			'version'          => (int) ( $row['revision'] ?? 1 ),
			'owner'            => isset( $row['owner'] ) ? (string) $row['owner'] : null,
			'created_by'       => self::attributionPresent( (string) $row['created_by_type'], $row['created_by_id'], $row['created_by_name'], $row['created_via_link'] ?? null ),
			'created_at'       => (string) $row['created_at'],
			'updated_by'       => self::attributionPresent( (string) $row['updated_by_type'], $row['updated_by_id'], $row['updated_by_name'], $row['updated_via_link'] ?? null ),
			'updated_at'       => (string) $row['updated_at'],
			'artifact_version' => (int) $row['artifact_version'],
		];
	}

	/**
	 * @param mixed $id
	 * @param mixed $name
	 * @param mixed $link The share link the write came through (#375), or null.
	 * @return array{anonymous:bool,type:string,id:int|null,name:string|null,share_link_id:int|null}
	 */
	private static function attributionPresent( string $type, $id, $name, $link = null ): array {
		$type = $type === ViewerToken::TYPE_PERSON || $type === ViewerToken::TYPE_AGENT ? $type : 'anonymous';
		$link = $link !== null ? (int) $link : null;

		if ( $type === 'anonymous' && $name === null && $link !== null ) {
			/* translators: %d: share link id */
			$name = sprintf( __( 'viewer via share link %d', 'agent-brain' ), $link );
		}

		return [
			'anonymous'     => $type === 'anonymous',
			'type'          => $type,
			'id'            => $id !== null ? (int) $id : null,
			'name'          => $name !== null ? (string) $name : null,
			'share_link_id' => $link,
		];
	}

	/**
	 * @param mixed $value
	 * @param mixed $expected
	 */
	private static function fieldMatches( $value, string $field, $expected ): bool {
		foreach ( explode( '.', $field ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return false;
			}
			$value = $value[ $segment ];
		}

		return $value === $expected;
	}

	/**
	 * Recursive array merge: scalars are replaced, arrays merge pairwise, a
	 * non-array patch value replaces whatever the document held.
	 *
	 * @param mixed $base
	 * @param mixed $patch
	 * @return mixed
	 */
	public static function merge( $base, $patch ) {
		if ( ! is_array( $base ) || ! is_array( $patch ) || self::isList( $base ) || self::isList( $patch ) ) {
			return $patch;
		}

		foreach ( $patch as $key => $value ) {
			$base[ $key ] = array_key_exists( $key, $base ) ? self::merge( $base[ $key ], $value ) : $value;
		}

		return $base;
	}

	/**
	 * @param mixed $value
	 */
	private static function isList( $value ): bool {
		return is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private static function artifactVersion( int $artifactId ): int {
		$version = (int) get_post_meta( $artifactId, Cpts::META_VERSION, true );

		return $version > 0 ? $version : 0;
	}
}
