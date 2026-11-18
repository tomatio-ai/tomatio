<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * The files of one archive while it is built: each is written under a
 * private staging directory (0700 directories, 0600 files) and hashed as it
 * is written, so the manifest's SHA-256 and sizes come from the bytes on disk
 * and memory stays flat however large a file is.
 *
 * Every name is checked against the manifest's file_path grammar, and two
 * names that differ only in case are refused (PCP v0.3 SPEC section 8.3
 * item 4), so an archive that would not unpack on a case-insensitive file
 * system is never produced.
 *
 * The build has a deadline (decision 12: one request, a measured ceiling):
 * tick() throws ExportFailed with code too_large once it has passed.
 */
final class ArchiveFiles {

	public const FILE_PATH_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,127}(\/[A-Za-z0-9_][A-Za-z0-9._-]{0,127})*$/';

	private const FILE_PATH_MAX = 512;

	/** @var array<string, array{path:string, sha256:string, bytes:int}> */
	private array $files = [];

	/** @var array<string, string> lower-cased path => path */
	private array $lower = [];

	/** @var array<string, array{handle:resource, hash:\HashContext, bytes:int}> */
	private array $open = [];

	/**
	 * @param string $root     Staging directory; created when missing.
	 * @param float  $deadline microtime(true) after which tick() fails; 0 for none.
	 */
	public function __construct(private string $root, private float $deadline = 0.0) {
		$this->root = rtrim($root, '/');
		self::makeDir($this->root);
	}

	public function root(): string {
		return $this->root;
	}

	/** Writes a whole file. */
	public function write(string $path, string $bytes): void {
		$this->begin($path);
		$this->append($path, $bytes);
		$this->end($path);
	}

	/** Starts a file that is written in parts (append, then end). */
	public function begin(string $path): void {
		$this->claim($path);

		$absolute = $this->root . '/' . $path;
		self::makeDir(dirname($absolute));

		$handle = fopen($absolute, 'wb');
		if ($handle === false) {
			throw new ExportFailed('internal', 'Could not create an archive file.');
		}
		chmod($absolute, 0600);

		$this->open[$path] = ['handle' => $handle, 'hash' => hash_init('sha256'), 'bytes' => 0];
	}

	public function append(string $path, string $bytes): void {
		if (! isset($this->open[$path])) {
			throw new ExportFailed('internal', 'Archive file is not open.');
		}

		if ($bytes === '') {
			return;
		}

		if (fwrite($this->open[$path]['handle'], $bytes) !== strlen($bytes)) {
			throw new ExportFailed('internal', 'Could not write an archive file (disk full?).');
		}

		hash_update($this->open[$path]['hash'], $bytes);
		$this->open[$path]['bytes'] += strlen($bytes);
	}

	public function end(string $path): void {
		if (! isset($this->open[$path])) {
			return;
		}

		$file = $this->open[$path];
		unset($this->open[$path]);
		fclose($file['handle']);

		$this->files[$path] = [
			'path'   => $path,
			'sha256' => hash_final($file['hash']),
			'bytes'  => $file['bytes'],
		];
	}

	public function has(string $path): bool {
		return isset($this->files[$path]);
	}

	/**
	 * Every finished file, sorted by path (byte order).
	 *
	 * @return array<int, array{path:string, sha256:string, bytes:int}>
	 */
	public function entries(): array {
		$entries = $this->files;
		ksort($entries, SORT_STRING);

		return array_values($entries);
	}

	/** Fails the build once the deadline has passed. */
	public function tick(): void {
		if ($this->deadline > 0.0 && microtime(true) > $this->deadline) {
			throw new ExportFailed('too_large', 'The export did not finish within the time this request allows. Run it with WP-CLI (wp tomatio export), which has no time limit.');
		}
	}

	/** Pure: is $path a valid archive file name (SPEC 8.3 item 4)? */
	public static function isFilePath(string $path): bool {
		return strlen($path) <= self::FILE_PATH_MAX && preg_match(self::FILE_PATH_PATTERN, $path) === 1;
	}

	private function claim(string $path): void {
		if (! self::isFilePath($path)) {
			throw new ExportFailed('internal', 'An archive file name is not valid under the PCP file path grammar.');
		}

		$lower = strtolower($path);
		if (isset($this->lower[$lower]) || isset($this->open[$path])) {
			throw new ExportFailed('internal', 'Two archive files would have the same name (ignoring case).');
		}

		$this->lower[$lower] = $path;
	}

	private static function makeDir(string $dir): void {
		if (is_dir($dir)) {
			return;
		}

		if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
			throw new ExportFailed('internal', 'Could not create the export staging directory.');
		}
	}
}
