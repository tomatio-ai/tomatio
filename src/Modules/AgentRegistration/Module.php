<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentRegistration;

use AgentBrain\Core\ModuleInterface;

/**
 * Registration by key (docs/rfc/agent-id-fqdn.md section 6) and the per-agent
 * lifecycle actions on the Agents page.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'agent-registration';
	}

	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		KeyAuth::registerHooks();
		add_action( 'wp_abilities_api_init', [ RegistrationAbilities::class, 'register' ] );
		AdminUi::registerHooks();
		AgentActions::registerHooks();
		AgentNames::registerHooks();
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			1 => [ self::class, 'createTable' ],
			// #807: keys for several bots (a use limit and a use count).
			2 => [ self::class, 'createTable' ],
		];
	}

	/**
	 * The registrations table as it is now. dbDelta() makes it or adds the
	 * columns it lacks, so every migration step can run the same definition.
	 */
	public static function createTable(): void {
		global $wpdb;

		$table          = RegistrationStore::table();
		$charsetCollate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			key_hash VARCHAR(64) NOT NULL,
			register_id VARCHAR(8) NOT NULL,
			status VARCHAR(12) NOT NULL DEFAULT 'pending',
			agent_class VARCHAR(16) NOT NULL,
			read_scopes TEXT NOT NULL,
			write_scopes TEXT NOT NULL,
			agent_name VARCHAR(32) NULL,
			issued_by BIGINT(20) UNSIGNED NOT NULL,
			created DATETIME NOT NULL,
			expires DATETIME NOT NULL,
			spent_at DATETIME NULL,
			agent_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			max_uses INT(10) UNSIGNED NOT NULL DEFAULT 1,
			uses INT(10) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY key_hash (key_hash),
			UNIQUE KEY register_id (register_id),
			KEY status_expires (status, expires)
		) ENGINE=InnoDB {$charsetCollate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
	}

	public function activate(): void {
		// Nothing beyond migrations.
	}

	public function deactivate(): void {
		// Registration records are registry data; keep them.
	}
}
