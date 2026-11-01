<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Uploads;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Portability\ArchiveFiles;
use AgentBrain\Modules\Portability\ExportFormat;
use AgentBrain\Modules\Portability\ExtensionIds;
use AgentBrain\Modules\Portability\Partitions;
use AgentBrain\Modules\Portability\PrincipalMap;

/**
 * ART-27 (#167): artifact uploads in a full PCP export, via the
 * `agent_brain/export_extensions` filter ExportBuilder applies while the
 * archive is staged. Uploads belong to the shared-documents extension (the
 * doc slot is where artifacts live), so the files land under
 * `extensions/<doc id>/uploads/<artifactId>/`:
 *
 * - `<uploadId>.json`: id, artifact, name, mime, size, file (the bytes'
 *   path below, #547, so a migration can put them back), uploaded_by (PCP
 *   principal id), recorded_at — fixed key order, so export bytes depend
 *   only on the data (SPEC 8.8);
 * - `files/<stored name>`: the raw bytes.
 *
 * With no uploads the filter returns no entry, so manifests of brains
 * without uploads are byte-for-byte what they were.
 */
final class UploadsExport {

	public const RECORD_KEYS = [ 'id', 'artifact', 'partition', 'name', 'mime', 'size', 'file', 'uploaded_by', 'recorded_at' ];

	/**
	 * `agent_brain/export_extensions` handler.
	 *
	 * @param array<int, array<string, mixed>> $entries
	 * @return array<int, array<string, mixed>>
	 */
	public static function extend( array $entries, ArchiveFiles $files ): array {
		$rows = UploadStore::all();
		if ( $rows === [] ) {
			return $entries;
		}

		$principals = new PrincipalMap();
		$base       = ExtensionIds::path( ExtensionIds::DOC ) . 'uploads/';
		$count      = 0;

		foreach ( $rows as $row ) {
			$artifactId = (int) $row['artifact_id'];
			$scopeId    = (int) get_post_meta( $artifactId, Cpts::META_SCOPE_ID, true );
			$partition  = $scopeId > 0 ? ( Partitions::nameFor( $scopeId ) ?? Partitions::DEFAULT_NAME ) : Partitions::DEFAULT_NAME;
			$record     = [
				'id'          => 'upload-' . (int) $row['id'],
				'artifact'    => 'artifact-' . $artifactId,
				'partition'   => $partition,
				'name'        => ExportFormat::validUtf8( (string) $row['name'] ),
				'mime'        => (string) $row['mime'],
				'size'        => (int) $row['size'],
				'file'        => 'files/' . (string) $row['stored_name'],
				'uploaded_by' => $principals->idFor( (int) $row['uploaded_by'] ),
				'recorded_at' => ExportFormat::utc( (string) $row['created_at'] ) ?? '1970-01-01T00:00:00Z',
			];

			$files->write( $base . $artifactId . '/' . (int) $row['id'] . '.json', ExportFormat::document( self::ordered( $record ) ) );

			$path = UploadStore::dirFor( $artifactId ) . '/' . (string) $row['stored_name'];
			$raw  = is_file( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- export reads the bytes the store validated.
			if ( $raw !== false ) {
				$files->write( $base . $artifactId . '/files/' . (string) $row['stored_name'], $raw );
			}

			$files->tick();
			++$count;
		}

		$entries[] = [
			'id'      => ExtensionIds::DOC,
			'path'    => ExtensionIds::path( ExtensionIds::DOC ),
			'slot'    => 'doc',
			'version' => ExtensionIds::LAYOUT_VERSION,
			'items'   => $count,
		];

		return $entries;
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private static function ordered( array $values ): array {
		$out = [];
		foreach ( self::RECORD_KEYS as $key ) {
			if ( array_key_exists( $key, $values ) && $values[ $key ] !== null ) {
				$out[ $key ] = $values[ $key ];
			}
		}

		return $out;
	}
}
