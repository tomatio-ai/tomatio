<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Portability\Exports;
use AgentBrain\Modules\Portability\PageExtra;
use AgentBrain\Modules\Recall\KnowledgeSearch;
use WP_Error;
use WP_Post;

/**
 * #594: the PCP lifecycle of a knowledge or procedure item (SPEC 5.2.1,
 * 5.5), so an import can land as reference instead of a Review Queue full
 * of proposals.
 *
 * - Current (no meta): approved knowledge as always, exported as
 *   `validated`.
 * - `stale`, a reference page: a dated record of what another tool or brain
 *   said, approved once by the controller (an import with `as: reference`,
 *   or reference() on the proposals of an earlier import). Search returns
 *   it after current items, with its date; briefings leave it out of their
 *   recent items. Mark as current makes it ordinary knowledge.
 * - `archived`: kept and exported, never searched or briefed.
 *
 * Stored in post meta ab_lifecycle; export, restore and import carry it as
 * the page's `lifecycle`. The Knowledge and Procedures lists get a
 * "Reference" view and a column with Mark as current.
 */
final class Lifecycle {

	public const META     = 'ab_lifecycle';
	public const STALE    = 'stale';
	public const ARCHIVED = 'archived';

	/** The lifecycles kept in meta; current items have none. */
	public const STORED = [self::STALE, self::ARCHIVED];

	public const ACTION      = 'ab_mark_current';
	public const VIEW        = 'ab_lifecycle';
	public const AUDIT_EVENT = 'knowledge_lifecycle';

	public static function register(): void {
		foreach (KnowledgeSearch::POST_TYPES as $postType) {
			add_filter("manage_{$postType}_posts_columns", [self::class, 'addColumn']);
			add_action("manage_{$postType}_posts_custom_column", [self::class, 'renderColumn'], 10, 2);
			add_filter("views_edit-{$postType}", [self::class, 'addView']);
		}
		add_action('pre_get_posts', [self::class, 'filterList']);
		add_action('admin_post_' . self::ACTION, [self::class, 'handleMarkCurrent']);
	}

	/** The stored lifecycle of a post: stale, archived, or null (current). */
	public static function of(int $postId): ?string {
		return self::stored(get_post_meta($postId, self::META, true));
	}

	/**
	 * A frontmatter or meta value as stored: stale or archived, else null.
	 *
	 * @param mixed $value
	 */
	public static function stored($value): ?string {
		return is_string($value) && in_array($value, self::STORED, true) ? $value : null;
	}

	public static function set(int $postId, ?string $lifecycle): void {
		$lifecycle = self::stored($lifecycle);
		if ($lifecycle === null) {
			delete_post_meta($postId, self::META);
		} else {
			update_post_meta($postId, self::META, $lifecycle);
		}
	}

	/**
	 * Mark as current: a reference (or archived) item becomes ordinary
	 * knowledge. Curators with edit rights on the item; audited.
	 *
	 * @return true|WP_Error
	 */
	public static function markCurrent(int $userId, int $postId) {
		$post = get_post($postId);
		if (! $post instanceof WP_Post || ! in_array($post->post_type, KnowledgeSearch::POST_TYPES, true)) {
			return new WP_Error('ab_not_found', 'Item not found.');
		}
		if (! user_can($userId, Capabilities::CAP_APPROVE_KNOWLEDGE) || ! user_can($userId, 'edit_post', $postId)) {
			return new WP_Error('ab_forbidden', 'Cannot change this item.');
		}

		$was = self::of($postId);
		if ($was === null) {
			return true;
		}

		self::set($postId, null);
		do_action('agent_brain/audit', [
			'actor_id'    => $userId,
			'event'       => self::AUDIT_EVENT,
			'status'      => 'ok',
			'object_type' => $post->post_type,
			'object_id'   => (string) $postId,
			'meta'        => ['from' => $was, 'to' => 'current'],
		]);

		return true;
	}

	/**
	 * #594: turns the proposals an earlier import left in a scope into
	 * reference pages, the way an import with `as: reference` lands them:
	 * approved by the controller (an administrator), lifecycle stale unless
	 * the page names one already, one audit entry. Only imported proposals (x_import kept on the page) are
	 * touched; `$exporter`, when given, narrows to archives whose exporter
	 * name starts with it (for example "notion-pcp").
	 *
	 * @return array{matched:int, switched:int}|WP_Error
	 */
	public static function reference(int $userId, int $scopeId, string $exporter = '', bool $dryRun = false) {
		if (! Exports::canExport($userId)) {
			return new WP_Error('ab_forbidden', 'Only an administrator, acting for the controller, can approve imported pages as reference.');
		}

		$ids = get_posts([
			'post_type'        => KnowledgeSearch::POST_TYPES,
			'post_status'      => PostStatuses::PROPOSED,
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'tax_query'        => [['taxonomy' => KnowledgeSearch::TAXONOMY, 'field' => 'term_id', 'terms' => [$scopeId]]], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- an admin command over one scope.
		]);

		$matched = [];
		foreach ($ids as $postId) {
			$extra   = json_decode((string) get_post_meta((int) $postId, PageExtra::META, true), true);
			$xImport = is_array($extra['keys']['x_import'] ?? null) ? $extra['keys']['x_import'] : null;
			if ($xImport === null) {
				continue;
			}
			if ($exporter !== '' && ! str_starts_with((string) ($xImport['exporter'] ?? ''), $exporter)) {
				continue;
			}
			$matched[] = (int) $postId;
		}

		if ($dryRun || $matched === []) {
			return ['matched' => count($matched), 'switched' => 0];
		}

		global $wpdb;
		$now = gmdate('Y-m-d H:i:s');
		foreach ($matched as $postId) {
			$wpdb->update($wpdb->posts, ['post_status' => 'private'], ['ID' => $postId], ['%s'], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a controller batch, like the import it completes; caches cleaned below.
			update_post_meta($postId, ReviewActions::META_REVIEWED_BY, $userId);
			update_post_meta($postId, ReviewActions::META_REVIEWED_AT, $now);
			update_post_meta($postId, ReviewActions::META_REVIEW_OUTCOME, ReviewActions::OUTCOME_APPROVED);
			if (self::of($postId) === null) {
				self::set($postId, self::STALE);
			}
			clean_post_cache($postId);
		}

		do_action('agent_brain/audit', [
			'actor_id'    => $userId,
			'event'       => 'import.reference',
			'status'      => 'ok',
			'object_type' => 'scope',
			'object_id'   => (string) $scopeId,
			'meta'        => ['switched' => count($matched), 'exporter' => $exporter !== '' ? $exporter : null],
		]);

		return ['matched' => count($matched), 'switched' => count($matched)];
	}

	// Lists ----------------------------------------------------------------

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function addColumn(array $columns): array {
		$columns['ab_lifecycle'] = __('Reference', 'agent-brain');

		return $columns;
	}

	public static function renderColumn(string $column, int $postId): void {
		if ($column !== 'ab_lifecycle') {
			return;
		}

		$lifecycle = self::of($postId);
		if ($lifecycle === null) {
			return;
		}

		$post  = get_post($postId);
		$label = $lifecycle === self::STALE
			/* translators: %s: the date the source last changed the page. */
			? sprintf(__('Reference, as of %s', 'agent-brain'), $post instanceof WP_Post ? mysql2date((string) get_option('date_format'), $post->post_modified_gmt) : '')
			: __('Archived', 'agent-brain');
		echo esc_html($label);

		if (current_user_can(Capabilities::CAP_APPROVE_KNOWLEDGE) && current_user_can('edit_post', $postId)) {
			$url = wp_nonce_url(
				add_query_arg(['action' => self::ACTION, 'post_id' => $postId], admin_url('admin-post.php')),
				self::ACTION . $postId
			);
			echo '<br /><a href="' . esc_url($url) . '">' . esc_html__('Mark as current', 'agent-brain') . '</a>';
		}
	}

	/**
	 * @param array<string, string> $views
	 * @return array<string, string>
	 */
	public static function addView(array $views): array {
		$screen   = function_exists('get_current_screen') ? get_current_screen() : null;
		$postType = $screen !== null && in_array($screen->post_type, KnowledgeSearch::POST_TYPES, true) ? $screen->post_type : 'ab_knowledge';
		$count    = count(get_posts([
			'post_type'        => $postType,
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one admin count.
			'meta_value'       => self::STALE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one admin count.
		]));
		if ($count === 0) {
			return $views;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$current = isset($_GET[self::VIEW]) && sanitize_key(wp_unslash($_GET[self::VIEW])) === self::STALE;
		$url     = add_query_arg(['post_type' => $postType, self::VIEW => self::STALE], admin_url('edit.php'));

		$views[self::VIEW] = '<a href="' . esc_url($url) . '"' . ($current ? ' class="current" aria-current="page"' : '') . '>'
			. esc_html__('Reference', 'agent-brain') . ' <span class="count">(' . (int) $count . ')</span></a>';

		return $views;
	}

	public static function filterList(\WP_Query $query): void {
		if (! is_admin() || ! $query->is_main_query() || ! in_array($query->get('post_type'), KnowledgeSearch::POST_TYPES, true)) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		if (! isset($_GET[self::VIEW]) || sanitize_key(wp_unslash($_GET[self::VIEW])) !== self::STALE) {
			return;
		}

		$query->set('meta_key', self::META);
		$query->set('meta_value', self::STALE);
	}

	public static function handleMarkCurrent(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$postId = isset($_GET['post_id']) ? absint(wp_unslash($_GET['post_id'])) : 0;
		check_admin_referer(self::ACTION . $postId);

		$result = self::markCurrent(get_current_user_id(), $postId);
		if ($result instanceof WP_Error) {
			wp_die(esc_html($result->get_error_message()), 403);
		}

		wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=' . get_post_type($postId)));
		exit;
	}
}
