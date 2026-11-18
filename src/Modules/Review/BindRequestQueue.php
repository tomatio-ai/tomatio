<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Extensions\BindRequests;
use WP_Error;

/**
 * #994 (RFC artifact-extensions, section 5.2): "Bind page to extension"
 * items in the Review inbox (InboxItems). Shown only to curators and
 * managers who are people; Bind page / Refuse are nonce-protected, and
 * BindRequests re-checks the reviewer and the page on every action.
 */
final class BindRequestQueue {

	public const ACTION       = 'ab_extension_bind';
	public const NONCE_ACTION = 'ab_extension_bind_';
	public const NONCE_FIELD  = 'ab_extension_bind_nonce';

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_post_' . self::ACTION, [self::class, 'handleAction']);
	}

	public static function handleAction(): void {
		if (! BindRequests::canApprove(get_current_user_id())) {
			wp_die(esc_html__('Only a curator or a manager can decide a bind request.', 'tomatio'), 403);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified explicitly below (per-request nonce).
		$id = isset($_POST['request']) ? sanitize_text_field(wp_unslash($_POST['request'])) : '';
		check_admin_referer(self::NONCE_ACTION . $id, self::NONCE_FIELD);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
		$result   = $decision === 'approve'
			? BindRequests::approve(get_current_user_id(), $id)
			: BindRequests::refuse(get_current_user_id(), $id, ReviewQueuePage::reasonFromRequest());

		if (! $result instanceof WP_Error) {
			$result = true;
		}

		ReviewQueuePage::respond($result, $decision === 'approve'
			? __('Page bound. Its files now come from the extension, and open pages pick them up on their next request.', 'tomatio')
			: __('Request refused. The page stays as it is.', 'tomatio'), admin_url('admin.php?page=' . ReviewQueuePage::SLUG));
	}
}
