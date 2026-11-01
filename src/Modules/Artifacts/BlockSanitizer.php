<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

/**
 * ART-5: turns agent-submitted JSON blocks into serialized, sanitized
 * section markup that matches what WordPress core itself would save for
 * these static blocks — the block editor and `do_blocks()` render both
 * expect the real saved shape (e.g. `<h2 class="wp-block-heading">`,
 * `<p>`, `<ul class="wp-block-list"><li>…</li></ul>`), not a bare
 * "content" attribute stuffed into the block comment.
 *
 * Input shape (per contract): array<{name: string, attributes?: array,
 * innerHTML?: string, innerBlocks?: array}>. Every block name (recursively,
 * including nested innerBlocks) must be in the section's ab_allowed_blocks
 * list AND be a block actually registered in core's block type registry;
 * the first violation aborts with a precise WP_Error naming the offending
 * block.
 *
 * Per block, only the attributes core actually persists in the block
 * comment are written to `attrs` (e.g. heading's non-default `level`, or
 * list's `ordered`) — an editor-only/derived value such as `content` is
 * NEVER written there; it is rendered into `innerHTML`/`innerContent`
 * instead, matching how core's own save() functions work (attributes
 * sourced from markup, such as RichText `content`, are never re-serialized
 * into the JSON comment). Every text value that comes from an agent's plain
 * `attributes.content` field is escaped with esc_html() exactly once before
 * it is embedded; a value supplied via `innerHTML` is treated as already
 * being markup. Either way the final serialized output always passes
 * through wp_kses_post() as a last line of defense, so no unsanitized HTML
 * (e.g. <script>) ever reaches post_content.
 */
class BlockSanitizer {

	private const HEADING_DEFAULT_LEVEL = 2;

	/**
	 * Status badge states (`core/paragraph` with `attributes.status`) and the
	 * default label each one shows when the block carries no text of its own.
	 *
	 * @var array<string, string>
	 */
	public const STATUS_LABELS = [
		'done'        => 'Done',
		'in-progress' => 'In progress',
		'blocked'     => 'Blocked',
		'planned'     => 'Planned',
		'decision'    => 'Decision',
	];

	/**
	 * Callout kinds (`core/group` with `attributes.callout`).
	 *
	 * @var string[]
	 */
	public const CALLOUT_KINDS = [ 'note', 'info', 'success', 'warning', 'decision' ];

	/**
	 * Per-block keys accepted from an agent; anything else is
	 * `ab_invalid_block` (fail loud rather than silently ignoring a
	 * mistyped/unsupported key, which is how the "saved empty" bug shipped).
	 *
	 * @var string[]
	 */
	private const ALLOWED_BLOCK_KEYS = [ 'name', 'attributes', 'content', 'text', 'innerHTML', 'innerContent', 'innerBlocks' ];

	/**
	 * Block names whose whole point is to carry text: an empty normalised
	 * text value on one of these is never saved silently (ab_empty_block).
	 *
	 * @var string[]
	 */
	private const TEXT_BEARING_BLOCKS = [
		'core/heading',
		'core/paragraph',
		'core/list-item',
		'core/quote',
		'core/code',
		'core/preformatted',
	];

	/**
	 * @param array<int, array<string, mixed>> $blocksJson
	 * @param string[] $allowedBlocks
	 * @return string|\WP_Error Sanitized serialized block markup, or a WP_Error.
	 */
	public static function build( array $blocksJson, array $allowedBlocks ) {
		if ( $blocksJson === [] ) {
			return '';
		}

		$validation = self::validate( $blocksJson, $allowedBlocks, 'blocks' );
		if ( $validation instanceof \WP_Error ) {
			return $validation;
		}

		$blocks     = self::toBlockArrays( $blocksJson );
		$serialized = serialize_blocks( $blocks );

		return wp_kses_post( $serialized );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocksJson
	 * @param string[] $allowedBlocks
	 */
	private static function validate( array $blocksJson, array $allowedBlocks, string $pathPrefix ): ?\WP_Error {
		$registry = \WP_Block_Type_Registry::get_instance();

		foreach ( $blocksJson as $index => $block ) {
			$path = $pathPrefix . '[' . $index . ']';

			if ( ! is_array( $block ) || ! isset( $block['name'] ) || ! is_string( $block['name'] ) || $block['name'] === '' ) {
				return new \WP_Error(
					'ab_invalid_block',
					sprintf(
						/* translators: %s: block path, e.g. blocks[2].innerBlocks[1] */
						__( 'Each block must have a string "name" (%s).', 'agent-brain' ),
						$path
					),
					[ 'status' => 422, 'block_path' => $path ]
				);
			}

			$unknownKeys = array_diff( array_keys( $block ), self::ALLOWED_BLOCK_KEYS );
			if ( $unknownKeys !== [] ) {
				return new \WP_Error(
					'ab_invalid_block',
					sprintf(
						/* translators: 1: block path, 2: comma-separated unknown key list */
						__( 'Block %1$s has unknown key(s): %2$s.', 'agent-brain' ),
						$path,
						implode( ', ', $unknownKeys )
					),
					[ 'status' => 422, 'block_path' => $path ]
				);
			}

			$name = $block['name'];

			if ( ! in_array( $name, $allowedBlocks, true ) ) {
				return new \WP_Error(
					'ab_disallowed_block',
					sprintf(
						/* translators: %s: block name */
						__( 'Block "%s" is not allowed in this section.', 'agent-brain' ),
						$name
					),
					[ 'status' => 422, 'block' => $name, 'block_path' => $path ]
				);
			}

			if ( ! $registry->is_registered( $name ) ) {
				return new \WP_Error(
					'ab_unknown_block',
					sprintf(
						/* translators: %s: block name */
						__( 'Block "%s" is not a registered block.', 'agent-brain' ),
						$name
					),
					[ 'status' => 422, 'block' => $name, 'block_path' => $path ]
				);
			}

			$styleError = self::validateStyleAttribute( $block, $name, $path );
			if ( $styleError instanceof \WP_Error ) {
				return $styleError;
			}

			if ( $name === 'core/table' ) {
				$tableError = self::validateTable( $block, $path );
				if ( $tableError instanceof \WP_Error ) {
					return $tableError;
				}
			}

			if ( isset( $block['innerBlocks'] ) && ! is_array( $block['innerBlocks'] ) ) {
				return new \WP_Error(
					'ab_invalid_block',
					sprintf(
						/* translators: %s: block path */
						__( 'innerBlocks must be an array (%s).', 'agent-brain' ),
						$path
					),
					[ 'status' => 422, 'block_path' => $path ]
				);
			}

			$innerBlocksRaw = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			// A badge may be label-less (the state's default label is used).
			$isBadge = $name === 'core/paragraph' && self::statusOf( $block ) !== null;

			// Fail loud on silent data loss: a text-bearing block whose
			// normalised text is empty (across every accepted input shape)
			// is never saved as an empty node with a version bump.
			// Containers that only wrap children (core/list) or a leaf that
			// legitimately holds only nested blocks (e.g. a quote made of
			// child paragraphs) are exempt — the check only applies where
			// this block itself is the leaf carrying the text.
			if ( ! $isBadge && in_array( $name, self::TEXT_BEARING_BLOCKS, true ) && $innerBlocksRaw === [] ) {
				if ( self::normalizedText( $block, $name ) === '' ) {
					return new \WP_Error(
						'ab_empty_block',
						sprintf(
							/* translators: %s: block path */
							__( 'Block %s has no text after normalisation; nothing was saved.', 'agent-brain' ),
							$path
						),
						[ 'status' => 422, 'block_path' => $path ]
					);
				}
			}

			if ( $innerBlocksRaw !== [] ) {
				$nested = self::validate( $innerBlocksRaw, $allowedBlocks, $path . '.innerBlocks' );
				if ( $nested instanceof \WP_Error ) {
					return $nested;
				}
			}
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocksJson
	 * @return array<int, array<string, mixed>>
	 */
	private static function toBlockArrays( array $blocksJson ): array {
		$out = [];

		foreach ( $blocksJson as $block ) {
			$out[] = self::buildBlock( $block );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $rawBlock
	 * @return array<string, mixed>
	 */
	private static function buildBlock( array $rawBlock ): array {
		$name           = (string) $rawBlock['name'];
		$innerBlocksRaw = is_array( $rawBlock['innerBlocks'] ?? null ) ? $rawBlock['innerBlocks'] : [];
		$builtChildren  = array_map( [ self::class, 'buildBlock' ], $innerBlocksRaw );

		switch ( $name ) {
			case 'core/paragraph':
				$status = self::statusOf( $rawBlock );
				if ( $status !== null ) {
					return self::buildStatusBadge( $rawBlock, $status );
				}

				return self::leaf( 'core/paragraph', [], '<p>' . self::content( $rawBlock, [ 'p' ] ) . '</p>' );

			case 'core/group':
				return self::buildGroup( $rawBlock, $builtChildren );

			case 'core/columns':
				return self::wrap( 'core/columns', [], '<div class="wp-block-columns">', $builtChildren, '</div>' );

			case 'core/column':
				return self::wrap( 'core/column', [], '<div class="wp-block-column">', $builtChildren, '</div>' );

			case 'core/heading':
				return self::buildHeading( $rawBlock );

			case 'core/list':
				return self::buildList( $rawBlock, $builtChildren );

			case 'core/list-item':
				return self::buildListItem( $rawBlock, $builtChildren );

			case 'core/quote':
				return self::buildQuote( $rawBlock, $builtChildren );

			case 'core/code':
				return self::leaf(
					'core/code',
					[],
					'<pre class="wp-block-code"><code>' . self::content( $rawBlock, [ 'code' ] ) . '</code></pre>'
				);

			case 'core/table':
				return self::buildTable( $rawBlock );

			case 'core/image':
				return self::buildImage( $rawBlock );

			default:
				// Not one of the static blocks this builder knows the real
				// saved shape for. Fall back to a bare content-only render so
				// a section allowlist that names an extra block doesn't hard
				// fail — attrs still never carry the derived 'content' key.
				$attrs = is_array( $rawBlock['attributes'] ?? null ) ? $rawBlock['attributes'] : [];
				unset( $attrs['content'] );

				return [
					'blockName'    => $name,
					'attrs'        => $attrs,
					'innerBlocks'  => $builtChildren,
					'innerHTML'    => self::content( $rawBlock ),
					'innerContent' => self::content( $rawBlock ) === '' ? [] : [ self::content( $rawBlock ) ],
				];
		}
	}

	/**
	 * `attributes.status` / `attributes.callout` must be one of the fixed
	 * values; a mistyped one is rejected rather than silently dropped.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function validateStyleAttribute( array $block, string $name, string $path ): ?\WP_Error {
		$attrs = is_array( $block['attributes'] ?? null ) ? $block['attributes'] : [];

		if ( $name === 'core/paragraph' && array_key_exists( 'status', $attrs ) && self::statusOf( $block ) === null ) {
			return new \WP_Error(
				'ab_invalid_block',
				sprintf(
					/* translators: 1: block path, 2: comma-separated allowed states */
					__( 'Block %1$s: attributes.status must be one of: %2$s.', 'agent-brain' ),
					$path,
					implode( ', ', array_keys( self::STATUS_LABELS ) )
				),
				[ 'status' => 422, 'block_path' => $path ]
			);
		}

		if ( $name === 'core/group' && array_key_exists( 'callout', $attrs ) && ( ! is_string( $attrs['callout'] ) || ! in_array( $attrs['callout'], self::CALLOUT_KINDS, true ) ) ) {
			return new \WP_Error(
				'ab_invalid_block',
				sprintf(
					/* translators: 1: block path, 2: comma-separated allowed kinds */
					__( 'Block %1$s: attributes.callout must be one of: %2$s.', 'agent-brain' ),
					$path,
					implode( ', ', self::CALLOUT_KINDS )
				),
				[ 'status' => 422, 'block_path' => $path ]
			);
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $rawBlock
	 */
	private static function statusOf( array $rawBlock ): ?string {
		$attrs  = is_array( $rawBlock['attributes'] ?? null ) ? $rawBlock['attributes'] : [];
		$status = $attrs['status'] ?? null;

		return is_string( $status ) && isset( self::STATUS_LABELS[ $status ] ) ? $status : null;
	}

	/**
	 * A status label: a paragraph carrying the `ab-status` classes; the text
	 * defaults to the state's label.
	 *
	 * @param array<string, mixed> $rawBlock
	 * @return array<string, mixed>
	 */
	private static function buildStatusBadge( array $rawBlock, string $status ): array {
		$text  = self::content( $rawBlock, [ 'p' ] );
		$class = 'ab-status ab-status--' . $status;

		return self::leaf(
			'core/paragraph',
			[ 'className' => $class ],
			'<p class="' . $class . '">' . ( $text !== '' ? $text : esc_html( self::STATUS_LABELS[ $status ] ) ) . '</p>'
		);
	}

	/**
	 * A group; with `attributes.callout` it is a callout box.
	 *
	 * @param array<string, mixed> $rawBlock
	 * @param array<int, array<string, mixed>> $builtChildren
	 * @return array<string, mixed>
	 */
	private static function buildGroup( array $rawBlock, array $builtChildren ): array {
		$attrs   = is_array( $rawBlock['attributes'] ?? null ) ? $rawBlock['attributes'] : [];
		$callout = $attrs['callout'] ?? null;
		$class   = 'wp-block-group';
		$comment = [];

		if ( is_string( $callout ) && in_array( $callout, self::CALLOUT_KINDS, true ) ) {
			$extra                = 'ab-callout ab-callout--' . $callout;
			$class               .= ' ' . $extra;
			$comment['className'] = $extra;
		}

		return self::wrap(
			'core/group',
			$comment,
			'<div class="' . $class . '"><div class="wp-block-group__inner-container">',
			$builtChildren,
			'</div></div>'
		);
	}

	/**
	 * Normalises a block's text from every input shape a caller may have
	 * sent, in the contract's binding order — CRITICAL fix for the silent
	 * data-loss bug (section-update previously kept text only from
	 * `attributes.content`, so `attributes.text`, top-level `content`/`text`
	 * and `innerContent` were saved EMPTY with success + a version bump):
	 *
	 *   1. attributes.content   (plain text, escaped once)
	 *   2. attributes.text      (plain text, escaped once)
	 *   3. top-level content    (plain text, escaped once)
	 *   4. top-level text       (plain text, escaped once)
	 *   5. innerHTML            (already markup; unwrapped if it is wrapped
	 *                            in the block's own tag, e.g. <p>…</p>, so
	 *                            re-wrapping below never double-wraps, e.g.
	 *                            <p><p>…</p></p>)
	 *   6. innerContent         (string parts joined; same unwrap rule)
	 *
	 * Either way the final serialized output still passes through
	 * wp_kses_post() in build() as a last line of defense.
	 *
	 * @param array<string, mixed> $rawBlock
	 * @param string[] $wrapperTags Tag name(s) this block's own markup would
	 *   wrap text in (e.g. ['p'], ['h2']) — stripped from innerHTML/innerContent
	 *   before the value is re-wrapped by the caller, so it is never double-wrapped.
	 */
	private static function content( array $rawBlock, array $wrapperTags = [] ): string {
		$attrs = is_array( $rawBlock['attributes'] ?? null ) ? $rawBlock['attributes'] : [];

		foreach ( [ [ $attrs, 'content' ], [ $attrs, 'text' ], [ $rawBlock, 'content' ], [ $rawBlock, 'text' ] ] as [ $source, $key ] ) {
			if ( isset( $source[ $key ] ) && is_string( $source[ $key ] ) && $source[ $key ] !== '' ) {
				return esc_html( $source[ $key ] );
			}
		}

		if ( isset( $rawBlock['innerHTML'] ) && is_string( $rawBlock['innerHTML'] ) && trim( $rawBlock['innerHTML'] ) !== '' ) {
			return self::unwrap( $rawBlock['innerHTML'], $wrapperTags );
		}

		if ( isset( $rawBlock['innerContent'] ) && is_array( $rawBlock['innerContent'] ) ) {
			$joined = implode( '', array_filter( $rawBlock['innerContent'], 'is_string' ) );
			if ( trim( $joined ) !== '' ) {
				return self::unwrap( $joined, $wrapperTags );
			}
		}

		return '';
	}

	/**
	 * The same normalisation content() applies, used by validate() to decide
	 * whether a text-bearing block's text is empty after every input shape
	 * has been tried — never guess from a single field.
	 *
	 * @param array<string, mixed> $rawBlock
	 */
	private static function normalizedText( array $rawBlock, string $name ): string {
		return trim( wp_strip_all_tags( self::content( $rawBlock, self::wrapperTagsFor( $rawBlock, $name ) ) ) );
	}

	/**
	 * @param array<string, mixed> $rawBlock
	 * @return string[]
	 */
	private static function wrapperTagsFor( array $rawBlock, string $name ): array {
		switch ( $name ) {
			case 'core/paragraph':
				return [ 'p' ];
			case 'core/heading':
				$attrs = is_array( $rawBlock['attributes'] ?? null ) ? $rawBlock['attributes'] : [];
				$level = isset( $attrs['level'] ) && is_int( $attrs['level'] ) ? max( 1, min( 6, $attrs['level'] ) ) : self::HEADING_DEFAULT_LEVEL;

				return [ 'h' . $level ];
			case 'core/list-item':
				return [ 'li' ];
			case 'core/quote':
				return [ 'p' ];
			case 'core/code':
				return [ 'code' ];
			default:
				return [];
		}
	}

	/**
	 * Strips a single matching outer `<tag ...>…</tag>` wrapper from $html,
	 * if present — so an agent-supplied `innerHTML`/`innerContent` that
	 * already carries the block's own wrapper (e.g. `<p>hello</p>` for a
	 * paragraph) is unwrapped before this class re-wraps it, rather than
	 * producing `<p><p>hello</p></p>`. Leaves $html untouched if it doesn't
	 * match any given wrapper tag (e.g. inline markup with no outer tag).
	 *
	 * @param string[] $wrapperTags
	 */
	private static function unwrap( string $html, array $wrapperTags ): string {
		$html = trim( $html );

		foreach ( $wrapperTags as $tag ) {
			if ( preg_match( '/^<' . preg_quote( $tag, '/' ) . '\b[^>]*>(.*)<\/' . preg_quote( $tag, '/' ) . '>$/is', $html, $matches ) === 1 ) {
				return $matches[1];
			}
		}

		return $html;
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private static function leaf( string $name, array $attrs, string $html ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * A block that wraps zero or more child blocks between an opening and
	 * closing literal HTML chunk (e.g. <ul>…children…</ul>), mirroring how
	 * parse_blocks()/serialize_block() represent nesting: innerContent holds
	 * the literal chunks with a null placeholder per child, and
	 * serialize_block() fills each null by recursively serializing the
	 * matching innerBlocks entry.
	 *
	 * @param array<int, array<string, mixed>> $builtChildren
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private static function wrap( string $name, array $attrs, string $open, array $builtChildren, string $close ): array {
		$innerContent = [ $open ];
		foreach ( $builtChildren as $ignored ) {
			$innerContent[] = null;
		}
		$innerContent[] = $close;

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $builtChildren,
			'innerHTML'    => $open . $close,
			'innerContent' => $innerContent,
		];
	}

	/**
	 * @param array<string, mixed> $rawBlock
	 * @return array<string, mixed>
	 */
	private static function buildHeading( array $rawBlock ): array {
		$attrs = is_array( $rawBlock['attributes'] ?? null ) ? $rawBlock['attributes'] : [];
		$level = isset( $attrs['level'] ) && is_int( $attrs['level'] ) ? $attrs['level'] : self::HEADING_DEFAULT_LEVEL;
		$level = max( 1, min( 6, $level ) );

		$commentAttrs = $level !== self::HEADING_DEFAULT_LEVEL ? [ 'level' => $level ] : [];
		$tag          = 'h' . $level;

		return self::leaf(
			'core/heading',
			$commentAttrs,
			'<' . $tag . ' class="wp-block-heading">' . self::content( $rawBlock, [ $tag ] ) . '</' . $tag . '>'
		);
	}

	/**
	 * @param array<string, mixed> $rawBlock
	 * @param array<int, array<string, mixed>> $builtChildren
	 * @return array<string, mixed>
	 */
	private static function buildList( array $rawBlock, array $builtChildren ): array {
		$attrs   = is_array( $rawBlock['attributes'] ?? null ) ? $rawBlock['attributes'] : [];
		$ordered = ! empty( $attrs['ordered'] );
		$tag     = $ordered ? 'ol' : 'ul';

		$commentAttrs = $ordered ? [ 'ordered' => true ] : [];

		return self::wrap(
			'core/list',
			$commentAttrs,
			'<' . $tag . ' class="wp-block-list">',
			$builtChildren,
			'</' . $tag . '>'
		);
	}

	/**
	 * @param array<string, mixed> $rawBlock
	 * @param array<int, array<string, mixed>> $builtChildren
	 * @return array<string, mixed>
	 */
	private static function buildListItem( array $rawBlock, array $builtChildren ): array {
		$text = self::content( $rawBlock, [ 'li' ] );

		if ( $builtChildren === [] ) {
			return self::leaf( 'core/list-item', [], '<li>' . $text . '</li>' );
		}

		// A list item that itself contains a nested list: the nested block's
		// markup is appended inside the <li> before the closing tag, exactly
		// as core's own list-item save() does.
		return self::wrap( 'core/list-item', [], '<li>' . $text, $builtChildren, '</li>' );
	}

	/**
	 * @param array<string, mixed> $rawBlock
	 * @param array<int, array<string, mixed>> $builtChildren
	 * @return array<string, mixed>
	 */
	private static function buildQuote( array $rawBlock, array $builtChildren ): array {
		if ( $builtChildren !== [] ) {
			return self::wrap(
				'core/quote',
				[],
				'<blockquote class="wp-block-quote">',
				$builtChildren,
				'</blockquote>'
			);
		}

		$text = self::content( $rawBlock, [ 'p' ] );

		return self::leaf(
			'core/quote',
			[],
			'<blockquote class="wp-block-quote"><p>' . $text . '</p></blockquote>'
		);
	}

	/**
	 * A5: `attributes.head`/`body`/`foot` each hold a list of rows; each row
	 * is EITHER `{cells:[{content, tag?}]}`, an array of plain strings, or an
	 * array of `{content}` objects (normalizeTableRow() below accepts all
	 * three — validateTable() already confirmed every row parses). Cell tags:
	 * head cells are always rendered `<th>`; body/foot cells are `<td>`
	 * unless the cell explicitly set `tag: "th"`. Sections that produced no
	 * rows are omitted entirely (thead/tfoot) except tbody, which core always
	 * emits even when empty.
	 *
	 * @param array<string, mixed> $rawBlock
	 * @return array<string, mixed>
	 */
	private static function buildTable( array $rawBlock ): array {
		$attrs = is_array( $rawBlock['attributes'] ?? null ) ? $rawBlock['attributes'] : [];
		$head  = is_array( $attrs['head'] ?? null ) ? $attrs['head'] : [];
		$body  = is_array( $attrs['body'] ?? null ) ? $attrs['body'] : [];
		$foot  = is_array( $attrs['foot'] ?? null ) ? $attrs['foot'] : [];

		$theadHtml = self::renderTableRows( $head, true );
		$tbodyHtml = self::renderTableRows( $body, false );
		$tfootHtml = self::renderTableRows( $foot, false );

		$tableInner = '';
		if ( $theadHtml !== '' ) {
			$tableInner .= '<thead>' . $theadHtml . '</thead>';
		}
		$tableInner .= '<tbody>' . $tbodyHtml . '</tbody>';
		if ( $tfootHtml !== '' ) {
			$tableInner .= '<tfoot>' . $tfootHtml . '</tfoot>';
		}

		$commentAttrs = [];
		if ( isset( $attrs['hasFixedLayout'] ) ) {
			$commentAttrs['hasFixedLayout'] = (bool) $attrs['hasFixedLayout'];
		}

		return self::leaf(
			'core/table',
			$commentAttrs,
			'<figure class="wp-block-table"><table>' . $tableInner . '</table></figure>'
		);
	}

	/**
	 * @param array<int, mixed> $rows
	 */
	private static function renderTableRows( array $rows, bool $forceHeaderCells ): string {
		$html = '';

		foreach ( $rows as $row ) {
			$cells = self::normalizeTableRow( $row );
			if ( $cells === null ) {
				// Already rejected by validateTable() before this is ever
				// reached; skip defensively rather than emit a malformed row.
				continue;
			}

			$rowHtml = '';
			foreach ( $cells as $cell ) {
				$tag      = $forceHeaderCells ? 'th' : ( $cell['tag'] ?? 'td' );
				$rowHtml .= '<' . $tag . '>' . esc_html( $cell['content'] ) . '</' . $tag . '>';
			}

			$html .= '<tr>' . $rowHtml . '</tr>';
		}

		return $html;
	}

	/**
	 * A5 validation: confirms every row of `attributes.head`/`body`/`foot`
	 * matches one of the three accepted shapes and that the table has at
	 * least one non-empty cell somewhere across all three sections.
	 *
	 * @param array<string, mixed> $block
	 */
	private static function validateTable( array $block, string $path ): ?\WP_Error {
		$attrs = is_array( $block['attributes'] ?? null ) ? $block['attributes'] : [];

		$hasNonEmptyCell = false;

		foreach ( [ 'head', 'body', 'foot' ] as $sectionName ) {
			$rows = $attrs[ $sectionName ] ?? [];
			if ( $rows === [] ) {
				continue;
			}

			if ( ! is_array( $rows ) ) {
				return new \WP_Error(
					'ab_invalid_block',
					sprintf(
						/* translators: %s: block path, e.g. blocks[0].attributes.body */
						__( 'Table %s must be an array of rows.', 'agent-brain' ),
						$path . '.attributes.' . $sectionName
					),
					[ 'status' => 422, 'block_path' => $path . '.attributes.' . $sectionName ]
				);
			}

			foreach ( $rows as $r => $row ) {
				$rowPath = $path . '.attributes.' . $sectionName . '[' . $r . ']';
				$cells   = self::normalizeTableRow( $row );

				if ( $cells === null ) {
					return new \WP_Error(
						'ab_invalid_block',
						sprintf(
							/* translators: %s: row path, e.g. blocks[0].attributes.body[1] */
							__( 'Table row %s is not one of the accepted shapes.', 'agent-brain' ),
							$rowPath
						),
						[ 'status' => 422, 'block_path' => $rowPath ]
					);
				}

				foreach ( $cells as $cell ) {
					if ( trim( $cell['content'] ) !== '' ) {
						$hasNonEmptyCell = true;
					}
				}
			}
		}

		if ( ! $hasNonEmptyCell ) {
			return new \WP_Error(
				'ab_empty_block',
				sprintf(
					/* translators: %s: block path */
					__( 'Table %s has no non-empty cell; nothing was saved.', 'agent-brain' ),
					$path
				),
				[ 'status' => 422, 'block_path' => $path ]
			);
		}

		return null;
	}

	/**
	 * Normalizes one table row from any of the three accepted shapes into a
	 * list of `{content: string, tag: ?string}` cells, or null if the row
	 * matches none of them.
	 *
	 * @return array<int, array{content:string, tag:?string}>|null
	 */
	private static function normalizeTableRow( mixed $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		// Shape 1: {cells:[{content, tag?}]}.
		if ( array_key_exists( 'cells', $row ) ) {
			if ( array_diff( array_keys( $row ), [ 'cells' ] ) !== [] || ! is_array( $row['cells'] ) ) {
				return null;
			}

			$cells = [];
			foreach ( $row['cells'] as $cell ) {
				if ( ! is_array( $cell ) || ! isset( $cell['content'] ) || ! is_string( $cell['content'] ) ) {
					return null;
				}

				if ( array_diff( array_keys( $cell ), [ 'content', 'tag' ] ) !== [] ) {
					return null;
				}

				$cells[] = [
					'content' => $cell['content'],
					'tag'     => ( $cell['tag'] ?? '' ) === 'th' ? 'th' : null,
				];
			}

			return $cells;
		}

		// Shapes 2/3: a plain list — either strings or {content} objects.
		if ( ! array_is_list( $row ) ) {
			return null;
		}

		$cells = [];
		foreach ( $row as $cell ) {
			if ( is_string( $cell ) ) {
				$cells[] = [ 'content' => $cell, 'tag' => null ];
				continue;
			}

			if ( is_array( $cell ) && isset( $cell['content'] ) && is_string( $cell['content'] )
				&& array_diff( array_keys( $cell ), [ 'content' ] ) === [] ) {
				$cells[] = [ 'content' => $cell['content'], 'tag' => null ];
				continue;
			}

			return null;
		}

		return $cells;
	}

	/**
	 * @param array<string, mixed> $rawBlock
	 * @return array<string, mixed>
	 */
	private static function buildImage( array $rawBlock ): array {
		$attrs = is_array( $rawBlock['attributes'] ?? null ) ? $rawBlock['attributes'] : [];

		$commentAttrs = [];
		foreach ( [ 'id', 'url', 'alt', 'width', 'height', 'sizeSlug', 'linkDestination' ] as $key ) {
			if ( isset( $attrs[ $key ] ) ) {
				$commentAttrs[ $key ] = $attrs[ $key ];
			}
		}

		$url = is_string( $attrs['url'] ?? null ) ? esc_url( $attrs['url'] ) : '';
		$alt = is_string( $attrs['alt'] ?? null ) ? esc_attr( $attrs['alt'] ) : '';

		return self::leaf(
			'core/image',
			$commentAttrs,
			'<figure class="wp-block-image"><img src="' . $url . '" alt="' . $alt . '"/></figure>'
		);
	}
}
