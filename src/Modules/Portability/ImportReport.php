<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * The report of a restore or an import (PCP v0.3 SPEC section 8.7.4,
 * export.manifest.schema.json#/$defs/import_report): mode, status, the
 * archive's exporter, exported_at and manifest hash, counts per outcome and
 * per item outcomes with paths. It goes only to the requester, kept for a
 * day for GET /manage/imports/{id}; the audit entry carries counts only.
 */
final class ImportReport {

	public const OUTCOMES = ['created', 'revised', 'renamed', 'replaced', 'skipped_duplicate', 'skipped_conflict', 'skipped_review_state', 'skipped_expired', 'skipped_erased', 'invalid'];

	private const TRANSIENT = 'ab_pcp_import_';

	private const MAX_ITEMS = 10000;

	/** @var array<string, int> */
	private array $counts = [];

	/** @var array<int, array<string, string>> */
	private array $items = [];

	private string $startedAt;

	public function __construct(private string $id, private string $mode, private bool $dryRun) {
		$this->startedAt = gmdate('Y-m-d\TH:i:s\Z');
	}

	public function id(): string {
		return $this->id;
	}

	public function item(string $source, string $outcome, ?string $path = null, ?string $message = null): void {
		$this->count($outcome);

		if (count($this->items) >= self::MAX_ITEMS) {
			return;
		}

		$item = ['source' => substr($source, 0, 512)];
		if ($path !== null && Names::isPath($path)) {
			$item['path'] = $path;
		}
		$item['outcome'] = $outcome;
		if ($message !== null && $message !== '') {
			$item['message'] = ExportFormat::clip($message, 1000);
		}
		$this->items[] = $item;
	}

	public function count(string $key, int $by = 1): void {
		$this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
	}

	/** @return array<string, int> */
	public function counts(): array {
		return $this->counts;
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	public function finish(string $status, array $manifest, string $manifestSha256, ?string $rejectedReason = null): array {
		$archive = [];
		if (is_string($manifest['exporter']['name'] ?? null)) {
			$archive['exporter'] = substr(trim($manifest['exporter']['name'] . ' ' . (string) ($manifest['exporter']['version'] ?? '')), 0, 100);
		}
		if (is_string($manifest['exported_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?Z$/', $manifest['exported_at']) === 1) {
			$archive['exported_at'] = $manifest['exported_at'];
		}
		if (preg_match('/^[a-f0-9]{64}$/', $manifestSha256) === 1) {
			$archive['manifest_sha256'] = $manifestSha256;
		}

		$counts = [];
		foreach (array_merge(self::OUTCOMES, ['extensions_kept', 'extensions_not_imported']) as $key) {
			if (($this->counts[$key] ?? 0) > 0) {
				$counts[$key] = $this->counts[$key];
			}
		}

		$report = [
			'id'      => $this->id,
			'mode'    => $this->mode,
			'dry_run' => $this->dryRun,
			'status'  => $status,
		];
		if ($rejectedReason !== null) {
			$report['rejected_reason'] = ExportFormat::clip($rejectedReason, 1000);
		}
		$report['format'] = 'pcp';
		if ($archive !== []) {
			$report['archive'] = $archive;
		}
		$report['started_at']   = $this->startedAt;
		$report['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
		$report['counts']       = $counts !== [] ? $counts : new \stdClass();
		if ($status !== 'rejected') {
			$report['items'] = $this->items;
		}

		return $report;
	}

	/**
	 * @param array<string, mixed> $report
	 */
	public static function keep(array $report, int $userId): void {
		set_transient(self::TRANSIENT . $report['id'], ['user' => $userId, 'report' => $report], DAY_IN_SECONDS);
	}

	/**
	 * A kept report, for the user who asked for it only.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get(string $id, int $userId): ?array {
		if (preg_match('/^[a-f0-9-]{36}$/', $id) !== 1) {
			return null;
		}

		$kept = get_transient(self::TRANSIENT . $id);

		return is_array($kept) && (int) ($kept['user'] ?? -1) === $userId && is_array($kept['report'] ?? null) ? $kept['report'] : null;
	}
}
