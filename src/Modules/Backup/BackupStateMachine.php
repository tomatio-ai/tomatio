<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

/**
 * ops/F3 fix: turns the single-PHP-execution backup (Runner::run()) into a
 * resumable state machine so a slow host/big site can never hit a
 * WP-Cron/PHP execution-time limit mid-backup. State (phase + every
 * resumption cursor) lives in the option `ab_backup_state` (autoload=no).
 * Phases, in order: export (Markdown) -> dump (SQL, per table per
 * id-range batch) -> tar -> encrypt (streaming, chunked) -> upload
 * (chunked) -> done.
 *
 * Every call to tick() does as much work as fits in `ab_backup_budget_seconds`
 * (default 20s) and then either finishes (done/failed, temp files cleaned,
 * option cleared or left as a failure record) or persists its cursor and
 * reschedules a single (non-recurring) continuation event — also advanced
 * whenever `agent_brain/cloud_tick` fires, so a site relying on the cloud
 * scheduler instead of WP-Cron still makes progress. State older than 24h
 * is treated as abandoned: discarded, with every temp file it referenced
 * cleaned up.
 *
 * The dump and encrypt phases are built on primitives (SqlDumper's
 * schema/batch methods, sodium's streaming secretstream state, which is a
 * plain string safe to persist between requests) that are also what a
 * single, unbudgeted run uses, so a multi-tick run's dump.sql content and
 * decrypted tar content are byte-for-byte identical to a single-shot run's.
 */
final class BackupStateMachine {

	public const OPTION_STATE  = 'ab_backup_state';
	public const OPTION_BUDGET = 'ab_backup_budget_seconds';
	public const TICK_HOOK     = 'agent_brain_backup_tick';

	public const DEFAULT_BUDGET_SECONDS = 20;
	public const STALE_AFTER_SECONDS    = 24 * 60 * 60;

	private const PLAINTEXT_CHUNK = 64 * 1024;

	private const TERMINAL_PHASES = ['done', 'failed'];

	public static function register(): void {
		add_action(self::TICK_HOOK, [self::class, 'tick']);
		add_action('agent_brain/cloud_tick', [self::class, 'tick']);
	}

	/**
	 * Starts a fresh backup run, or (if one is already in progress and not
	 * stale) simply advances it. Called both by the daily cron (Cron::run())
	 * and by the curator "Back up now" action — both drive the same
	 * machine, so both are resumable/interruptible the same way.
	 */
	public static function start(): void {
		$state = self::loadState();

		if ($state !== null && ! self::isStale($state) && ! in_array($state['phase'], self::TERMINAL_PHASES, true)) {
			self::tick();

			return;
		}

		if ($state !== null) {
			self::cleanupFiles($state);
		}

		$state = self::freshState();
		self::saveState($state);

		self::tick();
	}

	/**
	 * Performs work for up to `ab_backup_budget_seconds`, then persists and
	 * either finishes or reschedules. Safe to call with no run in progress
	 * (no-op) and safe to call repeatedly/concurrently-ish (WordPress
	 * options provide no true locking, but each tick re-reads the freshest
	 * state and terminal phases short-circuit immediately).
	 */
	public static function tick(): void {
		$state = self::loadState();
		if ($state === null) {
			return;
		}

		if (self::isStale($state)) {
			self::cleanupFiles($state);
			delete_option(self::OPTION_STATE);
			self::unscheduleTick();
			self::audit('error', null, ['reason' => 'stale_state_discarded', 'phase' => $state['phase']]);

			return;
		}

		if (in_array($state['phase'], self::TERMINAL_PHASES, true)) {
			self::unscheduleTick();

			return;
		}

		$budgetSeconds = (int) get_option(self::OPTION_BUDGET, self::DEFAULT_BUDGET_SECONDS);
		if ($budgetSeconds < 1) {
			$budgetSeconds = 1;
		}
		$deadline = microtime(true) + $budgetSeconds;

		try {
			while (microtime(true) < $deadline && ! in_array($state['phase'], self::TERMINAL_PHASES, true)) {
				$state = self::step($state, $deadline);
				$state['updated'] = time();
				self::saveState($state);
			}
		} catch (\Throwable $exception) {
			$state['phase'] = 'failed';
			$state['error'] = $exception->getMessage();
			self::saveState($state);
		}

		if ($state['phase'] === 'done') {
			self::cleanupFiles($state);
			self::unscheduleTick();
			delete_option(self::OPTION_STATE);
			delete_option(MarkdownStage::OPTION_LAST_WARNING);
			self::audit('ok', $state);

			return;
		}

		if ($state['phase'] === 'failed') {
			self::cleanupFiles($state);
			self::unscheduleTick();
			update_option(
				MarkdownStage::OPTION_LAST_WARNING,
				'Backup failed during the "' . $state['failed_phase'] . '" phase: ' . (string) ($state['error'] ?? 'unknown error'),
				false
			);
			self::audit('error', $state);

			return;
		}

		self::scheduleTick();
	}

	/**
	 * Immediately abandons any run in progress: deletes its temp files and
	 * its state option, without marking it failed or scheduling anything
	 * further. Used by the forget-propagation listener (privacy/F1): a
	 * forget must never let an in-flight backup snapshot survive it.
	 */
	public static function discard(): void {
		$state = self::loadState();
		if ($state !== null) {
			self::cleanupFiles($state);
		}

		delete_option(self::OPTION_STATE);
		self::unscheduleTick();
	}

	public static function isRunning(): bool {
		$state = self::loadState();

		return $state !== null && ! self::isStale($state) && ! in_array($state['phase'], self::TERMINAL_PHASES, true);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function loadState(): ?array {
		$state = get_option(self::OPTION_STATE);

		return is_array($state) ? $state : null;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function saveState(array $state): void {
		update_option(self::OPTION_STATE, $state, false);
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function isStale(array $state): bool {
		$started = (int) ($state['started'] ?? 0);

		return $started > 0 && (time() - $started) > self::STALE_AFTER_SECONDS;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function freshState(): array {
		$base    = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
		$workDir = rtrim($base, '/') . '/ab-backup-' . bin2hex(random_bytes(8));

		return [
			'phase'        => 'export',
			'work_dir'     => $workDir,
			'backup_id'    => wp_generate_uuid4(),
			'started'      => time(),
			'updated'      => time(),
			'error'        => null,
			'failed_phase' => null,
			'markdown'     => ['done' => false, 'files' => 0],
			'dump'         => [
				'tables'         => null,
				'table_index'    => 0,
				'offset'         => 0,
				'schema_written' => false,
				'done'           => false,
			],
			'tar'          => ['done' => false],
			'encrypt'      => [
				'done'           => false,
				'header_written' => false,
				'state'          => null,
				'offset'         => 0,
				'bytes'          => 0,
				'sha256'         => null,
			],
			'upload'       => [
				'inited'          => false,
				'upload_path'     => null,
				'next_chunk'      => 0,
				'complete_called' => false,
			],
		];
	}

	// -- Paths --------------------------------------------------------

	/**
	 * @param array<string, mixed> $state
	 */
	private static function markdownDir(array $state): string {
		return $state['work_dir'] . '/markdown';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function sqlPath(array $state): string {
		return $state['work_dir'] . '/sql/dump.sql';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function tarPath(array $state): string {
		return $state['work_dir'] . '.tar';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function cipherPath(array $state): string {
		return $state['work_dir'] . '.enc';
	}

	// -- Phase dispatch -------------------------------------------------

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private static function step(array $state, float $deadline): array {
		switch ($state['phase']) {
			case 'export':
				return self::stepExport($state);
			case 'dump':
				return self::stepDump($state, $deadline);
			case 'tar':
				return self::stepTar($state);
			case 'encrypt':
				return self::stepEncrypt($state, $deadline);
			case 'upload':
				return self::stepUpload($state, $deadline);
			default:
				$state['phase']        = 'failed';
				$state['failed_phase'] = (string) $state['phase'];
				$state['error']        = 'Unknown backup phase.';

				return $state;
		}
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private static function stepExport(array $state): array {
		$dir = self::markdownDir($state);
		if (! is_dir($dir) && (! mkdir($dir, 0700, true) && ! is_dir($dir))) {
			throw new \RuntimeException('Unable to create backup markdown directory.');
		}

		$files                    = MarkdownStage::export($dir);
		$state['markdown']['done']  = true;
		$state['markdown']['files'] = count($files);
		$state['phase']             = 'dump';

		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private static function stepDump(array $state, float $deadline): array {
		$sqlPath = self::sqlPath($state);
		$dir     = dirname($sqlPath);
		if (! is_dir($dir) && (! mkdir($dir, 0700, true) && ! is_dir($dir))) {
			throw new \RuntimeException('Unable to create backup SQL directory.');
		}

		if ($state['dump']['tables'] === null) {
			$state['dump']['tables'] = SqlDumper::listBackupTables();

			$out = fopen($sqlPath, 'wb');
			if ($out === false) {
				throw new \RuntimeException('Unable to open the SQL dump for writing.');
			}
			SqlDumper::writeHeader($out);
			fclose($out);
		}

		$tables = $state['dump']['tables'];

		while (microtime(true) < $deadline) {
			if ($state['dump']['table_index'] >= count($tables)) {
				$out = fopen($sqlPath, 'ab');
				if ($out === false) {
					throw new \RuntimeException('Unable to open the SQL dump for writing.');
				}
				SqlDumper::writeFooter($out);
				fclose($out);

				$state['dump']['done'] = true;
				$state['phase']        = 'tar';

				return $state;
			}

			$table = $tables[$state['dump']['table_index']];

			$out = fopen($sqlPath, 'ab');
			if ($out === false) {
				throw new \RuntimeException('Unable to open the SQL dump for writing.');
			}

			if (! $state['dump']['schema_written']) {
				SqlDumper::dumpTableSchema($out, $table);
				$state['dump']['schema_written'] = true;
			}

			$written = SqlDumper::dumpTableBatch($out, $table, $state['dump']['offset'], SqlDumper::BATCH_ROWS);

			if ($written > 0) {
				$state['dump']['offset'] += $written;
			}

			if ($written < SqlDumper::BATCH_ROWS) {
				// Table exhausted: same trailing blank line the single-shot
				// dumper writes after each table.
				fwrite($out, "\n");
				fclose($out);

				$state['dump']['table_index']++;
				$state['dump']['offset']         = 0;
				$state['dump']['schema_written'] = false;

				continue;
			}

			fclose($out);
		}

		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private static function stepTar(array $state): array {
		TarWriter::createFromDirectory($state['work_dir'], self::tarPath($state));

		$state['tar']['done'] = true;
		$state['phase']       = 'encrypt';

		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private static function stepEncrypt(array $state, float $deadline): array {
		$tarPath    = self::tarPath($state);
		$cipherPath = self::cipherPath($state);
		$key        = KeyManager::getOrCreateKey();

		if (! $state['encrypt']['header_written']) {
			[$sodiumState, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

			$out = fopen($cipherPath, 'wb');
			if ($out === false) {
				throw new \RuntimeException('Unable to open the encrypted backup for writing.');
			}
			fwrite($out, $header);
			fclose($out);

			$state['encrypt']['header_written'] = true;
			$state['encrypt']['state']          = base64_encode($sodiumState);
			$state['encrypt']['offset']         = 0;
		}

		$tarSize     = (int) filesize($tarPath);
		$sodiumState = base64_decode((string) $state['encrypt']['state'], true);
		if ($sodiumState === false) {
			throw new \RuntimeException('Corrupt encryption state; cannot resume this backup.');
		}

		$in = fopen($tarPath, 'rb');
		if ($in === false) {
			throw new \RuntimeException('Unable to open the tar snapshot for encryption.');
		}
		fseek($in, $state['encrypt']['offset']);

		$out = fopen($cipherPath, 'ab');
		if ($out === false) {
			fclose($in);
			throw new \RuntimeException('Unable to open the encrypted backup for writing.');
		}

		while (microtime(true) < $deadline) {
			$chunk  = fread($in, self::PLAINTEXT_CHUNK);
			$chunk  = $chunk === false ? '' : $chunk;
			$offset = $state['encrypt']['offset'] + strlen($chunk);
			$isLast = $offset >= $tarSize;

			$tag = $isLast
				? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
				: SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

			$cipherChunk = sodium_crypto_secretstream_xchacha20poly1305_push($sodiumState, $chunk, '', $tag);
			fwrite($out, $cipherChunk);

			$state['encrypt']['offset'] = $offset;
			$state['encrypt']['state']  = base64_encode($sodiumState);

			if ($isLast) {
				fclose($in);
				fclose($out);

				@unlink($tarPath);

				$bytes  = (int) filesize($cipherPath);
				$sha256 = hash_file('sha256', $cipherPath);
				if ($sha256 === false) {
					throw new \RuntimeException('Unable to hash the encrypted backup snapshot.');
				}

				$state['encrypt']['done']   = true;
				$state['encrypt']['bytes']  = $bytes;
				$state['encrypt']['sha256'] = $sha256;
				$state['phase']             = 'upload';

				return $state;
			}
		}

		fclose($in);
		fclose($out);

		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private static function stepUpload(array $state, float $deadline): array {
		$cipherPath = self::cipherPath($state);
		$backupId   = $state['backup_id'];

		if (! $state['upload']['inited']) {
			$init = UploadClient::init($backupId, $state['encrypt']['bytes'], (string) $state['encrypt']['sha256']);
			if (is_wp_error($init)) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic message, never rendered as HTML.
				throw new \RuntimeException($init->get_error_message());
			}

			$state['upload']['inited']      = true;
			$state['upload']['upload_path'] = $init['upload_url_path'];
		}

		$uploadPath = (string) $state['upload']['upload_path'];

		while (microtime(true) < $deadline) {
			$offset = $state['upload']['next_chunk'] * UploadClient::CHUNK_BYTES;

			if ($offset >= $state['encrypt']['bytes']) {
				if (! $state['upload']['complete_called']) {
					$complete = UploadClient::complete($uploadPath, $backupId, $state['upload']['next_chunk']);
					if (is_wp_error($complete)) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic message, never rendered as HTML.
						throw new \RuntimeException($complete->get_error_message());
					}
					$state['upload']['complete_called'] = true;
				}

				$state['phase'] = 'done';

				return $state;
			}

			$in = fopen($cipherPath, 'rb');
			if ($in === false) {
				throw new \RuntimeException('Unable to open the encrypted backup for upload.');
			}
			fseek($in, $offset);
			$chunk = fread($in, UploadClient::CHUNK_BYTES);
			fclose($in);

			if ($chunk === false || $chunk === '') {
				// Nothing left to send even though we haven't hit bytes yet
				// (shouldn't happen, but never loop forever).
				if (! $state['upload']['complete_called']) {
					$complete = UploadClient::complete($uploadPath, $backupId, $state['upload']['next_chunk']);
					if (is_wp_error($complete)) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic message, never rendered as HTML.
						throw new \RuntimeException($complete->get_error_message());
					}
					$state['upload']['complete_called'] = true;
				}

				$state['phase'] = 'done';

				return $state;
			}

			$result = UploadClient::sendChunk($uploadPath, $state['upload']['next_chunk'], $chunk);
			if (is_wp_error($result)) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic message, never rendered as HTML.
				throw new \RuntimeException($result->get_error_message());
			}

			$state['upload']['next_chunk']++;
		}

		return $state;
	}

	// -- Scheduling / cleanup / audit ------------------------------------

	private static function scheduleTick(): void {
		if (wp_next_scheduled(self::TICK_HOOK) === false) {
			wp_schedule_single_event(time() + 5, self::TICK_HOOK);
		}
	}

	private static function unscheduleTick(): void {
		$timestamp = wp_next_scheduled(self::TICK_HOOK);
		if ($timestamp !== false) {
			wp_unschedule_event($timestamp, self::TICK_HOOK);
		}
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private static function cleanupFiles(array $state): void {
		@unlink(self::tarPath($state));
		@unlink(self::cipherPath($state));

		$workDir = $state['work_dir'] ?? null;
		if (is_string($workDir) && $workDir !== '' && is_dir($workDir)) {
			self::removeDir($workDir);
		}
	}

	private static function removeDir(string $dir): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $fileInfo) {
			/** @var \SplFileInfo $fileInfo */
			$fileInfo->isDir() ? @rmdir($fileInfo->getPathname()) : @unlink($fileInfo->getPathname());
		}

		@rmdir($dir);
	}

	/**
	 * @param array<string, mixed>|null $state
	 * @param array<string, mixed> $extraMeta
	 */
	private static function audit(string $status, ?array $state, array $extraMeta = []): void {
		$meta = $extraMeta;
		if ($state !== null) {
			$meta['backup_id'] = $state['backup_id'] ?? null;
			$meta['phase']     = $state['phase'] ?? null;
			if (isset($state['error'])) {
				$meta['error'] = $state['error'];
			}
		}

		do_action('agent_brain/audit', [
			'actor_id'    => function_exists('get_current_user_id') ? get_current_user_id() : 0,
			'event'       => 'backup.run',
			'status'      => $status,
			'object_type' => 'backup',
			'object_id'   => $state['backup_id'] ?? null,
			'meta'        => $meta,
		]);
	}
}
