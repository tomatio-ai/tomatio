<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Recall\KnowledgeSearch;

/**
 * Curator pinning: a pinned knowledge or procedure item is always briefed
 * whole, ahead of recents (see Recall\Briefing). Adds a "Pinned" column with
 * a toggle link on the Knowledge and Procedures lists.
 */
final class Pins {

	public const ACTION = 'ab_toggle_pin';

	public static function register(): void {
		foreach (['ab_knowledge', 'ab_procedure'] as $postType) {
			add_filter("manage_{$postType}_posts_columns", [self::class, 'addColumn']);
			add_action("manage_{$postType}_posts_custom_column", [self::class, 'renderColumn'], 10, 2);
		}
		add_action('admin_post_' . self::ACTION, [self::class, 'handleToggle']);
	}

	public static function isPinned(int $postId): bool {
		return (string) get_post_meta($postId, KnowledgeSearch::META_PINNED, true) === '1';
	}

	public static function set(int $postId, bool $pinned): void {
		if ($pinned) {
			update_post_meta($postId, KnowledgeSearch::META_PINNED, '1');
		} else {
			delete_post_meta($postId, KnowledgeSearch::META_PINNED);
		}
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function addColumn(array $columns): array {
		$columns['ab_pinned'] = __('Pinned', 'agent-brain');

		return $columns;
	}

	public static function renderColumn(string $column, int $postId): void {
		if ($column !== 'ab_pinned') {
			return;
		}

		$pinned = self::isPinned($postId);
		if (! current_user_can(Capabilities::CAP_APPROVE_KNOWLEDGE)) {
			echo esc_html($pinned ? __('Pinned', 'agent-brain') : '');
			return;
		}

		$url = wp_nonce_url(
			add_query_arg(['action' => self::ACTION, 'post_id' => $postId], admin_url('admin-post.php')),
			self::ACTION . $postId
		);
		echo '<a href="' . esc_url($url) . '">' . esc_html($pinned ? __('Unpin', 'agent-brain') : __('Pin to briefings', 'agent-brain')) . '</a>';
	}

	public static function handleToggle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$postId = isset($_GET['post_id']) ? absint(wp_unslash($_GET['post_id'])) : 0;

		if (! current_user_can(Capabilities::CAP_APPROVE_KNOWLEDGE)) {
			wp_die(esc_html__('You do not have permission to pin items.', 'agent-brain'), 403);
		}
		check_admin_referer(self::ACTION . $postId);

		$post = $postId > 0 ? get_post($postId) : null;
		if ($post instanceof \WP_Post && in_array($post->post_type, KnowledgeSearch::POST_TYPES, true)) {
			self::set($postId, ! self::isPinned($postId));
		}

		wp_safe_redirect(wp_get_referer() ?: admin_url());
		exit;
	}
}
