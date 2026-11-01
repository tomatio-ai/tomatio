<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Core\Db;

/**
 * Wires artifacts/sections into REV/Forget's forget pipeline (contract:
 * "Artifacts module adds sections/artifacts via this filter"). We never
 * write the audit table or receipts ourselves — Forget owns that — we only
 * (a) report matching posts as delete targets, and (b) delete them when
 * asked for a store we own.
 *
 * Multi-agent pipeline fix: assemble()/compile() write the flattened
 * document as an ordinary `post` (never a new CPT — see ArtifactService's
 * docblocks), identified by carrying `ab_compiled_from` meta. Without this
 * store those assembled posts would be invisible to forget entirely (the
 * artifact/section collect loop above only ever queries the two Artifacts
 * CPTs), which is exactly the privacy gap the contract calls out.
 */
class Forget {

	private const STORE_ARTIFACT  = 'artifacts_ab_artifact';
	private const STORE_SECTION   = 'artifacts_ab_artifact_section';
	private const STORE_ASSEMBLED = 'artifacts_assembled_post';

	/**
	 * @param array<int, array{store:string,id:int|string}> $targets
	 * @return array<int, array{store:string,id:int|string}>
	 */
	public static function collect( array $targets, string $subject ): array {
		global $wpdb;

		if ( trim( $subject ) === '' ) {
			return $targets;
		}

		// The subject as one phrase, case-insensitive, in title or content,
		// in every status (trash and review statuses too). WordPress's own
		// search ("s") would split "Ada Lovelace" into two words that match
		// separately, which erasure must never do (PCP SPEC 8.9.1 item 3).
		$like = '%' . $wpdb->esc_like( $subject ) . '%';

		foreach ( [
			Cpts::ARTIFACT_POST_TYPE => self::STORE_ARTIFACT,
			Cpts::SECTION_POST_TYPE  => self::STORE_SECTION,
		] as $postType => $store ) {
			$rows = Db::getResults(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft' AND ( post_title LIKE %s OR post_content LIKE %s )",
				[ $postType, $like, $like ]
			);

			foreach ( $rows as $row ) {
				$targets[] = [ 'store' => $store, 'id' => (int) $row['ID'] ];
			}
		}

		// Earlier versions of a section or artifact that held the subject.
		$rows = Db::getResults(
			"SELECT r.ID FROM {$wpdb->posts} r
				INNER JOIN {$wpdb->posts} p ON p.ID = r.post_parent AND p.post_type IN (%s, %s)
				WHERE r.post_type = 'revision' AND ( r.post_title LIKE %s OR r.post_content LIKE %s )",
			[ Cpts::ARTIFACT_POST_TYPE, Cpts::SECTION_POST_TYPE, $like, $like ]
		);

		foreach ( $rows as $row ) {
			$targets[] = [ 'store' => self::STORE_SECTION, 'id' => (int) $row['ID'] ];
		}

		$rows = Db::getResults(
			"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE p.post_type = 'post' AND ( p.post_title LIKE %s OR p.post_content LIKE %s )",
			[ Cpts::META_COMPILED_FROM, $like, $like ]
		);

		foreach ( array_unique( array_map( static fn ( array $row ): int => (int) $row['ID'], $rows ) ) as $id ) {
			$targets[] = [ 'store' => self::STORE_ASSEMBLED, 'id' => $id ];
		}

		return $targets;
	}

	/**
	 * @param array<int, int|string> $ids
	 */
	public static function delete( string $store, array $ids ): void {
		if ( ! in_array( $store, [ self::STORE_ARTIFACT, self::STORE_SECTION, self::STORE_ASSEMBLED ], true ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
}
