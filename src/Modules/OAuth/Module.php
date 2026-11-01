<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Core\ModuleInterface;

/**
 * OAuth 2.1 + CIMD module (PRD ID-5), extended with RFC 7591 Dynamic
 * Client Registration (#239): authorization-code + PKCE (S256 only) for
 * public MCP clients identified by a Client ID Metadata Document OR a
 * dynamic registration, plus bearer authentication for the
 * agent-brain/v1/mcp REST namespace.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'oauth';
	}

	public function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('rest_api_init', [Rest::class, 'registerRoutes']);
		// Priority 0: before core's redirect_canonical (also template_redirect,
		// priority 10), which can 301 /.well-known/... to a trailing-slash URL
		// that discovery clients do not follow.
		add_action('template_redirect', [WellKnown::class, 'maybeServe'], 0);

		(new AuthorizeController())->registerHooks();
		(new ProfileUi())->registerHooks();
		(new DefaultAgent())->registerHooks();

		Bearer::registerHooks();
		RoleSwitching::registerHooks();
		Challenge::registerHooks();
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			1 => static function (): void {
				global $wpdb;

				$charsetCollate = $wpdb->get_charset_collate();

				$codesTable = TokenStore::codesTable();
				$codesSql   = "CREATE TABLE {$codesTable} (
					code_hash VARCHAR(64) NOT NULL,
					user_id BIGINT UNSIGNED NOT NULL,
					client_id TEXT NOT NULL,
					redirect_uri TEXT NOT NULL,
					code_challenge VARCHAR(191) NOT NULL,
					scope VARCHAR(191) NOT NULL DEFAULT 'mcp',
					used TINYINT(1) NOT NULL DEFAULT 0,
					created DATETIME NOT NULL,
					expires DATETIME NOT NULL,
					PRIMARY KEY  (code_hash),
					KEY expires (expires)
				) ENGINE=InnoDB {$charsetCollate};";

				self::dbDelta($codesSql);

				$tokensTable = TokenStore::tokensTable();
				$tokensSql   = "CREATE TABLE {$tokensTable} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					token_hash VARCHAR(64) NOT NULL,
					token_type VARCHAR(16) NOT NULL,
					user_id BIGINT UNSIGNED NOT NULL,
					client_id TEXT NOT NULL,
					scope VARCHAR(191) NOT NULL DEFAULT 'mcp',
					family_id VARCHAR(64) NOT NULL,
					rotated TINYINT(1) NOT NULL DEFAULT 0,
					revoked TINYINT(1) NOT NULL DEFAULT 0,
					created DATETIME NOT NULL,
					expires DATETIME NOT NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY token_hash (token_hash),
					KEY family_id (family_id),
					KEY user_id (user_id)
				) ENGINE=InnoDB {$charsetCollate};";

				self::dbDelta($tokensSql);
			},
			// "Connect this AI as": codes and tokens remember the person who
			// authorized a delegated grant (0 when the user consented for
			// themselves), so requests can be attributed "via" that person.
			2 => static function (): void {
				foreach ([TokenStore::codesTable(), TokenStore::tokensTable()] as $table) {
					self::addColumnIfMissing($table, 'authorized_by', 'BIGINT UNSIGNED NOT NULL DEFAULT 0');
				}
			},
			// #239: Dynamic Client Registration (RFC 7591) clients, alongside
			// CIMD. rat_hash holds only the sha256 of the registration access
			// token (RFC 7592); the plaintext is returned once at registration.
			3 => static function (): void {
				global $wpdb;

				$charsetCollate = $wpdb->get_charset_collate();
				$table          = Dcr::table();
				$sql            = "CREATE TABLE {$table} (
					client_id VARCHAR(64) NOT NULL,
					client_name VARCHAR(255) NOT NULL DEFAULT '',
					client_uri TEXT NOT NULL,
					redirect_uris TEXT NOT NULL,
					rat_hash VARCHAR(64) NOT NULL,
					created DATETIME NOT NULL,
					expires DATETIME NOT NULL,
					revoked TINYINT(1) NOT NULL DEFAULT 0,
					PRIMARY KEY  (client_id),
					KEY expires (expires)
				) ENGINE=InnoDB {$charsetCollate};";

				self::dbDelta($sql);
			},
		];
	}

	private static function addColumnIfMissing(string $table, string $column, string $definition): void {
		global $wpdb;

		$exists = $wpdb->get_var($wpdb->prepare(
			'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			DB_NAME,
			$table,
			$column
		));
		if ($exists !== null) {
			return; // Idempotent.
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- identifiers and a fixed definition from this class, no user input.
		$wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
	}

	public function activate(): void {
		// Nothing beyond migrations: OAuth is available to any client that
		// presents a valid CIMD document, no per-site opt-in required.
		add_action(Dcr::PRUNE_HOOK, static function (): void {
			Dcr::pruneExpired();
		});
		if (! wp_next_scheduled(Dcr::PRUNE_HOOK)) {
			wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', Dcr::PRUNE_HOOK);
		}
	}

	public function deactivate(): void {
		$timestamp = wp_next_scheduled(Dcr::PRUNE_HOOK);
		if ($timestamp !== false) {
			wp_unschedule_event($timestamp, Dcr::PRUNE_HOOK);
		}
	}

	private static function dbDelta(string $sql): void {
		if (! function_exists('dbDelta')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta($sql);
	}
}
