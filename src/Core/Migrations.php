<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * Runs versioned per-module migrations via dbDelta, tracking applied
 * versions in a single wp_options row so re-activation and version bumps
 * are idempotent (re-running an already-applied migration is a no-op from
 * the caller's perspective; dbDelta itself is idempotent for CREATE TABLE).
 */
class Migrations {

	private const OPTION_KEY = 'agent_brain_module_versions';

	/**
	 * @param array<string, ModuleInterface> $modules id => module
	 */
	public static function runAll(array $modules): void {
		foreach ($modules as $module) {
			self::runForModule($module);
		}
	}

	/**
	 * True when any module has a migration newer than the version recorded
	 * for it. Cheap (one autoloaded option, array keys only), so boot can
	 * apply schema changes that arrive without a plugin version bump.
	 *
	 * @param array<string, ModuleInterface> $modules id => module
	 */
	public static function hasPending(array $modules): bool {
		$applied = self::getAppliedVersions();

		foreach ($modules as $module) {
			$versions = array_keys($module->migrations());
			if ($versions !== [] && max($versions) > ($applied[$module->id()] ?? 0)) {
				return true;
			}
		}

		return false;
	}

	public static function runForModule(ModuleInterface $module): void {
		$applied     = self::getAppliedVersions();
		$id          = $module->id();
		$currentVer  = $applied[$id] ?? 0;
		$migrations  = $module->migrations();

		ksort($migrations);

		foreach ($migrations as $version => $migration) {
			if ($version <= $currentVer) {
				continue;
			}

			self::apply($migration);

			$applied[$id] = $version;
			self::saveAppliedVersions($applied);
		}
	}

	/**
	 * @param callable|string $migration
	 */
	private static function apply($migration): void {
		if (is_callable($migration)) {
			$migration();

			return;
		}

		if (is_string($migration)) {
			self::dbDelta($migration);
		}
	}

	private static function dbDelta(string $sql): void {
		if (! function_exists('dbDelta')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta($sql);
	}

	/**
	 * @return array<string, int>
	 */
	private static function getAppliedVersions(): array {
		$stored = get_option(self::OPTION_KEY, []);

		return is_array($stored) ? $stored : [];
	}

	/**
	 * @param array<string, int> $versions
	 */
	private static function saveAppliedVersions(array $versions): void {
		update_option(self::OPTION_KEY, $versions);
	}
}
