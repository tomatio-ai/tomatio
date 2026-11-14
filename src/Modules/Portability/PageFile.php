<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Splits a page file (PCP v0.3 SPEC section 8.2 item 1) into its
 * frontmatter, read as YAML, and its body: the bytes after the line that
 * closes the frontmatter block (section 8.8), exactly as they are. Pure.
 */
final class PageFile {

	/**
	 * @return array{frontmatter:array<string, mixed>, body:string}
	 * @throws \InvalidArgumentException
	 */
	public static function parse(string $bytes): array {
		if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
			throw new \InvalidArgumentException('The page starts with a byte order mark.');
		}
		if (! mb_check_encoding($bytes, 'UTF-8')) {
			throw new \InvalidArgumentException('The page is not valid UTF-8.');
		}
		if (preg_match('/\A---[ \t]*\r?\n/', $bytes, $open) !== 1) {
			throw new \InvalidArgumentException('The page has no frontmatter block.');
		}

		$start = strlen($open[0]);
		if (preg_match('/^---[ \t]*(\r?\n|\z)/m', $bytes, $close, PREG_OFFSET_CAPTURE, $start) !== 1) {
			throw new \InvalidArgumentException('The frontmatter block is not closed.');
		}

		$closeAt = (int) $close[0][1];
		$yaml    = substr($bytes, $start, $closeAt - $start);
		$body    = substr($bytes, $closeAt + strlen($close[0][0]));

		return ['frontmatter' => Yaml::parseMapping($yaml), 'body' => $body];
	}
}
