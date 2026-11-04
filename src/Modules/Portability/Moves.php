<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\DataStore\StoreRestore;

/**
 * #550: move chosen scopes of an archive into this brain while it is in
 * use. An import (PCP SPEC 8.7.3) that brings the scopes themselves, with
 * their people, artifacts and history:
 *
 *  - each chosen partition becomes a scope here: the scope it is mapped to
 *    (scope_map), the scope here with the same partition name, or a new
 *    scope with that name and the archive's access list;
 *  - each person and agent of the archive is the principal here it is
 *    mapped to (principal_map), the principal here with the same id, or a
 *    new unbound account (Binding), to invite or give a key afterwards;
 *  - pages go through ImportWriter as for an import: marked as imported
 *    (x_import, the source brain, the signature result), idempotent, and
 *    proposals, unless the controller keeps what the source had approved
 *    (keep_approved, administrators). Knowledge and procedures bring their
 *    history as revisions;
 *  - artifacts with their sections, comments, compiled documents, HTML
 *    versions, version history, uploads, page store data and seats, and the
 *    handoffs, under new ids with every reference between them rewritten
 *    (ExtensionRestore and StoreRestore in move mode). Each remembers where
 *    it came from, so a second move of the same archive skips it. Unless
 *    approvals are kept, approved sections wait for review again and no
 *    HTML version is approved here. Share links are never made live: their
 *    records wait to be created again.
 *
 * Administrators only: a move creates scopes and accounts. Every check and
 * a dry run come first, and everything is written in one transaction.
 */
final class Moves {

	public const AUDIT_EVENT = 'import.move';

	/**
	 * @param array<string, mixed> $options dry_run, scopes, scope_map, principal_map, keep_approved, on_conflict, confirm, client.
	 * @return array{report:array<string, mixed>, errors:array<int, string>, notes:array<int, string>}
	 * @throws ImportFailed When the requester may not move scopes at all.
	 */
	public function run(string $archive, int $userId, array $options = []): array {
		if ($userId <= 0 || ! Exports::canExport($userId) || Binding::isUnbound($userId)) {
			throw new ImportFailed('forbidden', 'Only an administrator can move scopes into this brain: it creates scopes and accounts.');
		}

		$dryRun = ! empty($options['dry_run']);
		$report = new ImportReport(wp_generate_uuid4(), 'import', $dryRun);
		$store  = new ExportStore();
		$work   = $store->stagingDir($store->streamOnly());
		$dir    = $work . '/archive';
		$notes  = [];

		try {
			try {
				$present = ArchiveReader::unpack($archive, $dir);
			} catch (ImportFailed $e) {
				return $this->rejected($report, [], '', [$e->getMessage()], $userId, $dryRun);
			}

			$check  = ArchiveCheck::run($dir, $present);
			$errors = $check['errors'];
			[$signatureError, $signatureNote, $signatureProvenance] = ManifestSignature::assess($check['manifest_sha256'], $check['signature']['doc'] ?? null);
			if ($signatureError !== null) {
				$errors[] = $signatureError;
			} elseif ($signatureNote !== null) {
				$notes[] = $signatureNote;
			}
			if ($errors !== []) {
				return $this->rejected($report, $check['manifest'], $check['manifest_sha256'], $errors, $userId, $dryRun);
			}

			[$selection, $errors, $chosen] = Selection::only($options['scopes'] ?? [], $check['context']);
			[$targets, $create, $scopeErrors] = $errors === [] ? self::targets($chosen, $options) : [[], [], []];
			$errors = array_merge($errors, $scopeErrors);
			if ($errors !== []) {
				return $this->rejected($report, $check['manifest'], $check['manifest_sha256'], $errors, $userId, $dryRun);
			}

			$moved = $selection->apply($check);
			$keep  = ! empty($options['keep_approved']);

			[$plan, $errors, $warnings] = Imports::plan($moved, $userId, [
				'partition_map' => $targets,
				'partition'     => (string) reset($targets),
				'on_conflict'   => (string) ($options['on_conflict'] ?? 'rename'),
				'approve_all'   => $keep,
				'new_scopes'    => array_values($create),
				'client'        => (string) ($options['client'] ?? ''),
			], $signatureProvenance);
			$notes = array_merge($notes, $warnings);
			if ($errors === [] && $warnings !== [] && ! $dryRun && empty($options['confirm'])) {
				$errors[] = 'This archive needs your confirmation: ' . implode(' ', $warnings) . ' Run a dry run to review it, then move with confirm.';
			}

			$source    = (string) ($check['manifest']['context']['controller'] ?? '');
			$documents = new ExtensionRestore($dir, $moved, $selection, ['source' => $source, 'keep_approved' => $keep]);
			$data      = new StoreRestore($dir, $moved['extension_files'], $documents->artifactIds(), $documents->principalIds(), $documents->leftArtifactIds(), true);
			// People: only those the moved scopes use get an account here.
			[$people, $newPeople, $peopleErrors] = self::people($moved['context'], $options, self::referenced($moved, $documents, $data));
			$errors = array_merge($errors, $peopleErrors);
			if ($documents->reason() !== null) {
				$errors[] = sprintf('The documents and handoffs of these scopes cannot be moved (%s).', $documents->reason());
			}
			if ($data->reason() !== null) {
				$errors[] = sprintf('The page store data of these scopes cannot be moved (%s).', $data->reason());
			}
			if ($errors !== []) {
				return $this->rejected($report, $check['manifest'], $check['manifest_sha256'], $errors, $userId, $dryRun);
			}

			foreach ($targets as $from => $to) {
				$notes[] = in_array($to, $create, true)
					? sprintf('The scope %s is created here, with its access list.', $to)
					: sprintf('%s moves into the scope %s that this brain already has.', $from, $to);
			}
			if ($newPeople !== []) {
				$notes[] = sprintf('%d people and agents of the archive are new here (%s): they get unbound accounts, to invite or give a key from the reconnect checklist.', count($newPeople), implode(', ', array_slice($newPeople, 0, 20)));
			}
			if ($documents->rebuilds()) {
				$notes[] = sprintf('%d documents, sections, handoffs, HTML versions and uploads move, under new ids.', $documents->records());
			}
			if ($data->rebuilds()) {
				$notes[] = sprintf('%d page store documents, history entries and seats move.', $data->records());
			}
			if ($documents->duplicates() > 0) {
				$notes[] = sprintf('%d artifacts and handoffs are here already from an earlier move of this archive, and are skipped.', $documents->duplicates());
			}
			if ($documents->shares() > 0) {
				$notes[] = sprintf('%d share links are not live here: create them again from the reconnect checklist.', $documents->shares());
			}
			$notes[] = $keep
				? 'What the source had approved stays approved (the controller\'s choice); the rest waits in the Review Queue.'
				: 'Everything waits for review here: pages in the Review Queue, sections for approval, HTML versions until a person approves one.';

			$pages = $moved['pages'];
			ksort($pages, SORT_STRING);

			if ($dryRun) {
				$writer = new ImportWriter($dir, $plan);
				foreach ($pages as $path => $page) {
					$result = $writer->import((string) $path, $page, true);
					$report->item($page['file'], $result['outcome'], $result['path'], $result['message']);
				}
				$result = $report->finish('complete', $check['manifest'], $check['manifest_sha256']);
				ImportReport::keep($result, $userId);

				return ['report' => $result, 'errors' => [], 'notes' => $notes];
			}

			$started = microtime(true);
			$writer  = new MoveWriter($dir, $moved, $plan, $documents, $data);
			try {
				$counts = Transaction::run(static fn (): array => $writer->write($targets, $create, $people, $pages, $report));
			} catch (\Throwable $e) {
				foreach ($documents->writtenFiles() as $file) {
					wp_delete_file($file);
				}
				$message = $e instanceof ImportFailed ? $e->getMessage() : 'The move failed part way and was undone; nothing was moved.';

				return $this->rejected(new ImportReport($report->id(), 'import', false), $check['manifest'], $check['manifest_sha256'], [$message], $userId, false);
			}
			$writer->afterWrite();

			self::audit($userId, $report->id(), 'ok', $counts + ($keep ? ['approved_by_controller' => 1] : []), $started);

			$status = ($report->counts()['invalid'] ?? 0) > 0 ? 'partial' : 'complete';
			$result = $report->finish($status, $check['manifest'], $check['manifest_sha256']);
			ImportReport::keep($result, $userId);

			return ['report' => $result, 'errors' => [], 'notes' => $notes];
		} finally {
			ExportStore::removeTree($work);
		}
	}

	/**
	 * Where each chosen partition goes: the mapped scope, the scope here of
	 * the same name, or a new scope.
	 *
	 * @param array<int, string>   $chosen
	 * @param array<string, mixed> $options
	 * @return array{0:array<string, string>, 1:array<int, string>, 2:array<int, string>} archive partition => partition here; partitions to create; errors.
	 */
	private static function targets(array $chosen, array $options): array {
		$here = [];
		foreach (Partitions::all() as $row) {
			if ($row['name'] !== null) {
				$here[(string) $row['name']] = true;
			}
		}
		$map = is_array($options['scope_map'] ?? null) ? $options['scope_map'] : [];

		$targets = [];
		$create  = [];
		$errors  = [];
		foreach ($chosen as $name) {
			if (isset($map[$name])) {
				$to = (string) $map[$name];
				if (! isset($here[$to])) {
					$errors[] = sprintf('The scope map sends %s to %s, which is not a scope of this brain.', $name, $to);
					continue;
				}
				$targets[$name] = $to;
				continue;
			}
			$targets[$name] = $name;
			if (! isset($here[$name])) {
				$create[] = $name;
			}
		}
		foreach (array_keys($map) as $from) {
			if (! in_array((string) $from, $chosen, true)) {
				$errors[] = sprintf('The scope map names %s, which is not one of the scopes to move.', (string) $from);
			}
		}
		if (count(array_unique(array_values($targets))) < count($targets)) {
			$errors[] = 'Two scopes of the archive would move into the same scope here; map them to different scopes.';
		}
		return [$targets, $create, $errors];
	}

	/**
	 * The people and agents the moved scopes use: their access lists, the
	 * pages' authors and reviewers, and everyone the documents, handoffs and
	 * page store name. Nobody else of the archive gets an account here.
	 *
	 * @param array<string, mixed> $moved The archive check, with Selection::apply().
	 * @return array<string, true>
	 */
	private static function referenced(array $moved, ExtensionRestore $documents, StoreRestore $data): array {
		$out = $documents->referencedPrincipals() + $data->referencedPrincipals();
		foreach ((array) $moved['context']['partitions'] as $partition) {
			foreach ((array) ($partition['access'] ?? []) as $access) {
				if (is_string($access['principal'] ?? null)) {
					$out[$access['principal']] = true;
				}
			}
		}
		foreach ($moved['pages'] as $page) {
			foreach (ArchiveCheck::pagePrincipals($page['frontmatter']) as $principal) {
				$out[$principal] = true;
			}
		}

		return $out;
	}

	/**
	 * Who each person and agent the moved scopes use is here.
	 *
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $options
	 * @param array<string, true>  $referenced Principal ids the moved scopes use.
	 * @return array{0:array<string, array{user:int, principal:array<string, mixed>}>, 1:array<int, string>, 2:array<int, string>} principal id => user here (0: a new account); the new ones; errors.
	 */
	private static function people(array $context, array $options, array $referenced): array {
		$map    = is_array($options['principal_map'] ?? null) ? $options['principal_map'] : [];
		$people = [];
		$new    = [];
		$errors = [];

		$listed = [];
		foreach ((array) $context['principals'] as $principal) {
			$id = (string) ($principal['id'] ?? '');
			if (! str_starts_with($id, 'human:') && ! str_starts_with($id, 'agent:')) {
				continue;
			}
			$listed[$id] = true;
			if (! isset($referenced[$id]) && ! isset($map[$id])) {
				continue;
			}

			if (isset($map[$id])) {
				$user = is_string($map[$id]) ? Binding::userFor($map[$id]) : null;
				if ($user === null) {
					$errors[] = sprintf('The principal map sends %s to %s, which is not a principal of this brain.', $id, is_string($map[$id]) ? $map[$id] : 'a value');
					continue;
				}
				$people[$id] = ['user' => $user, 'principal' => $principal];
				continue;
			}

			$user = Binding::userFor($id);
			if ($user !== null) {
				$people[$id] = ['user' => $user, 'principal' => $principal];
				continue;
			}
			if (($principal['status'] ?? '') === Principals::STATUS_REMOVED) {
				continue;
			}
			// An id this brain gave someone who is removed here cannot be reused.
			foreach (Principals::all() as $row) {
				if ($row['principal_id'] === $id) {
					$errors[] = sprintf('The principal id %s is held here by an account that is removed or suspended; map it to a principal (principal_map).', $id);
					continue 2;
				}
			}
			$people[$id] = ['user' => 0, 'principal' => $principal];
			$new[]       = $id;
		}
		foreach (array_keys($map) as $from) {
			if (! isset($listed[(string) $from])) {
				$errors[] = sprintf('The principal map names %s, which is not a person or agent of the archive.', (string) $from);
			}
		}

		return [$people, $new, $errors];
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
	 * @param array<string, int> $counts
	 */
	private static function audit(int $userId, string $id, string $status, array $counts, float $started): void {
		do_action('agent_brain/audit', [
			'actor_id'    => max(0, $userId),
			'event'       => self::AUDIT_EVENT,
			'status'      => $status,
			'object_type' => 'import',
			'object_id'   => $id,
			'meta'        => $counts,
			'duration_ms' => (int) round((microtime(true) - $started) * 1000),
		]);
	}
}
