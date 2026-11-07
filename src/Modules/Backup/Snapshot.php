<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

/**
 * Builds one encrypted backup snapshot: Markdown export + SQL dump of every
 * ab_* table, packed into a tar, encrypted with the site's ab_backup_key.
 * Everything happens under a private temp directory that is removed once
 * the encrypted artifact is produced (or on any failure).
 */
final class Snapshot {

	/**
	 * @return array{path:string, bytes:int, sha256:string, markdown_files:int, sql_tables:array<int,string>}
	 */
	public static function build(): array {
		$workDir = self::makeTempDir('ab-backup-');

		try {
			$markdownDir = $workDir . '/markdown';
			mkdir($markdownDir, 0700, true);
			$markdownFiles = MarkdownStage::export($markdownDir);

			$sqlPath = $workDir . '/sql/dump.sql';
			mkdir(dirname($sqlPath), 0700, true);
			$tables = SqlDumper::dumpTables($sqlPath);

			// The tar must be written OUTSIDE $workDir from the start — if
			// it were created inside the directory it archives, TarWriter's
			// directory scan would pick up the (partially written) tar file
			// itself as an entry, corrupting the archive.
			$finalTarPath = $workDir . '.tar';
			TarWriter::createFromDirectory($workDir, $finalTarPath);

			$key        = KeyManager::getOrCreateKey();
			$cipherPath = $workDir . '.enc';
			Encryptor::encryptFile($finalTarPath, $cipherPath, $key);

			$bytes  = (int) filesize($cipherPath);
			$sha256 = hash_file('sha256', $cipherPath);
			if ($sha256 === false) {
				throw new \RuntimeException('Unable to hash encrypted backup snapshot.');
			}

			@unlink($finalTarPath);

			return [
				'path'           => $cipherPath,
				'bytes'          => $bytes,
				'sha256'         => $sha256,
				'markdown_files' => count($markdownFiles),
				'sql_tables'     => $tables,
			];
		} finally {
			self::removeDir($workDir);
		}
	}

	private static function makeTempDir(string $prefix): string {
		$base = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
		$dir  = rtrim($base, '/') . '/' . $prefix . bin2hex(random_bytes(8));

		if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to create backup temp directory: {$dir}");
		}

		return $dir;
	}

	private static function removeDir(string $dir): void {
		if (! is_dir($dir)) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $fileInfo) {
			/** @var \SplFileInfo $fileInfo */
			if ($fileInfo->isDir()) {
				@rmdir($fileInfo->getPathname());
			} else {
				@unlink($fileInfo->getPathname());
			}
		}

		@rmdir($dir);
	}
}
