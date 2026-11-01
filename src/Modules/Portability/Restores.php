<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Artifacts\Cpts as ArtifactCpts;
use AgentBrain\Modules\Forget\ReceiptRepository;
use AgentBrain\Modules\Identity\DefaultScope;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\Cpts;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Recall\Orientation;
use AgentBrain\Modules\Review\HandoffCpt;

/**
 * Restore mode (PCP v0.3 SPEC section 8.7.2): an administrator restores a
 * full archive of a team context into an empty brain, which becomes the
 * brain the archive was. Used by the Export and import page, the management
 * REST routes and WP-CLI.
 *
 * In order, and nothing is written before the last check passes:
 *  1. the archive is unpacked safely (ArchiveReader);
 *  2. every SPEC 8.3 check and both versions (ArchiveCheck), and the
 *     manifest signature (PROV-8, #177): an archive whose signature does
 *     not verify is refused here, before anything is written, and an
 *     unsigned archive restores with a note that its origin cannot be
 *     verified;
 *  3. the restore's own conditions: a full archive of a team context, no
 *     sensitive pages (decision 10), no pending revisions (TomaTío has
 *     none to put them in), an empty brain, and no principal id of the
 *     archive held here by anyone else;
 *  4. a dry run stops here with the report of what would be written;
 *  5. everything is written in one transaction (RestoreWriter, with
 *     ExtensionRestore for documents and handoffs), so a failure part way
 *     leaves the brain empty (IMP-03);
 *  6. one import.restore audit entry, with counts only.
 *
 * The brain's own next export is then the archive again (SPEC 8.8): the
 * same page, history, extension and profile files and context.json, and the
 * same audit log with this restore's and that export's entries added.
 */
final class Restores {

	public const AUDIT_EVENT = 'import.restore';

	/** ab_audit.object_type of entries kept verbatim from an archive. */
	public const RESTORED_AUDIT_TYPE = 'pcp_restored';

	/** Option: when and from what the brain was restored. */
	public const OPTION_RESTORED = 'ab_pcp_restored';

	/**
	 * @param array{dry_run?:bool, bind_as?:string, leave_scopes?:mixed, keep_scopes?:mixed, leave_people?:mixed, keep_people?:mixed, adopt_accounts?:bool} $options
	 * @return array{report:array<string, mixed>, errors:array<int, string>, notes:array<int, string>}
	 * @throws ImportFailed When the requester may not restore.
	 */
	public function run(string $archive, int $userId, array $options = []): array {
		self::authorize($userId);

		$dryRun = ! empty($options['dry_run']);
		$bindAs = trim((string) ($options['bind_as'] ?? ''));
		$report = new ImportReport(wp_generate_uuid4(), 'restore', $dryRun);
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

			// PROV-8 (#177): like an import, a restore refuses an archive
			// whose manifest signature does not verify, before anything is
			// written, and notes one that carries no signature.
			[$signatureError, $signatureNote, $signatureProvenance] = ManifestSignature::assess($check['manifest_sha256'], $check['signature']['doc'] ?? null);
			if ($signatureError !== null) {
				$errors[] = $signatureError;
			} elseif ($signatureNote !== null) {
				$notes[] = $signatureNote;
			}

			if ($errors === []) {
				$errors = self::conditions($check, $userId, $bindAs);
			}
			$selection = Selection::none();
			if ($errors === []) {
				[$selection, $errors] = Selection::fromOptions($options, $check['context'], $bindAs);
			}
			// #582: accounts here that hold an archive person's id take them over, when asked to.
			$adopt = [];
			if ($errors === []) {
				[$adopt, $errors, $adoptNotes] = Adoption::plan($check['context'], $userId, $bindAs, ! empty($options['adopt_accounts']), $selection);
				$notes = array_merge($notes, $adoptNotes);
			}
			if ($errors !== []) {
				return $this->rejected($report, $check['manifest'], $check['manifest_sha256'], $errors, $userId, $dryRun);
			}

			// #549: what stays behind is never written, nor counted as migrated.
			$notes = array_merge($notes, $dryRun ? Selection::inventory($check) : [], $selection->describe($check));
			$check = $selection->apply($check);

			foreach ($check['pages'] as $path => $page) {
				$report->item($page['file'], 'created', (string) $path);
			}
			$documents = new ExtensionRestore($work . '/archive', $check, $selection);
			$store     = RestoreWriter::storeFor($work . '/archive', $check, $documents);
			if ($documents->rebuilds()) {
				$notes[] = sprintf('%d documents, sections and handoffs are migrated under their own ids.', $documents->records());
				if ($documents->shares() > 0) {
					$notes[] = sprintf('%d share links are not live on this brain: their tokens belong to the old address. Create them again from the reconnect checklist.', $documents->shares());
				}
			} elseif ($documents->reason() !== null) {
				$notes[] = sprintf('Documents and handoffs cannot be migrated here (%s): their files are kept as they are and exported again unchanged, but this brain does not show them.', $documents->reason());
			}
			if ($store->rebuilds()) {
				$notes[] = sprintf('%d page store documents, history entries and seats are migrated.', $store->records());
			} elseif ($store->reason() !== null) {
				$notes[] = sprintf('The page store data cannot be migrated here (%s): its files are kept as they are and exported again unchanged, but this brain does not serve it.', $store->reason());
			}
			if (! $selection->isEmpty()) {
				if ($documents->leftRecords() + $store->leftRecords() > 0) {
					$notes[] = sprintf('%d documents, handoffs and page store entries stay behind with their scopes.', $documents->leftRecords() + $store->leftRecords());
				}
				// Files kept verbatim cannot be sorted by scope: refuse rather than bring what should stay behind.
				if ($documents->reason() !== null || $store->reason() !== null) {
					$result = $this->rejected($report, $check['manifest'], $check['manifest_sha256'], ['Scopes or people can be left behind only when the documents and the page store can be rebuilt; here they would be kept as files, with everything in them. Migrate without leaving anything behind, or export the source again with this version.'], $userId, $dryRun);
					$result['notes'] = $notes;

					return $result;
				}
			}
			$kept = array_values(array_filter($check['extension_files'], static fn (string $path): bool => ! ($documents->rebuilds() && ExtensionRestore::owns($path)) && ! ($store->rebuilds() && \AgentBrain\Modules\DataStore\StoreRestore::owns($path))));
			if ($kept !== []) {
				$report->count('extensions_kept', count($kept));
				$notes[] = sprintf('%d extension files are kept as they are and exported again unchanged; this version does not serve them.', count($kept));
			}
			if ($check['connections'] > 0) {
				$notes[] = 'Connections do not travel: authorize each one again on this server.';
			}
			$suggested = self::suggestedBinding($check['context'], $userId);
			if ($bindAs === '' && $suggested !== null) {
				$notes[] = sprintf('The archive has the person %s, which matches your login. To carry on as them, migrate with "Bind me to" set to %s.', $suggested, $suggested);
			}
			$notes[] = 'Every migrated person and agent is unbound until an administrator binds them on the Principals screen: nobody can sign in or connect as them before that.';

			if ($dryRun) {
				$result = $report->finish('complete', $check['manifest'], $check['manifest_sha256']);
				ImportReport::keep($result, $userId);

				return ['report' => $result, 'errors' => [], 'notes' => $notes];
			}

			$started = microtime(true);
			DefaultScope::removeForRestore();
			$writer  = new RestoreWriter($work . '/archive', $check, $userId, $bindAs, $documents, $store, $selection, $adopt);
			try {
				$counts = Transaction::run(static fn (): array => $writer->write());
			} catch (\Throwable $e) {
				foreach ($writer->writtenFiles() as $file) {
					wp_delete_file($file);
				}
				self::undo($writer->createdPosts());
				$message = $e instanceof ImportFailed ? $e->getMessage() : 'The migration failed part way and was undone; the brain is empty again.';

				return $this->rejected($report, $check['manifest'], $check['manifest_sha256'], [$message], $userId, false);
			}

			self::afterWrite($writer);

			update_option(self::OPTION_RESTORED, [
				'at'              => gmdate('Y-m-d\TH:i:s\Z'),
				'by'              => $userId,
				'exporter'        => (string) ($check['manifest']['exporter']['name'] ?? ''),
				'exported_at'     => (string) ($check['manifest']['exported_at'] ?? ''),
				'manifest_sha256' => $check['manifest_sha256'],
				'signature'       => $signatureProvenance,
			], false);

			self::audit($userId, $report->id(), 'ok', ['written' => $counts['rows'] + $counts['posts']], $started);

			$result = $report->finish('complete', $check['manifest'], $check['manifest_sha256']);
			ImportReport::keep($result, $userId);

			return ['report' => $result, 'errors' => [], 'notes' => $notes];
		} finally {
			ExportStore::removeTree($work);
		}
	}

	/**
	 * Why this brain is not empty (SPEC 8.7.2 item 1), or nothing. Users may
	 * exist; memory, scopes, house rules and extension data may not.
	 *
	 * @return array<int, string>
	 */
	public static function notEmpty(): array {
		global $wpdb;

		$out   = [];
		$types = array_merge(Cpts::postTypes(), [ArtifactCpts::ARTIFACT_POST_TYPE, ArtifactCpts::SECTION_POST_TYPE, HandoffCpt::POST_TYPE]);
		$in    = implode(', ', array_fill(0, count($types), '%s'));

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows   = (int) (Db::getResults('SELECT COUNT(*) AS c FROM ' . MemoryRepository::memoryTable())[0]['c'] ?? 0);
		$posts  = (int) (Db::getResults("SELECT COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type IN ({$in})", $types)[0]['c'] ?? 0);
		$scopes = (int) (Db::getResults("SELECT COUNT(*) AS c FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", [Scopes::TAXONOMY])[0]['c'] ?? 0);
		$index  = (int) (Db::getResults('SELECT COUNT(*) AS c FROM ' . PageIndex::table())[0]['c'] ?? 0);
		// phpcs:enable

		if ($rows > 0) {
			$out[] = sprintf('it has %d memory rows', $rows);
		}
		if ($posts > 0) {
			$out[] = sprintf('it has %d knowledge, procedure, artifact or handoff posts', $posts);
		}
		// The team scope a new brain starts with (#182) does not count when
		// nothing else is here; the restore removes it.
		if (DefaultScope::id() !== null) {
			--$scopes;
		}
		if ($scopes > 0) {
			$out[] = sprintf('it has %d scopes', $scopes);
		}
		if ($index > 0 && $rows === 0 && $posts === 0) {
			$out[] = 'its page index is not empty';
		}
		if (Orientation::houseRules() !== '') {
			$out[] = 'it has house rules';
		}
		if (Kept::count() > 0) {
			$out[] = 'it holds data kept from an earlier migration';
		}
		$proposals = get_option('agent_brain_agent_proposals', []);
		if (is_array($proposals) && $proposals !== []) {
			$out[] = sprintf('it has %d agent proposals waiting', count($proposals));
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$receipts = (int) (Db::getResults('SELECT COUNT(*) AS c FROM ' . ReceiptRepository::tableName())[0]['c'] ?? 0);
		if ($receipts > 0) {
			$out[] = sprintf('it has %d forget receipts', $receipts);
		}

		return $out;
	}

	/**
	 * A human principal of the archive whose id matches the requester's
	 * login, suggested for binding (never bound automatically).
	 *
	 * @param array<string, mixed> $context
	 */
	private static function suggestedBinding(array $context, int $userId): ?string {
		$user = $userId > 0 ? get_userdata($userId) : false;
		if (! $user instanceof \WP_User) {
			return null;
		}

		$wanted = 'human:' . Names::localId((string) $user->user_login);
		foreach ((array) $context['principals'] as $principal) {
			if (($principal['id'] ?? '') === $wanted && ($principal['status'] ?? '') !== Principals::STATUS_REMOVED) {
				return $wanted;
			}
		}

		return null;
	}

	public static function canRestore(int $userId): bool {
		return Exports::canExport($userId);
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
	 * The restore's own conditions, after the archive checks.
	 *
	 * @param array<string, mixed> $check
	 * @return array<int, string>
	 */
	private static function conditions(array $check, int $userId, string $bindAs): array {
		$errors   = [];
		$manifest = $check['manifest'];
		$context  = $check['context'];

		if (($manifest['coverage'] ?? '') !== 'full') {
			$errors[] = 'Only a full export can be migrated as a whole brain; this archive is partial. Import it instead.';
		}
		if (($context['kind'] ?? '') !== 'team') {
			$errors[] = sprintf('This brain is a team context; the archive is a %s context. Import its pages instead.', is_string($context['kind'] ?? null) ? $context['kind'] : 'different');
		}
		if ((int) ($manifest['memory']['sensitive_page_count'] ?? 0) > 0) {
			$errors[] = 'The archive has sensitive pages, which TomaTío cannot protect yet, so it cannot be migrated here.';
		}
		if ($check['revisions'] > 0) {
			$errors[] = 'The archive has pending revisions of pages, which TomaTío cannot keep yet, so it cannot be migrated here.';
		}

		$problems = self::notEmpty();
		if ($problems !== []) {
			$errors[] = 'This brain is not empty (' . implode('; ', $problems) . '). Migrating a whole brain needs an empty brain: import into this one instead.';
		}

		$listed = [];
		foreach ((array) $context['principals'] as $principal) {
			$listed[(string) $principal['id']] = $principal;
		}

		if ($bindAs !== '') {
			$target = $listed[$bindAs] ?? null;
			if ($target === null || ! str_starts_with($bindAs, 'human:') || ($target['status'] ?? '') === Principals::STATUS_REMOVED) {
				$errors[] = 'You can be bound only to a person in the archive who was not removed.';
			}
			if ($userId <= 0) {
				$errors[] = 'Binding needs a signed-in administrator (WP-CLI: --user).';
			}
		}

		$own = $userId > 0 ? Principals::idFor($userId) : null;
		if ($own !== null && isset($listed[$own]) && $own !== $bindAs) {
			$errors[] = sprintf('Your own principal id %s is in the archive: bind yourself to it, or migrate as another administrator.', $own);
		}

		// Ids already held here by another account: Adoption (#582).

		return $errors;
	}

	/**
	 * After a rolled back write: if the database could not roll back (a
	 * table without transactions), remove what the restore made, so the
	 * brain is empty again.
	 *
	 * @param array<int, int> $createdPosts Posts the restore wrote (compiled documents are ordinary posts).
	 */
	private static function undo(array $createdPosts = []): void {
		if (self::notEmpty() === []) {
			return;
		}

		global $wpdb;

		foreach ($createdPosts as $postId) {
			wp_delete_post($postId, true);
		}

		$types = array_merge(Cpts::postTypes(), [ArtifactCpts::ARTIFACT_POST_TYPE, ArtifactCpts::SECTION_POST_TYPE, HandoffCpt::POST_TYPE, 'revision']);
		$attached = [\AgentBrain\Modules\Uploads\UploadStore::table(), \AgentBrain\Modules\DataStore\StoreData::table(), \AgentBrain\Modules\DataStore\StoreHistory::table(), \AgentBrain\Modules\DataStore\StoreSeats::table()];
		foreach (array_merge([MemoryRepository::memoryTable(), MemoryRepository::linksTable(), PageIndex::table(), Kept::table()], $attached) as $table) {
			$wpdb->query("DELETE FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		}
		$in = implode(', ', array_fill(0, count($types), '%s'));
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		foreach (Db::getResults("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$in})", $types) as $row) {
			wp_delete_post((int) $row['ID'], true);
		}
		// phpcs:enable
		foreach ((array) get_terms(['taxonomy' => Scopes::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids']) as $termId) {
			wp_delete_term((int) $termId, Scopes::TAXONOMY);
		}
		wp_cache_flush();
	}

	private static function afterWrite(RestoreWriter $writer): void {
		wp_cache_flush();

		foreach ($writer->createdUsers() as $userId) {
			clean_user_cache($userId);
		}
		foreach ($writer->createdPosts() as $postId) {
			clean_post_cache($postId);
		}

		$terms = $writer->createdTerms();
		if ($terms !== []) {
			clean_term_cache($terms, Scopes::TAXONOMY);
			$ttIds = [];
			foreach ($terms as $termId) {
				$term = get_term($termId, Scopes::TAXONOMY);
				if ($term instanceof \WP_Term) {
					$ttIds[] = (int) $term->term_taxonomy_id;
				}
			}
			wp_update_term_count_now($ttIds, Scopes::TAXONOMY);
		}
	}

	private static function authorize(int $userId): void {
		if ($userId === 0 && defined('WP_CLI') && constant('WP_CLI')) {
			return;
		}

		if (! self::canRestore($userId)) {
			throw new ImportFailed('forbidden', 'Only administrators can migrate a brain here.');
		}
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
