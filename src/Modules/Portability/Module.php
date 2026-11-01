<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\ModuleInterface;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * PCP portability. Stable identities for everything an export names
 * (principal ids with a registry that never reissues one, the controller,
 * partition names, page ids and paths, one scope per knowledge or procedure
 * post), full PCP v0.3 exports (the archive builder, its storage outside
 * uploads/), migrating a whole brain into an empty one (PCP's restore mode)
 * with its unbound principals, the Export and import page and its entry
 * under WordPress's Tools > Import, the management REST routes and WP-CLI. Nothing
 * here changes what agents or clients see, apart from refusing principals
 * a restore brought back until they are bound.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'portability';
	}

	public function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		// Principals: every way an account gets a role or a scope grant. After
		// the AgentId module (priority 20), so a new agent's FQDN exists first.
		add_action('set_user_role', [Principals::class, 'onRoleChanged'], 30, 1);
		add_action('add_user_role', [Principals::class, 'onRoleChanged'], 30, 1);
		add_action('user_register', [Principals::class, 'onUserChanged'], 30, 1);
		add_action('profile_update', [Principals::class, 'onProfileUpdate'], 20, 1);
		add_action('added_user_meta', [Principals::class, 'onUserMeta'], 10, 4);
		add_action('updated_user_meta', [Principals::class, 'onUserMeta'], 10, 4);
		add_action('delete_user', [Principals::class, 'beforeDelete'], 10, 1);
		add_action('deleted_user', [Principals::class, 'onDeleted'], 10, 1);
		add_action('remove_user_from_blog', [Principals::class, 'onRemovedFromBlog'], 10, 2);
		add_filter('pre_user_login', [Principals::class, 'rememberLogin'], PHP_INT_MAX, 1);
		add_filter('illegal_user_logins', [Principals::class, 'reservedLogins'], 10, 1);
		foreach (['add_user_metadata', 'update_user_metadata', 'delete_user_metadata'] as $filter) {
			add_filter($filter, [Principals::class, 'guardMeta'], 10, 3);
		}

		add_action('admin_notices', [Principals::class, 'renderDuplicateNotice']);

		// Partitions.
		add_action('created_' . Scopes::TAXONOMY, [Partitions::class, 'onCreated'], 10, 1);
		foreach (['add_term_metadata', 'update_term_metadata', 'delete_term_metadata'] as $filter) {
			add_filter($filter, [Partitions::class, 'guardMeta'], 10, 3);
		}

		// Pages (memory rows are indexed by MemoryRepository itself).
		add_action('wp_insert_post', [PageIndex::class, 'onInsertPost'], 10, 2);
		add_action('deleted_post', [PageIndex::class, 'onDeletedPost'], 10, 2);

		add_action('added_term_relationship', [ScopeGuard::class, 'onAddedRelationship'], 10, 3);
		add_action('set_object_terms', [ScopeGuard::class, 'onSetObjectTerms'], 10, 6);

		// Restored principals stay unbound until bound (Binding).
		add_filter('determine_current_user', [Binding::class, 'gateCurrentUser'], 1000, 1);
		add_filter('authenticate', [Binding::class, 'gateLogin'], 1000, 1);
		add_filter('wp_is_application_passwords_available_for_user', [Binding::class, 'gateApplicationPasswords'], 10, 2);
		add_filter('agent_brain/oauth_delegable_agents', [Binding::class, 'gateDelegation'], 10, 1);

		// Extension files a restore kept are content: forget reaches them.
		add_filter('agent_brain/forget_collect', [Kept::class, 'collect'], 10, 2);
		add_action('agent_brain/forget_delete', [Kept::class, 'delete'], 10, 2);

		// Exports, migrations and imports.
		ExportPage::register();
		add_action('admin_init', [WpImporter::class, 'register']);
		add_action('rest_api_init', [ExportRest::class, 'registerRoutes']);
		add_action('rest_api_init', [ImportRest::class, 'registerRoutes']);
		add_filter('rest_pre_serve_request', [ExportRest::class, 'serve'], 10, 2);
		add_action(ExportStore::SWEEP_HOOK, [self::class, 'sweepExports']);

		if (defined('WP_CLI') && constant('WP_CLI') && class_exists('\WP_CLI')) {
			\WP_CLI::add_command('agent-brain', Cli::class);
			// #184 rename alias: `wp tomatio` is the same command class and
			// the same internal capability checks, registered under the
			// folded name for the alias window (>= 1 minor release).
			\WP_CLI::add_command('tomatio', Cli::class);
		}
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			1 => static function (): void {
				self::dbDelta(Principals::createTableSql());
			},
			2 => static function (): void {
				self::dbDelta(PageIndex::createTableSql());
			},
			// Backfill. Idempotent: each part only fills what is missing.
			3 => static function (): void {
				self::backfill();
			},
			// One principal per FQDN: the plain index becomes UNIQUE (or stays, with a notice, when duplicates exist).
			4 => static function (): void {
				Principals::upgradeFqdnIndex();
			},
			// PCP exports replace the Markdown exporter: remove what it left
			// under uploads/, stop its nightly job, sweep held archives hourly.
			5 => static function (): void {
				ExportStore::deleteLegacy();
				wp_clear_scheduled_hook('agent_brain_export_nightly');
				delete_option('ab_export_nightly');
				delete_option('ab_export_keep');
				self::scheduleSweep();
			},
			// Restore: what an archive holds that has no column of its own.
			6 => static function (): void {
				self::dbDelta(Kept::createTableSql());
			},
			// Import: idempotency keys, and the frontmatter keys a memory row
			// has no column for (posts keep theirs in post meta ab_pcp_extra).
			7 => static function (): void {
				self::dbDelta(ImportKeys::createTableSql());
				self::addExtraColumn();
			},
		];
	}

	/**
	 * Gives every existing thing its identity. Safe to run again: nothing
	 * already assigned changes.
	 *
	 * @return array{principals:int, partitions:int, memory:int, posts:int}
	 */
	public static function backfill(): array {
		Controller::ensure();
		$principals = Principals::backfill();
		$partitions = Partitions::backfill();
		$pages      = PageIndex::backfill();

		return [
			'principals' => $principals,
			'partitions' => $partitions,
			'memory'     => $pages['memory'],
			'posts'      => $pages['posts'],
		];
	}

	public function activate(): void {
		// The controller is stored by the backfill migration, which activation runs.
		self::scheduleSweep();
	}

	public function deactivate(): void {
		// Identities are registry data; keep them. Held archives stay until they expire.
		wp_clear_scheduled_hook(ExportStore::SWEEP_HOOK);
	}

	/** Cron: deletes expired held archives. */
	public static function sweepExports(): void {
		( new ExportStore() )->sweep();
	}

	private static function scheduleSweep(): void {
		if (! wp_next_scheduled(ExportStore::SWEEP_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', ExportStore::SWEEP_HOOK);
		}
	}

	/** ab_memory.pcp_extra (PageExtra), once. */
	private static function addExtraColumn(): void {
		global $wpdb;

		$table = MemoryRepository::memoryTable();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; the value goes through prepare().
		$exists = $wpdb->get_var($wpdb->prepare(
			'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			DB_NAME,
			$table,
			'pcp_extra'
		));
		if ($exists === null) {
			$wpdb->query("ALTER TABLE {$table} ADD COLUMN pcp_extra LONGTEXT NULL");
		}
		// phpcs:enable
	}

	private static function dbDelta(string $sql): void {
		if (! function_exists('dbDelta')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta($sql);
	}
}
