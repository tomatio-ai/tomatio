<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Identity\Capabilities;
use WP_Post;

/**
 * REV-3: no `ab_*` post, and no post created by artifact compile (meta
 * `ab_compiled_from`), may become `publish` unless it carries the
 * `ab_grant` `public-grant` term AND the acting user holds `ab_publish`.
 * Agents can never satisfy the second half of that (see GrantTaxonomy /
 * agentForbiddenCapabilities), so this is an unconditional block for them.
 *
 * Two layers, both required by contract:
 *  - `wp_insert_post_data` (primary): runs before the row is written, for
 *    both wp_insert_post() and wp_update_post() — which is also what a
 *    direct wp_update_post() call or (if ever enabled) a REST create/update
 *    request routes through.
 *  - `transition_post_status` (defense-in-depth): runs after the write, in
 *    case some other code path changed post_status without going through
 *    wp_insert_post_data (e.g. a raw $wpdb write). If an unauthorized
 *    'publish' is observed here, it is immediately reverted and an
 *    admin-visible audit event is raised (fail loud, L26) rather than
 *    silently leaving it revert-and-forget.
 */
class PublishGuard {

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 * @return array<string, mixed>
	 */
	public static function filterInsertData(array $data, array $postarr): array {
		if (($data['post_status'] ?? '') !== 'publish') {
			return $data;
		}

		$postId = (int) ($postarr['ID'] ?? 0);
		$postType = (string) ($data['post_type'] ?? '');

		if (! self::isGuarded($postType, $postId)) {
			return $data;
		}

		if (self::isAuthorized($postId)) {
			return $data;
		}

		$data['post_status'] = self::fallbackStatus($postId);

		self::auditBlocked($postId, $postType, 'wp_insert_post_data');

		return $data;
	}

	public static function onTransition(string $newStatus, string $oldStatus, WP_Post $post): void {
		if ($newStatus !== 'publish' || $oldStatus === 'publish') {
			return;
		}

		if (! self::isGuarded($post->post_type, $post->ID)) {
			return;
		}

		if (self::isAuthorized($post->ID)) {
			return;
		}

		static $reverting = false;
		if ($reverting) {
			return;
		}

		$reverting = true;
		wp_update_post(['ID' => $post->ID, 'post_status' => self::fallbackStatus($post->ID)]);
		$reverting = false;

		self::auditBlocked($post->ID, $post->post_type, 'transition_post_status');
	}

	private static function isGuarded(string $postType, int $postId): bool {
		if (str_starts_with($postType, 'ab_')) {
			return true;
		}

		return $postId > 0 && get_post_meta($postId, 'ab_compiled_from', true) !== '';
	}

	private static function isAuthorized(int $postId): bool {
		if ($postId <= 0) {
			// A brand new post cannot already carry the public-grant term.
			return false;
		}

		if (! GrantTaxonomy::hasPublicGrant($postId)) {
			return false;
		}

		$actorId = function_exists('get_current_user_id') ? get_current_user_id() : 0;

		return $actorId > 0 && user_can($actorId, Capabilities::CAP_PUBLISH);
	}

	private static function fallbackStatus(int $postId): string {
		if ($postId <= 0) {
			return 'draft';
		}

		$current = get_post_status($postId);

		return $current !== false && $current !== 'publish' ? $current : 'draft';
	}

	private static function auditBlocked(int $postId, string $postType, string $layer): void {
		if (! function_exists('do_action')) {
			return;
		}

		do_action('agent_brain/audit', [
			'actor_id'    => function_exists('get_current_user_id') ? get_current_user_id() : 0,
			'event'       => 'publish_blocked',
			'status'      => 'denied',
			'object_type' => $postType,
			'object_id'   => (string) $postId,
			'meta'        => ['layer' => $layer],
		]);
	}
}
