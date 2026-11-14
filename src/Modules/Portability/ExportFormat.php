<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Pure byte rules for the files of a PCP archive (PCP v0.3 SPEC sections
 * 5.2 and 8.2). No WordPress needed, so the unit suite checks them directly.
 *
 * Determinism is the point: the same data always gives the same bytes, so
 * an export, restore, export round trip is byte-identical (SPEC 8.8).
 *
 *  - Frontmatter is written as "key: <JSON value>" lines in a fixed key
 *    order. JSON is valid YAML 1.2, so nothing is hand-written YAML, and a
 *    value with commas, colons, quotes, backslashes or newlines cannot break
 *    the file. Characters a YAML reader treats as line breaks or refuses
 *    (C1 controls, U+FFFE, U+FFFF) are escaped as \uXXXX as well.
 *  - The body is the stored bytes exactly: nothing is added or trimmed.
 *  - JSON documents use a fixed key order (the caller's), LF line ends and
 *    a final newline; JSON Lines files hold one compact object per line.
 *  - Times are RFC 3339 UTC with a Z offset.
 */
final class ExportFormat {

	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

	/**
	 * A page file: the frontmatter block, then the body as stored.
	 *
	 * @param array<string, mixed> $frontmatter Keys in the order they are written; null values are left out.
	 */
	public static function page(array $frontmatter, string $body): string {
		$out = "---\n";
		foreach ($frontmatter as $key => $value) {
			if ($value === null) {
				continue;
			}
			$out .= $key . ': ' . self::yamlValue($value) . "\n";
		}

		return $out . "---\n" . $body;
	}

	/**
	 * One frontmatter value: compact JSON, safe for a YAML 1.2 (and 1.1)
	 * reader.
	 *
	 * @param mixed $value
	 */
	public static function yamlValue($value): string {
		$json = json_encode($value, self::JSON_FLAGS);

		return preg_replace_callback(
			'/[\x{80}-\x{9F}\x{FFFE}\x{FFFF}]/u',
			static fn (array $m): string => sprintf('\\u%04x', mb_ord($m[0], 'UTF-8')),
			$json
		) ?? $json;
	}

	/**
	 * A JSON document: pretty printed (four spaces, LF) with a final
	 * newline. Empty objects are passed as \stdClass so they stay {}.
	 *
	 * @param array<string, mixed> $document
	 */
	public static function document(array $document): string {
		return json_encode($document, self::JSON_FLAGS | JSON_PRETTY_PRINT) . "\n";
	}

	/**
	 * One JSON Lines line, with its newline.
	 *
	 * @param array<string, mixed> $object
	 */
	public static function line(array $object): string {
		return json_encode($object, self::JSON_FLAGS) . "\n";
	}

	/**
	 * A MySQL DATETIME ("Y-m-d H:i:s", optionally with fractions), read as
	 * UTC, as RFC 3339 with a Z offset. Null for empty and zero dates.
	 */
	public static function utc(?string $datetime): ?string {
		$datetime = trim((string) $datetime);
		if ($datetime === '' || str_starts_with($datetime, '0000-00-00')) {
			return null;
		}

		if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(\.\d{1,9})?/', $datetime, $m) !== 1) {
			return null;
		}

		$fraction = isset($m[3]) ? rtrim($m[3], '0') : '';

		return $m[1] . 'T' . $m[2] . ($fraction === '.' ? '' : $fraction) . 'Z';
	}

	/**
	 * Importance from TomaTío's 0 to 100 to PCP's 0 to 1.
	 */
	public static function importance(int $value): float {
		return round(max(0, min(100, $value)) / 100, 2);
	}

	/**
	 * A one-line title from a memory row's content, which has none: the
	 * first non-empty line with runs of white space collapsed, cut on a
	 * character boundary. Null when the content has no visible text.
	 */
	public static function titleFromContent(string $content, int $max = 80): ?string {
		$content = self::validUtf8($content);
		foreach (preg_split('/\R/u', $content) ?: [] as $line) {
			$line = trim((string) preg_replace('/\s+/u', ' ', $line));
			$line = ltrim($line, '# ');
			if ($line === '') {
				continue;
			}

			return self::clip($line, $max);
		}

		return null;
	}

	/**
	 * At most $max characters, with an ellipsis when cut.
	 */
	public static function clip(string $text, int $max): string {
		$text = self::validUtf8($text);
		if (mb_strlen($text, 'UTF-8') <= $max) {
			return $text;
		}

		return rtrim(mb_substr($text, 0, $max - 1, 'UTF-8')) . "\u{2026}";
	}

	/**
	 * Invalid UTF-8 sequences replaced (mb_scrub), so a value that was cut
	 * on a byte (older proposal titles were) still encodes.
	 */
	public static function validUtf8(string $text): string {
		return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
	}
}
