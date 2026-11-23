<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Cloud;

use AgentBrain\Core\ModuleInterface;

/**
 * Self-registering "cloud" module: opt-in signed connector to the Agent
 * Brain cloud service. Nothing here ever contacts the network unless
 * ab_cloud_enabled is true AND Connector::connect() has already succeeded
 * (gated behind the ab_manage capability + nonce in Settings).
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'cloud';
	}

	public function register(): void {
		add_action('rest_api_init', [Rest::class, 'registerRoutes']);
		add_action('admin_menu', [Settings::class, 'registerPage']);
		add_action('admin_post_' . Settings::ACTION_CONNECT, [Settings::class, 'handleConnect']);
		add_action('admin_post_' . Settings::ACTION_DISCONNECT, [Settings::class, 'handleDisconnect']);
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			1 => function (): void {
				global $wpdb;

				$table          = NonceStore::tableName();
				$charsetCollate = $wpdb->get_charset_collate();

				$sql = "CREATE TABLE {$table} (
					nonce_key VARCHAR(191) NOT NULL,
					expires_at DATETIME NOT NULL,
					PRIMARY KEY  (nonce_key),
					KEY expires_at (expires_at)
				) {$charsetCollate};";

				if (! function_exists('dbDelta')) {
					require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				}

				dbDelta($sql);
			},
		];
	}

	public function activate(): void {
		// Nothing beyond migrations; the connector stays disabled until a
		// curator explicitly connects from the settings page.
	}

	public function deactivate(): void {
		// Intentionally does not disconnect or wipe keys on deactivation —
		// only an explicit Settings::handleDisconnect() call does that.
	}
}
