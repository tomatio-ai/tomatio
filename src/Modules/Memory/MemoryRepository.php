<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Memory;

use AgentBrain\Core\ActionContext;
use AgentBrain\Core\Db;
use AgentBrain\Core\SqliteRuntime;
use AgentBrain\Modules\Portability\PageIndex;
use AgentBrain\Modules\Recall\MemoryScopedSearch;
use InvalidArgumentException;

/**
 * Data access for raw agent memory (ab_memory) and provenance links
 * (ab_memory_links).
 *
 * Scope discipline (binding, see the design contracts):
 * every read-for-agent method REQUIRES the caller's allowed scope ids and
 * applies `scope_id IN (...)` inside the SQL itself; an empty allowed-scope
 * list returns an empty result *without issuing a query*. Methods named
 * `maintenance*` are the only exception — they are never wired to an agent
 * request path and must only be called from trusted, scope-agnostic
 * maintenance code (e.g. the rollover cron in Rollover.php).
 */
class MemoryRepository {

	public const TYPES        = [ 'working', 'episode', 'fact' ];
	public const STATUSES     = [ 'active', 'rolled_up', 'proposed', 'deleted' ];
	public const LINK_KINDS   = [ 'memory', 'post' ];
	public const DEFAULT_LIMIT = 20;
	public const MAX_LIMIT     = 200;

	/**
	 * Table names are spelled out per the binding contract (`ab_memory`,
	 * `ab_memory_links`) rather than via Core\Db::table(), which prefixes
	 * "agent_brain_" instead of "ab_". See learnings in this unit's return
	 * for the discrepancy flagged to the orchestrator.
	 */
	public static function memoryTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'ab_memory';
	}

	public static function linksTable(): string {
		global $wpdb;

		return $wpdb->prefix . 'ab_memory_links';
	}

	/**
	 * Insert a raw memory row.
	 *
	 * @param array<string, mixed> $data Keys: agent_id, scope_id, type, content,
	 *   search_keys?, importance?, source_ids?, status?, agent_written?, client?,
	 *   via?, read_set?, expires?, created?.
	 * @return int Newly inserted row id.
	 * @throws InvalidArgumentException on any validation failure (fail loud, L26).
	 * @throws InsertFailed when the database does not store the row (#183).
	 */
	public function insert(array $data): int {
		global $wpdb;

		$agentId = (int) ( $data['agent_id'] ?? 0 );
		if ( $agentId <= 0 ) {
			throw new InvalidArgumentException( 'agent_id must be a positive integer' );
		}

		$scopeId = (int) ( $data['scope_id'] ?? 0 );
		if ( $scopeId <= 0 ) {
			throw new InvalidArgumentException( 'scope_id must be a positive integer' );
		}

		$type = (string) ( $data['type'] ?? '' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static enum list, not user output.
			throw new InvalidArgumentException( 'type must be one of: ' . implode( ', ', self::TYPES ) );
		}

		$content = (string) ( $data['content'] ?? '' );
		if ( trim( $content ) === '' ) {
			throw new InvalidArgumentException( 'content must not be empty' );
		}

		$status = (string) ( $data['status'] ?? 'active' );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static enum list, not user output.
			throw new InvalidArgumentException( 'status must be one of: ' . implode( ', ', self::STATUSES ) );
		}

		$importance = (int) ( $data['importance'] ?? 50 );
		if ( $importance < 0 || $importance > 100 ) {
			throw new InvalidArgumentException( 'importance must be between 0 and 100' );
		}

		$searchKeys = isset( $data['search_keys'] ) ? (string) $data['search_keys'] : null;

		$sourceIds = null;
		if ( isset( $data['source_ids'] ) && is_array( $data['source_ids'] ) ) {
			$sourceIds = wp_json_encode( array_values( array_map( 'intval', $data['source_ids'] ) ) );
		}

		$agentWritten = array_key_exists( 'agent_written', $data ) ? ( (int) (bool) $data['agent_written'] ) : 1;

		// P2 (the design contracts "Fix wave — mixed Kimi +
		// Claude team test", Unit P): optional MCP client provenance,
		// sanitized identically to Core\ClientContext (Unit M) — allow only
		// [A-Za-z0-9 ._/-], max 64 chars, otherwise null. System-written
		// memories (compression, outcome, assembly) never pass this key, so
		// it stays null for them.
		$client = null;
		if ( isset( $data['client'] ) && is_string( $data['client'] ) ) {
			$candidate = trim( $data['client'] );
			if ( $candidate !== '' && preg_match( '/^[A-Za-z0-9 ._\/-]+$/', $candidate ) === 1 ) {
				$client = mb_substr( $candidate, 0, 64 );
			}
		}

		$created = isset( $data['created'] ) ? (string) $data['created'] : gmdate( 'Y-m-d H:i:s' );

		// PROV-6 (#175): action-context read set, sanitized to identifiers
		// only — each element must be a whitelisted kind with a positive int
		// id (and optional positive int version) or it is dropped. Stored as
		// JSON (LONGTEXT column, migration 5); absent/empty stays SQL NULL so
		// rows written outside an MCP session never carry an empty set.
		$readSet = null;
		if ( isset( $data['read_set'] ) && is_array( $data['read_set'] ) && $data['read_set'] !== [] ) {
			$sanitized = [];
			foreach ( $data['read_set'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$kind = (string) ( $item['kind'] ?? '' );
				$id   = (int) ( $item['id'] ?? 0 );
				if ( $id <= 0 || ! in_array( $kind, ActionContext::KINDS, true ) ) {
					continue;
				}

				$version = isset( $item['version'] ) ? (int) $item['version'] : null;

				$entry = [ 'kind' => $kind, 'id' => $id ];
				if ( $version !== null && $version > 0 ) {
					$entry['version'] = $version;
				}

				$sanitized[] = $entry;
			}

			if ( $sanitized !== [] ) {
				$readSet = wp_json_encode( $sanitized, JSON_UNESCAPED_UNICODE );
			}
		}

		$expires = null;
		if ( array_key_exists( 'expires', $data ) && $data['expires'] !== null ) {
			$expires = (string) $data['expires'];
		} elseif ( $type === 'working' ) {
			// MEM-4: working memory defaults to now + ab_working_ttl_hours unless
			// the caller explicitly supplied (or explicitly nulled) an expiry.
			$ttlHours = self::workingTtlHours();
			$expires  = gmdate( 'Y-m-d H:i:s', strtotime( $created ) + ( $ttlHours * HOUR_IN_SECONDS ) );
		}

		$table = self::memoryTable();

		// source_ids, client and read_set all have no default in the schema
		// and %s would cast a PHP null to '' (empty string) rather than SQL
		// NULL (confirmed by BatchReader's dual '' / NULL predicate for
		// source_ids, and by this method's own tests for client) — so when
		// any of them is absent we splice the NULL literal directly instead
		// of a placeholder. Safe: these branches never carry caller-
		// controlled data, only a fixed literal chosen by this method (the
		// read_set JSON itself is built from whitelisted identifiers above).
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$sourceIdsColumn = $sourceIds === null ? 'NULL' : '%s';
		$clientColumn    = $client === null ? 'NULL' : '%s';
		$via             = isset( $data['via'] ) && (int) $data['via'] > 0 ? (int) $data['via'] : null;
		$viaColumn       = $via === null ? 'NULL' : '%d';
		$readSetColumn   = $readSet === null ? 'NULL' : '%s';

		$params = [ $agentId, $scopeId, $type, $content, $searchKeys, $importance ];
		if ( $sourceIds !== null ) {
			$params[] = $sourceIds;
		}
		$params[] = $status;
		$params[] = $agentWritten;
		if ( $client !== null ) {
			$params[] = $client;
		}
		if ( $via !== null ) {
			$params[] = $via;
		}
		if ( $readSet !== null ) {
			$params[] = $readSet;
		}
		$params[] = $created;
		$params[] = $expires;

		$inserted = $wpdb->query( Db::prepare(
			"INSERT INTO {$table}
				(agent_id, scope_id, type, content, search_keys, importance, source_ids, status, agent_written, client, via, read_set, created, expires)
			VALUES (%d, %d, %s, %s, %s, %d, {$sourceIdsColumn}, %s, %d, {$clientColumn}, {$viaColumn}, {$readSetColumn}, %s, %s)",
			$params
		) );
		// phpcs:enable

		// After a failed INSERT, insert_id still holds the previous row's id
		// in this request, so it is read only after a stored row (#183).
		$id = $inserted !== false && (int) $inserted > 0 ? (int) $wpdb->insert_id : 0;
		if ( $id <= 0 ) {
			throw new InsertFailed( 'The memory row was not stored.' );
		}

		// Every row gets its PCP page id and path here, the one place rows
		// are created (agents, rollover, compression, review outcomes, handoffs).
		PageIndex::assignMemory( $id, $type );

		return $id;
	}

	/**
	 * Fetch a single memory row, scoped. Returns null if not found OR the
	 * caller's allowed scopes don't include it OR the caller has no allowed
	 * scopes at all (in which case no query is issued).
	 *
	 * @param int[] $allowedScopeIds
	 * @return array<string, mixed>|null
	 */
	public function get( int $id, array $allowedScopeIds ): ?array {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		if ( $allowedScopeIds === [] ) {
			return null;
		}

		$table        = self::memoryTable();
		$placeholders = self::placeholders( $allowedScopeIds );

		$rows = Db::getResults(
			"SELECT * FROM {$table} WHERE id = %d AND scope_id IN ({$placeholders}) LIMIT 1",
			array_merge( [ $id ], $allowedScopeIds )
		);

		return $rows[0] ?? null;
	}

	/**
	 * Full-text search over content + search_keys, scope-filtered in the same
	 * SQL statement. Empty allowed scopes ⇒ [] without querying (fail closed).
	 *
	 * Ranking is FULLTEXT relevance only in this wave (Wave 2 adds recency +
	 * importance blending on top) — the `relevance` column is returned so a
	 * caller can layer additional ordering without a second query.
	 *
	 * @param int[] $allowedScopeIds
	 * @param array{type?:string,status?:string} $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $q, array $allowedScopeIds, int $limit = self::DEFAULT_LIMIT, array $filters = [] ): array {
		$allowedScopeIds = self::sanitizeIds( $allowedScopeIds );
		if ( $allowedScopeIds === [] || trim( $q ) === '' ) {
			return [];
		}

		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );

		// SQLite drop-in (#405): MATCH ... AGAINST is untranslated and
		// errors. Route to the scoped LIKE/BM25 fallback, which is
		// SQLite-safe; $filters (no caller uses them today) are re-applied
		// in PHP because the fallback's SQL has no type/status columns in
		// its predicate.
		if ( SqliteRuntime::isActive() ) {
			$rows = ( new MemoryScopedSearch() )->searchLike( $q, $allowedScopeIds, $limit );

			if ( $filters !== [] ) {
				$rows = array_values( array_filter( $rows, static function ( array $row ) use ( $filters ): bool {
					if ( isset( $filters['type'] ) && $row['type'] !== $filters['type'] ) {
						return false;
					}
					if ( isset( $filters['status'] ) && $row['status'] !== $filters['status'] ) {
						return false;
					}
					return true;
				} ) );
			}

			return $rows;
		}

		$table = self::memoryTable();

		$placeholders = self::placeholders( $allowedScopeIds );
		$params       = [ $q, $q ];

		$sql = "SELECT *, MATCH(content, search_keys) AGAINST (%s IN NATURAL LANGUAGE MODE) AS relevance
			FROM {$table}
			WHERE MATCH(content, search_keys) AGAINST (%s IN NATURAL LANGUAGE MODE)
			AND scope_id IN ({$placeholders})";
		$params = array_merge( $params, $allowedScopeIds );

		if ( isset( $filters['type'] ) && in_array( $filters['type'], self::TYPES, true ) ) {
			$sql     .= ' AND type = %s';
			$params[] = $filters['type'];
		}

		if ( isset( $filters['status'] ) && in_array( $filters['status'], self::STATUSES, true ) ) {
			$sql     .= ' AND status = %s';
			$params[] = $filters['status'];
		}

		$sql     .= ' ORDER BY relevance DESC LIMIT %d';
		$params[] = $limit;

		return Db::getResults( $sql, $params );
	}

	/**
	 * Record that $derivedId (of $derivedKind) was derived from every id in
	 * $sourceIds (always raw memory ids). Idempotent: re-linking an existing
	 * pair is a no-op.
	 *
	 * @param int[] $sourceIds
	 */
	public function link( string $derivedKind, int $derivedId, array $sourceIds ): void {
		global $wpdb;

		if ( ! in_array( $derivedKind, self::LINK_KINDS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static enum list, not user output.
			throw new InvalidArgumentException( 'derivedKind must be one of: ' . implode( ', ', self::LINK_KINDS ) );
		}

		$sourceIds = self::sanitizeIds( $sourceIds );
		if ( $sourceIds === [] ) {
			return;
		}

		$table = self::linksTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		foreach ( $sourceIds as $sourceId ) {
			$wpdb->query( Db::prepare(
				"INSERT IGNORE INTO {$table} (derived_kind, derived_id, source_id) VALUES (%s, %d, %d)",
				[ $derivedKind, $derivedId, $sourceId ]
			) );
		}
		// phpcs:enable
	}

	/**
	 * Full transitive closure of source memory ids feeding $derivedId
	 * (ancestors). Cycle-safe: a node already visited is never re-expanded.
	 *
	 * @return int[]
	 */
	public function sourcesOf( string $derivedKind, int $derivedId ): array {
		$visitedNodes = [ $derivedKind . ':' . $derivedId => true ];
		$queue        = [ [ $derivedKind, $derivedId ] ];
		$sources      = [];

		while ( $queue !== [] ) {
			[ $kind, $id ] = array_shift( $queue );

			foreach ( $this->directSources( $kind, $id ) as $sourceId ) {
				$sources[ $sourceId ] = true;

				$nodeKey = 'memory:' . $sourceId;
				if ( ! isset( $visitedNodes[ $nodeKey ] ) ) {
					$visitedNodes[ $nodeKey ] = true;
					$queue[]                  = [ 'memory', $sourceId ];
				}
			}
		}

		return array_map( 'intval', array_keys( $sources ) );
	}

	/**
	 * Full transitive closure of everything derived from raw memory
	 * $sourceMemoryId (descendants) — used to cascade a delete (MEM-3/FGT-1).
	 * Cycle-safe.
	 *
	 * @return array<int, array{kind:string, id:int}>
	 */
	public function derivedFrom( int $sourceMemoryId ): array {
		$visitedNodes = [ 'memory:' . $sourceMemoryId => true ];
		$queue        = [ $sourceMemoryId ];
		$derived      = [];
		$seenDerived  = [];

		while ( $queue !== [] ) {
			$sourceId = array_shift( $queue );

			foreach ( $this->directDerived( $sourceId ) as $pair ) {
				$key = $pair['kind'] . ':' . $pair['id'];
				if ( isset( $seenDerived[ $key ] ) ) {
					continue;
				}
				$seenDerived[ $key ] = true;
				$derived[]           = $pair;

				if ( $pair['kind'] === 'memory' && ! isset( $visitedNodes[ $key ] ) ) {
					$visitedNodes[ $key ] = true;
					$queue[]              = $pair['id'];
				}
			}
		}

		return $derived;
	}

	/** @return int[] */
	private function directSources( string $derivedKind, int $derivedId ): array {
		$table = self::linksTable();

		$rows = Db::getResults(
			"SELECT source_id FROM {$table} WHERE derived_kind = %s AND derived_id = %d",
			[ $derivedKind, $derivedId ]
		);

		return array_map( static fn( array $row ): int => (int) $row['source_id'], $rows );
	}

	/** @return array<int, array{kind:string, id:int}> */
	private function directDerived( int $sourceId ): array {
		$table = self::linksTable();

		$rows = Db::getResults(
			"SELECT derived_kind, derived_id FROM {$table} WHERE source_id = %d",
			[ $sourceId ]
		);

		return array_map(
			static fn( array $row ): array => [ 'kind' => (string) $row['derived_kind'], 'id' => (int) $row['derived_id'] ],
			$rows
		);
	}

	/**
	 * MAINTENANCE ONLY — no scope filter. Never call from an agent-facing
	 * read path; this exists solely for the hourly rollover cron
	 * (Rollover::run()) to find working memory past its TTL.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function maintenanceExpiredWorking(): array {
		$table = self::memoryTable();

		return Db::getResults(
			"SELECT * FROM {$table}
				WHERE type = %s AND status = %s AND expires IS NOT NULL AND expires <= %s
				ORDER BY agent_id, scope_id, id",
			[ 'working', 'active', gmdate( 'Y-m-d H:i:s' ) ]
		);
	}

	/**
	 * MAINTENANCE ONLY — no scope filter. Marks rows rolled_up after a
	 * successful rollover insert + link.
	 *
	 * @param int[] $ids
	 */
	public function maintenanceMarkRolledUp( array $ids ): void {
		global $wpdb;

		$ids = self::sanitizeIds( $ids );
		if ( $ids === [] ) {
			return;
		}

		$table        = self::memoryTable();
		$placeholders = self::placeholders( $ids );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query( Db::prepare(
			"UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})",
			array_merge( [ 'rolled_up' ], $ids )
		) );
		// phpcs:enable
	}

	public static function workingTtlHours(): int {
		$ttl = (int) get_option( 'ab_working_ttl_hours', 48 );

		return $ttl > 0 ? $ttl : 48;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function sanitizeIds( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		return array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );
	}

	/** @param int[] $ids */
	private static function placeholders( array $ids ): string {
		return implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
	}
}
