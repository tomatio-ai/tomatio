<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Server-stamped instants to RFC 3339 UTC (PCP v0.3 SPEC section 8.7.3
 * item 8). Pure.
 *
 *  - "YYYY-MM-DD HH:MM:SS" or "YYYY-MM-DDTHH:MM:SS" without an offset is UTC;
 *  - a value with an offset is converted to UTC;
 *  - a date alone is midnight UTC;
 *  - a number is Unix seconds, or milliseconds when it is that large.
 * Fractions of a second are kept (up to nine digits, trailing zeros
 * dropped). Anything else is null: the caller reports the page invalid.
 */
final class TimeConvert {

	/** Above this a Unix time is in milliseconds (year 33658 in seconds). */
	private const MILLIS_FROM = 1000000000000;

	/**
	 * @param mixed $value
	 */
	public static function utc($value): ?string {
		if (is_int($value) || is_float($value)) {
			return self::fromUnix((float) $value);
		}
		if (! is_string($value)) {
			return null;
		}

		$value = trim($value);
		if (preg_match('/^-?\d{9,}(\.\d+)?$/', $value) === 1) {
			return self::fromUnix((float) $value);
		}

		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
			return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value . 'T00:00:00Z' : null;
		}

		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(\.\d{1,9})?(Z|z|[+-]\d{2}:?\d{2})?$/', $value, $m) !== 1) {
			return null;
		}
		if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 60) {
			return null;
		}

		$fraction = isset($m[7]) ? rtrim($m[7], '0') : '';
		$fraction = $fraction === '.' ? '' : $fraction;
		$offset   = $m[8] ?? '';

		// A leap second is kept as the last second before it.
		$time = sprintf('%s-%s-%sT%s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] === '60' ? '59' : $m[6]);
		if ($offset === '' || $offset === 'Z' || $offset === 'z') {
			return $time . $fraction . 'Z';
		}

		$sign    = $offset[0] === '-' ? 1 : -1;
		$digits  = str_replace(':', '', substr($offset, 1));
		$seconds = $sign * ((int) substr($digits, 0, 2) * 3600 + (int) substr($digits, 2, 2) * 60);
		$base    = strtotime($time . 'Z');
		if ($base === false) {
			return null;
		}

		return gmdate('Y-m-d\TH:i:s', $base + $seconds) . $fraction . 'Z';
	}

	private static function fromUnix(float $value): ?string {
		if (! is_finite($value)) {
			return null;
		}
		if (abs($value) >= self::MILLIS_FROM) {
			$value /= 1000;
		}

		$seconds  = (int) floor($value);
		$fraction = rtrim(substr(sprintf('%.3f', $value - $seconds), 1), '0');

		return gmdate('Y-m-d\TH:i:s', $seconds) . ($fraction === '.' ? '' : $fraction) . 'Z';
	}
}
