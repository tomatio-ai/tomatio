<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * One date formatter for every TomaTío admin screen (issue #92): readable
 * text such as "Oct 21, 2026 07:56 UTC" with the ISO 8601 value in the
 * tooltip. Null, empty and zero dates ("0000-00-00 00:00:00") are "not set"
 * rather than an invalid date. Pure apart from escaping in html().
 */
final class DateFormat {

	/** Parses a UTC MySQL "Y-m-d H:i:s" (or ISO 8601) stamp; null when unset or invalid. */
	public static function timestamp(?string $value): ?int {
		$value = trim((string) $value);
		if ($value === '' || strncmp($value, '0000-00-00', 10) === 0) {
			return null;
		}

		$ts = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1 ? strtotime($value) : strtotime($value . ' UTC');

		return $ts === false || $ts <= 0 ? null : $ts;
	}

	/** ISO 8601 with Z, or '' when unset. */
	public static function iso(?string $value): string {
		$ts = self::timestamp($value);

		return $ts === null ? '' : gmdate('Y-m-d\\TH:i:s\\Z', $ts);
	}

	/** Readable text, e.g. "Oct 21, 2026 07:56 UTC"; $unset is returned for null/zero dates. */
	public static function text(?string $value, string $unset = ''): string {
		$ts = self::timestamp($value);

		return $ts === null ? $unset : gmdate('M j, Y H:i', $ts) . ' UTC';
	}

	/** Escaped `<time>` element: readable text, ISO 8601 in datetime and title. */
	public static function html(?string $value, string $unset = ''): string {
		$ts = self::timestamp($value);
		if ($ts === null) {
			return esc_html($unset);
		}

		$iso = gmdate('Y-m-d\\TH:i:s\\Z', $ts);

		return '<time datetime="' . esc_attr($iso) . '" title="' . esc_attr($iso) . '">' . esc_html(self::text($value)) . '</time>';
	}

	/**
	 * "3 hours ago" in a <time> that carries the exact moment (#751).
	 */
	public static function ago(?string $value, string $unset = ''): string {
		$ts = self::timestamp($value);
		if ($ts === null) {
			return esc_html($unset);
		}

		$iso = gmdate('Y-m-d\\TH:i:s\\Z', $ts);

		/* translators: %s: a time span such as "3 hours" */
		return '<time datetime="' . esc_attr($iso) . '" title="' . esc_attr(self::text($value)) . '">' . esc_html(sprintf(__('%s ago', 'agent-brain'), human_time_diff($ts, time()))) . '</time>';
	}
}
