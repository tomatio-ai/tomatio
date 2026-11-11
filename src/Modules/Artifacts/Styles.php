<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

/**
 * ART-15: the CSS for the document blocks sections may use (status labels,
 * callouts, columns, readable tables), shared by the share page and the
 * compiled post.
 */
final class Styles {

	/** Styles a compiled post (the theme knows nothing of the ab- classes). */
	public static function printForCompiledPost(): void {
		if ( is_singular( 'post' ) && get_post_meta( (int) get_queried_object_id(), Cpts::META_COMPILED_FROM, true ) ) {
			echo '<style id="agent-brain-blocks">' . self::blocks() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS, no input.
		}
	}

	/** CSS for the blocks; no selector reaches outside the document body. */
	public static function blocks(): string {
		return '.ab-status{display:inline-block;margin:0 0 .5em;padding:.1em .6em;border-radius:1em;font-size:.8em;font-weight:600;line-height:1.6;background:#e8eaed;color:#1d2327}'
			. '.ab-status--done{background:#d6f0dc;color:#0b4a1d}.ab-status--in-progress{background:#dbe9fb;color:#0a3b78}'
			. '.ab-status--blocked{background:#f9d9d6;color:#7a1710}.ab-status--planned{background:#ececec;color:#3c434a}'
			. '.ab-status--decision{background:#e9ddf7;color:#43236b}'
			. '.ab-callout{margin:1em 0;padding:.75em 1em;border-left:4px solid #8c8f94;background:#f6f7f7}'
			. '.ab-callout>:first-child{margin-top:0}.ab-callout>:last-child{margin-bottom:0}'
			. '.ab-callout--info{border-color:#2271b1;background:#f0f6fc}.ab-callout--success{border-color:#1a7f37;background:#edf8ef}'
			. '.ab-callout--warning{border-color:#bd8600;background:#fcf6e3}.ab-callout--decision{border-color:#6b3fa0;background:#f5effb}'
			. '.wp-block-columns{display:flex;flex-wrap:wrap;gap:1.5em}.wp-block-column{flex:1 1 14rem;min-width:0}'
			. '.wp-block-table{overflow-x:auto;margin:1em 0}.wp-block-table table{border-collapse:collapse;width:100%}'
			. '.wp-block-table th,.wp-block-table td{border:1px solid #ccd0d4;padding:.35rem .6rem;text-align:left;vertical-align:top;overflow-wrap:anywhere}'
			. '.wp-block-table thead th{background:#f0f0f1}.wp-block-table tbody tr:nth-child(even){background:#f9f9f9}';
	}
}
