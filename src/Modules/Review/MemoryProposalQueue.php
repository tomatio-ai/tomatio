<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * Proposed memories in the Review inbox (#701, InboxItems): memory rows with status
 * proposed, which an import of a PCP archive makes from episodic pages
 * (Portability\Imports). Approving one makes it active (readable by agents
 * with access to its scope); rejecting one deletes it like Forget's soft
 * delete. Curators only, nonce-protected, audited.
 */
final class MemoryProposalQueue {

	public const ACTION       = 'ab_memory_proposal';
	public const NONCE_ACTION = 'ab_memory_proposal_';
	public const NONCE_FIELD  = 'ab_memory_proposal_nonce';

	public const EVENT_APPROVED = 'review.memory_approved';
	public const EVENT_REJECTED = 'review.memory_rejected';

	private const LIMIT = 200;

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_post_' . self::ACTION, [self::class, 'handleAction']);
	}

	/**
	 * Proposed rows, oldest first, within the current user's scopes (#552).
	 *
	 * @return array<int, array{id:int, content:string, type:string, scope:string, created:string}>
	 */
	public static function pending(): array {
		global $wpdb;

		$table  = MemoryRepository::memoryTable();
		$where  = 'm.status = %s';
		$params = ['proposed'];

		$visible = ScopeVisibility::visibleScopeIds(get_current_user_id());
		if ($visible !== null) {
			if ($visible === []) {
				return [];
			}
			$where .= ' AND m.scope_id IN (' . implode(', ', array_fill(0, count($visible), '%d')) . ')';
			$params = array_merge($params, $visible);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point; $where holds placeholders only.
		$rows = Db::getResults(
			"SELECT m.id, m.content, m.type, m.created, t.name AS scope FROM {$table} m
				LEFT JOIN {$wpdb->terms} t ON t.term_id = m.scope_id
				WHERE {$where} ORDER BY m.id ASC LIMIT %d",
			array_merge($params, [self::LIMIT])
		);
		// phpcs:enable

		return array_map(static fn (array $row): array => [
			'id'      => (int) $row['id'],
			'content' => (string) $row['content'],
			'type'    => (string) $row['type'],
			'scope'   => (string) ($row['scope'] ?? ''),
			'created' => (string) $row['created'],
		], $rows);
	}

	/**
	 * Approves (active) or rejects (deleted) a proposed row. False when the
	 * row is not a proposal.
	 */
	public static function decide(int $memoryId, string $decision, int $curatorId): bool {
		global $wpdb;

		if (! in_array($decision, ['approve', 'reject'], true)) {
			return false;
		}

		// #552: a proposal outside the curator's scopes is not theirs to decide.
		$scope = Db::getResults('SELECT scope_id FROM ' . MemoryRepository::memoryTable() . ' WHERE id = %d', [$memoryId]);
		if ($scope !== [] && ! ScopeVisibility::canSeeScope($curatorId, (int) $scope[0]['scope_id'])) {
			return false;
		}

		$updated = $wpdb->update(
			MemoryRepository::memoryTable(),
			['status' => $decision === 'approve' ? 'active' : 'deleted'],
			['id' => $memoryId, 'status' => 'proposed'],
			['%s'],
			['%d', '%s']
		);
		if ((int) $updated !== 1) {
			return false;
		}

		do_action('agent_brain/audit', [
			'actor_id'    => $curatorId,
			'event'       => $decision === 'approve' ? self::EVENT_APPROVED : self::EVENT_REJECTED,
			'status'      => 'ok',
			'object_type' => 'ab_memory',
			'object_id'   => (string) $memoryId,
		]);

		return true;
	}

	public static function handleAction(): void {
		if (! current_user_can(ReviewQueuePage::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to review proposals.', 'agent-brain'), 403);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified explicitly below (per-row nonce).
		$memoryId = isset($_POST['memory_id']) ? absint(wp_unslash($_POST['memory_id'])) : 0;
		check_admin_referer(self::NONCE_ACTION . $memoryId, self::NONCE_FIELD);
		$decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';

		$result = self::decide($memoryId, $decision, get_current_user_id())
			? true
			: new \WP_Error('ab_not_proposed', __('That memory is no longer waiting for review.', 'agent-brain'));

		ReviewQueuePage::respond($result, $decision === 'approve'
			? __('Kept. The memory is now readable by agents with access to its scope.', 'agent-brain')
			: __('Deleted. The memory is gone.', 'agent-brain'), admin_url('admin.php?page=' . ReviewQueuePage::SLUG));
	}
}
