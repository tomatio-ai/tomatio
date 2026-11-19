<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * wp-config.php constants under their TomaTío names (#837, part of #804).
 *
 * `TOMATIO_<NAME>` is read first; the older `AGENT_BRAIN_<NAME>` still
 * works, so a site that set it never has to touch wp-config.php. When both
 * are set, the TomaTío one wins. For the secret key that is a key change:
 * set the old value as TOMATIO_SECRET_KEY_PREVIOUS to keep reading what it
 * sealed.
 */
final class Config {

	public const PREFIXES = ['TOMATIO_', 'AGENT_BRAIN_'];

	/** The first non-empty string constant of TOMATIO_<name>, AGENT_BRAIN_<name>; '' when neither is set. */
	public static function string(string $name): string {
		foreach (self::PREFIXES as $prefix) {
			if (defined($prefix . $name)) {
				$value = constant($prefix . $name);
				if (is_string($value) && $value !== '') {
					return $value;
				}
			}
		}

		return '';
	}

	/** True when TOMATIO_<name> or AGENT_BRAIN_<name> is set to a true value. */
	public static function flag(string $name): bool {
		foreach (self::PREFIXES as $prefix) {
			if (defined($prefix . $name) && constant($prefix . $name)) {
				return true;
			}
		}

		return false;
	}
}
