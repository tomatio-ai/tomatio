<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * The context's controller (PCP v0.3 SPEC section 1.2): the site, as
 * "org:<site host>". Stored once, at install or by the identities migration,
 * and never computed from the site URL again, so moving the brain to a new
 * address (or restoring it on another host) keeps the same controller.
 */
final class Controller {

	public const OPTION = 'ab_pcp_controller';

	/** The stored controller id, or null before install. Never writes. */
	public static function stored(): ?string {
		$value = get_option(self::OPTION, '');

		return is_string($value) && Names::isPrincipal($value) && str_starts_with($value, 'org:') ? $value : null;
	}

	/** The controller id, storing it first if this is the first call. */
	public static function ensure(): string {
		$stored = self::stored();
		if ($stored !== null) {
			return $stored;
		}

		$host = wp_parse_url(home_url(), PHP_URL_HOST);
		$id   = self::fromHost(is_string($host) ? $host : '');

		update_option(self::OPTION, $id, false);
		Principals::reserve($id, (string) get_option('blogname', ''));

		return $id;
	}

	/** Pure: "org:" and the lowercased host ("localhost" when there is none). */
	public static function fromHost(string $host): string {
		$host = strtolower(trim($host));

		return 'org:' . Names::localId($host !== '' ? $host : 'localhost');
	}
}
