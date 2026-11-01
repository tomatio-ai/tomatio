<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Comments;

use AgentBrain\Modules\Admin\CommentsPage;
use AgentBrain\Modules\Admin\Notices;

/**
 * wp-admin actions on comment threads: Resolve / Reopen on a thread root
 * (the Comments page and the WordPress list's row actions) and Reply (the
 * Comments page, #751), each through a nonce-protected admin-post handler. Mirrors
 * Review\HandoffQueue's action discipline: the handler re-checks the
 * capability and the specific object from scratch (never trusting the link
 * that got clicked) and flashes the outcome on redirect back to the list.
 *
 * wp-admin actions run as the logged-in curator (the only role holding the
 * CPT's primitive caps), which CommentService::canResolve admits.
 */
class AdminActions {

	public const ACTION         = 'ab_comment_action';
	public const ACTION_REPLY   = 'ab_comment_reply';
	public const NONCE_ACTION   = 'ab_comment_action';
	public const NONCE_FIELD    = 'ab_comment_nonce';
	public const FIELD_COMMENT  = 'ab_comment_id';
	public const FIELD_OUTCOME  = 'ab_comment_outcome';

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_filter('post_row_actions', [self::class, 'rowActions'], 10, 2);
		add_action('admin_post_' . self::ACTION, [self::class, 'handleAction']);
		add_action('admin_post_' . self::ACTION_REPLY, [self::class, 'handleReply']);
	}

	/** Resolve ($resolve) or reopen a thread, coming back to it on the Comments page. */
	public static function resolveUrl(int $commentId, bool $resolve): string {
		return wp_nonce_url(
			admin_url('admin-post.php?action=' . self::ACTION . '&' . self::FIELD_COMMENT . '=' . $commentId . '&' . self::FIELD_OUTCOME . '=' . ($resolve ? 'resolve' : 'reopen')),
			self::NONCE_ACTION . '_' . $commentId
		);
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function rowActions(array $actions, \WP_Post $post): array {
		if ($post->post_type !== Cpt::POST_TYPE || (int) $post->post_parent !== 0) {
			return $actions;
		}

		if (! function_exists('current_user_can') || ! current_user_can('edit_post', $post->ID)) {
			return $actions;
		}

		$resolved = (int) get_post_meta($post->ID, Cpt::META_RESOLVED, true) === 1;
		$label    = $resolved ? __('Reopen', 'agent-brain') : __('Resolve', 'agent-brain');
		$url      = self::resolveUrl((int) $post->ID, ! $resolved);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $url is escaped.
		$actions['ab_comment_resolve'] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';

		return $actions;
	}

	public static function handleAction(): void {
		if (! function_exists('current_user_can')) {
			wp_die(esc_html__('You do not have permission to do this.', 'agent-brain'));
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified explicitly below (per-row nonce).
		$commentId = isset($_GET[self::FIELD_COMMENT]) ? absint(wp_unslash($_GET[self::FIELD_COMMENT])) : 0;
		check_admin_referer(self::NONCE_ACTION . '_' . $commentId);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$outcome = isset($_GET[self::FIELD_OUTCOME]) ? sanitize_key(wp_unslash($_GET[self::FIELD_OUTCOME])) : '';

		$comment = get_post($commentId);
		if (! $comment instanceof \WP_Post || $comment->post_type !== Cpt::POST_TYPE || ! current_user_can('edit_post', $commentId)) {
			wp_die(esc_html__('You do not have permission to do this.', 'agent-brain'));
		}

		$result = (new CommentService())->resolve(get_current_user_id(), $commentId, $outcome !== 'reopen');

		if ($result instanceof \WP_Error) {
			Notices::flash('error', $result->get_error_message());
		} else {
			Notices::flash('success', $outcome === 'reopen'
				? __('Comment thread reopened.', 'agent-brain')
				: __('Comment thread resolved.', 'agent-brain'));
		}

		wp_safe_redirect(CommentsPage::url(['item' => (string) $commentId] + ($outcome === 'reopen' ? [] : ['view' => 'resolved'])));
		exit;
	}

	/** Reply from the Comments page, as the signed-in person. */
	public static function handleReply(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified explicitly below (per-thread nonce).
		$commentId = isset($_POST[self::FIELD_COMMENT]) ? absint(wp_unslash($_POST[self::FIELD_COMMENT])) : 0;
		check_admin_referer(self::NONCE_ACTION . '_' . $commentId, self::NONCE_FIELD);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$body    = isset($_POST['body']) ? sanitize_textarea_field(wp_unslash((string) $_POST['body'])) : '';
		$comment = get_post($commentId);
		if (! $comment instanceof \WP_Post || $comment->post_type !== Cpt::POST_TYPE || ! current_user_can('edit_post', $commentId)) {
			wp_die(esc_html__('You do not have permission to do this.', 'agent-brain'));
		}

		$result = (new CommentService())->reply(get_current_user_id(), $commentId, $body);
		if ($result instanceof \WP_Error) {
			Notices::flash('error', $result->get_error_message());
		} else {
			Notices::flash('success', __('Reply added.', 'agent-brain'));
		}

		$root = (int) $comment->post_parent > 0 ? (int) $comment->post_parent : $commentId;
		$args = ['item' => (string) $root];
		if ((int) get_post_meta($root, Cpt::META_RESOLVED, true) === 1) {
			$args['view'] = 'resolved';
		}
		wp_safe_redirect(CommentsPage::url($args));
		exit;
	}
}
