<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Forget;

use AgentBrain\Core\Db;

/**
 * Erasure tombstones (PCP v0.3 SPEC 8.9.5): with each receipt, keyed
 * digests (DigestKey) of every erased page's id, path and body, in
 * `{prefix}ab_erasure_tombstones`. They let an import of an archive made
 * before the erasure skip the erased pages (`skipped_erased`) without the
 * brain keeping anything readable about them.
 *
 * Not exported: their key is not, so they would mean nothing elsewhere.
 * The page file itself is not digested, because its frontmatter (review
 * state, updated) changes from export to export while the body is the
 * stored bytes exactly (SPEC 8.8), so the body is the stronger match.
 *
 * A page counts as erased when its id or its body matches (matches()).
 * A path alone does not: a new page may reuse the path of an erased one.
 */
final class Tombstones {

	public const ID   = 'page.id';
	public const PATH = 'page.path';
	public const BODY = 'page.body';

	public static function tableName(): string {
		global $wpdb;

		return $wpdb->prefix . 'ab_erasure_tombstones';
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table = self::tableName();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			receipt_id VARCHAR(36) NOT NULL,
			kind VARCHAR(16) NOT NULL,
			digest CHAR(64) NOT NULL,
			key_id VARCHAR(32) NOT NULL,
			PRIMARY KEY  (id),
			KEY receipt_id (receipt_id),
			KEY digest (digest)
		) {$wpdb->get_charset_collate()};";
	}

	/**
	 * Records the tombstones of a receipt's erased pages.
	 *
	 * @param array<int, array{id?: ?string, path?: ?string, body?: ?string}> $pages
	 * @return int Rows written.
	 */
	public static function record(string $receiptId, array $pages): int {
		global $wpdb;

		$keyId   = DigestKey::id();
		$written = 0;
		$seen    = [];

		foreach ($pages as $page) {
			foreach ([self::ID => 'id', self::PATH => 'path', self::BODY => 'body'] as $kind => $field) {
				$value = $page[$field] ?? null;
				if (! is_string($value) || $value === '') {
					continue;
				}

				$digest = DigestKey::digest($kind, $value);
				if (isset($seen[$kind . $digest])) {
					continue;
				}
				$seen[$kind . $digest] = true;

				$ok = $wpdb->insert(
					self::tableName(),
					['receipt_id' => $receiptId, 'kind' => $kind, 'digest' => $digest, 'key_id' => $keyId],
					['%s', '%s', '%s', '%s']
				);
				$written += $ok === false ? 0 : 1;
			}
		}

		return $written;
	}

	/**
	 * Which of a page's parts match a tombstone under the current key:
	 * a subset of page.id, page.path and page.body.
	 *
	 * @return array<int, string>
	 */
	public static function matches(?string $id, ?string $path, ?string $body): array {
		$keyId = DigestKey::id();
		$found = [];

		foreach ([self::ID => $id, self::PATH => $path, self::BODY => $body] as $kind => $value) {
			if ($value === null || $value === '') {
				continue;
			}

			$rows = Db::getResults(
				'SELECT 1 AS hit FROM ' . self::tableName() . ' WHERE kind = %s AND digest = %s AND key_id = %s LIMIT 1',
				[$kind, DigestKey::digest($kind, $value), $keyId]
			);
			if ($rows !== []) {
				$found[] = $kind;
			}
		}

		return $found;
	}

	/**
	 * Whether import mode must skip this page as erased: its id or its
	 * body matches a tombstone.
	 */
	public static function isErased(?string $id, ?string $path, ?string $body): bool {
		return array_intersect(self::matches($id, $path, $body), [self::ID, self::BODY]) !== [];
	}

	public static function countFor(string $receiptId): int {
		$rows = Db::getResults(
			'SELECT COUNT(*) AS n FROM ' . self::tableName() . ' WHERE receipt_id = %s',
			[$receiptId]
		);

		return (int) ($rows[0]['n'] ?? 0);
	}
}
