<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\InsertFailed;
use AgentBrain\Modules\Memory\MemoryRepository;
use InvalidArgumentException;
use WP_Error;
use WP_Post;
use WP_User;

/**
 * Fixes HIGH "the Review queue did not list handoffs": lists open
 * `ab_handoff` posts in the Review Queue admin page and exposes
 * "Approve & grant access" / "Dismiss" actions. Every action re-checks the
 * capability and the specific object (never trusts the caller's page-level
 * context, mirroring ReviewActions), fails closed on any check failure, and
 * is nonce-protected via its own admin-post handler.
 *
 * "Approve & grant access": for every memory id the handoff references,
 * resolves that memory's scope and grants the recipient (meta
 * `ab_handoff_to`) READ on it via `Identity\Scopes::grant()` with the
 * curator as acting user (so Scopes' own `ab_grant_scopes` check applies),
 * marks the handoff `ab_handoff_status` = approved, and writes REV-2-style
 * outcome memories for both the sender and the recipient.
 */
class HandoffQueue {

	public const META_TO         = 'ab_handoff_to';
	public const META_STATUS     = 'ab_handoff_status';
	public const META_MEMORY_IDS = 'ab_source_memory_ids';

	public const STATUS_APPROVED  = 'approved';
	public const STATUS_DISMISSED = 'dismissed';

	/**
	 * #658: why a handoff was dismissed (a curator's note from chat, or the
	 * expiry note), and 1 when it expired rather than being dismissed by
	 * someone.
	 */
	public const META_NOTE    = 'ab_handoff_note';
	public const META_EXPIRED = 'ab_handoff_expired';

	/** Audit event for a handoff that expired (#658). */
	public const EVENT_EXPIRED = 'handoff_expired';

	public const NONCE_ACTION = 'ab_handoff_action';
	public const NONCE_FIELD  = 'ab_handoff_nonce';

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_post_ab_handoff_action', [self::class, 'handleAction']);
	}

	/**
	 * Open handoffs = private ab_handoff posts with no resolution status
	 * yet (approve/dismiss both write ab_handoff_status), as $viewerId (the
	 * current user when null) may see them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function openHandoffs(?int $viewerId = null): array {
		if (! function_exists('get_posts')) {
			return [];
		}

		$posts = get_posts([
			'post_type'      => HandoffCpt::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'     => self::META_STATUS,
					'compare' => 'NOT EXISTS',
				],
			],
		]);

		$viewerId ??= get_current_user_id();
		$rows       = [];
		foreach ($posts as $post) {
			// #552: only handoffs whose memories the viewer may read.
			if (! ScopeVisibility::canSeePost($viewerId, $post)) {
				continue;
			}

			$fromId = (int) $post->post_author;
			$from   = $fromId > 0 ? get_userdata($fromId) : false;
			$toId   = (int) get_post_meta($post->ID, self::META_TO, true);
			$to     = $toId > 0 ? get_userdata($toId) : false;
			$rawIds = get_post_meta($post->ID, self::META_MEMORY_IDS, true);

			$rows[] = [
				'id'              => $post->ID,
				'title'           => $post->post_title,
				'note'            => $post->post_content,
				'from_agent_id'   => $fromId,
				'from_agent_name' => $from instanceof WP_User ? $from->display_name : (string) $fromId,
				'to_agent_id'     => $toId,
				'to_agent_name'   => $to instanceof WP_User ? $to->display_name : ($toId > 0 ? (string) $toId : ''),
				'memory_ids'      => is_array($rawIds) ? array_map('intval', $rawIds) : [],
				'created'         => (string) $post->post_date_gmt,
				'modified'        => (string) $post->post_modified_gmt,
			];
		}

		return $rows;
	}

	public static function status(int $handoffId): ?string {
		$status = get_post_meta($handoffId, self::META_STATUS, true);

		return is_string($status) && $status !== '' ? $status : null;
	}

	/** @return true|WP_Error */
	public function approveAndGrant(int $curatorId, int $handoffId) {
		$handoff = self::requireOpenHandoff($curatorId, $handoffId);
		if ($handoff instanceof WP_Error) {
			return $handoff;
		}

		$toAgentId = (int) get_post_meta($handoffId, self::META_TO, true);
		$memoryIds = get_post_meta($handoffId, self::META_MEMORY_IDS, true);
		$memoryIds = is_array($memoryIds) ? array_map('intval', $memoryIds) : [];

		$scopeIds = self::scopeIds($memoryIds);

		if ($toAgentId > 0 && $scopeIds !== []) {
			$scopes = new Scopes();
			foreach ($scopeIds as $scopeId) {
				// Curator is the acting user: Scopes::grant() re-checks
				// ab_grant_scopes itself and fails closed (returns false)
				// rather than throwing, so a race where the curator's caps
				// changed between the page-level check and this call still
				// can't silently grant on their behalf.
				$scopes->grant($curatorId, $toAgentId, 'read', $scopeId);
			}
		}

		update_post_meta($handoffId, self::META_STATUS, self::STATUS_APPROVED);

		self::writeOutcomeMemories($handoff, $toAgentId, $scopeIds);

		return true;
	}

	/**
	 * $note (#658, from review in chat) says why, kept on the handoff.
	 *
	 * @return true|WP_Error
	 */
	public function dismiss(int $curatorId, int $handoffId, string $note = '') {
		$handoff = self::requireOpenHandoff($curatorId, $handoffId);
		if ($handoff instanceof WP_Error) {
			return $handoff;
		}

		update_post_meta($handoffId, self::META_STATUS, self::STATUS_DISMISSED);
		if (trim($note) !== '') {
			update_post_meta($handoffId, self::META_NOTE, $note);
		}

		return true;
	}

	/**
	 * #658: closes an open handoff nobody acted on within $days as
	 * dismissed, with an expiry note, an audit entry and an outcome memory
	 * for the agent that asked. No access is granted and the memories it
	 * names are untouched. Only an open handoff can expire.
	 *
	 * @return true|WP_Error
	 */
	public static function expire(int $handoffId, int $days) {
		$handoff = get_post($handoffId);
		if (! $handoff instanceof WP_Post || $handoff->post_type !== HandoffCpt::POST_TYPE) {
			return new WP_Error('ab_not_found', 'Handoff not found.');
		}

		if (self::status($handoffId) !== null) {
			return new WP_Error('ab_not_open', 'Handoff already resolved.');
		}

		$note = sprintf(
			/* translators: %d: number of days */
			_n('Nobody acted on it within %d day, so it closed without sharing anything.', 'Nobody acted on it within %d days, so it closed without sharing anything.', $days, 'agent-brain'),
			$days
		);

		update_post_meta($handoffId, self::META_STATUS, self::STATUS_DISMISSED);
		update_post_meta($handoffId, self::META_NOTE, $note);
		update_post_meta($handoffId, self::META_EXPIRED, 1);

		do_action('agent_brain/audit', [
			'actor_id'    => 0,
			'event'       => 'handoff_expired',
			'status'      => 'ok',
			'object_type' => HandoffCpt::POST_TYPE,
			'object_id'   => (string) $handoffId,
			'meta'        => ['days' => $days, 'from_agent_id' => (int) $handoff->post_author],
		]);

		$memoryIds = get_post_meta($handoffId, self::META_MEMORY_IDS, true);
		$scopeId   = null;
		foreach (is_array($memoryIds) ? array_map('intval', $memoryIds) : [] as $memoryId) {
			$scopeId = self::memoryScopeId($memoryId);
			if ($scopeId !== null) {
				break;
			}
		}
		if ($scopeId !== null) {
			self::writeOutcomeMemory(new MemoryRepository(), (int) $handoff->post_author, $scopeId, $handoff, sprintf(
				'Your handoff "%s" was not reviewed in time and closed without sharing anything.',
				$handoff->post_title
			));
		}

		return true;
	}

	/** @return WP_Post|WP_Error */
	private static function requireOpenHandoff(int $curatorId, int $handoffId) {
		$handoff = get_post($handoffId);
		if (! $handoff instanceof WP_Post || $handoff->post_type !== HandoffCpt::POST_TYPE) {
			return new WP_Error('ab_not_found', 'Handoff not found.');
		}

		// Object-level check: map_meta_cap => true on ab_handoff resolves
		// 'edit_post' for a handoff authored by someone else to
		// edit_others_ab_handoffs / read_private_ab_handoffs, which only
		// curator/administrator hold (via HandoffCapabilities).
		if (! function_exists('user_can') || ! user_can($curatorId, 'edit_post', $handoffId)) {
			return new WP_Error('ab_forbidden', 'Cannot act on this handoff.');
		}

		if (self::status($handoffId) !== null) {
			return new WP_Error('ab_not_open', 'Handoff already resolved.');
		}

		return $handoff;
	}

	/**
	 * The scopes the given memories are in, each once. Approving a handoff
	 * grants the recipient read on every one of them, so it shares the
	 * whole scope, not only these memories (#731).
	 *
	 * @param int[] $memoryIds
	 * @return int[]
	 */
	public static function scopeIds(array $memoryIds): array {
		$scopeIds = [];
		foreach ($memoryIds as $memoryId) {
			$scopeId = self::memoryScopeId((int) $memoryId);
			if ($scopeId !== null) {
				$scopeIds[$scopeId] = true;
			}
		}

		return array_keys($scopeIds);
	}

	private static function memoryScopeId(int $memoryId): ?int {
		global $wpdb;

		// Table name spelled out to match MemoryRepository::memoryTable()
		// (Memory module owns that file; duplicated literal here per this
		// unit's scope boundary — see learnings).
		$table = $wpdb->prefix . 'ab_memory';
		$rows  = Db::getResults("SELECT scope_id FROM {$table} WHERE id = %d", [$memoryId]);

		return isset($rows[0]['scope_id']) ? (int) $rows[0]['scope_id'] : null;
	}

	/** @param int[] $scopeIds */
	private static function writeOutcomeMemories(WP_Post $handoff, int $toAgentId, array $scopeIds): void {
		if ($scopeIds === []) {
			return;
		}

		$scopeId     = $scopeIds[0];
		$fromAgentId = (int) $handoff->post_author;
		$repo        = new MemoryRepository();

		self::writeOutcomeMemory($repo, $fromAgentId, $scopeId, $handoff, sprintf(
			'Your handoff "%s" was approved and access was granted.',
			$handoff->post_title
		));

		if ($toAgentId > 0 && $toAgentId !== $fromAgentId) {
			self::writeOutcomeMemory($repo, $toAgentId, $scopeId, $handoff, sprintf(
				'You were granted access to memory referenced by the handoff "%s".',
				$handoff->post_title
			));
		}
	}

	private static function writeOutcomeMemory(MemoryRepository $repo, int $agentId, int $scopeId, WP_Post $handoff, string $content): void {
		if ($agentId <= 0) {
			return;
		}

		try {
			$repo->insert([
				'agent_id'      => $agentId,
				'scope_id'      => $scopeId,
				'type'          => 'episode',
				'content'       => $content,
				'agent_written' => 0,
				'status'        => 'active',
				'importance'    => 50,
				'source_ids'    => [$handoff->ID],
			]);
		} catch (InvalidArgumentException | InsertFailed $e) {
			// Fail loud, not fatal: an admin-visible degraded signal
			// instead of a silent drop (L26).
			if (function_exists('do_action')) {
				do_action('agent_brain/audit', [
					'actor_id'    => $agentId,
					'event'       => 'handoff_outcome_memory_write_failed',
					'status'      => 'error',
					'object_type' => HandoffCpt::POST_TYPE,
					'object_id'   => (string) $handoff->ID,
					'meta'        => ['message' => $e->getMessage()],
				]);
			}
		}
	}

	public static function handleAction(): void {
		if (! function_exists('current_user_can') || ! current_user_can(ReviewQueuePage::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to review handoffs.', 'agent-brain'));
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified explicitly below (per-row nonce).
		$handoffId = isset($_POST['handoff_id']) ? absint(wp_unslash($_POST['handoff_id'])) : 0;
		check_admin_referer(self::NONCE_ACTION . '_' . $handoffId, self::NONCE_FIELD);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$outcome = isset($_POST['handoff_outcome']) ? sanitize_key(wp_unslash($_POST['handoff_outcome'])) : '';

		$curatorId = get_current_user_id();
		$queue     = new self();

		$result = null;
		if ($outcome === 'approve') {
			$result = $queue->approveAndGrant($curatorId, $handoffId);
		} elseif ($outcome === 'dismiss') {
			$result = $queue->dismiss($curatorId, $handoffId);
		}

		ReviewQueuePage::respond($result, $outcome === 'approve'
			? __('Handoff approved: the recipient can now read the scopes its memories are in.', 'agent-brain')
			: __('Handoff dismissed. No access was granted.', 'agent-brain'), admin_url('admin.php?page=' . ReviewQueuePage::SLUG));
	}
}
