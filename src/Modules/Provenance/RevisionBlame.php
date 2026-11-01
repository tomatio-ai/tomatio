<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Core\ActionContext;
use AgentBrain\Core\Text;

/**
 * PROV-5 (#174): blame — per-revision attribution and block-level blame for
 * the versioned post types (ab_artifact_section, ab_knowledge, ab_procedure,
 * ab_handoff).
 *
 * Revision semantics (verified against WordPress 6.x in the integration
 * suite): a revision created by a content write holds the content AFTER
 * that write, and the newest revision duplicates the current text. So the
 * revision stream is a sequence of content states, one per content write,
 * each one stamped with the actor whose write produced it:
 *
 *   create (v1, no revision) -> write v2 (rev 1 holds v2) -> write v3 (rev 2 holds v3)
 *
 * Every revision of a tracked post type gets one blame entry ({actor_id,
 * via, client, ai, at, parent_version?}) stamped from the Core request
 * registries (ActionContext::actorContext() plus the current user) at
 * insert time — a single wp_insert_post hook covers EVERY write path
 * uniformly (MCP section-update, proposal edits, edit-and-approve,
 * wp-admin editors), exactly the "as memory rows do today" parallel from
 * the issue. Stamping stops when the action-context switch is off
 * (ActionContext::enabled(), the PROV-7 knob).
 *
 * Storage: one `ab_revision_blame` post meta row on the PARENT post, a JSON
 * object keyed by revision id. WordPress' post-meta API deliberately
 * redirects meta reads/writes on a revision to its parent ("make sure meta
 * is added to the post, not a revision"), so the per-revision entries live
 * on the parent keyed by revision id — read back per revision through
 * forRevision().
 *
 * The current (unrevisioned newest) state is produced by the actor stamped
 * on the NEWEST revision; a post that was never content-edited credits its
 * post_author. Two known limits, both inherent to what WordPress keeps:
 * the original v1 content is never stored as a revision, so a block that
 * survived every write credits the writer of the first revision that
 * contains it; and blocks are matched by normalized (block name, text), so
 * a block whose text was edited even slightly counts as new.
 */
final class RevisionBlame {

	/** Post types whose revisions carry blame stamps. */
	public const POST_TYPES = [
		'ab_artifact_section',
		'ab_knowledge',
		'ab_procedure',
		'ab_handoff',
	];

	/** Single JSON meta row on the PARENT post: revision id => blame entry. */
	public const META = 'ab_revision_blame';

	/**
	 * Cap on the plain-text excerpt returned per blamed block (the full
	 * block text can be unbounded; blame needs a reader-facing snippet only).
	 */
	private const BLOCK_TEXT_CAP = 300;

	/**
	 * Line cap per side of a state compare, so a pathological post cannot
	 * make the LCS quadratic blow up unbounded (same bound Versions uses).
	 */
	private const COMPARE_MAX_LINES = 200;

	// ------------------------------------------------------------------
	// Stamping
	// ------------------------------------------------------------------

	/**
	 * Hooked on wp_insert_post (runs for revisions too). Stamps a fresh
	 * blame entry for every revision of a tracked post type, skipping
	 * autosaves and revisions that already carry one. Read from the Core
	 * request registries, never from caller input; no-op when the
	 * action-context switch is off.
	 *
	 * @param mixed       $postId Post id (revision).
	 * @param \WP_Post    $post   The inserted post.
	 * @param mixed       $update Always false for revisions.
	 */
	public static function stampRevision( $postId, $post, $update = false ): void {
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'revision' ) {
			return;
		}

		if ( ! ActionContext::enabled() || ! function_exists( 'get_post' ) ) {
			return;
		}

		$revisionId = (int) $postId;
		if ( $revisionId <= 0 || self::isAutosaveName( (string) $post->post_name ) ) {
			return;
		}

		$parent = get_post( (int) $post->post_parent );
		if ( ! $parent instanceof \WP_Post || ! in_array( $parent->post_type, self::POST_TYPES, true ) ) {
			return;
		}

		$map = self::blameMap( (int) $parent->ID );

		// Already stamped (e.g. a re-insert); the first stamp stands.
		if ( isset( $map[ (string) $revisionId ] ) ) {
			return;
		}

		$context = ActionContext::actorContext();

		$stamp = [
			'actor_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'client'   => $context['client'],
			'via'      => $context['via'] > 0 ? $context['via'] : null,
			'ai'       => $context['ai'],
			'at'       => gmdate( 'Y-m-d H:i:s' ),
		];

		// Sections carry the ab_version counter: record which section
		// version this revision's content IS (the counter has already been
		// bumped by the write that created this revision).
		if ( $parent->post_type === 'ab_artifact_section' ) {
			$version = (int) get_post_meta( $parent->ID, 'ab_version', true );
			if ( $version > 0 ) {
				$stamp['parent_version'] = $version;
			}
		}

		$map[ (string) $revisionId ] = $stamp;

		// Same persistence pattern Stages uses for the action/review logs:
		// UNESCAPED_UNICODE keeps non-ASCII labels intact, wp_slash()
		// round-trips quotes through WordPress' unslash-on-save.
		update_post_meta( (int) $parent->ID, self::META, wp_slash( (string) wp_json_encode( $map, JSON_UNESCAPED_UNICODE ) ) );
	}

	/**
	 * The blame entries of one post: revision id => stamp (raw decoded
	 * values — readers normalize defensively, see forRevision()). Unparseable
	 * stored values read back as an empty map (never a fatal).
	 *
	 * @return array<string, mixed>
	 */
	public static function blameMap( int $postId ): array {
		$raw = get_post_meta( $postId, self::META, true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}

		$map = json_decode( $raw, true );

		return is_array( $map ) ? $map : [];
	}

	/**
	 * The blame stamp for one revision, or null when absent (a revision
	 * predating PROV-5, or capture switched off).
	 *
	 * @return array{actor_id:int, client:?string, via:?int, ai:bool, at:string, parent_version?:int}|null
	 */
	public static function forRevision( int $revisionId ): ?array {
		$revision = function_exists( 'get_post' ) ? get_post( $revisionId ) : null;
		if ( ! $revision instanceof \WP_Post || $revision->post_type !== 'revision' ) {
			return null;
		}

		$stamp = self::blameMap( (int) $revision->post_parent )[ (string) $revisionId ] ?? null;
		if ( ! is_array( $stamp ) ) {
			return null;
		}

		return [
			'actor_id'       => (int) ( $stamp['actor_id'] ?? 0 ),
			'client'         => isset( $stamp['client'] ) && is_string( $stamp['client'] ) ? $stamp['client'] : null,
			'via'            => isset( $stamp['via'] ) && (int) $stamp['via'] > 0 ? (int) $stamp['via'] : null,
			'ai'             => ! empty( $stamp['ai'] ),
			'at'             => (string) ( $stamp['at'] ?? '' ),
			'parent_version' => isset( $stamp['parent_version'] ) && (int) $stamp['parent_version'] > 0 ? (int) $stamp['parent_version'] : null,
		];
	}

	// ------------------------------------------------------------------
	// States (the versioned content stream)
	// ------------------------------------------------------------------

	/**
	 * The content states of a tracked post, oldest first: every
	 * non-autosave revision (each holding the content after one write) and,
	 * last, the current state. Each state carries its blame attribution:
	 *
	 *  - revision state: the stamp on the revision — the actor whose write
	 *    produced exactly this content;
	 *  - current state: the stamp of the NEWEST revision (the last content
	 *    write), or the post author when the post was never edited.
	 *
	 * `version` is the section's ab_version for section states (null for
	 * other post types), `ordinal` is the 1-based position in this list.
	 *
	 * @return array<int, array{ordinal:int, revision_id:?int, at:string, version:?int, content:string, actor:array{actor_id:int, client:?string, via:?int, ai:bool, at:?string, revision_id:?int}}>
	 */
	public static function states( int $postId ): array {
		$post = function_exists( 'get_post' ) ? get_post( $postId ) : null;
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return [];
		}

		$states    = [];
		$lastStamp = null;

		if ( function_exists( 'wp_get_post_revisions' ) ) {
			foreach ( wp_get_post_revisions( $postId, [ 'order' => 'ASC' ] ) as $revision ) {
				if ( ! $revision instanceof \WP_Post || self::isAutosaveName( (string) $revision->post_name ) ) {
					continue;
				}

				$stamp     = self::forRevision( (int) $revision->ID );
				$lastStamp = $stamp ?? $lastStamp;

				$states[] = [
					'ordinal'      => 0, // assigned below.
					'revision_id'  => (int) $revision->ID,
					'at'           => (string) $revision->post_date_gmt,
					'version'      => $stamp !== null ? $stamp['parent_version'] : self::stateVersionFallback( $post ),
					'content'      => (string) $revision->post_content,
					'actor'        => self::attribution( $stamp, (int) $revision->ID ),
				];
			}
		}

		// The current state: produced by the last content write (the newest
		// revision's stamp). A post with no stamped revisions at all (never
		// edited, or capture switched off since birth) credits the author.
		if ( $lastStamp === null ) {
			$lastStamp = [ 'actor_id' => (int) $post->post_author, 'client' => null, 'via' => null, 'ai' => false, 'at' => (string) $post->post_date_gmt ];
		}

		$states[] = [
			'ordinal'     => 0,
			'revision_id' => null,
			'at'          => (string) $post->post_modified_gmt,
			'version'     => self::stateVersionFallback( $post ),
			'content'     => (string) $post->post_content,
			'actor'       => self::attribution( $lastStamp, null ),
		];

		foreach ( $states as $i => $state ) {
			$states[ $i ]['ordinal'] = $i + 1;
		}

		return $states;
	}

	/**
	 * @param array{actor_id:int, client:?string, via:?int, ai:bool, at:string, parent_version?:int}|null $stamp
	 * @return array{actor_id:int, client:?string, via:?int, ai:bool, at:?string, revision_id:?int}
	 */
	private static function attribution( ?array $stamp, ?int $revisionId ): array {
		if ( $stamp !== null ) {
			return [
				'actor_id'    => $stamp['actor_id'],
				'client'      => $stamp['client'],
				'via'         => $stamp['via'],
				'ai'          => $stamp['ai'],
				'at'          => $stamp['at'] !== '' ? $stamp['at'] : null,
				'revision_id' => $revisionId,
			];
		}

		return [
			'actor_id'    => 0,
			'client'      => null,
			'via'         => null,
			'ai'          => false,
			'at'          => null,
			'revision_id' => null,
		];
	}

	private static function stateVersionFallback( \WP_Post $post ): ?int {
		if ( $post->post_type !== 'ab_artifact_section' ) {
			return null;
		}

		$version = (int) get_post_meta( $post->ID, 'ab_version', true );

		return $version > 0 ? $version : null;
	}

	private static function isAutosaveName( string $postName ): bool {
		return str_contains( $postName, '-autosave-' );
	}

	// ------------------------------------------------------------------
	// Block-level blame
	// ------------------------------------------------------------------

	/**
	 * Block-level blame of a post's CURRENT content. Each top-level block
	 * is matched against every earlier state (oldest first); the credited
	 * author is the producer of the oldest state whose content contains an
	 * equal block — i.e. each block's author is carried forward until the
	 * block's text changes, at which point the writer of the state where
	 * the new text first appears takes over. A block that matches no earlier
	 * state is credited to the current state's actor.
	 *
	 * @return array{post_id:int, post_type:string, version:?int, blocks:array<int, array{index:int, block:string, text:string, author:array{actor_id:int, client:?string, via:?int, ai:bool, at:?string, version:?int, revision_id:?int}}>}
	 */
	public static function blame( int $postId ): array {
		$post = function_exists( 'get_post' ) ? get_post( $postId ) : null;
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return [ 'post_id' => $postId, 'post_type' => '', 'version' => null, 'blocks' => [] ];
		}

		$states = self::states( $postId );
		$current = end( $states );

		$currentBlocks = self::normalizedBlocks( (string) $post->post_content );
		$stateSets     = [];
		foreach ( $states as $state ) {
			$stateSets[] = [
				'state'  => (string) $state['ordinal'],
				'blocks' => self::normalizedBlocks( (string) $state['content'] ),
			];
		}

		$credited = self::attributeBlocks( $currentBlocks, $stateSets );

		$byOrdinal = [];
		foreach ( $states as $state ) {
			$byOrdinal[ (string) $state['ordinal'] ] = $state;
		}

		$blocks = [];
		foreach ( $currentBlocks as $i => $block ) {
			$state  = $byOrdinal[ $credited[ $i ] ] ?? $current;
			$author = $state['actor'];
			$author['version'] = $state['version'];

			$blocks[] = [
				'index'  => $i,
				'block'  => $block['name'],
				'text'   => self::excerpt( $block['text'] ),
				'author' => $author,
			];
		}

		return [
			'post_id'   => $postId,
			'post_type' => (string) $post->post_type,
			'version'   => $current !== false ? $current['version'] : null,
			'blocks'    => $blocks,
		];
	}

	/**
	 * The pure matching core of blame: given the current top-level blocks
	 * and every content state (oldest first, CURRENT STATE LAST), credit
	 * each current block to the oldest state containing an equal block —
	 * carried forward until it changes. A block matching nothing earlier is
	 * credited to the last state in the list. Static and WordPress-free so
	 * the algorithm is unit-testable without a database.
	 *
	 * @param array<int, array{name:string, text:string}> $currentBlocks
	 * @param array<int, array{state:string, blocks:array<int, array{name:string, text:string}>}> $states
	 * @return array<int, string> credited state key per current block index.
	 */
	public static function attributeBlocks( array $currentBlocks, array $states ): array {
		$out          = [];
		$currentKey   = $states !== [] ? (string) $states[ count( $states ) - 1 ]['state'] : '0';
		$blockIndexes = [];

		foreach ( $states as $state ) {
			$blockIndexes[ (string) $state['state'] ] = self::blockIndex( $state['blocks'] );
		}

		foreach ( $currentBlocks as $i => $block ) {
			$key = self::identity( $block );
			if ( $key === '' ) {
				// Unparseable / empty block: no meaningful attribution.
				$out[ $i ] = $currentKey;
				continue;
			}

			$credited = $currentKey;
			foreach ( $blockIndexes as $stateKey => $index ) {
				if ( isset( $index[ $key ] ) ) {
					// (string): PHP turns the numeric string state keys into
					// ints when they index the array — pin the contract.
					$credited = (string) $stateKey;
					break;
				}
			}

			$out[ $i ] = (string) $credited;
		}

		return $out;
	}

	/**
	 * @param array<int, array{name:string, text:string}> $blocks
	 * @return array<string, true>
	 */
	private static function blockIndex( array $blocks ): array {
		$index = [];
		foreach ( $blocks as $block ) {
			$key = self::identity( $block );
			if ( $key !== '' ) {
				$index[ $key ] = true;
			}
		}

		return $index;
	}

	/**
	 * A block's identity: its name plus whitespace-normalized text. Empty
	 * text (spacer-like blocks) has no identity and is never matched.
	 *
	 * @param array{name:string, text:string} $block
	 */
	private static function identity( array $block ): string {
		$name = trim( $block['name'] );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $block['text'] ) );

		if ( $text === '' ) {
			return '';
		}

		return $name . "\0" . $text;
	}

	/**
	 * Top-level blocks of a post content string as (blockName, plain text)
	 * pairs. parse_blocks is always present where this runs (WordPress
	 * loaded); Text::plainTextFromBlocks supplies the same normalization
	 * the rest of the plugin uses, applied per block via its inner markup.
	 *
	 * @return array<int, array{name:string, text:string}>
	 */
	private static function normalizedBlocks( string $content ): array {
		if ( ! function_exists( 'parse_blocks' ) || trim( $content ) === '' ) {
			return [];
		}

		$out = [];
		foreach ( parse_blocks( $content ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$out[] = [
				'name' => (string) ( $block['blockName'] ?? '' ),
				'text' => Text::plainTextFromBlocks( self::blockMarkup( $block ) ),
			];
		}

		return $out;
	}

	/**
	 * Render one parsed block (and its inner blocks) back to markup so
	 * Text::plainTextFromBlocks sees exactly this block's text.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function blockMarkup( array $block ): string {
		if ( function_exists( 'serialize_block' ) ) {
			return serialize_block( $block );
		}

		return (string) ( $block['innerHTML'] ?? '' );
	}

	private static function excerpt( string $text ): string {
		$text = trim( $text );
		if ( mb_strlen( $text, 'UTF-8' ) <= self::BLOCK_TEXT_CAP ) {
			return $text;
		}

		return mb_substr( $text, 0, self::BLOCK_TEXT_CAP, 'UTF-8' ) . '…';
	}

	// ------------------------------------------------------------------
	// Compare two states
	// ------------------------------------------------------------------

	/**
	 * A line-based diff of two states' plain text (for the wp-admin "diff
	 * between any two versions"). Entries: {type: same|added|removed, line}.
	 *
	 * @return array{from:int, to:int, identical:bool, truncated:bool, lines:array<int, array{type:string, line:string}>}|null
	 */
	public static function compareStates( int $postId, int $fromOrdinal, int $toOrdinal ): ?array {
		$states = self::states( $postId );

		$from = self::stateByOrdinal( $states, $fromOrdinal );
		$to   = self::stateByOrdinal( $states, $toOrdinal );
		if ( $from === null || $to === null ) {
			return null;
		}

		$fromLines = self::diffableLines( (string) $from['content'] );
		$toLines   = self::diffableLines( (string) $to['content'] );

		return [
			'from'      => $fromOrdinal,
			'to'        => $toOrdinal,
			'identical' => $fromLines === $toLines,
			'truncated' => count( $fromLines ) >= self::COMPARE_MAX_LINES || count( $toLines ) >= self::COMPARE_MAX_LINES,
			'lines'     => self::lineDiff( $fromLines, $toLines ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $states
	 * @return array<string, mixed>|null
	 */
	private static function stateByOrdinal( array $states, int $ordinal ): ?array {
		foreach ( $states as $state ) {
			if ( (int) $state['ordinal'] === $ordinal ) {
				return $state;
			}
		}

		return null;
	}

	/** @return string[] */
	private static function diffableLines( string $content ): array {
		$text = trim( Text::plainTextFromBlocks( $content ) );
		if ( $text === '' ) {
			return [];
		}

		$lines = preg_split( '/\n+/', $text ) ?: [];

		return array_slice( array_map( 'trim', $lines ), 0, self::COMPARE_MAX_LINES );
	}

	/**
	 * Line-based LCS diff, the same bounded DP Versions::lineDiff() uses.
	 *
	 * @param string[] $fromLines
	 * @param string[] $toLines
	 * @return array<int, array{type:string, line:string}>
	 */
	private static function lineDiff( array $fromLines, array $toLines ): array {
		$n = count( $fromLines );
		$m = count( $toLines );

		$dp = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$dp[ $i ][ $j ] = $fromLines[ $i ] === $toLines[ $j ]
					? $dp[ $i + 1 ][ $j + 1 ] + 1
					: max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
			}
		}

		$out = [];
		$i   = 0;
		$j   = 0;
		while ( $i < $n && $j < $m ) {
			if ( $fromLines[ $i ] === $toLines[ $j ] ) {
				$out[] = [ 'type' => 'same', 'line' => $fromLines[ $i ] ];
				$i++;
				$j++;
			} elseif ( $dp[ $i + 1 ][ $j ] >= $dp[ $i ][ $j + 1 ] ) {
				$out[] = [ 'type' => 'removed', 'line' => $fromLines[ $i ] ];
				$i++;
			} else {
				$out[] = [ 'type' => 'added', 'line' => $toLines[ $j ] ];
				$j++;
			}
		}
		while ( $i < $n ) {
			$out[] = [ 'type' => 'removed', 'line' => $fromLines[ $i ] ];
			$i++;
		}
		while ( $j < $m ) {
			$out[] = [ 'type' => 'added', 'line' => $toLines[ $j ] ];
			$j++;
		}

		return $out;
	}
}
