<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Uploads;

use AgentBrain\Core\Db;

/**
 * ART-27 (#167): uploads in the Forget pipeline. Two paths, both removing
 * rows and bytes together:
 *
 * - cascade: before_delete_post on an artifact removes its whole store, so
 *   Forget's artifact erasure (which deletes the artifact post) reaches the
 *   files exactly like share-link revocation;
 * - subject: `agent_brain/forget_collect` reports upload rows whose original
 *   filename contains the subject, and `agent_brain/forget_delete` removes
 *   those (store `uploads_ab_artifact_upload`).
 */
final class UploadForget {

	public const STORE = 'uploads_ab_artifact_upload';

	/**
	 * @param array<int, array{store:string,id:int|string}> $targets
	 * @return array<int, array{store:string,id:int|string}>
	 */
	public static function collect( array $targets, string $subject ): array {
		global $wpdb;

		if ( trim( $subject ) === '' ) {
			return $targets;
		}

		$like = '%' . $wpdb->esc_like( $subject ) . '%';
		$table = UploadStore::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT id FROM {$table} WHERE name LIKE %s", [ $like ] );

		foreach ( $rows as $row ) {
			$targets[] = [ 'store' => self::STORE, 'id' => (int) $row['id'] ];
		}

		return $targets;
	}

	/**
	 * @param array<int, int|string> $ids
	 */
	public static function delete( string $store, array $ids ): void {
		if ( $store !== self::STORE ) {
			return;
		}

		foreach ( $ids as $id ) {
			$row = UploadStore::find( (int) $id );
			if ( $row !== null ) {
				UploadStore::delete( $row );
			}
		}
	}
}
