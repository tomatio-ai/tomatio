<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;

/**
 * Import mode (PCP v0.3 SPEC section 8.7.3): the pages of a full or partial
 * archive join this brain as proposals in the Review Queue. Used by the
 * Export and import page, the management REST routes and WP-CLI.
 *
 * Who: an administrator, or a person with write (propose) access to every
 * target scope. Not agents: the management routes take the browser login
 * only, and WP-CLI needs --user.
 *
 * Before anything is written: safe unpacking and every SPEC 8.3 check (as
 * for restore), and the manifest signature (PROV-8): an archive whose
 * signature does not verify — the manifest or a file was altered after
 * export, or another brain signed it — is rejected outright, and an
 * unsigned archive proceeds with a note that its origin cannot be verified;
 * either way every imported page keeps the result in its provenance
 * (x_agent_brain.import_signature). Then the options (on_conflict skip, rename or replace; revise,
 * the spec's default with review, needs pending revisions, which Agent
 * Brain does not have yet, so it is refused); the partition map (every
 * target scope must exist here: import never creates scopes or changes
 * access) and the requester's rights on each; the principal map (targets
 * must be principals here); and the warnings of SPEC 8.4 and 9.2.3
 * (IMP-06): an archive from another controller, or readable by more than
 * principals and the host, is only imported with confirm.
 *
 * Then every page goes through ImportWriter in one transaction, and one
 * import.merge audit entry records the counts, never paths. Extension data
 * is reported as extensions_not_imported (SPEC 8.5 item 4); history,
 * revisions, the audit log and receipts belong to the source and are not
 * imported (SPEC 8.7.3 item 3).
 */
final class Imports {

	public const AUDIT_EVENT = 'import.merge';

	public const ON_CONFLICT = ['rename', 'skip', 'replace'];

	/**
	 * #594: how imported pages land. `proposals` (the default): every page
	 * waits in the Review Queue. `reference`: the controller (an
	 * administrator) approves the import once and its knowledge pages land
	 * as dated reference (lifecycle stale), searchable without a review per
	 * page (Review\Lifecycle).
	 */
	public const AS = ['proposals', 'reference'];

	/** The client recorded as source.client when none is named. */
	public const DEFAULT_CLIENT = 'agent-brain-import';

	/**
	 * @param array<string, mixed> $options dry_run, on_conflict, partition, partition_map, principal_map, approve_all, as, confirm, client.
	 * @return array{report:array<string, mixed>, errors:array<int, string>, notes:array<int, string>}
	 * @throws ImportFailed When the requester may not import at all.
	 */
	public function run(string $archive, int $userId, array $options = []): array {
		if ($userId <= 0 || get_userdata($userId) === false) {
			throw new ImportFailed('forbidden', 'Import as a person: sign in, or give WP-CLI --user.');
		}
		if (Binding::isUnbound($userId)) {
			throw new ImportFailed('forbidden', 'This account is not bound yet.');
		}

		$dryRun = ! empty($options['dry_run']);
		$report = new ImportReport(wp_generate_uuid4(), 'import', $dryRun);
		$store  = new ExportStore();
		$work   = $store->stagingDir($store->streamOnly());
		$notes  = [];

		try {
			try {
				$present = ArchiveReader::unpack($archive, $work . '/archive');
			} catch (ImportFailed $e) {
				return $this->rejected($report, [], '', [$e->getMessage()], $userId, $dryRun);
			}

			$check  = ArchiveCheck::run($work . '/archive', $present);
			$errors = $check['errors'];

			// PROV-8 (#177): a signed manifest whose signature does not verify
			// is rejected here, before anything is written; an unsigned
			// archive proceeds with a note. Imported pages keep the result in
			// their provenance (x_agent_brain.import_signature).
			[$signatureError, $signatureNote, $signatureProvenance] = ManifestSignature::assess($check['manifest_sha256'], $check['signature']['doc'] ?? null);
			if ($signatureError !== null) {
				$errors[] = $signatureError;
			} elseif ($signatureNote !== null) {
				$notes[] = $signatureNote;
			}

			$plan   = [];
			if ($errors === []) {
				[$plan, $errors, $warnings] = self::plan($check, $userId, $options, $signatureProvenance);
				$notes = array_merge($notes, $warnings);
				if ($errors === [] && $plan['as'] === 'reference') {
					$notes[] = 'Imported as reference: the pages the source had approved are approved once by the controller, and knowledge pages that name no lifecycle land as dated reference (stale). Search finds them after current knowledge; mark one as current when it still holds.';
				}
				if ($errors === [] && $warnings !== [] && ! $dryRun && empty($options['confirm'])) {
					$errors[] = 'This archive needs your confirmation: ' . implode(' ', $warnings) . ' Run a dry run to review it, then import with confirm.';
				}
			}
			if ($errors !== []) {
				return $this->rejected($report, $check['manifest'], $check['manifest_sha256'], $errors, $userId, $dryRun);
			}

			if ($check['extension_files'] !== []) {
				$report->count('extensions_not_imported', count($check['extensions']));
				$notes[] = sprintf('%d extensions in the archive are not imported: this version imports pages only.', count($check['extensions']));
			}
			$versions = $check['revisions'] + array_sum(array_map('count', $check['history']));
			if ($versions > 0) {
				$notes[] = sprintf('%d earlier versions and revisions belong to the source and are not imported.', $versions);
			}

			$writer  = new ImportWriter($work . '/archive', $plan);
			$started = microtime(true);
			$pages   = $check['pages'];
			ksort($pages, SORT_STRING);

			$apply = static function () use ($writer, $pages, $report, $dryRun): void {
				foreach ($pages as $path => $page) {
					$result = $writer->import((string) $path, $page, $dryRun);
					$report->item($page['file'], $result['outcome'], $result['path'], $result['message']);
				}
			};

			if ($dryRun) {
				$apply();
			} else {
				try {
					Transaction::run($apply);
				} catch (\Throwable $e) {
					$message = $e instanceof ImportFailed ? $e->getMessage() : 'The import failed part way and was undone; nothing was imported.';

					return $this->rejected(new ImportReport($report->id(), 'import', false), $check['manifest'], $check['manifest_sha256'], [$message], $userId, false);
				}

				wp_cache_flush();
				foreach ($writer->createdPosts() as $postId) {
					clean_post_cache($postId);
				}
				if ($writer->createdPosts() !== []) {
					foreach (array_unique(array_values($plan['partitions'])) as $termId) {
						$term = get_term((int) $termId, Scopes::TAXONOMY);
						if ($term instanceof \WP_Term) {
							wp_update_term_count_now([(int) $term->term_taxonomy_id], Scopes::TAXONOMY);
						}
					}
				}

				$counts  = $report->counts();
				$written = ($counts['created'] ?? 0) + ($counts['renamed'] ?? 0) + ($counts['replaced'] ?? 0);
				self::audit($userId, $report->id(), 'ok', [
					'matched' => count($pages),
					'written' => $written,
				] + (! empty($plan['approve_all']) || $plan['as'] === 'reference' ? ['approved_by_controller' => 1] : []) + ($plan['as'] === 'reference' ? ['as_reference' => 1] : []), $started);
			}

			$status = ($report->counts()['invalid'] ?? 0) > 0 ? 'partial' : 'complete';
			$result = $report->finish($status, $check['manifest'], $check['manifest_sha256']);
			ImportReport::keep($result, $userId);

			return ['report' => $result, 'errors' => [], 'notes' => $notes];
		} finally {
			ExportStore::removeTree($work);
		}
	}

	/**
	 * Everything ImportWriter needs, or why the import cannot run, and the
	 * warnings that need a confirm.
	 *
	 * @param array<string, mixed> $check
	 * @param array<string, mixed> $options
	 * @param array<string, mixed>|null $signature The manifest-signature
	 *   provenance from ManifestSignature::assess(); computed from $check
	 *   when not given.
	 * @return array{0:array<string, mixed>, 1:array<int, string>, 2:array<int, string>}
	 */
	public static function plan(array $check, int $userId, array $options, ?array $signature = null): array {
		$errors   = [];
		$warnings = [];
		$manifest = $check['manifest'];
		$admin    = Exports::canExport($userId);
		$signature ??= ManifestSignature::assess($check['manifest_sha256'], $check['signature']['doc'] ?? null)[2];

		$onConflict = (string) ($options['on_conflict'] ?? 'rename');
		if ($onConflict === 'revise') {
			$errors[] = 'on_conflict "revise" needs pending revisions of existing pages, which TomaTío does not support yet. Use rename, skip or replace.';
		} elseif (! in_array($onConflict, self::ON_CONFLICT, true)) {
			$errors[] = 'on_conflict must be rename, skip or replace.';
		}

		if (! empty($options['approve_all']) && ! $admin) {
			$errors[] = 'Only an administrator, acting for the controller, can approve an import at once.';
		}
		$as = (string) ($options['as'] ?? 'proposals');
		if (! in_array($as, self::AS, true)) {
			$errors[] = 'as must be proposals or reference.';
		} elseif ($as === 'reference' && ! $admin) {
			$errors[] = 'Only an administrator, acting for the controller, can import as reference.';
		}

		// Scopes here, by partition name.
		$scopes = [];
		foreach (Partitions::all() as $row) {
			if ($row['name'] !== null) {
				$scopes[$row['name']] = $row['term_id'];
			}
		}
		// #550: moving scopes creates these in the same transaction (Moves).
		$new = array_map('strval', (array) ($options['new_scopes'] ?? []));
		foreach ($new as $name) {
			$scopes[$name] ??= 0;
		}

		$map     = is_array($options['partition_map'] ?? null) ? $options['partition_map'] : [];
		$default = (string) ($options['partition'] ?? Partitions::DEFAULT_NAME);
		$targets = [];
		foreach ($check['pages'] as $page) {
			$source           = is_string($page['frontmatter']['partition'] ?? null) ? $page['frontmatter']['partition'] : Partitions::DEFAULT_NAME;
			$target           = isset($map[$source]) && is_string($map[$source]) ? $map[$source] : $default;
			$targets[$target] = true;
		}

		$partitions = [];
		foreach (array_keys($targets) as $target) {
			if (! isset($scopes[$target])) {
				$errors[] = sprintf('There is no scope named "%s" on this brain. Import never creates scopes: map the archive\'s partitions to scopes that exist (partition_map), or choose a target scope (partition).', $target);
				continue;
			}
			$partitions[$target] = $scopes[$target];
		}

		$readable = self::scopeIds($userId, Scopes::META_READ_SCOPES);
		$writable = self::scopeIds($userId, Scopes::META_WRITE_SCOPES);
		$seesAll  = $admin || user_can($userId, Capabilities::CAP_APPROVE_KNOWLEDGE);
		foreach ($partitions as $name => $termId) {
			if (! $seesAll && ! in_array($termId, $writable, true) && ! in_array($name, $new, true)) {
				$errors[] = sprintf('You cannot write to the scope "%s", so nothing can be imported into it.', $name);
			}
		}

		// Principals: each source id to a principal of this brain.
		$principalMap = [];
		$principalIds = [];
		$importerId   = Principals::ensure($userId, true);
		if ($importerId === null) {
			$errors[] = 'Your account has no principal id.';
		}
		$principalIds[$userId] = (string) $importerId;
		foreach ((array) ($options['principal_map'] ?? []) as $from => $to) {
			$target = is_string($to) ? Binding::userFor($to) : null;
			if ($target === null) {
				$errors[] = sprintf('The principal map names %s, which is not a principal of this brain.', is_string($to) ? $to : 'a value');
				continue;
			}
			$principalMap[(string) $from] = $target;
			$principalIds[$target]        = (string) $to;
		}

		// IMP-06: another controller, or wider readability, needs a confirm.
		$controller = Controller::ensure();
		$readableBy = (array) ($manifest['context']['encryption']['readable_by'] ?? []);
		if (array_diff($readableBy, ['principals', 'host']) !== []) {
			$warnings[] = sprintf('The archive comes from a context readable by %s; here it will be readable by its principals and this host.', implode(', ', array_map('strval', $readableBy)));
		}
		if (($manifest['context']['controller'] ?? null) !== $controller) {
			$warnings[] = sprintf('The archive comes from another controller (%s); its pages will be governed by this brain\'s controller (%s).', (string) ($manifest['context']['controller'] ?? 'unknown'), $controller);
		}

		$exporter = trim((string) ($manifest['exporter']['name'] ?? '') . ' ' . (string) ($manifest['exporter']['version'] ?? ''));
		$client   = trim((string) ($options['client'] ?? ''));

		$plan = [
			'importer'           => $userId,
			'importer_principal' => (string) $importerId,
			'principal_ids'      => $principalIds,
			'principal_map'      => $principalMap,
			'partition_map'      => array_filter($map, 'is_string'),
			'default_partition'  => $default,
			'partitions'         => $partitions,
			'readable'           => array_values(array_unique(array_merge($readable, $writable))),
			'writable'           => $writable,
			'sees_all'           => $seesAll,
			'on_conflict'        => $onConflict,
			'approve_all'        => ! empty($options['approve_all']) && $admin,
			'as'                 => $as,
			'controller'         => $controller,
			'client'             => $client !== '' ? substr($client, 0, 512) : self::DEFAULT_CLIENT,
			'exporter'           => substr($exporter !== '' ? $exporter : 'unknown', 0, 100),
			'source_brain'       => substr((string) ($manifest['context']['controller'] ?? ''), 0, 255),
			'source_context'     => substr((string) ($manifest['context']['discovery'] ?? $manifest['context']['controller'] ?? ''), 0, 512),
			'source_signature'   => $signature,
			'now'                => gmdate('Y-m-d\TH:i:s\Z'),
			'now_ts'             => time(),
		];

		return [$plan, $errors, $warnings];
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @param array<int, string>   $errors
	 * @return array{report:array<string, mixed>, errors:array<int, string>, notes:array<int, string>}
	 */
	private function rejected(ImportReport $report, array $manifest, string $sha, array $errors, int $userId, bool $dryRun): array {
		$result = $report->finish('rejected', $manifest, $sha, implode(' ', array_slice($errors, 0, 5)));
		ImportReport::keep($result, $userId);

		if (! $dryRun) {
			self::audit($userId, $report->id(), 'error', [], microtime(true));
		}

		return ['report' => $result, 'errors' => $errors, 'notes' => []];
	}

	/**
	 * @return array<int, int>
	 */
	private static function scopeIds(int $userId, string $key): array {
		$raw = get_user_meta($userId, $key, true);

		return is_array($raw) ? array_values(array_map('intval', $raw)) : [];
	}

	/**
	 * @param array<string, int> $counts
	 */
	private static function audit(int $userId, string $id, string $status, array $counts, float $started): void {
		do_action('agent_brain/audit', [
			'actor_id'    => $userId,
			'event'       => self::AUDIT_EVENT,
			'status'      => $status,
			'object_type' => 'import',
			'object_id'   => $id,
			'meta'        => $counts,
			'duration_ms' => (int) round((microtime(true) - $started) * 1000),
		]);
	}
}
