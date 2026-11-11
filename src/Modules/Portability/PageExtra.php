<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Frontmatter a page brings that TomaTío's columns cannot give back
 * (PCP v0.3 SPEC section 8.8: a server that stores fields keeps every key
 * it has no column for). Restore and import compute the frontmatter the
 * export would write from what they store (PageWriter::rowFrontmatter(),
 * postFrontmatter()) and keep only the keys whose values differ: tags,
 * relations, valid_from, x_import, a row's title, an imported page's source,
 * and so on. Kept in ab_memory.pcp_extra for rows and post meta
 * ab_pcp_extra for posts, as JSON. Pure.
 *
 * Each kept key remembers a hash of the value the columns gave when it was
 * stored. The export applies the kept value only while the columns still
 * give that value: once a person edits the page or reviews it, the live
 * value wins, so nothing kept can hide a later change.
 */
final class PageExtra {

	public const META = 'ab_pcp_extra';

	private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

	/**
	 * The keys of $page that $native does not give exactly. A key $page
	 * lacks but $native has is kept as null (left out on export).
	 *
	 * @param array<string, mixed> $page
	 * @param array<string, mixed> $native
	 * @return array{keys:array<string, mixed>, native:array<string, string>}
	 */
	public static function diff(array $page, array $native): array {
		$out  = ['keys' => [], 'native' => []];
		$keys = array_unique(array_merge(array_keys($page), array_keys(array_filter($native, static fn ($v): bool => $v !== null))));
		sort($keys, SORT_STRING);

		foreach ($keys as $key) {
			$mine   = $page[$key] ?? null;
			$theirs = $native[$key] ?? null;
			if ($mine === $theirs) {
				continue;
			}
			$out['keys'][$key]   = $mine;
			$out['native'][$key] = self::hash($theirs);
		}

		return $out;
	}

	/**
	 * $native with the kept keys whose column value has not changed since
	 * they were stored.
	 *
	 * @param array<string, mixed>                                         $native
	 * @param array{keys:array<string, mixed>, native:array<string, string>} $extra
	 * @return array<string, mixed>
	 */
	public static function apply(array $native, array $extra): array {
		foreach ($extra['keys'] as $key => $value) {
			if (($extra['native'][$key] ?? null) !== self::hash($native[$key] ?? null)) {
				continue;
			}
			if ($value === null) {
				unset($native[$key]);
			} else {
				$native[$key] = $value;
			}
		}

		return $native;
	}

	/**
	 * True when a kept PCP expires has passed (SPEC 8.2 item 8, 8.7.3 item 9).
	 *
	 * @param array{keys:array<string, mixed>, native:array<string, string>} $extra
	 */
	public static function expired(array $extra, ?int $now = null): bool {
		$expires = $extra['keys']['expires'] ?? null;
		if (! is_string($expires)) {
			return false;
		}

		$time = strtotime($expires);

		return $time !== false && $time <= ($now ?? time());
	}

	/**
	 * @param array{keys:array<string, mixed>, native:array<string, string>} $extra
	 */
	public static function encode(array $extra): ?string {
		if ($extra['keys'] === []) {
			return null;
		}

		$json = json_encode($extra, self::FLAGS);

		return $json === false ? null : $json;
	}

	/**
	 * @param mixed $raw The stored JSON (or nothing).
	 * @return array{keys:array<string, mixed>, native:array<string, string>}
	 */
	public static function decode($raw): array {
		$data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
		if (! is_array($data) || ! is_array($data['keys'] ?? null) || ! is_array($data['native'] ?? null)) {
			return ['keys' => [], 'native' => []];
		}

		return [
			'keys'   => $data['keys'],
			'native' => array_map('strval', $data['native']),
		];
	}

	/**
	 * @param mixed $value
	 */
	private static function hash($value): string {
		return hash('sha256', (string) json_encode($value, self::FLAGS));
	}
}
