<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * The plugin's own hooks, under their TomaTío names (#837, part of #804).
 *
 * Every filter and action the plugin fires is `tomatio/<name>`. Code still
 * hooked on the old `agent_brain/<name>` keeps working: when something is
 * attached there, the old hook fires too, through WordPress's deprecation
 * functions, so a site running with WP_DEBUG is told the new name. The old
 * names are removed no sooner than one minor release after this one.
 *
 * Callbacks on the new name run first, then those on the old name, each in
 * its own priority order.
 */
final class Hooks {

	public const PREFIX     = 'tomatio/';
	public const OLD_PREFIX = 'agent_brain/';

	/** The release the old names were deprecated in (the notice names it). */
	public const DEPRECATED_SINCE = '1.10.0';

	/**
	 * apply_filters() for `tomatio/<name>`, then the old `agent_brain/<name>`
	 * when anything is hooked on it.
	 *
	 * @param mixed $value
	 * @param mixed ...$args
	 * @return mixed
	 */
	public static function filter(string $name, $value, ...$args) {
		$value = apply_filters(self::PREFIX . $name, $value, ...$args);

		if (function_exists('has_filter') && has_filter(self::OLD_PREFIX . $name)) {
			$value = apply_filters_deprecated(self::OLD_PREFIX . $name, [$value, ...$args], self::DEPRECATED_SINCE, self::PREFIX . $name);
		}

		return $value;
	}

	/**
	 * do_action() for `tomatio/<name>`, then the old `agent_brain/<name>`
	 * when anything is hooked on it.
	 *
	 * @param mixed ...$args
	 */
	public static function action(string $name, ...$args): void {
		do_action(self::PREFIX . $name, ...$args);

		if (function_exists('has_action') && has_action(self::OLD_PREFIX . $name)) {
			do_action_deprecated(self::OLD_PREFIX . $name, $args, self::DEPRECATED_SINCE, self::PREFIX . $name);
		}
	}

	/** Whether anything is hooked on `tomatio/<name>` or the old `agent_brain/<name>`. */
	public static function has(string $name): bool {
		if (! function_exists('has_filter')) {
			return false;
		}

		return has_filter(self::PREFIX . $name) !== false || has_filter(self::OLD_PREFIX . $name) !== false;
	}
}
