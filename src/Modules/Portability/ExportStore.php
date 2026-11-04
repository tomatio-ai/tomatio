<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Where export archives are built and held, never under uploads/ (PCP v0.3
 * SPEC section 8.1 item 4).
 *
 * The first location that works wins:
 *  1. the AGENT_BRAIN_EXPORT_DIR constant or the agent_brain/export_dir
 *     filter, set by the host;
 *  2. a private directory beside the web root (dirname(ABSPATH)), when it
 *     is writable and not under the document root;
 *  3. get_temp_dir(), when it is not inside ABSPATH;
 *  4. wp-content/agent-brain-private/ with deny files, checked by a canary:
 *     a file with a random token is requested over HTTP, and unless the
 *     request is refused the brain holds nothing (stream-only mode: build in
 *     a temp directory, stream the download, delete).
 * A location under uploads/ is never used, whoever configured it.
 *
 * Archives are 0600 files with random names. They are downloaded only
 * through access-checked handlers (Repr-Digest carries the SHA-256), expire
 * after the retention setting (24 hours by default, at most 7 days), are
 * swept hourly by cron, and forget deletes them all. An export is
 * plaintext: never encrypted with the backup key (decision 7).
 */
final class ExportStore {

	public const OPTION_RECORDS   = 'ab_export_records';
	public const OPTION_RETENTION = 'ab_export_retention_hours';
	public const OPTION_CANARY    = 'ab_export_canary';
	public const SWEEP_HOOK       = 'agent_brain_export_sweep';

	public const DEFAULT_RETENTION_HOURS = 24;
	public const MAX_RETENTION_HOURS     = 168;

	public const KIND_HOST    = 'host';
	public const KIND_BESIDE  = 'beside_web_root';
	public const KIND_TEMP    = 'temp';
	public const KIND_CONTENT = 'wp_content';

	/** The old exporter's directory under uploads/, emptied on upgrade and by forget. */
	public const LEGACY_SUBDIR = 'agent-brain-exports';

	private const ARCHIVE_PATTERN = '/^[a-f0-9]{32}\.pcp\.(zip|tar)$/';
	private const CANARY_MAX_AGE  = DAY_IN_SECONDS;

	/** @var array{dir:string, kind:string}|null */
	private ?array $location = null;

	// -----------------------------------------------------------------
	// Location
	// -----------------------------------------------------------------

	/**
	 * The directory archives are held in, and which rule chose it.
	 *
	 * @return array{dir:string, kind:string}
	 */
	public function location(): array {
		if ($this->location !== null) {
			return $this->location;
		}

		$suffix = substr(hash('sha256', ABSPATH . '|' . (string) get_current_blog_id()), 0, 12);

		$host = defined('AGENT_BRAIN_EXPORT_DIR') ? (string) constant('AGENT_BRAIN_EXPORT_DIR') : '';
		$host = (string) apply_filters('agent_brain/export_dir', $host);
		if ($host !== '' && $this->usable($host)) {
			return $this->location = ['dir' => self::real($host), 'kind' => self::KIND_HOST];
		}

		$beside = rtrim(dirname(rtrim(ABSPATH, '/')), '/') . '/agent-brain-private-' . $suffix;
		if (! $this->underDocumentRoot($beside) && $this->usable($beside)) {
			return $this->location = ['dir' => self::real($beside), 'kind' => self::KIND_BESIDE];
		}

		$temp = rtrim(get_temp_dir(), '/') . '/agent-brain-exports-' . $suffix;
		if (! self::inside(self::real(get_temp_dir()), self::real(ABSPATH)) && $this->usable($temp)) {
			return $this->location = ['dir' => self::real($temp), 'kind' => self::KIND_TEMP];
		}

		$content = rtrim(WP_CONTENT_DIR, '/') . '/agent-brain-private';
		$this->usable($content);
		self::writeDenyFiles($content);

		return $this->location = ['dir' => self::real($content), 'kind' => self::KIND_CONTENT];
	}

	/**
	 * True when archives must not be held: only the wp-content fallback is
	 * reachable from the web, and its canary was served (or could not be
	 * checked, which fails closed).
	 */
	public function streamOnly(): bool {
		if ($this->location()['kind'] !== self::KIND_CONTENT) {
			return false;
		}

		$canary = get_option(self::OPTION_CANARY, []);
		if (! is_array($canary) || ! isset($canary['result'], $canary['checked_at']) || (int) $canary['checked_at'] < time() - self::CANARY_MAX_AGE) {
			$canary = $this->checkCanary();
		}

		return ($canary['result'] ?? '') !== 'blocked';
	}

	/**
	 * Puts a canary file with a random token in the wp-content fallback and
	 * requests it over HTTP. "blocked" when the server refuses it, "served"
	 * when the token comes back, "unknown" when the request failed.
	 *
	 * @return array{result:string, checked_at:int, status:int}
	 */
	public function checkCanary(): array {
		$dir   = rtrim(WP_CONTENT_DIR, '/') . '/agent-brain-private';
		$token = bin2hex(random_bytes(16));
		$name  = 'canary-' . bin2hex(random_bytes(8)) . '.txt';

		$this->usable($dir);
		self::writeDenyFiles($dir);
		$written = file_put_contents($dir . '/' . $name, $token) !== false;

		$status = 0;
		$result = 'unknown';
		if ($written) {
			$response = wp_remote_get(content_url('agent-brain-private/' . $name), [
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => false,
			]);
			if (! is_wp_error($response)) {
				$status = (int) wp_remote_retrieve_response_code($response);
				$body   = (string) wp_remote_retrieve_body($response);
				$result = str_contains($body, $token) ? 'served' : ($status >= 400 ? 'blocked' : 'unknown');
			}
			wp_delete_file($dir . '/' . $name);
		}

		$canary = ['result' => $result, 'checked_at' => time(), 'status' => $status];
		update_option(self::OPTION_CANARY, $canary, false);

		return $canary;
	}

	/** A fresh private staging directory for one build. */
	public function stagingDir(bool $temp = false): string {
		$base = $temp ? rtrim(get_temp_dir(), '/') : $this->location()['dir'];
		$dir  = $base . '/build-' . bin2hex(random_bytes(16));

		if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
			throw new ExportFailed('internal', 'Could not create the export staging directory.');
		}

		return $dir;
	}

	/** A new random archive path in the store (not created). */
	public function newArchivePath(string $extension): string {
		return $this->location()['dir'] . '/' . bin2hex(random_bytes(16)) . '.pcp.' . $extension;
	}

	// -----------------------------------------------------------------
	// Records
	// -----------------------------------------------------------------

	/**
	 * Held archives, newest first, expired ones swept first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$this->sweep();

		$records = array_values($this->records());
		usort($records, static fn (array $a, array $b): int => strcmp((string) $b['created'], (string) $a['created']));

		return $records;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get(string $id): ?array {
		$records = $this->records();
		$record  = $records[$id] ?? null;

		if ($record === null) {
			return null;
		}

		if ((int) $record['expires_ts'] <= time() || ! is_file($this->pathOf($record))) {
			$this->delete($id);

			return null;
		}

		return $record;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public function add(array $record): void {
		$records                         = $this->records();
		$records[(string) $record['id']] = $record;
		update_option(self::OPTION_RECORDS, $records, false);
	}

	public function delete(string $id): bool {
		$records = $this->records();
		if (! isset($records[$id])) {
			return false;
		}

		$path = $this->pathOf($records[$id]);
		if (is_file($path)) {
			wp_delete_file($path);
		}

		unset($records[$id]);
		update_option(self::OPTION_RECORDS, $records, false);

		return true;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public function pathOf(array $record): string {
		$file = basename((string) ($record['file'] ?? ''));

		return preg_match(self::ARCHIVE_PATTERN, $file) === 1 ? $this->location()['dir'] . '/' . $file : '';
	}

	/** Hours a held archive is kept: the setting, from 1 to 168. */
	public static function retentionHours(): int {
		$hours = (int) get_option(self::OPTION_RETENTION, self::DEFAULT_RETENTION_HOURS);

		return max(1, min(self::MAX_RETENTION_HOURS, $hours));
	}

	/**
	 * Cron (hourly) and every listing: deletes expired archives, archives
	 * with no record, and staging directories older than a day.
	 *
	 * @return int Archives deleted.
	 */
	public function sweep(): int {
		$deleted = 0;
		$records = $this->records();
		$known   = [];

		foreach ($records as $id => $record) {
			if ((int) $record['expires_ts'] <= time() || ! is_file($this->pathOf($record))) {
				$this->delete((string) $id);
				++$deleted;
				continue;
			}
			$known[basename((string) $record['file'])] = true;
		}

		$dir = $this->location()['dir'];
		foreach (glob($dir . '/*.pcp.*') ?: [] as $path) {
			if (preg_match(self::ARCHIVE_PATTERN, basename($path)) === 1 && ! isset($known[basename($path)])) {
				wp_delete_file($path);
				++$deleted;
			}
		}

		foreach (glob($dir . '/build-*', GLOB_ONLYDIR) ?: [] as $staging) {
			if ((int) filemtime($staging) < time() - DAY_IN_SECONDS) {
				self::removeTree($staging);
			}
		}

		return $deleted;
	}

	/**
	 * Forget: deletes every held archive (any could hold the subject, SPEC
	 * 8.9.2 item 3), and anything the old exporter left under uploads/.
	 *
	 * @return int Archives deleted.
	 */
	public function deleteAll(): int {
		$deleted = 0;
		foreach (array_keys($this->records()) as $id) {
			if ($this->delete((string) $id)) {
				++$deleted;
			}
		}

		foreach (glob($this->location()['dir'] . '/*.pcp.*') ?: [] as $path) {
			if (preg_match(self::ARCHIVE_PATTERN, basename($path)) === 1) {
				wp_delete_file($path);
				++$deleted;
			}
		}

		return $deleted + self::deleteLegacy();
	}

	/**
	 * Forget's residue check: held archives with an entry that still
	 * contains the subject (case-insensitive).
	 */
	public function residueCount(string $subject): int {
		$needle = mb_strtolower($subject);
		$count  = 0;

		foreach ($this->records() as $record) {
			$path = $this->pathOf($record);
			if ($path === '' || ! is_file($path) || ! class_exists(\ZipArchive::class) || ! str_ends_with($path, '.zip')) {
				continue;
			}

			$zip = new \ZipArchive();
			if ($zip->open($path) !== true) {
				continue;
			}
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$content = $zip->getFromIndex($i);
				if ($content !== false && mb_strpos(mb_strtolower((string) $content), $needle) !== false) {
					++$count;
				}
			}
			$zip->close();
		}

		return $count;
	}

	/**
	 * Removes what the old Markdown exporter left in uploads/ (reachable on
	 * servers that ignore .htaccess).
	 *
	 * @return int Entries removed.
	 */
	public static function deleteLegacy(): int {
		$upload = wp_upload_dir(null, false);
		$base   = rtrim((string) $upload['basedir'], '/') . '/' . self::LEGACY_SUBDIR;
		if (! is_dir($base)) {
			return 0;
		}

		$removed = 0;
		foreach (glob($base . '/*') ?: [] as $path) {
			if (is_dir($path)) {
				self::removeTree($path);
			} else {
				wp_delete_file($path);
			}
			++$removed;
		}

		self::removeTree($base);

		return $removed;
	}

	/**
	 * Sends a held archive as an attachment, with its SHA-256 in
	 * Repr-Digest (RFC 9530). The caller checks access and exits.
	 *
	 * @param array<string, mixed> $record
	 */
	public function send(array $record): void {
		self::sendFile($this->pathOf($record), (string) $record['name'], (string) $record['sha256']);
	}

	public static function sendFile(string $path, string $name, string $sha256): void {
		nocache_headers();
		header('Content-Type: ' . (str_ends_with($name, '.tar') ? 'application/x-tar' : 'application/zip'));
		header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $name) . '"');
		header('Content-Length: ' . (string) filesize($path));
		header('Repr-Digest: ' . self::reprDigest($sha256));
		header('X-Content-Type-Options: nosniff');
		readfile($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a private file to its owner.
	}

	/** Pure: the Repr-Digest field value for a hex SHA-256. */
	public static function reprDigest(string $sha256): string {
		$binary = (string) hex2bin($sha256);

		return 'sha-256=:' . base64_encode($binary) . ':';
	}

	// -----------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function records(): array {
		$records = get_option(self::OPTION_RECORDS, []);
		if (! is_array($records)) {
			return [];
		}

		return array_filter($records, static fn ($record): bool => is_array($record) && isset($record['id'], $record['file'], $record['expires_ts']));
	}

	/** Creates the directory (0700) when missing; true when it is writable and not under uploads/. */
	private function usable(string $dir): bool {
		if ($this->underUploads($dir)) {
			return false;
		}

		if (! is_dir($dir)) {
			$parent = dirname($dir);
			if (! is_dir($parent) || ! wp_is_writable($parent)) {
				return false;
			}
			if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
				return false;
			}
		}

		return wp_is_writable($dir) && ! $this->underUploads(self::real($dir));
	}

	private function underUploads(string $dir): bool {
		$upload = wp_upload_dir(null, false);
		$base   = (string) $upload['basedir'];

		return $base !== '' && self::inside(self::real($dir), self::real($base));
	}

	private function underDocumentRoot(string $dir): bool {
		$root = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared as a path only.

		return $root !== '' && self::inside(self::real($dir), self::real($root));
	}

	/** Pure: is $path the same as, or below, $parent? */
	public static function inside(string $path, string $parent): bool {
		$path   = rtrim($path, '/') . '/';
		$parent = rtrim($parent, '/') . '/';

		return $parent !== '/' && str_starts_with($path, $parent);
	}

	/** realpath() for what exists, else the parent's realpath and the rest. */
	private static function real(string $path): string {
		$path = rtrim($path, '/');
		$real = realpath($path);
		if ($real !== false) {
			return $real;
		}

		$parent = realpath(dirname($path));

		return $parent !== false ? $parent . '/' . basename($path) : $path;
	}

	private static function writeDenyFiles(string $dir): void {
		if (! is_dir($dir)) {
			return;
		}

		$files = [
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		];

		foreach ($files as $name => $contents) {
			if (! file_exists($dir . '/' . $name)) {
				file_put_contents($dir . '/' . $name, $contents);
			}
		}
	}

	public static function removeTree(string $dir): void {
		if (! is_dir($dir)) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			/** @var \SplFileInfo $item */
			if ($item->isDir() && ! $item->isLink()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}

		rmdir($dir);
	}
}
