<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * The Markdown half of a backup snapshot (decision 7): every page and its
 * history, written by the PCP page writer exactly as an export writes them
 * (memory/<path>.md, history/<path>/<n>.md). The snapshot stays a backup:
 * Backup encrypts it with the site key; an export is never encrypted.
 */
final class BackupPages {

	/**
	 * Writes the pages under $dir (created if missing) and returns the
	 * absolute paths written, sorted.
	 *
	 * @return array<int, string>
	 */
	public static function write(string $dir): array {
		$files = new ArchiveFiles($dir);
		( new PageWriter($files, new PrincipalMap()) )->writeAll();

		return array_map(
			static fn (array $entry): string => $files->root() . '/' . $entry['path'],
			$files->entries()
		);
	}
}
