<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\Admin\Confirm;
use AgentBrain\Modules\Admin\DateFormat;
use AgentBrain\Modules\Admin\EmptyState;
use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\Notices;
use AgentBrain\Modules\Admin\Ui;

/**
 * "Export and import": full PCP exports for administrators (decision 6:
 * ab_manage and manage_options; curators hold only the first). Making, downloading and deleting an archive are admin-post
 * actions behind a nonce; downloads go through the access check, never a
 * public URL. Migrating a whole brain here (a full archive into an empty
 * brain, PCP's restore mode) and importing an archive's pages as proposals
 * are uploads behind the same checks; the Principals section binds what a
 * migration brought back (PrincipalsPage). Backups are another flow, on the
 * Backups page; restoring one is not available yet (Backup\Restore only
 * unpacks a blob for inspection).
 */
final class ExportPage {

	/** The menu entry; every handler also checks Exports::canExport(). */
	public const CAPABILITY      = 'manage_options';
	public const SLUG            = 'agent-brain-export';
	public const CREATE_ACTION   = 'agent_brain_export_create';
	public const EXPORT_DOWNLOAD = 'agent_brain_export_download';
	public const DELETE_ACTION   = 'agent_brain_export_delete';
	public const SETTINGS_ACTION = 'agent_brain_export_settings';
	public const MIGRATE_ACTION  = 'agent_brain_migrate';
	public const IMPORT_ACTION   = 'agent_brain_import';
	public const MOVE_ACTION     = 'agent_brain_move';

	private const RESULT = 'ab_pcp_import_result_';

	public static function register(): void {
		add_action('admin_menu', [self::class, 'addMenu']);
		add_action('admin_post_' . self::CREATE_ACTION, [self::class, 'handleCreate']);
		add_action('admin_post_' . self::EXPORT_DOWNLOAD, [self::class, 'handleDownload']);
		add_action('admin_post_' . self::DELETE_ACTION, [self::class, 'handleDelete']);
		add_action('admin_post_' . self::SETTINGS_ACTION, [self::class, 'handleSettings']);
		add_action('admin_post_' . self::MIGRATE_ACTION, [self::class, 'handleMigrate']);
		add_action('admin_post_' . self::IMPORT_ACTION, [self::class, 'handleImport']);
		add_action('admin_post_' . self::MOVE_ACTION, [self::class, 'handleMove']);
		PrincipalsPage::register();
		ReconnectPage::register();
	}

	public static function addMenu(): void {
		add_submenu_page(
			Menu::PARENT,
			__('Export and import', 'agent-brain'),
			__('Export and import', 'agent-brain'),
			self::CAPABILITY,
			self::SLUG,
			[self::class, 'render']
		);
	}

	public static function render(): void {
		self::guard();

		$store      = new ExportStore();
		$streamOnly = $store->streamOnly();
		$hours      = ExportStore::retentionHours();
		$records    = $streamOnly ? [] : $store->all();

		$status = $streamOnly
			? esc_html__('Archives are built and downloaded in one step', 'agent-brain')
			: ($records === []
				? esc_html__('No archive kept here', 'agent-brain')
				/* translators: %s: when the newest kept archive was made. */
				: sprintf(esc_html__('Newest kept archive: %s', 'agent-brain'), '<b>' . esc_html(DateFormat::text((string) $records[0]['created'])) . '</b>'));

		echo '<div class="wrap ab-admin ab-admin-export">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::head(__('Export and import', 'agent-brain'), $status, '', self::aboutHtml($hours));

		echo '<div class="ab-ui-grid">';
		self::renderExportCard($streamOnly, $hours);
		self::renderImport();
		echo '</div>';

		if (! $streamOnly) {
			self::renderHeld($records, $hours);
		}

		self::renderMigrate();
		self::renderMove();
		self::renderBackupsPointer();
		echo '<section class="ab-ui-card ab-ui-card--headed">';
		ReconnectPage::render();
		echo '</section><section class="ab-ui-card ab-ui-card--headed">';
		PrincipalsPage::render();
		echo '</section>';

		echo '</div>';
	}

	/**
	 * The long explanations that used to sit above the button (#751): what
	 * an archive holds, that it is not encrypted, the signing key, and how
	 * long a kept archive lasts.
	 */
	private static function aboutHtml(int $hours): string {
		return '<p>' . esc_html__('A full export is a portable archive of this brain in the open PCP v0.3 format: every memory, knowledge item and procedure (including proposals and rejections) with its history, the people and agents, the scopes and who can use them, the house rules, the audit log and the forget receipts. Another server that speaks PCP can read it without this one.', 'agent-brain') . '</p>'
			. '<p>' . esc_html__('The archive is not encrypted and holds no passwords, tokens or keys: store it as carefully as the brain itself. Agents and connections are authorized again wherever it is migrated or imported.', 'agent-brain') . '</p>'
			. '<p>' . sprintf(
				/* translators: 1: the signing key id, 2: its fingerprint, 3: the rotate command. */
				esc_html__('Every export is signed with this brain\'s own signing key (%1$s, fingerprint %2$s). When an archive comes back to this brain, the signature is checked and one altered after export is refused. Another brain cannot check it, since only this brain holds the key: it migrates or imports the archive with a note that its origin cannot be verified. The key never leaves this server; rotate it with %3$s.', 'agent-brain'),
				esc_html(SigningKey::id()),
				esc_html(SigningKey::fingerprint()),
				'<code>wp tomatio pcp-rotate-signing-key</code>'
			) . '</p>'
			. '<p>' . sprintf(
				/* translators: %d: hours an archive is kept. */
				esc_html__('A kept archive lasts %d hours, and on hosting without lasting storage it can disappear sooner, when the server restarts. Forget deletes every kept archive.', 'agent-brain'),
				$hours
			) . '</p>';
	}

	/** "Take everything with you": one button and one line on how long it is kept. */
	private static function renderExportCard(bool $streamOnly, int $hours): void {
		echo '<section class="ab-ui-card"><h2 class="ab-ui-kicker">' . esc_html__('Take everything with you', 'agent-brain') . '</h2>';
		echo '<p>' . esc_html__('One archive with every memory, fact, procedure, document, person and scope, in the open PCP format. Signed by this brain.', 'agent-brain') . '</p>';

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::CREATE_ACTION);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::CREATE_ACTION) . '" />';
		submit_button($streamOnly ? __('Export and download', 'agent-brain') : __('Make a full export', 'agent-brain'), 'primary', 'submit', false, ['id' => 'ab-export-create']);
		echo '</form>';

		if ($streamOnly) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
			echo Ui::callout('warn', __('Nothing is kept on this server.', 'agent-brain'), esc_html__('It could serve files from the private export folder to anyone, so the export is built and downloaded in one step. Ask your host to set AGENT_BRAIN_EXPORT_DIR to a folder outside the web root.', 'agent-brain'));
		} else {
			echo '<p class="ab-ui-hint">' . sprintf(
				/* translators: 1: hours an archive is kept, 2: link to the setting. */
				esc_html__('Download the archive right away: it is kept here for %1$d hours. %2$s', 'agent-brain'),
				absint($hours),
				'<a href="#ab-export-retention">' . esc_html__('Change', 'agent-brain') . '</a>'
			) . '</p>';
		}

		echo '</section>';
	}

	/**
	 * @param array<int, array<string, mixed>> $records
	 */
	private static function renderHeld(array $records, int $hours): void {
		echo '<section class="ab-ui-card"><h2 class="ab-ui-kicker">' . esc_html__('Kept archives', 'agent-brain') . '</h2>';

		if ($records === []) {
			EmptyState::render(
				__('None kept', 'agent-brain'),
				__('A full export shows up here until it expires or you delete it.', 'agent-brain'),
				null
			);
		} else {
			self::renderHeldTable($records);
		}

		self::renderSettings($hours);
		echo '</section>';
	}

	/**
	 * @param array<int, array<string, mixed>> $records
	 */
	private static function renderHeldTable(array $records): void {

		echo '<div class="ab-table-wrap"><table class="widefat striped"><thead><tr>'
			. '<th scope="col">' . esc_html__('Made', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Expires', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Pages', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Size', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('SHA-256', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Left out', 'agent-brain') . '</th>'
			. '<th scope="col"><span class="screen-reader-text">' . esc_html__('Actions', 'agent-brain') . '</span></th>'
			. '</tr></thead><tbody>';

		foreach ($records as $record) {
			$id       = (string) $record['id'];
			$download = wp_nonce_url(
				add_query_arg(['action' => self::EXPORT_DOWNLOAD, 'export' => $id], admin_url('admin-post.php')),
				self::EXPORT_DOWNLOAD . '_' . $id
			);
			$omitted = 0;
			foreach ((array) $record['omitted'] as $item) {
				$omitted += is_array($item) ? (int) ($item['count'] ?? 0) : 0;
			}

			echo '<tr>'
				. '<td>' . DateFormat::html((string) $record['created']) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- DateFormat::html() escapes.
				. '<td>' . DateFormat::html((string) $record['expires_at']) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- DateFormat::html() escapes.
				. '<td>' . esc_html(number_format_i18n((int) $record['page_count'])) . '</td>'
				. '<td>' . esc_html(size_format((int) $record['bytes'])) . '</td>'
				. '<td><code title="' . esc_attr((string) $record['sha256']) . '">' . esc_html(substr((string) $record['sha256'], 0, 12)) . '</code></td>'
				. '<td>' . esc_html(number_format_i18n($omitted)) . '</td>'
				. '<td><a class="button button-primary" href="' . esc_url($download) . '">' . esc_html__('Download', 'agent-brain') . '</a> ';

			$confirm = Confirm::attr(Confirm::message(__('Delete this archive from the server; copies you downloaded are not affected', 'agent-brain')));
			echo '<form method="post" style="display:inline" action="' . esc_url(admin_url('admin-post.php')) . '"' . $confirm . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Confirm::attr() escapes.
			echo '<input type="hidden" name="action" value="' . esc_attr(self::DELETE_ACTION) . '" />';
			echo '<input type="hidden" name="export" value="' . esc_attr($id) . '" />';
			wp_nonce_field(self::DELETE_ACTION . '_' . $id);
			echo '<button type="submit" class="button">' . esc_html__('Delete', 'agent-brain') . '</button></form></td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/** How long archives are kept, as one row with a Save bar (#751). */
	private static function renderSettings(int $hours): void {
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::SETTINGS_ACTION);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::SETTINGS_ACTION) . '" />';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::setting(
			__('Keep archives for (hours)', 'agent-brain'),
			esc_html(sprintf(
				/* translators: %d: hours */
				_n('%d hour, then the archive is deleted.', '%d hours, then the archive is deleted.', $hours, 'agent-brain'),
				$hours
			)),
			'<input type="number" class="small-text" id="ab-export-retention" name="ab_export_retention_hours" min="1" max="' . esc_attr((string) ExportStore::MAX_RETENTION_HOURS) . '" value="' . esc_attr((string) $hours) . '" />',
			'ab-export-retention',
			'<p>' . esc_html__('From 1 hour to 7 days (168 hours). Expired archives are deleted every hour.', 'agent-brain') . '</p>'
		);
		echo Ui::saveBar('ab_export_settings_submit', __('Save', 'agent-brain'));
		// phpcs:enable
		echo '</form>';
	}

	private static function renderMigrate(): void {
		echo '<section class="ab-ui-card" id="migrate"><h2 class="ab-ui-kicker">' . esc_html__('Migrate a brain here', 'agent-brain') . '</h2>';
		echo '<p>' . esc_html__('Bring a whole brain from its full export into this empty one.', 'agent-brain') . '</p>';
		echo '<details class="ab-ui-set-more"><summary>' . esc_html__('What moves', 'agent-brain') . '</summary><div><p>' . esc_html__('Migrating moves a whole brain to this site from its full PCP export: its memory, knowledge and procedures with their ids, paths and history, its documents and HTML artifacts, its scopes and who can use them, its house rules, its audit log and forget receipts. It only works on an empty brain, and it is all or nothing: if anything fails, nothing is kept. Passwords, tokens and connections never travel, so every person and agent comes back unbound until you bind them below.', 'agent-brain') . '</p></div></details>';

		self::renderResult(ImportRest::MODE_RESTORE);

		$problems = Restores::notEmpty();
		if ($problems !== []) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
			echo Ui::callout('info', __('This brain is not empty.', 'agent-brain'), esc_html(sprintf(
				/* translators: %s: why the brain is not empty. */
				__('A whole brain can only be migrated into an empty one (%s).', 'agent-brain'),
				implode('; ', $problems)
			)));
			echo '</section>';

			return;
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::MIGRATE_ACTION);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::MIGRATE_ACTION) . '" />';
		echo '<p><label for="ab-migrate-archive">' . esc_html__('Full export of the brain (.pcp.zip, .tar or .tar.gz)', 'agent-brain') . '</label><br />';
		echo '<input type="file" id="ab-migrate-archive" name="archive" required accept=".zip,.tar,.gz,.tgz" /></p>';
		echo '<p><label for="ab-migrate-bind">' . esc_html__('Bind me to this person of the archive (optional)', 'agent-brain') . '</label><br />';
		echo '<input type="text" id="ab-migrate-bind" name="bind_as" class="regular-text code" placeholder="human:" value="" /></p>';
		echo '<p class="description">' . esc_html__('Your account then carries that person\'s principal id, scopes and history. Leave it empty to keep your own id.', 'agent-brain') . '</p>';
		echo '<fieldset><legend>' . esc_html__('Leave some behind (optional)', 'agent-brain') . '</legend>';
		echo '<p class="description">' . esc_html__('For example demo scopes and demo logins. Run a dry run first: it lists every scope and person of the archive.', 'agent-brain') . '</p>';
		echo '<p><label for="ab-migrate-keep-scopes">' . esc_html__('Bring only these scopes (partition names, comma-separated)', 'agent-brain') . '</label><br />';
		echo '<input type="text" id="ab-migrate-keep-scopes" name="keep_scopes" class="regular-text code" value="" /></p>';
		echo '<p><label for="ab-migrate-leave-scopes">' . esc_html__('Or leave these scopes behind', 'agent-brain') . '</label><br />';
		echo '<input type="text" id="ab-migrate-leave-scopes" name="leave_scopes" class="regular-text code" value="" /></p>';
		echo '<p><label for="ab-migrate-leave-people">' . esc_html__('Leave these people and agents behind (principal ids; human:try-* matches every id that starts so)', 'agent-brain') . '</label><br />';
		echo '<input type="text" id="ab-migrate-leave-people" name="leave_people" class="regular-text code" value="" /></p>';
		echo '</fieldset>';
		echo '<p><label><input type="checkbox" name="adopt_accounts" value="1" /> ' . esc_html__('Let accounts here take over their archive identity: an account that already holds a principal id of the archive becomes that person (it keeps its login, password and role). The dry run lists each one.', 'agent-brain') . '</label></p>';
		echo '<p><label><input type="checkbox" name="dry_run" value="1" checked /> ' . esc_html__('Dry run: check the archive and show what would be migrated, without writing anything', 'agent-brain') . '</label></p>';
		submit_button(__('Check or migrate', 'agent-brain'), 'primary', 'submit', false, ['id' => 'ab-migrate-submit']);
		echo '</form></section>';
	}

	/** #550: chosen scopes of an archive into this brain, which may be in use. */
	private static function renderMove(): void {
		echo '<section class="ab-ui-card" id="move"><h2 class="ab-ui-kicker">' . esc_html__('Move scopes here', 'agent-brain') . '</h2>';
		echo '<p>' . esc_html__('Bring chosen scopes of another brain\'s export into this one, even while it is in use.', 'agent-brain') . '</p>';
		echo '<details class="ab-ui-set-more"><summary>' . esc_html__('What moves', 'agent-brain') . '</summary><div><p>' . esc_html__('Moving brings chosen scopes of another brain\'s export into this one, in use or not: the scopes and who can use them, their memories, knowledge and procedures with history and provenance, their documents and HTML artifacts with versions, page store data, seats and uploads, and their handoffs. People new here get accounts that are unbound until you invite them. Unless you keep approvals, everything waits for review here. A dry run shows what would happen; running it again skips what came before.', 'agent-brain') . '</p></div></details>';

		self::renderResult('move');

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::MOVE_ACTION);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::MOVE_ACTION) . '" />';
		echo '<p><label for="ab-move-archive">' . esc_html__('Archive (.pcp.zip, .tar or .tar.gz)', 'agent-brain') . '</label><br />';
		echo '<input type="file" id="ab-move-archive" name="archive" required accept=".zip,.tar,.gz,.tgz" /></p>';
		echo '<p><label for="ab-move-scopes">' . esc_html__('Scopes to move (partition names of the archive, comma-separated)', 'agent-brain') . '</label><br />';
		echo '<input type="text" id="ab-move-scopes" name="scopes" class="regular-text code" required value="" /></p>';
		echo '<p><label for="ab-move-map">' . esc_html__('Into existing scopes: one "archive partition = scope here" per line (optional; others keep their name)', 'agent-brain') . '</label><br />';
		echo '<textarea id="ab-move-map" name="scope_map" rows="2" class="large-text code" placeholder="project = nautilus-project"></textarea></p>';
		echo '<p><label for="ab-move-umap">' . esc_html__('Principal map: one "archive principal = principal here" per line (optional; others are the same id here, or new unbound accounts)', 'agent-brain') . '</label><br />';
		echo '<textarea id="ab-move-umap" name="principal_map" rows="2" class="large-text code" placeholder="human:dennis = human:dk"></textarea></p>';
		echo '<p><label><input type="checkbox" name="keep_approved" value="1" /> ' . esc_html__('Keep approved what the source had approved (as the controller); otherwise everything waits for review', 'agent-brain') . '</label></p>';
		echo '<p><label><input type="checkbox" name="confirm" value="1" /> ' . esc_html__('I have read the dry run\'s warnings (another controller, or wider access) and want to move', 'agent-brain') . '</label></p>';
		echo '<p><label><input type="checkbox" name="dry_run" value="1" checked /> ' . esc_html__('Dry run: check the archive and show what would be moved, without writing anything', 'agent-brain') . '</label></p>';
		submit_button(__('Check or move', 'agent-brain'), 'primary', 'submit_move', false);
		echo '</form></section>';
	}

	public static function handleMove(): void {
		self::guard();
		check_admin_referer(self::MOVE_ACTION);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- an uploaded file's temporary path, checked with is_uploaded_file().
		$file  = isset($_FILES['archive']['tmp_name']) ? (string) $_FILES['archive']['tmp_name'] : '';
		$error = isset($_FILES['archive']['error']) ? (int) $_FILES['archive']['error'] : UPLOAD_ERR_NO_FILE;
		if ($error !== UPLOAD_ERR_OK || $file === '' || ! is_uploaded_file($file)) {
			Notices::flash('error', __('The archive did not upload. Check its size against the upload limit, or move with WP-CLI (wp tomatio move-scopes).', 'agent-brain'));
			self::redirect();
		}

		if (function_exists('set_time_limit')) {
			set_time_limit(0);
		}

		try {
			$result         = ImportRest::run(ImportRest::MODE_MOVE, $file, get_current_user_id(), [
				'dry_run'       => ! empty($_POST['dry_run']),
				'confirm'       => ! empty($_POST['confirm']),
				'keep_approved' => ! empty($_POST['keep_approved']),
				'scopes'        => isset($_POST['scopes']) ? sanitize_text_field(wp_unslash($_POST['scopes'])) : '',
				'scope_map'     => self::pairs(isset($_POST['scope_map']) ? sanitize_textarea_field(wp_unslash($_POST['scope_map'])) : ''),
				'principal_map' => self::pairs(isset($_POST['principal_map']) ? sanitize_textarea_field(wp_unslash($_POST['principal_map'])) : ''),
				'client'        => 'wp-admin',
			]);
			$result['flow'] = 'move';
			set_transient(self::RESULT . get_current_user_id(), $result, 600);
		} catch (ImportFailed $e) {
			Notices::flash('error', $e->getMessage());
		}

		wp_safe_redirect(add_query_arg(['page' => self::SLUG], admin_url('admin.php')) . '#move');
		exit;
	}

	/** Backups are not exports: say where they are, and that restoring is not there yet. */
	private static function renderBackupsPointer(): void {
		echo '<section class="ab-ui-card" id="backups"><h2 class="ab-ui-kicker">' . esc_html__('Backups', 'agent-brain') . '</h2>';
		echo '<p>' . sprintf(
			/* translators: %s: link to the Backups page. */
			esc_html__('Migrating and importing move context between brains. Encrypted backups of this brain are made on %s. Restoring a backup is not available yet: to be able to rebuild this brain, keep a full export somewhere safe.', 'agent-brain'),
			'<a href="' . esc_url(admin_url('admin.php?page=' . \AgentBrain\Modules\Backup\SettingsPage::PAGE_SLUG)) . '">' . esc_html__('Backups', 'agent-brain') . '</a>'
		) . '</p></section>';
	}

	private static function renderImport(): void {
		echo '<section class="ab-ui-card" id="import"><h2 class="ab-ui-kicker">' . esc_html__('Bring pages in', 'agent-brain') . '</h2>';
		echo '<details class="ab-ui-set-more"><summary>' . esc_html__('What an import does', 'agent-brain') . '</summary><div><p>' . esc_html__('Importing adds the pages of a PCP archive (from another brain or another PCP tool) to this brain as proposals in the Review Queue, marked as imported. Nothing else changes: scopes, people and access stay as they are. Running the same import again skips what it already imported.', 'agent-brain') . '</p></div></details>';

		self::renderResult('import');

		$scopes = [];
		foreach (Partitions::all() as $row) {
			if ($row['name'] !== null) {
				$term                  = get_term($row['term_id'], \AgentBrain\Modules\Identity\Scopes::TAXONOMY);
				$scopes[$row['name']] = $term instanceof \WP_Term ? $term->name : $row['name'];
			}
		}
		if ($scopes === []) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
			echo Ui::callout('info', __('This brain has no scopes yet.', 'agent-brain'), esc_html__('Create one first: imported pages go into an existing scope.', 'agent-brain'));
			echo '</section>';

			return;
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::IMPORT_ACTION);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::IMPORT_ACTION) . '" />';
		echo '<div class="ab-ui-drop"><label for="ab-import-archive">' . esc_html__('Drop a .pcp.zip, .tar or .tar.gz archive here, or choose one', 'agent-brain') . '</label>';
		echo '<input type="file" id="ab-import-archive" name="archive" required accept=".zip,.tar,.gz,.tgz" />';
		echo '<small>' . esc_html__('Everything arrives as proposals for Review. Nothing goes live on its own.', 'agent-brain') . '</small></div>';
		echo '<details class="ab-ui-set-more"><summary>' . esc_html__('Options', 'agent-brain') . '</summary><div>';

		echo '<p><label for="ab-import-partition">' . esc_html__('Scope for pages whose partition is not mapped below', 'agent-brain') . '</label><br /><select id="ab-import-partition" name="partition">';
		foreach ($scopes as $name => $label) {
			echo '<option value="' . esc_attr($name) . '"' . selected($name, 'default', false) . '>' . esc_html($label . ' (' . $name . ')') . '</option>';
		}
		echo '</select></p>';

		echo '<p><label for="ab-import-pmap">' . esc_html__('Partition map: one "archive partition = scope here" per line (optional)', 'agent-brain') . '</label><br />';
		echo '<textarea id="ab-import-pmap" name="partition_map" rows="3" class="large-text code" placeholder="team-notes = research-desk-private"></textarea></p>';
		echo '<p><label for="ab-import-umap">' . esc_html__('Principal map: one "archive principal = principal here" per line (optional; others become you)', 'agent-brain') . '</label><br />';
		echo '<textarea id="ab-import-umap" name="principal_map" rows="3" class="large-text code" placeholder="human:dana = human:dennis"></textarea></p>';

		echo '<p><label for="ab-import-conflict">' . esc_html__('When a path is already taken', 'agent-brain') . '</label><br /><select id="ab-import-conflict" name="on_conflict">'
			. '<option value="rename">' . esc_html__('Keep both: import as <path>-imported-<n>', 'agent-brain') . '</option>'
			. '<option value="skip">' . esc_html__('Skip the imported page', 'agent-brain') . '</option>'
			. '<option value="replace">' . esc_html__('Replace the page here', 'agent-brain') . '</option>'
			. '</select></p>';

		if (Exports::canExport(get_current_user_id())) {
			echo '<p><label><input type="checkbox" name="approve_all" value="1" /> ' . esc_html__('Approve at once the pages the source had approved (as the controller); the rest wait in the Review Queue', 'agent-brain') . '</label></p>';
			echo '<p><label><input type="checkbox" name="as_reference" value="1" /> ' . esc_html__('Import as reference (for a Notion or other tool\'s export): approved once as the controller, and pages land as dated reference that search finds after current knowledge, with no review per page', 'agent-brain') . '</label></p>';
		}
		echo '</div></details>';
		echo '<p><label><input type="checkbox" name="confirm" value="1" /> ' . esc_html__('I have read the dry run\'s warnings (another controller, or wider access) and want to import', 'agent-brain') . '</label></p>';
		echo '<p><label><input type="checkbox" name="dry_run" value="1" checked /> ' . esc_html__('Dry run: check the archive and show what would be imported, without writing anything', 'agent-brain') . '</label></p>';
		submit_button(__('Check or import', 'agent-brain'), 'primary', 'submit', false, ['id' => 'ab-import-submit']);
		echo '</form></section>';
	}

	public static function handleImport(): void {
		self::guard();
		check_admin_referer(self::IMPORT_ACTION);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- an uploaded file's temporary path, checked with is_uploaded_file().
		$file  = isset($_FILES['archive']['tmp_name']) ? (string) $_FILES['archive']['tmp_name'] : '';
		$error = isset($_FILES['archive']['error']) ? (int) $_FILES['archive']['error'] : UPLOAD_ERR_NO_FILE;
		if ($error !== UPLOAD_ERR_OK || $file === '' || ! is_uploaded_file($file)) {
			Notices::flash('error', __('The archive did not upload. Check its size against the upload limit, or import with WP-CLI (wp tomatio import).', 'agent-brain'));
			self::redirect();
		}

		$options = [
			'dry_run'       => ! empty($_POST['dry_run']),
			'confirm'       => ! empty($_POST['confirm']),
			'approve_all'   => ! empty($_POST['approve_all']),
			'as'            => ! empty($_POST['as_reference']) ? 'reference' : 'proposals',
			'on_conflict'   => isset($_POST['on_conflict']) ? sanitize_key(wp_unslash($_POST['on_conflict'])) : 'rename',
			'partition'     => isset($_POST['partition']) ? sanitize_text_field(wp_unslash($_POST['partition'])) : 'default',
			'partition_map' => self::pairs(isset($_POST['partition_map']) ? sanitize_textarea_field(wp_unslash($_POST['partition_map'])) : ''),
			'principal_map' => self::pairs(isset($_POST['principal_map']) ? sanitize_textarea_field(wp_unslash($_POST['principal_map'])) : ''),
			'client'        => 'wp-admin',
		];

		if (function_exists('set_time_limit')) {
			set_time_limit(0);
		}

		try {
			$result         = ImportRest::run('import', $file, get_current_user_id(), $options);
			$result['mode'] = 'import';
			set_transient(self::RESULT . get_current_user_id(), $result, 600);
		} catch (ImportFailed $e) {
			Notices::flash('error', $e->getMessage());
		}

		wp_safe_redirect(add_query_arg(['page' => self::SLUG], admin_url('admin.php')) . '#import');
		exit;
	}

	/**
	 * "a = b" lines to a map. Pure.
	 *
	 * @return array<string, string>
	 */
	public static function pairs(string $text): array {
		$out = [];
		foreach (preg_split('/\R/', $text) ?: [] as $line) {
			$parts = array_map('trim', explode('=', $line, 2));
			if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
				$out[$parts[0]] = $parts[1];
			}
		}

		return $out;
	}

	/** The last migration or import report of this user, once, in its own section. */
	private static function renderResult(string $mode): void {
		$key    = self::RESULT . get_current_user_id();
		$result = get_transient($key);
		if (! is_array($result) || ! is_array($result['report'] ?? null) || (string) ($result['flow'] ?? $result['report']['mode'] ?? '') !== $mode) {
			return;
		}
		delete_transient($key);

		$report = $result['report'];
		$counts = [];
		foreach ((array) $report['counts'] as $outcome => $count) {
			$counts[] = $outcome . ': ' . number_format_i18n((int) $count);
		}

		$title = match (true) {
			$report['status'] === 'rejected' => __('Rejected. Nothing was written.', 'agent-brain'),
			! empty($report['dry_run'])      => __('Dry run passed. Nothing was written yet; untick "Dry run" to go ahead.', 'agent-brain'),
			default                          => __('Done.', 'agent-brain'),
		};

		echo '<div class="notice notice-' . esc_attr($report['status'] === 'rejected' ? 'error' : 'success') . ' ab-notice"><p><strong>' . esc_html($title) . '</strong> ' . esc_html(implode(', ', $counts)) . '</p>';
		foreach (array_merge((array) ($result['errors'] ?? []), (array) ($result['notes'] ?? [])) as $line) {
			echo '<p>' . esc_html((string) $line) . '</p>';
		}
		echo '</div>';
	}

	public static function handleMigrate(): void {
		self::guard();
		check_admin_referer(self::MIGRATE_ACTION);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- an uploaded file's temporary path, checked with is_uploaded_file().
		$file   = isset($_FILES['archive']['tmp_name']) ? (string) $_FILES['archive']['tmp_name'] : '';
		$error  = isset($_FILES['archive']['error']) ? (int) $_FILES['archive']['error'] : UPLOAD_ERR_NO_FILE;
		$bindAs = isset($_POST['bind_as']) ? sanitize_text_field(wp_unslash($_POST['bind_as'])) : '';
		$dryRun = ! empty($_POST['dry_run']);

		if ($error !== UPLOAD_ERR_OK || $file === '' || ! is_uploaded_file($file)) {
			Notices::flash('error', __('The archive did not upload. Check its size against the upload limit, or migrate with WP-CLI (wp tomatio migrate).', 'agent-brain'));
			self::redirect();
		}

		if (function_exists('set_time_limit')) {
			set_time_limit(0);
		}

		try {
			$result = ImportRest::run(ImportRest::MODE_MIGRATE, $file, get_current_user_id(), [
				'dry_run'      => $dryRun,
				'bind_as'      => $bindAs,
				'keep_scopes'  => isset($_POST['keep_scopes']) ? sanitize_text_field(wp_unslash($_POST['keep_scopes'])) : '',
				'leave_scopes' => isset($_POST['leave_scopes']) ? sanitize_text_field(wp_unslash($_POST['leave_scopes'])) : '',
				'leave_people' => isset($_POST['leave_people']) ? sanitize_text_field(wp_unslash($_POST['leave_people'])) : '',
				'adopt_accounts' => ! empty($_POST['adopt_accounts']),
			]);
			set_transient(self::RESULT . get_current_user_id(), $result, 600);
		} catch (ImportFailed $e) {
			Notices::flash('error', $e->getMessage());
		}

		wp_safe_redirect(add_query_arg(['page' => self::SLUG], admin_url('admin.php')) . '#migrate');
		exit;
	}

	public static function handleCreate(): void {
		self::guard();
		check_admin_referer(self::CREATE_ACTION);

		$exports = new Exports();

		try {
			if ($exports->store()->streamOnly()) {
				$file = $exports->createFile(get_current_user_id());
				ExportStore::sendFile($file['path'], $file['name'], $file['sha256']);
				wp_delete_file($file['path']);
				exit;
			}

			$exports->create(get_current_user_id());
			Notices::flash('success', __('The export is ready. Download it now.', 'agent-brain'));
		} catch (ExportFailed $e) {
			Notices::flash('error', $e->getMessage());
		}

		self::redirect();
	}

	public static function handleDownload(): void {
		self::guard();

		$id = isset($_GET['export']) ? sanitize_key(wp_unslash($_GET['export'])) : '';
		check_admin_referer(self::EXPORT_DOWNLOAD . '_' . $id);

		$store  = new ExportStore();
		$record = $id !== '' ? $store->get($id) : null;
		if ($record === null) {
			wp_die(esc_html__('This archive has expired or was deleted. Make a new export.', 'agent-brain'), 404);
		}

		$store->send($record);
		exit;
	}

	public static function handleDelete(): void {
		self::guard();

		$id = isset($_POST['export']) ? sanitize_key(wp_unslash($_POST['export'])) : '';
		check_admin_referer(self::DELETE_ACTION . '_' . $id);

		if ($id !== '' && ( new ExportStore() )->delete($id)) {
			Notices::flash('success', __('The archive was deleted from the server.', 'agent-brain'));
		}

		self::redirect();
	}

	public static function handleSettings(): void {
		self::guard();
		check_admin_referer(self::SETTINGS_ACTION);

		$hours = isset($_POST['ab_export_retention_hours']) ? absint(wp_unslash($_POST['ab_export_retention_hours'])) : ExportStore::DEFAULT_RETENTION_HOURS;
		update_option(ExportStore::OPTION_RETENTION, max(1, min(ExportStore::MAX_RETENTION_HOURS, $hours)), false);
		Notices::flash('success', __('Saved. The new period applies to archives made from now on.', 'agent-brain'));

		self::redirect();
	}

	private static function guard(): void {
		if (! Exports::canExport(get_current_user_id())) {
			wp_die(esc_html__('You do not have permission to export this brain.', 'agent-brain'), 403);
		}
	}

	private static function redirect(): void {
		wp_safe_redirect(add_query_arg(['page' => self::SLUG], admin_url('admin.php')));
		exit;
	}
}
