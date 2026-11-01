<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Forget\Tombstones;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Review\Lifecycle;
use AgentBrain\Modules\Review\PostStatuses;
use AgentBrain\Modules\Review\ProposalService;

/**
 * Writes one page of an archive in import mode (PCP v0.3 SPEC section
 * 8.7.3) and says what happened to it. Imports runs every check first and
 * calls this per page inside one transaction.
 *
 * Each page is marked as imported: source.origin import, source.principal
 * the mapped author or the importer, source.client the importing client,
 * recorded_at the import instant, updated kept, and x_import with the
 * source's id, file hash, recorded_at, source, review and (when changed)
 * partition and path, plus the source brain and the manifest-signature
 * result under x_agent_brain (source_brain: the archive's controller
 * principal; import_signature: whether the archive's manifest signature
 * verified, PROV-8). It becomes a proposal: episodic pages and TomaTío
 * memory rows as memory rows with status proposed, the rest as knowledge or
 * procedure posts in ab_proposed, all in the Review Queue. With the
 * controller shortcut, pages the source had approved are approved at once,
 * each verified by the controller. #594: an import `as` reference does the
 * same and lands every knowledge or procedure page that names no lifecycle
 * as `stale`: a dated reference page (Review\Lifecycle), searchable after
 * current knowledge, without a review per page. A lifecycle the source
 * names (stale, archived) is kept either way.
 *
 * Keys without a column are kept (PageExtra) and the body is stored as
 * received, so every source value is in the page or its x_import (SPEC 8.8
 * item 3). The idempotency key (source path and the file's SHA-256) goes in
 * ab_pcp_import_keys, so a second run skips every page as a duplicate.
 */
final class ImportWriter {

	use WritesTables;

	/** x_agent_brain keys about the source's own review or scopes, which import does not carry. */
	private const DROPPED_AB_KEYS = ['also_in', 'review_outcome', 'review_by_unknown'];

	/** @var array<string, true> lower-cased target paths taken by this import */
	private array $taken = [];

	/** @var array<string, string> source path => target path */
	private array $renamed = [];

	/**
	 * @param array<string, mixed> $context What the import knows: importer (user id and principal), client, now, controller, manifest, options, partitions (name => term id), principal_map (source id => user id), readable (term ids the importer reads), writable (term ids), source_signature (x_agent_brain.import_signature, or null when the archive was rejected over it).
	 */
	public function __construct(private string $dir, private array $context) {
	}

	/**
	 * @param array{file:string, sha256:string, frontmatter:array<string, mixed>} $page
	 * @return array{outcome:string, path:?string, message:?string}
	 */
	public function import(string $path, array $page, bool $dryRun): array {
		$fm   = $page['frontmatter'];
		$body = PageFile::parse((string) file_get_contents($this->dir . '/' . $page['file']))['body'];

		if (($fm['sensitivity'] ?? 'normal') === 'sensitive') {
			return self::result('invalid', null, 'Sensitive pages are not imported: TomaTío cannot protect them yet.');
		}

		$converted = self::convertTimes($fm);
		if ($converted === null) {
			return self::result('invalid', null, 'A time in the frontmatter cannot be read.');
		}
		$fm = $converted;

		if (isset($fm['expires']) && strtotime((string) $fm['expires']) <= (int) $this->context['now_ts']) {
			return self::result('skipped_expired', null, null);
		}

		if (self::erased(is_string($fm['id'] ?? null) ? $fm['id'] : null, $path, $body)) {
			return self::result('skipped_erased', null, null);
		}

		$sourcePath = is_array($fm['x_import'] ?? null) && is_string($fm['x_import']['path'] ?? null) ? $fm['x_import']['path'] : $path;
		$key        = self::key($sourcePath, $page['sha256']);
		if (ImportKeys::exists($key)) {
			return self::result('skipped_duplicate', null, null);
		}

		$sourcePartition = is_string($fm['partition'] ?? null) ? $fm['partition'] : Partitions::DEFAULT_NAME;
		$targetPartition = $this->context['partition_map'][$sourcePartition] ?? $this->context['default_partition'];
		$termId          = (int) $this->context['partitions'][$targetPartition];

		$outcome  = 'created';
		$target   = $path;
		$existing = $this->existing($path);
		if ($existing !== null || isset($this->taken[strtolower($path)])) {
			$readable = $existing === null || $this->canRead($existing);
			switch ($this->context['on_conflict']) {
				case 'replace':
					if ($existing === null || ! $readable || ! $this->canWrite($existing)) {
						return self::result('skipped_conflict', null, null);
					}
					$outcome = 'replaced';
					break;
				case 'rename':
					$target  = $this->freePath($path);
					$outcome = 'renamed';
					if ($target === null) {
						return self::result('invalid', null, 'No free path is left to rename this page to.');
					}
					break;
				default:
					return self::result('skipped_conflict', null, null);
			}
		}

		$this->taken[strtolower($target)] = true;
		if ($target !== $path) {
			$this->renamed[$path] = $target;
		}

		if ($dryRun) {
			return self::result($outcome, $target, null);
		}

		if ($outcome === 'replaced' && $existing !== null) {
			self::remove($existing);
		}

		$this->write($path, $target, $page, $fm, $body, $sourcePartition, $targetPartition, $termId);
		ImportKeys::add($key, $sourcePath, $target);

		return self::result($outcome, $target, null);
	}

	/** @return array<string, string> source path => target path, for pages this import renamed */
	public function renamed(): array {
		return $this->renamed;
	}

	/**
	 * @param array{file:string, sha256:string, frontmatter:array<string, mixed>} $page
	 * @param array<string, mixed>                                                 $fm Times converted.
	 */
	private function write(string $sourcePath, string $path, array $page, array $fm, string $body, string $sourcePartition, string $partition, int $termId): void {
		$now      = (string) $this->context['now'];
		$importer = (string) $this->context['importer_principal'];
		$source   = is_array($fm['source'] ?? null) ? $fm['source'] : [];
		$review   = is_array($fm['review'] ?? null) ? $fm['review'] : null;

		// Principals: mapped, or the importer (SPEC 8.7.3 item 7).
		$authorUser = $this->mapped($source['principal'] ?? null);
		if ($authorUser <= 0) {
			$authorUser = (int) $this->context['importer'];
		}
		$author  = (string) ($this->context['principal_ids'][$authorUser] ?? $importer);
		$viaUser = $this->mapped($source['on_behalf_of'] ?? null);
		$via     = $viaUser > 0 ? (string) ($this->context['principal_ids'][$viaUser] ?? '') : '';

		$reference = ($this->context['as'] ?? '') === 'reference';
		$approved  = (! empty($this->context['approve_all']) || $reference) && ($review === null || ($review['state'] ?? '') === 'approved');

		$stamped = array_filter([
			'origin'       => 'import',
			'basis'        => $source['basis'] ?? null,
			'principal'    => $author,
			'on_behalf_of' => $via !== '' && $via !== $author ? $via : null,
			'client'       => (string) $this->context['client'],
			'session'      => $source['session'] ?? null,
			'at'           => $source['at'] ?? $fm['recorded_at'] ?? null,
		], static fn ($v): bool => $v !== null);

		$xImport = array_filter([
			'format'      => 'pcp',
			'at'          => $now,
			'exporter'    => (string) $this->context['exporter'],
			'context'     => (string) $this->context['source_context'],
			'id'          => is_string($fm['id'] ?? null) ? $fm['id'] : null,
			'path'        => $path !== $sourcePath ? $sourcePath : null,
			'sha256'      => $page['sha256'],
			'recorded_at' => $fm['recorded_at'] ?? null,
			'source'      => $source !== [] ? $source : null,
			'review'      => $review ?? ['state' => 'approved'],
			'partition'   => $partition !== $sourcePartition ? $sourcePartition : null,
		], static fn ($v): bool => $v !== null && $v !== '');

		$abExtra = is_array($fm['x_agent_brain'] ?? null) ? array_diff_key($fm['x_agent_brain'], array_flip(self::DROPPED_AB_KEYS)) : [];
		$rowType = is_string($abExtra['type'] ?? null) && in_array($abExtra['type'], MemoryRepository::TYPES, true) ? $abExtra['type'] : null;
		$asRow   = $rowType !== null || ($fm['type'] ?? '') === 'episodic';

		// derived_from follows pages this import renamed.
		$derived = null;
		if (is_array($fm['derived_from'] ?? null)) {
			$derived = array_values(array_map(fn ($p): string => $this->renamed[(string) $p] ?? (string) $p, $fm['derived_from']));
		}

		$importance = isset($fm['importance']) && is_numeric($fm['importance']) ? (int) round(((float) $fm['importance']) * 100) : null;
		$searchKeys = is_string($abExtra['search_keys'] ?? null) ? $abExtra['search_keys'] : '';

		if ($asRow) {
			$data = [
				'agent_id'      => $authorUser,
				'scope_id'      => $termId,
				'type'          => $rowType ?? 'episode',
				'content'       => $body,
				'search_keys'   => $searchKeys !== '' ? $searchKeys : null,
				'importance'    => $importance ?? 50,
				'source_ids'    => null,
				'status'        => $approved ? 'active' : 'proposed',
				'agent_written' => 0,
				'client'        => (string) $this->context['client'],
				'via'           => $viaUser > 0 ? $viaUser : null,
				'created'       => WritesTables::mysql($now),
				'expires'       => is_string($abExtra['rollover_at'] ?? null) ? WritesTables::mysql($abExtra['rollover_at']) : null,
			];
			$rowId = $this->insertRow($data, ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s']);
			$pcpId = self::pcpId('ab-memory-' . $rowId);
			$this->index(PageIndex::KIND_MEMORY, $rowId, $pcpId, $path);

			$native = PageWriter::rowFrontmatter(['id' => $rowId, 'pcp_id' => $pcpId] + $data, $author, $via !== '' ? $via : null, $partition, []);
			$this->keepExtra('memory', $rowId, $this->page($fm, $native, $stamped, $xImport, $derived, $approved, $abExtra, null), $native);

			return;
		}

		$postType  = ($fm['type'] ?? '') === 'procedural' ? 'ab_procedure' : 'ab_knowledge';
		$updated   = WritesTables::mysql((string) ($fm['updated'] ?? $now));
		$lifecycle = Lifecycle::stored($fm['lifecycle'] ?? null);
		if ($lifecycle === null && $reference && $approved && ! isset($fm['lifecycle'])) {
			$lifecycle = Lifecycle::STALE;
		}
		$segments = explode('/', $path);

		$postId = $this->insertPost([
			'post_author'       => $authorUser,
			'post_date'         => get_date_from_gmt(WritesTables::mysql($now)),
			'post_date_gmt'     => WritesTables::mysql($now),
			'post_content'      => $body,
			'post_title'        => (string) $fm['title'],
			'post_status'       => $approved ? 'private' : PostStatuses::PROPOSED,
			'post_name'         => substr(strtolower((string) end($segments)), 0, 200),
			'post_modified'     => get_date_from_gmt($updated),
			'post_modified_gmt' => $updated,
			'post_parent'       => 0,
			'post_type'         => $postType,
		]);
		if ($importance !== null) {
			$this->setPostMeta($postId, ProposalService::META_IMPORTANCE, $importance);
		}
		if ($searchKeys !== '') {
			$this->setPostMeta($postId, ProposalService::META_SEARCH_KEYS, $searchKeys);
		}
		if ($lifecycle !== null) {
			$this->setPostMeta($postId, Lifecycle::META, $lifecycle);
		}
		$this->relate($postId, $termId);

		$kind  = $postType === 'ab_procedure' ? PageIndex::KIND_PROCEDURE : PageIndex::KIND_KNOWLEDGE;
		$pcpId = self::pcpId('ab-' . $kind . '-' . $postId);
		$this->index($kind, $postId, $pcpId, $path);

		[$native] = PageWriter::postFrontmatter([
			'ID'                => $postId,
			'post_type'         => $postType,
			'post_status'       => $approved ? 'private' : PostStatuses::PROPOSED,
			'post_title'        => (string) $fm['title'],
			'post_content'      => $body,
			'post_date_gmt'     => WritesTables::mysql($now),
			'post_modified_gmt' => $updated,
			'path'              => $path,
			'pcp_id'            => $pcpId,
		], [
			'author'         => $author,
			'by_agent'       => false,
			'partition'      => $partition,
			'also_in'        => [],
			'search_keys'    => $searchKeys,
			'importance'     => $importance,
			'reviewer'       => null,
			'reviewed_at'    => '',
			'review_outcome' => '',
			'derived_from'   => [],
			'lifecycle'      => $lifecycle,
		]);
		$this->keepExtra('post', $postId, $this->page($fm, $native, $stamped, $xImport, $derived, $approved, $abExtra, $lifecycle), $native);
	}

	/**
	 * The page as it should export after the import: the source's
	 * frontmatter with the imported marks, on top of what the columns give.
	 *
	 * @param array<string, mixed>      $fm
	 * @param array<string, mixed>      $native
	 * @param array<string, mixed>      $stamped
	 * @param array<string, mixed>      $xImport
	 * @param array<int, string>|null   $derived
	 * @param array<string, mixed>      $abExtra
	 * @return array<string, mixed>
	 */
	private function page(array $fm, array $native, array $stamped, array $xImport, ?array $derived, bool $approved, array $abExtra, ?string $lifecycle): array {
		$page = $fm;

		$page['id']          = $native['id'];
		$page['partition']   = $native['partition'];
		$page['recorded_at'] = $native['recorded_at'];
		$page['source']      = $stamped;
		$page['x_import']    = $xImport;
		$page['type']        = $fm['type'] ?? $native['type'];
		if (! isset($fm['importance'])) {
			unset($page['importance']);
		}
		if ($derived !== null) {
			$page['derived_from'] = $derived;
		}
		if ($lifecycle !== null) {
			$page['lifecycle'] = $lifecycle;
		}

		$page['review'] = $approved
			? ['state' => 'approved', 'verified' => [['by' => (string) $this->context['controller'], 'at' => (string) $this->context['now']]]]
			: ['state' => 'proposed', 'generated' => ['by' => (string) $stamped['principal'], 'at' => (string) $this->context['now']]];

		// TomaTío's own keys as the columns give them, with the source's
		// others (not its scopes or review, which import does not carry).
		$own = is_array($native['x_agent_brain'] ?? null) ? $native['x_agent_brain'] : [];
		$own = $abExtra + $own;
		// PROV-1 (#170): an imported page keeps the source brain (its
		// controller principal) in x_agent_brain — the epic's agreed place
		// for fields the PCP spec does not have yet (x_import is the spec's
		// own shape and admits no new keys).
		$sourceBrain = (string) ($this->context['source_brain'] ?? '');
		if ($sourceBrain !== '') {
			$own['source_brain'] = $sourceBrain;
		}
		// PROV-8 (#177): whether the archive's manifest signature verified
		// (and under which key), so the provenance of an imported page says
		// how much its origin can be trusted. An unsigned archive is
		// recorded as signed: false; an invalid one never reaches here.
		if (is_array($this->context['source_signature'] ?? null)) {
			$own['import_signature'] = $this->context['source_signature'];
		}
		// #594: the form the controller chose (PCP: recorded with the import).
		if (($this->context['as'] ?? '') === 'reference') {
			$own['import_as'] = 'reference';
		}
		ksort($own, SORT_STRING);
		$page['x_agent_brain'] = $own !== [] ? $own : null;

		return array_filter($page, static fn ($v): bool => $v !== null);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Server-stamped instants as RFC 3339 UTC (SPEC 8.7.3 item 8), or null
	 * when one cannot be read. valid_from and valid_until keep their offsets.
	 *
	 * @param array<string, mixed> $fm
	 * @return array<string, mixed>|null
	 */
	public static function convertTimes(array $fm): ?array {
		foreach (['updated', 'recorded_at', 'expires'] as $key) {
			if (isset($fm[$key])) {
				$utc = TimeConvert::utc($fm[$key]);
				if ($utc === null) {
					return null;
				}
				$fm[$key] = $utc;
			}
		}

		if (is_array($fm['review'] ?? null)) {
			foreach (['generated', 'rejected'] as $key) {
				if (isset($fm['review'][$key]['at'])) {
					$utc = TimeConvert::utc($fm['review'][$key]['at']);
					if ($utc === null) {
						return null;
					}
					$fm['review'][$key]['at'] = $utc;
				}
			}
			foreach ((array) ($fm['review']['verified'] ?? []) as $i => $verified) {
				if (is_array($verified) && isset($verified['at'])) {
					$utc = TimeConvert::utc($verified['at']);
					if ($utc === null) {
						return null;
					}
					$fm['review']['verified'][$i]['at'] = $utc;
				}
			}
		}

		return $fm;
	}

	/** The idempotency key: the source path and the file's SHA-256 (SPEC 8.7.3 item 6). */
	public static function key(string $sourcePath, string $sha256): string {
		return hash('sha256', $sourcePath . "\n" . $sha256);
	}

	/**
	 * @param mixed $principal
	 */
	private function mapped($principal): int {
		return is_string($principal) ? (int) ($this->context['principal_map'][$principal] ?? 0) : 0;
	}

	/**
	 * The page this brain holds at $path (ignoring case), or null.
	 *
	 * @return array{kind:string, id:int, term:int}|null
	 */
	private function existing(string $path): ?array {
		$rows = Db::getResults(
			'SELECT object_kind, object_id FROM ' . PageIndex::table() . ' WHERE path_lower = %s LIMIT 1',
			[strtolower($path)]
		);
		if ($rows === []) {
			return null;
		}

		$kind = (string) $rows[0]['object_kind'];
		$id   = (int) $rows[0]['object_id'];
		$term = 0;
		if ($kind === PageIndex::KIND_MEMORY) {
			$scope = Db::getResults('SELECT scope_id FROM ' . MemoryRepository::memoryTable() . ' WHERE id = %d', [$id]);
			$term  = (int) ($scope[0]['scope_id'] ?? 0);
		} else {
			$terms = wp_get_object_terms($id, \AgentBrain\Modules\Identity\Scopes::TAXONOMY, ['fields' => 'ids']);
			$term  = is_array($terms) && $terms !== [] ? (int) $terms[0] : 0;
		}

		return ['kind' => $kind, 'id' => $id, 'term' => $term];
	}

	/**
	 * @param array{kind:string, id:int, term:int} $existing
	 */
	private function canRead(array $existing): bool {
		return ! empty($this->context['sees_all']) || in_array($existing['term'], (array) $this->context['readable'], true);
	}

	/**
	 * @param array{kind:string, id:int, term:int} $existing
	 */
	private function canWrite(array $existing): bool {
		return ! empty($this->context['sees_all']) || in_array($existing['term'], (array) $this->context['writable'], true);
	}

	/**
	 * "<path>-imported-<n>" with the smallest free n, the last segment cut
	 * to fit 64 characters (SPEC 8.7.3 item 5).
	 */
	private function freePath(string $path): ?string {
		$slash = strrpos($path, '/');
		$head  = $slash === false ? '' : substr($path, 0, $slash + 1);
		$last  = $slash === false ? $path : substr($path, $slash + 1);

		for ($n = 1; $n <= 1000; $n++) {
			$suffix    = '-imported-' . $n;
			$candidate = $head . rtrim(substr($last, 0, Names::SEGMENT_MAX - strlen($suffix)), '-') . $suffix;
			if (! Names::isPath($candidate)) {
				return null;
			}
			if (! isset($this->taken[strtolower($candidate)]) && $this->existing($candidate) === null) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Replace: the page that held the path goes (rows as deleted, posts for
	 * good), with its index row.
	 *
	 * @param array{kind:string, id:int, term:int} $existing
	 */
	private static function remove(array $existing): void {
		global $wpdb;

		if ($existing['kind'] === PageIndex::KIND_MEMORY) {
			$wpdb->update(MemoryRepository::memoryTable(), ['status' => 'deleted'], ['id' => $existing['id']], ['%s'], ['%d']);
			PageIndex::removeMemory([$existing['id']]);

			return;
		}

		wp_delete_post($existing['id'], true);
		$wpdb->delete(PageIndex::table(), ['object_kind' => $existing['kind'], 'object_id' => $existing['id']], ['%s', '%d']);
	}

	private function relate(int $postId, int $termId): void {
		global $wpdb;

		$term = get_term($termId, \AgentBrain\Modules\Identity\Scopes::TAXONOMY);
		if ($term instanceof \WP_Term) {
			$wpdb->insert($wpdb->term_relationships, ['object_id' => $postId, 'term_taxonomy_id' => (int) $term->term_taxonomy_id, 'term_order' => 0], ['%d', '%d', '%d']);
		}
	}

	private static function pcpId(string $wanted): string {
		$taken = Db::getResults('SELECT 1 FROM ' . PageIndex::table() . ' WHERE pcp_id = %s LIMIT 1', [$wanted]);
		if ($taken === []) {
			return $wanted;
		}
		for ($n = 2; $n < 1000; $n++) {
			$taken = Db::getResults('SELECT 1 FROM ' . PageIndex::table() . ' WHERE pcp_id = %s LIMIT 1', [$wanted . '-' . $n]);
			if ($taken === []) {
				return $wanted . '-' . $n;
			}
		}

		throw new ImportFailed('internal', 'No free page id.');
	}

	/** An erasure tombstone of this brain matches the page (SPEC 8.9.5). */
	private static function erased(?string $id, string $path, string $body): bool {
		return Tombstones::isErased($id, $path, $body);
	}

	/**
	 * @return array{outcome:string, path:?string, message:?string}
	 */
	private static function result(string $outcome, ?string $path, ?string $message): array {
		return ['outcome' => $outcome, 'path' => $path, 'message' => $message];
	}
}
