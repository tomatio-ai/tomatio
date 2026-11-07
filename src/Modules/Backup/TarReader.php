<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

/**
 * Counterpart reader for TarWriter's ustar archives. Used by both the unit
 * round-trip test and Restore::unpack(). Pure PHP, no WordPress dependency.
 *
 * SEC-1 (tar-slip) hardening: every entry name is validated BEFORE any
 * filesystem write. An entry is rejected (never written) if its name is
 * absolute, contains a `..` path segment, contains a NUL byte anywhere
 * other than as trailing header padding, or looks like a Windows drive
 * or backslash-separated path — any of those can be used to escape
 * $destDir on extraction ("tar-slip"). As defense in depth, the resolved
 * parent directory of every accepted entry is also verified to still be
 * inside realpath($destDir) once created. Symlink/hardlink/device
 * entries are never followed or created — only plain files (and the
 * directories implied by their path) are written. Any rejection fails
 * the whole extraction loudly (RuntimeException naming every rejected
 * entry and the reason) instead of silently skipping the bad entries
 * and proceeding with a partial, possibly-misleading extraction.
 */
final class TarReader {

	private const BLOCK_SIZE = 512;

	/** ustar typeflag bytes that are safe to materialise as regular files. */
	private const REGULAR_FILE_TYPEFLAGS = ['0', "\0"];

	/**
	 * Extracts every entry from $tarPath into $destDir, preserving relative
	 * paths. Returns the list of relative paths written.
	 *
	 * @return array<int, string>
	 * @throws \RuntimeException if the archive cannot be read, or contains
	 *         any entry that fails the tar-slip / typeflag validation below.
	 */
	public static function extractTo(string $tarPath, string $destDir): array {
		$in = fopen($tarPath, 'rb');
		if ($in === false) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to open tar for reading: {$tarPath}");
		}

		if (! is_dir($destDir) && (! mkdir($destDir, 0700, true) && ! is_dir($destDir))) {
			fclose($in);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to create extraction directory: {$destDir}");
		}

		$destReal = realpath($destDir);
		if ($destReal === false) {
			fclose($in);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to resolve extraction directory: {$destDir}");
		}

		$written  = [];
		$rejected = [];

		while (! feof($in)) {
			$header = fread($in, self::BLOCK_SIZE);
			if ($header === false || $header === '' || strlen($header) < self::BLOCK_SIZE) {
				break;
			}

			if (trim($header) === '') {
				// Zero-filled block: end-of-archive marker (two of these in a row).
				continue;
			}

			$rawName = substr($header, 0, 100);
			// ustar: a non-empty prefix field (bytes 345..499) is the leading
			// part of a long name, joined with "/". Validated as one name below.
			if (substr($header, 257, 5) === 'ustar') {
				$prefix = substr($header, 345, 155);
				$nul    = strpos($prefix, "\0");
				$prefix = $nul === false ? $prefix : substr($prefix, 0, $nul);
				if ($prefix !== '') {
					$rawName = $prefix . '/' . $rawName;
				}
			}
			$sizeOctal = rtrim(substr($header, 124, 12), " \0");
			$size      = $sizeOctal === '' ? 0 : intval($sizeOctal, 8);
			$typeflag  = substr($header, 156, 1);

			$reason = self::rejectionReason($rawName, $typeflag);

			if ($reason !== null) {
				$rejected[] = sprintf('%s (%s)', trim(rtrim($rawName, "\0")), $reason);
				self::skipData($in, $size);

				continue;
			}

			$name       = rtrim($rawName, "\0");
			$targetPath = $destDir . '/' . $name;
			$targetDir  = dirname($targetPath);

			if (! is_dir($targetDir) && (! mkdir($targetDir, 0700, true) && ! is_dir($targetDir))) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
				throw new \RuntimeException("Unable to create directory for tar entry: {$targetDir}");
			}

			$targetDirReal = realpath($targetDir);
			if ($targetDirReal === false || ! self::isInside($targetDirReal, $destReal)) {
				$rejected[] = sprintf('%s (resolves outside destination directory)', $name);
				self::skipData($in, $size);

				continue;
			}

			$out = fopen($targetPath, 'wb');
			if ($out === false) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
				throw new \RuntimeException("Unable to write extracted file: {$targetPath}");
			}

			$remaining = $size;
			while ($remaining > 0) {
				$read = fread($in, min(65536, $remaining));
				if ($read === false || $read === '') {
					break;
				}
				fwrite($out, $read);
				$remaining -= strlen($read);
			}
			fclose($out);

			$padding = (self::BLOCK_SIZE - ($size % self::BLOCK_SIZE)) % self::BLOCK_SIZE;
			if ($padding > 0) {
				fread($in, $padding);
			}

			$written[] = $name;
		}

		fclose($in);

		if ($rejected !== []) {
			// Fail closed: remove everything this run wrote so a caller can
			// never end up with a partial, silently-truncated extraction.
			foreach ($written as $relative) {
				@unlink($destDir . '/' . $relative);
			}

			throw new \RuntimeException(
				'Tar archive rejected ' . count($rejected) . ' unsafe entr' . (count($rejected) === 1 ? 'y' : 'ies') . ': '
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic message, entry names are not rendered as HTML.
				. implode('; ', $rejected)
			);
		}

		return $written;
	}

	/**
	 * Returns a human-readable rejection reason, or null if the entry is
	 * safe to extract as a regular file under $destDir.
	 */
	private static function rejectionReason(string $rawName, string $typeflag): ?string {
		if (! in_array($typeflag, self::REGULAR_FILE_TYPEFLAGS, true)) {
			return 'unsupported typeflag (symlink/hardlink/device entries are never extracted)';
		}

		$nulPos = strpos($rawName, "\0");
		$name   = $nulPos === false ? $rawName : substr($rawName, 0, $nulPos);
		$tail   = $nulPos === false ? '' : substr($rawName, $nulPos);

		// Anything but NUL padding after the first NUL means an embedded NUL byte.
		if ($tail !== '' && trim($tail, "\0") !== '') {
			return 'embedded NUL byte in entry name';
		}

		if ($name === '') {
			return 'empty entry name';
		}

		if ($name[0] === '/' || $name[0] === '\\') {
			return 'absolute path';
		}

		if (str_contains($name, '\\')) {
			return 'backslash path separator';
		}

		if (preg_match('/^[A-Za-z]:/', $name) === 1) {
			return 'Windows drive-letter path';
		}

		foreach (explode('/', $name) as $segment) {
			if ($segment === '..') {
				return 'contains a ".." path segment';
			}
		}

		return null;
	}

	private static function isInside(string $path, string $base): bool {
		$base = rtrim($base, '/');

		return $path === $base || str_starts_with($path, $base . '/');
	}

	/**
	 * @param resource $in
	 */
	private static function skipData($in, int $size): void {
		$remaining = $size;
		while ($remaining > 0) {
			$read = fread($in, min(65536, $remaining));
			if ($read === false || $read === '') {
				break;
			}
			$remaining -= strlen($read);
		}

		$padding = (self::BLOCK_SIZE - ($size % self::BLOCK_SIZE)) % self::BLOCK_SIZE;
		if ($padding > 0) {
			fread($in, $padding);
		}
	}
}
