<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Review\Lifecycle;

/**
 * WP-CLI commands for PCP portability (`wp agent-brain ...`): pcp-ids,
 * pcp-signing-key, pcp-rotate-signing-key, export, import,
 * reference-imports, restore, principals and bind.
 */
final class Cli {

	/**
	 * Lists what a PCP export will call things: the controller, principals,
	 * partitions, page counts and a sample of page ids and paths. Read only;
	 * prints ids, names and paths, never memory or post content.
	 *
	 * ## OPTIONS
	 *
	 * [--sample=<n>]
	 * : Paths to show per kind (memory, knowledge, procedure).
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-brain pcp-ids
	 *     wp agent-brain pcp-ids --sample=50 --format=json
	 *
	 * @subcommand pcp-ids
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function pcp_ids(array $args, array $assocArgs): void {
		unset($args);

		$report = Report::build((int) ($assocArgs['sample'] ?? 10));

		if (($assocArgs['format'] ?? 'text') === 'json') {
			\WP_CLI::line((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return;
		}

		foreach (Report::lines($report) as $line) {
			\WP_CLI::line($line);
		}
	}

	/**
	 * Shows the brain's export signing key: the id and fingerprint that
	 * signature documents carry. The key itself never prints.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-brain pcp-signing-key
	 *
	 * @subcommand pcp-signing-key
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function pcp_signing_key(array $args, array $assocArgs): void {
		unset($args, $assocArgs);

		\WP_CLI::line(sprintf('key id: %s', SigningKey::id()));
		\WP_CLI::line(sprintf('fingerprint: %s', SigningKey::fingerprint()));
	}

	/**
	 * Rotates the brain's export signing key: the next exports are signed
	 * with a new key, and the current one is kept (up to 8 retired keys) so
	 * archives signed before still verify on import and restore. Archives
	 * signed with a key retired more than 8 rotations ago no longer verify.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-brain pcp-rotate-signing-key
	 *
	 * @subcommand pcp-rotate-signing-key
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function pcp_rotate_signing_key(array $args, array $assocArgs): void {
		unset($args, $assocArgs);

		SigningKey::rotate();

		\WP_CLI::success(sprintf('The export signing key is now %s (fingerprint %s); older archives keep verifying while their key stays retired.', SigningKey::id(), SigningKey::fingerprint()));
	}

	/**
	 * Makes a full PCP v0.3 export of this brain (no time limit). By default
	 * the archive is kept on the server like one made on the Export and
	 * import page; with --output it is written to that file instead and
	 * nothing is kept. Prints counts and the SHA-256, never content.
	 *
	 * ## OPTIONS
	 *
	 * [--output=<file>]
	 * : Write the archive to this file (it must not exist yet).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-brain export --user=admin
	 *     wp agent-brain export --output=/tmp/brain.pcp.zip
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function export(array $args, array $assocArgs): void {
		unset($args);

		if (function_exists('set_time_limit')) {
			set_time_limit(0);
		}

		$output  = (string) ($assocArgs['output'] ?? '');
		$exports = new Exports();

		if ($output !== '' && file_exists($output)) {
			\WP_CLI::error('The output file already exists.');
		}

		if ($output === '' && $exports->store()->streamOnly()) {
			\WP_CLI::error('Archives are not kept on this server (stream-only mode). Use --output=<file>.');
		}

		try {
			if ($output !== '') {
				$file = $exports->createFile(get_current_user_id(), 0.0);
				if (! rename($file['path'], $output)) {
					wp_delete_file($file['path']);
					\WP_CLI::error('Could not write the output file.');
				}
				chmod($output, 0600);
				$result = [
					'file'       => $output,
					'sha256'     => $file['sha256'],
					'bytes'      => $file['bytes'],
					'page_count' => (int) $file['manifest']['memory']['page_count'],
					'omitted'    => $file['manifest']['omitted'],
				];
			} else {
				$record = $exports->create(get_current_user_id(), 0.0);
				$result = Exports::view($record);
			}
		} catch (ExportFailed $e) {
			\WP_CLI::error($e->getMessage());
		}

		if (($assocArgs['format'] ?? 'text') === 'json') {
			\WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return;
		}

		foreach ($result as $key => $value) {
			\WP_CLI::line(sprintf('%s: %s', $key, is_array($value) ? (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES) : (string) $value));
		}
		\WP_CLI::success('Export complete.');
	}

	/**
	 * Migrates a whole brain here: a full PCP v0.3 archive of another brain
	 * into this one, which must be empty (no memory, knowledge, procedures,
	 * scopes or house rules; users may exist). This is a migration, not a
	 * backup restore (restoring a backup is not available yet). Every check runs
	 * before anything is written, and it is all or nothing. Migrated people
	 * and agents are unbound until bound (wp agent-brain bind). Prints
	 * counts, never content.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The archive (.zip, .tar or .tar.gz).
	 *
	 * [--dry-run]
	 * : Run every check and report what would be migrated, without writing.
	 *
	 * [--bind-as=<principal>]
	 * : Bind the --user account to this person of the archive (for example human:dennis).
	 *
	 * [--leave-scopes=<partitions>]
	 * : Comma-separated partitions to leave behind, with their pages, artifacts and handoffs.
	 *
	 * [--keep-scopes=<partitions>]
	 * : Or: the only partitions to bring (default always comes).
	 *
	 * [--leave-people=<principals>]
	 * : Comma-separated principal ids to leave behind; a trailing * matches a prefix (human:try-*).
	 *
	 * [--keep-people=<principals>]
	 * : Or: the only people and agents to bring.
	 *
	 * [--adopt-accounts]
	 * : Let an account here that already holds a principal id of the archive take that person over (it keeps its login, password and role). Without it such a migration is refused.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-brain migrate brain.pcp.zip --dry-run --user=admin
	 *     wp agent-brain migrate brain.pcp.zip --user=admin --bind-as=human:dennis
	 *     wp agent-brain migrate console.pcp.zip --user=admin --bind-as=human:dennis --keep-scopes=project --leave-people='human:try-*' --dry-run
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function migrate(array $args, array $assocArgs): void {
		if (function_exists('set_time_limit')) {
			set_time_limit(0);
		}

		$file = (string) ($args[0] ?? '');
		if ($file === '' || ! is_file($file) || ! is_readable($file)) {
			\WP_CLI::error('The archive file cannot be read.');
		}

		try {
			$result = ImportRest::run(ImportRest::MODE_MIGRATE, $file, get_current_user_id(), [
				'dry_run'      => isset($assocArgs['dry-run']),
				'bind_as'      => (string) ($assocArgs['bind-as'] ?? ''),
				'leave_scopes' => (string) ($assocArgs['leave-scopes'] ?? ''),
				'keep_scopes'  => (string) ($assocArgs['keep-scopes'] ?? ''),
				'leave_people' => (string) ($assocArgs['leave-people'] ?? ''),
				'keep_people'  => (string) ($assocArgs['keep-people'] ?? ''),
				'adopt_accounts' => isset($assocArgs['adopt-accounts']),
			]);
		} catch (ImportFailed $e) {
			\WP_CLI::error($e->getMessage());
		}

		self::printResult($result, (string) ($assocArgs['format'] ?? 'text'));
	}

	/**
	 * Deprecated name of migrate: a full PCP archive into an empty brain is a
	 * migration, not a backup restore (not available yet).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The archive (.zip, .tar or .tar.gz).
	 *
	 * [--dry-run]
	 * : Run every check and report what would be migrated, without writing.
	 *
	 * [--bind-as=<principal>]
	 * : Bind the --user account to this person of the archive.
	 *
	 * [--leave-scopes=<partitions>]
	 * : As for migrate.
	 *
	 * [--keep-scopes=<partitions>]
	 * : As for migrate.
	 *
	 * [--leave-people=<principals>]
	 * : As for migrate.
	 *
	 * [--keep-people=<principals>]
	 * : As for migrate.
	 *
	 * [--adopt-accounts]
	 * : As for migrate.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function restore(array $args, array $assocArgs): void {
		\WP_CLI::warning('"restore" is now "migrate" (a migration is not a backup restore); this name goes away in a later release.');
		$this->migrate($args, $assocArgs);
	}

	/**
	 * Imports the pages of a PCP v0.3 archive (full or partial) into this
	 * brain as proposals in the Review Queue, marked as imported. Every
	 * check runs before anything is written; a rerun skips what was
	 * imported before. Needs --user (the importer). Prints counts, never
	 * content.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The archive (.zip, .tar or .tar.gz).
	 *
	 * [--dry-run]
	 * : Run every check and report what would be imported, without writing.
	 *
	 * [--on-conflict=<mode>]
	 * : When a path is taken.
	 * ---
	 * default: rename
	 * options:
	 *   - rename
	 *   - skip
	 *   - replace
	 * ---
	 *
	 * [--partition=<name>]
	 * : The scope (partition name) for pages whose partition is not mapped.
	 * ---
	 * default: default
	 * ---
	 *
	 * [--partition-map=<json>]
	 * : Archive partition to scope here, as a JSON object.
	 *
	 * [--principal-map=<json>]
	 * : Archive principal to principal here, as a JSON object. Others become the importer.
	 *
	 * [--approve-all]
	 * : Approve at once the pages the source had approved (administrators, for the controller).
	 *
	 * [--as=<form>]
	 * : How the pages land. proposals: each waits in the Review Queue. reference (administrators, for the controller): approved once, and knowledge pages that name no lifecycle become dated reference (lifecycle stale), searchable after current knowledge without a review per page. Use reference for a Notion or other tool's export.
	 * ---
	 * default: proposals
	 * options:
	 *   - proposals
	 *   - reference
	 * ---
	 *
	 * [--confirm]
	 * : Import although the archive comes from another controller or a context with wider access.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-brain import other.pcp.zip --user=admin --dry-run
	 *     wp agent-brain import other.pcp.zip --user=admin --confirm --partition-map='{"notes":"team-shared"}'
	 *     wp agent-brain import notion.pcp.zip --user=admin --partition=team --as=reference
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function import(array $args, array $assocArgs): void {
		if (function_exists('set_time_limit')) {
			set_time_limit(0);
		}

		$file = (string) ($args[0] ?? '');
		if ($file === '' || ! is_file($file) || ! is_readable($file)) {
			\WP_CLI::error('The archive file cannot be read.');
		}

		$maps = [];
		foreach (['partition-map', 'principal-map'] as $key) {
			$maps[$key] = [];
			if (isset($assocArgs[$key])) {
				$decoded = json_decode((string) $assocArgs[$key], true);
				if (! is_array($decoded)) {
					\WP_CLI::error(sprintf('--%s must be a JSON object.', $key));
				}
				$maps[$key] = $decoded;
			}
		}

		try {
			$result = ImportRest::run('import', $file, get_current_user_id(), [
				'dry_run'       => isset($assocArgs['dry-run']),
				'on_conflict'   => (string) ($assocArgs['on-conflict'] ?? 'rename'),
				'partition'     => (string) ($assocArgs['partition'] ?? 'default'),
				'partition_map' => $maps['partition-map'],
				'principal_map' => $maps['principal-map'],
				'approve_all'   => isset($assocArgs['approve-all']),
				'as'            => (string) ($assocArgs['as'] ?? 'proposals'),
				'confirm'       => isset($assocArgs['confirm']),
				'client'        => 'wp-cli',
			]);
		} catch (ImportFailed $e) {
			\WP_CLI::error($e->getMessage());
		}

		self::printResult($result, (string) ($assocArgs['format'] ?? 'text'));
	}

	/**
	 * Moves chosen scopes of a PCP archive into this brain, which may be in
	 * use: the scopes (new, or mapped onto scopes here), their pages with
	 * history and provenance, their artifacts with HTML versions, page store
	 * data, seats and uploads, their handoffs, and the people who use them
	 * (new ones get unbound accounts). Administrators only. Every check runs
	 * first, it is all or nothing, and a rerun skips what came before.
	 * Prints counts, never content.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The archive (.zip, .tar or .tar.gz).
	 *
	 * --scopes=<partitions>
	 * : Comma-separated partitions of the archive to move.
	 *
	 * [--scope-map=<json>]
	 * : Archive partition to an existing scope here, as a JSON object. Unmapped ones keep their name (a new scope, or the scope here of that name).
	 *
	 * [--principal-map=<json>]
	 * : Archive principal to principal here, as a JSON object. Others are the principal here with the same id, or new unbound accounts.
	 *
	 * [--keep-approved]
	 * : Keep approved what the source had approved (the controller's choice). Otherwise everything waits for review here.
	 *
	 * [--on-conflict=<mode>]
	 * : When a page path is taken.
	 * ---
	 * default: rename
	 * options:
	 *   - rename
	 *   - skip
	 *   - replace
	 * ---
	 *
	 * [--dry-run]
	 * : Run every check and report what would be moved, without writing.
	 *
	 * [--confirm]
	 * : Move although the archive comes from another controller or a context with wider access.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-brain move-scopes console.pcp.zip --scopes=project --user=admin --dry-run
	 *     wp agent-brain move-scopes console.pcp.zip --scopes=project --user=admin --keep-approved --confirm
	 *
	 * @subcommand move-scopes
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function move_scopes(array $args, array $assocArgs): void {
		if (function_exists('set_time_limit')) {
			set_time_limit(0);
		}

		$file = (string) ($args[0] ?? '');
		if ($file === '' || ! is_file($file) || ! is_readable($file)) {
			\WP_CLI::error('The archive file cannot be read.');
		}

		$maps = [];
		foreach (['scope-map', 'principal-map'] as $key) {
			$maps[$key] = [];
			if (isset($assocArgs[$key])) {
				$decoded = json_decode((string) $assocArgs[$key], true);
				if (! is_array($decoded)) {
					\WP_CLI::error(sprintf('--%s must be a JSON object.', $key));
				}
				$maps[$key] = $decoded;
			}
		}

		try {
			$result = ImportRest::run(ImportRest::MODE_MOVE, $file, get_current_user_id(), [
				'dry_run'       => isset($assocArgs['dry-run']),
				'scopes'        => (string) ($assocArgs['scopes'] ?? ''),
				'scope_map'     => $maps['scope-map'],
				'principal_map' => $maps['principal-map'],
				'keep_approved' => isset($assocArgs['keep-approved']),
				'on_conflict'   => (string) ($assocArgs['on-conflict'] ?? 'rename'),
				'confirm'       => isset($assocArgs['confirm']),
				'client'        => 'wp-cli',
			]);
		} catch (ImportFailed $e) {
			\WP_CLI::error($e->getMessage());
		}

		self::printResult($result, (string) ($assocArgs['format'] ?? 'text'));
	}

	/**
	 * Turns the proposals an earlier import left in a scope into reference
	 * pages (#594), as an import with --as=reference would have landed
	 * them: approved once by you as the controller, and dated reference
	 * (lifecycle stale) that search finds after current knowledge. Only
	 * imported proposals are touched; one audit entry. Administrators only.
	 *
	 * ## OPTIONS
	 *
	 * <scope>
	 * : The scope, by id or slug.
	 *
	 * [--exporter=<name>]
	 * : Only pages from archives whose exporter name starts with this (for example notion-pcp).
	 *
	 * [--dry-run]
	 * : Count the pages without changing them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-brain reference-imports team --exporter=notion-pcp --user=admin --dry-run
	 *
	 * @subcommand reference-imports
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function reference_imports(array $args, array $assocArgs): void {
		$wanted = (string) ($args[0] ?? '');
		$term   = ctype_digit($wanted) ? get_term((int) $wanted, Scopes::TAXONOMY) : get_term_by('slug', $wanted, Scopes::TAXONOMY);
		if (! $term instanceof \WP_Term) {
			\WP_CLI::error('No such scope.');
		}

		$dryRun = isset($assocArgs['dry-run']);
		$result = Lifecycle::reference(get_current_user_id(), (int) $term->term_id, (string) ($assocArgs['exporter'] ?? ''), $dryRun);
		if ($result instanceof \WP_Error) {
			\WP_CLI::error($result->get_error_message());
		}

		\WP_CLI::line('matched: ' . $result['matched']);
		\WP_CLI::line('switched: ' . $result['switched']);
		\WP_CLI::success($dryRun ? 'Dry run, nothing changed.' : 'Done.');
	}

	/**
	 * Invites the people of a migrated or moved brain in bulk: each unbound
	 * person is bound to an email address and gets the link to set a
	 * password by email. Links are never printed; when mail fails they go
	 * to --links-to, a file only you can read.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : A CSV file of "principal,email" lines (for example human:roger,roger@example.org).
	 *
	 * [--links-to=<file>]
	 * : Where to write the links of invitations whose mail failed (a new file, mode 0600).
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-brain invite-people people.csv --user=admin --links-to=/root/links.txt
	 *
	 * @subcommand invite-people
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function invite_people(array $args, array $assocArgs): void {
		$file = (string) ($args[0] ?? '');
		if ($file === '' || ! is_file($file) || ! is_readable($file)) {
			\WP_CLI::error('The CSV file cannot be read.');
		}
		if (! Binding::canBind(get_current_user_id())) {
			\WP_CLI::error('Run this as an administrator (--user).');
		}

		$invites = [];
		foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
			$cells = array_map('trim', str_getcsv($line));
			if (count($cells) >= 2 && str_contains($cells[0], ':')) {
				$invites[$cells[0]] = $cells[1];
			}
		}
		if ($invites === []) {
			\WP_CLI::error('No "principal,email" lines in the file.');
		}

		$links = [];
		$ok    = 0;
		foreach (Reconnect::invite($invites, get_current_user_id()) as $principal => $result) {
			if (! $result['ok']) {
				\WP_CLI::line(sprintf('%s: not invited: %s', $principal, (string) ($result['error'] ?? '')));
				continue;
			}
			++$ok;
			if (! empty($result['mailed'])) {
				\WP_CLI::line(sprintf('%s: invited, link mailed', $principal));
			} else {
				$links[] = $principal . ' ' . (string) ($result['link'] ?? '');
				\WP_CLI::line(sprintf('%s: bound, but the mail failed: the link is in the --links-to file', $principal));
			}
		}

		if ($links !== []) {
			self::writePrivate((string) ($assocArgs['links-to'] ?? ''), implode("\n", $links) . "\n", 'Some mails failed; give --links-to to get their links.');
		}
		\WP_CLI::success(sprintf('%d of %d people invited.', $ok, count($invites)));
	}

	/**
	 * Gives every unbound agent (or the ones named) a new key in one step:
	 * each is bound with a new application password. The keys go to
	 * --output, a new file only you can read, never to the terminal.
	 *
	 * ## OPTIONS
	 *
	 * --output=<file>
	 * : Where to write the keys (a new file, mode 0600).
	 *
	 * [--agents=<principals>]
	 * : Comma-separated agent principal ids; default every unbound agent.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-brain agent-keys --user=admin --output=/root/agent-keys.txt
	 *
	 * @subcommand agent-keys
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function agent_keys(array $args, array $assocArgs): void {
		unset($args);
		if (! Binding::canBind(get_current_user_id())) {
			\WP_CLI::error('Run this as an administrator (--user).');
		}
		$output = (string) ($assocArgs['output'] ?? '');
		if ($output === '' || file_exists($output)) {
			\WP_CLI::error('Give --output a path for a new file: the keys are written there and nowhere else.');
		}

		$only = isset($assocArgs['agents']) ? array_values(array_filter(array_map('trim', explode(',', (string) $assocArgs['agents'])))) : null;
		$keys = Reconnect::keys($only, get_current_user_id());
		if ($keys === []) {
			\WP_CLI::success('Every agent is bound already; no keys were made.');

			return;
		}

		self::writePrivate($output, Reconnect::keyFile($keys), '');
		$issued = count(array_filter($keys, static fn (array $k): bool => isset($k['application_password'])));
		foreach ($keys as $principal => $key) {
			\WP_CLI::line(sprintf('%s: %s', $principal, isset($key['application_password']) ? 'key issued' : 'not issued: ' . (string) ($key['error'] ?? '')));
		}
		\WP_CLI::success(sprintf('%d keys written to %s.', $issued, $output));
	}

	/**
	 * The reconnect checklist: this brain's connector address, who is not
	 * back yet and the share links to create again. No secrets.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function reconnect(array $args, array $assocArgs): void {
		unset($args);
		$list = Reconnect::checklist();
		if (($assocArgs['format'] ?? 'text') === 'json') {
			\WP_CLI::line((string) wp_json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

			return;
		}

		\WP_CLI::line('Connector address: ' . $list['connector']);
		foreach (['people' => 'People', 'agents' => 'Agents'] as $key => $label) {
			$waiting = array_filter($list[$key], static fn (array $p): bool => ! $p['bound']);
			\WP_CLI::line(sprintf('%s: %d, %d not back yet%s', $label, count($list[$key]), count($waiting), $waiting !== [] ? ' (' . implode(', ', array_column($waiting, 'id')) . ')' : ''));
		}
		\WP_CLI::line(sprintf('Seats to claim back: %d%s', count($list['seats']), $list['seats'] !== [] ? ' (release one with the artifact-store-config tool, release_seat, or on the Reconnect section)' : ''));
		\WP_CLI::line(sprintf('Share links to create again: %d', count($list['shares'])));
		foreach ($list['shares'] as $share) {
			\WP_CLI::line(sprintf('  #%d %s (%s, %s)', $share['artifact'], $share['title'], $share['kind'], $share['privacy']));
		}
	}

	private static function writePrivate(string $path, string $bytes, string $missing): void {
		if ($path === '') {
			\WP_CLI::error($missing);
		}
		if (file_exists($path)) {
			\WP_CLI::error('The file exists already; give a new path.');
		}
		$old = umask(0077);
		$ok  = file_put_contents($path, $bytes); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a private file the administrator named.
		umask($old);
		if ($ok === false) {
			\WP_CLI::error('The file could not be written.');
		}
		chmod($path, 0600); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- keys and links: readable by the owner only.
	}

	/**
	 * Lists the principals of this brain and whether each is bound.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function principals(array $args, array $assocArgs): void {
		unset($args);

		$rows = array_map(static function (array $p): array {
			return [
				'id'           => $p['id'],
				'display_name' => $p['display_name'],
				'roles'        => implode(',', $p['roles']),
				'status'       => $p['status'],
				'bound'        => $p['user_id'] === 0 ? 'no account' : ($p['bound'] ? 'yes' : 'no'),
			];
		}, Binding::principals());

		if (($assocArgs['format'] ?? 'text') === 'json') {
			\WP_CLI::line((string) wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return;
		}

		foreach ($rows as $row) {
			\WP_CLI::line(implode("\t", $row));
		}
	}

	/**
	 * Binds a principal a restore brought back. A person needs --email and
	 * gets a link to set a password (mailed unless --no-mail; printed
	 * either way). An agent gets a new application password, printed once.
	 *
	 * ## OPTIONS
	 *
	 * <principal>
	 * : The principal id, for example human:roger or agent:claude-code-a3f2c819.
	 *
	 * [--email=<email>]
	 * : The person's email address.
	 *
	 * [--no-mail]
	 * : Do not mail the link; only print it.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function bind(array $args, array $assocArgs): void {
		try {
			$result = Binding::bind(
				(string) ($args[0] ?? ''),
				get_current_user_id(),
				(string) ($assocArgs['email'] ?? ''),
				! isset($assocArgs['no-mail'])
			);
		} catch (ImportFailed $e) {
			\WP_CLI::error($e->getMessage());
		}

		if (isset($result['application_password'])) {
			\WP_CLI::line('Application password (shown once): ' . $result['application_password']);
		}
		if (isset($result['link'])) {
			\WP_CLI::line('Set-password link (works once): ' . $result['link']);
			\WP_CLI::line('Mailed: ' . (! empty($result['mailed']) ? 'yes' : 'no'));
		}
		\WP_CLI::success('Bound ' . $result['principal'] . '.');
	}

	/**
	 * Sets up a new brain from a few onboarding answers (#457): a starter
	 * team of agents, house rules, an "About us" item, a pinned "How this
	 * brain works" guide and, for a launch or project, an "Our plan"
	 * outline. Refused on a brain that already has agents or was seeded.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : JSON answers: team (text), uses (decisions, launch, docs, onboarding, support, other), people (text), ai (claude, claude-code, chatgpt, cursor, other). Reads stdin when omitted.
	 *
	 * [--force]
	 * : Seed even when the brain already has agents or was seeded.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tomatio seed --file=answers.json --user=admin
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assocArgs
	 */
	public function seed(array $args, array $assocArgs): void {
		unset($args);
		$file = (string) ($assocArgs['file'] ?? 'php://stdin');
		$json = $file !== '' ? file_get_contents($file) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file or stdin.
		$data = is_string($json) ? json_decode($json, true) : null;
		if (! is_array($data)) {
			\WP_CLI::error('The answers must be a JSON object.');
		}

		$result = \AgentBrain\Modules\Admin\BrainSeed::run(get_current_user_id(), $data, isset($assocArgs['force']));
		if ($result instanceof \WP_Error) {
			\WP_CLI::error($result->get_error_message());
		}

		\WP_CLI::line((string) wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		\WP_CLI::success(sprintf('Seeded with %d agent(s).', count($result['agents'])));
	}

	/**
	 * @param array{report:array<string, mixed>, errors:array<int, string>, notes:array<int, string>} $result
	 */
	private static function printResult(array $result, string $format): void {
		$report = $result['report'];

		if ($format === 'json') {
			\WP_CLI::line((string) wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		} else {
			\WP_CLI::line(sprintf('status: %s%s', (string) $report['status'], ! empty($report['dry_run']) ? ' (dry run, nothing written)' : ''));
			foreach ((array) $report['counts'] as $outcome => $count) {
				\WP_CLI::line(sprintf('%s: %d', (string) $outcome, (int) $count));
			}
			foreach ($result['errors'] as $line) {
				\WP_CLI::line('error: ' . $line);
			}
			foreach ($result['notes'] as $line) {
				\WP_CLI::line('note: ' . $line);
			}
		}

		if ($report['status'] === 'rejected') {
			\WP_CLI::error('Rejected. Nothing was written.');
		}
		\WP_CLI::success(! empty($report['dry_run']) ? 'Checks passed.' : 'Done.');
	}
}
