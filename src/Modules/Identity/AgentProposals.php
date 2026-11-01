<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Portability\Principals;
use WP_Error;

/**
 * Agents proposed by an AI, created only when a person approves.
 *
 * On a role-switching connection the AI may notice the team needs a role
 * nobody has yet ("we need a sound designer") and call `agent-propose`. That
 * never creates an account by itself: it queues a proposal (name, role,
 * scopes) that someone who manages the brain approves or declines in the
 * Review Queue. Approval creates a pure agent (role ab_agent, a random
 * password nobody sees) with the proposed scopes, reachable only through
 * Connect-as or role switching.
 *
 * Boundaries:
 *  - Only a person who manages the brain (ab_manage) can propose, from
 *    their own connection; agents cannot (Mcp\Abilities enforces this).
 *  - Proposed scopes must already be within the proposing person's own, so
 *    a proposal never reaches further than that person could. Checked again
 *    at approval, against both people.
 *  - Approval needs ab_manage, create_users and ab_grant_scopes.
 *  - At most MAX_PENDING proposals wait at once, so a looping client cannot
 *    flood the queue. Every step is audited.
 *  - A proposal is the PENDING state of the agent ID RFC (section 4): it
 *    expires EXPIRES_AFTER (24 h) after it was made (#234). Expired ones
 *    can no longer be approved and are removed, with an audit entry, by
 *    an hourly job, the Review Queue and the next proposal. No account
 *    exists while a proposal waits, so there is nothing to sign in as.
 *
 * Proposals are small and few, so they live in one option rather than a
 * table or post type.
 */
final class AgentProposals {

	public const OPTION      = 'agent_brain_agent_proposals';
	public const MAX_PENDING = 10;
	public const NAME_MAX    = 60;
	public const ROLE_MAX    = 300;

	/** Seconds a proposal waits for a person before it expires (#234). */
	public const EXPIRES_AFTER = DAY_IN_SECONDS;

	/** Hourly WP-Cron hook that removes expired proposals. */
	public const EXPIRE_HOOK = 'agent_brain_expire_agent_proposals';

	/**
	 * Queue a proposal from $personId (acting as $actingId, which is the
	 * person themselves or one of their agents).
	 *
	 * @param int[]|null $readScopes  null: the person's own write scopes.
	 * @param int[]|null $writeScopes null: the person's own write scopes.
	 * @return array{id:string, name:string, role:string, read_scope_ids:int[], write_scope_ids:int[], person_id:int, acting_id:int, created:string}|WP_Error
	 */
	public static function propose(int $personId, int $actingId, string $name, string $role, ?array $readScopes, ?array $writeScopes) {
		if ($personId <= 0 || ! user_can($personId, Capabilities::CAP_MANAGE)) {
			return new WP_Error('ab_forbidden', __('Only someone who manages this brain can propose new agents.', 'agent-brain'), ['status' => 403]);
		}

		$name = trim(sanitize_text_field($name));
		$role = trim(sanitize_textarea_field($role));
		if ($name === '' || self::length($name) > self::NAME_MAX) {
			/* translators: %d: maximum length. */
			return self::invalid(sprintf(__('name is required, at most %d characters.', 'agent-brain'), self::NAME_MAX));
		}
		if ($role === '' || self::length($role) > self::ROLE_MAX) {
			/* translators: %d: maximum length. */
			return self::invalid(sprintf(__('role is required: one sentence on what the agent does, at most %d characters.', 'agent-brain'), self::ROLE_MAX));
		}

		self::pruneExpired();

		if (self::nameTaken($name)) {
			return self::invalid(__('An agent or pending proposal already has that name. Pick another, or switch to the existing agent with act-as.', 'agent-brain'));
		}

		$pending = self::pending();
		if (count($pending) >= self::MAX_PENDING) {
			return new WP_Error('ab_too_many_proposals', __('Too many agent proposals are waiting. Ask a person to review them first.', 'agent-brain'), ['status' => 429]);
		}

		$scopes = new Scopes();
		$own    = $scopes->writeScopeIds($personId);
		$read   = self::ids($readScopes ?? $own);
		$write  = self::ids($writeScopes ?? $own);

		$outside = array_merge(array_diff($read, $scopes->readScopeIds($personId)), array_diff($write, $own));
		if ($outside !== []) {
			/* translators: %s: comma-separated scope ids. */
			return new WP_Error('ab_forbidden', sprintf(__('A new agent can only get scopes you already hold. Not yours: %s.', 'agent-brain'), implode(', ', array_unique($outside))), ['status' => 403]);
		}

		$proposal = [
			'id'              => wp_generate_uuid4(),
			'name'            => $name,
			'role'            => $role,
			'read_scope_ids'  => $read,
			'write_scope_ids' => $write,
			'person_id'       => $personId,
			'acting_id'       => $actingId > 0 ? $actingId : $personId,
			'created'         => gmdate('Y-m-d H:i:s'),
		];

		$pending[$proposal['id']] = $proposal;
		update_option(self::OPTION, $pending, false);

		self::audit($personId, 'agent.propose', $proposal['id'], ['name' => $name, 'via_agent' => $proposal['acting_id']]);

		return $proposal;
	}

	/**
	 * Pending proposals, oldest first, keyed by id.
	 *
	 * @return array<string, array{id:string, name:string, role:string, read_scope_ids:int[], write_scope_ids:int[], person_id:int, acting_id:int, created:string}>
	 */
	public static function pending(): array {
		$raw = get_option(self::OPTION, []);
		if (! is_array($raw)) {
			return [];
		}

		$out = [];
		foreach ($raw as $id => $row) {
			if (is_array($row) && is_string($id) && isset($row['name'], $row['role'])) {
				$out[$id] = [
					'id'              => $id,
					'name'            => (string) $row['name'],
					'role'            => (string) $row['role'],
					'read_scope_ids'  => self::ids((array) ($row['read_scope_ids'] ?? [])),
					'write_scope_ids' => self::ids((array) ($row['write_scope_ids'] ?? [])),
					'person_id'       => (int) ($row['person_id'] ?? 0),
					'acting_id'       => (int) ($row['acting_id'] ?? 0),
					'created'         => (string) ($row['created'] ?? ''),
				];
			}
		}

		return $out;
	}

	public static function canReview(int $userId): bool {
		return $userId > 0
			&& user_can($userId, Capabilities::CAP_MANAGE)
			&& user_can($userId, 'create_users')
			&& user_can($userId, Capabilities::CAP_GRANT_SCOPES);
	}

	/**
	 * Create the agent. Returns its user id.
	 *
	 * @return int|WP_Error
	 */
	public static function approve(int $reviewerId, string $id) {
		if (! self::canReview($reviewerId)) {
			return new WP_Error('ab_forbidden', __('You cannot add agents to this brain.', 'agent-brain'), ['status' => 403]);
		}

		$pending  = self::pending();
		$proposal = $pending[$id] ?? null;
		if ($proposal === null) {
			return new WP_Error('ab_not_found', __('That proposal is no longer waiting.', 'agent-brain'), ['status' => 404]);
		}

		if (self::isExpired($proposal)) {
			self::pruneExpired();

			return new WP_Error('ab_proposal_expired', __('That proposal expired: a person has 24 hours to add a proposed agent. Ask the AI to propose it again.', 'agent-brain'), ['status' => 410]);
		}

		// Scopes may have changed since the proposal: both the proposer and
		// the reviewer must still hold every scope the agent would get.
		$scopes = new Scopes();
		foreach ([$proposal['person_id'], $reviewerId] as $holder) {
			if (array_diff($proposal['read_scope_ids'], $scopes->readScopeIds($holder)) !== []
				|| array_diff($proposal['write_scope_ids'], $scopes->writeScopeIds($holder)) !== []) {
				return new WP_Error('ab_forbidden', __('The proposed scopes are no longer all held by the proposer and by you. Decline it and propose again.', 'agent-brain'), ['status' => 403]);
			}
		}

		if (self::userNameTaken($proposal['name'])) {
			return self::invalid(__('An agent with that name exists now. Decline this proposal.', 'agent-brain'));
		}

		// The ID is chosen first, so the login is its first label (#232, RFC
		// section 3), like a key registration's.
		$agentName  = AgentIssuer::nameFor($proposal['name']);
		$registerId = AgentIssuer::freeRegisterId($agentName);
		if ($registerId === null) {
			return new WP_Error('ab_fqdn_taken', __('Could not find a free agent ID for that name. Try again.', 'agent-brain'), ['status' => 409]);
		}
		$login = $agentName . '-' . $registerId;

		AgentIssuer::deferAutoIssue(true);
		try {
			$userId = wp_insert_user([
				'user_login'    => $login,
				'user_nicename' => $login,
				'user_pass'     => wp_generate_password(48, true, true),
				'display_name'  => $proposal['name'],
				'nickname'      => $proposal['name'],
				'description'   => $proposal['role'],
				'role'          => Capabilities::ROLE_AGENT,
			]);
		} finally {
			AgentIssuer::deferAutoIssue(false);
		}
		if (is_wp_error($userId)) {
			return $userId;
		}

		// No agent without an ID: if it cannot be issued, the account goes
		// and the proposal keeps waiting.
		$fqdn = (new AgentIssuer())->issue((int) $userId, $agentName, AgentMeta::CLASS_EXECUTOR, $reviewerId, $registerId);
		if ($fqdn instanceof WP_Error) {
			if (! function_exists('wp_delete_user')) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			wp_delete_user((int) $userId);
			Principals::discardUnused((int) $userId);

			return $fqdn;
		}
		// The principal id follows the FQDN just issued.
		Principals::ensure((int) $userId);

		foreach ($proposal['read_scope_ids'] as $scopeId) {
			$scopes->grant($reviewerId, (int) $userId, 'read', $scopeId);
		}
		foreach ($proposal['write_scope_ids'] as $scopeId) {
			$scopes->grant($reviewerId, (int) $userId, 'write', $scopeId);
		}

		unset($pending[$id]);
		update_option(self::OPTION, $pending, false);

		self::audit($reviewerId, 'agent.create', (string) $userId, ['proposal' => $id, 'proposed_by' => $proposal['person_id']]);

		return (int) $userId;
	}

	/** @return true|WP_Error */
	public static function decline(int $reviewerId, string $id) {
		if (! self::canReview($reviewerId)) {
			return new WP_Error('ab_forbidden', __('You cannot review agent proposals.', 'agent-brain'), ['status' => 403]);
		}

		$pending = self::pending();
		if (! isset($pending[$id])) {
			return new WP_Error('ab_not_found', __('That proposal is no longer waiting.', 'agent-brain'), ['status' => 404]);
		}

		$name = $pending[$id]['name'];
		unset($pending[$id]);
		update_option(self::OPTION, $pending, false);

		self::audit($reviewerId, 'agent.decline', $id, ['name' => $name]);

		return true;
	}

	/**
	 * Pure: has this proposal waited longer than EXPIRES_AFTER? A proposal
	 * whose time cannot be read counts as expired.
	 *
	 * @param array{created:string} $proposal
	 */
	public static function isExpired(array $proposal, ?int $now = null): bool {
		$created = strtotime($proposal['created'] . ' UTC');

		return $created === false || ($now ?? time()) - $created >= self::EXPIRES_AFTER;
	}

	/**
	 * Removes expired proposals, auditing each. Returns how many.
	 */
	public static function pruneExpired(): int {
		$pending = self::pending();
		$expired = array_filter($pending, static fn (array $proposal): bool => self::isExpired($proposal));
		if ($expired === []) {
			return 0;
		}

		update_option(self::OPTION, array_diff_key($pending, $expired), false);
		foreach ($expired as $id => $proposal) {
			self::audit(0, 'agent.proposal_expired', (string) $id, ['name' => $proposal['name'], 'proposed_by' => $proposal['person_id'], 'created' => $proposal['created']]);
		}

		return count($expired);
	}

	public static function scheduleExpiry(): void {
		if (! wp_next_scheduled(self::EXPIRE_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::EXPIRE_HOOK);
		}
	}

	/**
	 * Erasure (Forget): drops pending proposals without a review. The
	 * caller audits the erasure; nothing is written here.
	 *
	 * @param array<int, string> $ids
	 * @return int Proposals removed.
	 */
	public static function remove(array $ids): int {
		$pending = self::pending();
		$removed = 0;

		foreach ($ids as $id) {
			if (isset($pending[$id])) {
				unset($pending[$id]);
				++$removed;
			}
		}

		if ($removed > 0) {
			update_option(self::OPTION, $pending, false);
		}

		return $removed;
	}

	private static function nameTaken(string $name): bool {
		if (self::userNameTaken($name)) {
			return true;
		}

		foreach (self::pending() as $proposal) {
			if (self::fold($proposal['name']) === self::fold($name)) {
				return true;
			}
		}

		return false;
	}

	private static function userNameTaken(string $name): bool {
		$key = self::fold($name);
		// An approved agent's login is "<agent_name>-<register id>" and its
		// display name its FQDN (#232), so the agent name is matched too.
		$asLogin = '/^' . preg_quote(AgentIssuer::nameFor($name), '/') . '-[0-9a-f]{8}$/';
		foreach (get_users(['fields' => ['display_name', 'user_login']]) as $user) {
			$login = self::fold((string) $user->user_login);
			if (self::fold((string) $user->display_name) === $key || $login === $key || preg_match($asLogin, $login) === 1) {
				return true;
			}
		}

		return false;
	}

	private static function fold(string $s): string {
		return strtolower(remove_accents(trim($s)));
	}

	private static function length(string $s): int {
		return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
	}

	/**
	 * @param array<int|string, mixed> $ids
	 * @return int[]
	 */
	private static function ids(array $ids): array {
		return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
	}

	private static function invalid(string $message): WP_Error {
		return new WP_Error('ab_invalid_input', $message, ['status' => 422]);
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function audit(int $actorId, string $event, string $objectId, array $meta): void {
		do_action('agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => $event,
			'status'      => 'ok',
			'object_type' => 'user',
			'object_id'   => $objectId,
			'meta'        => $meta,
		]);
	}
}
