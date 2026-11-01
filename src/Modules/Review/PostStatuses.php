<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

/**
 * REV-1/REV-3: the two custom post statuses Review owns for `ab_knowledge` /
 * `ab_procedure` posts. Neither is public or searchable; both are shown in
 * admin list screens so curators (who hold the post type's
 * read_private_{plural}/edit_{plural} caps) can find them. `private` (a core
 * WordPress status) is reused as "approved" per the binding contract, so it
 * is not registered here.
 */
class PostStatuses {

	public const PROPOSED = 'ab_proposed';
	public const REJECTED = 'ab_rejected';

	public static function register(): void {
		if (! function_exists('register_post_status')) {
			return;
		}

		register_post_status(self::PROPOSED, [
			'label'                     => _x('Proposed', 'post status', 'agent-brain'),
			'public'                    => false,
			'internal'                  => false,
			'private'                   => true,
			'protected'                 => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of proposed items. */
			'label_count'               => _n_noop(
				'Proposed <span class="count">(%s)</span>',
				'Proposed <span class="count">(%s)</span>',
				'agent-brain'
			),
		]);

		register_post_status(self::REJECTED, [
			'label'                     => _x('Rejected', 'post status', 'agent-brain'),
			'public'                    => false,
			'internal'                  => false,
			'private'                   => true,
			'protected'                 => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of rejected items. */
			'label_count'               => _n_noop(
				'Rejected <span class="count">(%s)</span>',
				'Rejected <span class="count">(%s)</span>',
				'agent-brain'
			),
		]);
	}

	/** @return string[] */
	public static function all(): array {
		return [self::PROPOSED, self::REJECTED];
	}
}
