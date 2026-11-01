<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Core\Db;
use InvalidArgumentException;

/**
 * Data access for outside source records (ab_outside_sources) and the
 * generic item -> source link table (ab_item_sources), PROV-3 (#172).
 *
 * A source record is a small reference — kind, title, locator (URL or
 * free-text where-to-find-it), optional publisher / retrieved-at / hash —
 * never a copy of the source content. Registration is idempotent per
 * (registrar, kind, locator, hash): an agent re-registering a source it
 * already recorded gets the existing row's id back.
 *
 * Scope discipline: a record belongs to the agent that registered it.
 * get()/exists()/find() take an optional $forAgentId filter so the MCP
 * read path can only ever see the caller's own records — otherwise
 * source-get would allow id-enumeration across agents and the register
 * dedupe would leak whether another agent recorded a locator. Trusted
 * scope-agnostic callers (the wp-admin Memories screen, the PROV-4
 * provenance panel) pass null and may read every record. The link table
 * is generic over item_kind ('memory' today) so derived-from (#170) and
 * cites (#171) can hang their own item kinds off the same pattern.
 */
class OutsideSources {

	public const KINDS      = [ 'url', 'document', 'meeting', 'conversation', 'other' ];
	public const ITEM_KINDS = [ 'memory' ];
	public const MAX_TITLE      = 255;
	public const MAX_LOCATOR    = 65535;
	public const MAX_PUBLISHER  = 255;
	public const MAX_HASH_CHARS = 64;

	public static function sourcesTable(): string {
		return Db::table( 'outside_sources' );
	}

	public static function linksTable(): string {
		return Db::table( 'item_sources' );
	}

	/**
	 * Register an outside source record. Idempotent: when a record with the
	 * same kind, locator and hash (null hash = "no hash recorded", matched
	 * only against other nulls) already exists, its id is returned and
	 * nothing is inserted.
	 *
	 * @param array<string, mixed> $data Keys: kind, title, locator,
	 *   publisher?, retrieved_at?, hash?, agent_id, created?.
	 * @return int The record id (existing or newly inserted).
	 * @throws InvalidArgumentException on any validation failure (fail loud, L26).
	 */
	public function register( array $data ): int {
		global $wpdb;

		$kind = (string) ( $data['kind'] ?? '' );
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static enum list, not user output.
			throw new InvalidArgumentException( 'kind must be one of: ' . implode( ', ', self::KINDS ) );
		}

		$title = trim( (string) ( $data['title'] ?? '' ) );
		if ( $title === '' ) {
			throw new InvalidArgumentException( 'title must not be empty' );
		}
		$title = mb_substr( $title, 0, self::MAX_TITLE );

		$locator = trim( (string) ( $data['locator'] ?? '' ) );
		if ( $locator === '' ) {
			throw new InvalidArgumentException( 'locator must not be empty' );
		}
		if ( mb_strlen( $locator ) > self::MAX_LOCATOR ) {
			throw new InvalidArgumentException( 'locator is too long' );
		}

		$publisher = null;
		if ( isset( $data['publisher'] ) && trim( (string) $data['publisher'] ) !== '' ) {
			$publisher = mb_substr( trim( (string) $data['publisher'] ), 0, self::MAX_PUBLISHER );
		}

		$retrievedAt = self::normalizeDateTime( $data['retrieved_at'] ?? null, 'retrieved_at' );

		$hash = null;
		if ( isset( $data['hash'] ) && trim( (string) $data['hash'] ) !== '' ) {
			$hash = strtolower( trim( (string) $data['hash'] ) );
			if ( preg_match( '/^[0-9a-f]{' . self::MAX_HASH_CHARS . '}$/', $hash ) !== 1 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static integer constant, not user output.
				throw new InvalidArgumentException( 'hash must be ' . self::MAX_HASH_CHARS . ' hexadecimal characters' );
			}
		}

		$agentId = (int) ( $data['agent_id'] ?? 0 );
		if ( $agentId <= 0 ) {
			throw new InvalidArgumentException( 'agent_id must be a positive integer' );
		}

		$existing = $this->find( $kind, $locator, $hash, $agentId );
		if ( $existing !== null ) {
			return (int) $existing['id'];
		}

		$created = isset( $data['created'] ) ? (string) $data['created'] : gmdate( 'Y-m-d H:i:s' );

		$table = self::sourcesTable();

		// publisher / retrieved_at / hash are NULL-able; %s would cast a PHP
		// null to '' (same splice rationale as MemoryRepository::insert()).
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$publisherColumn   = $publisher === null ? 'NULL' : '%s';
		$retrievedAtColumn = $retrievedAt === null ? 'NULL' : '%s';
		$hashColumn        = $hash === null ? 'NULL' : '%s';

		$params = [ $kind, $title, $locator ];
		if ( $publisher !== null ) {
			$params[] = $publisher;
		}
		if ( $retrievedAt !== null ) {
			$params[] = $retrievedAt;
		}
		if ( $hash !== null ) {
			$params[] = $hash;
		}
		$params[] = $agentId;
		$params[] = $created;

		$wpdb->query( Db::prepare(
			"INSERT INTO {$table}
				(kind, title, locator, publisher, retrieved_at, hash, agent_id, created)
			VALUES (%s, %s, %s, {$publisherColumn}, {$retrievedAtColumn}, {$hashColumn}, %d, %s)",
			$params
		) );
		// phpcs:enable

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a single source record, or null when no row has that id OR the
	 * row belongs to another agent than $forAgentId. Pass null to read
	 * without the registrar filter (trusted, scope-agnostic callers only:
	 * wp-admin screens, the PROV-4 provenance panel).
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id, ?int $forAgentId = null ): ?array {
		global $wpdb;

		$table = self::sourcesTable();

		if ( $forAgentId === null ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$row = $wpdb->get_row( Db::prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", [ $id ] ), ARRAY_A );
			// phpcs:enable
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$row = $wpdb->get_row( Db::prepare( "SELECT * FROM {$table} WHERE id = %d AND agent_id = %d LIMIT 1", [ $id, $forAgentId ] ), ARRAY_A );
			// phpcs:enable
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Idempotency lookup behind register(): same registrar, same kind, same
	 * locator, same hash (null matched only against null). Scoping dedupe
	 * to the registrar keeps the dedupe decision — and so the register
	 * response's deduped flag — from leaking other agents' records.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( string $kind, string $locator, ?string $hash, int $forAgentId ): ?array {
		global $wpdb;

		$table = self::sourcesTable();

		if ( $hash === null ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$row = $wpdb->get_row( Db::prepare(
				"SELECT * FROM {$table} WHERE kind = %s AND locator = %s AND hash IS NULL AND agent_id = %d LIMIT 1",
				[ $kind, $locator, $forAgentId ]
			), ARRAY_A );
			// phpcs:enable
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$row = $wpdb->get_row( Db::prepare(
				"SELECT * FROM {$table} WHERE kind = %s AND locator = %s AND hash = %s AND agent_id = %d LIMIT 1",
				[ $kind, $locator, $hash, $forAgentId ]
			), ARRAY_A );
			// phpcs:enable
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A source record must exist AND belong to $forAgentId before an agent
	 * may link it to one of its items; foreign or missing ids are
	 * indistinguishable to the caller (no existence leak).
	 */
	public function exists( int $id, int $forAgentId ): bool {
		return $this->get( $id, $forAgentId ) !== null;
	}

	/**
	 * Link source records to an item. Idempotent: re-linking an existing
	 * pair is a no-op, and new links are appended after the current highest
	 * position so citation order is stable.
	 *
	 * @param int[] $sourceIds
	 */
	public function link( string $itemKind, int $itemId, array $sourceIds ): void {
		global $wpdb;

		if ( ! in_array( $itemKind, self::ITEM_KINDS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static enum list, not user output.
			throw new InvalidArgumentException( 'itemKind must be one of: ' . implode( ', ', self::ITEM_KINDS ) );
		}

		$sourceIds = self::sanitizeIds( $sourceIds );
		if ( $itemId <= 0 || $sourceIds === [] ) {
			return;
		}

		$table = self::linksTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		foreach ( $sourceIds as $sourceId ) {
			$position = (int) $wpdb->get_var( Db::prepare(
				"SELECT COALESCE(MAX(position), 0) + 1 FROM {$table} WHERE item_kind = %s AND item_id = %d",
				[ $itemKind, $itemId ]
			) );

			$wpdb->query( Db::prepare(
				"INSERT IGNORE INTO {$table} (item_kind, item_id, source_id, position) VALUES (%s, %d, %d, %d)",
				[ $itemKind, $itemId, $sourceId, $position ]
			) );
		}
		// phpcs:enable
	}

	/**
	 * Replace an item's source links with exactly $sourceIds (delete all,
	 * re-link in order). Used by the wp-admin edit path; MCP writes use
	 * link() because a memory row is written exactly once.
	 *
	 * @param int[] $sourceIds
	 */
	public function replace( string $itemKind, int $itemId, array $sourceIds ): void {
		global $wpdb;

		if ( ! in_array( $itemKind, self::ITEM_KINDS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static enum list, not user output.
			throw new InvalidArgumentException( 'itemKind must be one of: ' . implode( ', ', self::ITEM_KINDS ) );
		}

		if ( $itemId <= 0 ) {
			return;
		}

		$table = self::linksTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query( Db::prepare(
			"DELETE FROM {$table} WHERE item_kind = %s AND item_id = %d",
			[ $itemKind, $itemId ]
		) );
		// phpcs:enable

		$this->link( $itemKind, $itemId, $sourceIds );
	}

	/**
	 * The source records an item names, in citation (position) order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sourcesOf( string $itemKind, int $itemId ): array {
		$links = self::linksTable();
		$tables = self::sourcesTable();

		return Db::getResults(
			"SELECT s.*, l.position AS link_position FROM {$links} l
				INNER JOIN {$tables} s ON s.id = l.source_id
				WHERE l.item_kind = %s AND l.item_id = %d
				ORDER BY l.position, l.source_id",
			[ $itemKind, $itemId ]
		);
	}

	/**
	 * Every item a source record is linked from (both directions of the
	 * link table; kept for the provenance panel, PROV-4).
	 *
	 * @return array<int, array{kind:string, id:int}>
	 */
	public function itemsOf( int $sourceId ): array {
		$table = self::linksTable();

		$rows = Db::getResults(
			"SELECT item_kind, item_id FROM {$table} WHERE source_id = %d ORDER BY item_kind, item_id",
			[ $sourceId ]
		);

		return array_map(
			static fn( array $row ): array => [ 'kind' => (string) $row['item_kind'], 'id' => (int) $row['item_id'] ],
			$rows
		);
	}

	/**
	 * Accepts null (absent), a MySQL datetime or an ISO 8601 string;
	 * returns a MySQL 'Y-m-d H:i:s' UTC string or null. Anything else is a
	 * validation failure (fail loud, L26) rather than a silent drop.
	 *
	 * @param mixed $value
	 */
	private static function normalizeDateTime( $value, string $field ): ?string {
		if ( $value === null || trim( (string) $value ) === '' ) {
			return null;
		}

		$raw   = trim( (string) $value );
		$hasZone = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $raw );
		$ts    = strtotime( $hasZone ? $raw : $raw . ' UTC' );

		if ( $ts === false ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caller-fixed field name, not user output.
			throw new InvalidArgumentException( $field . ' must be a parseable date/time' );
		}

		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function sanitizeIds( array $ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		return array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );
	}
}
