<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Audit;

use AgentBrain\Core\ModuleInterface;

/**
 * Audit module (AUD-1, AUD-2): persists `agent_brain/audit` events, logs
 * every ability call in our own namespace, and warns curators about foreign
 * `wp_pre_execute_ability` hooks.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'audit';
	}

	public function register(): void {
		add_action('agent_brain/audit', [Repository::class, 'persist'], 10, 1);
		add_filter('agent_brain/audit_write', [AuditWrite::class, 'write'], 10, 2);
		add_filter('agent_brain/forget_collect', [Forget::class, 'collect'], 10, 2);
		add_action('agent_brain/forget_delete', [Forget::class, 'delete'], 10, 2);

		AbilityListener::register();
		AdminNotice::register();
		ViewerPage::register();
		Cron::register();
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			1 => function (): void {
				global $wpdb;

				$table          = Repository::tableName();
				$charsetCollate = $wpdb->get_charset_collate();

				$sql = "CREATE TABLE {$table} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					ts DATETIME(3) NOT NULL,
					actor_id BIGINT UNSIGNED NOT NULL,
					event VARCHAR(191) NOT NULL,
					status VARCHAR(16) NOT NULL,
					object_type VARCHAR(64) NULL,
					object_id VARCHAR(191) NULL,
					args_hash CHAR(64) NULL,
					meta LONGTEXT NULL,
					duration_ms INT NULL,
					PRIMARY KEY  (id),
					KEY ts (ts),
					KEY actor_event (actor_id, event),
					KEY object (object_type, object_id)
				) {$charsetCollate};";

				if (! function_exists('dbDelta')) {
					require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				}

				dbDelta($sql);
			},
		];
	}

	public function activate(): void {
		if (! get_option(Cron::OPTION_RETENTION_DAYS)) {
			add_option(Cron::OPTION_RETENTION_DAYS, Cron::DEFAULT_RETENTION_DAYS);
		}

		$administrator = get_role('administrator');
		if ($administrator !== null && ! $administrator->has_cap(ViewerPage::CAPABILITY)) {
			$administrator->add_cap(ViewerPage::CAPABILITY);
		}

		Cron::activate();
	}

	public function deactivate(): void {
		Cron::deactivate();
	}
}
