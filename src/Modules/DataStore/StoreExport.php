<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Portability\ArchiveFiles;
use AgentBrain\Modules\Portability\ExportFormat;
use AgentBrain\Modules\Portability\ExtensionIds;
use AgentBrain\Modules\Portability\PrincipalMap;

/**
 * ART-23 (#163): the data store in PCP exports, under
 * extensions/<ExtensionIds::ID_BASE>.data-store/ (numeric file names, which
 * the PCP path grammar always admits; keys travel inside the JSON):
 *
 *  - docs/<artifact id>/<collection>/<doc id>.json: one store document, its
 *    value, revision, owner, attribution and artifact version;
 *  - history/<artifact id>/<history id>.json (#547): one row of the 30-day
 *    write history (StoreHistory) that version restore and undo-a-link read;
 *  - seats/<artifact id>/<seat id>.json (#547): a seat, with the sha256 of
 *    its secret. That is a verifier, not a credential: it keeps the name
 *    reserved and its colour stable, and nobody can claim the seat from it.
 *
 * Writers are principal ids (PrincipalMap), with their kind (person, agent
 * or anonymous) beside them; share links are named share-<id>, the doc
 * extension's records of them (Portability\ArtifactRecords). Only data of
 * exported artifacts is written. Written through the
 * agent_brain/pcp_extensions filter that Portability\ExtensionWriter::writeAll()
 * applies after its two built-in extensions, and read back by StoreRestore;
 * the manifest entry reuses ExtensionIds::LAYOUT_VERSION and the doc slot. A
 * brain with no store data, history or seats adds no files and no entry.
 */
final class StoreExport {

	public const SLOT = 'doc';

	public const DOC_KEYS     = [ 'id', 'artifact', 'collection', 'key', 'value', 'size', 'revision', 'owner', 'created_by', 'created_by_type', 'created_by_name', 'created_at', 'created_via', 'updated_by', 'updated_by_type', 'updated_by_name', 'updated_at', 'updated_via', 'artifact_version' ];
	public const HISTORY_KEYS = [ 'id', 'artifact', 'collection', 'key', 'version', 'op', 'value', 'delta', 'by', 'by_type', 'by_name', 'via', 'at' ];
	public const SEAT_KEYS    = [ 'id', 'artifact', 'name', 'secret_sha256', 'created_at', 'opens', 'last_seen_at', 'via' ];

	public static function id(): string {
		return ExtensionIds::ID_BASE . '.data-store';
	}

	/**
	 * agent_brain/pcp_extensions: append this extension's files and manifest
	 * entry.
	 *
	 * @param mixed                                   $entries
	 * @return array<int, array<string, mixed>>
	 */
	public static function collect( $entries, ArchiveFiles $files ): array {
		if ( ! is_array( $entries ) ) {
			$entries = [];
		}

		$principals = new PrincipalMap();
		$base       = ExtensionIds::path( self::id() );
		$items      = 0;
		foreach ( self::rows( StoreData::table(), [ 'artifact_id', 'collection', 'id' ] ) as $doc ) {
			$files->tick();
			$files->write( self::path( $doc ), ExportFormat::document( self::record( $doc, $principals ) ) );
			++$items;
		}
		foreach ( self::rows( StoreHistory::table(), [ 'artifact_id', 'id' ] ) as $row ) {
			$files->tick();
			$files->write( $base . 'history/' . (int) $row['artifact_id'] . '/' . (int) $row['id'] . '.json', ExportFormat::document( self::historyRecord( $row, $principals ) ) );
			++$items;
		}
		foreach ( self::rows( StoreSeats::table(), [ 'artifact_id', 'id' ] ) as $row ) {
			$files->tick();
			$files->write( $base . 'seats/' . (int) $row['artifact_id'] . '/' . (int) $row['id'] . '.json', ExportFormat::document( self::seatRecord( $row ) ) );
			++$items;
		}

		if ( $items === 0 ) {
			return $entries;
		}

		$entries[] = [
			'id'      => self::id(),
			'path'    => $base,
			'slot'    => self::SLOT,
			'version' => ExtensionIds::LAYOUT_VERSION,
			'items'   => $items,
		];

		return $entries;
	}

	/**
	 * The archive path of one document: numeric segments only.
	 *
	 * @param array<string, mixed> $doc
	 */
	public static function path( array $doc ): string {
		return ExtensionIds::path( self::id() )
			. 'docs/' . (int) $doc['artifact_id'] . '/'
			. (string) $doc['collection'] . '/'
			. (int) $doc['id'] . '.json';
	}

	/**
	 * The fixed-order export record of one store row.
	 *
	 * @param array<string, mixed> $doc
	 * @return array<string, mixed>
	 */
	public static function record( array $doc, ?PrincipalMap $principals = null ): array {
		$principals ??= new PrincipalMap();
		// Objects stay objects, so an empty {} is not written back as [].
		$decoded      = json_decode( (string) $doc['value'] );

		return [
			'id'               => 'datastore-' . (int) $doc['id'],
			'artifact'         => 'artifact-' . (int) $doc['artifact_id'],
			'collection'       => (string) $doc['collection'],
			'key'              => (string) $doc['doc_key'],
			'value'            => $decoded,
			'size'             => (int) $doc['size'],
			'revision'         => (int) ( $doc['revision'] ?? 1 ),
			'owner'            => isset( $doc['owner'] ) ? ExportFormat::validUtf8( (string) $doc['owner'] ) : null,
			'created_by'       => self::principal( (string) $doc['created_by_type'], $doc['created_by_id'], $principals ),
			'created_by_type'  => self::type( (string) $doc['created_by_type'] ),
			'created_by_name'  => $doc['created_by_name'] !== null ? ExportFormat::validUtf8( (string) $doc['created_by_name'] ) : null,
			'created_at'       => ExportFormat::utc( (string) $doc['created_at'] ),
			'created_via'      => self::link( $doc['created_via_link'] ?? null ),
			'updated_by'       => self::principal( (string) $doc['updated_by_type'], $doc['updated_by_id'], $principals ),
			'updated_by_type'  => self::type( (string) $doc['updated_by_type'] ),
			'updated_by_name'  => $doc['updated_by_name'] !== null ? ExportFormat::validUtf8( (string) $doc['updated_by_name'] ) : null,
			'updated_at'       => ExportFormat::utc( (string) $doc['updated_at'] ),
			'updated_via'      => self::link( $doc['updated_via_link'] ?? null ),
			'artifact_version' => (int) $doc['artifact_version'],
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function historyRecord( array $row, PrincipalMap $principals ): array {
		return [
			'id'         => 'datastore-history-' . (int) $row['id'],
			'artifact'   => 'artifact-' . (int) $row['artifact_id'],
			'collection' => (string) $row['collection'],
			'key'        => (string) $row['doc_key'],
			'version'    => (int) $row['version'],
			'op'         => (string) $row['op'],
			'value'      => $row['value'] !== null ? ExportFormat::validUtf8( (string) $row['value'] ) : null,
			'delta'      => $row['delta'] !== null ? ExportFormat::validUtf8( (string) $row['delta'] ) : null,
			'by'         => self::principal( (string) $row['by_type'], $row['by_id'], $principals ),
			'by_type'    => self::type( (string) $row['by_type'] ),
			'by_name'    => $row['by_name'] !== null ? ExportFormat::validUtf8( (string) $row['by_name'] ) : null,
			'via'        => self::link( $row['via_link'] ?? null ),
			'at'         => ExportFormat::utc( (string) $row['at'] ),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function seatRecord( array $row ): array {
		return [
			'id'            => 'seat-' . (int) $row['id'],
			'artifact'      => 'artifact-' . (int) $row['artifact_id'],
			'name'          => ExportFormat::validUtf8( (string) $row['name'] ),
			'secret_sha256' => strtolower( (string) $row['secret_hash'] ),
			'created_at'    => ExportFormat::utc( (string) $row['created_at'] ),
			'opens'         => (int) $row['opens'],
			'last_seen_at'  => ExportFormat::utc( isset( $row['last_seen_at'] ) ? (string) $row['last_seen_at'] : null ),
			'via'           => self::link( $row['link_id'] ?? null ),
		];
	}

	/**
	 * Every row of a store table whose artifact is exported (not trashed),
	 * in a stable order.
	 *
	 * @param array<int, string> $order columns of the table, ascending
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( string $table, array $order ): array {
		global $wpdb;

		$by = implode( ', ', array_map( static fn ( string $column ): string => 't.' . $column . ' ASC', $order ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from Db::table(), fixed columns.
		return \AgentBrain\Core\Db::getResults( "SELECT t.* FROM {$table} t INNER JOIN {$wpdb->posts} p ON p.ID = t.artifact_id AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') ORDER BY {$by}" );
	}

	private static function type( string $type ): string {
		return $type === 'person' || $type === 'agent' ? $type : 'anonymous';
	}

	/**
	 * @param mixed $id
	 */
	private static function principal( string $type, $id, PrincipalMap $principals ): ?string {
		if ( self::type( $type ) === 'anonymous' || $id === null || (int) $id <= 0 ) {
			return null;
		}

		return $principals->idFor( (int) $id );
	}

	/**
	 * @param mixed $id
	 */
	private static function link( $id ): ?string {
		return $id !== null && (int) $id > 0 ? 'share-' . (int) $id : null;
	}
}
