<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Reads the YAML of page frontmatter (PCP v0.3 SPEC section 5.2) into PHP
 * values, as the JSON data model of YAML 1.2's core schema: timestamps stay
 * strings. Pure, so the unit suite checks it directly.
 *
 * TomaTío writes every value as JSON (ExportFormat), which is valid YAML,
 * and a value that decodes as JSON is taken as JSON. Other PCP servers write
 * ordinary YAML, so the rest of what frontmatter uses is read too: block
 * mappings and sequences, flow mappings and sequences, plain, single-quoted
 * and double-quoted scalars, literal and folded block scalars, and comments.
 * Anchors, aliases, tags, directives and several documents are refused
 * (they have no place in frontmatter), as is a key given twice.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- parse errors are plain text, escaped where they are shown.
final class Yaml {

	private const MAX_DEPTH = 32;

	/** @var array<int, string> */
	private array $lines = [];

	private int $pos = 0;

	/**
	 * A mapping at the top level.
	 *
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When the text is not YAML this reader accepts.
	 */
	public static function parseMapping(string $text): array {
		$value = self::parse($text);
		if ($value === null) {
			return [];
		}

		if (! is_array($value) || ($value !== [] && array_is_list($value))) {
			throw new \InvalidArgumentException('The frontmatter is not a mapping.');
		}

		return $value;
	}

	/**
	 * @return mixed
	 * @throws \InvalidArgumentException
	 */
	public static function parse(string $text) {
		if (str_starts_with($text, "\xEF\xBB\xBF")) {
			throw new \InvalidArgumentException('A byte order mark is not allowed.');
		}

		$parser        = new self();
		$parser->lines = preg_split('/\r\n|\n|\r/', $text) ?: [];

		$parser->skipBlank();
		if ($parser->pos >= count($parser->lines)) {
			return null;
		}

		$indent = self::indentOf($parser->lines[$parser->pos]);
		$value  = $parser->block($indent, 0);

		$parser->skipBlank();
		if ($parser->pos < count($parser->lines)) {
			throw self::error('Unexpected text', $parser->pos);
		}

		return $value;
	}

	// -----------------------------------------------------------------
	// Block structure
	// -----------------------------------------------------------------

	/**
	 * The node whose first line is at $this->pos, indented by $indent.
	 *
	 * @return mixed
	 */
	private function block(int $indent, int $depth) {
		if ($depth > self::MAX_DEPTH) {
			throw self::error('Nested too deeply', $this->pos);
		}

		$line    = $this->lines[$this->pos];
		$content = substr($line, $indent);

		if (self::isSequenceItem($content)) {
			return $this->sequence($indent, $depth);
		}

		if ($this->keyOf($content) !== null) {
			return $this->mapping($indent, $depth);
		}

		// A lone scalar or flow collection, possibly over several lines.
		$text = $this->continued(trim($content), $indent);

		return $this->inline($text);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function mapping(int $indent, int $depth): array {
		$out = [];

		while (true) {
			$this->skipBlank();
			if ($this->pos >= count($this->lines)) {
				break;
			}

			$line = $this->lines[$this->pos];
			$at   = self::indentOf($line);
			if ($at < $indent) {
				break;
			}
			if ($at > $indent) {
				throw self::error('Bad indentation', $this->pos);
			}

			$content = substr($line, $indent);
			if (self::isSequenceItem($content)) {
				break;
			}

			$pair = $this->keyOf($content);
			if ($pair === null) {
				throw self::error('Expected a "key: value" line', $this->pos);
			}

			[$key, $rest] = $pair;
			if (array_key_exists($key, $out)) {
				throw self::error('Duplicate key "' . $key . '"', $this->pos);
			}

			$out[$key] = $this->valueAfterKey($rest, $indent, $depth);
		}

		return $out;
	}

	/**
	 * @return array<int, mixed>
	 */
	private function sequence(int $indent, int $depth): array {
		$out = [];

		while (true) {
			$this->skipBlank();
			if ($this->pos >= count($this->lines)) {
				break;
			}

			$line = $this->lines[$this->pos];
			$at   = self::indentOf($line);
			if ($at < $indent) {
				break;
			}
			if ($at > $indent) {
				throw self::error('Bad indentation', $this->pos);
			}

			$content = substr($line, $indent);
			if (! self::isSequenceItem($content)) {
				break;
			}

			$rest = ltrim(substr($content, 1), ' ');
			$rest = self::stripComment($rest);

			if ($rest === '') {
				++$this->pos;
				$out[] = $this->nested($indent, $depth);
				continue;
			}

			// "- key: value": a mapping whose first key is on the dash line.
			$itemIndent = $indent + (strlen($content) - strlen(ltrim(substr($content, 1), ' ')));
			if ($this->keyOf($rest) !== null) {
				$this->lines[$this->pos] = str_repeat(' ', $itemIndent) . $rest;
				$out[]                   = $this->mapping($itemIndent, $depth + 1);
				continue;
			}

			if (self::isSequenceItem($rest)) {
				$this->lines[$this->pos] = str_repeat(' ', $itemIndent) . $rest;
				$out[]                   = $this->sequence($itemIndent, $depth + 1);
				continue;
			}

			$out[] = $this->scalarOrBlock($rest, $indent, $depth);
		}

		return $out;
	}

	/**
	 * The value of "key: <rest>".
	 *
	 * @return mixed
	 */
	private function valueAfterKey(string $rest, int $indent, int $depth) {
		$rest = self::stripComment($rest);

		if ($rest === '') {
			// A nested block, or a sequence at the same indentation.
			++$this->pos;
			$this->skipBlank();
			if ($this->pos < count($this->lines)) {
				$line = $this->lines[$this->pos];
				$at   = self::indentOf($line);
				if ($at === $indent && self::isSequenceItem(substr($line, $indent))) {
					return $this->sequence($indent, $depth + 1);
				}
			}

			return $this->nested($indent, $depth);
		}

		return $this->scalarOrBlock($rest, $indent, $depth);
	}

	/**
	 * A node indented deeper than $parent, or null when there is none.
	 *
	 * @return mixed
	 */
	private function nested(int $parent, int $depth) {
		$this->skipBlank();
		if ($this->pos >= count($this->lines)) {
			return null;
		}

		$at = self::indentOf($this->lines[$this->pos]);
		if ($at <= $parent) {
			return null;
		}

		return $this->block($at, $depth + 1);
	}

	/**
	 * @return mixed
	 */
	private function scalarOrBlock(string $rest, int $indent, int $depth) {
		unset($depth);

		if (preg_match('/^([|>])([+-]?)(\d?)([+-]?)$/', $rest, $m) === 1) {
			++$this->pos;

			return $this->blockScalar($m[1], $m[2] . $m[4], $m[3] !== '' ? (int) $m[3] : 0, $indent);
		}

		return $this->inline($this->continued($rest, $indent));
	}

	/**
	 * Joins the lines that continue a value: an open flow collection or
	 * quoted scalar, or a plain scalar folded over more indented lines.
	 */
	private function continued(string $text, int $indent): string {
		while ($this->pos + 1 < count($this->lines) && $this->needsMore($text)) {
			++$this->pos;
			$text .= "\n" . $this->lines[$this->pos];
		}

		// Plain multi-line scalars: more indented lines that are not keys.
		if ($text !== '' && ! in_array($text[0], ['"', "'", '{', '['], true)) {
			while ($this->pos + 1 < count($this->lines)) {
				$next = $this->lines[$this->pos + 1];
				if (trim($next) === '' || self::indentOf($next) <= $indent || str_starts_with(ltrim($next), '#')) {
					break;
				}
				++$this->pos;
				$text .= ' ' . trim($next);
			}
		}

		++$this->pos;

		return $text;
	}

	private function needsMore(string $text): bool {
		$first = $text[0] ?? '';
		if ($first === '"') {
			return preg_match('/^"(?:[^"\\\\]|\\\\.)*"/s', $text) !== 1;
		}
		if ($first === "'") {
			return preg_match("/^'(?:[^']|'')*'/s", $text) !== 1;
		}
		if ($first === '{' || $first === '[') {
			return ! self::balanced($text);
		}

		return false;
	}

	private function blockScalar(string $style, string $chomp, int $explicit, int $parent): string {
		$lines  = [];
		$indent = $explicit > 0 ? $parent + $explicit : null;

		while ($this->pos < count($this->lines)) {
			$line = $this->lines[$this->pos];
			if (trim($line) === '') {
				$lines[] = '';
				++$this->pos;
				continue;
			}

			$at = self::indentOf($line);
			if ($indent === null) {
				if ($at <= $parent) {
					break;
				}
				$indent = $at;
			}
			if ($at < $indent) {
				break;
			}

			$lines[] = substr($line, $indent);
			++$this->pos;
		}

		// Trailing empty lines belong to the chomping, not the content.
		$trailing = 0;
		while ($lines !== [] && end($lines) === '') {
			array_pop($lines);
			++$trailing;
		}

		if ($style === '|') {
			$text = implode("\n", $lines);
		} else {
			$text = '';
			foreach ($lines as $i => $line) {
				if ($i === 0) {
					$text = $line;
				} elseif ($line === '') {
					$text .= "\n";
				} elseif ($lines[$i - 1] === '') {
					$text .= $line;
				} elseif (str_starts_with($line, ' ') || str_starts_with($lines[$i - 1], ' ')) {
					$text .= "\n" . $line;
				} else {
					$text .= ' ' . $line;
				}
			}
		}

		if ($lines === []) {
			return $chomp === '+' ? str_repeat("\n", $trailing) : '';
		}

		return match ($chomp) {
			'-'     => $text,
			'+'     => $text . "\n" . str_repeat("\n", $trailing),
			default => $text . "\n",
		};
	}

	// -----------------------------------------------------------------
	// Inline values: JSON first, then flow YAML and scalars
	// -----------------------------------------------------------------

	/**
	 * @return mixed
	 */
	private function inline(string $text) {
		$text = trim($text);
		if ($text === '') {
			return null;
		}

		$first = $text[0];
		if (in_array($first, ['"', '{', '['], true) || ctype_digit($first) || $first === '-') {
			$json = json_decode($text, true, self::MAX_DEPTH + 2);
			if (json_last_error() === JSON_ERROR_NONE && ($first !== '"' || is_string($json))) {
				return $json;
			}
		}

		$offset = 0;
		$value  = $this->flowValue($text, $offset, false, 0);
		$rest   = trim(self::stripComment(substr($text, $offset)));
		if ($rest !== '') {
			throw self::error('Unexpected text after a value', $this->pos - 1);
		}

		return $value;
	}

	/**
	 * @return mixed
	 */
	private function flowValue(string $text, int &$offset, bool $inFlow, int $depth) {
		if ($depth > self::MAX_DEPTH) {
			throw self::error('Nested too deeply', $this->pos - 1);
		}

		self::skipSpace($text, $offset);
		$char = $text[$offset] ?? '';

		if ($char === '{') {
			return $this->flowMapping($text, $offset, $depth + 1);
		}
		if ($char === '[') {
			return $this->flowSequence($text, $offset, $depth + 1);
		}
		if ($char === '"') {
			return $this->doubleQuoted($text, $offset);
		}
		if ($char === "'") {
			return $this->singleQuoted($text, $offset);
		}
		if (in_array($char, ['&', '*', '!', '%', '@', '`'], true)) {
			throw self::error('Anchors, aliases, tags and reserved characters are not supported', $this->pos - 1);
		}

		return self::resolve($this->plain($text, $offset, $inFlow));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function flowMapping(string $text, int &$offset, int $depth): array {
		++$offset;
		$out = [];

		while (true) {
			self::skipSpace($text, $offset);
			if (($text[$offset] ?? '') === '}') {
				++$offset;

				return $out;
			}

			$key = $this->flowValue($text, $offset, true, $depth);
			if (! is_string($key) && ! is_int($key) && ! is_float($key) && ! is_bool($key) && $key !== null) {
				throw self::error('A mapping key must be a scalar', $this->pos - 1);
			}
			$key = self::keyString($key);

			self::skipSpace($text, $offset);
			$value = null;
			if (($text[$offset] ?? '') === ':') {
				++$offset;
				$value = $this->flowValue($text, $offset, true, $depth);
			}

			if (array_key_exists($key, $out)) {
				throw self::error('Duplicate key "' . $key . '"', $this->pos - 1);
			}
			$out[$key] = $value;

			self::skipSpace($text, $offset);
			$char = $text[$offset] ?? '';
			if ($char === ',') {
				++$offset;
				continue;
			}
			if ($char === '}') {
				++$offset;

				return $out;
			}

			throw self::error('Unterminated flow mapping', $this->pos - 1);
		}
	}

	/**
	 * @return array<int, mixed>
	 */
	private function flowSequence(string $text, int &$offset, int $depth): array {
		++$offset;
		$out = [];

		while (true) {
			self::skipSpace($text, $offset);
			if (($text[$offset] ?? '') === ']') {
				++$offset;

				return $out;
			}

			$out[] = $this->flowValue($text, $offset, true, $depth);

			self::skipSpace($text, $offset);
			$char = $text[$offset] ?? '';
			if ($char === ',') {
				++$offset;
				continue;
			}
			if ($char === ']') {
				++$offset;

				return $out;
			}

			throw self::error('Unterminated flow sequence', $this->pos - 1);
		}
	}

	private function doubleQuoted(string $text, int &$offset): string {
		if (preg_match('/"((?:[^"\\\\]|\\\\.)*)"/As', $text, $m, 0, $offset) !== 1) {
			throw self::error('Unterminated double-quoted string', $this->pos - 1);
		}
		$offset += strlen($m[0]);

		$raw = self::foldQuoted($m[1]);

		$out = preg_replace_callback(
			'/\\\\(u[dD][89abAB][0-9A-Fa-f]{2}\\\\u[dD][c-fC-F][0-9A-Fa-f]{2}|x[0-9A-Fa-f]{2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/s',
			static function (array $e): string {
				$code = $e[1];
				$map  = [
					'0'  => "\0",
					'a'  => "\x07",
					'b'  => "\x08",
					't'  => "\t",
					"\t" => "\t",
					'n'  => "\n",
					'v'  => "\x0B",
					'f'  => "\x0C",
					'r'  => "\r",
					'e'  => "\x1B",
					' '  => ' ',
					'"'  => '"',
					'/'  => '/',
					'\\' => '\\',
					'N'  => "\u{85}",
					'_'  => "\u{A0}",
					'L'  => "\u{2028}",
					'P'  => "\u{2029}",
				];
				if (isset($map[$code])) {
					return $map[$code];
				}
				if (strlen($code) === 11) {
					$high = (int) hexdec(substr($code, 1, 4));
					$low  = (int) hexdec(substr($code, 7, 4));

					return (string) mb_chr(0x10000 + (($high - 0xD800) << 10) + ($low - 0xDC00), 'UTF-8');
				}
				if (in_array($code[0], ['x', 'u', 'U'], true) && strlen($code) > 1) {
					return mb_chr((int) hexdec(substr($code, 1)), 'UTF-8') ?: '';
				}

				throw new \InvalidArgumentException('Unknown escape \\' . $code . ' in a double-quoted string.');
			},
			$raw
		);

		return (string) $out;
	}

	private function singleQuoted(string $text, int &$offset): string {
		if (preg_match("/'((?:[^']|'')*)'/As", $text, $m, 0, $offset) !== 1) {
			throw self::error('Unterminated single-quoted string', $this->pos - 1);
		}
		$offset += strlen($m[0]);

		return str_replace("''", "'", self::foldQuoted($m[1]));
	}

	private function plain(string $text, int &$offset, bool $inFlow): string {
		$start = $offset;
		$len   = strlen($text);

		while ($offset < $len) {
			$char = $text[$offset];
			if ($inFlow && in_array($char, [',', '[', ']', '{', '}'], true)) {
				break;
			}
			if ($char === ':' && ($offset + 1 >= $len || in_array($text[$offset + 1], [' ', "\t", "\n"], true) || ($inFlow && in_array($text[$offset + 1], [',', ']', '}'], true)))) {
				break;
			}
			if ($char === '#' && $offset > $start && in_array($text[$offset - 1], [' ', "\t", "\n"], true)) {
				break;
			}
			++$offset;
		}

		$value = trim(substr($text, $start, $offset - $start));

		return (string) preg_replace('/\s*\n\s*/', ' ', $value);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * YAML 1.2 core schema resolution of a plain scalar.
	 *
	 * @return mixed
	 */
	public static function resolve(string $plain) {
		if (in_array($plain, ['', '~', 'null', 'Null', 'NULL'], true)) {
			return null;
		}
		if (in_array($plain, ['true', 'True', 'TRUE'], true)) {
			return true;
		}
		if (in_array($plain, ['false', 'False', 'FALSE'], true)) {
			return false;
		}
		if (preg_match('/^[-+]?[0-9]+$/', $plain) === 1) {
			$int = filter_var($plain, FILTER_VALIDATE_INT);

			return $int !== false ? $int : (float) $plain;
		}
		if (preg_match('/^0o[0-7]+$/', $plain) === 1) {
			return (int) octdec(substr($plain, 2));
		}
		if (preg_match('/^0x[0-9a-fA-F]+$/', $plain) === 1) {
			return (int) hexdec(substr($plain, 2));
		}
		if (preg_match('/^[-+]?(\.[0-9]+|[0-9]+(\.[0-9]*)?)([eE][-+]?[0-9]+)?$/', $plain) === 1) {
			return (float) $plain;
		}
		if (preg_match('/^[-+]?\.(inf|Inf|INF)$/', $plain) === 1) {
			return str_starts_with($plain, '-') ? -INF : INF;
		}
		if (in_array($plain, ['.nan', '.NaN', '.NAN'], true)) {
			return NAN;
		}

		return $plain;
	}

	/**
	 * "key: rest" when $content starts with a mapping key, else null.
	 *
	 * @return array{0:string, 1:string}|null
	 */
	private function keyOf(string $content): ?array {
		$content = ltrim($content, ' ');
		if ($content === '' || $content[0] === '#') {
			return null;
		}

		$first = $content[0];
		if ($first === '"' || $first === "'") {
			$offset = 0;
			try {
				$key = $first === '"' ? $this->doubleQuoted($content, $offset) : $this->singleQuoted($content, $offset);
			} catch (\InvalidArgumentException) {
				return null;
			}
			$after = substr($content, $offset);
			if (preg_match('/^\s*:(\s|$)/', $after, $m) !== 1) {
				return null;
			}

			return [$key, substr($after, strlen($m[0]))];
		}

		if (in_array($first, ['{', '[', '&', '*', '!', '|', '>', '%', '@', '`'], true)) {
			return null;
		}

		if (preg_match('/^([^#]*?):(?:\s+|$)/', $content, $m) !== 1 || str_contains($m[1], ': ')) {
			return null;
		}

		$key = trim($m[1]);
		if ($key === '') {
			return null;
		}

		return [self::keyString(self::resolve($key)), substr($content, strlen($m[0]))];
	}

	/**
	 * @param mixed $key
	 */
	private static function keyString($key): string {
		if ($key === null) {
			return 'null';
		}
		if (is_bool($key)) {
			return $key ? 'true' : 'false';
		}

		return (string) $key;
	}

	private static function isSequenceItem(string $content): bool {
		return $content === '-' || str_starts_with($content, '- ') || str_starts_with($content, "-\t");
	}

	private function skipBlank(): void {
		while ($this->pos < count($this->lines)) {
			$trimmed = trim($this->lines[$this->pos]);
			if ($trimmed === '---' || $trimmed === '...' || str_starts_with($trimmed, '%')) {
				throw self::error('Document markers and directives are not supported in frontmatter', $this->pos);
			}
			if ($trimmed !== '' && ! str_starts_with($trimmed, '#')) {
				return;
			}
			++$this->pos;
		}
	}

	private static function indentOf(string $line): int {
		if (str_starts_with(ltrim($line, ' '), "\t") && strlen($line) - strlen(ltrim($line, ' ')) === 0 && trim($line) !== '') {
			throw new \InvalidArgumentException('Tabs cannot indent YAML.');
		}

		return strlen($line) - strlen(ltrim($line, ' '));
	}

	private static function stripComment(string $text): string {
		$text = rtrim($text);
		if ($text === '' || $text[0] === '"' || $text[0] === "'" || $text[0] === '{' || $text[0] === '[') {
			// Quoted and flow values remove their own comments after parsing.
			return $text;
		}

		if (str_starts_with($text, '#')) {
			return '';
		}

		$pos = strpos($text, ' #');

		return $pos === false ? $text : rtrim(substr($text, 0, $pos));
	}

	private static function skipSpace(string $text, int &$offset): void {
		$len = strlen($text);
		while ($offset < $len) {
			$char = $text[$offset];
			if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
				++$offset;
				continue;
			}
			if ($char === '#' && ($offset === 0 || in_array($text[$offset - 1], [' ', "\t", "\n"], true))) {
				while ($offset < $len && $text[$offset] !== "\n") {
					++$offset;
				}
				continue;
			}

			return;
		}
	}

	/** True when every bracket and quote opened in $text is closed. */
	private static function balanced(string $text): bool {
		$depth = 0;
		$len   = strlen($text);

		for ($i = 0; $i < $len; $i++) {
			$char = $text[$i];
			if ($char === '"') {
				if (preg_match('/"(?:[^"\\\\]|\\\\.)*"/As', $text, $m, 0, $i) !== 1) {
					return false;
				}
				$i += strlen($m[0]) - 1;
			} elseif ($char === "'") {
				if (preg_match("/'(?:[^']|'')*'/As", $text, $m, 0, $i) !== 1) {
					return false;
				}
				$i += strlen($m[0]) - 1;
			} elseif ($char === '{' || $char === '[') {
				++$depth;
			} elseif ($char === '}' || $char === ']') {
				--$depth;
				if ($depth === 0) {
					return true;
				}
			}
		}

		return $depth <= 0;
	}

	/** Line folding inside a quoted scalar that spans lines. */
	private static function foldQuoted(string $raw): string {
		if (! str_contains($raw, "\n")) {
			return $raw;
		}

		$parts = explode("\n", $raw);
		$out   = rtrim(array_shift($parts), ' ');
		$empty = 0;
		foreach ($parts as $i => $part) {
			$part = trim($part, ' ');
			$last = $i === count($parts) - 1;
			if ($part === '' && ! $last) {
				++$empty;
				continue;
			}
			$out  .= $empty > 0 ? str_repeat("\n", $empty) : ' ';
			$out  .= $part;
			$empty = 0;
		}

		return $out;
	}

	private static function error(string $message, int $line): \InvalidArgumentException {
		return new \InvalidArgumentException(sprintf('%s (frontmatter line %d).', $message, $line + 1));
	}
}
