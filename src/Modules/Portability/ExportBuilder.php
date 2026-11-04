<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Audit\Cron as AuditCron;
use AgentBrain\Modules\Audit\Repository as AuditRepository;
use AgentBrain\Modules\Forget\Forgetter;
use AgentBrain\Modules\Forget\ReceiptRepository;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * Builds a full PCP v0.3 archive's files in a staging directory, stage by
 * stage: pages and history, extension data (artifacts and handoffs), the
 * audit log, erasure receipts, context.json, profile/team.json, then
 * manifest.json (SPEC sections 8.2, 8.3 and 8.5).
 *
 * Principals are listed last on purpose: writing pages, extension records
 * and the audit log can give a live user the brain names its first
 * principal id, and context.json must list it.
 *
 * Deterministic: every file except manifest.json and its signature depends
 * only on the brain's data, so two exports of an unchanged brain give the
 * same bytes (the audit log grows by each export's own entry, as SPEC 8.8
 * expects). Only the manifest carries exported_at; the signature covers
 * the manifest's bytes.
 */
final class ExportBuilder {

	public const EXPORTER_NAME = 'agent-brain';

	private const BATCH = 500;

	/**
	 * @param string   $stagingDir  Empty private directory to build in.
	 * @param float    $deadline    microtime(true) to fail after; 0 for none.
	 * @param int|null $requestedBy User who asked (null for WP-CLI without --user).
	 * @return array{manifest:array<string, mixed>, files:array<int, array{path:string, sha256:string, bytes:int}>}
	 */
	public static function build(string $stagingDir, float $deadline = 0.0, ?int $requestedBy = null): array {
		$files      = new ArchiveFiles($stagingDir, $deadline);
		$principals = new PrincipalMap();
		$pages      = new PageWriter($files, $principals);

		$memory     = $pages->writeAll();
		// ART-27 (#167): modules contribute extension data (e.g. artifact
		// uploads) with their own manifest entries, merged by id below.
		$extraExtensions = (array) apply_filters('agent_brain/export_extensions', [], $files);
		$extensions = self::mergeExtensions(
			$extraExtensions === [] ? self::writeKeptExtensions($files) : array_merge(self::writeKeptExtensions($files), $extraExtensions),
			( new ExtensionWriter($files, $principals, $pages) )->writeAll()
		);
		$audit      = self::writeAudit($files, $principals);
		$receipts = self::writeReceipts($files, $principals);

		$files->tick();
		$context = ContextWriter::context($pages, $principals);
		$files->write('context.json', ExportFormat::document($context));
		$files->write('profile/team.json', ExportFormat::document(ContextWriter::teamProfile()));

		$manifest = self::manifest(
			$files->entries(),
			$context,
			$memory,
			$extensions,
			$audit,
			$receipts,
			$requestedBy !== null ? $principals->idFor($requestedBy) : null
		);
		file_put_contents($files->root() . '/manifest.json', ExportFormat::document($manifest));
		chmod($files->root() . '/manifest.json', 0600);

		// PROV-8 (#177): sign the manifest (and so, through its file hashes,
		// the whole archive) with the brain's per-brain key. The signature
		// file is archive metadata like the manifest itself: never listed in
		// the manifest's files[].
		$signaturePath = $files->root() . '/' . ManifestSignature::FILE;
		file_put_contents($signaturePath, ExportFormat::document(ManifestSignature::create(hash('sha256', (string) file_get_contents($files->root() . '/manifest.json')))));
		chmod($signaturePath, 0600);

		return ['manifest' => $manifest, 'files' => $files->entries()];
	}

	/**
	 * @param array<int, array{path:string, sha256:string, bytes:int}> $files
	 * @param array<string, mixed>                                     $context
	 * @param array<string, mixed>                                     $memory
	 * @param array<int, array<string, mixed>>                         $extensions
	 * @param array{entries:int, from:?string, to:?string, truncated:bool} $audit
	 * @return array<string, mixed>
	 */
	private static function manifest(array $files, array $context, array $memory, array $extensions, array $audit, int $receipts, ?string $requestedBy): array {
		$version = defined('AGENT_BRAIN_VERSION') ? (string) constant('AGENT_BRAIN_VERSION') : '';

		$manifest = [
			'pcp_version'      => '0.3',
			'manifest_version' => '2',
			'exported_at'      => gmdate('Y-m-d\TH:i:s\Z'),
			'exporter'         => array_filter(['name' => self::EXPORTER_NAME, 'version' => $version], static fn (string $v): bool => $v !== ''),
		];

		if ($requestedBy !== null) {
			$manifest['requested_by'] = $requestedBy;
		}

		$manifest['context'] = [
			'kind'       => 'team',
			'controller' => $context['controller'],
			'discovery'  => home_url('/.well-known/pcp.json'),
			'features'   => $context['features'],
			'path'       => 'context.json',
			'encryption' => ContextWriter::encryption(),
		];
		$manifest['coverage'] = 'full';
		$manifest['profile']  = ['type' => 'team', 'path' => 'profile/team.json'];

		$memoryEntry = [
			'path'                 => 'memory/',
			'page_count'           => $memory['page_count'],
			'sensitive_page_count' => 0,
			'review_counts'        => $memory['review_counts'],
			'partition_counts'     => $memory['partition_counts'] !== [] ? $memory['partition_counts'] : new \stdClass(),
		];
		if ($memory['history'] !== []) {
			$memoryEntry['history'] = $memory['history'];
		}
		$memoryEntry['index_included'] = false;
		$manifest['memory']            = $memoryEntry;

		$manifest['audit'] = array_filter([
			'path'      => 'audit/log.jsonl',
			'entries'   => $audit['entries'],
			'from'      => $audit['from'],
			'to'        => $audit['to'],
			'truncated' => $audit['truncated'],
		], static fn ($v): bool => $v !== null);

		$manifest['erasures'] = ['path' => 'erasures/receipts.jsonl', 'receipts' => $receipts];

		if ($extensions !== []) {
			$manifest['extensions'] = $extensions;
		}

		$manifest['credentials_included'] = false;
		$manifest['files']                = $files;
		$manifest['omitted']              = $memory['omitted'];

		return $manifest;
	}

	/**
	 * One manifest entry per extension id: what a restore kept and what the
	 * brain's own records gave, with their item counts added up.
	 *
	 * @param array<int, array<string, mixed>> $kept
	 * @param array<int, array<string, mixed>> $written
	 * @return array<int, array<string, mixed>>
	 */
	private static function mergeExtensions(array $kept, array $written): array {
		$byId = [];
		foreach (array_merge($kept, $written) as $entry) {
			$id = (string) ($entry['id'] ?? '');
			if (isset($byId[$id])) {
				$byId[$id]['items'] = (int) $byId[$id]['items'] + (int) ($entry['items'] ?? 0);
				continue;
			}
			$byId[$id] = $entry;
		}

		return array_values($byId);
	}

	/**
	 * Extension data a restore kept verbatim (Kept), under its own paths,
	 * with the manifest entries it came with (SPEC 8.5 item 4).
	 *
	 * @return array<int, array<string, mixed>> Manifest extensions entries.
	 */
	private static function writeKeptExtensions(ArchiveFiles $files): array {
		$entries = [];
		Kept::each(Kept::KIND_EXTENSION_ENTRY, static function (string $path, string $json) use (&$entries): void {
			$entry = json_decode($json, true);
			if (is_array($entry)) {
				$entries[$path] = $entry + ['items' => 0];
			}
		});

		$present = [];
		Kept::each(Kept::KIND_EXTENSION, static function (string $path, string $bytes) use ($files, &$present): void {
			$files->write($path, $bytes);
			$files->tick();
			$present[$path] = true;
		});

		// An extension whose files were all erased is no longer listed.
		$out = [];
		foreach ($entries as $prefix => $entry) {
			foreach (array_keys($present) as $path) {
				if (str_starts_with($path, $prefix)) {
					$out[] = $entry;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * audit/log.jsonl: every retained entry, oldest first, mapped by
	 * AuditMapper. truncated once the retention prune has removed entries,
	 * or the brain is older than the retention window (a cut log is
	 * acceptable, an undeclared one is not).
	 *
	 * @return array{entries:int, from:?string, to:?string, truncated:bool}
	 */
	private static function writeAudit(ArchiveFiles $files, PrincipalMap $principals): array {
		$table  = AuditRepository::tableName();
		$lastId = 0;
		$count  = 0;
		$from   = null;
		$to     = null;

		$principal = static fn (int $userId): ?string => $principals->idFor($userId);
		$byFqdn    = static fn (string $fqdn): ?string => $principals->idForFqdn($fqdn);

		$files->begin('audit/log.jsonl');
		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$rows = Db::getResults(
				"SELECT id, ts, actor_id, event, status, object_type, object_id, meta, duration_ms FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
				[$lastId, self::BATCH]
			);
			// phpcs:enable

			$chunk = '';
			foreach ($rows as $row) {
				$lastId = (int) $row['id'];
				if ($row['object_type'] === Restores::RESTORED_AUDIT_TYPE) {
					// Restored from an archive: written back as it came (SPEC 8.8).
					$line  = trim((string) $row['meta']);
					$entry = (array) json_decode($line, true);
					$chunk .= $line . "\n";
				} else {
					$entry  = AuditMapper::entry($row, $principal, $byFqdn);
					$chunk .= ExportFormat::line($entry);
				}
				++$count;

				$at   = (string) ($entry['at'] ?? '');
				$from = $from === null || strcmp($at, $from) < 0 ? $at : $from;
				$to   = $to === null || strcmp($at, $to) > 0 ? $at : $to;
			}
			$files->append('audit/log.jsonl', $chunk);
			$files->tick();
		} while (count($rows) === self::BATCH);
		$files->end('audit/log.jsonl');

		return ['entries' => $count, 'from' => $from, 'to' => $to, 'truncated' => self::auditTruncated()];
	}

	private static function auditTruncated(): bool {
		// A restored log that was already cut stays cut.
		if (get_option(AuditRepository::OPTION_PRUNED_AT, '') !== '' || get_option('ab_pcp_audit_truncated', '') === '1') {
			return true;
		}

		global $wpdb;

		$days   = (int) get_option(AuditCron::OPTION_RETENTION_DAYS, AuditCron::DEFAULT_RETENTION_DAYS);
		$cutoff = gmdate('Y-m-d H:i:s', time() - max(0, $days) * DAY_IN_SECONDS);
		$memory = MemoryRepository::memoryTable();
		$audit  = AuditRepository::tableName();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT MIN(t) AS oldest FROM (
				SELECT MIN(created) AS t FROM {$memory}
				UNION ALL SELECT MIN(post_date_gmt) FROM {$wpdb->posts} WHERE post_type IN (%s, %s) AND post_date_gmt > %s
				UNION ALL SELECT MIN(ts) FROM {$audit}
			) oldest_rows",
			['ab_knowledge', 'ab_procedure', '0000-00-00 00:00:00']
		);
		// phpcs:enable

		$oldest = isset($rows[0]['oldest']) ? (string) $rows[0]['oldest'] : '';

		return $oldest !== '' && strcmp($oldest, $cutoff) < 0;
	}

	/**
	 * erasures/receipts.jsonl: every erasure receipt, oldest first, with the
	 * ids of its erase.request and erase.complete audit entries (or the
	 * forget.requested and forget.completed ones of earlier versions).
	 */
	private static function writeReceipts(ArchiveFiles $files, PrincipalMap $principals): int {
		$table = ReceiptRepository::tableName();
		$audit = AuditRepository::tableName();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, no values.
		$rows = Db::getResults("SELECT id, kind, subject_digest, digest_key_id, include_derived, requested_at, created, curator_id, counts, residue, status FROM {$table} ORDER BY created ASC, id ASC");
		// phpcs:enable

		$controller = Controller::ensure();
		$principal  = static fn (int $userId): ?string => $principals->idFor($userId);
		$out        = '';

		// Receipts restored from an archive come first, as they came.
		$kept = Kept::each(Kept::KIND_RECEIPT, static function (string $path, string $line) use (&$out): void {
			unset($path);
			$out .= $line . "\n";
		});

		foreach ($rows as $row) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$auditRows = Db::getResults(
				"SELECT id FROM {$audit} WHERE object_type = %s AND object_id = %s AND event IN (%s, %s, %s, %s) ORDER BY id ASC",
				array_merge(['ab_forget_receipt', (string) $row['id'], Forgetter::EVENT_REQUEST, Forgetter::EVENT_COMPLETE], Forgetter::LEGACY_EVENTS)
			);
			// phpcs:enable
			$auditIds = array_map(static fn (array $r): string => 'ab-audit-' . (int) $r['id'], $auditRows);

			$out .= ExportFormat::line(ReceiptMapper::receipt($row, $principal, $controller, $auditIds));
			$files->tick();
		}

		$files->write('erasures/receipts.jsonl', $out);

		return $kept + count($rows);
	}
}
