<?php
/**
 * Block pattern: TomaTío landing page.
 *
 * Plain markup file included (via output buffering) by
 * src\Modules\Patterns\Module::patternContent(). No dynamic data — this is
 * static block markup registered as-is with register_block_pattern().
 *
 * Text is wrapped in esc_html_e()/esc_html__() where it can be without
 * breaking block validation (block comments and HTML structure must stay
 * literal for the block parser); the surrounding markup itself remains
 * static PHP/HTML.
 *
 * @package AgentBrain
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

?>
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50","right":"var:preset|spacing|50"}}},"backgroundColor":"accent-2","layout":{"type":"constrained","contentSize":"820px"}} -->
<div class="wp-block-group alignfull has-accent-2-background-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--50)"><!-- wp:paragraph {"align":"center","fontSize":"small"} -->
<p class="has-text-align-center has-small-font-size"><?php esc_html_e('AGENT BRAIN · WORKING NAME', 'agent-brain'); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"textAlign":"center","level":1,"fontSize":"xx-large"} -->
<h1 class="wp-block-heading has-text-align-center has-xx-large-font-size"><?php esc_html_e('A private, shared brain for your AI agents', 'agent-brain'); ?></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","fontSize":"large"} -->
<p class="has-text-align-center has-large-font-size"><?php esc_html_e('Your agents start every session cold. TomaTío gives each one a persistent, scoped memory it reads and writes over MCP, inside a WordPress site you already own, with a human curator deciding what becomes knowledge.', 'agent-brain'); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#how-it-works"><?php esc_html_e('See how it works', 'agent-brain'); ?></a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#pipeline"><?php esc_html_e('The authoring pipeline', 'agent-brain'); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|40","left":"var:preset|spacing|50","right":"var:preset|spacing|50"}}},"layout":{"type":"constrained","contentSize":"1100px"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--40);padding-left:var(--wp--preset--spacing--50)"><!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center"><?php esc_html_e('Why it exists', 'agent-brain'); ?></h2>
<!-- /wp:heading -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e('You own the data', 'agent-brain'); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e('Memory lives in your own WordPress database, even on shared hosting. The optional cloud only holds data it can rebuild from your site. Markdown export works at every tier, so you can always leave.', 'agent-brain'); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e('Every agent is scoped', 'agent-brain'); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e('Each agent is its own WordPress user with its own application password, and revoking it is the kill switch. Read and write scopes are separate and default to deny. Every search filters by scope before ranking, and fails closed.', 'agent-brain'); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e('A human stays in the loop', 'agent-brain'); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e('Agents write freely, but durable knowledge needs a curator. Important facts arrive as proposals you approve, edit or reject, and the outcome goes back into the agent\'s memory. Nothing is public unless you grant it.', 'agent-brain'); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->

<!-- wp:group {"anchor":"how-it-works","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained","contentSize":"820px"}} -->
<div id="how-it-works" class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)"><!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center"><?php esc_html_e('How it works', 'agent-brain'); ?></h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li><strong><?php esc_html_e('Connect an agent.', 'agent-brain'); ?></strong> <?php esc_html_e("Claude Code, Cursor or your own bot connects to the site's MCP endpoint as its own user.", 'agent-brain'); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong><?php esc_html_e('Start with a briefing.', 'agent-brain'); ?></strong> <?php esc_html_e('One call returns working memory, recent episodes, approved knowledge and procedures, trimmed to the token budget the agent asks for.', 'agent-brain'); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong><?php esc_html_e('Write as you work.', 'agent-brain'); ?></strong> <?php esc_html_e('Raw notes go into scoped memory. A compression job turns sessions into summaries, facts and search keywords.', 'agent-brain'); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong><?php esc_html_e('Curate.', 'agent-brain'); ?></strong> <?php esc_html_e('High-importance facts become proposals in the review queue. Approved knowledge shows up in future briefings.', 'agent-brain'); ?></li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li><strong><?php esc_html_e('Forget for real.', 'agent-brain'); ?></strong> <?php esc_html_e('Erasing a person or topic reaches memory, summaries, knowledge, exports and backups, and leaves a receipt that stores a hash, not the name.', 'agent-brain'); ?></li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size"><?php esc_html_e('Memory read from one agent is always handed to another as data, never as instructions.', 'agent-brain'); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"anchor":"pipeline","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60","left":"var:preset|spacing|50","right":"var:preset|spacing|50"}}},"backgroundColor":"accent-5","layout":{"type":"constrained","contentSize":"1100px"}} -->
<div id="pipeline" class="wp-block-group alignfull has-accent-5-background-color has-background" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--60);padding-left:var(--wp--preset--spacing--50)"><!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center"><?php esc_html_e('Agents that write together', 'agent-brain'); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center"><?php esc_html_e('A lead agent splits a document into sections that other agents draft in parallel. Conflicts are avoided by design: each section has one owner, an expiring lease, and a version check on every write.', 'agent-brain'); ?></p>
<!-- /wp:paragraph -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e('1. Outline', 'agent-brain'); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e('The lead defines the sections, assigns each to an agent, and writes a brief for it.', 'agent-brain'); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e('2. Draft', 'agent-brain'); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e("Each agent claims its own section and writes it as editor blocks. No one can touch a section they don't own.", 'agent-brain'); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e('3. Revise', 'agent-brain'); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e('The lead approves sections or asks for changes with notes. Approved sections lock.', 'agent-brain'); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size"><?php esc_html_e('4. Assemble', 'agent-brain'); ?></h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e('The finished document is assembled and fed back into the memory space, as a proposal a curator approves.', 'agent-brain'); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->

<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained","contentSize":"820px"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)"><!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center"><?php esc_html_e('Free plugin, optional cloud', 'agent-brain'); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center"><?php esc_html_e('The plugin is complete and free: scoped memory, recall, MCP, the review queue, artifacts, forget and export. An optional service adds the things shared hosting struggles with: reliable scheduling, managed compression, encrypted backups, and later semantic search. Cancelling the cloud only costs you convenience.', 'agent-brain'); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#"><?php esc_html_e('View the project', 'agent-brain'); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
