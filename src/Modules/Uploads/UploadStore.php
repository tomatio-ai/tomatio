<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Uploads;

use AgentBrain\Core\Db;

/**
 * ART-27 (#167): the asset store. One row per upload in `ab_artifact_uploads`
 * (who uploaded what, when, under which served type) and the bytes on disk
 * under `wp-content/uploads/agent-brain/artifacts/<artifactId>/` — the path
 * the feature prescribes. Nothing is ever served from that URL: the bytes
 * leave the site only through UploadsRoute (validated share link, forced
 * attachment). Stored names are random so the directory is not enumerable;
 * deny files guard servers that honour them. Deletion removes the row and
 * the bytes together; deleting an artifact removes the whole directory.
 */
final class UploadStore {

	public static function table(): string {
		return Db::table( 'artifact_uploads' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			artifact_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			stored_name VARCHAR(64) NOT NULL,
			mime VARCHAR(100) NOT NULL,
			size BIGINT UNSIGNED NOT NULL,
			uploaded_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY artifact_id (artifact_id)
		) {$charset};";
	}

	/**
	 * The bytes root: <uploads>/agent-brain/artifacts. Created lazily with
	 * deny files so a direct web request to the tree answers nothing.
	 */
	public static function baseDir(): string {
		$upload = wp_upload_dir( null, false );

		return rtrim( (string) $upload['basedir'], '/' ) . '/agent-brain/artifacts';
	}

	public static function dirFor( int $artifactId ): string {
		return self::baseDir() . '/' . $artifactId;
	}

	/**
	 * @param array{name:string,mime:string,raw:string,size:int} $file Result of UploadPolicy::normalize().
	 * @return array{id:int,artifact_id:int,name:string,mime:string,size:int,uploaded_by:int,created_at:string,url_path:string}|\WP_Error
	 */
	public static function store( int $artifactId, int $actorId, array $file ) {
		global $wpdb;

		if ( self::countFor( $artifactId ) >= UploadPolicy::limits()['max_per_artifact'] ) {
			return new \WP_Error( 'ab_upload_too_many', __( 'This artifact already holds the maximum number of uploads.', 'agent-brain' ), [ 'status' => 422 ] );
		}

		$ext    = strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$stored = bin2hex( random_bytes( 16 ) ) . ( $ext !== '' ? '.' . $ext : '' );

		$dir = self::dirFor( $artifactId );
		if ( ! self::ensureDir( $dir ) ) {
			return new \WP_Error( 'ab_upload_failed', __( 'Could not store the upload.', 'agent-brain' ), [ 'status' => 500 ] );
		}

		$path = $dir . '/' . $stored;
		if ( file_put_contents( $path, $file['raw'] ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- binary bytes, no WP filesystem API write support needed.
			return new \WP_Error( 'ab_upload_failed', __( 'Could not store the upload.', 'agent-brain' ), [ 'status' => 500 ] );
		}
		chmod( $path, 0600 );

		$now     = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			self::table(),
			[
				'artifact_id' => $artifactId,
				'name'        => substr( $file['name'], 0, 190 ),
				'stored_name'      => $stored,
				'mime'        => substr( $file['mime'], 0, 100 ),
				'size'        => $file['size'],
				'uploaded_by' => $actorId,
				'created_at'  => $now,
			],
			[ '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
		);

		if ( ! $inserted ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- rolling back a file we just wrote.
			unlink( $path );

			return new \WP_Error( 'ab_upload_failed', __( 'Could not store the upload.', 'agent-brain' ), [ 'status' => 500 ] );
		}

		return [
			'id'          => (int) $wpdb->insert_id,
			'artifact_id' => $artifactId,
			'name'        => $file['name'],
			'mime'        => $file['mime'],
			'size'        => $file['size'],
			'uploaded_by' => $actorId,
			'created_at'  => $now,
			'url_path'    => $stored,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find( int $uploadId ): ?array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT * FROM {$table} WHERE id = %d", [ $uploadId ] );

		return $rows[0] ?? null;
	}

	public static function countFor( int $artifactId ): int {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return (int) $wpdb->get_var( Db::prepare( "SELECT COUNT(*) FROM {$table} WHERE artifact_id = %d", [ $artifactId ] ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function listFor( int $artifactId ): array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return Db::getResults( "SELECT * FROM {$table} WHERE artifact_id = %d ORDER BY id ASC", [ $artifactId ] );
	}

	/**
	 * Every upload row of the site, oldest first, for export and Forget.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return Db::getResults( "SELECT * FROM {$table} ORDER BY id ASC" );
	}

	/**
	 * Delete one upload: row and bytes together.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function delete( array $row ): void {
		global $wpdb;

		$id = (int) $row['id'];
		$wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );

		$path = self::dirFor( (int) $row['artifact_id'] ) . '/' . (string) $row['stored_name'];
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- removing an upload's bytes is the feature.
			unlink( $path );
		}
	}

	/**
	 * Deleting an artifact deletes its whole store (rows, bytes, directory).
	 * Called from before_delete_post, so Forget's artifact erasure cascades
	 * here like share-link revocation does in the Sharing module.
	 */
	public static function deleteAllForArtifact( int $artifactId ): void {
		foreach ( self::listFor( $artifactId ) as $row ) {
			self::delete( $row );
		}

		$dir = self::dirFor( $artifactId );
		if ( is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the emptied per-artifact directory.
			rmdir( $dir );
		}
	}

	/** Public for a migration, which puts uploaded files back (Portability\\ExtensionRestore). */
	public static function ensureDir( string $dir ): bool {
		if ( is_dir( $dir ) ) {
			return true;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		self::writeDenyFiles( dirname( self::baseDir() ) );

		return true;
	}

	/**
	 * index.php silence stub + .htaccess deny at the agent-brain/ tree root,
	 * so servers that map the uploads directory into the web root still
	 * answer nothing for the whole tree (the deny applies recursively).
	 */
	private static function writeDenyFiles( string $root ): void {
		$files = [
			$root . '/index.php' => "<?php\n// Silence is golden.\n",
			$root . '/.htaccess' => "Deny from all\n",
		];

		foreach ( $files as $path => $contents ) {
			if ( ! is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny static guard file.
				file_put_contents( $path, $contents );
			}
		}
	}
}
