<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Forget;

use AgentBrain\Core\Db;

/**
 * Data access for `{prefix}ab_forget_receipts` (FGT-3, PCP v0.3 SPEC 8.9.3).
 * Nothing readable about what was erased is stored here: a subject receipt
 * keeps only a keyed digest (`subject_digest`, under the key named by
 * `digest_key_id`, see DigestKey). The unkeyed `subject_hash` column of
 * earlier versions was dropped by the Forget module's migration 2.
 */
final class ReceiptRepository {

	public const STATUS_COMPLETE      = 'complete';
	public const STATUS_PENDING       = 'pending_propagation';
	public const STATUS_RESIDUE_FOUND = 'residue_found';

	public static function tableName(): string {
		global $wpdb;

		return $wpdb->prefix . 'ab_forget_receipts';
	}

	/**
	 * @param array{
	 *   id: string,
	 *   kind?: string,
	 *   subject_digest?: ?string,
	 *   digest_key_id?: ?string,
	 *   include_derived?: bool,
	 *   requested_at?: string,
	 *   created: string,
	 *   curator_id: int,
	 *   counts: array<string, mixed>,
	 *   residue: array<string, mixed>,
	 *   status: string,
	 * } $receipt
	 */
	public static function insert(array $receipt): bool {
		global $wpdb;

		$result = $wpdb->insert(
			self::tableName(),
			[
				'id'              => $receipt['id'],
				'kind'            => $receipt['kind'] ?? 'subject',
				'subject_digest'  => $receipt['subject_digest'] ?? null,
				'digest_key_id'   => $receipt['digest_key_id'] ?? null,
				'include_derived' => ( $receipt['include_derived'] ?? true ) ? 1 : 0,
				'requested_at'    => $receipt['requested_at'] ?? $receipt['created'],
				'created'         => $receipt['created'],
				'curator_id'      => $receipt['curator_id'],
				'counts'          => wp_json_encode( $receipt['counts'] ),
				'residue'         => wp_json_encode( $receipt['residue'] ),
				'status'          => $receipt['status'],
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		return $result !== false;
	}

	/**
	 * Receipts of subject erasures whose keyed digest matches $subject
	 * under the current key (management "verify"). A receipt made before
	 * keyed digests, or under another key, never matches.
	 *
	 * @return array<int, string> Receipt ids, oldest first.
	 */
	public static function matchingSubject( string $subject ): array {
		$rows = Db::getResults(
			'SELECT id FROM ' . self::tableName() . ' WHERE kind = %s AND subject_digest = %s AND digest_key_id = %s ORDER BY created ASC, id ASC',
			[ 'subject', DigestKey::subjectDigest( $subject ), DigestKey::id() ]
		);

		return array_map( static fn ( array $row ): string => (string) $row['id'], $rows );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		$rows = Db::getResults(
			'SELECT * FROM ' . self::tableName() . ' WHERE id = %s LIMIT 1',
			[ $id ]
		);

		if ( ! isset( $rows[0] ) ) {
			return null;
		}

		return self::decode( $rows[0] );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 50 ): array {
		$limit = max( 1, min( 200, $limit ) );

		$rows = Db::getResults(
			'SELECT * FROM ' . self::tableName() . ' ORDER BY created DESC, id DESC LIMIT %d',
			[ $limit ]
		);

		return array_map( [ self::class, 'decode' ], $rows );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		$row['counts']  = json_decode( (string) ( $row['counts'] ?? '[]' ), true ) ?? [];
		$row['residue'] = json_decode( (string) ( $row['residue'] ?? '[]' ), true ) ?? [];
		unset( $row['subject_hash'] );

		return $row;
	}
}
