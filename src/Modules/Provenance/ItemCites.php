<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Core\Db;
use InvalidArgumentException;

/**
 * Data access for item citations (ab_item_cites), PROV-2 (#171).
 *
 * A cite is a link from a written item (a memory row, a section version, a
 * proposed knowledge post) to the brain items its author used: memory ids,
 * knowledge ids (approved ab_knowledge/ab_procedure posts) and section ids
 * with optional versions. Identifiers only — never content copies, per the
 * provenance design (epic #169).
 *
 * Readability is enforced at the MCP boundary (Abilities), never here: a
 * foreign or missing id must be indistinguishable to the caller, so the
 * validation that rejects unknown/unreadable ids lives next to the ability
 * callbacks and this repository only stores what it is given. link() still
 * runs normalizeEntry() on every cite so direct callers (tests, later
 * wp-admin screens) cannot write malformed rows.
 *
 * item_version records the version of the item at write time (section
 * updates); cited_version records which section version the author cited
 * (optional). Unversioned kinds store NULL.
 */
class ItemCites {

	public const ITEM_KINDS  = [ 'memory', 'section', 'knowledge' ];
	public const CITED_KINDS = [ 'memory', 'knowledge', 'section' ];

	public static function table(): string {
		return Db::table( 'item_cites' );
	}

	/**
	 * Pure shape validation of one cites[] entry (no database access, no
	 * readability check — the MCP layer owns the latter).
	 *
	 * @param mixed $entry Raw entry from MCP input.
	 * @return array{kind:string, id:int, version:?int}
	 * @throws InvalidArgumentException on any shape failure (fail loud, L26).
	 */
	public static function normalizeEntry( $entry, int $index ): array {
		$label = sprintf( 'cites[%d]', $index );

		if ( ! is_array( $entry ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caller-fixed field label, not user output.
			throw new InvalidArgumentException( $label . ' must be an object with kind and id' );
		}

		$kind = (string) ( $entry['kind'] ?? '' );
		if ( ! in_array( $kind, self::CITED_KINDS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static enum list, not user output.
			throw new InvalidArgumentException( $label . '.kind must be one of: ' . implode( ', ', self::CITED_KINDS ) );
		}

		if ( ! isset( $entry['id'] ) || ! is_numeric( $entry['id'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caller-fixed field label, not user output.
			throw new InvalidArgumentException( $label . '.id must be a positive integer' );
		}
		$id = (int) $entry['id'];
		if ( $id <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caller-fixed field label, not user output.
			throw new InvalidArgumentException( $label . '.id must be a positive integer' );
		}

		$version = null;
		if ( isset( $entry['version'] ) && trim( (string) $entry['version'] ) !== '' ) {
			if ( $kind !== 'section' ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caller-fixed field label, not user output.
				throw new InvalidArgumentException( $label . '.version is only valid for section cites' );
			}
			if ( ! is_numeric( $entry['version'] ) || (int) $entry['version'] <= 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caller-fixed field label, not user output.
				throw new InvalidArgumentException( $label . '.version must be a positive integer' );
			}
			$version = (int) $entry['version'];
		}

		return [ 'kind' => $kind, 'id' => $id, 'version' => $version ];
	}

	/**
	 * Link cites to an item, appending after the current highest position so
	 * citation order is stable. Idempotent per (item, cited kind + id): a
	 * duplicate cite keeps its first position and is skipped afterwards.
	 *
	 * @param array<int, array{kind:string, id:int, version:?int}>|array<int, mixed> $cites
	 * @param int|null $itemVersion Version of the item being written (sections).
	 */
	public function link( string $itemKind, int $itemId, array $cites, ?int $itemVersion = null ): void {
		global $wpdb;

		if ( ! in_array( $itemKind, self::ITEM_KINDS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static enum list, not user output.
			throw new InvalidArgumentException( 'itemKind must be one of: ' . implode( ', ', self::ITEM_KINDS ) );
		}

		if ( $itemId <= 0 || $cites === [] ) {
			return;
		}

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		foreach ( $cites as $i => $rawCite ) {
			// normalizeEntry is idempotent over already-normalized entries,
			// so pre-normalized MCP input and raw direct-caller input take
			// the same path.
			$cite = self::normalizeEntry( $rawCite, (int) $i );

			$existing = $wpdb->get_var( Db::prepare(
				"SELECT position FROM {$table} WHERE item_kind = %s AND item_id = %d AND cited_kind = %s AND cited_id = %d LIMIT 1",
				[ $itemKind, $itemId, $cite['kind'], $cite['id'] ]
			) );
			if ( $existing !== null ) {
				continue;
			}

			$position = (int) $wpdb->get_var( Db::prepare(
				"SELECT COALESCE(MAX(position), 0) + 1 FROM {$table} WHERE item_kind = %s AND item_id = %d",
				[ $itemKind, $itemId ]
			) );

			$itemVersionColumn   = $itemVersion === null ? 'NULL' : '%d';
			$citedVersionColumn  = empty( $cite['version'] ) ? 'NULL' : '%d';

			$params = [ $itemKind, $itemId ];
			if ( $itemVersion !== null ) {
				$params[] = $itemVersion;
			}
			$params[] = $cite['kind'];
			$params[] = $cite['id'];
			if ( ! empty( $cite['version'] ) ) {
				$params[] = (int) $cite['version'];
			}
			$params[] = $position;

			$wpdb->query( Db::prepare(
				"INSERT INTO {$table}
					(item_kind, item_id, item_version, cited_kind, cited_id, cited_version, position)
				VALUES (%s, %d, {$itemVersionColumn}, %s, %d, {$citedVersionColumn}, %d)
				ON DUPLICATE KEY UPDATE item_version = VALUES(item_version), cited_version = VALUES(cited_version)",
				$params
			) );
		}
		// phpcs:enable
	}

	/**
	 * Replace an item's citations with exactly $cites (delete all, re-link
	 * in order). Used by section-update, where each update rewrites the
	 * section and so re-declares what it cites; memory-write and proposals
	 * use link() because their items are written exactly once.
	 *
	 * @param array<int, array{kind:string, id:int, version:?int}>|array<int, mixed> $cites
	 */
	public function replace( string $itemKind, int $itemId, array $cites, ?int $itemVersion = null ): void {
		global $wpdb;

		if ( ! in_array( $itemKind, self::ITEM_KINDS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static enum list, not user output.
			throw new InvalidArgumentException( 'itemKind must be one of: ' . implode( ', ', self::ITEM_KINDS ) );
		}

		if ( $itemId <= 0 ) {
			return;
		}

		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query( Db::prepare(
			"DELETE FROM {$table} WHERE item_kind = %s AND item_id = %d",
			[ $itemKind, $itemId ]
		) );
		// phpcs:enable

		$this->link( $itemKind, $itemId, $cites, $itemVersion );
	}

	/**
	 * The cites recorded on an item, in citation (position) order.
	 *
	 * @return array<int, array{kind:string, id:int, version:?int, item_version:?int}>
	 */
	public function citesOf( string $itemKind, int $itemId ): array {
		$table = self::table();

		$rows = Db::getResults(
			"SELECT cited_kind, cited_id, cited_version, item_version FROM {$table}
				WHERE item_kind = %s AND item_id = %d
				ORDER BY position, cited_kind, cited_id",
			[ $itemKind, $itemId ]
		);

		return array_map(
			static fn( array $row ): array => [
				'kind'         => (string) $row['cited_kind'],
				'id'           => (int) $row['cited_id'],
				'version'      => isset( $row['cited_version'] ) && $row['cited_version'] !== null ? (int) $row['cited_version'] : null,
				'item_version' => isset( $row['item_version'] ) && $row['item_version'] !== null ? (int) $row['item_version'] : null,
			],
			$rows
		);
	}
}
