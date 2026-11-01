<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Provenance\DerivedFrom;
use AgentBrain\Modules\Provenance\RevisionBlame;
use AgentBrain\Modules\Review\Lifecycle;
use AgentBrain\Modules\Review\PostStatuses;
use AgentBrain\Modules\Review\ProposalService;
use AgentBrain\Modules\Review\ReviewActions;

/**
 * Writes every page of the brain as memory/<path>.md (PCP v0.3 SPEC sections
 * 5.2 and 8.2), and the earlier versions of knowledge and procedure posts as
 * history/<path>/<n>.md. Also the page writer of backup snapshots
 * (Backup\MarkdownStage, decision 7).
 *
 * Ids and paths come from ab_pcp_index and are never derived here: anything
 * missing is assigned through PageIndex::backfill() first. Partitions come
 * from Partitions, principals from the registry (PrincipalMap).
 *
 * What is a page:
 *  - memory rows with status active, rolled_up (lifecycle archived) or
 *    proposed (review proposed). Deleted rows are never exported (SPEC 8.10
 *    item 3). Working memory's expires is the rollover deadline, not PCP
 *    expires (decision 4): it is exported as x_agent_brain.rollover_at, and
 *    rows past it are still exported until the rollover archives them;
 *  - knowledge and procedure posts with status private (approved),
 *    ab_proposed or ab_rejected, read by an explicit status list, because
 *    get_posts() with "any" skips statuses registered with
 *    exclude_from_search, which both review statuses are.
 * Anything else that exists (other post statuses, unknown row statuses) is
 * counted in omitted. Trashed posts are deleted pages and are not listed
 * (SPEC 8.2 item 8).
 *
 * Rows and posts are read in pages of 500 by id, and each file is hashed as
 * it is written, so memory use does not grow with the brain.
 */
final class PageWriter {

	/** Frontmatter keys, in the order they are written. */
	public const KEY_ORDER = ['title', 'id', 'type', 'partition', 'lifecycle', 'importance', 'recorded_at', 'updated', 'source', 'derived_from', 'review', 'x_agent_brain'];

	/** Keys only kept keys (PageExtra) bring, after KEY_ORDER, then other x_ keys by name. */
	public const EXTRA_ORDER = ['sensitivity', 'confidence', 'tags', 'valid_from', 'valid_until', 'expires', 'relations', 'x_import'];

	public const ROW_STATUSES  = ['active', 'rolled_up', 'proposed'];
	public const POST_STATUSES = ['private', PostStatuses::PROPOSED, PostStatuses::REJECTED];

	/** Post statuses that are not pages at all: never exported, never listed. */
	private const NOT_PAGES = ['trash', 'auto-draft', 'inherit'];

	private const BATCH = 500;

	/** @var array<int, string> scope term id => partition name */
	private array $partitions = [];

	private int $pages = 0;

	/** @var array{approved:int, proposed:int, rejected:int} */
	private array $review = ['approved' => 0, 'proposed' => 0, 'rejected' => 0];

	/** @var array<string, int> */
	private array $perPartition = [];

	/** @var array<int, array{file:string, page:string}> */
	private array $history = [];

	public function __construct(private ArchiveFiles $files, private PrincipalMap $principals) {
	}

	/**
	 * Writes every page and its history.
	 *
	 * @return array{
	 *   page_count:int,
	 *   review_counts:array{approved:int, proposed:int, rejected:int},
	 *   partition_counts:array<string, int>,
	 *   history:array<int, array{file:string, page:string}>,
	 *   omitted:array<int, array{what:string, reason:string, count:int}>
	 * }
	 */
	public function writeAll(bool $withHistory = true): array {
		PageIndex::backfill();

		$this->writeMemoryRows();
		$this->writePosts($withHistory);

		ksort($this->perPartition, SORT_STRING);

		return [
			'page_count'       => $this->pages,
			'review_counts'    => $this->review,
			'partition_counts' => $this->perPartition,
			'history'          => $this->history,
			'omitted'          => self::omitted(),
		];
	}

	/** The partition name for a scope term; "default" when the term is gone. */
	public function partitionFor(int $termId): string {
		if (! isset($this->partitions[$termId])) {
			$name = $termId > 0 ? (Partitions::nameFor($termId) ?? Partitions::assign($termId, true)) : null;

			$this->partitions[$termId] = $name ?? Partitions::DEFAULT_NAME;
		}

		return $this->partitions[$termId];
	}

	// -----------------------------------------------------------------
	// Memory rows
	// -----------------------------------------------------------------

	private function writeMemoryRows(): void {
		$table    = MemoryRepository::memoryTable();
		$index    = PageIndex::table();
		$statuses = self::ROW_STATUSES;
		$in       = implode(', ', array_fill(0, count($statuses), '%s'));
		$lastId   = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$rows = Db::getResults(
				"SELECT m.id, m.agent_id, m.scope_id, m.type, m.content, m.search_keys, m.importance, m.source_ids,
						m.status, m.agent_written, m.client, m.via, m.created, m.expires, m.pcp_extra, i.pcp_id, i.path
					FROM {$table} m
					INNER JOIN {$index} i ON i.object_kind = %s AND i.object_id = m.id
					WHERE m.id > %d AND m.status IN ({$in})
					ORDER BY m.id ASC
					LIMIT %d",
				array_merge([PageIndex::KIND_MEMORY, $lastId], $statuses, [self::BATCH])
			);
			// phpcs:enable

			$sources = $this->rowSources($rows);

			foreach ($rows as $row) {
				$lastId = (int) $row['id'];
				$this->files->tick();
				$this->writeRow($row, $sources[$lastId] ?? []);
			}
		} while (count($rows) === self::BATCH);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<int, string>   $derivedFrom
	 */
	private function writeRow(array $row, array $derivedFrom): void {
		$extra = PageExtra::decode($row['pcp_extra'] ?? null);
		if (PageExtra::expired($extra)) {
			// Past its PCP expires (an imported page): not a page any more (SPEC 8.2 item 8).
			return;
		}

		$frontmatter = self::rowFrontmatter(
			$row,
			$this->principals->idFor((int) $row['agent_id']),
			$row['via'] !== null ? $this->principals->idFor((int) $row['via']) : null,
			$this->partitionFor((int) $row['scope_id']),
			$derivedFrom,
			(new DerivedFrom())->sourcesOf('memory', (int) $row['id'])
		);

		$this->writePage((string) $row['path'], PageExtra::apply($frontmatter, $extra), (string) $row['content']);
	}

	/**
	 * The frontmatter a memory row's columns give, before kept keys
	 * (PageExtra) are applied. Pure: restore and import compute it for what
	 * they are about to write, and keep only what differs.
	 *
	 * @param array<string, mixed> $row         id, pcp_id, type, content, search_keys, importance, status, agent_written, client, created, expires.
	 * @param array<int, string>   $derivedFrom
	 * @param array<int, array{kind:string, id:int, version?:int}> $derivedRefs
	 * @return array<string, mixed>
	 */
	public static function rowFrontmatter(array $row, ?string $author, ?string $via, string $partition, array $derivedFrom, array $derivedRefs = []): array {
		$id      = (int) $row['id'];
		$created = ExportFormat::utc((string) $row['created']) ?? '1970-01-01T00:00:00Z';
		$status  = (string) $row['status'];
		$type    = (string) $row['type'];

		$review = null;
		if ($status === 'proposed') {
			$review = ['state' => 'proposed'];
			if ($author !== null) {
				$review['generated'] = ['by' => $author, 'at' => $created];
			}
		}

		$extra = ['type' => $type];
		$searchKeys = trim((string) ($row['search_keys'] ?? ''));
		if ($searchKeys !== '') {
			$extra['search_keys'] = ExportFormat::validUtf8($searchKeys);
		}
		$rollover = ExportFormat::utc(isset($row['expires']) ? (string) $row['expires'] : null);
		if ($rollover !== null) {
			$extra['rollover_at'] = $rollover;
		}
		// PROV-1 (#170): derived-from links as {kind, id, version?} refs —
		// distinct from the spec-level derived_from above, which lists the
		// source page PATHS this row was derived from.
		if ($derivedRefs !== []) {
			$extra['derived_from'] = $derivedRefs;
		}
		ksort($extra, SORT_STRING);

		$client = isset($row['client']) && (string) $row['client'] !== '' ? (string) $row['client'] : null;

		return [
			'title'         => ExportFormat::titleFromContent((string) $row['content']) ?? 'Memory ' . $id,
			'id'            => (string) $row['pcp_id'],
			'type'          => $type === 'fact' ? 'semantic' : 'episodic',
			'partition'     => $partition,
			'lifecycle'     => $status === 'rolled_up' ? 'archived' : null,
			'importance'    => ExportFormat::importance((int) $row['importance']),
			'recorded_at'   => $created,
			// Rows are not edited in place; their content is as recorded.
			'updated'       => $created,
			'source'        => self::source(
				(int) $row['agent_written'] === 1 ? 'agent' : 'consolidation',
				$author,
				$via,
				$client,
				$created
			),
			'derived_from'  => $derivedFrom !== [] ? $derivedFrom : null,
			'review'        => $review,
			'x_agent_brain' => $extra,
		];
	}

	/**
	 * Paths of the exported rows each row was derived from: the links table
	 * (derived_kind memory) and the legacy source_ids column, in id order.
	 * Sources that are not exported (deleted rows) are left out.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<int, string>> row id => paths
	 */
	private function rowSources(array $rows): array {
		if ($rows === []) {
			return [];
		}

		$ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
		$map = $this->linkedSources('memory', $ids);

		foreach ($rows as $row) {
			$legacy = json_decode((string) ($row['source_ids'] ?? ''), true);
			if (is_array($legacy)) {
				foreach ($legacy as $sourceId) {
					if (is_numeric($sourceId) && (int) $sourceId > 0) {
						$map[(int) $row['id']][(int) $sourceId] = true;
					}
				}
			}
		}

		return $this->resolveSources($map);
	}

	/**
	 * @param array<int, int> $derivedIds
	 * @return array<int, array<int, true>> derived id => source row ids
	 */
	private function linkedSources(string $kind, array $derivedIds): array {
		if ($derivedIds === []) {
			return [];
		}

		$table = MemoryRepository::linksTable();
		$in    = implode(', ', array_fill(0, count($derivedIds), '%d'));

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$links = Db::getResults(
			"SELECT derived_id, source_id FROM {$table} WHERE derived_kind = %s AND derived_id IN ({$in})",
			array_merge([$kind], $derivedIds)
		);
		// phpcs:enable

		$map = [];
		foreach ($links as $link) {
			$map[(int) $link['derived_id']][(int) $link['source_id']] = true;
		}

		return $map;
	}

	/**
	 * @param array<int, array<int, true>> $map derived id => source row ids
	 * @return array<int, array<int, string>> derived id => paths, by source id
	 */
	private function resolveSources(array $map): array {
		$all = [];
		foreach ($map as $sources) {
			$all += $sources;
		}
		if ($all === []) {
			return [];
		}

		$ids      = array_keys($all);
		$table    = MemoryRepository::memoryTable();
		$index    = PageIndex::table();
		$statuses = self::ROW_STATUSES;
		$in       = implode(', ', array_fill(0, count($ids), '%d'));
		$sin      = implode(', ', array_fill(0, count($statuses), '%s'));

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$found = Db::getResults(
			"SELECT m.id, i.path FROM {$table} m
				INNER JOIN {$index} i ON i.object_kind = %s AND i.object_id = m.id
				WHERE m.id IN ({$in}) AND m.status IN ({$sin})",
			array_merge([PageIndex::KIND_MEMORY], $ids, $statuses)
		);
		// phpcs:enable

		$paths = [];
		foreach ($found as $row) {
			$paths[(int) $row['id']] = (string) $row['path'];
		}

		$out = [];
		foreach ($map as $derivedId => $sources) {
			$sourceIds = array_keys($sources);
			sort($sourceIds, SORT_NUMERIC);
			foreach ($sourceIds as $sourceId) {
				if (isset($paths[$sourceId])) {
					$out[$derivedId][] = $paths[$sourceId];
				}
			}
		}

		return $out;
	}

	// -----------------------------------------------------------------
	// Knowledge and procedure posts
	// -----------------------------------------------------------------

	private function writePosts(bool $withHistory): void {
		global $wpdb;

		$index    = PageIndex::table();
		$statuses = self::POST_STATUSES;
		$types    = array_keys(PageIndex::POST_KINDS);
		$sin      = implode(', ', array_fill(0, count($statuses), '%s'));
		$lastId   = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$rows = Db::getResults(
				"SELECT p.ID, p.post_type, p.post_status, p.post_author, p.post_title, p.post_content,
						p.post_date_gmt, p.post_modified_gmt, i.pcp_id, i.path
					FROM {$wpdb->posts} p
					INNER JOIN {$index} i ON i.object_kind = (CASE p.post_type WHEN %s THEN %s ELSE %s END) AND i.object_id = p.ID
					WHERE p.ID > %d AND p.post_type IN (%s, %s) AND p.post_status IN ({$sin})
					ORDER BY p.ID ASC
					LIMIT %d",
				array_merge(
					[$types[0], PageIndex::POST_KINDS[$types[0]][0], PageIndex::POST_KINDS[$types[1]][0], $lastId, $types[0], $types[1]],
					$statuses,
					[self::BATCH]
				)
			);
			// phpcs:enable

			$ids     = array_map(static fn (array $row): int => (int) $row['ID'], $rows);
			$sources = $this->postSources($ids);

			foreach ($rows as $row) {
				$lastId = (int) $row['ID'];
				$this->files->tick();
				$this->writePost($row, $sources[$lastId] ?? [], $withHistory);
			}
		} while (count($rows) === self::BATCH);
	}

	/**
	 * @param array<int, int> $postIds
	 * @return array<int, array<int, string>>
	 */
	private function postSources(array $postIds): array {
		$map = $this->linkedSources('post', $postIds);

		foreach ($postIds as $postId) {
			$legacy = get_post_meta($postId, ProposalService::META_SOURCE_MEMORY_IDS, true);
			if (is_array($legacy)) {
				foreach ($legacy as $sourceId) {
					if (is_numeric($sourceId) && (int) $sourceId > 0) {
						$map[$postId][(int) $sourceId] = true;
					}
				}
			}
		}

		return $this->resolveSources($map);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<int, string>   $derivedFrom
	 */
	private function writePost(array $row, array $derivedFrom, bool $withHistory): void {
		$id    = (int) $row['ID'];
		$extra = PageExtra::decode(get_post_meta($id, PageExtra::META, true));
		if (PageExtra::expired($extra)) {
			return;
		}

		$proposerId = (int) get_post_meta($id, ProposalService::META_PROPOSING_AGENT, true);
		$reviewerId = (int) get_post_meta($id, ReviewActions::META_REVIEWED_BY, true);

		[$partition, $alsoIn] = $this->postPartitions($id);

		[$frontmatter] = self::postFrontmatter($row, [
			'author'         => $this->principals->idFor($proposerId > 0 ? $proposerId : (int) $row['post_author']),
			'by_agent'       => $proposerId > 0,
			'partition'      => $partition,
			'also_in'        => $alsoIn,
			'search_keys'    => (string) get_post_meta($id, ProposalService::META_SEARCH_KEYS, true),
			'importance'     => get_post_meta($id, ProposalService::META_IMPORTANCE, true),
			'reviewer'       => $reviewerId > 0 ? $this->principals->idFor($reviewerId) : null,
			'reviewed_at'    => (string) get_post_meta($id, ReviewActions::META_REVIEWED_AT, true),
			'review_outcome' => (string) get_post_meta($id, ReviewActions::META_REVIEW_OUTCOME, true),
			'derived_from'   => $derivedFrom,
			// PROV-1 (#170): {kind, id, version?} refs under x_agent_brain,
			// distinct from the spec-level derived_from paths above.
			'derived_refs'   => (new DerivedFrom())->sourcesOf('post', $id),
			'lifecycle'      => Lifecycle::of($id),
		]);

		$frontmatter = PageExtra::apply($frontmatter, $extra);
		$this->writePage((string) $row['path'], $frontmatter, (string) $row['post_content']);

		if ($withHistory) {
			$this->writeHistory($id, (string) $row['path'], $frontmatter, (string) $row['post_title'], (string) $row['post_content']);
		}
	}

	/**
	 * The frontmatter a knowledge or procedure post gives, before kept keys
	 * (PageExtra) are applied, and its review state for the counts. Pure
	 * apart from the controller (a rejection with no recorded reviewer).
	 *
	 * @param array<string, mixed> $post ID, post_type, post_status, post_title, post_content, post_date_gmt, post_modified_gmt, path, pcp_id.
	 * @param array{author:?string, by_agent:bool, partition:string, also_in:array<int, string>, search_keys:string, importance:mixed, reviewer:?string, reviewed_at:string, review_outcome:string, derived_from:array<int, string>, derived_refs?:array<int, array{kind:string, id:int, version?:int}>, lifecycle?:?string} $in
	 * @return array{0:array<string, mixed>, 1:string}
	 */
	public static function postFrontmatter(array $post, array $in): array {
		$status   = (string) $post['post_status'];
		$isKnow   = $post['post_type'] === 'ab_knowledge';
		$recorded = ExportFormat::utc((string) $post['post_date_gmt']) ?? ExportFormat::utc((string) $post['post_modified_gmt']) ?? '1970-01-01T00:00:00Z';
		$updated  = ExportFormat::utc((string) $post['post_modified_gmt']) ?? $recorded;
		$author   = $in['author'];

		$extra = [];
		if ($in['also_in'] !== []) {
			$extra['also_in'] = $in['also_in'];
		}
		$derivedRefs = (array) ($in['derived_refs'] ?? []);
		if ($derivedRefs !== []) {
			$extra['derived_from'] = $derivedRefs;
		}
		$searchKeys = trim($in['search_keys']);
		if ($searchKeys !== '') {
			$extra['search_keys'] = ExportFormat::validUtf8($searchKeys);
		}

		[$review, $state, $reviewExtra] = self::postReview($status, $author, $in['reviewer'], ExportFormat::utc($in['reviewed_at']), $in['review_outcome'], $recorded, $updated);
		$extra += $reviewExtra;
		ksort($extra, SORT_STRING);

		$importance = $in['importance'];

		$frontmatter = [
			'title'         => self::postTitle((string) $post['post_title'], (string) $post['post_content'], (string) $post['path']),
			'id'            => (string) $post['pcp_id'],
			'type'          => $isKnow ? 'semantic' : 'procedural',
			'partition'     => $in['partition'],
			// #594: a stored lifecycle (stale reference, archived) wins.
			'lifecycle'     => $in['lifecycle'] ?? ($isKnow && $state === 'approved' ? 'validated' : null),
			'importance'    => is_numeric($importance) ? ExportFormat::importance((int) $importance) : null,
			'recorded_at'   => $recorded,
			'updated'       => $updated,
			'source'        => self::source($in['by_agent'] ? 'agent' : 'user', $author, null, null, $recorded),
			'derived_from'  => $in['derived_from'] !== [] ? $in['derived_from'] : null,
			'review'        => $review,
			'x_agent_brain' => $extra !== [] ? $extra : null,
		];

		return [$frontmatter, $state];
	}

	/**
	 * The primary partition (the first scope by partition name) and the
	 * others, sorted, for the legacy posts that still have several scopes.
	 *
	 * @return array{0:string, 1:array<int, string>}
	 */
	private function postPartitions(int $postId): array {
		$terms = wp_get_object_terms($postId, Scopes::TAXONOMY, ['fields' => 'ids', 'orderby' => 'none']);
		$names = [];
		if (is_array($terms)) {
			foreach ($terms as $termId) {
				$names[] = $this->partitionFor((int) $termId);
			}
		}

		$names = array_values(array_unique($names));
		sort($names, SORT_STRING);

		if ($names === []) {
			return [Partitions::DEFAULT_NAME, []];
		}

		return [array_shift($names), $names];
	}

	/**
	 * The review object (SPEC 5.4), the page's review state for the counts,
	 * and any x_agent_brain keys the review adds.
	 *
	 * @return array{0:?array<string, mixed>, 1:string, 2:array<string, mixed>}
	 */
	private static function postReview(string $status, ?string $author, ?string $reviewer, ?string $reviewedAt, string $outcome, string $recorded, string $updated): array {
		$generated = $author !== null ? ['by' => $author, 'at' => $recorded] : null;
		$extra     = [];

		if ($status === PostStatuses::PROPOSED) {
			return [array_filter(['state' => 'proposed', 'generated' => $generated]), 'proposed', []];
		}

		if ($status === PostStatuses::REJECTED) {
			$by = $reviewer;
			if ($by === null) {
				// Rejected before reviewers were recorded: the schema needs a
				// rejecting principal, and the controller acts for the site.
				$by                         = Controller::ensure();
				$extra['review_by_unknown'] = true;
			}

			return [
				array_filter([
					'state'     => 'rejected',
					'generated' => $generated,
					'rejected'  => ['by' => $by, 'at' => $reviewedAt ?? $updated],
				]),
				'rejected',
				$extra,
			];
		}

		if ($outcome === ReviewActions::OUTCOME_EDITED) {
			$extra['review_outcome'] = ReviewActions::OUTCOME_EDITED;
		}

		// Approved. Without a recorded reviewer, review is left out, which
		// means approved (SPEC 5.4).
		if ($reviewer === null) {
			return [null, 'approved', $extra];
		}

		return [
			array_filter([
				'state'     => 'approved',
				'generated' => $generated,
				'verified'  => [['by' => $reviewer, 'at' => $reviewedAt ?? $updated]],
			]),
			'approved',
			$extra,
		];
	}

	/**
	 * Earlier versions from WordPress revisions, oldest first, as
	 * history/<path>/<n>.md. Autosaves are not versions; the newest revision
	 * is the current text itself (WordPress saves one on every update) and
	 * is left out when it matches the page.
	 *
	 * @param array<string, mixed> $page The page's frontmatter.
	 */
	private function writeHistory(int $postId, string $path, array $page, string $title, string $content): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$revisions = Db::getResults(
			"SELECT ID, post_title, post_content, post_name, post_date_gmt, post_modified_gmt FROM {$wpdb->posts}
				WHERE post_parent = %d AND post_type = %s
				ORDER BY post_date_gmt ASC, ID ASC",
			[$postId, 'revision']
		);
		// phpcs:enable

		$revisions = array_values(array_filter(
			$revisions,
			static fn (array $r): bool => ! str_contains((string) $r['post_name'], '-autosave-')
		));

		$last = end($revisions);
		if ($last !== false && (string) $last['post_title'] === $title && (string) $last['post_content'] === $content) {
			array_pop($revisions);
		}

		$n = 0;
		foreach ($revisions as $revision) {
			++$n;
			$this->files->tick();

			$updated = ExportFormat::utc((string) $revision['post_modified_gmt']) ?? ExportFormat::utc((string) $revision['post_date_gmt']) ?? (string) $page['recorded_at'];
			$file    = 'history/' . $path . '/' . $n . '.md';

			// PROV-5 (#174): per-revision blame (actor, via, client, AI) as x_* extension keys (the SPEC page
			// schema only admits x_-prefixed extras) from the revision's stamp,
			// so blame survives a move.
			$stamp = RevisionBlame::forRevision((int) $revision['ID']);

			$frontmatter = [
				'title'     => self::postTitle((string) $revision['post_title'], (string) $revision['post_content'], $path),
				'id'        => $page['id'],
				'type'      => $page['type'],
				'partition' => $page['partition'],
				'updated'   => $updated,
				'x_written_by'     => $stamp !== null ? $this->principals->idFor($stamp['actor_id']) : null,
				'x_written_via'    => $stamp !== null && $stamp['via'] !== null ? $this->principals->idFor($stamp['via']) : null,
				'x_written_client' => $stamp !== null && $stamp['client'] !== null ? ExportFormat::validUtf8($stamp['client']) : null,
				'x_written_ai'     => $stamp !== null && $stamp['ai'] ? 1 : null,
			];

			$this->files->write($file, ExportFormat::page(self::ordered($frontmatter), (string) $revision['post_content']));
			$this->history[] = ['file' => $file, 'page' => $path];
		}
	}

	// -----------------------------------------------------------------
	// Shared
	// -----------------------------------------------------------------

	/**
	 * @param array<string, mixed> $frontmatter
	 */
	private function writePage(string $path, array $frontmatter, string $body): void {
		$this->files->write('memory/' . $path . '.md', ExportFormat::page(self::ordered($frontmatter), $body));

		$state = is_array($frontmatter['review'] ?? null) ? (string) ($frontmatter['review']['state'] ?? 'approved') : 'approved';
		++$this->pages;
		$this->review[$state] = ($this->review[$state] ?? 0) + 1;
		$partition                      = (string) $frontmatter['partition'];
		$this->perPartition[$partition] = ($this->perPartition[$partition] ?? 0) + 1;
	}

	/**
	 * @param array<string, mixed> $frontmatter
	 * @return array<string, mixed>
	 */
	public static function ordered(array $frontmatter): array {
		$out = [];
		foreach (self::KEY_ORDER as $key) {
			if (array_key_exists($key, $frontmatter) && $frontmatter[$key] !== null) {
				$out[$key] = $frontmatter[$key];
			}
		}

		// Kept keys (PageExtra) the columns do not give: the other SPEC 5.2
		// keys in a fixed order, then extension keys by name.
		$rest = array_diff_key($frontmatter, $out);
		foreach (self::EXTRA_ORDER as $key) {
			if (isset($rest[$key])) {
				$out[$key] = $rest[$key];
				unset($rest[$key]);
			}
		}
		ksort($rest, SORT_STRING);
		foreach ($rest as $key => $value) {
			if ($value !== null) {
				$out[$key] = $value;
			}
		}

		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	private static function source(string $origin, ?string $principal, ?string $onBehalfOf, ?string $client, string $at): array {
		return array_filter([
			'origin'       => $origin,
			'principal'    => $principal,
			'on_behalf_of' => $onBehalfOf !== $principal ? $onBehalfOf : null,
			'client'       => $client,
			'at'           => $at,
		], static fn (?string $value): bool => $value !== null);
	}

	/**
	 * The stored title, cut to the schema's 300 characters; posts saved
	 * without one fall back to their text, then to the last path segment.
	 */
	private static function postTitle(string $title, string $content, string $path): string {
		if (trim($title) !== '') {
			return ExportFormat::clip($title, 300);
		}

		$segments = explode('/', $path);

		return ExportFormat::titleFromContent(wp_strip_all_tags($content)) ?? (string) end($segments);
	}

	/**
	 * Data that exists and is not exported, by category with counts:
	 * knowledge and procedure posts in other statuses, memory rows in
	 * statuses this version does not know.
	 *
	 * @return array<int, array{what:string, reason:string, count:int}>
	 */
	public static function omitted(): array {
		global $wpdb;

		$skip  = array_merge(self::POST_STATUSES, self::NOT_PAGES);
		$types = array_keys(PageIndex::POST_KINDS);
		$in    = implode(', ', array_fill(0, count($skip), '%s'));

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$posts = Db::getResults(
			"SELECT post_type, post_status, COUNT(*) AS c FROM {$wpdb->posts}
				WHERE post_type IN (%s, %s) AND post_status NOT IN ({$in})
				GROUP BY post_type, post_status
				ORDER BY post_type ASC, post_status ASC",
			array_merge($types, $skip)
		);

		$known = array_merge(self::ROW_STATUSES, ['deleted']);
		$kin   = implode(', ', array_fill(0, count($known), '%s'));
		$table = MemoryRepository::memoryTable();
		$rows  = Db::getResults(
			"SELECT status, COUNT(*) AS c FROM {$table} WHERE status NOT IN ({$kin}) GROUP BY status ORDER BY status ASC",
			$known
		);
		// phpcs:enable

		$out = [];
		foreach ($posts as $row) {
			$kind  = $row['post_type'] === 'ab_knowledge' ? 'knowledge' : 'procedure';
			$out[] = [
				'what'   => sprintf('memory: %s posts with status %s', $kind, preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $row['post_status']))),
				'reason' => 'unsupported',
				'count'  => (int) $row['c'],
			];
		}
		foreach ($rows as $row) {
			$out[] = [
				'what'   => sprintf('memory: rows with status %s', preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $row['status']))),
				'reason' => 'unsupported',
				'count'  => (int) $row['c'],
			];
		}

		return $out;
	}
}
