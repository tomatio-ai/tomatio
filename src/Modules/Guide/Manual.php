<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Guide;

use AgentBrain\Core\Hooks;

/**
 * #861: the TomaTío manual inside every brain, for agents (tomatio-help) and
 * people (TomaTío > Help), whether or not the brain can reach the docs brain.
 *
 * Two copies, the newest first:
 *  - the full manual, fetched from tomatio.ai/docs/manual.json once a day
 *    (no sign-in; the request carries only "TomaTio/<version>") and kept in
 *    the option ab_guide_manual;
 *  - the core pages bundled with the plugin (manual-core.json, written by
 *    tools/sync_manual.py), for a brain that has not fetched it yet or
 *    cannot. The whole manual would not fit in the release zip (#788).
 *
 * The manual is not the team's knowledge: it is never stored as memory,
 * never searched with memory and never part of an export. The fetch can be
 * turned off with the `tomatio/guide_fetch` filter.
 */
final class Manual {

	public const SOURCE_URL = 'https://tomatio.ai/docs/manual.json';
	public const DOCS_URL   = 'https://tomatio.ai/docs';

	/** The full manual as fetched: version, fetched_at and pages. */
	public const OPTION = 'ab_guide_manual';

	/** The last fetch that failed: when and why. */
	public const OPTION_ERROR = 'ab_guide_fetch_error';

	public const CRON_HOOK = 'agent_brain_guide_refresh';

	/** The bundled pages; tools/sync_manual.py keeps the same list. */
	public const CORE = ['README', 'what-is-tomatio', 'connect-an-ai', 'agents-and-scopes', 'export-and-migration'];

	public const MAX_BYTES = 2097152;
	public const MAX_PAGES = 200;

	/** @var array<string, mixed>|null */
	private static ?array $bundled = null;

	/**
	 * The newest manual this brain has: source 'tomatio.ai' (fetched) or
	 * 'bundled'.
	 *
	 * @return array{source:string, version:string, fetched_at:int|null, pages:array<int, array{name:string, title:string, url:string, html:string}>}
	 */
	public static function current(): array {
		$fetched = get_option(self::OPTION, null);
		if (is_array($fetched) && isset($fetched['pages']) && is_array($fetched['pages']) && $fetched['pages'] !== []) {
			return [
				'source'     => 'tomatio.ai',
				'version'    => (string) ($fetched['version'] ?? ''),
				'fetched_at' => (int) ($fetched['fetched_at'] ?? 0),
				'pages'      => $fetched['pages'],
			];
		}

		$bundled = self::bundled();

		return ['source' => 'bundled', 'version' => (string) $bundled['version'], 'fetched_at' => null, 'pages' => $bundled['pages']];
	}

	/**
	 * The core pages shipped with the plugin.
	 *
	 * @return array{version:string, pages:array<int, array{name:string, title:string, url:string, html:string}>}
	 */
	public static function bundled(): array {
		if (self::$bundled === null) {
			$raw            = is_readable(__DIR__ . '/manual-core.json') ? (string) file_get_contents(__DIR__ . '/manual-core.json') : '';
			$pages          = self::validate(json_decode($raw, true));
			self::$bundled  = $pages ?? ['version' => '', 'pages' => []];
		}

		return self::$bundled;
	}

	/**
	 * One page by name, or null.
	 *
	 * @return array{name:string, title:string, url:string, html:string}|null
	 */
	public static function page(string $name): ?array {
		foreach (self::current()['pages'] as $page) {
			if ($page['name'] === $name) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * Fetches the full manual from tomatio.ai and keeps it. False when the
	 * fetch is turned off or fails; the copy already kept stays.
	 */
	public static function refresh(): bool {
		if (! (bool) Hooks::filter('guide_fetch', true)) {
			return false;
		}

		$response = wp_safe_remote_get(self::SOURCE_URL, [
			'timeout'             => 10,
			'redirection'         => 2,
			'limit_response_size' => self::MAX_BYTES,
			// Not WordPress's default, which names the site.
			'user-agent'          => 'TomaTio/' . (defined('AGENT_BRAIN_VERSION') ? AGENT_BRAIN_VERSION : '0'),
			'headers'             => ['Accept' => 'application/json'],
		]);

		if (is_wp_error($response)) {
			return self::failed($response->get_error_message());
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			return self::failed(sprintf('HTTP %d', $code));
		}

		$manual = self::validate(json_decode((string) wp_remote_retrieve_body($response), true));
		if ($manual === null) {
			return self::failed('not a TomaTío manual');
		}

		update_option(self::OPTION, $manual + ['fetched_at' => time()], false);
		delete_option(self::OPTION_ERROR);

		return true;
	}

	/** The daily fetch (WP-Cron). */
	public static function runScheduled(): void {
		self::refresh();
	}

	/** Schedules the daily fetch, the first one a minute from now. */
	public static function schedule(): void {
		if (function_exists('wp_next_scheduled') && ! wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK);
		}
	}

	/**
	 * A manual.json (or the bundled file) as clean pages, or null when it is
	 * not one. Page HTML keeps only what a post may hold (wp_kses_post).
	 *
	 * @param mixed $data
	 * @return array{version:string, pages:array<int, array{name:string, title:string, url:string, html:string}>}|null
	 */
	public static function validate($data): ?array {
		if (! is_array($data) || ! is_string($data['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/', $data['version']) !== 1) {
			return null;
		}
		if (! is_array($data['pages'] ?? null) || $data['pages'] === [] || count($data['pages']) > self::MAX_PAGES) {
			return null;
		}

		$pages = [];
		$names = [];
		foreach ($data['pages'] as $page) {
			if (! is_array($page)) {
				return null;
			}
			$name  = $page['name'] ?? null;
			$title = $page['title'] ?? null;
			$url   = $page['url'] ?? null;
			$html  = $page['html'] ?? null;
			if (! is_string($name) || preg_match('/^(README|[a-z0-9-]{1,64})$/', $name) !== 1 || isset($names[$name])) {
				return null;
			}
			if (! is_string($title) || $title === '' || ! is_string($html) || $html === '' || ! is_string($url) || ! str_starts_with($url, 'https://tomatio.ai/')) {
				return null;
			}

			$names[$name] = true;
			$pages[]      = [
				'name'  => $name,
				'title' => sanitize_text_field($title),
				'url'   => esc_url_raw($url),
				'html'  => wp_kses_post($html),
			];
		}

		return ['version' => $data['version'], 'pages' => $pages];
	}

	/**
	 * Sections of the manual that answer $question, best first.
	 *
	 * @return array<int, array{page:string, title:string, section:string, url:string, text:string}>
	 */
	public static function search(string $question, int $limit = 3): array {
		$terms = self::terms($question);
		if ($terms === []) {
			return [];
		}

		$scored = [];
		foreach (self::current()['pages'] as $page) {
			$pageTitle = self::fold($page['title']);
			foreach (self::sections($page) as $section) {
				$heading = self::fold($section['heading']);
				$body    = self::fold(self::text($section['html']));
				$score   = 0;
				$matched = 0;
				foreach ($terms as $term) {
					// Words that start with the term: "tio" finds "tios", not "migration".
					$word   = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '/u';
					$hits   = min(5, (int) preg_match_all($word, $body));
					$inHead = preg_match($word, $heading) === 1;
					$inPage = preg_match($word, $pageTitle) === 1;
					$score += $hits + ($inHead ? 4 : 0) + ($inPage ? 2 : 0);
					$matched += ($hits > 0 || $inHead || $inPage) ? 1 : 0;
				}
				if ($score === 0) {
					continue;
				}
				// A section that matches every word beats one that repeats a
				// single word; the contents page, mostly links, counts half.
				$scored[] = [
					'score'   => $score * $matched / ($page['name'] === 'README' ? 2 : 1),
					'page'    => $page['name'],
					'title'   => $page['title'],
					'section' => $section['heading'],
					'url'     => $page['url'] . ($section['anchor'] !== '' ? '#' . $section['anchor'] : ''),
					'text'    => self::clip(self::text($section['html']), 3000),
				];
			}
		}

		usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

		return array_map(static function (array $hit): array {
			unset($hit['score']);

			return $hit;
		}, array_slice($scored, 0, max(1, $limit)));
	}

	/**
	 * A page split at its h2 headings; the part before the first one is the
	 * page's introduction (heading = the page title, no anchor).
	 *
	 * @param array{name:string, title:string, url:string, html:string} $page
	 * @return array<int, array{heading:string, anchor:string, html:string}>
	 */
	public static function sections(array $page): array {
		$parts    = preg_split('/(<h2\b[^>]*>.*?<\/h2>)/is', $page['html'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$page['html']];
		$sections = [['heading' => $page['title'], 'anchor' => '', 'html' => (string) preg_replace('/<h1\b[^>]*>.*?<\/h1>/is', '', (string) array_shift($parts))]];

		while ($parts !== []) {
			$head = (string) array_shift($parts);
			$body = (string) array_shift($parts);
			preg_match('/\bid="([^"]*)"/i', $head, $id);
			$sections[] = [
				'heading' => trim(html_entity_decode(wp_strip_all_tags($head), ENT_QUOTES, 'UTF-8')),
				'anchor'  => (string) ($id[1] ?? ''),
				'html'    => $body,
			];
		}

		return array_values(array_filter($sections, static fn (array $s): bool => trim(wp_strip_all_tags($s['html'])) !== '' || $s['anchor'] !== ''));
	}

	/**
	 * Manual HTML as plain text an AI reads easily: headings as "##",
	 * list items as "-", links as "text (address)", code in backticks.
	 */
	public static function text(string $html): string {
		$text = (string) preg_replace_callback('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', static fn (array $m): string => "\n\n" . str_repeat('#', (int) $m[1]) . ' ' . trim(wp_strip_all_tags($m[2])) . "\n", $html);
		$text = (string) preg_replace_callback('/<pre\b[^>]*>(.*?)<\/pre>/is', static fn (array $m): string => "\n```\n" . trim(wp_strip_all_tags($m[1])) . "\n```\n", $text);
		$text = (string) preg_replace_callback('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', static function (array $m): string {
			$label = trim(wp_strip_all_tags($m[2]));
			$href  = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

			return $href === '' || str_starts_with($href, '#') ? $label : $label . ' (' . $href . ')';
		}, $text);
		$text = (string) preg_replace('/<li\b[^>]*>/i', "\n- ", $text);
		$text = (string) preg_replace('/<\/?code\b[^>]*>/i', '`', $text);
		$text = (string) preg_replace('/<\/?(strong|b)\b[^>]*>/i', '**', $text);
		$text = (string) preg_replace('/<tr\b[^>]*>/i', "\n| ", $text);
		$text = (string) preg_replace('/<\/t[dh]>/i', ' | ', $text);
		$text = (string) preg_replace('/<\/(p|ul|ol|table|blockquote|div)>/i', "\n\n", $text);
		$text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8');
		$text = (string) preg_replace('/[ \t]+\n/', "\n", $text);

		return trim((string) preg_replace('/\n{3,}/', "\n\n", $text));
	}

	/** At most $max characters, cut at a paragraph or a line when one is near. */
	public static function clip(string $text, int $max): string {
		if (mb_strlen($text) <= $max) {
			return $text;
		}

		$cut  = mb_substr($text, 0, $max);
		$stop = max((int) mb_strrpos($cut, "\n\n"), (int) mb_strrpos($cut, "\n"));

		return rtrim($stop > $max / 2 ? mb_substr($cut, 0, $stop) : $cut) . "\n…";
	}

	/** @return array<int, string> Lower-case words of the question, accents and common words left out. */
	private static function terms(string $question): array {
		$stop  = ['the', 'and', 'for', 'how', 'what', 'does', 'with', 'this', 'that', 'are', 'can', 'you', 'your', 'from', 'into', 'about', 'when', 'where', 'which', 'why', 'who', 'its', 'our', 'use', 'get', 'set', 'tomatio'];
		$words = preg_split('/[^\p{L}\p{N}]+/u', self::fold($question)) ?: [];
		$terms = [];
		foreach ($words as $word) {
			if (mb_strlen($word) < 3 || in_array($word, $stop, true)) {
				continue;
			}
			// "tíos" finds "tío", "brains" finds "brain".
			$terms[] = mb_strlen($word) > 3 && str_ends_with($word, 's') ? mb_substr($word, 0, -1) : $word;
		}

		return array_values(array_unique(array_slice($terms, 0, 12)));
	}

	private static function fold(string $text): string {
		return mb_strtolower(remove_accents(html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
	}

	private static function failed(string $why): bool {
		update_option(self::OPTION_ERROR, ['at' => time(), 'error' => substr($why, 0, 200)], false);

		return false;
	}
}
