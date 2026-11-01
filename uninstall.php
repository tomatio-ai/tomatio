<?php

declare(strict_types=1);

/**
 * Uninstall routine (fires only on "Delete" from the Plugins screen, never
 * on deactivation). Fail closed on the data-removal decision itself: unless
 * the site owner has explicitly opted in via `ab_delete_data_on_uninstall`,
 * nothing here is touched and this file returns immediately.
 *
 * When opted in, removes every trace of TomaTío's own data:
 *  - every {$wpdb->prefix}ab_* table (memory, links, audit, oauth tokens, …)
 *  - every `ab_*` option, including autoload=no secrets (cloud/backup keys)
 *  - every `ab_*` user meta key (read/write scope grants)
 *  - every post of our CPTs (ab_knowledge, ab_procedure, ab_artifact,
 *    ab_artifact_section, ab_handoff, ab_comment), force-deleted (bypassing trash) so
 *    postmeta/revisions/terms relationships are cleaned up by WordPress core
 *  - every term in the `ab_scope` / `ab_grant` taxonomies
 *  - every cron hook this plugin schedules
 *  - the protected export directory under uploads
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$optIn = get_option('ab_delete_data_on_uninstall', false);

if (! $optIn) {
	return;
}

global $wpdb;

// --- Tables -----------------------------------------------------------
$tables = $wpdb->get_col(
	$wpdb->prepare(
		'SHOW TABLES LIKE %s',
		$wpdb->esc_like($wpdb->prefix . 'ab_') . '%'
	)
);

foreach ($tables as $table) {
	// Table names can't be bound via $wpdb->prepare() placeholders; $table
	// is not caller input, it comes from our own SHOW TABLES LIKE query
	// above, so it is already constrained to our own prefixed tables.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

// --- Options (including autoload=no secrets: cloud/backup keys, etc.) --
$optionNames = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT option_name FROM ' . $wpdb->options . ' WHERE option_name LIKE %s',
		$wpdb->esc_like('ab_') . '%'
	)
);

foreach ($optionNames as $optionName) {
	delete_option($optionName);
}

delete_option('agent_brain_version');
delete_option('agent_brain_module_versions');

// --- User meta ----------------------------------------------------------
$metaKeys = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT DISTINCT meta_key FROM ' . $wpdb->usermeta . ' WHERE meta_key LIKE %s',
		$wpdb->esc_like('ab_') . '%'
	)
);

foreach ($metaKeys as $metaKey) {
	$wpdb->delete($wpdb->usermeta, ['meta_key' => $metaKey]);
}

// --- CPT posts (force-delete: bypass trash, cascades postmeta/terms) ---
$postTypes = ['ab_knowledge', 'ab_procedure', 'ab_artifact', 'ab_artifact_section', 'ab_handoff', 'ab_comment'];

$postIds = get_posts([
	'post_type'      => $postTypes,
	'post_status'    => 'any',
	'numberposts'    => -1,
	'fields'         => 'ids',
	'suppress_filters' => true,
]);

foreach ($postIds as $postId) {
	wp_delete_post((int) $postId, true);
}

// --- Taxonomy terms ------------------------------------------------------
foreach (['ab_scope', 'ab_grant'] as $taxonomy) {
	$terms = get_terms([
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'fields'     => 'ids',
	]);

	if (is_array($terms)) {
		foreach ($terms as $termId) {
			wp_delete_term((int) $termId, $taxonomy);
		}
	}
}

// --- Cron hooks -----------------------------------------------------------
$cronHooks = [
	'agent_brain_memory_rollover',
	'agent_brain_compression_run',
	'agent_brain_audit_prune',
	'agent_brain_export_nightly',
	'agent_brain_export_sweep',
	'agent_brain_db_decay',
	'agent_brain_backup',
	'agent_brain_review_expiry',
];

foreach ($cronHooks as $hook) {
	wp_clear_scheduled_hook($hook);
}

// --- Protected export directory ------------------------------------------
$upload = wp_upload_dir();
$exportDir = trailingslashit($upload['basedir']) . 'agent-brain-exports';

if (is_dir($exportDir)) {
	if (! function_exists('WP_Filesystem')) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	WP_Filesystem();

	global $wp_filesystem;

	if ($wp_filesystem) {
		$wp_filesystem->delete($exportDir, true);
	}
}

// --- Held PCP export archives ---------------------------------------------
// The same candidate folders Portability\ExportStore uses; only our own
// archive files and staging folders are removed, then our folders.
$abExportSuffix = substr(hash('sha256', ABSPATH . '|' . (string) get_current_blog_id()), 0, 12);
$abExportDirs   = [
	defined('AGENT_BRAIN_EXPORT_DIR') ? (string) constant('AGENT_BRAIN_EXPORT_DIR') : '' => false,
	rtrim(dirname(rtrim(ABSPATH, '/')), '/') . '/agent-brain-private-' . $abExportSuffix => true,
	rtrim(get_temp_dir(), '/') . '/agent-brain-exports-' . $abExportSuffix => true,
	rtrim(WP_CONTENT_DIR, '/') . '/agent-brain-private' => true,
];

foreach ($abExportDirs as $abDir => $abOwned) {
	if ($abDir === '' || ! is_dir($abDir)) {
		continue;
	}

	foreach (glob($abDir . '/*.pcp.*') ?: [] as $abPath) {
		if (preg_match('/^[a-f0-9]{32}\.pcp\.(zip|tar)$/', basename($abPath)) === 1) {
			wp_delete_file($abPath);
		}
	}

	foreach (glob($abDir . '/build-*', GLOB_ONLYDIR) ?: [] as $abStaging) {
		$abItems = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($abStaging, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($abItems as $abItem) {
			$abItem->isDir() ? rmdir($abItem->getPathname()) : unlink($abItem->getPathname());
		}
		rmdir($abStaging);
	}

	if ($abOwned) {
		foreach (['.htaccess', 'index.php', 'web.config'] as $abDeny) {
			if (is_file($abDir . '/' . $abDeny)) {
				wp_delete_file($abDir . '/' . $abDeny);
			}
		}
		@rmdir($abDir); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- leaves a folder that holds anything else.
	}
}
