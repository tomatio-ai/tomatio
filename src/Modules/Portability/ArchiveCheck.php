<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * The checks an importer runs on an unpacked archive before it writes
 * anything (PCP v0.3 SPEC sections 8.3, 8.6 and 8.7.1 item 2): the versions,
 * the manifest against its schema, every file listed both ways with its
 * SHA-256 and size, the counts, the paths, every document against its
 * schema, every partition and principal the documents name present in
 * context.json, and the manifest signature document when the archive
 * carries one (its shape only — the cryptographic check runs in
 * Imports/Restores, where the brain's signing key lives). No WordPress
 * needed.
 *
 * The result keeps what restore and import need afterwards, without the
 * page bodies (they are read again from disk when written), so memory stays
 * small for large archives.
 */
final class ArchiveCheck {

	public const PCP_VERSION      = '0.3';
	public const MANIFEST_VERSION = '2';

	private const MAX_ERRORS = 50;

	/** @var array<int, string> */
	private array $errors = [];

	private function __construct(private string $dir) {
	}

	/**
	 * @param array<int, string> $present Files in the unpacked archive (ArchiveReader::unpack()).
	 * @return array{
	 *   errors:array<int, string>,
	 *   manifest:array<string, mixed>,
	 *   manifest_sha256:string,
	 *   context:array<string, mixed>,
	 *   pages:array<string, array{file:string, sha256:string, frontmatter:array<string, mixed>}>,
	 *   history:array<string, array<int, array{file:string, frontmatter:array<string, mixed>}>>,
	 *   revisions:int,
	 *   audit:int,
	 *   receipts:int,
	 *   profile:?array<string, mixed>,
	 *   extensions:array<int, array<string, mixed>>,
	 *   extension_files:array<int, string>,
	 *   connections:int,
	 *   signature:array{signed:bool, doc:?array<string, mixed>}
	 * }
	 */
	public static function run(string $dir, array $present): array {
		$check = new self(rtrim($dir, '/'));

		return $check->verify($present);
	}

	/**
	 * @param array<int, string> $present
	 * @return array<string, mixed>
	 */
	private function verify(array $present): array {
		$out = [
			'errors'          => [],
			'manifest'        => [],
			'manifest_sha256' => '',
			'context'         => [],
			'pages'           => [],
			'history'         => [],
			'revisions'       => 0,
			'audit'           => 0,
			'receipts'        => 0,
			'profile'         => null,
			'extensions'      => [],
			'extension_files' => [],
			'connections'     => 0,
			'signature'       => ['signed' => false, 'doc' => null],
		];

		if (! in_array('manifest.json', $present, true)) {
			$out['errors'] = ['The archive has no manifest.json at its root.'];

			return $out;
		}

		$raw      = (string) file_get_contents($this->dir . '/manifest.json');
		$manifest = json_decode($raw, true);
		$out['manifest_sha256'] = hash('sha256', $raw);
		if (! is_array($manifest)) {
			$out['errors'] = ['manifest.json is not a JSON object.'];

			return $out;
		}
		$out['manifest'] = $manifest;

		// Versions first, so a newer archive is named as such (SPEC 8.6 item 3).
		$pcp     = $manifest['pcp_version'] ?? null;
		$version = $manifest['manifest_version'] ?? null;
		if ($pcp !== self::PCP_VERSION || $version !== self::MANIFEST_VERSION) {
			$out['errors'] = [sprintf(
				'This server reads PCP %s archives (manifest version %s); this one says pcp_version %s, manifest_version %s.',
				self::PCP_VERSION,
				self::MANIFEST_VERSION,
				is_scalar($pcp) ? (string) $pcp : 'none',
				is_scalar($version) ? (string) $version : 'none'
			)];

			return $out;
		}

		$this->schema('manifest.json', SchemaValidator::MANIFEST, $manifest);
		if ($this->errors !== []) {
			$out['errors'] = $this->errors;

			return $out;
		}

		if (isset($manifest['archive_encryption'])) {
			$this->error('The archive is encrypted as a whole, which this server cannot open.');
		}

		// PROV-8 (#177): an optional signature over the manifest. The shape
		// is checked here (pure); the cryptography runs in Imports/Restores,
		// which have WordPress and the brain's key.
		if (in_array(ManifestSignature::FILE, $present, true)) {
			$doc = $this->readJson(ManifestSignature::FILE, SchemaValidator::SIGNATURE);
			if (is_array($doc)) {
				$out['signature'] = ['signed' => true, 'doc' => $doc];
			}
		}

		$listed = $this->checkFiles($manifest, $present);

		$context        = $this->readJson('context.json', SchemaValidator::CONTEXT);
		$out['context'] = is_array($context) ? $context : [];

		$principals = [];
		foreach ((array) ($out['context']['principals'] ?? []) as $principal) {
			if (is_array($principal) && is_string($principal['id'] ?? null)) {
				$principals[$principal['id']] = true;
			}
		}
		if (is_string($out['context']['controller'] ?? null)) {
			$principals[$out['context']['controller']] = true;
		}
		$partitions = [];
		foreach ((array) ($out['context']['partitions'] ?? []) as $partition) {
			if (is_array($partition) && is_string($partition['name'] ?? null)) {
				$partitions[$partition['name']] = true;
			}
		}

		$counts = $this->checkPages($listed, $principals, $partitions, $out);
		$this->checkCounts($manifest, $counts, $listed, $out);

		$out['audit']    = $this->checkLines('audit/log.jsonl', SchemaValidator::AUDIT, $listed, $principals, ['principal', 'on_behalf_of'], (int) ($manifest['audit']['entries'] ?? 0), 'audit.entries');
		$out['receipts'] = isset($manifest['erasures'])
			? $this->checkLines('erasures/receipts.jsonl', SchemaValidator::RECEIPT, $listed, $principals, ['requested_by'], (int) ($manifest['erasures']['receipts'] ?? 0), 'erasures.receipts')
			: 0;
		if (! isset($manifest['erasures']) && isset($listed['erasures/receipts.jsonl'])) {
			$this->error('erasures/receipts.jsonl is in the archive, but the manifest does not describe it.');
		}

		$out['profile'] = $this->checkProfile($manifest, $listed);

		[$out['extensions'], $out['extension_files']] = $this->checkExtensions($manifest, $listed);
		$out['connections'] = count((array) ($manifest['connections'] ?? []));

		if (($manifest['context']['controller'] ?? null) !== ($out['context']['controller'] ?? null)) {
			$this->error('The manifest and context.json name different controllers.');
		}
		if (($manifest['context']['kind'] ?? null) !== ($out['context']['kind'] ?? null)) {
			$this->error('The manifest and context.json name different kinds of context.');
		}

		$out['errors'] = $this->errors;

		return $out;
	}

	/**
	 * SPEC 8.3 item 2: every file listed with its hash and size, every
	 * listed file present, nothing listed twice.
	 *
	 * @param array<string, mixed> $manifest
	 * @param array<int, string>   $present
	 * @return array<string, true> Listed paths.
	 */
	private function checkFiles(array $manifest, array $present): array {
		$listed = [];
		foreach ((array) $manifest['files'] as $file) {
			$path = (string) $file['path'];
			if (isset($listed[$path])) {
				$this->error(sprintf('%s is listed twice in the manifest.', $path));
				continue;
			}
			$listed[$path] = true;

			if ($path === 'manifest.json') {
				$this->error('The manifest lists itself.');
				continue;
			}
			if ($path === ManifestSignature::FILE) {
				$this->error(sprintf('The manifest lists %s, which is archive metadata and is never listed.', ManifestSignature::FILE));
				continue;
			}
			if (! empty($file['encryption'])) {
				$this->error(sprintf('%s is encrypted, which this server cannot read.', $path));
			}
			if (! in_array($path, $present, true)) {
				$this->error(sprintf('%s is listed in the manifest but missing from the archive.', $path));
				continue;
			}

			$absolute = $this->dir . '/' . $path;
			if ((int) filesize($absolute) !== (int) $file['bytes']) {
				$this->error(sprintf('%s does not have the size the manifest lists.', $path));
			} elseif (hash_file('sha256', $absolute) !== $file['sha256']) {
				$this->error(sprintf('%s does not have the SHA-256 the manifest lists.', $path));
			}
		}

		foreach ($present as $path) {
			if ($path !== 'manifest.json' && $path !== ManifestSignature::FILE && ! isset($listed[$path])) {
				$this->error(sprintf('%s is in the archive but not listed in the manifest.', $path));
			}
			if (! ArchiveFiles::isFilePath($path)) {
				$this->error(sprintf('%s is not a valid archive file name.', self::safe($path)));
			}
		}

		return $listed;
	}

	/**
	 * Pages, history and revisions: paths, schema, references.
	 *
	 * @param array<string, true> $listed
	 * @param array<string, true> $principals
	 * @param array<string, true> $partitions
	 * @param array<string, mixed> $out
	 * @return array{pages:int, sensitive:int, review:array<string, int>, partitions:array<string, int>}
	 */
	private function checkPages(array $listed, array $principals, array $partitions, array &$out): array {
		$counts = ['pages' => 0, 'sensitive' => 0, 'review' => ['approved' => 0, 'proposed' => 0, 'rejected' => 0], 'partitions' => []];
		$ids    = [];
		$paths  = array_keys($listed);
		sort($paths, SORT_STRING);

		foreach ($paths as $file) {
			$kind = strtok($file, '/');
			if (! in_array($kind, ['memory', 'history', 'revisions'], true)) {
				continue;
			}

			if ($kind === 'memory') {
				++$counts['pages'];
				if (preg_match('#^memory/(.+)\.md$#', $file, $m) !== 1 || ! Names::isPath($m[1])) {
					$this->error(sprintf('%s is not a page file with a valid path.', $file));
					continue;
				}
				$path = $m[1];
			} elseif (preg_match('#^(?:history|revisions)/(.+)/([1-9][0-9]*)\.md$#', $file, $m) !== 1 || ! Names::isPath($m[1])) {
				$this->error(sprintf('%s is not a numbered version of a page with a valid path.', $file));
				continue;
			} else {
				$path = $m[1];
			}

			$bytes = (string) file_get_contents($this->dir . '/' . $file);
			try {
				$page = PageFile::parse($bytes);
			} catch (\InvalidArgumentException $e) {
				$this->error(sprintf('%s: %s', $file, $e->getMessage()));
				continue;
			}

			$frontmatter = $page['frontmatter'];
			foreach (SchemaValidator::errors(SchemaValidator::PAGE, $frontmatter) as $message) {
				$this->error(sprintf('%s %s', $file, $message));
			}

			$partition = is_string($frontmatter['partition'] ?? null) ? $frontmatter['partition'] : Partitions::DEFAULT_NAME;
			if (! isset($partitions[$partition])) {
				$this->error(sprintf('%s names a partition that context.json does not list.', $file));
			}
			foreach (self::pagePrincipals($frontmatter) as $principal) {
				if (! isset($principals[$principal])) {
					$this->error(sprintf('%s names a principal that context.json does not list.', $file));
					break;
				}
			}

			if ($kind === 'memory') {
				if (($frontmatter['sensitivity'] ?? null) === 'sensitive') {
					++$counts['sensitive'];
				}
				$state = is_array($frontmatter['review'] ?? null) ? (string) ($frontmatter['review']['state'] ?? 'approved') : 'approved';
				if (isset($counts['review'][$state])) {
					++$counts['review'][$state];
				}
				$counts['partitions'][$partition] = ($counts['partitions'][$partition] ?? 0) + 1;

				$id = is_string($frontmatter['id'] ?? null) ? $frontmatter['id'] : '';
				if ($id !== '' && isset($ids[$id])) {
					$this->error(sprintf('%s has the same id as %s.', $file, $ids[$id]));
				}
				$ids[$id] = $file;

				$out['pages'][$path] = ['file' => $file, 'sha256' => hash('sha256', $bytes), 'frontmatter' => $frontmatter];
			} elseif ($kind === 'history') {
				$out['history'][$path][(int) $m[2]] = ['file' => $file, 'frontmatter' => $frontmatter];
			} else {
				++$out['revisions'];
			}
		}

		foreach ($out['history'] as $path => $versions) {
			ksort($versions, SORT_NUMERIC);
			$out['history'][$path] = $versions;
			if (array_keys($versions) !== range(1, count($versions))) {
				$this->error(sprintf('The history of %s is not numbered from 1 without gaps.', $path));
			}
		}

		return $counts;
	}

	/**
	 * SPEC 8.3 item 3.
	 *
	 * @param array<string, mixed> $manifest
	 * @param array{pages:int, sensitive:int, review:array<string, int>, partitions:array<string, int>} $counts
	 * @param array<string, true> $listed
	 * @param array<string, mixed> $out
	 */
	private function checkCounts(array $manifest, array $counts, array $listed, array $out): void {
		$memory = (array) $manifest['memory'];

		if ((int) $memory['page_count'] !== $counts['pages']) {
			$this->error(sprintf('memory.page_count says %d, the archive has %d page files.', (int) $memory['page_count'], $counts['pages']));
		}
		if ((int) $memory['sensitive_page_count'] !== $counts['sensitive']) {
			$this->error('memory.sensitive_page_count does not match the pages.');
		}
		if (isset($memory['review_counts'])) {
			foreach (['approved', 'proposed', 'rejected'] as $state) {
				if ((int) ($memory['review_counts'][$state] ?? 0) !== $counts['review'][$state]) {
					$this->error(sprintf('memory.review_counts.%s does not match the pages.', $state));
				}
			}
		}
		if (isset($memory['partition_counts'])) {
			$stated = array_map('intval', (array) $memory['partition_counts']);
			$actual = $counts['partitions'];
			ksort($stated, SORT_STRING);
			ksort($actual, SORT_STRING);
			if (array_filter($stated) != array_filter($actual)) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- same keys and numbers, any order.
				$this->error('memory.partition_counts does not match the pages.');
			}
		}

		foreach (['history', 'revisions'] as $key) {
			foreach ((array) ($memory[$key] ?? []) as $item) {
				$file = (string) ($item['file'] ?? '');
				if (! isset($listed[$file])) {
					$this->error(sprintf('memory.%s names %s, which is not in the archive.', $key, self::safe($file)));
				}
			}
		}

		$described = [];
		foreach ((array) ($memory['history'] ?? []) as $item) {
			$described[(string) ($item['file'] ?? '')] = true;
		}
		foreach ($out['history'] as $versions) {
			foreach ($versions as $version) {
				if (! isset($described[$version['file']])) {
					$this->error(sprintf('%s is not listed in memory.history.', $version['file']));
				}
			}
		}
	}

	/**
	 * A JSON Lines file: each line against its schema, principals listed,
	 * and the line count against the manifest.
	 *
	 * @param array<string, true> $listed
	 * @param array<string, true> $principals
	 * @param array<int, string>  $keys
	 */
	private function checkLines(string $file, string $schema, array $listed, array $principals, array $keys, int $stated, string $label): int {
		if (! isset($listed[$file])) {
			if ($stated > 0) {
				$this->error(sprintf('%s says %d, but %s is not in the archive.', $label, $stated, $file));
			}

			return 0;
		}

		$handle = fopen($this->dir . '/' . $file, 'rb');
		if ($handle === false) {
			$this->error(sprintf('%s cannot be read.', $file));

			return 0;
		}

		$count = 0;
		$ids   = [];
		while (($line = fgets($handle)) !== false) {
			if (trim($line) === '') {
				continue;
			}
			++$count;
			$entry = json_decode($line, true);
			if (! is_array($entry)) {
				$this->error(sprintf('%s line %d is not a JSON object.', $file, $count));
				continue;
			}
			foreach (SchemaValidator::errors($schema, $entry) as $message) {
				$this->error(sprintf('%s line %d %s', $file, $count, $message));
			}
			foreach ($keys as $key) {
				if (is_string($entry[$key] ?? null) && ! isset($principals[$entry[$key]])) {
					$this->error(sprintf('%s line %d names a principal that context.json does not list.', $file, $count));
				}
			}
			$id = is_string($entry['id'] ?? null) ? $entry['id'] : '';
			if ($id !== '' && isset($ids[$id])) {
				$this->error(sprintf('%s has the id of line %d twice.', $file, $count));
			}
			$ids[$id] = true;
		}
		fclose($handle);

		if ($count !== $stated) {
			$this->error(sprintf('%s says %d, %s has %d lines.', $label, $stated, $file, $count));
		}

		return $count;
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @param array<string, true>  $listed
	 * @return array<string, mixed>|null
	 */
	private function checkProfile(array $manifest, array $listed): ?array {
		$type = (string) ($manifest['profile']['type'] ?? 'none');
		$path = $manifest['profile']['path'] ?? null;

		if ($type === 'none' || ! is_string($path)) {
			foreach (['profile/team.json', 'profile/core.json'] as $file) {
				if (isset($listed[$file])) {
					$this->error(sprintf('%s is in the archive, but the manifest says there is no such profile.', $file));
				}
			}

			return null;
		}

		if (! isset($listed[$path])) {
			$this->error(sprintf('The manifest names %s, which is not in the archive.', $path));

			return null;
		}

		$profile = $this->readJson($path, $type === 'team' ? 'profile.team.schema.json' : 'profile.core.schema.json');

		return is_array($profile) ? $profile : null;
	}

	/**
	 * Every file under extensions/ belongs to an extension the manifest
	 * lists, and every listed extension has files.
	 *
	 * @param array<string, mixed> $manifest
	 * @param array<string, true>  $listed
	 * @return array{0:array<int, array<string, mixed>>, 1:array<int, string>}
	 */
	private function checkExtensions(array $manifest, array $listed): array {
		$extensions = array_values(array_filter((array) ($manifest['extensions'] ?? []), 'is_array'));
		$prefixes   = [];
		foreach ($extensions as $extension) {
			$prefix = (string) $extension['path'];
			if ($prefix !== 'extensions/' . $extension['id'] . '/') {
				$this->error(sprintf('The extension %s is not stored under extensions/<its id>/.', self::safe((string) $extension['id'])));
			}
			$prefixes[$prefix] = 0;
		}

		$files = [];
		foreach (array_keys($listed) as $path) {
			if (! str_starts_with($path, 'extensions/')) {
				continue;
			}
			$files[] = $path;
			$owner   = null;
			foreach (array_keys($prefixes) as $prefix) {
				if (str_starts_with($path, $prefix)) {
					$owner = $prefix;
					break;
				}
			}
			if ($owner === null) {
				$this->error(sprintf('%s belongs to no extension the manifest lists.', $path));
			} else {
				++$prefixes[$owner];
			}
		}

		foreach ($prefixes as $prefix => $count) {
			if ($count === 0) {
				$this->error(sprintf('The manifest lists %s, but the archive has no files there.', $prefix));
			}
		}

		sort($files, SORT_STRING);

		return [$extensions, $files];
	}

	/**
	 * @return mixed
	 */
	private function readJson(string $file, string $schema) {
		if (! is_file($this->dir . '/' . $file)) {
			$this->error(sprintf('%s is missing.', $file));

			return null;
		}

		$data = json_decode((string) file_get_contents($this->dir . '/' . $file), true);
		if (! is_array($data)) {
			$this->error(sprintf('%s is not a JSON object.', $file));

			return null;
		}

		$this->schema($file, $schema, $data);

		return $data;
	}

	/**
	 * @param mixed $data
	 */
	private function schema(string $file, string $schema, $data): void {
		foreach (SchemaValidator::errors($schema, $data) as $message) {
			$this->error(sprintf('%s %s', $file, $message));
		}
	}

	/**
	 * Principals a page names in source and review.
	 *
	 * @param array<string, mixed> $frontmatter
	 * @return array<int, string>
	 */
	public static function pagePrincipals(array $frontmatter): array {
		$out    = [];
		$source = is_array($frontmatter['source'] ?? null) ? $frontmatter['source'] : [];
		foreach (['principal', 'on_behalf_of'] as $key) {
			if (is_string($source[$key] ?? null)) {
				$out[] = $source[$key];
			}
		}

		$review = is_array($frontmatter['review'] ?? null) ? $frontmatter['review'] : [];
		foreach (['generated', 'rejected'] as $key) {
			if (is_string($review[$key]['by'] ?? null)) {
				$out[] = $review[$key]['by'];
			}
		}
		foreach ((array) ($review['verified'] ?? []) as $verified) {
			if (is_array($verified) && is_string($verified['by'] ?? null)) {
				$out[] = $verified['by'];
			}
		}

		return array_values(array_unique($out));
	}

	private function error(string $message): void {
		if (count($this->errors) < self::MAX_ERRORS) {
			$this->errors[] = $message;
		}
	}

	/** A name from the archive, safe to show: printable ASCII only, cut. */
	private static function safe(string $text): string {
		return substr((string) preg_replace('/[^\x20-\x7E]/', '?', $text), 0, 200);
	}
}
