<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * Shared UTF-8-safe text trimming, used by both Briefing (B1 truncated
 * excerpts) and ReviewActions (B2 bounded outcome memory). Extracted after
 * a reflection-probe-verified HIGH finding: two separate byte-oriented
 * (strlen/substr) trim implementations could split a multi-byte UTF-8
 * character in half -- CJK or emoji-heavy content came back with a stray
 * replacement character (U+FFFD) after truncation, and the same broken
 * bytes could be written straight into the memory content column.
 *
 * $maxChars here is a CHARACTER count (mb_strlen), not a byte count --
 * callers computing a byte- or token-based budget must convert (and, since
 * multi-byte characters cost more than one byte each, treat the conversion
 * as an estimate to be corrected for, not an exact figure).
 *
 * plainTextFromBlocks()'s fallback path (no `parse_blocks()` available) also
 * inserts a space after a closing `</th>`, not just `</td>`/`</tr>` -- an
 * intentional improvement over the original regex so a table header cell
 * immediately followed by another element never glues onto it either; this
 * only affects the parser-less fallback and does not change any other
 * behaviour.
 */
final class Text {

	/**
	 * How far back from the cut point to look for a whitespace character
	 * before giving up and using a plain character cut. Keeps the search
	 * O(window) instead of O(text length) for huge content, and matches the
	 * "cut on a word boundary" contract for ordinary space-delimited text --
	 * CJK/emoji runs with no whitespace at all simply fall through to the
	 * clean, multi-byte-safe character cut below (never a raw byte cut, so
	 * the result is always valid UTF-8).
	 */
	private const WHITESPACE_TAIL_WINDOW = 80;

	/**
	 * Trim $text to at most $maxChars *characters*, cutting back to the last
	 * whitespace character found within a reasonable tail window of the
	 * limit when one exists, or otherwise to a clean character boundary.
	 * Always returns valid UTF-8 -- mb_strlen()/mb_substr() operate on
	 * characters, so this never splits a multi-byte character the way a
	 * byte-oriented strlen()/substr() cut can. Returns $text (trimmed of
	 * surrounding whitespace only) unchanged when it already fits.
	 */
	public static function trimToWordBoundary( string $text, int $maxChars ): string {
		if ( $maxChars <= 0 ) {
			return '';
		}

		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			// Defensive: scrub anything that isn't valid UTF-8 rather than
			// ever pass it on -- untrusted content is never assumed to
			// already be well-formed.
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}

		if ( mb_strlen( $text, 'UTF-8' ) <= $maxChars ) {
			return trim( $text );
		}

		$cut = mb_substr( $text, 0, $maxChars, 'UTF-8' );

		$windowStart  = max( 0, mb_strlen( $cut, 'UTF-8' ) - self::WHITESPACE_TAIL_WINDOW );
		$tail         = mb_substr( $cut, $windowStart, null, 'UTF-8' );
		$tailSpacePos = self::lastWhitespaceCharPos( $tail );

		if ( $tailSpacePos !== null ) {
			$absolutePos = $windowStart + $tailSpacePos;
			if ( $absolutePos > 0 ) {
				$cut = mb_substr( $cut, 0, $absolutePos, 'UTF-8' );
			}
		}

		return trim( $cut );
	}

	/**
	 * Character-index (not byte-index) position of the last ASCII
	 * whitespace character in $text, or null if none is present. $text is
	 * assumed to already be valid UTF-8 (it is always mb_substr() output
	 * here) -- ASCII whitespace bytes never occur as a byte within a
	 * multi-byte UTF-8 sequence, so a plain byte-level search for them is
	 * safe, and only the final offset needs converting to a character
	 * count.
	 */
	private static function lastWhitespaceCharPos( string $text ): ?int {
		$bytePos = false;
		foreach ( [ ' ', "\t", "\n", "\r" ] as $whitespace ) {
			$candidate = strrpos( $text, $whitespace );
			if ( $candidate !== false && ( $bytePos === false || $candidate > $bytePos ) ) {
				$bytePos = $candidate;
			}
		}

		if ( $bytePos === false ) {
			return null;
		}

		return mb_strlen( substr( $text, 0, $bytePos ), 'UTF-8' );
	}

	/**
	 * Turns serialized block markup (`post_content` from an assembled
	 * artifact, proposed knowledge, or a review-queue row, e.g.
	 * `<!-- wp:heading --><h2>Storage Model</h2><!-- /wp:heading -->
	 * <!-- wp:paragraph --><p>Every memory row carries...</p>...`) into
	 * readable, whitespace-separated plain text.
	 *
	 * Single home for block->plain-text extraction (Follow-up wave, Unit G):
	 * previously `ReviewQueuePage::blockExcerpt()` had its own copy, and
	 * `ArtifactService::assemblySummary()` used a bare `wp_strip_all_tags()`
	 * per section that glued table cells together (live: "Results: Metric
	 * ValueRecall@591.1%Latency10.6 ms…"). Every caller (ReviewQueuePage,
	 * Diff, ArtifactService) now goes through this one method, so the fix
	 * only has to happen once.
	 *
	 * For flat (non-table) content this returns exactly what the previous
	 * `ReviewQueuePage::blockExcerpt()` returned: each block's own text
	 * (not its children's, which are walked separately via `innerBlocks`)
	 * joined with " · " and collapsed to single spaces.
	 *
	 * `core/table` is a special case, same as before: a table's rows and
	 * cells are NOT separate blocks -- the whole `<table>...</table>`
	 * markup is one block's `innerHTML`. Unlike the previous regex-based
	 * extraction (`/<t[dh][^>]*>(.*?)<\/t[dh]>/`), a table NESTED inside a
	 * cell no longer confuses the extraction: the regex's first `</td>`
	 * match closed on the *inner* table's first cell, gluing the outer
	 * cell's text onto it and silently dropping every cell that followed
	 * (reviewer probe: an inner 2x1 table nested in an outer 1x2 table's
	 * first cell produced "outer-cell-1inner-A inner-B" and lost
	 * "outer-cell-2" entirely). A real DOM parse (DOMDocument) fixes this:
	 * `<tr>` and `<td>`/`<th>` are found regardless of nesting depth, and
	 * each cell's own text excludes any table nested inside it (that
	 * nested table's cells are collected as their own rows), so nested
	 * tables never glue text together and no cell is ever lost.
	 */
	public static function plainTextFromBlocks( string $content ): string {
		if ( function_exists( 'parse_blocks' ) ) {
			$parts = self::blockTextParts( parse_blocks( $content ) );
			if ( $parts !== [] ) {
				return trim( (string) preg_replace( '/\s+/', ' ', implode( ' · ', $parts ) ) );
			}
		}

		// Fallback for contexts without the block parser (or plain-text
		// content with no blocks at all): insert a space after common
		// block-level closing tags -- including table cells/rows -- before
		// stripping tags, so adjacent elements don't get glued together.
		$spaced = (string) preg_replace( '/<\/(h[1-6]|p|li|div|blockquote|figcaption|td|th|tr)>/i', '</$1> ', $content );

		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $spaced ) ) );
	}

	/**
	 * Recursively collects each block's own rendered text (not its
	 * children's, which are walked separately via `innerBlocks`), so text
	 * from a heading and its following paragraph -- or a list and each of
	 * its list items -- end up as distinct parts to join with a separator.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return string[]
	 */
	private static function blockTextParts( array $blocks ): array {
		$parts = [];

		foreach ( $blocks as $block ) {
			$blockName = (string) ( $block['blockName'] ?? '' );
			$html      = (string) ( $block['innerHTML'] ?? '' );

			$text = $blockName === 'core/table'
				? self::tableBlockText( $html )
				: trim( wp_strip_all_tags( $html ) );

			if ( $text !== '' ) {
				$parts[] = $text;
			}

			$inner = $block['innerBlocks'] ?? [];
			if ( is_array( $inner ) && $inner !== [] ) {
				$parts = array_merge( $parts, self::blockTextParts( $inner ) );
			}
		}

		return $parts;
	}

	/**
	 * Plain text of a `core/table` block's markup, parsed with DOMDocument
	 * (not regex) so nested tables can't glue cells together or lose them.
	 * Cells are joined with a space, rows with " · " -- the same rendered
	 * shape the previous regex-based extraction produced for a flat table,
	 * so flat-table output is unchanged. Every `<tr>` in the markup is
	 * collected regardless of nesting depth (DOMDocument::getElementsByTagName
	 * walks the whole subtree), and each cell's own text explicitly excludes
	 * any `<table>` nested inside it, so a nested table's cells surface as
	 * their own separate row(s) instead of being glued onto -- or lost from
	 * -- the cell that contains them. Falls back to a plain tag-strip if the
	 * markup has no recognisable `<tr>` rows, or can't be parsed as HTML at
	 * all (defensive; DOMDocument is lenient and this should be rare).
	 */
	private static function tableBlockText( string $html ): string {
		if ( trim( $html ) === '' ) {
			return '';
		}

		if ( ! class_exists( \DOMDocument::class ) || stripos( $html, '<tr' ) === false ) {
			return trim( wp_strip_all_tags( $html ) );
		}

		$previousSetting = libxml_use_internal_errors( true );
		$doc             = new \DOMDocument();
		// The leading "<?xml encoding=" pseudo-instruction is the standard
		// way to tell DOMDocument::loadHTML() the input is UTF-8 -- without it,
		// loadHTML() assumes ISO-8859-1 and multi-byte (CJK, emoji, accented)
		// cell text comes back mangled. LIBXML_NOERROR/NOWARNING suppress
		// warnings for markup fragments (no full <html>/<body>), which
		// libxml handles fine but complains about; internal errors are
		// cleared below regardless of whether loadHTML() reports success.
		$loaded = $doc->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previousSetting );

		if ( ! $loaded ) {
			return trim( wp_strip_all_tags( $html ) );
		}

		$rows = [];
		foreach ( $doc->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = [];
			foreach ( $tr->childNodes as $cellNode ) {
				if ( ! $cellNode instanceof \DOMElement ) {
					continue;
				}

				$tag = strtolower( $cellNode->tagName );
				if ( $tag !== 'td' && $tag !== 'th' ) {
					continue;
				}

				$cellText = self::cellText( $cellNode );
				if ( $cellText !== '' ) {
					$cells[] = $cellText;
				}
			}

			if ( $cells !== [] ) {
				$rows[] = implode( ' ', $cells );
			}
		}

		if ( $rows === [] ) {
			return trim( wp_strip_all_tags( $html ) );
		}

		return implode( ' · ', $rows );
	}

	/**
	 * Element tag names whose entire subtree is never treated as visible
	 * text -- same rule `wp_strip_all_tags()` applies on the non-table path
	 * (it removes `<script>`/`<style>` wholesale, content included, rather
	 * than stripping just the tags and leaving the payload behind). Fix
	 * round 2 (reviewer-found MEDIUM): the DOM cell walk originally only
	 * special-cased `<table>`, so a cell containing
	 * `hello<script>alert(document.cookie)</script>world` leaked the script
	 * body straight into plain text ("helloalert(document.cookie)world"),
	 * and a `<style>` block leaked raw CSS the same way -- this feeds
	 * agent-visible memory (the artifact assembly summary), so it matters
	 * beyond cosmetics. `noscript` and `template` subtrees are excluded for
	 * the same reason: neither renders as ordinary page text.
	 */
	private const NON_TEXT_ELEMENTS = [ 'table', 'script', 'style', 'noscript', 'template' ];

	/**
	 * Plain text of a single `<td>`/`<th>` element, excluding any subtree
	 * listed in {@see self::NON_TEXT_ELEMENTS}. A nested `<table>`'s own
	 * `<tr>`s are already walked separately by
	 * {@see self::tableBlockText()}'s top-level loop
	 * (DOMDocument::getElementsByTagName() finds them at any depth), so
	 * including their text here too would duplicate it; leaving it out
	 * entirely (rather than gluing it onto the parent cell) is what keeps a
	 * nested table's cells intact as their own row instead of glued to or
	 * lost from the cell that contains them. `<script>`/`<style>`/etc. have
	 * no such separate collection -- they are simply never text.
	 */
	private static function cellText( \DOMElement $cell ): string {
		$text = '';
		foreach ( $cell->childNodes as $child ) {
			$text .= self::nodeTextExcludingTables( $child );
		}

		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	private static function nodeTextExcludingTables( \DOMNode $node ): string {
		if ( $node instanceof \DOMText ) {
			return $node->wholeText;
		}

		if ( $node instanceof \DOMElement && in_array( strtolower( $node->tagName ), self::NON_TEXT_ELEMENTS, true ) ) {
			// Skip: a nested <table>'s own cells are collected as their own
			// row(s) by the top-level <tr> walk, not glued in here; a
			// <script>/<style>/<noscript>/<template> subtree is simply never
			// visible text, same as wp_strip_all_tags() on the non-table path.
			return ' ';
		}

		if ( $node->hasChildNodes() ) {
			$text = '';
			foreach ( $node->childNodes as $child ) {
				$text .= self::nodeTextExcludingTables( $child );
			}

			return $text;
		}

		return '';
	}
}
