<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Unpacks a PCP archive safely (PCP v0.3 SPEC sections 8.2 and 8.7.1 item 1):
 * zip, tar (ustar or pax) and tar.gz, told apart by their first bytes.
 *
 * Every entry is checked before anything is written: names that are
 * absolute, have an empty, "." or ".." segment, a NUL byte or a backslash;
 * links, devices and anything else that is not a regular file or a
 * directory; a name given twice, or twice ignoring case; and the limits on
 * entry count, single file size and total size, from the sizes the archive
 * states. Only then are the files written, under a fresh 0700 directory,
 * and a file that turns out larger than it said fails the whole unpack.
 * Nothing is ever written outside that directory.
 */
final class ArchiveReader {

	/** Limits, filterable with agent_brain/import_limits. */
	public const DEFAULT_LIMITS = [
		'archive_bytes' => 1073741824, // 1 GiB as uploaded.
		'entries'       => 200000,
		'file_bytes'    => 268435456,  // 256 MiB for one file.
		'total_bytes'   => 2147483648, // 2 GiB unpacked.
	];

	private const BLOCK = 512;

	/**
	 * @return array{archive_bytes:int, entries:int, file_bytes:int, total_bytes:int}
	 */
	public static function limits(): array {
		$limits = function_exists('apply_filters') ? apply_filters('agent_brain/import_limits', self::DEFAULT_LIMITS) : self::DEFAULT_LIMITS;
		$out    = self::DEFAULT_LIMITS;
		foreach (array_keys($out) as $key) {
			if (is_array($limits) && isset($limits[$key]) && is_numeric($limits[$key]) && (int) $limits[$key] > 0) {
				$out[$key] = (int) $limits[$key];
			}
		}

		return $out;
	}

	/**
	 * Unpacks $archive into $dir (created, must not exist).
	 *
	 * @param array{archive_bytes:int, entries:int, file_bytes:int, total_bytes:int}|null $limits
	 * @return array<int, string> The files written, relative to $dir, sorted.
	 * @throws ImportFailed With code unsafe_archive, too_large or invalid_archive.
	 */
	public static function unpack(string $archive, string $dir, ?array $limits = null): array {
		$limits ??= self::limits();

		$size = is_file($archive) ? (int) filesize($archive) : -1;
		if ($size < 0) {
			throw new ImportFailed('invalid_archive', 'The archive file cannot be read.');
		}
		if ($size > $limits['archive_bytes']) {
			throw new ImportFailed('too_large', 'The archive is larger than this server accepts.');
		}

		$format  = self::format($archive);
		$entries = match ($format) {
			'zip'    => self::zipEntries($archive),
			'tar'    => self::tarEntries('file://' . $archive),
			'tar.gz' => self::tarEntries('compress.zlib://' . $archive),
			default  => throw new ImportFailed('invalid_archive', 'The file is not a zip, tar or tar.gz archive.'),
		};

		self::checkEntries($entries, $limits);

		if (file_exists($dir) || (! mkdir($dir, 0700, true) && ! is_dir($dir))) {
			throw new ImportFailed('internal', 'Could not create a private directory to unpack the archive in.');
		}

		try {
			$written = $format === 'zip'
				? self::extractZip($archive, $dir, $entries)
				: self::extractTar($format === 'tar' ? 'file://' . $archive : 'compress.zlib://' . $archive, $dir, $entries);
		} catch (\Throwable $e) {
			ExportStore::removeTree($dir);

			throw $e instanceof ImportFailed ? $e : new ImportFailed('invalid_archive', 'The archive could not be unpacked.');
		}

		sort($written, SORT_STRING);

		return $written;
	}

	/** "zip", "tar", "tar.gz" or null, from the first bytes. */
	public static function format(string $archive): ?string {
		$handle = fopen($archive, 'rb');
		if ($handle === false) {
			return null;
		}
		$head = (string) fread($handle, self::BLOCK);
		fclose($handle);

		if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
			return 'zip';
		}
		if (str_starts_with($head, "\x1f\x8b")) {
			return 'tar.gz';
		}
		if (strlen($head) === self::BLOCK && substr($head, 257, 5) === 'ustar') {
			return 'tar';
		}

		return null;
	}

	/**
	 * Why a name is unsafe, or null. Pure.
	 */
	public static function unsafeName(string $name): ?string {
		if ($name === '') {
			return 'an empty name';
		}
		if (str_contains($name, "\0")) {
			return 'a NUL byte';
		}
		if (str_contains($name, '\\')) {
			return 'a backslash';
		}
		if ($name[0] === '/' || preg_match('/^[A-Za-z]:/', $name) === 1) {
			return 'an absolute path';
		}

		$segments = explode('/', rtrim($name, '/'));
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return 'an empty, "." or ".." segment';
			}
		}

		return null;
	}

	/**
	 * @param array<int, array{name:string, type:string, size:int, index:int}> $entries
	 * @param array{archive_bytes:int, entries:int, file_bytes:int, total_bytes:int} $limits
	 */
	private static function checkEntries(array $entries, array $limits): void {
		if (count($entries) > $limits['entries']) {
			throw new ImportFailed('too_large', 'The archive has more entries than this server accepts.');
		}

		$seen  = [];
		$lower = [];
		$total = 0;

		foreach ($entries as $entry) {
			$name   = $entry['name'];
			$reason = self::unsafeName($name);
			if ($reason !== null) {
				throw new ImportFailed('unsafe_archive', sprintf('The archive has an entry with %s in its name.', $reason)); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed phrase; escaped where shown.
			}
			if ($entry['type'] !== 'file' && $entry['type'] !== 'dir') {
				throw new ImportFailed('unsafe_archive', 'The archive has a link, device or other entry that is not a regular file.');
			}

			$key = rtrim($name, '/');
			if (isset($seen[$key])) {
				throw new ImportFailed('unsafe_archive', 'The archive names the same entry twice.');
			}
			if (isset($lower[strtolower($key)])) {
				throw new ImportFailed('unsafe_archive', 'The archive has two entries whose names differ only in case.');
			}
			$seen[$key]               = true;
			$lower[strtolower($key)] = true;

			if ($entry['size'] < 0 || $entry['size'] > $limits['file_bytes']) {
				throw new ImportFailed('too_large', 'A file in the archive is larger than this server accepts.');
			}
			$total += $entry['size'];
			if ($total > $limits['total_bytes']) {
				throw new ImportFailed('too_large', 'The archive unpacks to more than this server accepts.');
			}
		}

		// A file may not also be a directory ("a" and "a/b").
		$files = [];
		foreach ($entries as $entry) {
			if ($entry['type'] === 'file') {
				$files[$entry['name']] = true;
			}
		}
		foreach (array_keys($seen) as $key) {
			for ($parent = dirname((string) $key); $parent !== '.' && $parent !== '' && $parent !== '/'; $parent = dirname($parent)) {
				if (isset($files[$parent])) {
					throw new ImportFailed('unsafe_archive', 'The archive has a file and a directory with the same name.');
				}
			}
		}
	}

	// -----------------------------------------------------------------
	// zip
	// -----------------------------------------------------------------

	/**
	 * @return array<int, array{name:string, type:string, size:int, index:int}>
	 */
	private static function zipEntries(string $archive): array {
		if (! class_exists(\ZipArchive::class)) {
			throw new ImportFailed('unsupported', 'This server has no zip support. Upload the archive as tar or tar.gz.');
		}

		$zip = new \ZipArchive();
		if ($zip->open($archive, \ZipArchive::RDONLY) !== true) {
			throw new ImportFailed('invalid_archive', 'The zip archive cannot be opened.');
		}

		$entries = [];
		try {
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$stat = $zip->statIndex($i, \ZipArchive::FL_UNCHANGED);
				$name = $zip->getNameIndex($i, \ZipArchive::FL_UNCHANGED);
				if ($stat === false || ! is_string($name)) {
					throw new ImportFailed('invalid_archive', 'The zip archive has an unreadable entry.');
				}

				$type = str_ends_with($name, '/') ? 'dir' : 'file';
				$opsys = 0;
				$attr  = 0;
				if ($zip->getExternalAttributesIndex($i, $opsys, $attr, \ZipArchive::FL_UNCHANGED) && $opsys === \ZipArchive::OPSYS_UNIX) {
					$mode = ($attr >> 16) & 0170000;
					if ($mode !== 0 && $mode !== 0100000 && $mode !== 0040000) {
						$type = 'other';
					} elseif ($mode === 0040000) {
						$type = 'dir';
					}
				}
				if (! empty($stat['encryption_method'])) {
					throw new ImportFailed('unsupported', 'Encrypted zip archives are not supported.');
				}

				$entries[] = ['name' => $name, 'type' => $type, 'size' => (int) $stat['size'], 'index' => $i];
			}
		} finally {
			$zip->close();
		}

		return $entries;
	}

	/**
	 * @param array<int, array{name:string, type:string, size:int, index:int}> $entries
	 * @return array<int, string>
	 */
	private static function extractZip(string $archive, string $dir, array $entries): array {
		$zip = new \ZipArchive();
		if ($zip->open($archive, \ZipArchive::RDONLY) !== true) {
			throw new ImportFailed('invalid_archive', 'The zip archive cannot be opened.');
		}

		$written = [];
		try {
			foreach ($entries as $entry) {
				if ($entry['type'] !== 'file') {
					continue;
				}

				$in = $zip->getStream($entry['name']);
				if ($in === false) {
					throw new ImportFailed('invalid_archive', 'A zip entry cannot be read.');
				}

				try {
					self::copyStream($in, $dir, $entry['name'], $entry['size']);
				} finally {
					fclose($in);
				}
				$written[] = $entry['name'];
			}
		} finally {
			$zip->close();
		}

		return $written;
	}

	// -----------------------------------------------------------------
	// tar (ustar and pax), plain or gzip
	// -----------------------------------------------------------------

	/**
	 * Reads headers only, skipping data.
	 *
	 * @return array<int, array{name:string, type:string, size:int, index:int}>
	 */
	private static function tarEntries(string $uri): array {
		$entries = [];
		self::walkTar($uri, static function (array $entry) use (&$entries): void {
			$entries[] = $entry;
		}, null);

		return $entries;
	}

	/**
	 * @param array<int, array{name:string, type:string, size:int, index:int}> $entries
	 * @return array<int, string>
	 */
	private static function extractTar(string $uri, string $dir, array $entries): array {
		$expected = [];
		foreach ($entries as $entry) {
			$expected[$entry['index']] = $entry;
		}

		$written = [];
		self::walkTar($uri, static function (array $entry, $in = null) use ($dir, $expected, &$written): void {
			$known = $expected[$entry['index']] ?? null;
			if ($known === null || $known['name'] !== $entry['name'] || $known['size'] !== $entry['size']) {
				throw new ImportFailed('invalid_archive', 'The archive changed while it was read.');
			}
			if ($entry['type'] === 'file' && $in !== null) {
				self::copyStream($in, $dir, $entry['name'], $entry['size']);
				$written[] = $entry['name'];
			}
		}, true);

		return $written;
	}

	/**
	 * Calls $visit for every entry. With $withData, regular files get the
	 * stream positioned at their data, and must read exactly their size.
	 *
	 * @param callable $visit
	 */
	private static function walkTar(string $uri, callable $visit, ?bool $withData): void {
		$in = @fopen($uri, 'rb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad gzip stream is reported below.
		if ($in === false) {
			throw new ImportFailed('invalid_archive', 'The tar archive cannot be read.');
		}

		$index   = 0;
		$pax     = [];
		$global  = [];
		$longName = null;

		try {
			while (true) {
				$header = self::readExactly($in, self::BLOCK, true);
				if ($header === null || trim($header, "\0") === '') {
					break;
				}

				if (! self::checksumOk($header)) {
					throw new ImportFailed('invalid_archive', 'A tar header is damaged.');
				}

				$type = $header[156];
				$size = self::octal(substr($header, 124, 12));
				if ($size < 0) {
					throw new ImportFailed('invalid_archive', 'A tar header has a bad size.');
				}

				// pax extended headers and GNU long names describe the next entry.
				if ($type === 'x' || $type === 'g' || $type === 'L') {
					if ($size > 1048576) {
						throw new ImportFailed('too_large', 'A tar extended header is too large.');
					}
					$data = self::readExactly($in, $size, false) ?? '';
					self::skip($in, self::padding($size));
					if ($type === 'L') {
						$longName = rtrim($data, "\0");
					} elseif ($type === 'x') {
						$pax = self::paxRecords($data);
					} else {
						$global = array_merge($global, self::paxRecords($data));
					}
					continue;
				}

				$name = rtrim(substr($header, 0, 100), "\0");
				if (substr($header, 257, 5) === 'ustar') {
					$prefix = rtrim(substr($header, 345, 155), "\0");
					if ($prefix !== '') {
						$name = $prefix . '/' . $name;
					}
				}
				$attrs = array_merge($global, $pax);
				if (isset($attrs['path'])) {
					$name = $attrs['path'];
				} elseif ($longName !== null) {
					$name = $longName;
				}
				if (isset($attrs['size']) && ctype_digit($attrs['size'])) {
					$size = (int) $attrs['size'];
				}
				$pax      = [];
				$longName = null;

				// A NUL inside the stored name field is how a name hides bytes.
				if (str_contains(rtrim(substr($header, 0, 100), "\0"), "\0")) {
					$name = "\0";
				}

				$kind = match ($type) {
					'0', "\0", '7' => 'file',
					'5'            => 'dir',
					default        => 'other',
				};
				if ($kind === 'file' && str_ends_with($name, '/')) {
					$kind = 'dir';
				}

				$entry = ['name' => $name, 'type' => $kind, 'size' => $kind === 'file' ? $size : 0, 'index' => $index++];

				if ($withData && $kind === 'file') {
					$visit($entry, $in);
				} else {
					$visit($entry);
					self::skip($in, $size);
				}
				self::skip($in, self::padding($size));
			}
		} finally {
			fclose($in);
		}
	}

	/**
	 * @return array<string, string>
	 */
	private static function paxRecords(string $data): array {
		$out = [];
		$pos = 0;
		$len = strlen($data);
		while ($pos < $len) {
			$space = strpos($data, ' ', $pos);
			if ($space === false) {
				break;
			}
			$recordLength = (int) substr($data, $pos, $space - $pos);
			if ($recordLength <= 0 || $pos + $recordLength > $len) {
				throw new ImportFailed('invalid_archive', 'A pax header is damaged.');
			}
			$record = substr($data, $space + 1, $recordLength - ($space - $pos) - 2);
			$eq     = strpos($record, '=');
			if ($eq !== false) {
				$out[substr($record, 0, $eq)] = substr($record, $eq + 1);
			}
			$pos += $recordLength;
		}

		return $out;
	}

	private static function checksumOk(string $header): bool {
		$stored = self::octal(substr($header, 148, 8));
		$sum    = 0;
		for ($i = 0; $i < self::BLOCK; $i++) {
			$sum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
		}

		return $stored === $sum;
	}

	private static function octal(string $field): int {
		$field = trim($field, " \0");
		if ($field === '') {
			return 0;
		}

		return preg_match('/^[0-7]+$/', $field) === 1 ? (int) octdec($field) : -1;
	}

	private static function padding(int $size): int {
		return (self::BLOCK - ($size % self::BLOCK)) % self::BLOCK;
	}

	// -----------------------------------------------------------------
	// Shared
	// -----------------------------------------------------------------

	/**
	 * Copies exactly $size bytes of $in to $dir/$name (0600), creating
	 * parent directories (0700) that must stay inside $dir.
	 *
	 * @param resource $in
	 */
	private static function copyStream($in, string $dir, string $name, int $size): void {
		$target = $dir . '/' . $name;
		$parent = dirname($target);
		if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
			throw new ImportFailed('internal', 'Could not create a directory while unpacking.');
		}

		$root = realpath($dir);
		$real = realpath($parent);
		if ($root === false || $real === false || ! ExportStore::inside($real, $root)) {
			throw new ImportFailed('unsafe_archive', 'An archive entry would land outside the unpack directory.');
		}

		$out = fopen($target, 'xb');
		if ($out === false) {
			throw new ImportFailed('unsafe_archive', 'The archive names the same file twice.');
		}
		chmod($target, 0600);

		try {
			$remaining = $size;
			while ($remaining > 0) {
				$chunk = fread($in, min(65536, $remaining));
				if ($chunk === false || $chunk === '') {
					throw new ImportFailed('invalid_archive', 'An archive entry is shorter than it says.');
				}
				fwrite($out, $chunk);
				$remaining -= strlen($chunk);
			}
		} finally {
			fclose($out);
		}
	}

	/**
	 * @param resource $in
	 */
	private static function readExactly($in, int $length, bool $eofOk): ?string {
		if ($length === 0) {
			return '';
		}

		$data = '';
		while (strlen($data) < $length) {
			$chunk = fread($in, $length - strlen($data));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$data .= $chunk;
		}

		if (strlen($data) < $length) {
			if ($eofOk && $data === '') {
				return null;
			}

			throw new ImportFailed('invalid_archive', 'The tar archive ends in the middle of an entry.');
		}

		return $data;
	}

	/**
	 * @param resource $in
	 */
	private static function skip($in, int $length): void {
		while ($length > 0) {
			$chunk = fread($in, min(65536, $length));
			if ($chunk === false || $chunk === '') {
				throw new ImportFailed('invalid_archive', 'The tar archive ends in the middle of an entry.');
			}
			$length -= strlen($chunk);
		}
	}
}
