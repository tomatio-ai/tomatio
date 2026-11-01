<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

/**
 * Small pure-PHP ustar writer (no Phar/PharData dependency, so it works
 * regardless of the phar.readonly ini setting and is exercisable from the
 * WordPress-free unit suite). Streams file contents in bounded chunks —
 * never loads a whole snapshot file into memory.
 */
final class TarWriter {

	private const BLOCK_SIZE  = 512;
	private const READ_CHUNK  = 65536;

	/**
	 * Tars every regular file under $sourceDir (recursively) into $tarPath.
	 * Archive entry names are the path relative to $sourceDir, forward-slash
	 * separated, so the archive is portable across platforms.
	 */
	public static function createFromDirectory(string $sourceDir, string $tarPath): void {
		$sourceDir = rtrim($sourceDir, '/');

		$out = fopen($tarPath, 'wb');
		if ($out === false) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to open tar output for writing: {$tarPath}");
		}

		foreach (self::listFiles($sourceDir) as $relative => $absolute) {
			self::writeEntry($out, $relative, $absolute);
		}

		// End of archive: two consecutive zero-filled 512-byte records.
		fwrite($out, str_repeat("\0", self::BLOCK_SIZE * 2));
		fclose($out);
	}

	/**
	 * Tars the named files under $baseDir, in the order given (the PCP
	 * exporter puts manifest.json first). Names are relative paths with
	 * forward slashes.
	 *
	 * @param array<int, string> $names
	 */
	public static function createFromFiles(string $baseDir, array $names, string $tarPath): void {
		$baseDir = rtrim($baseDir, '/');

		$out = fopen($tarPath, 'wb');
		if ($out === false) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to open tar output for writing: {$tarPath}");
		}

		foreach ($names as $name) {
			self::writeEntry($out, $name, $baseDir . '/' . $name);
		}

		fwrite($out, str_repeat("\0", self::BLOCK_SIZE * 2));
		fclose($out);
	}

	/**
	 * @return array<string, string> relative name => absolute path, sorted for determinism.
	 */
	private static function listFiles(string $sourceDir): array {
		$files = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $fileInfo) {
			/** @var \SplFileInfo $fileInfo */
			if (! $fileInfo->isFile()) {
				continue;
			}

			$absolute = $fileInfo->getPathname();
			$relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($sourceDir))), '/');
			$files[$relative] = $absolute;
		}

		ksort($files);

		return $files;
	}

	/**
	 * @param resource $out
	 */
	private static function writeEntry($out, string $name, string $path): void {
		$size = filesize($path);
		if ($size === false) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to stat file for tar entry: {$path}");
		}

		fwrite($out, self::buildHeader($name, $size));

		$in = fopen($path, 'rb');
		if ($in === false) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to open file for tar entry: {$path}");
		}

		$written = 0;
		while (! feof($in)) {
			$chunk = fread($in, self::READ_CHUNK);
			if ($chunk === false || $chunk === '') {
				break;
			}
			fwrite($out, $chunk);
			$written += strlen($chunk);
		}
		fclose($in);

		$padding = (self::BLOCK_SIZE - ($written % self::BLOCK_SIZE)) % self::BLOCK_SIZE;
		if ($padding > 0) {
			fwrite($out, str_repeat("\0", $padding));
		}
	}

	/**
	 * Splits a name into the ustar prefix (up to 155 bytes) and name (up to
	 * 100 bytes) fields at a "/", so paths up to 256 bytes fit a plain ustar
	 * header.
	 *
	 * @return array{0:string, 1:string} [prefix, name]
	 */
	public static function splitName(string $name): array {
		if (strlen($name) <= 100) {
			return ['', $name];
		}

		$cut = strlen($name);
		while (($cut = strrpos(substr($name, 0, $cut), '/')) !== false) {
			$prefix = substr($name, 0, $cut);
			$rest   = substr($name, $cut + 1);
			if (strlen($prefix) <= 155 && strlen($rest) <= 100 && $rest !== '') {
				return [$prefix, $rest];
			}
			if ($cut === 0) {
				break;
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
		throw new \RuntimeException("Tar entry name too long for a ustar header: {$name}");
	}

	private static function buildHeader(string $name, int $size): string {
		[$prefix, $name] = self::splitName($name);

		$header  = str_pad($name, 100, "\0");
		$header .= self::octalField(0100644, 8); // mode
		$header .= self::octalField(0, 8);       // uid
		$header .= self::octalField(0, 8);       // gid
		$header .= self::octalField($size, 12);  // size
		$header .= self::octalField(time(), 12); // mtime
		$header .= str_repeat(' ', 8);            // chksum placeholder while computing
		$header .= '0';                           // typeflag: regular file
		$header .= str_repeat("\0", 100);         // linkname
		$header .= "ustar\0";                     // magic
		$header .= '00';                          // version
		$header .= str_pad('', 32, "\0");         // uname
		$header .= str_pad('', 32, "\0");         // gname
		$header .= self::octalField(0, 8);        // devmajor
		$header .= self::octalField(0, 8);        // devminor
		$header .= str_pad($prefix, 155, "\0");   // prefix

		$header = str_pad($header, self::BLOCK_SIZE, "\0");

		$checksum = 0;
		for ($i = 0; $i < self::BLOCK_SIZE; $i++) {
			$checksum += ord($header[$i]);
		}

		$checksumField = str_pad(sprintf('%06o', $checksum), 6, '0', STR_PAD_LEFT) . "\0 ";

		// Chksum field occupies bytes 148..155.
		return substr($header, 0, 148) . $checksumField . substr($header, 156);
	}

	private static function octalField(int $value, int $width): string {
		return str_pad(sprintf('%0' . ($width - 1) . 'o', $value), $width - 1, '0', STR_PAD_LEFT) . "\0";
	}
}
