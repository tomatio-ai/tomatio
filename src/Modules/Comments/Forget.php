<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Comments;

use AgentBrain\Core\Db;

/**
 * Wires ab_comment posts into REV/Forget's forget pipeline through the same
 * contract Artifacts\Forget uses: report matching comments as delete
 * targets on `agent_brain/forget_collect`, hard-delete them on
 * `agent_brain/forget_delete` when asked for our store. Forget owns the
 * audit table and receipts; this class never writes them.
 */
class Forget {

	private const STORE = 'comments_ab_comment';

	/**
	 * @param array<int, array{store:string,id:int|string}> $targets
	 * @return array<int, array{store:string,id:int|string}>
	 */
	public static function collect(array $targets, string $subject): array {
		global $wpdb;

		if (trim($subject) === '') {
			return $targets;
		}

		// One phrase, case-insensitive, in the body or title, every status —
		// identical semantics to Artifacts\Forget (PCP SPEC 8.9.1 item 3:
		// erasure must never treat "Ada Lovelace" as two independent words).
		$like = '%' . $wpdb->esc_like($subject) . '%';

		$rows = Db::getResults(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft' AND ( post_title LIKE %s OR post_content LIKE %s )",
			[Cpt::POST_TYPE, $like, $like]
		);

		foreach ($rows as $row) {
			$targets[] = ['store' => self::STORE, 'id' => (int) $row['ID']];
		}

		// Earlier text of a comment that held the subject (WordPress revisions).
		$rows = Db::getResults(
			"SELECT r.ID FROM {$wpdb->posts} r
				INNER JOIN {$wpdb->posts} p ON p.ID = r.post_parent AND p.post_type = %s
				WHERE r.post_type = 'revision' AND ( r.post_title LIKE %s OR r.post_content LIKE %s )",
			[Cpt::POST_TYPE, $like, $like]
		);

		foreach ($rows as $row) {
			$targets[] = ['store' => self::STORE, 'id' => (int) $row['ID']];
		}

		return $targets;
	}

	/**
	 * @param array<int, int|string> $ids
	 */
	public static function delete(string $store, array $ids): void {
		if ($store !== self::STORE) {
			return;
		}

		foreach ($ids as $id) {
			wp_delete_post((int) $id, true);
		}
	}
}
