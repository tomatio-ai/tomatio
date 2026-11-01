<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\Identity\Capabilities;

/**
 * Full exports (PCP v0.3 SPEC section 8.1): administrators only
 * (decision 6, see canExport()), full archives only. Used by the admin page, the management
 * REST routes and WP-CLI.
 *
 * The stages run in one request under a measured ceiling (decision 12):
 * the build fails loudly with too_large when it would run past it, and
 * WP-CLI runs without one. Every export, ready or failed, writes one
 * export.create audit entry with counts only (EXP-04).
 */
final class Exports {

	public const AUDIT_EVENT = 'export.create';

	/** Seconds a web request may spend building, when max_execution_time is unlimited. */
	public const DEFAULT_CEILING = 120;

	public function __construct(private ExportStore $store = new ExportStore()) {
	}

	public function store(): ExportStore {
		return $this->store;
	}

	/**
	 * Builds a full export and holds it in the store.
	 *
	 * @param float|null $ceiling Seconds; null for the web default, 0 for none.
	 * @return array<string, mixed> The record (see record()).
	 * @throws ExportFailed
	 */
	public function create(int $userId, ?float $ceiling = null): array {
		$this->authorize($userId);

		if ($this->store->streamOnly()) {
			throw new ExportFailed('unsupported', 'This server can serve files from the export directory to the web, so archives are not held: download the export directly from the Export and import page.');
		}

		$built = $this->buildArchive($userId, $ceiling, false);

		$record = $this->record($built, $userId);
		$this->store->add($record);

		return $record;
	}

	/**
	 * Builds a full export in a temporary directory for streaming at once
	 * (stream-only mode, and WP-CLI --output). The caller deletes the file.
	 *
	 * @return array{id:string, path:string, name:string, sha256:string, bytes:int, manifest:array<string, mixed>}
	 * @throws ExportFailed
	 */
	public function createFile(int $userId, ?float $ceiling = null): array {
		$this->authorize($userId);

		return $this->buildArchive($userId, $ceiling, true);
	}

	/**
	 * The ceiling for a web request: 80% of max_execution_time, or the
	 * default when PHP sets no limit, filterable.
	 */
	public static function webCeiling(): float {
		$limit   = (int) ini_get('max_execution_time');
		$ceiling = $limit > 0 ? $limit * 0.8 : self::DEFAULT_CEILING;

		return max(5.0, (float) apply_filters('agent_brain/export_ceiling_seconds', $ceiling));
	}

	/**
	 * @return array{id:string, path:string, name:string, sha256:string, bytes:int, manifest:array<string, mixed>}
	 */
	private function buildArchive(int $userId, ?float $ceiling, bool $temp): array {
		$started  = microtime(true);
		$ceiling  = $ceiling ?? self::webCeiling();
		$deadline = $ceiling > 0 ? $started + $ceiling : 0.0;
		$staging  = $this->store->stagingDir($temp);
		$id       = wp_generate_uuid4();

		try {
			$built   = ExportBuilder::build($staging, $deadline, $userId > 0 ? $userId : null);
			$useZip  = class_exists(\ZipArchive::class);
			$archive = $temp
				? rtrim(get_temp_dir(), '/') . '/' . bin2hex(random_bytes(16)) . '.pcp.' . ($useZip ? 'zip' : 'tar')
				: $this->store->newArchivePath($useZip ? 'zip' : 'tar');

			self::pack($staging, $built['files'], $archive, $useZip);
			chmod($archive, 0600);
		} catch (ExportFailed $e) {
			ExportStore::removeTree($staging);
			$this->audit($userId, $id, 'error', [], $started);

			throw $e;
		} catch (\Throwable $e) {
			ExportStore::removeTree($staging);
			$this->audit($userId, $id, 'error', [], $started);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where it is shown.
			throw new ExportFailed('internal', 'The export failed: ' . $e->getMessage());
		}

		ExportStore::removeTree($staging);

		$manifest = $built['manifest'];
		$this->audit($userId, $id, 'ok', [
			'pages'       => (int) $manifest['memory']['page_count'],
			'files'       => count($built['files']) + 2, // manifest.json and its signature are written beside the tracked files.
			'signing_key' => SigningKey::id(),
		], $started);

		return [
			'id'       => $id,
			'path'     => $archive,
			'name'     => self::downloadName($archive),
			'sha256'   => (string) hash_file('sha256', $archive),
			'bytes'    => (int) filesize($archive),
			'manifest' => $manifest,
		];
	}

	/**
	 * Zip (or ustar when the zip extension is missing) of the staged files,
	 * manifest.json first, its signature next, then every listed file by path.
	 *
	 * @param array<int, array{path:string, sha256:string, bytes:int}> $files
	 */
	public static function pack(string $staging, array $files, string $archive, bool $zip = true): void {
		$names = array_merge(['manifest.json', ManifestSignature::FILE], array_map(static fn (array $f): string => $f['path'], $files));

		if (! $zip) {
			\AgentBrain\Modules\Backup\TarWriter::createFromFiles($staging, $names, $archive);

			return;
		}

		$out = new \ZipArchive();
		if ($out->open($archive, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
			throw new ExportFailed('internal', 'Could not create the archive file.');
		}
		foreach ($names as $name) {
			$out->addFile($staging . '/' . $name, $name);
			$out->setCompressionName($name, \ZipArchive::CM_DEFLATE);
		}
		if (! $out->close()) {
			throw new ExportFailed('internal', 'Could not write the archive file.');
		}
	}

	/**
	 * @param array{id:string, path:string, name:string, sha256:string, bytes:int, manifest:array<string, mixed>} $built
	 * @return array<string, mixed>
	 */
	private function record(array $built, int $userId): array {
		$now     = time();
		$expires = $now + ExportStore::retentionHours() * HOUR_IN_SECONDS;

		return [
			'id'           => $built['id'],
			'status'       => 'ready',
			'file'         => basename($built['path']),
			'name'         => $built['name'],
			'created'      => gmdate('Y-m-d\TH:i:s\Z', $now),
			'expires_at'   => gmdate('Y-m-d\TH:i:s\Z', $expires),
			'expires_ts'   => $expires,
			'sha256'       => $built['sha256'],
			'bytes'        => $built['bytes'],
			'coverage'     => 'full',
			'page_count'   => (int) $built['manifest']['memory']['page_count'],
			'omitted'      => $built['manifest']['omitted'],
			'requested_by' => $userId,
		];
	}

	/**
	 * What the management surface shows (management-api.md GET /exports/{id}).
	 *
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	public static function view(array $record): array {
		return [
			'id'         => (string) $record['id'],
			'status'     => (string) $record['status'],
			'created'    => (string) $record['created'],
			'expires_at' => (string) $record['expires_at'],
			'sha256'     => (string) $record['sha256'],
			'bytes'      => (int) $record['bytes'],
			'coverage'   => (string) $record['coverage'],
			'page_count' => (int) $record['page_count'],
			'omitted'    => (array) $record['omitted'],
		];
	}

	/** "<host>-<date>.pcp.zip" (SPEC 8.2). */
	private static function downloadName(string $archive): string {
		$host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
		$host = trim((string) preg_replace('/[^a-z0-9-]+/', '-', $host), '-');

		return ($host !== '' ? $host : 'brain') . '-' . gmdate('Y-m-d') . '.pcp.' . (str_ends_with($archive, '.tar') ? 'tar' : 'zip');
	}

	private function authorize(int $userId): void {
		// WP-CLI without --user runs as nobody; the shell is the authority there.
		if ($userId === 0 && defined('WP_CLI') && constant('WP_CLI')) {
			return;
		}

		if ($userId <= 0 || ! self::canExport($userId)) {
			throw new ExportFailed('forbidden', 'Only administrators can make a full export.');
		}
	}

	/**
	 * Decision 6, administrators only: ab_manage, which curators hold too,
	 * and manage_options, which only administrators do.
	 */
	public static function canExport(int $userId): bool {
		return $userId > 0 && user_can($userId, Capabilities::CAP_MANAGE) && user_can($userId, 'manage_options');
	}

	/**
	 * @param array<string, int> $counts
	 */
	private function audit(int $userId, string $id, string $status, array $counts, float $started): void {
		do_action('agent_brain/audit', [
			'actor_id'    => max(0, $userId),
			'event'       => self::AUDIT_EVENT,
			'status'      => $status,
			'object_type' => 'export',
			'object_id'   => $id,
			'meta'        => $counts,
			'duration_ms' => (int) round((microtime(true) - $started) * 1000),
		]);
	}
}
