<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Artifacts\Block;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\Stages;
use AgentBrain\Modules\Comments\Cpt;
use AgentBrain\Modules\Mcp\Present;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Provenance\RevisionBlame;
use AgentBrain\Modules\Review\HandoffCpt;
use AgentBrain\Modules\Review\HandoffQueue;

/**
 * Writes the data of TomaTío's two tool extensions (PCP v0.3 SPEC
 * sections 6.5 and 8.5) under extensions/<id>/, one file per record, and
 * returns their manifest entries. The layouts are described in
 * docs/pcp-export.md ("Extension data").
 *
 * Shared documents (slot doc), extensions/<ExtensionIds::DOC>/:
 *  - artifacts/<post id>.json: an artifact with its stage, lead, partition
 *    and outline (section record ids, in order), its kind, friendly
 *    address and settings (#547, ArtifactRecords);
 *  - html/<artifact id>/<n>.json, snapshots/<artifact id>/<seq>.json and
 *    shares/<link id>.json: HTML versions, version history rows and live
 *    share links without their secrets (#547, ArtifactRecords);
 *  - sections/<post id>.md: a section, its state, owner, version, brief
 *    and review log in the frontmatter, its block markup as the body;
 *  - history/sections/<post id>/<n>.md: earlier versions of a section
 *    (WordPress revisions), oldest first;
 *  - compiled/<post id>.md: documents compiled or assembled from an
 *    artifact (ordinary posts carrying ab_compiled_from);
 *  - comments/<post id>.md: a comment thread root or reply on an artifact
 *    version (ART-28), its author, version, section/element anchor, thread
 *    parent and resolution state in the frontmatter, its text as the body.
 * Handoffs (slot handoff), extensions/<ExtensionIds::HANDOFF>/:
 *  - handoffs/<post id>.md: a handoff, its sender, recipient, status,
 *    section and linked memory pages (by path), its note as the body.
 *
 * Records are named "<kind>-<post id>" and reference each other by those
 * names and pages by path. Frontmatter is written by ExportFormat in a
 * fixed key order, JSON with fixed key order too, so the bytes depend only
 * on the data (SPEC 8.8). The §5.2 keys partition, source, updated and
 * recorded_at keep their §5.2 meanings; source appears only where the
 * creator is known (handoffs).
 *
 * Leases (_edit_lock, ab_lease_expires) are runtime locks: never exported
 * and not listed in omitted. Trashed posts are deleted records and are not
 * exported either (SPEC 8.2 item 8).
 *
 * Partitions: an artifact's is its scope (ab_scope_id); its sections and
 * compiled documents take the artifact's. A handoff routed to a section
 * takes that artifact's; otherwise the first, by name, of its linked
 * memory pages; otherwise default.
 */
final class ExtensionWriter {

	public const SECTION_KEYS  = ['title', 'id', 'artifact', 'partition', 'recorded_at', 'updated', 'owner', 'state', 'version', 'last_writer', 'last_writer_client', 'brief', 'allowed_blocks', 'review_log', 'x_written_by', 'x_written_via', 'x_written_client', 'x_written_ai'];
	public const COMPILED_KEYS = ['title', 'id', 'artifact', 'partition', 'status', 'recorded_at', 'updated'];
	public const HANDOFF_KEYS  = ['title', 'id', 'partition', 'recorded_at', 'updated', 'source', 'from', 'to', 'status', 'section', 'artifact', 'pages'];
	public const COMMENT_KEYS  = ['title', 'id', 'artifact', 'partition', 'recorded_at', 'updated', 'source', 'author', 'version', 'section', 'element', 'parent', 'status', 'resolved_by', 'resolved_at'];

	/** Post statuses that are not records: never exported, never listed. */
	private const NOT_RECORDS = ['trash', 'auto-draft', 'inherit'];

	private const BATCH = 500;

	/** @var array<int, string> artifact post id => partition */
	private array $artifactPartitions = [];

	/** @var array<int, true> section post ids that are exported */
	private array $sections = [];

	/** @var array<int, int> section post id => artifact post id, for exported artifacts */
	private array $sectionArtifact = [];

	public function __construct(private ArchiveFiles $files, private PrincipalMap $principals, private PageWriter $pages) {
	}

	/**
	 * Writes both extensions. An extension with no records writes no files
	 * and gets no manifest entry.
	 *
	 * @return array<int, array{id:string, path:string, slot:string, version:string, items:int}>
	 */
	public function writeAll(): array {
		$entries = [];

		$doc = $this->writeDoc();
		if ($doc > 0) {
			$entries[] = self::entry(ExtensionIds::DOC, 'doc', $doc);
		}

		$handoffs = $this->writeHandoffs();
		if ($handoffs > 0) {
			$entries[] = self::entry(ExtensionIds::HANDOFF, 'handoff', $handoffs);
		}

		// Third-party extensions (e.g. the ART-23 data store): a module
		// writes its files through the shared ArchiveFiles and returns one
		// manifest entry per extension, the same shape writeAll() returns.
		foreach ((array) apply_filters('agent_brain/pcp_extensions', [], $this->files) as $entry) {
			if (! is_array($entry) || ! isset($entry['id'], $entry['path'], $entry['slot'], $entry['version'], $entry['items'])) {
				continue;
			}

			$entries[] = [
				'id'      => (string) $entry['id'],
				'path'    => (string) $entry['path'],
				'slot'    => (string) $entry['slot'],
				'version' => (string) $entry['version'],
				'items'   => (int) $entry['items'],
			];
		}

		return $entries;
	}

	/**
	 * @return array{id:string, path:string, slot:string, version:string, items:int}
	 */
	private static function entry(string $id, string $slot, int $items): array {
		return [
			'id'      => $id,
			'path'    => ExtensionIds::path($id),
			'slot'    => $slot,
			'version' => ExtensionIds::LAYOUT_VERSION,
			'items'   => $items,
		];
	}

	// -----------------------------------------------------------------
	// Shared documents
	// -----------------------------------------------------------------

	/** @return int Records written: artifacts, sections and compiled documents. */
	private function writeDoc(): int {
		$artifacts = $this->posts(Cpts::ARTIFACT_POST_TYPE);
		foreach ($artifacts as $row) {
			$this->artifactPartitions[(int) $row['ID']] = $this->pages->partitionFor((int) get_post_meta((int) $row['ID'], Cpts::META_SCOPE_ID, true));
		}

		// Sections first, so an outline lists only sections that are
		// exported. A section of a trashed artifact is deleted with it; one
		// whose artifact no longer exists at all is still exported, alone.
		$sections = [];
		foreach ($this->posts(Cpts::SECTION_POST_TYPE) as $row) {
			$parent = (int) $row['post_parent'];
			if (isset($this->artifactPartitions[$parent])) {
				$this->sectionArtifact[(int) $row['ID']] = $parent;
			} elseif ($parent > 0 && in_array(get_post_status($parent), self::NOT_RECORDS, true)) {
				continue;
			}
			$this->sections[(int) $row['ID']] = true;
			$sections[]                       = $row;
		}

		$count = 0;
		foreach ($artifacts as $row) {
			$this->files->tick();
			$this->writeArtifact($row);
			$count += 1 + $this->writeArtifactData((int) $row['ID']);
		}
		foreach (ArtifactRecords::shareRecords($this->artifactPartitions, $this->principals) as $id => $record) {
			$this->files->tick();
			$this->files->write($this->docPath(ArtifactRecords::DIR_SHARES . $id . '.json'), ExportFormat::document($record));
			++$count;
		}
		foreach ($sections as $row) {
			$this->files->tick();
			$this->writeSection($row);
			++$count;
		}

		// Comments (ART-28): a comment of a trashed artifact is deleted with
		// it; one whose artifact no longer exists at all is still exported,
		// alone (the section rule above). The parent pass needs the full set
		// of exported comment ids first, so collect before writing.
		$comments   = [];
		$commentIds = [];
		foreach ($this->posts(Cpt::POST_TYPE) as $row) {
			$artifact = (int) get_post_meta((int) $row['ID'], Cpt::META_ARTIFACT, true);
			if ($artifact > 0 && ! isset($this->artifactPartitions[$artifact]) && in_array(get_post_status($artifact), self::NOT_RECORDS, true)) {
				continue;
			}
			$commentIds[(int) $row['ID']] = true;
			$comments[]                   = $row;
		}
		foreach ($comments as $row) {
			$this->files->tick();
			$this->writeComment($row, $commentIds);
			++$count;
		}

		return $count + $this->writeCompiled();
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function writeArtifact(array $row): void {
		$id       = (int) $row['ID'];
		$recorded = self::recorded($row);
		$parent   = (int) $row['post_parent'];

		$outline = [];
		foreach (parse_blocks((string) $row['post_content']) as $block) {
			$sectionId = (int) ($block['attrs']['sectionId'] ?? 0);
			if (($block['blockName'] ?? null) === Block::NAME && isset($this->sections[$sectionId])) {
				$outline['section-' . $sectionId] = true;
			}
		}
		$outline = array_keys($outline);

		// ab_share_visibility and ab_archived_at are ArtifactService's own keys.
		$visibility = trim((string) get_post_meta($id, 'ab_share_visibility', true));

		$slug = (string) ($row['post_name'] ?? '');

		$record = array_filter([
			'id'               => 'artifact-' . $id,
			'title'            => self::title((string) $row['post_title'], 'Artifact ' . $id),
			'partition'        => $this->artifactPartitions[$id],
			'parent'           => isset($this->artifactPartitions[$parent]) ? 'artifact-' . $parent : null,
			'stage'            => Stages::artifactStage($id),
			'status'           => (string) $row['post_status'],
			'share_visibility' => $visibility !== '' ? ExportFormat::validUtf8($visibility) : null,
			'archived_at'      => ExportFormat::utc((string) get_post_meta($id, 'ab_archived_at', true)),
			'lead'             => $this->principals->idFor((int) get_post_meta($id, Cpts::META_LEAD_AGENT, true)),
			'sections'         => $outline,
			'recorded_at'      => $recorded,
			'updated'          => ExportFormat::utc((string) $row['post_modified_gmt']) ?? $recorded,
			'kind'             => ArtifactRecords::kind($id),
			'slug'             => $slug !== '' ? ExportFormat::validUtf8($slug) : null,
			'old_slugs'        => ArtifactRecords::oldSlugs($id),
			'settings'         => ArtifactRecords::settings($id),
		], static fn ($value): bool => $value !== null);

		$this->files->write($this->docPath('artifacts/' . $id . '.json'), ExportFormat::document($record));
	}

	/**
	 * An artifact's HTML versions and version history rows (#547).
	 *
	 * @return int Records written.
	 */
	private function writeArtifactData(int $id): int {
		$count = 0;
		foreach (ArtifactRecords::htmlRecords($id, $this->artifactPartitions[$id], $this->principals) as $n => $record) {
			$this->files->tick();
			$this->files->write($this->docPath(ArtifactRecords::DIR_HTML . $id . '/' . $n . '.json'), ExportFormat::document($record));
			++$count;
		}
		foreach (ArtifactRecords::snapshotRecords($id, $this->artifactPartitions[$id], $this->sections, $this->principals) as $seq => $record) {
			$this->files->tick();
			$this->files->write($this->docPath(ArtifactRecords::DIR_SNAPSHOTS . $id . '/' . $seq . '.json'), ExportFormat::document($record));
			++$count;
		}

		return $count;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function writeSection(array $row): void {
		$id       = (int) $row['ID'];
		$recorded = self::recorded($row);
		$updated  = ExportFormat::utc((string) $row['post_modified_gmt']) ?? $recorded;
		$artifact = $this->sectionArtifact[$id] ?? null;

		$brief   = (string) get_post_meta($id, Cpts::META_SECTION_BRIEF, true);
		$client  = trim((string) get_post_meta($id, 'ab_last_writer_client', true)); // ArtifactService's key.
		$allowed = get_post_meta($id, Cpts::META_ALLOWED_BLOCKS, true);
		$allowed = is_array($allowed) ? array_values(array_filter(array_map('strval', $allowed), static fn (string $b): bool => $b !== '')) : [];
		$version = get_post_meta($id, Cpts::META_VERSION, true);

		$log = [];
		foreach (Stages::reviewLog($id) as $stored) {
			// Decoded from stored JSON: the declared shape is not guaranteed.
			/** @var mixed $entry */
			$entry = $stored;
			if (! is_array($entry)) {
				continue;
			}
			$log[] = array_filter([
				'version' => isset($entry['version']) ? (int) $entry['version'] : null,
				'by'      => $this->principals->idFor((int) ($entry['reviewer_id'] ?? 0)),
				'verdict' => isset($entry['verdict']) ? (string) $entry['verdict'] : null,
				'notes'   => isset($entry['notes']) && (string) $entry['notes'] !== '' ? ExportFormat::validUtf8((string) $entry['notes']) : null,
				'at'      => ExportFormat::utc(isset($entry['at']) ? (string) $entry['at'] : null),
			], static fn ($value): bool => $value !== null);
		}

		$frontmatter = [
			'title'              => self::title((string) $row['post_title'], 'Section ' . $id),
			'id'                 => 'section-' . $id,
			'artifact'           => $artifact !== null ? 'artifact-' . $artifact : null,
			'partition'          => $artifact !== null ? $this->artifactPartitions[$artifact] : Partitions::DEFAULT_NAME,
			'recorded_at'        => $recorded,
			'updated'            => $updated,
			'owner'              => $this->principals->idFor((int) get_post_meta($id, Cpts::META_SECTION_OWNER, true)),
			'state'              => Stages::sectionState($id),
			'version'            => $version === '' ? 1 : (int) $version,
			'last_writer'        => $this->principals->idFor((int) get_post_meta($id, Cpts::META_LAST_WRITER, true)),
			'last_writer_client' => $client !== '' ? ExportFormat::validUtf8($client) : null,
			'brief'              => $brief !== '' ? ExportFormat::validUtf8($brief) : null,
			'allowed_blocks'     => $allowed !== [] ? $allowed : null,
			'review_log'         => $log !== [] ? $log : null,
		];

		$this->files->write($this->docPath('sections/' . $id . '.md'), ExportFormat::page(self::ordered(self::SECTION_KEYS, $frontmatter), (string) $row['post_content']));
		$this->writeSectionHistory($id, $frontmatter, (string) $row['post_title'], (string) $row['post_content']);
	}

	/**
	 * Earlier versions of a section from WordPress revisions, oldest first,
	 * without autosaves and without the newest revision when it is the
	 * current text (as PageWriter does for knowledge and procedures).
	 *
	 * @param array<string, mixed> $section The section's frontmatter.
	 */
	private function writeSectionHistory(int $sectionId, array $section, string $title, string $content): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$revisions = Db::getResults(
			"SELECT ID, post_title, post_content, post_name, post_date_gmt, post_modified_gmt FROM {$wpdb->posts}
				WHERE post_parent = %d AND post_type = %s
				ORDER BY post_date_gmt ASC, ID ASC",
			[$sectionId, 'revision']
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

			// PROV-5 (#174): per-revision blame (actor, via, client, AI) as x_* extension keys (the SPEC page
			// schema only admits x_-prefixed extras) from the revision's stamp,
			// so blame survives a move.
			$stamp     = RevisionBlame::forRevision((int) $revision['ID']);
			$frontmatter = [
				'title'     => self::title((string) $revision['post_title'], (string) $section['title']),
				'id'        => $section['id'],
				'artifact'  => $section['artifact'],
				'partition' => $section['partition'],
				'updated'   => ExportFormat::utc((string) $revision['post_modified_gmt']) ?? ExportFormat::utc((string) $revision['post_date_gmt']) ?? $section['recorded_at'],
				'x_written_by'     => $stamp !== null ? $this->principals->idFor($stamp['actor_id']) : null,
				'x_written_via'    => $stamp !== null && $stamp['via'] !== null ? $this->principals->idFor($stamp['via']) : null,
				'x_written_client' => $stamp !== null && $stamp['client'] !== null ? ExportFormat::validUtf8($stamp['client']) : null,
				'x_written_ai'     => $stamp !== null && $stamp['ai'] ? 1 : null,
			];

			$this->files->write(
				$this->docPath('history/sections/' . $sectionId . '/' . $n . '.md'),
				ExportFormat::page(self::ordered(self::SECTION_KEYS, $frontmatter), (string) $revision['post_content'])
			);
		}
	}

	/**
	 * comments/<post id>.md: a comment thread root or reply. The
	 * frontmatter carries the artifact/section anchors (by record id), the
	 * version the comment was made against, the thread parent (replies
	 * only), the open/resolved status and the resolver, and the author as
	 * both a principal and a §5.2 source (origin derived from the principal
	 * id prefix — a curator's comment is a human source, an agent's is an
	 * agent source). Partition: the artifact's, like its sections.
	 *
	 * @param array<string, mixed>     $row
	 * @param array<int, true>         $commentIds Every exported comment id (parent refs).
	 */
	private function writeComment(array $row, array $commentIds): void {
		$id       = (int) $row['ID'];
		$recorded = self::recorded($row);

		$artifactId = (int) get_post_meta($id, Cpt::META_ARTIFACT, true);
		$exported   = $artifactId > 0 && isset($this->artifactPartitions[$artifactId]);

		$author = $this->principals->idFor((int) $row['post_author']);

		$resolved   = (int) get_post_meta($id, Cpt::META_RESOLVED, true) === 1;
		$resolvedBy = (int) get_post_meta($id, Cpt::META_RESOLVED_BY, true);

		$sectionId = (int) get_post_meta($id, Cpt::META_SECTION, true);
		$parentId  = (int) $row['post_parent'];
		$element   = trim((string) get_post_meta($id, Cpt::META_ELEMENT, true));

		$frontmatter = [
			'title'       => self::title((string) $row['post_title'], 'Comment ' . $id),
			'id'          => 'comment-' . $id,
			'artifact'    => $exported ? 'artifact-' . $artifactId : null,
			'partition'   => $exported ? $this->artifactPartitions[$artifactId] : Partitions::DEFAULT_NAME,
			'recorded_at' => $recorded,
			'updated'     => ExportFormat::utc((string) $row['post_modified_gmt']) ?? $recorded,
			'source'      => $author !== null ? ['origin' => str_starts_with($author, 'human:') ? 'user' : 'agent', 'principal' => $author, 'at' => $recorded] : null,
			'author'      => $author,
			'version'     => max(1, (int) get_post_meta($id, Cpt::META_VERSION, true)),
			'section'     => isset($this->sections[$sectionId]) ? 'section-' . $sectionId : null,
			'element'     => $element !== '' ? ExportFormat::validUtf8($element) : null,
			'parent'      => isset($commentIds[$parentId]) ? 'comment-' . $parentId : null,
			'status'      => $resolved ? Cpt::STATUS_RESOLVED : Cpt::STATUS_OPEN,
			'resolved_by' => $resolved ? $this->principals->idFor($resolvedBy) : null,
			'resolved_at' => $resolved ? ExportFormat::utc((string) get_post_meta($id, Cpt::META_RESOLVED_AT, true)) : null,
		];

		$this->files->write($this->docPath('comments/' . $id . '.md'), ExportFormat::page(self::ordered(self::COMMENT_KEYS, $frontmatter), (string) $row['post_content']));
	}

	/**
	 * compiled/<post id>.md: the posts compile() and assemble() write.
	 *
	 * @return int Records written.
	 */
	private function writeCompiled(): int {
		global $wpdb;

		$skip   = self::NOT_RECORDS;
		$in     = implode(', ', array_fill(0, count($skip), '%s'));
		$lastId = 0;
		$count  = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$rows = Db::getResults(
				"SELECT p.ID, p.post_status, p.post_title, p.post_content, p.post_date_gmt, p.post_modified_gmt, MIN(m.meta_value) AS compiled_from
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
					WHERE p.ID > %d AND p.post_type = %s AND p.post_status NOT IN ({$in})
					GROUP BY p.ID
					ORDER BY p.ID ASC
					LIMIT %d",
				array_merge([Cpts::META_COMPILED_FROM, $lastId, 'post'], $skip, [self::BATCH])
			);
			// phpcs:enable

			foreach ($rows as $row) {
				$lastId = (int) $row['ID'];
				$this->files->tick();

				$artifact = (int) $row['compiled_from'];
				$exported = isset($this->artifactPartitions[$artifact]);
				$recorded = self::recorded($row);

				$frontmatter = [
					'title'       => self::title((string) $row['post_title'], 'Compiled ' . $lastId),
					'id'          => 'compiled-' . $lastId,
					'artifact'    => $exported ? 'artifact-' . $artifact : null,
					'partition'   => $exported ? $this->artifactPartitions[$artifact] : Partitions::DEFAULT_NAME,
					'status'      => (string) $row['post_status'],
					'recorded_at' => $recorded,
					'updated'     => ExportFormat::utc((string) $row['post_modified_gmt']) ?? $recorded,
				];

				$this->files->write($this->docPath('compiled/' . $lastId . '.md'), ExportFormat::page(self::ordered(self::COMPILED_KEYS, $frontmatter), (string) $row['post_content']));
				++$count;
			}
		} while (count($rows) === self::BATCH);

		return $count;
	}

	private function docPath(string $file): string {
		return ExtensionIds::path(ExtensionIds::DOC) . $file;
	}

	// -----------------------------------------------------------------
	// Handoffs
	// -----------------------------------------------------------------

	/** @return int Handoffs written. */
	private function writeHandoffs(): int {
		$count = 0;
		foreach (array_chunk($this->posts(HandoffCpt::POST_TYPE), self::BATCH) as $rows) {
			$ids   = array_map(static fn (array $row): int => (int) $row['ID'], $rows);
			$pages = $this->handoffPages($ids);

			foreach ($rows as $row) {
				$this->files->tick();
				$this->writeHandoff($row, $pages[(int) $row['ID']] ?? []);
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param array<string, mixed>                               $row
	 * @param array<int, array{path:string, partition:string}>   $pages Linked memory pages, by row id.
	 */
	private function writeHandoff(array $row, array $pages): void {
		$id       = (int) $row['ID'];
		$recorded = self::recorded($row);
		$from     = $this->principals->idFor((int) $row['post_author']);

		$toRaw = get_post_meta($id, HandoffQueue::META_TO, true);
		$to    = $toRaw !== '' && (int) $toRaw > 0 ? (int) $toRaw : null;

		$resolution = (string) get_post_meta($id, HandoffQueue::META_STATUS, true);

		$sectionId = (int) get_post_meta($id, 'ab_handoff_section', true);
		$section   = isset($this->sections[$sectionId]) ? $sectionId : null;
		$artifact  = $section !== null ? ($this->sectionArtifact[$section] ?? null) : null;

		$partitions = array_values(array_unique(array_column($pages, 'partition')));
		sort($partitions, SORT_STRING);
		$partition = $artifact !== null ? $this->artifactPartitions[$artifact] : ($partitions[0] ?? Partitions::DEFAULT_NAME);

		$paths = array_column($pages, 'path');

		$frontmatter = [
			'title'       => self::title((string) $row['post_title'], 'Handoff ' . $id),
			'id'          => 'handoff-' . $id,
			'partition'   => $partition,
			'recorded_at' => $recorded,
			'updated'     => ExportFormat::utc((string) $row['post_modified_gmt']) ?? $recorded,
			'source'      => $from !== null ? ['origin' => 'agent', 'principal' => $from, 'at' => $recorded] : null,
			'from'        => $from,
			'to'          => $to !== null ? $this->principals->idFor($to) : null,
			'status'      => Present::handoffStatus($resolution !== '' ? $resolution : null, $to),
			'section'     => $section !== null ? 'section-' . $section : null,
			'artifact'    => $artifact !== null ? 'artifact-' . $artifact : null,
			'pages'       => $paths !== [] ? $paths : null,
		];

		$this->files->write(
			ExtensionIds::path(ExtensionIds::HANDOFF) . 'handoffs/' . $id . '.md',
			ExportFormat::page(self::ordered(self::HANDOFF_KEYS, $frontmatter), (string) $row['post_content'])
		);
	}

	/**
	 * The exported memory pages each handoff links, in row id order: the
	 * links table (derived_kind post) and ab_source_memory_ids. Rows that
	 * are not exported (deleted ones) are left out.
	 *
	 * @param array<int, int> $handoffIds
	 * @return array<int, array<int, array{path:string, partition:string}>> handoff id => pages
	 */
	private function handoffPages(array $handoffIds): array {
		if ($handoffIds === []) {
			return [];
		}

		$links = MemoryRepository::linksTable();
		$in    = implode(', ', array_fill(0, count($handoffIds), '%d'));

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$linked = Db::getResults(
			"SELECT derived_id, source_id FROM {$links} WHERE derived_kind = %s AND derived_id IN ({$in})",
			array_merge(['post'], $handoffIds)
		);
		// phpcs:enable

		$map = [];
		foreach ($linked as $link) {
			$map[(int) $link['derived_id']][(int) $link['source_id']] = true;
		}
		foreach ($handoffIds as $handoffId) {
			$legacy = get_post_meta($handoffId, HandoffQueue::META_MEMORY_IDS, true);
			foreach (is_array($legacy) ? $legacy : [] as $memoryId) {
				if (is_numeric($memoryId) && (int) $memoryId > 0) {
					$map[$handoffId][(int) $memoryId] = true;
				}
			}
		}

		$all = [];
		foreach ($map as $rows) {
			$all += $rows;
		}
		if ($all === []) {
			return [];
		}

		$ids      = array_keys($all);
		$table    = MemoryRepository::memoryTable();
		$index    = PageIndex::table();
		$statuses = PageWriter::ROW_STATUSES;
		$iin      = implode(', ', array_fill(0, count($ids), '%d'));
		$sin      = implode(', ', array_fill(0, count($statuses), '%s'));

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$found = Db::getResults(
			"SELECT m.id, m.scope_id, i.path FROM {$table} m
				INNER JOIN {$index} i ON i.object_kind = %s AND i.object_id = m.id
				WHERE m.id IN ({$iin}) AND m.status IN ({$sin})",
			array_merge([PageIndex::KIND_MEMORY], $ids, $statuses)
		);
		// phpcs:enable

		$pages = [];
		foreach ($found as $row) {
			$pages[(int) $row['id']] = ['path' => (string) $row['path'], 'partition' => $this->pages->partitionFor((int) $row['scope_id'])];
		}

		$out = [];
		foreach ($map as $handoffId => $rows) {
			$memoryIds = array_keys($rows);
			sort($memoryIds, SORT_NUMERIC);
			foreach ($memoryIds as $memoryId) {
				if (isset($pages[$memoryId])) {
					$out[$handoffId][] = $pages[$memoryId];
				}
			}
		}

		return $out;
	}

	// -----------------------------------------------------------------
	// Shared
	// -----------------------------------------------------------------

	/**
	 * Every post of a type that is a record, in id order, read in pages of
	 * 500. Artifacts, sections and handoffs are few next to pages, so the
	 * rows are held until they are written.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function posts(string $postType): array {
		global $wpdb;

		$skip   = self::NOT_RECORDS;
		$in     = implode(', ', array_fill(0, count($skip), '%s'));
		$lastId = 0;
		$out    = [];

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$rows = Db::getResults(
				"SELECT ID, post_parent, post_author, post_status, post_name, post_title, post_content, post_date_gmt, post_modified_gmt
					FROM {$wpdb->posts}
					WHERE ID > %d AND post_type = %s AND post_status NOT IN ({$in})
					ORDER BY ID ASC
					LIMIT %d",
				array_merge([$lastId, $postType], $skip, [self::BATCH])
			);
			// phpcs:enable

			foreach ($rows as $row) {
				$lastId = (int) $row['ID'];
				$out[]  = $row;
			}
		} while (count($rows) === self::BATCH);

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function recorded(array $row): string {
		return ExportFormat::utc((string) $row['post_date_gmt']) ?? ExportFormat::utc((string) $row['post_modified_gmt']) ?? '1970-01-01T00:00:00Z';
	}

	private static function title(string $title, string $fallback): string {
		return trim($title) !== '' ? ExportFormat::clip($title, 300) : $fallback;
	}

	/**
	 * @param array<int, string>   $order
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private static function ordered(array $order, array $values): array {
		$out = [];
		foreach ($order as $key) {
			if (array_key_exists($key, $values) && $values[$key] !== null) {
				$out[$key] = $values[$key];
			}
		}

		return $out;
	}
}
