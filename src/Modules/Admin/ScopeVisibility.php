<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Artifacts\Cpts as ArtifactCpts;
use AgentBrain\Modules\Comments\Cpt as CommentCpt;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Review\HandoffCpt;
use AgentBrain\Modules\Review\HandoffQueue;

/**
 * Issue #552: wp-admin shows a person only what their read scopes allow,
 * the same "scope before rank" rule agents get from Recall. One answer to
 * "may this person see this item", used by the list screens and their
 * search, the "All (n)" status counts, item and revision screens reached by
 * URL (through map_meta_cap), and the TomaTío screens and counts that list
 * items themselves (Memories, Review Queue, Overview, menu badges, Agent
 * View, Provenance, Audit Log labels).
 *
 * Who sees what:
 *  - A site administrator (manage_options) sees everything: they can grant
 *    themselves any scope, and DefaultScope gives every administrator the
 *    team scope, which must not hide the rest of the brain from them.
 *  - A person who holds read scopes sees items in those scopes only. An
 *    item with no scope yet (a draft, a curator's note) is shown to people
 *    who manage TomaTío (ab_manage), who are the ones who give it one.
 *  - A manager who holds no read scope at all keeps the whole view: that is
 *    a brain whose people were never given scopes (before #182 every brain
 *    started that way; DefaultScope now grants one to every curator and
 *    administrator), so there is nothing to limit them to. The Overview has
 *    shown such a manager real totals since it was built.
 *  - Anyone else with no read scope sees nothing scoped.
 *  - User 0 (cron, CLI) is not a person: nothing is limited.
 *
 * Agents (the ab_agent role) are left to their own rules (ObjectAccess,
 * Recall and the abilities): they have no wp-admin screens, and an artifact
 * lead's rights do not depend on read scopes.
 *
 * What an item's scope is, per type: knowledge and procedures, their
 * ab_scope terms; an artifact, its ab_scope_id; a section, its artifact's;
 * a comment, its artifact's; a handoff, the scopes of the memories it
 * shares plus the artifact of the comment it came from. A revision has its
 * parent's. An item spanning several scopes is shown only when the person
 * can read every one of them.
 */
final class ScopeVisibility {

	/** @var string[] */
	public const POST_TYPES = [
		'ab_knowledge',
		'ab_procedure',
		ArtifactCpts::ARTIFACT_POST_TYPE,
		ArtifactCpts::SECTION_POST_TYPE,
		HandoffCpt::POST_TYPE,
		CommentCpt::POST_TYPE,
	];

	private const META_CAPS = ['edit_post', 'read_post', 'delete_post'];

	public static function register(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		add_action('pre_get_posts', [self::class, 'limitListQuery']);
		add_filter('wp_count_posts', [self::class, 'limitCounts'], 10, 3);
		// After Artifacts\ObjectAccess (10), which lets every curator in.
		add_filter('map_meta_cap', [self::class, 'limitMetaCap'], 20, 4);
	}

	/**
	 * The scope ids $userId may see, or null for every scope (see the class
	 * comment). Fails closed: an error reading the scopes means none.
	 *
	 * @return int[]|null
	 */
	public static function visibleScopeIds(int $userId): ?array {
		if ($userId <= 0 || (function_exists('user_can') && user_can($userId, 'manage_options'))) {
			return null;
		}

		try {
			$ids = array_values(array_filter(array_map('intval', (new Scopes())->readScopeIds($userId)), static fn (int $id): bool => $id > 0));
		} catch (\Throwable $e) {
			return [];
		}

		if ($ids === [] && self::isManager($userId)) {
			return null;
		}

		return $ids;
	}

	public static function canSeeScope(int $userId, int $scopeId): bool {
		$visible = self::visibleScopeIds($userId);

		return $visible === null || ($scopeId > 0 && in_array($scopeId, $visible, true));
	}

	/**
	 * @param int|\WP_Post $post
	 */
	public static function canSeePost(int $userId, $post): bool {
		$visible = self::visibleScopeIds($userId);
		if ($visible === null) {
			return true;
		}

		$post = get_post($post);
		if (! $post instanceof \WP_Post) {
			return true;
		}

		if ($post->post_type === 'revision') {
			$parent = get_post((int) $post->post_parent);
			if (! $parent instanceof \WP_Post) {
				return true;
			}
			$post = $parent;
		}

		$scopes = self::scopeIdsFor($post->post_type, $post->ID, (int) $post->post_parent);

		return self::allowed($userId, $visible, $scopes);
	}

	/**
	 * The ids in $ids that $userId may see, in their order.
	 *
	 * @param array<int, int|string> $ids
	 * @return int[]
	 */
	public static function filterPostIds(int $userId, array $ids): array {
		$ids = array_map('intval', $ids);
		if (self::visibleScopeIds($userId) === null) {
			return $ids;
		}

		return array_values(array_filter($ids, static fn (int $id): bool => self::canSeePost($userId, $id)));
	}

	/**
	 * Every post of $postType that $userId may not see (any status).
	 *
	 * @return int[]
	 */
	public static function hiddenPostIds(int $userId, string $postType): array {
		$visible = self::visibleScopeIds($userId);
		if ($visible === null || ! in_array($postType, self::POST_TYPES, true)) {
			return [];
		}

		global $wpdb;

		$rows = Db::getResults("SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = %s", [$postType]);
		if ($rows === []) {
			return [];
		}

		$ids = array_map(static fn (array $row): int => (int) $row['ID'], $rows);
		if (function_exists('update_meta_cache')) {
			update_meta_cache('post', $ids);
		}
		if (in_array($postType, ['ab_knowledge', 'ab_procedure'], true) && function_exists('update_object_term_cache')) {
			update_object_term_cache($ids, $postType);
		}

		$hidden = [];
		foreach ($rows as $row) {
			if (! self::allowed($userId, $visible, self::scopeIdsFor($postType, (int) $row['ID'], (int) $row['post_parent']))) {
				$hidden[] = (int) $row['ID'];
			}
		}

		return $hidden;
	}

	/**
	 * pre_get_posts: the wp-admin list of a TomaTío type (and its search,
	 * which is the same query) leaves out what the viewer may not see.
	 */
	public static function limitListQuery(\WP_Query $query): void {
		if (! is_admin() || ! $query->is_main_query()) {
			return;
		}

		$postType = $query->get('post_type');
		if (! is_string($postType) || ! in_array($postType, self::POST_TYPES, true)) {
			return;
		}

		$hidden = self::hiddenPostIds(get_current_user_id(), $postType);
		if ($hidden === []) {
			return;
		}

		// WP_Query ignores post__not_in when post__in is set.
		$in = array_values(array_filter(array_map('intval', (array) $query->get('post__in'))));
		if ($in !== []) {
			$in = array_values(array_diff($in, $hidden));
			$query->set('post__in', $in !== [] ? $in : [0]);

			return;
		}

		$notIn = array_filter(array_map('intval', (array) $query->get('post__not_in')));
		$query->set('post__not_in', array_values(array_unique(array_merge($notIn, $hidden))));
	}

	/**
	 * wp_count_posts: the status counts above a list ("All (n)", "Draft
	 * (n)") count only what the viewer may see.
	 *
	 * @param \stdClass|mixed $counts
	 * @return \stdClass|mixed
	 */
	public static function limitCounts($counts, string $type, string $perm = '') {
		if (! $counts instanceof \stdClass || ! in_array($type, self::POST_TYPES, true)) {
			return $counts;
		}

		$userId = get_current_user_id();
		$hidden = self::hiddenPostIds($userId, $type);
		if ($hidden === []) {
			return $counts;
		}

		global $wpdb;

		$placeholders = implode(', ', array_fill(0, count($hidden), '%d'));
		$sql          = "SELECT post_status, COUNT(*) AS num_posts FROM {$wpdb->posts} WHERE post_type = %s AND ID NOT IN ({$placeholders})";
		$params       = array_merge([$type], $hidden);

		// Same rule as core's wp_count_posts( $type, 'readable' ).
		$object = get_post_type_object($type);
		if ($perm === 'readable' && $object !== null && ! current_user_can($object->cap->read_private_posts)) {
			$sql     .= " AND (post_status != 'private' OR post_author = %d)";
			$params[] = $userId;
		}

		$out = new \stdClass();
		foreach (array_keys((array) $counts) as $status) {
			$out->{$status} = 0;
		}
		foreach (Db::getResults($sql . ' GROUP BY post_status', $params) as $row) {
			$out->{(string) $row['post_status']} = (int) $row['num_posts'];
		}

		return $out;
	}

	/**
	 * map_meta_cap: an item, or a revision of it, that the person may not
	 * see cannot be opened, edited, restored or deleted, by URL or any other
	 * path that asks for the object-level capability.
	 *
	 * @param string[]          $caps
	 * @param array<int, mixed> $args
	 * @return string[]
	 */
	public static function limitMetaCap(array $caps, string $cap, int $userId, array $args): array {
		if ($userId <= 0 || ! in_array($cap, self::META_CAPS, true) || ! isset($args[0]) || self::isAgent($userId)) {
			return $caps;
		}

		$post = get_post((int) $args[0]);
		if (! $post instanceof \WP_Post) {
			return $caps;
		}

		$type = $post->post_type === 'revision' ? (string) get_post_type((int) $post->post_parent) : $post->post_type;
		if (! in_array($type, self::POST_TYPES, true)) {
			return $caps;
		}

		return self::canSeePost($userId, $post) ? $caps : ['do_not_allow'];
	}

	/**
	 * @param int[] $visible
	 * @param int[]|null $scopes
	 */
	private static function allowed(int $userId, array $visible, ?array $scopes): bool {
		if ($scopes === null) {
			return true;
		}

		if ($scopes === []) {
			return self::isManager($userId);
		}

		return array_diff($scopes, $visible) === [];
	}

	/**
	 * The item's scope ids, [] when it has none yet, null when the type is
	 * not one this class governs.
	 *
	 * @return int[]|null
	 */
	private static function scopeIdsFor(string $postType, int $postId, int $parentId): ?array {
		switch ($postType) {
			case 'ab_knowledge':
			case 'ab_procedure':
				$ids = wp_get_post_terms($postId, Scopes::TAXONOMY, ['fields' => 'ids']);

				return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
			case ArtifactCpts::ARTIFACT_POST_TYPE:
				return self::artifactScopeIds($postId);
			case ArtifactCpts::SECTION_POST_TYPE:
				return self::artifactScopeIds($parentId);
			case CommentCpt::POST_TYPE:
				return self::artifactScopeIds((int) get_post_meta($postId, CommentCpt::META_ARTIFACT, true));
			case HandoffCpt::POST_TYPE:
				return self::handoffScopeIds($postId);
		}

		return null;
	}

	/** @return int[] */
	private static function artifactScopeIds(int $artifactId): array {
		$scopeId = $artifactId > 0 ? (int) get_post_meta($artifactId, ArtifactCpts::META_SCOPE_ID, true) : 0;

		return $scopeId > 0 ? [$scopeId] : [];
	}

	/** @return int[] */
	private static function handoffScopeIds(int $handoffId): array {
		$scopes    = self::artifactScopeIds((int) get_post_meta($handoffId, CommentCpt::META_ARTIFACT, true));
		$memoryIds = get_post_meta($handoffId, HandoffQueue::META_MEMORY_IDS, true);
		$memoryIds = is_array($memoryIds) ? array_values(array_filter(array_map('intval', $memoryIds), static fn (int $id): bool => $id > 0)) : [];

		if ($memoryIds !== []) {
			$placeholders = implode(', ', array_fill(0, count($memoryIds), '%d'));
			$rows         = Db::getResults('SELECT DISTINCT scope_id FROM ' . MemoryRepository::memoryTable() . " WHERE id IN ({$placeholders})", $memoryIds);
			foreach ($rows as $row) {
				if ((int) $row['scope_id'] > 0) {
					$scopes[] = (int) $row['scope_id'];
				}
			}
		}

		return array_values(array_unique($scopes));
	}

	private static function isManager(int $userId): bool {
		return function_exists('user_can') && user_can($userId, Capabilities::CAP_MANAGE);
	}

	private static function isAgent(int $userId): bool {
		$user = get_userdata($userId);

		return $user instanceof \WP_User && in_array(Capabilities::ROLE_AGENT, (array) $user->roles, true);
	}
}
