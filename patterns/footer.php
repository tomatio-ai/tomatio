<?php
/**
 * Block pattern: TomaTío footer.
 *
 * Plain markup file included (via output buffering) by
 * src\Modules\Patterns\Module::patternContent(). No dynamic data — this is
 * static block markup registered as-is with register_block_pattern().
 *
 * @package AgentBrain
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50","right":"var:preset|spacing|50"}}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"constrained","contentSize":"1100px"}} -->
<div class="wp-block-group alignfull has-base-color has-contrast-background-color has-text-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)"><!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"40%"} -->
<div class="wp-block-column" style="flex-basis:40%"><!-- wp:site-title {"level":0,"fontSize":"large"} /-->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><?php esc_html_e('A free WordPress plugin that turns your own site into a private, multi-agent second brain. Each AI agent gets scoped memory over MCP, a curator approves what becomes knowledge, and agents can write documents together section by section.', 'agent-brain'); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":4,"fontSize":"medium"} -->
<h4 class="wp-block-heading has-medium-font-size"><?php esc_html_e("What's inside", 'agent-brain'); ?></h4>
<!-- /wp:heading -->

<!-- wp:list {"fontSize":"small"} -->
<ul class="wp-block-list has-small-font-size"><!-- wp:list-item -->
<li><?php esc_html_e('Scoped, fail-closed agent memory', 'agent-brain'); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e('Session briefings and full-text recall', 'agent-brain'); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e('Curator review queue', 'agent-brain'); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e('Multi-agent artifacts', 'agent-brain'); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e('Forget with receipts, Markdown export', 'agent-brain'); ?></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":4,"fontSize":"medium"} -->
<h4 class="wp-block-heading has-medium-font-size"><?php esc_html_e('Project', 'agent-brain'); ?></h4>
<!-- /wp:heading -->

<!-- wp:list {"fontSize":"small"} -->
<ul class="wp-block-list has-small-font-size"><!-- wp:list-item -->
<li><a href="#"><?php esc_html_e('Source repository', 'agent-brain'); ?></a></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e('Plugin: free and complete', 'agent-brain'); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e('Cloud: optional, rebuildable data only', 'agent-brain'); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><?php esc_html_e('Status: MVP, not yet released', 'agent-brain'); ?></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:separator {"style":{"spacing":{"margin":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|30"}}},"backgroundColor":"accent-4"} -->
<hr class="wp-block-separator has-text-color has-accent-4-color has-alpha-channel-opacity has-accent-4-background-color has-background" style="margin-top:var(--wp--preset--spacing--40);margin-bottom:var(--wp--preset--spacing--30)"/>
<!-- /wp:separator -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><?php esc_html_e('Built with TomaTío for WordPress 7.1+ with the Abilities API and MCP. Your data stays on your site. © 2026', 'agent-brain'); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
