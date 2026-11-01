<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Core\Text;

/**
 * REV-1 review-queue helper: find the most similar already-approved
 * knowledge item in the same scope, and render a diff against it (via
 * WordPress core's `wp_text_diff()`) for the curator to review.
 */
class Diff {

	/**
	 * Per-side cap (characters) on the plain text handed to wp_text_diff()
	 * for the rendered "Diff vs most similar approved" column (NEW B4). A
	 * 27 KB assembled paper previously produced a diff dump of raw block
	 * markup for the whole document; bounding each side keeps the column
	 * readable regardless of proposal size.
	 */
	private const MAX_DIFF_CHARS = 2000;

	/**
	 * Words of unchanged context kept on each side of a change in the
	 * compact "changed segments" summary (formatting wave, NEW). A run of
	 * unchanged words longer than 2x this is collapsed to "word … word"
	 * instead of being echoed in full, so a proposal that differs from its
	 * closest approved match by only a sentence or two shows just that
	 * sentence, not the whole ~2,000-char bounded document highlighted red
	 * end to end (the live defect this fixes: every proposal's diff column
	 * rendered the entire approved paper in red).
	 */
	private const CONTEXT_WORDS = 6;

	/**
	 * Visible-character cap (rendered text, not markup) for the compact
	 * changed-segments summary (formatting wave, NEW). The uncapped,
	 * unabridged side-by-side comparison is still available -- it is never
	 * dropped, only moved into the `<details>` element
	 * renderAgainstMostSimilar() wraps around it.
	 */
	private const MAX_SUMMARY_VISIBLE_CHARS = 600;

	/**
	 * Cap on the number of entries kept in $approvedPlainTextCache (Unit G,
	 * follow-up wave). The memo is per-request, but "per request" was
	 * unbounded: a curator opening the queue in a long-lived request (e.g.
	 * WP-CLI, a batch admin-ajax job, or simply a scope with thousands of
	 * approved posts) could grow it without limit. Oldest entry is evicted
	 * (insertion order, PHP arrays preserve it) whenever a new entry would
	 * push the memo past this size -- bounded memory regardless of how many
	 * distinct approved posts a single request scores.
	 */
	private const MAX_CACHE_ENTRIES = 256;

	/**
	 * Minimum {@see similarityScore()} (0-100) for the nearest approved item
	 * to count as a close match, so the queue shows a diff against it
	 * (#658). Below it the proposal is a new fact and the column says so.
	 *
	 * The polish wave set this at 15, which kept a diff for two unrelated
	 * short sentences (~27): the diff column then compared nearly every
	 * proposal with whatever was closest, such as an unrelated imported
	 * table row, and was mostly noise. Measured on this plugin's fixtures
	 * (AdminPolishTest pins both sides): unrelated sentences of comparable
	 * length score ~27, a short proposal against a long unrelated paper
	 * under 1, while a fact restated with one detail changed and the
	 * near-duplicate regression fixtures score 75-100. 60 sits between the
	 * two groups with margin on both sides.
	 */
	public const CLOSE_MATCH_THRESHOLD = 60.0;

	/**
	 * Per-request memo of each approved post's bounded plain text, keyed by
	 * `"{$postId}:{$postModifiedGmt}"` so a stale cache entry from a post
	 * edited mid-request (or a differently-seeded fixture in tests) is never
	 * reused. Fix-round-2 perf fix (see mostSimilarApproved()): without this,
	 * a queue page with N proposed rows against M approved candidates paid
	 * for blockExcerpt()+trim of every candidate N times over. Capped at
	 * MAX_CACHE_ENTRIES, oldest evicted first -- see MAX_CACHE_ENTRIES.
	 *
	 * @var array<string, string>
	 */
	private static array $approvedPlainTextCache = [];

	/**
	 * Pure similarity score in [0, 100], symmetric, deterministic.
	 */
	public static function similarityScore(string $a, string $b): float {
		if (trim($a) === '' || trim($b) === '') {
			return 0.0;
		}

		similar_text($a, $b, $percent);

		return $percent;
	}

	/**
	 * Most similar `private` (approved) post in the same `ab_scope` term and
	 * post type, or null if there is no candidate at all.
	 *
	 * Fix-round-2 (measured HIGH): this used to score `similar_text()` on
	 * raw block markup for every (proposed row x approved candidate) pair on
	 * every queue load -- ~2.7s per 13 KB candidate, ~15s per 27 KB
	 * candidate, so a handful of long documents could time out the queue
	 * page. It now scores the same bounded plain text used for rendering
	 * (blockExcerpt() + boundedPlainText(), capped at MAX_DIFF_CHARS), and
	 * each approved candidate's bounded plain text is memoised for the
	 * request via $approvedPlainTextCache so repeated calls (one per
	 * proposed row) compute it once per candidate, not once per pair.
	 * Bounding both sides before scoring does change the *number* fed to
	 * similar_text() for documents longer than MAX_DIFF_CHARS, but the tie
	 * rule and comparison (`$score > $bestScore`, i.e. first-seen-wins on a
	 * tie, in get_posts()'s default order) are unchanged, and scoring stays
	 * deterministic -- same inputs (now: same bounded plain text) always
	 * produce the same score.
	 */
	public static function mostSimilarApproved(int $scopeId, string $text, string $postType = 'ab_knowledge'): ?int {
		$best = self::bestMatch($scopeId, $text, $postType);

		return $best === null ? null : $best[0];
	}

	/**
	 * The nearest approved item and how close it is (#658): its id, title
	 * and {@see similarityScore()}, or null when the scope has no approved
	 * item of this type. Same scoring and tie rule as mostSimilarApproved().
	 *
	 * @return array{id:int, title:string, similarity:float}|null
	 */
	public static function nearest(int $scopeId, string $text, string $postType = 'ab_knowledge'): ?array {
		$best = self::bestMatch($scopeId, $text, $postType);
		if ($best === null) {
			return null;
		}

		$title = self::plainTitle($best[0]);

		return ['id' => $best[0], 'title' => $title !== '' ? $title : '#' . $best[0], 'similarity' => round($best[1], 1)];
	}

	/**
	 * Whether a proposal says word for word what official item $officialId
	 * says (#701). Not a similarity score: a fact that only changes a
	 * number ("1.8 MB" to "1.9 MB") scores above 95 and is still a change.
	 */
	public static function saysTheSame(int $officialId, string $text): bool {
		return self::sameWords(self::cachedApprovedPlainText($officialId), self::boundedPlainText(ReviewQueuePage::blockExcerpt($text)));
	}

	private static function sameWords(string $existing, string $proposed): bool {
		return preg_split('/\s+/', trim($existing)) === preg_split('/\s+/', trim($proposed));
	}

	/** Whether a {@see similarityScore()} counts as a close match (#658). */
	public static function isCloseMatch(float $score): bool {
		return $score >= self::CLOSE_MATCH_THRESHOLD;
	}

	/**
	 * @return array{0:int, 1:float}|null [post id, score]
	 */
	private static function bestMatch(int $scopeId, string $text, string $postType): ?array {
		if (! function_exists('get_posts')) {
			return null;
		}

		$candidates = get_posts([
			'post_type'      => $postType,
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'tax_query'      => [
				[
					'taxonomy' => 'ab_scope',
					'field'    => 'term_id',
					'terms'    => [$scopeId],
				],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		$proposedPlain = self::boundedPlainText(ReviewQueuePage::blockExcerpt($text));

		$bestId    = null;
		$bestScore = -1.0;

		foreach ($candidates as $candidateId) {
			$candidatePlain = self::cachedApprovedPlainText((int) $candidateId);
			$score          = self::similarityScore($proposedPlain, $candidatePlain);

			if ($score > $bestScore) {
				$bestScore = $score;
				$bestId    = (int) $candidateId;
			}
		}

		return $bestId === null ? null : [$bestId, $bestScore];
	}

	/**
	 * Compact "changed segments" summary plus the full side-by-side
	 * comparison (collapsed behind a `<details>`) when the nearest approved
	 * item is a close match (CLOSE_MATCH_THRESHOLD); "New fact" when it is
	 * not; "Already official as ..." when both sides are the same text; or
	 * null if the scope has no approved item of this type at all (#658).
	 *
	 * NEW B4 fix: both sides are reduced to plain text (via
	 * ReviewQueuePage::blockExcerpt(), the same block-aware extraction used
	 * for the queue's excerpt/word-count column) before diffing, instead of
	 * the raw serialized block markup -- diffing markup dumped the whole
	 * `<!-- wp:paragraph --><p>...` document into the column for a 27 KB
	 * assembled paper. Each side is then bounded to MAX_DIFF_CHARS
	 * characters (UTF-8-safe, word-boundary cut via Core\Text, "..."
	 * appended when cut) so the rendered diff stays a bounded size
	 * regardless of proposal length.
	 *
	 * Formatting wave fix (live defect: the diff column rendered the whole
	 * ~2,000-char bounded document in red for every single proposal): the
	 * previous implementation handed both bounded plain-text sides straight
	 * to wp_text_diff(), which diffs at the *line* level -- since bounded
	 * plain text has no newlines (Core\Text::plainTextFromBlocks() joins
	 * everything onto one line), any difference anywhere made
	 * Text_Diff treat the entire line as one changed hunk. This method now
	 * also builds a word-level diff (via the same core `Text_Diff` engine
	 * wp_text_diff() uses, guaranteed loaded by the wp_text_diff() call
	 * below) and renders only the changed words plus a small amount of
	 * unchanged context on each side (renderChangedSegments()), with the
	 * full wp_text_diff() table kept, unabridged, inside a
	 * `<details><summary>Show full comparison</summary>...</details>`
	 * element so nothing is lost, only re-ordered. Both the summary and the
	 * full table go through the same escaping wp_text_diff()/esc_html()
	 * already applied -- no new unescaped content is introduced.
	 */
	public static function renderAgainstMostSimilar(int $scopeId, string $text, string $postType = 'ab_knowledge'): ?string {
		$similarId = self::mostSimilarApproved($scopeId, $text, $postType);
		if ($similarId === null) {
			return null;
		}

		$existingPlain = self::cachedApprovedPlainText($similarId);
		$proposedPlain = self::boundedPlainText(ReviewQueuePage::blockExcerpt($text));

		if (! self::isCloseMatch(self::similarityScore($existingPlain, $proposedPlain))) {
			return self::renderNewFact();
		}

		// #658: the same text as an approved item is not a new fact; the
		// queue used to show it as "no similar approved item".
		if ($existingPlain === $proposedPlain) {
			return self::renderSame($similarId);
		}

		// Loads the Text_Diff/WP_Text_Diff_Renderer_Table classes (via
		// wp-includes/wp-diff.php) as a side effect if they are not already
		// loaded -- renderChangedSegments() below relies on Text_Diff being
		// available and deliberately runs after this call.
		$fullHtml = wp_text_diff($existingPlain, $proposedPlain, ['title' => '', 'show_split_view' => true]);
		if (! is_string($fullHtml) || $fullHtml === '') {
			return self::renderSame($similarId);
		}

		$summary = self::renderChangedSegments($existingPlain, $proposedPlain);

		return $summary
			. '<details><summary>' . esc_html__('Show full comparison', 'agent-brain') . '</summary>'
			. $fullHtml
			. '</details>';
	}

	/**
	 * #701: what the Review inbox shows for one proposal. kind is "update"
	 * (a close match: html is the whole proposal as a word diff against
	 * the official text), "same" (word for word what an official item
	 * already says), or "new" (nothing close; closest names the nearest
	 * item, if any). Same close-match score as renderAgainstMostSimilar();
	 * "same" is {@see saysTheSame()}.
	 *
	 * @return array{kind:string, id:int, title:string, similarity:float, official:string, html:string}
	 */
	public static function compare(int $scopeId, string $text, string $postType = 'ab_knowledge'): array {
		$best   = self::bestMatch($scopeId, $text, $postType);
		$result = ['kind' => 'new', 'id' => 0, 'title' => '', 'similarity' => 0.0, 'official' => '', 'html' => ''];
		if ($best === null) {
			return $result;
		}

		$existingPlain = self::cachedApprovedPlainText($best[0]);
		$proposedPlain = self::boundedPlainText(ReviewQueuePage::blockExcerpt($text));
		$score         = self::similarityScore($existingPlain, $proposedPlain);
		$title         = self::plainTitle($best[0]);

		$result['id']         = $best[0];
		$result['title']      = $title !== '' ? $title : '#' . $best[0];
		$result['similarity'] = round($score, 1);

		if (! self::isCloseMatch($score)) {
			return $result;
		}

		$result['official'] = $existingPlain;
		if (self::sameWords($existingPlain, $proposedPlain)) {
			$result['kind'] = 'same';
		} else {
			$result['kind'] = 'update';
		}

		if (! class_exists('Text_Diff') && defined('ABSPATH')) {
			require_once ABSPATH . 'wp-includes/wp-diff.php';
		}
		$result['html'] = self::renderWords($existingPlain, $proposedPlain);

		return $result;
	}

	/**
	 * The whole of $proposed as words, with what differs from $existing in
	 * `<del>`/`<ins>` (#701). Unlike renderChangedSegments() nothing is
	 * collapsed: the inbox shows the full text with the changes in place.
	 */
	public static function renderWords(string $existing, string $proposed): string {
		$origWords  = preg_split('/\s+/', trim($existing)) ?: [];
		$finalWords = preg_split('/\s+/', trim($proposed)) ?: [];
		$diff       = new \Text_Diff('auto', [$origWords, $finalWords]);

		$parts = [];
		foreach ($diff->getDiff() as $op) {
			$orig  = is_array($op->orig) ? implode(' ', $op->orig) : '';
			$final = is_array($op->final) ? implode(' ', $op->final) : '';
			if ($op instanceof \Text_Diff_Op_copy) {
				$parts[] = esc_html($orig);
			} elseif ($op instanceof \Text_Diff_Op_delete) {
				$parts[] = '<del>' . esc_html($orig) . '</del>';
			} elseif ($op instanceof \Text_Diff_Op_add) {
				$parts[] = '<ins>' . esc_html($final) . '</ins>';
			} elseif ($op instanceof \Text_Diff_Op_change) {
				$parts[] = '<del>' . esc_html($orig) . '</del> <ins>' . esc_html($final) . '</ins>';
			}
		}

		return implode(' ', $parts);
	}

	/**
	 * Rendered in place of a diff when the nearest approved candidate is not
	 * a close match (#658): the proposal is a new fact, and naming the
	 * nearest item only invited a comparison that meant nothing. Null from
	 * renderAgainstMostSimilar() (no candidate at all) reads the same on the
	 * queue page.
	 */
	private static function renderNewFact(): string {
		return '<p class="ab-diff-new-fact">' . esc_html__('New fact', 'agent-brain') . '</p>';
	}

	/**
	 * The stored title: approved items are `private`, and get_the_title()
	 * would prefix "Private: " to every one of them.
	 */
	private static function plainTitle(int $postId): string {
		return trim((string) get_post_field('post_title', $postId));
	}

	/** Rendered when the proposal says what an approved item already says. */
	private static function renderSame(int $approvedId): string {
		$title = self::plainTitle($approvedId);

		return '<p class="ab-diff-same">' . sprintf(
			/* translators: %s: title of the approved item with the same text */
			esc_html__('Already official as "%s"', 'agent-brain'),
			esc_html($title !== '' ? $title : ('#' . $approvedId))
		) . '</p>';
	}

	/**
	 * Word-level "changed segments" summary: unchanged runs longer than
	 * 2*CONTEXT_WORDS collapse to "<= CONTEXT_WORDS words> … <= CONTEXT_WORDS
	 * words>"; changed runs are shown in full, wrapped in `<del>`/`<ins>`.
	 * Stops once the rendered *visible* text (tags excluded) would exceed
	 * MAX_SUMMARY_VISIBLE_CHARS, appending a trailing ellipsis to signal
	 * truncation -- the full, untruncated comparison is always still
	 * available via the `<details>` renderAgainstMostSimilar() wraps around
	 * this summary.
	 */
	private static function renderChangedSegments(string $existing, string $proposed): string {
		$origWords  = preg_split('/\s+/', trim($existing)) ?: [];
		$finalWords = preg_split('/\s+/', trim($proposed)) ?: [];

		// 'auto' + [orig, final] (rather than the 2-array-args
		// backward-compatibility form Text_Diff also accepts) matches its
		// declared constructor(string $engine, array $params) signature.
		$diff = new \Text_Diff('auto', [$origWords, $finalWords]);
		$ops  = $diff->getDiff();
		$opsCount = count($ops);

		$html    = '';
		$visible = 0;
		$truncated = false;

		foreach ($ops as $index => $op) {
			if ($visible >= self::MAX_SUMMARY_VISIBLE_CHARS) {
				$truncated = true;
				break;
			}

			if ($op instanceof \Text_Diff_Op_copy) {
				$words = is_array($op->orig) ? $op->orig : [];
				if (count($words) <= self::CONTEXT_WORDS * 2) {
					$segment = implode(' ', $words);
					$html   .= esc_html($segment) . ' ';
					$visible += mb_strlen($segment) + 1;

					continue;
				}

				if ($index > 0) {
					$lead = implode(' ', array_slice($words, 0, self::CONTEXT_WORDS));
					$html   .= esc_html($lead) . ' ';
					$visible += mb_strlen($lead) + 1;
				}

				$html    .= '<span class="ab-diff-ellipsis">…</span> ';
				$visible += 2;

				if ($index < $opsCount - 1) {
					$tail = implode(' ', array_slice($words, -self::CONTEXT_WORDS));
					$html   .= esc_html($tail) . ' ';
					$visible += mb_strlen($tail) + 1;
				}

				continue;
			}

			if ($op instanceof \Text_Diff_Op_delete) {
				$segment = implode(' ', is_array($op->orig) ? $op->orig : []);
				$html   .= '<del>' . esc_html($segment) . '</del> ';
				$visible += mb_strlen($segment) + 1;

				continue;
			}

			if ($op instanceof \Text_Diff_Op_add) {
				$segment = implode(' ', is_array($op->final) ? $op->final : []);
				$html   .= '<ins>' . esc_html($segment) . '</ins> ';
				$visible += mb_strlen($segment) + 1;

				continue;
			}

			if ($op instanceof \Text_Diff_Op_change) {
				$origSegment  = implode(' ', is_array($op->orig) ? $op->orig : []);
				$finalSegment = implode(' ', is_array($op->final) ? $op->final : []);
				$html   .= '<del>' . esc_html($origSegment) . '</del> <ins>' . esc_html($finalSegment) . '</ins> ';
				$visible += mb_strlen($origSegment) + mb_strlen($finalSegment) + 2;
			}
		}

		if ($truncated) {
			$html .= '<span class="ab-diff-ellipsis">…</span>';
		}

		return '<div class="ab-diff-summary">' . trim($html) . '</div>';
	}

	/**
	 * Bounded plain text for an approved post, memoised per request (see
	 * $approvedPlainTextCache) so scoring it against every proposed row on
	 * the queue page computes blockExcerpt()+trim once per candidate rather
	 * than once per (proposed row x candidate) pair.
	 */
	private static function cachedApprovedPlainText(int $postId): string {
		$modified = (string) get_post_field('post_modified_gmt', $postId);
		$cacheKey = $postId . ':' . $modified;

		if (! array_key_exists($cacheKey, self::$approvedPlainTextCache)) {
			$content = (string) get_post_field('post_content', $postId);

			self::rememberApprovedPlainText($cacheKey, self::boundedPlainText(ReviewQueuePage::blockExcerpt($content)));
		}

		return self::$approvedPlainTextCache[$cacheKey];
	}

	/**
	 * Stores $value under $cacheKey, evicting the oldest entry first
	 * (insertion order) whenever the memo would otherwise exceed
	 * MAX_CACHE_ENTRIES. Re-storing an existing key (a no-op in practice --
	 * cachedApprovedPlainText() only calls this on a miss) would also reset
	 * its position to "most recently added" the same way array_shift() based
	 * eviction naturally does, so this stays a plain LRU-by-insertion memo
	 * without needing a separate access-order structure.
	 */
	private static function rememberApprovedPlainText(string $cacheKey, string $value): void {
		if (count(self::$approvedPlainTextCache) >= self::MAX_CACHE_ENTRIES) {
			$oldestKey = array_key_first(self::$approvedPlainTextCache);
			if ($oldestKey !== null) {
				unset(self::$approvedPlainTextCache[$oldestKey]);
			}
		}

		self::$approvedPlainTextCache[$cacheKey] = $value;
	}

	/**
	 * Bounds $text to at most MAX_DIFF_CHARS characters, cutting on a word
	 * boundary via the shared UTF-8-safe Text::trimToWordBoundary() and
	 * appending an ellipsis when the text was actually cut (never when it
	 * already fit, and never splitting a multi-byte character -- CJK/emoji
	 * content stays valid UTF-8 after the cut).
	 *
	 * Formatting wave fix (live defect: literal `&quot;` showing up in the
	 * rendered diff column): ReviewQueuePage::blockExcerpt() strips tags
	 * but never decodes HTML entities, so a source block whose stored
	 * markup contains an already-entity-encoded quote (e.g. `<p>the
	 * &quot;backup&quot; command</p>`, which WordPress's editor legitimately
	 * produces) survives tag-stripping as the *literal six characters*
	 * `&quot;`, not a `"` character. wp_text_diff() (and this class's own
	 * esc_html() in renderChangedSegments()) then escape that text for
	 * HTML output -- correctly, but the `&` in the already-literal
	 * `&quot;` becomes `&amp;`, so the rendered page shows `&amp;quot;`,
	 * which the browser displays as the literal string `&quot;` instead of
	 * a `"` character: an entity encoded a second time. Decoding entities
	 * here, once, before either diff path ever escapes the text for
	 * output, restores the single-escape invariant (raw text in, one
	 * escaping pass out) without touching the shared Core\Text extraction
	 * every other caller (Briefing, ArtifactService, ReviewQueuePage's
	 * excerpt/word-count column) also depends on.
	 */
	private static function boundedPlainText(string $text): string {
		$normalized = mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		$normalized = html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		if (mb_strlen($normalized, 'UTF-8') <= self::MAX_DIFF_CHARS) {
			return $normalized;
		}

		return Text::trimToWordBoundary($normalized, self::MAX_DIFF_CHARS) . '…';
	}
}
