<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Health;

use AgentBrain\Core\Db;
use AgentBrain\Core\ModuleInterface;

/**
 * Sample module proving auto-discovery + migrations. Safe to delete once
 * later waves add real modules that exercise the same contract.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'health';
	}

	public function register(): void {
		// No hooks yet; this module exists to prove discovery + migrations.
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			// Fresh installs: Db::table('health') resolves to {prefix}ab_health
			// (contract naming). This CREATE TABLE is a no-op for any site
			// that already applied it — dbDelta is idempotent for CREATE TABLE.
			1 => function (): void {
				global $wpdb;

				$table           = Db::table('health');
				$charsetCollate  = $wpdb->get_charset_collate();

				$sql = "CREATE TABLE {$table} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					checked_at DATETIME NOT NULL,
					status VARCHAR(20) NOT NULL,
					PRIMARY KEY  (id)
				) {$charsetCollate};";

				if (! function_exists('dbDelta')) {
					require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				}

				dbDelta($sql);
			},
			// Existing installs that migrated under the pre-contract Db::table()
			// (which prefixed "agent_brain_" instead of "ab_") still have their
			// data under {prefix}agent_brain_health. Db::table() now returns
			// {prefix}ab_health, so version 1 above would otherwise silently
			// create a second, empty table for those sites and orphan the old
			// one. Rename the old table into the new name when the old one
			// exists and the new one doesn't; a no-op everywhere else
			// (fresh installs already created ab_health directly via v1 above;
			// installs that already ran this migration have no old table left).
			2 => function (): void {
				global $wpdb;

				$oldTable = $wpdb->prefix . 'agent_brain_health';
				$newTable = Db::table('health');

				if ( self::tableExists( $oldTable ) && ! self::tableExists( $newTable ) ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names built from $wpdb->prefix + Db::table(), not caller input; RENAME TABLE takes no placeholders.
					$wpdb->query( "RENAME TABLE {$oldTable} TO {$newTable}" );
				}
			},
		];
	}

	private static function tableExists( string $table ): bool {
		global $wpdb;

		$found = Db::getResults( 'SHOW TABLES LIKE %s', [ $table ] );

		return $found !== [];
	}

	public function activate(): void {
		// Nothing beyond migrations for this sample module.
	}

	public function deactivate(): void {
		// Data removal, if any, belongs in uninstall.php gated by an option.
	}
}
