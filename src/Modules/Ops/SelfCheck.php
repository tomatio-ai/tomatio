<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Ops;

use AgentBrain\Core\Health;
use AgentBrain\Modules\Audit\Diagnostics;
use AgentBrain\Modules\Portability\ExportStore;

/**
 * OPS-2 self-check: one machine-readable check per contract requirement,
 * each `pass`/`warn`/`fail` plus remediation text. Exposed both as the admin
 * page (SelfCheckPage) and this function, so integration tests can assert
 * on the structured result without scraping HTML.
 */
final class SelfCheck {

	public const STATUS_PASS = 'pass';
	public const STATUS_WARN = 'warn';
	public const STATUS_FAIL = 'fail';

	public const MIN_PHP     = '8.3.0';
	public const MIN_WP      = '7.1';
	public const MIN_MYSQL   = '8.0.0';
	public const MIN_MARIADB = '10.6.0';
	public const MARIADB_VECTOR_MIN = '11.8.0';

	/**
	 * @return array<int, array{id:string, label:string, status:string, message:string, remediation:string}>
	 */
	public static function run(): array {
		return [
			self::checkPhpVersion(),
			self::checkWpVersion(),
			self::checkDbEngine(),
			self::checkHttps(),
			self::checkAuthorizationHeader(),
			self::checkCronHealth(),
			self::checkForeignPreExecuteHooks(),
			self::checkModuleHealth(),
			self::checkDbBudget(),
			self::checkExportStorage(),
		];
	}

	/**
	 * @return array{id:string, label:string, status:string, message:string, remediation:string}
	 */
	private static function checkPhpVersion(): array {
		$ok = version_compare(PHP_VERSION, self::MIN_PHP, '>=');

		return [
			'id'          => 'php_version',
			'label'       => 'PHP version',
			'status'      => $ok ? self::STATUS_PASS : self::STATUS_FAIL,
			'message'     => sprintf('Running PHP %s (minimum %s).', PHP_VERSION, self::MIN_PHP),
			'remediation' => $ok ? '' : sprintf('Upgrade PHP to %s or newer.', self::MIN_PHP),
		];
	}

	/**
	 * @return array{id:string, label:string, status:string, message:string, remediation:string}
	 */
	private static function checkWpVersion(): array {
		$version = function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '0';
		$ok      = $version !== '' && version_compare($version, self::MIN_WP, '>=');

		return [
			'id'          => 'wp_version',
			'label'       => 'WordPress version',
			'status'      => $ok ? self::STATUS_PASS : self::STATUS_FAIL,
			'message'     => sprintf('Running WordPress %s (minimum %s).', $version, self::MIN_WP),
			'remediation' => $ok ? '' : sprintf('Upgrade WordPress to %s or newer.', self::MIN_WP),
		];
	}

	/**
	 * @return array{id:string, label:string, status:string, message:string, remediation:string}
	 */
	private static function checkDbEngine(): array {
		global $wpdb;

		$raw = method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : '';
		$parsed = self::parseDbVersion($raw);

		$isMariaDb = $parsed['is_mariadb'];
		$version   = $parsed['version'];

		if ($version === '') {
			return [
				'id'          => 'db_engine',
				'label'       => 'Database engine',
				'status'      => self::STATUS_WARN,
				'message'     => 'Could not determine database engine/version.',
				'remediation' => 'Confirm MySQL >= 8.0 or MariaDB >= 10.6 is in use.',
			];
		}

		$min = $isMariaDb ? self::MIN_MARIADB : self::MIN_MYSQL;
		$ok  = version_compare($version, $min, '>=');

		$engine  = $isMariaDb ? 'MariaDB' : 'MySQL';
		$message = sprintf('%s %s detected (minimum %s).', $engine, $version, $min);

		if ($isMariaDb && version_compare($version, self::MARIADB_VECTOR_MIN, '>=')) {
			$message .= ' Local vectors possible (MariaDB >= ' . self::MARIADB_VECTOR_MIN . ').';
		}

		return [
			'id'          => 'db_engine',
			'label'       => 'Database engine',
			'status'      => $ok ? self::STATUS_PASS : self::STATUS_FAIL,
			'message'     => $message,
			'remediation' => $ok ? '' : sprintf('Upgrade to %s %s or newer.', $engine, $min),
		];
	}

	/**
	 * Parses the raw db_server_info() string (e.g. "8.0.36" or
	 * "10.11.6-MariaDB-1:10.11.6+maria~ubu2204") into a clean semantic
	 * version plus whether it identifies as MariaDB.
	 *
	 * @return array{version:string, is_mariadb:bool}
	 */
	public static function parseDbVersion(string $raw): array {
		$isMariaDb = stripos($raw, 'mariadb') !== false;

		if (preg_match('/^(\d+\.\d+\.\d+)/', $raw, $matches) === 1) {
			return ['version' => $matches[1], 'is_mariadb' => $isMariaDb];
		}

		return ['version' => '', 'is_mariadb' => $isMariaDb];
	}

	/**
	 * Application-password/bearer credentials travel on every MCP call, so
	 * the site must be served over HTTPS (contract Wave 3 Ops section; PRD
	 * NFR table: "HTTPS | Required | Application passwords travel on every
	 * call"). Checks both that the request came in over TLS and that the
	 * configured site/home URLs use https, since a site can be reached over
	 * plain HTTP even when WordPress "thinks" its URL is https.
	 *
	 * @return array{id:string, label:string, status:string, message:string, remediation:string}
	 */
	private static function checkHttps(): array {
		$requestIsSsl = function_exists('is_ssl') && is_ssl();
		$siteUrl      = function_exists('site_url') ? site_url() : '';
		$homeUrl      = function_exists('home_url') ? home_url() : '';

		$siteUrlIsHttps = $siteUrl !== '' && strtolower((string) wp_parse_url($siteUrl, PHP_URL_SCHEME)) === 'https';
		$homeUrlIsHttps = $homeUrl !== '' && strtolower((string) wp_parse_url($homeUrl, PHP_URL_SCHEME)) === 'https';

		if ($requestIsSsl && $siteUrlIsHttps && $homeUrlIsHttps) {
			return [
				'id'          => 'https',
				'label'       => 'HTTPS',
				'status'      => self::STATUS_PASS,
				'message'     => 'The site is served over HTTPS.',
				'remediation' => '',
			];
		}

		return [
			'id'          => 'https',
			'label'       => 'HTTPS',
			'status'      => self::STATUS_FAIL,
			'message'     => 'This site is not fully served over HTTPS. Application passwords and bearer tokens travel on every MCP call and would be sent in the clear.',
			'remediation' => 'Serve the site over HTTPS (obtain a TLS certificate, e.g. via your host or Let\'s Encrypt) and set the WordPress Site Address and Home URLs to https://.',
		];
	}

	/**
	 * @return array{id:string, label:string, status:string, message:string, remediation:string}
	 */
	private static function checkAuthorizationHeader(): array {
		$result = AuthProbe::run();

		if (is_wp_error($result)) {
			return [
				'id'          => 'authorization_header',
				'label'       => 'Authorization header',
				'status'      => self::STATUS_WARN,
				'message'     => 'Could not run the loopback probe: ' . $result->get_error_message(),
				'remediation' => 'Confirm this site can make loopback HTTP requests to itself, then re-run the self-check.',
			];
		}

		if ($result === true) {
			return [
				'id'          => 'authorization_header',
				'label'       => 'Authorization header',
				'status'      => self::STATUS_PASS,
				'message'     => 'The Authorization header reaches PHP.',
				'remediation' => '',
			];
		}

		return [
			'id'          => 'authorization_header',
			'label'       => 'Authorization header',
			'status'      => self::STATUS_FAIL,
			'message'     => 'The Authorization header did not reach PHP. OAuth/MCP bearer-token auth will not work.',
			'remediation' => "Add this to your site's .htaccess (Apache) inside the WordPress rewrite block:\n" . AuthProbe::htaccessSnippet(),
		];
	}

	/**
	 * @return array{id:string, label:string, status:string, message:string, remediation:string}
	 */
	private static function checkCronHealth(): array {
		$disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

		$hooks = [
			\AgentBrain\Modules\Memory\Rollover::CRON_HOOK,
			\AgentBrain\Modules\Audit\Cron::HOOK,
			Cron::HOOK,
		];

		$missing = [];
		foreach ($hooks as $hook) {
			if (wp_next_scheduled($hook) === false) {
				$missing[] = $hook;
			}
		}

		$cloudConnected = class_exists(\AgentBrain\Modules\Cloud\Connector::class)
			&& \AgentBrain\Modules\Cloud\Connector::isConnected();

		if ($missing !== []) {
			return [
				'id'          => 'cron_health',
				'label'       => 'Cron health',
				'status'      => self::STATUS_FAIL,
				'message'     => 'Not scheduled: ' . implode(', ', $missing) . '.',
				'remediation' => $disabled
					? 'DISABLE_WP_CRON is set; configure a real system/host cron to hit wp-cron.php on a schedule, or connect the cloud scheduler.'
					: 'Visit any page on the site to let WP-Cron re-schedule these events, or deactivate/reactivate the plugin.',
			];
		}

		$message = $disabled
			? 'DISABLE_WP_CRON is set; relying on host cron or the cloud scheduler.'
			: 'WP-Cron is handling scheduled events.';
		$message .= $cloudConnected ? ' Cloud scheduler is connected.' : ' Cloud scheduler is not connected.';

		if ($disabled && ! $cloudConnected) {
			return [
				'id'          => 'cron_health',
				'label'       => 'Cron health',
				'status'      => self::STATUS_WARN,
				'message'     => $message,
				'remediation' => 'Confirm a host-level cron job is calling wp-cron.php, or connect the cloud (Settings > Summarising) so scheduled maintenance still runs.',
			];
		}

		return [
			'id'          => 'cron_health',
			'label'       => 'Cron health',
			'status'      => self::STATUS_PASS,
			'message'     => $message,
			'remediation' => '',
		];
	}

	/**
	 * @return array{id:string, label:string, status:string, message:string, remediation:string}
	 */
	private static function checkForeignPreExecuteHooks(): array {
		$foreign = Diagnostics::foreignPreExecuteHooks();

		if ($foreign === []) {
			return [
				'id'          => 'foreign_pre_execute_hooks',
				'label'       => 'Foreign ability hooks',
				'status'      => self::STATUS_PASS,
				'message'     => 'No foreign wp_pre_execute_ability callbacks detected.',
				'remediation' => '',
			];
		}

		$names = array_map(
			static fn (array $hook): string => $hook['plugin'] . ' (' . $hook['callback'] . ')',
			$foreign
		);

		return [
			'id'          => 'foreign_pre_execute_hooks',
			'label'       => 'Foreign ability hooks',
			'status'      => self::STATUS_WARN,
			'message'     => 'Other plugins hooked wp_pre_execute_ability: ' . implode(', ', $names) . '.',
			'remediation' => 'A foreign callback here can short-circuit ability execution and bypass permission checks. Review the listed plugin(s).',
		];
	}

	/**
	 * @return array{id:string, label:string, status:string, message:string, remediation:string}
	 */
	private static function checkModuleHealth(): array {
		$status = Health::status();

		if ($status === 'ok') {
			return [
				'id'          => 'module_health',
				'label'       => 'Module health',
				'status'      => self::STATUS_PASS,
				'message'     => 'All modules loaded successfully.',
				'remediation' => '',
			];
		}

		$reasons = array_map(
			static fn (array $reason): string => $reason['dir'] . ': ' . $reason['message'],
			Health::reasons()
		);

		return [
			'id'          => 'module_health',
			'label'       => 'Module health',
			'status'      => self::STATUS_FAIL,
			'message'     => 'Plugin is degraded: ' . implode('; ', $reasons),
			'remediation' => 'Check the listed module directory/directories for a fatal error and resolve it, then reload.',
		];
	}

	/**
	 * @return array{id:string, label:string, status:string, message:string, remediation:string}
	 */
	private static function checkDbBudget(): array {
		$percent = DbBudget::percentUsed();
		$status  = self::STATUS_PASS;

		if ($percent >= 100.0) {
			$status = self::STATUS_FAIL;
		} elseif ($percent >= DbBudget::WARN_THRESHOLD_RATIO * 100.0) {
			$status = self::STATUS_WARN;
		}

		return [
			'id'          => 'db_budget',
			'label'       => 'Database budget',
			'status'      => $status,
			'message'     => sprintf(
				'%.1f MB used of %d MB budget (%.0f%%).',
				DbBudget::usedMb(),
				DbBudget::budgetMb(),
				$percent
			),
			'remediation' => $status === self::STATUS_PASS
				? ''
				: 'Daily decay prunes low-importance working/rolled_up memory until usage is back under 50%. Raise ab_db_budget_mb if this site legitimately needs more headroom.',
		];
	}

	/**
	 * Where full exports are held (PCP export storage, never uploads/). In
	 * the wp-content fallback the canary is requested again: if the web
	 * server serves it, the brain holds nothing (stream-only mode).
	 *
	 * @return array{id:string, label:string, status:string, message:string, remediation:string}
	 */
	private static function checkExportStorage(): array {
		$store    = new ExportStore();
		$location = $store->location();
		$labels   = [
			ExportStore::KIND_HOST    => 'the folder the host configured',
			ExportStore::KIND_BESIDE  => 'a private folder beside the web root',
			ExportStore::KIND_TEMP    => 'the server\'s temporary folder',
			ExportStore::KIND_CONTENT => 'wp-content/agent-brain-private',
		];
		$where = $labels[$location['kind']] ?? $location['kind'];

		if ($location['kind'] !== ExportStore::KIND_CONTENT) {
			return [
				'id'          => 'export_storage',
				'label'       => 'Export storage',
				'status'      => self::STATUS_PASS,
				'message'     => sprintf('Export archives are kept in %s, outside the web root and uploads.', $where),
				'remediation' => '',
			];
		}

		$canary = $store->checkCanary();
		if ($canary['result'] === 'blocked') {
			return [
				'id'          => 'export_storage',
				'label'       => 'Export storage',
				'status'      => self::STATUS_PASS,
				'message'     => sprintf('Export archives are kept in %s; the web server refuses to serve it (HTTP %d).', $where, $canary['status']),
				'remediation' => '',
			];
		}

		return [
			'id'          => 'export_storage',
			'label'       => 'Export storage',
			'status'      => self::STATUS_WARN,
			'message'     => $canary['result'] === 'served'
				? 'The web server serves files from wp-content/agent-brain-private, so exports are never kept: they are built and downloaded in one step.'
				: 'Could not check whether the web server serves wp-content/agent-brain-private, so exports are never kept: they are built and downloaded in one step.',
			'remediation' => 'Define AGENT_BRAIN_EXPORT_DIR in wp-config.php as a writable folder outside the web root, or block wp-content/agent-brain-private in the web server (nginx ignores .htaccess).',
		];
	}
}
