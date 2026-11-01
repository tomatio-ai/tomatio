<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Comments;

use AgentBrain\Modules\Identity\Capabilities;

/**
 * Grants the `ab_comment` CPT's primitive capabilities to ab_curator and
 * administrator, mirroring Review\HandoffCapabilities one module over: a
 * `user_has_cap` filter (never `$role->add_cap()`, which would break
 * Identity's byte-for-byte curator-role contract test and persist after
 * deactivation), wired on every request from Comments\Module::register().
 *
 * Agents deliberately get nothing here: they have no wp-admin at all, and
 * their comment access goes through CommentService's artifact-read gate.
 * The singular edit_ab_comment/read_ab_comment/delete_ab_comment meta-cap
 * names are excluded on the same grounds as HandoffCapabilities: they are
 * only ever checked as edit_post/read_post/delete_post against an object.
 */
class CommentCapabilities {

	/** @return string[] */
	public static function primitiveCaps(): array {
		return [
			'edit_ab_comments',
			'edit_others_ab_comments',
			'publish_ab_comments',
			'read_private_ab_comments',
			'delete_ab_comments',
			'delete_private_ab_comments',
			'delete_published_ab_comments',
			'delete_others_ab_comments',
			'edit_private_ab_comments',
			'edit_published_ab_comments',
		];
	}

	public static function register(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		add_filter('user_has_cap', [self::class, 'grant'], 10, 4);
	}

	/**
	 * @param array<string, bool> $allcaps
	 * @param string[]            $caps
	 * @param array<int, mixed>   $args
	 * @param mixed               $user   Unused: the grant is role-cap based, not object-level.
	 * @return array<string, bool>
	 */
	public static function grant(array $allcaps, array $caps, array $args, $user = null): array {
		// $allcaps already reflects role-derived capabilities (administrator
		// merges every curator cap) before this filter runs.
		if (empty($allcaps[Capabilities::CAP_MANAGE])) {
			return $allcaps;
		}

		foreach (self::primitiveCaps() as $cap) {
			$allcaps[$cap] = true;
		}

		return $allcaps;
	}
}
