<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Pure rules for the names a PCP export uses (PCP v0.3: principals in SPEC
 * section 1.3, paths in 5.1, partitions in 5.3). No WordPress needed, so the
 * unit suite checks every rule directly.
 *
 * Every name is derived once, stored, and never derived again: these rules
 * only produce the first candidate, and the stores (Principals, Partitions,
 * PageIndex) add the -2, -3 suffixes that keep names unique.
 */
final class Names {

	public const PRINCIPAL_PATTERN = '/^(human|agent|org|service):[A-Za-z0-9._@+\/-]{1,128}$/';
	public const PARTITION_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
	public const PATH_PATTERN      = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}(\/[A-Za-z0-9_][A-Za-z0-9._-]{0,63})*$/';
	public const PAGE_ID_PATTERN   = '/^[A-Za-z0-9._:-]{1,128}$/';

	public const LOCAL_MAX     = 128;
	public const SEGMENT_MAX   = 64;
	public const PATH_MAX      = 255;
	public const PARTITION_MAX = 64;

	/** Fallback when a scope slug has no usable character. */
	public const PARTITION_FALLBACK = 'scope';

	public static function isPrincipal(string $id): bool {
		return preg_match(self::PRINCIPAL_PATTERN, $id) === 1;
	}

	public static function isPartition(string $name): bool {
		return preg_match(self::PARTITION_PATTERN, $name) === 1;
	}

	public static function isPath(string $path): bool {
		return strlen($path) <= self::PATH_MAX && preg_match(self::PATH_PATTERN, $path) === 1;
	}

	public static function isPageId(string $id): bool {
		return preg_match(self::PAGE_ID_PATTERN, $id) === 1;
	}

	/**
	 * Local part of a principal id from a WordPress login. Logins are ASCII
	 * letters, digits, space, "_", ".", "-" and "@"; anything the principal
	 * grammar does not allow (in practice the space) becomes "-".
	 */
	public static function localId(string $login): string {
		$local = preg_replace('/[^A-Za-z0-9._@+\/-]/', '-', $login) ?? '';
		$local = substr($local, 0, self::LOCAL_MAX);

		return $local === '' ? 'user' : $local;
	}

	/**
	 * The n-th candidate for a principal id ($n >= 2 appends "-n"), cut so
	 * the local part stays within LOCAL_MAX.
	 */
	public static function principalCandidate(string $base, int $n): string {
		if ($n < 2) {
			return $base;
		}

		[$type, $local] = explode(':', $base, 2) + [1 => ''];
		$suffix = '-' . $n;

		return $type . ':' . substr($local, 0, self::LOCAL_MAX - strlen($suffix)) . $suffix;
	}

	/**
	 * First candidate for a partition name from a scope slug: percent
	 * decoded, accents removed, lowercased, every other character run to
	 * "-", trimmed and cut to PARTITION_MAX.
	 */
	public static function partitionBase(string $slug): string {
		$name = strtolower(self::ascii(rawurldecode($slug)));
		$name = preg_replace('/[^a-z0-9_-]+/', '-', $name) ?? '';
		$name = trim($name, '-_');
		$name = rtrim(substr($name, 0, self::PARTITION_MAX), '-_');

		return $name === '' ? self::PARTITION_FALLBACK : $name;
	}

	/** The n-th candidate for a partition name ($n >= 2 appends "-n"). */
	public static function partitionCandidate(string $base, int $n): string {
		if ($n < 2) {
			return $base;
		}

		$suffix = '-' . $n;

		return rtrim(substr($base, 0, self::PARTITION_MAX - strlen($suffix)), '-_') . $suffix;
	}

	/**
	 * An ASCII path segment from a post title, or null when the title has no
	 * letter or digit left after removing accents (the caller then uses the
	 * post id). Only [a-z0-9] and "-" survive, so the segment never starts
	 * with "." or "_" and is valid under SPEC section 5.1.
	 */
	public static function titleSegment(string $title): ?string {
		$text = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = strtolower(self::ascii($text));
		$text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
		$text = trim($text, '-');
		$text = rtrim(substr($text, 0, self::SEGMENT_MAX), '-');

		return $text === '' ? null : $text;
	}

	/**
	 * The n-th candidate for a path ($n >= 2 appends "-n" to the last
	 * segment, cut so the segment stays within SEGMENT_MAX).
	 */
	public static function pathCandidate(string $base, int $n): string {
		if ($n < 2) {
			return $base;
		}

		$slash  = strrpos($base, '/');
		$head   = $slash === false ? '' : substr($base, 0, $slash + 1);
		$last   = $slash === false ? $base : substr($base, $slash + 1);
		$suffix = '-' . $n;
		$last   = rtrim(substr($last, 0, self::SEGMENT_MAX - strlen($suffix)), '-');

		return $head . ($last === '' ? (string) $n : $last . $suffix);
	}

	/**
	 * Removes accents with WordPress's remove_accents() when it is loaded
	 * (always, outside the unit suite). Without it, non-ASCII bytes are left
	 * for the callers' character rules to turn into "-".
	 */
	private static function ascii(string $text): string {
		return function_exists('remove_accents') ? remove_accents($text) : $text;
	}
}
