<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

/**
 * Curator-only restore helper: decrypts and unpacks a previously downloaded
 * backup blob into a temp directory for manual inspection.
 *
 * Deliberately does NOT touch the live database: a snapshot's SQL dump is
 * plain `.sql` text once unpacked, and applying it is a curator decision
 * made outside of this plugin (e.g. reviewing it, then importing by hand or
 * via a controlled tool) — see contracts.md "Backups": "documented,
 * curator-only, no automatic DB overwrite".
 *
 * No admin-post action calls this yet — restoring is intentionally a
 * deliberate, out-of-band operation (contracts.md only requires the path be
 * "documented, curator-only, no automatic DB overwrite"), so today this is
 * invoked by a curator via WP-CLI/`wp eval` or a future dedicated admin
 * screen, never automatically. Any caller added later MUST gate on
 * `ab_manage` + nonce, same as every other state-changing Backup action.
 */
final class Restore {

	/**
	 * Decrypts $encryptedBlobPath with the site's ab_backup_key and unpacks
	 * the resulting tar into a fresh temp directory. Returns the directory
	 * and the list of files it contains. Throws (never silently no-ops) if
	 * the key is missing, decryption fails, or the file cannot be unpacked.
	 *
	 * @return array{dir:string, files:array<int,string>}
	 */
	public static function unpack(string $encryptedBlobPath): array {
		if (! KeyManager::exists()) {
			throw new \RuntimeException('No ab_backup_key is present on this site; cannot decrypt any backup.');
		}

		$key = KeyManager::getOrCreateKey();

		$base    = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
		$destDir = rtrim($base, '/') . '/ab-restore-' . bin2hex(random_bytes(8));

		if (! mkdir($destDir, 0700, true) && ! is_dir($destDir)) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to create restore directory: {$destDir}");
		}

		$tarPath = $destDir . '.tar';

		try {
			Encryptor::decryptFile($encryptedBlobPath, $tarPath, $key);
			$files = TarReader::extractTo($tarPath, $destDir);
		} finally {
			@unlink($tarPath);
		}

		return [
			'dir'   => $destDir,
			'files' => $files,
		];
	}
}
