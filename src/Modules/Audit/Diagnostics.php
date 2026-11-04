<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Audit;

/**
 * AUD-2: detects callbacks hooked on `wp_pre_execute_ability` that don't
 * belong to this plugin. That filter can short-circuit WP_Ability::execute()
 * entirely (see AbilityListener docblock) and bypass every permission check,
 * so a foreign callback there is a real security-relevant fact for curators.
 */
final class Diagnostics {

	/**
	 * @return array<int, array{plugin:string, callback:string, file:?string}>
	 */
	public static function foreignPreExecuteHooks(): array {
		global $wp_filter;

		$found = [];

		if (! isset($wp_filter['wp_pre_execute_ability'])) {
			return $found;
		}

		$hookObject = $wp_filter['wp_pre_execute_ability'];
		$callbacks  = is_object($hookObject) && property_exists($hookObject, 'callbacks')
			? $hookObject->callbacks
			: (is_array($hookObject) ? $hookObject : []);

		foreach ($callbacks as $priorityGroup) {
			if (! is_array($priorityGroup)) {
				continue;
			}

			foreach ($priorityGroup as $registered) {
				$callback = $registered['function'] ?? null;
				if ($callback === null) {
					continue;
				}

				if (self::isOwnCallback($callback)) {
					continue;
				}

				$file = self::resolveCallbackFile($callback);

				$found[] = [
					'plugin'   => self::pluginNameForFile($file),
					'callback' => self::describeCallback($callback),
					'file'     => $file,
				];
			}
		}

		return $found;
	}

	/**
	 * @param mixed $callback
	 */
	private static function isOwnCallback($callback): bool {
		$file = self::resolveCallbackFile($callback);
		if ($file === null) {
			return false;
		}

		$ourRoot = self::pluginRoot();

		return $ourRoot !== null && str_starts_with(self::normalizePath($file), self::normalizePath($ourRoot));
	}

	private static function pluginRoot(): ?string {
		// "Own" means "part of TomaTío's own shipped PHP source" —
		// src/Modules/Audit -> src/Modules -> src — deliberately narrower
		// than the whole plugin/agent-brain checkout so that test doubles
		// registered from tests/ are correctly treated as foreign, the same
		// way a genuinely foreign plugin's callback would be.
		$root = realpath(__DIR__ . '/../../');

		return $root !== false ? $root : null;
	}

	private static function normalizePath(string $path): string {
		return rtrim(str_replace('\\', '/', $path), '/') . '/';
	}

	/**
	 * @param mixed $callback
	 */
	private static function resolveCallbackFile($callback): ?string {
		try {
			if (is_string($callback) && strpos($callback, '::') !== false) {
				[$class, $method] = explode('::', $callback, 2);
				$ref = new \ReflectionMethod($class, $method);
			} elseif (is_array($callback) && count($callback) === 2) {
				$ref = new \ReflectionMethod($callback[0], $callback[1]);
			} elseif ($callback instanceof \Closure) {
				$ref = new \ReflectionFunction($callback);
			} elseif (is_string($callback) && function_exists($callback)) {
				$ref = new \ReflectionFunction($callback);
			} elseif (is_object($callback) && method_exists($callback, '__invoke')) {
				$ref = new \ReflectionMethod($callback, '__invoke');
			} else {
				return null;
			}

			$file = $ref->getFileName();

			return $file !== false ? $file : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * @param mixed $callback
	 */
	private static function describeCallback($callback): string {
		if (is_string($callback)) {
			return $callback;
		}

		if (is_array($callback)) {
			$target = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];

			return $target . '::' . (string) $callback[1];
		}

		if ($callback instanceof \Closure) {
			return 'Closure';
		}

		if (is_object($callback)) {
			return get_class($callback) . '::__invoke';
		}

		return 'unknown callback';
	}

	private static function pluginNameForFile(?string $file): string {
		if ($file === null) {
			return 'Unknown plugin (callback location could not be resolved)';
		}

		$normalizedFile = self::normalizePath($file);
		$pluginsDir     = defined('WP_PLUGIN_DIR') ? self::normalizePath(WP_PLUGIN_DIR) : null;

		if ($pluginsDir === null || ! str_starts_with($normalizedFile, $pluginsDir)) {
			return 'Unknown plugin (' . $file . ')';
		}

		$relative = substr($normalizedFile, strlen($pluginsDir));
		$slug     = explode('/', $relative)[0] ?? $relative;

		if (function_exists('get_plugins')) {
			if (! function_exists('get_plugin_data')) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			foreach (get_plugins() as $pluginFile => $data) {
				if (str_starts_with($pluginFile, $slug . '/') || $pluginFile === $slug . '.php') {
					return is_string($data['Name'] ?? null) && $data['Name'] !== '' ? $data['Name'] : $slug;
				}
			}
		}

		return $slug;
	}
}
