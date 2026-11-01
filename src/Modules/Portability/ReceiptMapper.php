<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Maps an ab_forget_receipts row to a PCP erasure receipt (PCP v0.3 SPEC
 * section 8.9.3, schemas/0.3/erasure.receipt.schema.json). Pure.
 *
 * A subject receipt carries subject_digest, the keyed HMAC-SHA-256 of the
 * normalized subject (Forget\DigestKey) with the id of the key, which is
 * never exported. Receipts made before keyed digests carry none, as the
 * schema allows (their unkeyed hash was cleared, decision 5). Counts and
 * residue keep their per-store numbers; the propagation results that
 * residue holds become propagation entries, a store that deletes later
 * ({pending: true, due_by}) a pending one.
 */
final class ReceiptMapper {

	private const STORE_PATTERN = '/^[a-z][a-z0-9_.:-]{0,63}$/';

	/**
	 * @param array<string, mixed>   $row       id, kind, subject_digest, digest_key_id, include_derived, requested_at, created, curator_id, counts, residue, status (JSON strings or arrays).
	 * @param callable(int): ?string $principal User id to principal id.
	 * @param string                 $fallback  Requester when the curator has no principal id (the controller).
	 * @param array<int, string>     $auditIds  Ids of the forget audit entries.
	 * @return array<string, mixed>
	 */
	public static function receipt(array $row, callable $principal, string $fallback, array $auditIds = []): array {
		$at        = ExportFormat::utc((string) ($row['created'] ?? '')) ?? '1970-01-01T00:00:00Z';
		$requested = ExportFormat::utc((string) ($row['requested_at'] ?? '')) ?? $at;
		$counts    = self::decode($row['counts'] ?? null);
		$residue   = self::decode($row['residue'] ?? null);

		$propagation = [];
		foreach ((array) ($residue['propagation'] ?? []) as $result) {
			if (is_array($result)) {
				$propagation[] = self::propagation($result);
			}
		}

		$residueCounts = self::storeCounts($residue);
		$status        = (string) ($row['status'] ?? '');
		if (! in_array($status, ['complete', 'pending_propagation', 'residue_found'], true)) {
			$status = 'residue_found';
		}
		if ($status !== 'residue_found' && (array_filter($residueCounts) !== [] || in_array('failed', array_column($propagation, 'status'), true))) {
			$status = 'residue_found';
		}
		if ($status === 'complete' && in_array('pending', array_column($propagation, 'status'), true)) {
			$status = 'pending_propagation';
		}

		$kind = (string) ($row['kind'] ?? 'subject');
		if (! in_array($kind, ['subject', 'pages', 'principal', 'context'], true)) {
			$kind = 'subject';
		}

		$curator = (int) ($row['curator_id'] ?? 0);

		$receipt = [
			'id'           => (string) ($row['id'] ?? ''),
			'kind'         => $kind,
			'requested_by' => ($curator > 0 ? $principal($curator) : null) ?? $fallback,
			'requested_at' => strcmp($requested, $at) <= 0 ? $requested : $at,
			'completed_at' => $at,
		];

		$digest = (string) ($row['subject_digest'] ?? '');
		$keyId  = (string) ($row['digest_key_id'] ?? '');
		if ($kind === 'subject' && preg_match('/^[a-f0-9]{64}$/', $digest) === 1 && $keyId !== '' && strlen($keyId) <= 128) {
			$receipt['subject_digest'] = ['alg' => 'hmac-sha256', 'key_id' => $keyId, 'value' => $digest];
		}

		if (array_key_exists('include_derived', $row) && $row['include_derived'] !== null) {
			$receipt['include_derived'] = (bool) (int) $row['include_derived'];
		}

		$receipt['counts']  = self::storeCounts($counts) ?: new \stdClass();
		$receipt['residue'] = $residueCounts ?: new \stdClass();

		if ($propagation !== []) {
			$receipt['propagation'] = $propagation;
		}

		$receipt['status'] = $status;

		if ($auditIds !== []) {
			$receipt['audit_ids'] = array_values($auditIds);
		}

		return $receipt;
	}

	/**
	 * Whole, non-negative numbers per store, with store names the schema
	 * allows, sorted.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, int>
	 */
	public static function storeCounts(array $values): array {
		$out = [];
		foreach ($values as $store => $value) {
			$store = strtolower((string) $store);
			if (is_int($value) && $value >= 0 && preg_match(self::STORE_PATTERN, $store) === 1) {
				$out[$store] = $value;
			}
		}
		ksort($out, SORT_STRING);

		return $out;
	}

	/**
	 * @param array<string, mixed> $result {store?, success?, reason?, skipped?, pending?, due_by?, method?}
	 * @return array<string, string>
	 */
	private static function propagation(array $result): array {
		$store = strtolower((string) ($result['store'] ?? 'backup'));
		if (preg_match(self::STORE_PATTERN, $store) !== 1) {
			$store = 'backup';
		}

		$failed = (array_key_exists('success', $result) && $result['success'] === false)
			|| (! array_key_exists('success', $result) && ! empty($result['reason']));
		$dueBy   = isset($result['due_by']) && is_string($result['due_by']) ? ExportFormat::utc($result['due_by']) : null;
		$pending = ! $failed && ! empty($result['pending']);

		$entry = [
			'store'  => $store,
			'status' => $failed ? 'failed' : ($pending ? 'pending' : 'ok'),
		];

		if (! $failed && ! $pending) {
			$entry['method'] = isset($result['method']) && $result['method'] === 'shredded' ? 'shredded' : 'deleted';
		}

		if ($pending && $dueBy !== null) {
			$entry['due_by'] = $dueBy;
		}

		foreach (['reason', 'skipped'] as $key) {
			if (isset($result[$key]) && is_string($result[$key]) && preg_match('/^[a-z0-9_]{1,64}$/', $result[$key]) === 1) {
				$entry['detail'] = $result[$key];
				break;
			}
		}

		return $entry;
	}

	/**
	 * @param mixed $value
	 * @return array<string, mixed>
	 */
	private static function decode($value): array {
		if (is_string($value)) {
			$value = json_decode($value, true);
		}

		return is_array($value) ? $value : [];
	}
}
