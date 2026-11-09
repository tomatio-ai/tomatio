<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Artifacts\Block;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Sharing\MembersAddress;
use AgentBrain\Modules\Uploads\UploadStore;
use AgentBrain\Modules\Uploads\UploadsExport;
use AgentBrain\Modules\Comments\Cpt;
use AgentBrain\Modules\Mcp\Present;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Review\HandoffCpt;
use AgentBrain\Modules\Review\HandoffQueue;

/**
 * Rebuilds the artifacts, sections, compiled documents, handoffs and
 * comments of TomaTío's two tool extensions (ExtensionWriter,
 * docs/pcp-export.md "Extension data") from an archive a migration writes
 * (RestoreWriter), so the migrated brain serves them and its next export
 * gives the same files. With them (#547): each artifact's kind, friendly
 * address and settings, its HTML versions, its version history rows and
 * its uploads (files included). Share link records are kept, never made
 * live (Kept::KIND_SHARE): the brain lists them to create again.
 *
 * Every record keeps its post id: "artifact-12" comes back as post 12, so
 * references between records, outlines, handoff routing and the ids agents
 * already know stay as they were. The posts go straight into the tables
 * inside the restore's transaction (WritesTables), before any other post,
 * so the posts the restore adds later get ids past them.
 *
 * The records are rebuilt only when all of them can be: both layouts at
 * version 1, every file known, every record well formed, every reference,
 * principal and partition resolvable, every derived value (a section's
 * partition, a handoff's status and partition) what the export would
 * derive, and no post id already taken on this brain. Otherwise nothing is
 * rebuilt and the restore keeps the files verbatim, as before (Kept), with
 * the reason in its notes.
 */
final class ExtensionRestore {

	use WritesTables;

	public const ARTIFACT_KEYS = ['id', 'title', 'partition', 'parent', 'stage', 'status', 'share_visibility', 'archived_at', 'lead', 'sections', 'recorded_at', 'updated', 'kind', 'slug', 'old_slugs', 'settings'];
	public const HISTORY_KEYS  = ['title', 'id', 'artifact', 'partition', 'updated', 'x_written_by', 'x_written_via', 'x_written_client', 'x_written_ai'];

	private const NOT_RECORDS = ['trash', 'auto-draft', 'inherit'];

	/** @var array<int, array<string, mixed>> post id => artifact record */
	private array $artifacts = [];

	/** @var array<int, array{fm:array<string, mixed>, body:string}> */
	private array $sections = [];

	/** @var array<int, array<int, array{fm:array<string, mixed>, body:string}>> section id => n => version */
	private array $history = [];

	/** @var array<int, array{fm:array<string, mixed>, body:string}> */
	private array $compiled = [];

	/** @var array<int, array{fm:array<string, mixed>, body:string}> */
	private array $handoffs = [];

	/** @var array<int, array{fm:array<string, mixed>, body:string}> */
	private array $comments = [];

	/** @var array<int, array<int, array<string, mixed>>> artifact id => version => HTML version record */
	private array $html = [];

	/** @var array<int, array<int, array<string, mixed>>> artifact id => seq => version history record */
	private array $snapshots = [];

	/** @var array<int, array{record:array<string, mixed>, path:string}> link id => share link record */
	private array $shares = [];

	/** @var array<int, array<string, mixed>> upload id => upload record */
	private array $uploads = [];

	/** @var array<string, string> "<artifact id>/<stored name>" => archive path of the bytes */
	private array $uploadBytes = [];

	/** @var array<int, string> Upload files written, removed again if the migration is undone. */
	private array $writtenFiles = [];

	/** @var array<int, true> Artifact ids of the archive left behind (#549). */
	private array $leftArtifacts = [];

	/** Records left behind (#549). */
	private int $left = 0;

	/** #550: post meta naming where a moved artifact or handoff came from, "<source controller>|<record>". */
	public const META_SOURCE = 'ab_pcp_source';

	/** @var array<int, int> archive post id => post id here (the same id unless moving) */
	private array $ids = [];

	/** Artifacts and handoffs this brain already has from an earlier move of the same archive (#550). */
	private int $duplicates = 0;

	/** @var array<int, string> The archive files this rebuilds. */
	private array $files = [];

	private ?string $reason = null;

	/** @var array<string, true> principal ids a record may name */
	private array $principals = [];

	/** @var array<string, true> partition names */
	private array $partitions = [];

	/** @var array<string, string> memory row page path => partition */
	private array $rowPages = [];

	/**
	 * @param array<string, mixed> $check     ArchiveCheck::run() result, without errors (and with Selection::apply()).
	 * @param Selection|null       $selection What the migration leaves behind (#549).
	 * @param array{source:string, keep_approved:bool}|null $move Moving scopes into a brain in use (#550): every record gets a new id here, references follow, and what this brain already has from the same source is skipped.
	 */
	public function __construct(private string $dir, private array $check, ?Selection $selection = null, private ?array $move = null) {
		foreach ((array) $check['context']['principals'] as $principal) {
			$id = (string) ($principal['id'] ?? '');
			if (str_starts_with($id, 'human:') || str_starts_with($id, 'agent:')) {
				$this->principals[$id] = true;
			}
		}
		foreach ((array) $check['context']['partitions'] as $partition) {
			$this->partitions[(string) $partition['name']] = true;
		}
		foreach ($check['pages'] as $path => $page) {
			if (RestoreWriter::isRowPage($page['frontmatter'])) {
				$this->rowPages[(string) $path] = (string) ($page['frontmatter']['partition'] ?? Partitions::DEFAULT_NAME);
			}
		}

		try {
			$this->read();
			if ($selection !== null && ! $selection->isEmpty()) {
				$this->leaveBehind($selection);
			}
			if ($this->move !== null) {
				$this->skipMoved();
			}
			$this->verify();
			if ($this->move === null) {
				$this->checkFreeIds();
			}
		} catch (\UnexpectedValueException $e) {
			$this->reason = $e->getMessage();
		}
		if ($this->reason !== null) {
			$this->artifacts = $this->sections = $this->history = $this->compiled = $this->handoffs = $this->comments = [];
			$this->html      = $this->snapshots = $this->shares = $this->uploads = $this->uploadBytes = [];
			$this->files     = [];
		}
	}

	/** True when the archive has TomaTío's documents or handoffs and they are rebuilt. */
	public function rebuilds(): bool {
		return $this->reason === null && $this->files !== [];
	}

	/** Why the records are kept as files instead, or null. */
	public function reason(): ?string {
		return $this->reason;
	}

	/** @return array<int, string> The archive files the rebuilt records stand for. */
	public function files(): array {
		return $this->files;
	}

	/** Records rebuilt: artifacts, sections, compiled documents, handoffs, comments, HTML versions, version history rows and uploads. */
	public function records(): int {
		return count($this->artifacts) + count($this->sections) + count($this->compiled) + count($this->handoffs) + count($this->comments)
			+ array_sum(array_map('count', $this->html)) + array_sum(array_map('count', $this->snapshots)) + count($this->uploads);
	}

	/** @return array<int, true> Artifact ids of the archive left behind (#549). */
	public function leftArtifactIds(): array {
		return $this->leftArtifacts;
	}

	/** Records left behind (#549). */
	public function leftRecords(): int {
		return $this->left;
	}

	/**
	 * Every person and agent the records name (#550: a move gives accounts
	 * to these, not to everyone in the archive).
	 *
	 * @return array<string, true>
	 */
	public function referencedPrincipals(): array {
		$out = [];
		$add = static function ($value) use (&$out): void {
			if (is_string($value) && $value !== '') {
				$out[$value] = true;
			}
		};
		foreach ($this->artifacts as $r) {
			$add($r['lead'] ?? null);
		}
		foreach ($this->sections as $section) {
			$add($section['fm']['owner'] ?? null);
			$add($section['fm']['last_writer'] ?? null);
			foreach ((array) ($section['fm']['review_log'] ?? []) as $entry) {
				$add(is_array($entry) ? ($entry['by'] ?? null) : null);
			}
		}
		foreach ($this->handoffs as $handoff) {
			$add($handoff['fm']['from'] ?? null);
			$add($handoff['fm']['to'] ?? null);
		}
		foreach ($this->comments as $comment) {
			$add($comment['fm']['author'] ?? null);
			$add($comment['fm']['resolved_by'] ?? null);
		}
		foreach ($this->html as $versions) {
			foreach ($versions as $r) {
				$add($r['created_by'] ?? null);
			}
		}
		foreach ($this->snapshots as $rows) {
			foreach ($rows as $r) {
				$add($r['by'] ?? null);
			}
		}
		foreach ($this->uploads as $r) {
			$add($r['uploaded_by'] ?? null);
		}

		return $out;
	}

	/** Artifacts and handoffs skipped because an earlier move brought them (#550). */
	public function duplicates(): int {
		return $this->duplicates;
	}

	/** The post id here of an archive post id (0 when it was not written). */
	public function to(int $old): int {
		return $old > 0 ? ($this->ids[$old] ?? ($this->move === null ? $old : 0)) : 0;
	}

	/** @return array<int, true> The artifact ids rebuilt (none when nothing is). */
	public function artifactIds(): array {
		return $this->rebuilds() ? array_fill_keys(array_keys($this->artifacts), true) : [];
	}

	/** @return array<string, true> The people and agents of the archive. */
	public function principalIds(): array {
		return $this->principals;
	}

	/** Share links of the archive, kept to be created again. */
	public function shares(): int {
		return count($this->shares);
	}

	/** @return array<int, string> Upload files written to disk. */
	public function writtenFiles(): array {
		return $this->writtenFiles;
	}

	/** Whether an archive path is in one of the two extensions this rebuilds. */
	public static function owns(string $path): bool {
		return str_starts_with($path, ExtensionIds::path(ExtensionIds::DOC)) || str_starts_with($path, ExtensionIds::path(ExtensionIds::HANDOFF));
	}

	// -----------------------------------------------------------------
	// Reading and checking
	// -----------------------------------------------------------------

	private function read(): void {
		$doc     = ExtensionIds::path(ExtensionIds::DOC);
		$handoff = ExtensionIds::path(ExtensionIds::HANDOFF);

		foreach ($this->check['extensions'] as $entry) {
			$id = (string) ($entry['id'] ?? '');
			if ($id !== ExtensionIds::DOC && $id !== ExtensionIds::HANDOFF) {
				continue;
			}
			if ((string) ($entry['version'] ?? '') !== ExtensionIds::LAYOUT_VERSION || (string) ($entry['slot'] ?? '') !== ($id === ExtensionIds::DOC ? 'doc' : 'handoff')) {
				self::fail(sprintf('the extension %s has a layout this version does not read', $id));
			}
		}

		foreach ($this->check['extension_files'] as $path) {
			if (! self::owns($path)) {
				continue;
			}
			$this->files[] = $path;

			if (preg_match('#^' . preg_quote($doc, '#') . 'artifacts/([1-9][0-9]{0,17})\.json$#', $path, $m) === 1) {
				$record = json_decode((string) file_get_contents($this->dir . '/' . $path), true);
				if (! is_array($record) || array_is_list($record)) {
					self::fail($path . ' is not a JSON object');
				}
				$this->artifacts[(int) $m[1]] = $record;
			} elseif (preg_match('#^' . preg_quote($doc, '#') . 'sections/([1-9][0-9]{0,17})\.md$#', $path, $m) === 1) {
				$this->sections[(int) $m[1]] = $this->page($path);
			} elseif (preg_match('#^' . preg_quote($doc, '#') . 'history/sections/([1-9][0-9]{0,17})/([1-9][0-9]{0,8})\.md$#', $path, $m) === 1) {
				$this->history[(int) $m[1]][(int) $m[2]] = $this->page($path);
			} elseif (preg_match('#^' . preg_quote($doc, '#') . 'compiled/([1-9][0-9]{0,17})\.md$#', $path, $m) === 1) {
				$this->compiled[(int) $m[1]] = $this->page($path);
			} elseif (preg_match('#^' . preg_quote($handoff, '#') . 'handoffs/([1-9][0-9]{0,17})\.md$#', $path, $m) === 1) {
				$this->handoffs[(int) $m[1]] = $this->page($path);
			} elseif (preg_match('#^' . preg_quote($doc, '#') . 'comments/([1-9][0-9]{0,17})\.md$#', $path, $m) === 1) {
				$this->comments[(int) $m[1]] = $this->page($path);
			} elseif (preg_match('#^' . preg_quote($doc, '#') . ArtifactRecords::DIR_HTML . '([1-9][0-9]{0,17})/([1-9][0-9]{0,8})\.json$#', $path, $m) === 1) {
				$this->html[(int) $m[1]][(int) $m[2]] = $this->json($path);
			} elseif (preg_match('#^' . preg_quote($doc, '#') . ArtifactRecords::DIR_SNAPSHOTS . '([1-9][0-9]{0,17})/([1-9][0-9]{0,8})\.json$#', $path, $m) === 1) {
				$this->snapshots[(int) $m[1]][(int) $m[2]] = $this->json($path);
			} elseif (preg_match('#^' . preg_quote($doc, '#') . ArtifactRecords::DIR_SHARES . '([1-9][0-9]{0,17})\.json$#', $path, $m) === 1) {
				$this->shares[(int) $m[1]] = ['record' => $this->json($path), 'path' => $path];
			} elseif (preg_match('#^' . preg_quote($doc, '#') . 'uploads/([1-9][0-9]{0,17})/([1-9][0-9]{0,17})\.json$#', $path, $m) === 1) {
				$this->uploads[(int) $m[2]] = $this->json($path) + ['_folder' => (int) $m[1]];
			} elseif (preg_match('#^' . preg_quote($doc, '#') . 'uploads/([1-9][0-9]{0,17})/files/([A-Za-z0-9._-]{1,64})$#', $path, $m) === 1) {
				$this->uploadBytes[$m[1] . '/' . $m[2]] = $path;
			} else {
				self::fail($path . ' is not a file this version reads');
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		$record = json_decode((string) file_get_contents($this->dir . '/' . $path), true);
		if (! is_array($record) || array_is_list($record)) {
			self::fail($path . ' is not a JSON object');
		}

		return $record;
	}

	/**
	 * @return array{fm:array<string, mixed>, body:string}
	 */
	private function page(string $path): array {
		try {
			$page = PageFile::parse((string) file_get_contents($this->dir . '/' . $path));
		} catch (\InvalidArgumentException $e) {
			self::fail($path . ': ' . $e->getMessage());
		}

		return ['fm' => $page['frontmatter'], 'body' => $page['body']];
	}

	/**
	 * Drops the records a migration leaves behind (#549): the artifacts in
	 * those partitions with everything of theirs, and the handoffs there.
	 * A kept artifact nested in one left behind is no longer nested, and a
	 * handoff loses the links to pages left behind.
	 */
	private function leaveBehind(Selection $selection): void {
		foreach ($this->artifacts as $id => $r) {
			if ($selection->leavesPartition((string) ($r['partition'] ?? ''))) {
				$this->leftArtifacts[$id] = true;
			}
		}
		$gone = fn ($ref): bool => is_string($ref) && preg_match('/^artifact-([1-9][0-9]{0,17})$/', $ref, $m) === 1 && isset($this->leftArtifacts[(int) $m[1]]);

		$before = $this->records() + count($this->shares);
		foreach (array_keys($this->leftArtifacts) as $id) {
			unset($this->artifacts[$id], $this->html[$id], $this->snapshots[$id]);
		}
		foreach ($this->artifacts as $id => $r) {
			if ($gone($r['parent'] ?? null)) {
				unset($this->artifacts[$id]['parent']);
			}
		}
		// Records with no artifact are in the default partition.
		$orphansGo = $selection->leavesPartition(Partitions::DEFAULT_NAME);
		foreach ($this->sections as $id => $section) {
			if ($gone($section['fm']['artifact'] ?? null) || ($orphansGo && ! isset($section['fm']['artifact']))) {
				unset($this->sections[$id], $this->history[$id]);
			}
		}
		foreach (['compiled', 'comments'] as $kind) {
			foreach ($this->{$kind} as $id => $record) {
				if ($gone($record['fm']['artifact'] ?? null) || ($orphansGo && ! isset($record['fm']['artifact']))) {
					unset($this->{$kind}[$id]);
				}
			}
		}
		foreach ($this->shares as $id => $share) {
			if ($gone($share['record']['artifact'] ?? null)) {
				unset($this->shares[$id]);
			}
		}
		foreach ($this->uploads as $id => $record) {
			if (isset($this->leftArtifacts[(int) $record['_folder']])) {
				unset($this->uploads[$id]);
			}
		}
		foreach ($this->handoffs as $id => $handoff) {
			if ($selection->leavesPartition((string) ($handoff['fm']['partition'] ?? ''))) {
				unset($this->handoffs[$id]);
				continue;
			}
			if (isset($handoff['fm']['pages'])) {
				$pages = array_values(array_filter((array) $handoff['fm']['pages'], fn ($path): bool => is_string($path) && isset($this->rowPages[$path])));
				if ($pages === []) {
					unset($this->handoffs[$id]['fm']['pages']);
				} else {
					$this->handoffs[$id]['fm']['pages'] = $pages;
				}
			}
		}
		$this->left = $before - $this->records() - count($this->shares);
	}

	/**
	 * #550: artifacts (with everything of theirs) and handoffs this brain
	 * already has from the same source are not moved again.
	 */
	private function skipMoved(): void {
		$source = (string) ($this->move['source'] ?? '');
		$keys   = [];
		foreach (array_keys($this->artifacts) as $id) {
			$keys[$source . '|artifact-' . $id] = ['artifact', $id];
		}
		foreach (array_keys($this->handoffs) as $id) {
			$keys[$source . '|handoff-' . $id] = ['handoff', $id];
		}
		if ($keys === []) {
			return;
		}

		global $wpdb;
		$present = [];
		foreach (array_chunk(array_keys($keys), 500) as $chunk) {
			$in = implode(', ', array_fill(0, count($chunk), '%s'));
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			foreach (Db::getResults("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ({$in})", array_merge([self::META_SOURCE], $chunk)) as $row) {
				$present[(string) $row['meta_value']] = true;
			}
			// phpcs:enable
		}

		$artifacts = [];
		foreach ($keys as $key => [$kind, $id]) {
			if (! isset($present[$key])) {
				continue;
			}
			++$this->duplicates;
			if ($kind === 'handoff') {
				unset($this->handoffs[$id]);
			} else {
				$artifacts[$id] = true;
			}
		}
		if ($artifacts === []) {
			return;
		}

		$gone = static fn ($ref): bool => is_string($ref) && preg_match('/^artifact-([1-9][0-9]{0,17})$/', $ref, $m) === 1 && isset($artifacts[(int) $m[1]]);
		foreach (array_keys($artifacts) as $id) {
			unset($this->artifacts[$id], $this->html[$id], $this->snapshots[$id]);
			$this->leftArtifacts[$id] = true;
		}
		foreach ($this->artifacts as $id => $r) {
			if ($gone($r['parent'] ?? null)) {
				unset($this->artifacts[$id]['parent']);
			}
		}
		foreach ($this->sections as $id => $section) {
			if ($gone($section['fm']['artifact'] ?? null)) {
				unset($this->sections[$id], $this->history[$id]);
			}
		}
		foreach (['compiled', 'comments'] as $kind) {
			foreach ($this->{$kind} as $id => $record) {
				if ($gone($record['fm']['artifact'] ?? null)) {
					unset($this->{$kind}[$id]);
				}
			}
		}
		foreach ($this->shares as $id => $share) {
			if ($gone($share['record']['artifact'] ?? null)) {
				unset($this->shares[$id]);
			}
		}
		foreach ($this->uploads as $id => $record) {
			if (isset($artifacts[(int) $record['_folder']])) {
				unset($this->uploads[$id]);
			}
		}
		foreach ($this->handoffs as $id => $handoff) {
			$section = isset($handoff['fm']['section']) ? (int) substr((string) $handoff['fm']['section'], 8) : 0;
			if ($section > 0 && ! isset($this->sections[$section])) {
				unset($this->handoffs[$id]['fm']['section'], $this->handoffs[$id]['fm']['artifact']);
			}
		}
	}

	private function verify(): void {
		$seen = [];
		foreach (['artifact' => $this->artifacts, 'section' => $this->sections, 'compiled' => $this->compiled, 'handoff' => $this->handoffs, 'comment' => $this->comments] as $kind => $records) {
			foreach (array_keys($records) as $id) {
				if (isset($seen[$id])) {
					self::fail(sprintf('%s-%d and %s-%d would be the same post', $seen[$id], $id, $kind, $id));
				}
				$seen[$id] = $kind;
			}
		}

		foreach ($this->artifacts as $id => $record) {
			$this->verifyArtifact($id, $record);
		}
		foreach ($this->sections as $id => $section) {
			$this->verifySection($id, $section['fm']);
		}
		foreach ($this->history as $id => $versions) {
			$this->verifyHistory($id, $versions);
		}
		foreach ($this->compiled as $id => $compiled) {
			$this->verifyCompiled($id, $compiled['fm']);
		}
		foreach ($this->handoffs as $id => $handoff) {
			$this->verifyHandoff($id, $handoff['fm']);
		}
		foreach ($this->comments as $id => $comment) {
			$this->verifyComment($id, $comment['fm']);
		}
		foreach ($this->html as $artifact => $versions) {
			foreach ($versions as $n => $record) {
				$this->verifyHtml($artifact, $n, $record);
			}
		}
		foreach ($this->snapshots as $artifact => $rows) {
			foreach ($rows as $seq => $record) {
				$this->verifySnapshot($artifact, $seq, $record);
			}
		}
		foreach ($this->shares as $id => $share) {
			$this->verifyShare($id, $share['record']);
		}
		foreach ($this->uploads as $id => $record) {
			$this->verifyUpload($id, $record);
		}
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private function verifyArtifact(int $id, array $r): void {
		$name = 'artifact-' . $id;
		$this->keys($name, $r, self::ARTIFACT_KEYS);
		$this->equals($name, 'id', $r, $name);
		$this->text($name, 'title', $r);
		$this->partition($name, $r);
		$this->ref($name, 'parent', $r, 'artifact', $this->artifacts, true);
		$this->oneOf($name, 'stage', $r, Cpts::stages());
		$this->status($name, $r);
		$this->text($name, 'share_visibility', $r, true);
		$this->date($name, 'archived_at', $r, true);
		$this->principal($name, 'lead', $r);
		$this->date($name, 'recorded_at', $r);
		$this->date($name, 'updated', $r);

		if (array_key_exists('kind', $r)) {
			$this->oneOf($name, 'kind', $r, [HtmlFiles::KIND_HTML]);
		}
		$this->text($name, 'slug', $r, true);
		if (array_key_exists('old_slugs', $r) && (! is_array($r['old_slugs']) || $r['old_slugs'] === [] || ! array_is_list($r['old_slugs']) || in_array(false, array_map(static fn ($slug): bool => is_string($slug) && $slug !== '', $r['old_slugs']), true))) {
			self::fail($name . ': old_slugs is not a list of slugs');
		}
		if (array_key_exists('settings', $r)) {
			$settings = $r['settings'];
			if (! is_array($settings) || $settings === [] || array_is_list($settings)) {
				self::fail($name . ': settings is not an object');
			}
			foreach ($settings as $setting => $value) {
				if (! isset(ArtifactRecords::SETTINGS[$setting]) || ! is_string($value)) {
					self::fail(sprintf('%s: the setting %s is not one this version knows', $name, (string) $setting));
				}
			}
		}

		$outline = $r['sections'] ?? null;
		if (! is_array($outline) || ! array_is_list($outline) || count(array_unique($outline, SORT_REGULAR)) !== count($outline)) {
			self::fail($name . ': sections is not a list of section records');
		}
		foreach ($outline as $section) {
			if (! is_string($section) || preg_match('/^section-([1-9][0-9]{0,17})$/', $section, $m) !== 1 || ! isset($this->sections[(int) $m[1]])) {
				self::fail($name . ': its outline names a section the archive does not have');
			}
		}
	}

	/**
	 * @param array<string, mixed> $fm
	 */
	private function verifySection(int $id, array $fm): void {
		$name = 'section-' . $id;
		$this->keys($name, $fm, ExtensionWriter::SECTION_KEYS);
		$this->equals($name, 'id', $fm, $name);
		$this->text($name, 'title', $fm);
		$artifact = $this->ref($name, 'artifact', $fm, 'artifact', $this->artifacts, true);
		$this->equals($name, 'partition', $fm, $artifact !== null ? (string) $this->artifacts[$artifact]['partition'] : Partitions::DEFAULT_NAME);
		$this->date($name, 'recorded_at', $fm);
		$this->date($name, 'updated', $fm);
		$this->principal($name, 'owner', $fm);
		$this->oneOf($name, 'state', $fm, Cpts::sectionStates());
		if (! is_int($fm['version'] ?? null) || $fm['version'] < 1) {
			self::fail($name . ': version is not a whole number from 1');
		}
		$this->principal($name, 'last_writer', $fm);
		$this->text($name, 'last_writer_client', $fm, true);
		$this->text($name, 'brief', $fm, true);

		if (array_key_exists('allowed_blocks', $fm)) {
			$blocks = $fm['allowed_blocks'];
			if (! is_array($blocks) || $blocks === [] || ! array_is_list($blocks) || in_array(false, array_map(static fn ($b): bool => is_string($b) && $b !== '', $blocks), true)) {
				self::fail($name . ': allowed_blocks is not a list of block names');
			}
		}

		if (array_key_exists('review_log', $fm)) {
			$log = $fm['review_log'];
			if (! is_array($log) || $log === [] || ! array_is_list($log)) {
				self::fail($name . ': review_log is not a list');
			}
			foreach ($log as $entry) {
				if (! is_array($entry) || $entry === [] || array_is_list($entry)) {
					self::fail($name . ': a review_log entry is not an object');
				}
				$this->keys($name . ' review_log', $entry, ['version', 'by', 'verdict', 'notes', 'at']);
				if (array_key_exists('version', $entry) && ! is_int($entry['version'])) {
					self::fail($name . ': a review_log version is not a whole number');
				}
				$this->principal($name . ' review_log', 'by', $entry);
				$this->text($name . ' review_log', 'verdict', $entry, true, true);
				$this->text($name . ' review_log', 'notes', $entry, true);
				$this->date($name . ' review_log', 'at', $entry, true);
			}
		}
	}

	/**
	 * @param array<int, array{fm:array<string, mixed>, body:string}> $versions
	 */
	private function verifyHistory(int $id, array $versions): void {
		$name = 'section-' . $id;
		if (! isset($this->sections[$id])) {
			self::fail('history of ' . $name . ', which the archive does not have');
		}
		ksort($versions, SORT_NUMERIC);
		if (array_keys($versions) !== range(1, count($versions))) {
			self::fail('the history of ' . $name . ' is not numbered 1, 2, 3 and so on');
		}

		$section = $this->sections[$id]['fm'];
		foreach ($versions as $n => $version) {
			$fm    = $version['fm'];
			$label = $name . ' version ' . $n;
			$this->keys($label, $fm, self::HISTORY_KEYS);
			$this->equals($label, 'id', $fm, $name);
			$this->text($label, 'title', $fm);
			$this->equals($label, 'artifact', $fm, $section['artifact'] ?? null);
			$this->equals($label, 'partition', $fm, $section['partition']);
			$this->date($label, 'updated', $fm);
		}
		$this->history[$id] = $versions;
	}

	/**
	 * @param array<string, mixed> $fm
	 */
	private function verifyCompiled(int $id, array $fm): void {
		$name = 'compiled-' . $id;
		$this->keys($name, $fm, ExtensionWriter::COMPILED_KEYS);
		$this->equals($name, 'id', $fm, $name);
		$this->text($name, 'title', $fm);
		$artifact = $this->ref($name, 'artifact', $fm, 'artifact', $this->artifacts, true);
		$this->equals($name, 'partition', $fm, $artifact !== null ? (string) $this->artifacts[$artifact]['partition'] : Partitions::DEFAULT_NAME);
		$this->status($name, $fm);
		$this->date($name, 'recorded_at', $fm);
		$this->date($name, 'updated', $fm);
	}

	/**
	 * @param array<string, mixed> $fm
	 */
	private function verifyHandoff(int $id, array $fm): void {
		$name = 'handoff-' . $id;
		$this->keys($name, $fm, ExtensionWriter::HANDOFF_KEYS);
		$this->equals($name, 'id', $fm, $name);
		$this->text($name, 'title', $fm);
		$this->date($name, 'recorded_at', $fm);
		$this->date($name, 'updated', $fm);
		$from = $this->principal($name, 'from', $fm);
		$to   = $this->principal($name, 'to', $fm);
		$this->equals($name, 'source', $fm, $from !== null ? ['origin' => 'agent', 'principal' => $from, 'at' => $fm['recorded_at']] : null);

		$status = $this->oneOf($name, 'status', $fm, [Present::HANDOFF_PENDING_REVIEW, Present::HANDOFF_ASSIGNED, Present::HANDOFF_RESOLVED, Present::HANDOFF_DISMISSED]);
		if (($status === Present::HANDOFF_ASSIGNED && $to === null) || ($status === Present::HANDOFF_PENDING_REVIEW && $to !== null)) {
			self::fail($name . ': its status does not match its recipient');
		}

		$section  = $this->ref($name, 'section', $fm, 'section', $this->sections, true);
		$artifact = $section !== null ? ($this->sections[$section]['fm']['artifact'] ?? null) : null;
		$this->equals($name, 'artifact', $fm, $artifact);

		$pages      = $fm['pages'] ?? [];
		$partitions = [];
		$order      = [];
		if (! is_array($pages) || ! array_is_list($pages) || (array_key_exists('pages', $fm) && $pages === [])) {
			self::fail($name . ': pages is not a list of paths');
		}
		foreach ($pages as $path) {
			if (! is_string($path) || ! isset($this->rowPages[$path])) {
				self::fail($name . ': it links a page that is not a memory row of the archive');
			}
			$partitions[] = $this->partitionOf($this->rowPages[$path]);
			$order[]      = self::rowNumber((string) ($this->check['pages'][$path]['frontmatter']['id'] ?? ''));
		}
		$sorted = $order;
		sort($sorted, SORT_NUMERIC);
		if ($sorted !== $order || count(array_unique($order)) !== count($order) || in_array(0, $order, true)) {
			self::fail($name . ': its pages are not in the order this brain keeps them');
		}

		$partitions = array_values(array_unique($partitions));
		sort($partitions, SORT_STRING);
		$expected = $artifact !== null ? (string) $this->artifacts[(int) substr((string) $artifact, 9)]['partition'] : ($partitions[0] ?? Partitions::DEFAULT_NAME);
		$this->equals($name, 'partition', $fm, $expected);
	}

	/**
	 * @param array<string, mixed> $fm
	 */
	private function verifyComment(int $id, array $fm): void {
		$name = 'comment-' . $id;
		$this->keys($name, $fm, ExtensionWriter::COMMENT_KEYS);
		$this->equals($name, 'id', $fm, $name);
		$this->text($name, 'title', $fm);
		$artifact = $this->ref($name, 'artifact', $fm, 'artifact', $this->artifacts, true);
		$this->equals($name, 'partition', $fm, $artifact !== null ? (string) $this->artifacts[$artifact]['partition'] : Partitions::DEFAULT_NAME);
		$this->date($name, 'recorded_at', $fm);
		$this->date($name, 'updated', $fm);
		$author = $this->principal($name, 'author', $fm);
		$this->equals($name, 'source', $fm, $author !== null ? ['origin' => str_starts_with($author, 'human:') ? 'user' : 'agent', 'principal' => $author, 'at' => $fm['recorded_at']] : null);

		if (! is_int($fm['version'] ?? null) || $fm['version'] < 1) {
			self::fail($name . ': version is not a whole number from 1');
		}
		$this->ref($name, 'section', $fm, 'section', $this->sections, true);
		$this->text($name, 'element', $fm, true, true);
		$parent = $this->ref($name, 'parent', $fm, 'comment', $this->comments, true);
		if ($parent !== null && $parent === $id) {
			self::fail($name . ': its parent is itself');
		}

		$status = $this->oneOf($name, 'status', $fm, Cpt::statuses());
		$resolvedBy = $this->principal($name, 'resolved_by', $fm);
		$this->date($name, 'resolved_at', $fm, true);
		if ($status === Cpt::STATUS_RESOLVED && ($resolvedBy === null || ! array_key_exists('resolved_at', $fm))) {
			self::fail($name . ': a resolved comment names no resolver or time');
		}
		if ($status === Cpt::STATUS_OPEN && (array_key_exists('resolved_by', $fm) || array_key_exists('resolved_at', $fm))) {
			self::fail($name . ': an open comment carries resolution state');
		}
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private function verifyHtml(int $artifact, int $n, array $r): void {
		$name = 'html-' . $artifact . '-' . $n;
		$this->keys($name, $r, ArtifactRecords::HTML_KEYS);
		$this->equals($name, 'id', $r, $name);
		$this->equals($name, 'artifact', $r, 'artifact-' . $artifact);
		if (! isset($this->artifacts[$artifact])) {
			self::fail($name . ': its artifact is not in the archive');
		}
		$this->equals($name, 'partition', $r, (string) $this->artifacts[$artifact]['partition']);
		$this->equals($name, 'version', $r, $n);
		$this->text($name, 'created_at', $r, true);
		$this->principal($name, 'created_by', $r);
		$this->text($name, 'note', $r, true);

		$files = $r['files'] ?? null;
		if (! is_array($files) || ($files !== [] && array_is_list($files))) {
			self::fail($name . ': files is not an object');
		}
		foreach ($files as $path => $file) {
			if (! HtmlFiles::isValidPath((string) $path) || ! is_array($file) || array_keys($file) !== ['type', 'enc', 'size', 'data']
				|| ! is_string($file['type']) || ! in_array($file['enc'], ['utf8', 'base64'], true) || ! is_int($file['size']) || $file['size'] < 0 || ! is_string($file['data'])) {
				self::fail(sprintf('%s: the file %s is not one this version reads', $name, substr((string) $path, 0, 120)));
			}
		}
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private function verifySnapshot(int $artifact, int $seq, array $r): void {
		$name = 'snapshot-' . $artifact . '-' . $seq;
		$this->keys($name, $r, ArtifactRecords::SNAPSHOT_KEYS);
		$this->equals($name, 'id', $r, $name);
		$this->equals($name, 'artifact', $r, 'artifact-' . $artifact);
		if (! isset($this->artifacts[$artifact])) {
			self::fail($name . ': its artifact is not in the archive');
		}
		$this->equals($name, 'partition', $r, (string) $this->artifacts[$artifact]['partition']);
		$this->equals($name, 'seq', $r, $seq);
		$this->text($name, 'kind', $r);
		$this->ref($name, 'section', $r, 'section', $this->sections, true);
		foreach (['section_version', 'html_version'] as $key) {
			if (array_key_exists($key, $r) && (! is_int($r[$key]) || $r[$key] < 0)) {
				self::fail(sprintf('%s: %s is not a whole number', $name, $key));
			}
		}
		foreach (['title', 'content', 'client', 'note'] as $key) {
			$this->text($name, $key, $r, true, true);
		}
		$this->principal($name, 'by', $r);
		$this->date($name, 'at', $r, true);
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private function verifyShare(int $id, array $r): void {
		$name = 'share-' . $id;
		$this->keys($name, $r, ArtifactRecords::SHARE_KEYS);
		$this->equals($name, 'id', $r, $name);
		$artifact = $this->ref($name, 'artifact', $r, 'artifact', $this->artifacts, false);
		$this->equals($name, 'partition', $r, (string) $this->artifacts[(int) $artifact]['partition']);
		$this->principal($name, 'created_by', $r);
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private function verifyUpload(int $id, array $r): void {
		$name   = 'upload-' . $id;
		$folder = (int) $r['_folder'];
		unset($r['_folder']);
		$this->keys($name, $r, UploadsExport::RECORD_KEYS);
		$this->equals($name, 'id', $r, $name);
		$this->equals($name, 'artifact', $r, 'artifact-' . $folder);
		if (! isset($this->artifacts[$folder])) {
			self::fail($name . ': its artifact is not in the archive');
		}
		$this->equals($name, 'partition', $r, (string) $this->artifacts[$folder]['partition']);
		$this->text($name, 'name', $r);
		$this->text($name, 'mime', $r);
		if (! is_int($r['size'] ?? null) || $r['size'] < 1) {
			self::fail($name . ': size is not a whole number from 1');
		}
		$file = $r['file'] ?? null;
		if (! is_string($file) || preg_match('#^files/([A-Za-z0-9._-]{1,64})$#', $file, $m) !== 1 || ! isset($this->uploadBytes[$folder . '/' . $m[1]])) {
			self::fail($name . ': its file is not in the archive');
		}
		$this->principal($name, 'uploaded_by', $r);
		$this->date($name, 'recorded_at', $r);
	}

	/**
	 * @throws \UnexpectedValueException Always: the records are kept as files, for this reason.
	 */
	private static function fail(string $reason): never {
		throw new \UnexpectedValueException($reason); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a note for the report, never HTML.
	}

	/** The partition a restored record ends up in: its own if it is a scope, else default. */
	private function partitionOf(string $name): string {
		return isset($this->partitions[$name]) ? $name : Partitions::DEFAULT_NAME;
	}

	private static function rowNumber(string $pcpId): int {
		return preg_match('/^ab-memory-([1-9][0-9]{0,17})$/', $pcpId, $m) === 1 ? (int) $m[1] : 0;
	}

	/**
	 * @param array<string, mixed> $r
	 * @param array<int, string>   $allowed
	 */
	private function keys(string $name, array $r, array $allowed): void {
		$unknown = array_diff(array_keys($r), $allowed);
		if ($unknown !== []) {
			self::fail(sprintf('%s: unknown key %s', $name, (string) reset($unknown)));
		}
	}

	/**
	 * @param array<string, mixed> $r
	 * @param mixed                $expected
	 */
	private function equals(string $name, string $key, array $r, $expected): void {
		if (($r[$key] ?? null) !== $expected) {
			self::fail(sprintf('%s: %s is not what this brain would give back', $name, $key));
		}
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private function text(string $name, string $key, array $r, bool $optional = false, bool $emptyOk = false): void {
		if ($optional && ! array_key_exists($key, $r)) {
			return;
		}
		if (! is_string($r[$key] ?? null) || (! $emptyOk && trim($r[$key]) === '')) {
			self::fail(sprintf('%s: %s is not text', $name, $key));
		}
	}

	/**
	 * Dates are UTC to the second, as the export writes them, so that they
	 * come back as the same text.
	 *
	 * @param array<string, mixed> $r
	 */
	private function date(string $name, string $key, array $r, bool $optional = false): void {
		if ($optional && ! array_key_exists($key, $r)) {
			return;
		}
		$value = $r[$key] ?? null;
		if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1 || ExportFormat::utc(self::mysql($value)) !== $value) {
			self::fail(sprintf('%s: %s is not a UTC time', $name, $key));
		}
	}

	/**
	 * @param array<string, mixed> $r
	 * @param array<int, string>   $values
	 */
	private function oneOf(string $name, string $key, array $r, array $values): string {
		if (! in_array($r[$key] ?? null, $values, true)) {
			self::fail(sprintf('%s: %s is not one this version knows', $name, $key));
		}

		return (string) $r[$key];
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private function status(string $name, array $r): void {
		$status = $r['status'] ?? null;
		if (! is_string($status) || preg_match('/^[a-z0-9_-]{1,20}$/', $status) !== 1 || in_array($status, self::NOT_RECORDS, true)) {
			self::fail($name . ': status is not a post status');
		}
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private function partition(string $name, array $r): void {
		$partition = $r['partition'] ?? null;
		if (! is_string($partition) || $this->partitionOf($partition) !== $partition) {
			self::fail($name . ': its partition is not in the archive');
		}
	}

	/**
	 * A person or agent of the archive, or null when the key is absent.
	 *
	 * @param array<string, mixed> $r
	 */
	private function principal(string $name, string $key, array $r): ?string {
		if (! array_key_exists($key, $r)) {
			return null;
		}
		if (! is_string($r[$key]) || ! isset($this->principals[$r[$key]])) {
			self::fail(sprintf('%s: %s is not a person or agent of the archive', $name, $key));
		}

		return $r[$key];
	}

	/**
	 * The post id a reference names, or null when the key is absent.
	 *
	 * @param array<string, mixed> $r
	 * @param array<int, mixed>    $records
	 */
	private function ref(string $name, string $key, array $r, string $kind, array $records, bool $optional): ?int {
		if ($optional && ! array_key_exists($key, $r)) {
			return null;
		}
		$value = $r[$key] ?? null;
		if (! is_string($value) || preg_match('/^' . $kind . '-([1-9][0-9]{0,17})$/', $value, $m) !== 1 || ! isset($records[(int) $m[1]])) {
			self::fail(sprintf('%s: %s names a record the archive does not have', $name, $key));
		}

		return (int) $m[1];
	}

	/** Every record keeps its post id (and an upload its upload id), so each must be free here. */
	private function checkFreeIds(): void {
		global $wpdb;

		if ($this->uploads !== []) {
			$in = implode(', ', array_fill(0, count($this->uploads), '%d'));
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			if (Db::getResults('SELECT id FROM ' . UploadStore::table() . " WHERE id IN ({$in}) LIMIT 1", array_keys($this->uploads)) !== []) {
				self::fail('upload ids of the archive are already used on this brain');
			}
		}

		$ids = array_merge(array_keys($this->artifacts), array_keys($this->sections), array_keys($this->compiled), array_keys($this->handoffs), array_keys($this->comments));
		if ($ids === []) {
			return;
		}

		foreach (array_chunk($ids, 500) as $chunk) {
			$in = implode(', ', array_fill(0, count($chunk), '%d'));
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$taken = Db::getResults("SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$in}) ORDER BY ID ASC LIMIT 5", $chunk);
			// phpcs:enable
			if ($taken !== []) {
				self::fail(sprintf('post ids %s are already used on this brain', implode(', ', array_map(static fn (array $row): string => (string) $row['ID'], $taken))));
			}
		}
	}

	// -----------------------------------------------------------------
	// Writing
	// -----------------------------------------------------------------

	/**
	 * Writes every record under its own post id, then the section
	 * revisions, before the restore adds any other post.
	 *
	 * @param callable(mixed): int $userOf principal id => user id (0 when unknown)
	 * @param array<string, int>   $terms  partition name => scope term id
	 */
	public function writePosts(callable $userOf, array $terms): void {
		if (! $this->rebuilds()) {
			return;
		}

		foreach ($this->artifacts as $id => $r) {
			$this->writeArtifact($id, $r, $userOf, $terms);
		}
		foreach ($this->sections as $id => $section) {
			$this->writeSection($id, $section['fm'], $section['body'], $userOf);
		}
		foreach ($this->compiled as $id => $compiled) {
			$fm       = $compiled['fm'];
			$artifact = isset($fm['artifact']) ? (int) substr((string) $fm['artifact'], 9) : 0;
			$new      = $this->post($id, 'post', (string) $fm['status'], $artifact > 0 ? $userOf($this->artifacts[$artifact]['lead'] ?? null) : 0, 0, (string) $fm['title'], $compiled['body'], (string) $fm['recorded_at'], (string) $fm['updated']);
			$this->setPostMeta($new, Cpts::META_COMPILED_FROM, $this->to($artifact));
		}
		foreach ($this->handoffs as $id => $handoff) {
			$this->writeHandoff($id, $handoff['fm'], $handoff['body'], $userOf);
		}
		foreach ($this->comments as $id => $comment) {
			$this->writeComment($id, $comment['fm'], $comment['body'], $userOf);
		}
		foreach ($this->html as $artifact => $versions) {
			foreach ($versions as $record) {
				$this->writeHtml($artifact, $record, $userOf);
			}
		}
		foreach ($this->snapshots as $artifact => $rows) {
			foreach ($rows as $record) {
				$this->writeSnapshot($artifact, $record, $userOf);
			}
		}
		foreach ($this->uploads as $id => $record) {
			$this->writeUpload($id, $record, $userOf);
		}
		// Never live again: kept, so the brain can list them to create anew.
		foreach ($this->shares as $share) {
			$bytes = (string) file_get_contents($this->dir . '/' . $share['path']);
			if ($this->move !== null) {
				$record             = $share['record'];
				$record['artifact'] = 'artifact-' . $this->to((int) substr((string) $record['artifact'], 9));
				$bytes              = ExportFormat::document($record);
			}
			Kept::add(Kept::KIND_SHARE, $share['path'], $bytes);
		}

		// Revisions last: they take new ids, which must not be a record's.
		foreach ($this->history as $id => $versions) {
			$this->writeHistory($id, $versions, $userOf);
		}

		if ($this->move !== null) {
			$this->linkMoved();
		}
	}

	/**
	 * #550: with new ids, the references written before their target
	 * existed: nesting and outlines of artifacts, thread parents of
	 * comments; and a free slug for each moved artifact.
	 */
	private function linkMoved(): void {
		global $wpdb;

		foreach ($this->artifacts as $id => $r) {
			$new    = $this->to($id);
			$parent = isset($r['parent']) ? $this->to((int) substr((string) $r['parent'], 9)) : 0;
			$slug   = (string) ($r['slug'] ?? '');
			if ($slug !== '') {
				$slug = wp_unique_post_slug($slug, $new, (string) $r['status'], Cpts::ARTIFACT_POST_TYPE, $parent);
			}
			$wpdb->update($wpdb->posts, ['post_parent' => $parent, 'post_content' => $this->outline($r), 'post_name' => $slug], ['ID' => $new], ['%d', '%s', '%s'], ['%d']);
		}
		foreach ($this->comments as $id => $comment) {
			if (isset($comment['fm']['parent'])) {
				$wpdb->update($wpdb->posts, ['post_parent' => $this->to((int) substr((string) $comment['fm']['parent'], 8))], ['ID' => $this->to($id)], ['%d'], ['%d']);
			}
		}
	}

	/**
	 * The outline block markup of an artifact record, with section ids here.
	 *
	 * @param array<string, mixed> $r
	 */
	private function outline(array $r): string {
		return serialize_blocks(array_map(
			fn (string $section): array => ['blockName' => Block::NAME, 'attrs' => ['sectionId' => $this->to((int) substr($section, 8))], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []],
			(array) $r['sections']
		));
	}

	/**
	 * Links each handoff to its memory pages, once the rows exist.
	 *
	 * @param callable(string): ?int $rowOf memory page path => row id
	 */
	public function writeLinks(callable $rowOf): void {
		global $wpdb;

		if (! $this->rebuilds()) {
			return;
		}

		foreach ($this->handoffs as $id => $handoff) {
			$rows = [];
			foreach ((array) ($handoff['fm']['pages'] ?? []) as $path) {
				$row = $rowOf((string) $path);
				if ($row !== null) {
					$rows[] = $row;
				}
			}
			if ($rows === []) {
				continue;
			}
			$this->setPostMeta($this->to($id), HandoffQueue::META_MEMORY_IDS, $rows);
			foreach ($rows as $row) {
				$wpdb->query($wpdb->prepare(
					'INSERT IGNORE INTO ' . MemoryRepository::linksTable() . ' (derived_kind, derived_id, source_id) VALUES (%s, %d, %d)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.
					'post',
					$this->to($id),
					$row
				));
			}
		}
	}

	/**
	 * @param array<string, mixed> $r
	 * @param callable(mixed): int $userOf
	 * @param array<string, int>   $terms
	 */
	private function writeArtifact(int $id, array $r, callable $userOf, array $terms): void {
		$lead   = $userOf($r['lead'] ?? null);
		$parent = isset($r['parent']) ? (int) substr((string) $r['parent'], 9) : 0;

		// Moving: the outline, nesting and slug are set once every record has its id here (linkMoved()).
		$new = $this->post($id, Cpts::ARTIFACT_POST_TYPE, (string) $r['status'], $lead, $this->move === null ? $parent : 0, (string) $r['title'], $this->move === null ? $this->outline($r) : '', (string) $r['recorded_at'], (string) $r['updated'], $this->move === null ? (string) ($r['slug'] ?? '') : '');

		$scope = $terms[(string) $r['partition']] ?? 0;
		if ($scope > 0) {
			$this->setPostMeta($new, Cpts::META_SCOPE_ID, $scope);
		}
		$this->setPostMeta($new, Cpts::META_STAGE, (string) $r['stage']);
		if ($lead > 0) {
			$this->setPostMeta($new, Cpts::META_LEAD_AGENT, $lead);
		}
		// ArtifactService's own keys, as ExtensionWriter reads them.
		if (isset($r['share_visibility'])) {
			$this->setPostMeta($new, 'ab_share_visibility', (string) $r['share_visibility']);
		}
		if (isset($r['archived_at'])) {
			$this->setPostMeta($new, 'ab_archived_at', self::mysql((string) $r['archived_at']));
		}
		if (isset($r['kind'])) {
			$this->setPostMeta($new, HtmlFiles::META_KIND, (string) $r['kind']);
		}
		foreach ((array) ($r['old_slugs'] ?? []) as $slug) {
			$this->setPostMeta($new, MembersAddress::META_OLD_SLUGS, (string) $slug);
		}
		foreach ((array) ($r['settings'] ?? []) as $setting => $value) {
			// #550: unless the controller keeps approvals, nothing moved is live: a person approves the HTML version here.
			if ($this->move !== null && empty($this->move['keep_approved']) && in_array($setting, ['html_approved', 'html_mode'], true)) {
				continue;
			}
			$this->setPostMeta($new, ArtifactRecords::SETTINGS[$setting], (string) $value);
		}
		if ($this->move !== null) {
			$this->setPostMeta($new, self::META_SOURCE, (string) $this->move['source'] . '|artifact-' . $id);
		}
	}

	/**
	 * One HTML version row, as HtmlFiles::put() stores it.
	 *
	 * @param array<string, mixed> $r
	 * @param callable(mixed): int $userOf
	 */
	private function writeHtml(int $artifact, array $r, callable $userOf): void {
		$files = [];
		foreach ((array) $r['files'] as $path => $file) {
			$files[(string) $path] = ['type' => (string) $file['type'], 'enc' => (string) $file['enc'], 'data' => (string) $file['data'], 'size' => (int) $file['size']];
		}

		$this->setPostMeta($this->to($artifact), HtmlFiles::META_VERSION, (string) wp_json_encode([
			'version'    => (int) $r['version'],
			'created_at' => (string) ($r['created_at'] ?? ''),
			'created_by' => $userOf($r['created_by'] ?? null),
			'note'       => (string) ($r['note'] ?? ''),
			'files'      => $files === [] ? new \stdClass() : $files,
		]));
	}

	/**
	 * One version history row, as Artifacts\Versions appends it.
	 *
	 * @param array<string, mixed> $r
	 * @param callable(mixed): int $userOf
	 */
	private function writeSnapshot(int $artifact, array $r, callable $userOf): void {
		$row = ['kind' => (string) $r['kind']];
		if (isset($r['section']) && $this->to((int) substr((string) $r['section'], 8)) > 0) {
			$row['section_id'] = $this->to((int) substr((string) $r['section'], 8));
		}
		foreach (['section_version', 'html_version'] as $key) {
			if (isset($r[$key])) {
				$row[$key] = (int) $r[$key];
			}
		}
		foreach (['title', 'content'] as $key) {
			if (isset($r[$key])) {
				$row[$key] = (string) $r[$key];
			}
		}
		$row['actor']  = $userOf($r['by'] ?? null);
		$row['client'] = isset($r['client']) ? (string) $r['client'] : null;
		$row['note']   = (string) ($r['note'] ?? '');
		$row['seq']    = (int) $r['seq'];
		$row['at']     = isset($r['at']) ? self::mysql((string) $r['at']) : '';

		$this->setPostMeta($this->to($artifact), ArtifactRecords::META_SNAPSHOTS, (string) wp_json_encode($row, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * An upload under its own id, its bytes back in the upload store.
	 *
	 * @param array<string, mixed> $r
	 * @param callable(mixed): int $userOf
	 */
	private function writeUpload(int $id, array $r, callable $userOf): void {
		global $wpdb;

		$folder   = (int) $r['_folder'];
		$artifact = $this->to($folder);
		$stored   = substr((string) $r['file'], 6); // 'files/' is 6 chars.
		$bytes    = (string) file_get_contents($this->dir . '/' . $this->uploadBytes[$folder . '/' . $stored]);

		$row = [
			'id'          => $id,
			'artifact_id' => $artifact,
			'name'        => (string) $r['name'],
			'stored_name' => $stored,
			'mime'        => (string) $r['mime'],
			'size'        => (int) $r['size'],
			'uploaded_by' => $userOf($r['uploaded_by'] ?? null),
			'created_at'  => self::mysql((string) $r['recorded_at']),
		];
		$formats = ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s'];
		if ($this->move !== null) {
			unset($row['id']);
			array_shift($formats);
		}
		$ok = $wpdb->insert(UploadStore::table(), $row, $formats);
		if ($ok === false) {
			throw new ImportFailed('internal', 'An uploaded file could not be written.');
		}

		$dir = UploadStore::dirFor($artifact);
		if (! UploadStore::ensureDir($dir) || file_put_contents($dir . '/' . $stored, $bytes) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the upload store's own folder.
			throw new ImportFailed('internal', 'An uploaded file could not be written.');
		}
		$this->writtenFiles[] = $dir . '/' . $stored;
	}

	/**
	 * @param array<string, mixed> $fm
	 * @param callable(mixed): int $userOf
	 */
	private function writeSection(int $id, array $fm, string $body, callable $userOf): void {
		$owner  = $userOf($fm['owner'] ?? null);
		$parent = isset($fm['artifact']) ? (int) substr((string) $fm['artifact'], 9) : 0;

		$new    = $this->post($id, Cpts::SECTION_POST_TYPE, 'private', $owner, $this->to($parent), (string) $fm['title'], $body, (string) $fm['recorded_at'], (string) $fm['updated']);
		$state  = (string) $fm['state'];
		// #550: unless the controller keeps approvals, an approved section waits for a person here.
		if ($this->move !== null && empty($this->move['keep_approved']) && $state === Cpts::SECTION_STATE_APPROVED) {
			$state = Cpts::SECTION_STATE_SUBMITTED;
		}

		if ($owner > 0) {
			$this->setPostMeta($new, Cpts::META_SECTION_OWNER, $owner);
		}
		$this->setPostMeta($new, Cpts::META_SECTION_STATE, $state);
		$this->setPostMeta($new, Cpts::META_VERSION, (int) $fm['version']);
		$writer = $userOf($fm['last_writer'] ?? null);
		if ($writer > 0) {
			$this->setPostMeta($new, Cpts::META_LAST_WRITER, $writer);
		}
		if (isset($fm['last_writer_client'])) {
			$this->setPostMeta($new, 'ab_last_writer_client', (string) $fm['last_writer_client']); // ArtifactService's key.
		}
		if (isset($fm['brief'])) {
			$this->setPostMeta($new, Cpts::META_SECTION_BRIEF, (string) $fm['brief']);
		}
		if (isset($fm['allowed_blocks'])) {
			$this->setPostMeta($new, Cpts::META_ALLOWED_BLOCKS, array_values((array) $fm['allowed_blocks']));
		}
		if (isset($fm['review_log'])) {
			$log = [];
			foreach ((array) $fm['review_log'] as $entry) {
				$stored = [];
				if (isset($entry['version'])) {
					$stored['version'] = (int) $entry['version'];
				}
				$stored['reviewer_id'] = $userOf($entry['by'] ?? null);
				if (isset($entry['verdict'])) {
					$stored['verdict'] = (string) $entry['verdict'];
				}
				$stored['notes'] = (string) ($entry['notes'] ?? '');
				if (isset($entry['at'])) {
					$stored['at'] = self::mysql((string) $entry['at']);
				}
				$log[] = $stored;
			}
			// Stored as Stages::appendReviewLog() stores it.
			$this->setPostMeta($new, Cpts::META_REVIEW_LOG, (string) wp_json_encode($log, JSON_UNESCAPED_UNICODE));
		}
	}

	/**
	 * Earlier versions as revisions, oldest first, then one for the current
	 * text, as WordPress keeps one (the export leaves it out).
	 *
	 * @param array<int, array{fm:array<string, mixed>, body:string}> $versions
	 * @param callable(mixed): int                                    $userOf
	 */
	private function writeHistory(int $sectionId, array $versions, callable $userOf): void {
		$section = $this->sections[$sectionId];
		$author  = $userOf($section['fm']['owner'] ?? null);

		foreach ($versions as $version) {
			$this->revision($this->to($sectionId), $author, (string) $version['fm']['title'], $version['body'], (string) $version['fm']['updated']);
		}
		$this->revision($this->to($sectionId), $author, (string) $section['fm']['title'], $section['body'], (string) $section['fm']['updated']);
	}

	/**
	 * @param array<string, mixed> $fm
	 * @param callable(mixed): int $userOf
	 */
	private function writeHandoff(int $id, array $fm, string $body, callable $userOf): void {
		$new = $this->post($id, HandoffCpt::POST_TYPE, 'private', $userOf($fm['from'] ?? null), 0, (string) $fm['title'], $body, (string) $fm['recorded_at'], (string) $fm['updated']);

		$to = $userOf($fm['to'] ?? null);
		if ($to > 0) {
			$this->setPostMeta($new, HandoffQueue::META_TO, $to);
		}
		$resolution = match ((string) $fm['status']) {
			Present::HANDOFF_RESOLVED  => HandoffQueue::STATUS_APPROVED,
			Present::HANDOFF_DISMISSED => HandoffQueue::STATUS_DISMISSED,
			default                    => null,
		};
		if ($resolution !== null) {
			$this->setPostMeta($new, HandoffQueue::META_STATUS, $resolution);
		}
		if (isset($fm['section'])) {
			$this->setPostMeta($new, 'ab_handoff_section', $this->to((int) substr((string) $fm['section'], 8))); // The handoff ability's key.
		}
		if ($this->move !== null) {
			$this->setPostMeta($new, self::META_SOURCE, (string) $this->move['source'] . '|handoff-' . $id);
		}
	}

	/**
	 * @param array<string, mixed> $fm
	 * @param callable(mixed): int $userOf
	 */
	private function writeComment(int $id, array $fm, string $body, callable $userOf): void {
		$parent = isset($fm['parent']) ? (int) substr((string) $fm['parent'], 8) : 0;

		$new = $this->post($id, Cpt::POST_TYPE, 'private', $userOf($fm['author'] ?? null), $this->to($parent), (string) $fm['title'], $body, (string) $fm['recorded_at'], (string) $fm['updated']);

		if (isset($fm['artifact'])) {
			$this->setPostMeta($new, Cpt::META_ARTIFACT, $this->to((int) substr((string) $fm['artifact'], 9))); // 'artifact-' is 9 chars.
		}
		$this->setPostMeta($new, Cpt::META_VERSION, (int) $fm['version']);
		if (isset($fm['section'])) {
			$this->setPostMeta($new, Cpt::META_SECTION, $this->to((int) substr((string) $fm['section'], 8)));
		}
		if (array_key_exists('element', $fm)) {
			$this->setPostMeta($new, Cpt::META_ELEMENT, (string) $fm['element']);
		}
		if ($fm['status'] === Cpt::STATUS_RESOLVED) {
			$this->setPostMeta($new, Cpt::META_RESOLVED, 1);
			$this->setPostMeta($new, Cpt::META_RESOLVED_BY, $userOf($fm['resolved_by'] ?? null));
			$this->setPostMeta($new, Cpt::META_RESOLVED_AT, self::mysql((string) $fm['resolved_at']));
		}
	}

	/**
	 * Writes a record's post: under its own id, or (moving, #550) under a
	 * new one, remembered for the references that follow.
	 *
	 * @return int The post id here.
	 */
	private function post(int $id, string $type, string $status, int $author, int $parent, string $title, string $content, string $recorded, string $updated, ?string $slug = null): int {
		$recorded = self::mysql($recorded);
		$updated  = self::mysql($updated);

		$fields = [
			'post_author'       => $author,
			'post_date'         => get_date_from_gmt($recorded),
			'post_date_gmt'     => $recorded,
			'post_content'      => $content,
			'post_title'        => $title,
			'post_status'       => $status,
			'post_name'         => $slug ?? ($type === 'post' || $this->move !== null ? '' : $type . '-' . $id),
			'post_modified'     => get_date_from_gmt($updated),
			'post_modified_gmt' => $updated,
			'post_parent'       => $parent,
			'post_type'         => $type,
		];
		if ($this->move === null) {
			$fields = ['ID' => $id] + $fields;
		}

		return $this->ids[$id] = $this->insertPost($fields);
	}

	private function revision(int $parent, int $author, string $title, string $content, string $when): void {
		$when = self::mysql($when);

		$this->insertPost([
			'post_author'       => $author,
			'post_date'         => get_date_from_gmt($when),
			'post_date_gmt'     => $when,
			'post_content'      => $content,
			'post_title'        => $title,
			'post_status'       => 'inherit',
			'post_name'         => $parent . '-revision-v1',
			'post_modified'     => get_date_from_gmt($when),
			'post_modified_gmt' => $when,
			'post_parent'       => $parent,
			'post_type'         => 'revision',
		]);
	}
}
