<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Admin\ConnectPage;
use AgentBrain\Modules\DataStore\StoreSeats;
use AgentBrain\Modules\Sharing\ShareLinks;

/**
 * #551: after a migration or a move, everyone gets back in without being
 * bound one by one. People are invited in bulk (each gets the set-password
 * link by email, as Binding sends it), every unbound agent gets a new key
 * in one step (application passwords, handed over once, never logged), and
 * the reconnect checklist lists what is left: who is not back yet, this
 * brain's connector address, the share links to create again at the new
 * address (the records a migration kept, Kept::KIND_SHARE), and the seats
 * that came along. A seat keeps its name and the sha256 of its secret, but
 * the secret itself sits in each viewer's browser under the old share
 * address, so nobody is in their seat here until they claim it back: by
 * opening a new link with their old "#seat=" secret after it, or by a
 * curator releasing the name so they can claim it afresh.
 */
final class Reconnect {

	/**
	 * Binds each unbound person to an email address and mails the
	 * set-password link. A link comes back only when the mail failed, for
	 * the administrator to pass on.
	 *
	 * @param array<string, string> $invites principal id => email address
	 * @return array<string, array{ok:bool, mailed?:bool, link?:string, error?:string}> by principal id
	 */
	public static function invite(array $invites, int $byUserId): array {
		$out = [];
		foreach ($invites as $principal => $email) {
			$principal = trim((string) $principal);
			if (! str_starts_with($principal, 'human:')) {
				$out[$principal] = ['ok' => false, 'error' => 'Only people are invited; agents get keys.'];
				continue;
			}
			try {
				$result = Binding::bind($principal, $byUserId, trim((string) $email));
			} catch (ImportFailed $e) {
				$out[$principal] = ['ok' => false, 'error' => $e->getMessage()];
				continue;
			}
			$out[$principal] = ! empty($result['mailed'])
				? ['ok' => true, 'mailed' => true]
				: ['ok' => true, 'mailed' => false, 'link' => (string) ($result['link'] ?? '')];
		}

		return $out;
	}

	/**
	 * A new key for every unbound agent (or the ones named): each is bound
	 * with a new application password. The keys are returned once, for the
	 * caller to hand over as a file; they are never stored or logged.
	 *
	 * @param array<int, string>|null $only principal ids, or null for every unbound agent
	 * @return array<string, array{login:string, application_password?:string, error?:string}> by principal id
	 */
	public static function keys(?array $only, int $byUserId): array {
		$out = [];
		foreach (Binding::principals() as $principal) {
			if ($principal['type'] !== 'agent' || $principal['user_id'] === 0 || $principal['bound']) {
				continue;
			}
			if ($only !== null && ! in_array($principal['id'], $only, true)) {
				continue;
			}
			$user  = get_userdata($principal['user_id']);
			$login = $user instanceof \WP_User ? (string) $user->user_login : '';
			try {
				$result                  = Binding::bind($principal['id'], $byUserId);
				$out[$principal['id']] = ['login' => $login, 'application_password' => (string) ($result['application_password'] ?? '')];
			} catch (ImportFailed $e) {
				$out[$principal['id']] = ['login' => $login, 'error' => $e->getMessage()];
			}
		}
		foreach ((array) $only as $id) {
			if (! isset($out[$id])) {
				$out[$id] = ['login' => '', 'error' => 'Not an unbound agent of this brain.'];
			}
		}

		return $out;
	}

	/**
	 * The keys as a file for whoever sets up the agents: one block per
	 * agent with its login, its key and the Basic header, plus this brain's
	 * connector address.
	 *
	 * @param array<string, array{login:string, application_password?:string, error?:string}> $keys
	 */
	public static function keyFile(array $keys): string {
		$lines = [
			'# TomaTío agent keys for ' . home_url('/') . ', made ' . gmdate('Y-m-d H:i') . ' UTC.',
			'# Each key is shown only here. Keep this file private and delete it once the agents are set up.',
			'# Connector address: ' . ConnectPage::mcpUrl(),
			'',
		];
		foreach ($keys as $principal => $key) {
			if (! isset($key['application_password'])) {
				$lines[] = $principal . ': not issued (' . (string) ($key['error'] ?? 'unknown') . ')';
				$lines[] = '';
				continue;
			}
			$lines[] = $principal;
			$lines[] = '  login: ' . $key['login'];
			$lines[] = '  application password: ' . $key['application_password'];
			$lines[] = '  header: Authorization: Basic ' . base64_encode($key['login'] . ':' . str_replace(' ', '', $key['application_password']));
			$lines[] = '';
		}

		return implode("\n", $lines);
	}

	/**
	 * What is left to reconnect, with no secrets in it.
	 *
	 * @return array{connector:string, connect_page:string, people:array<int, array<string, mixed>>, agents:array<int, array<string, mixed>>, shares:array<int, array<string, mixed>>, seats:array<int, array<string, mixed>>}
	 */
	public static function checklist(): array {
		$people = [];
		$agents = [];
		foreach (Binding::principals() as $principal) {
			if ($principal['status'] === Principals::STATUS_REMOVED || $principal['user_id'] === 0) {
				continue;
			}
			$row = [
				'id'      => $principal['id'],
				'name'    => $principal['display_name'],
				'bound'   => $principal['bound'],
				'user_id' => $principal['user_id'],
			];
			if ($principal['type'] === 'human') {
				$people[] = $row;
			} elseif ($principal['type'] === 'agent') {
				$agents[] = $row;
			}
		}

		return [
			'connector'    => ConnectPage::mcpUrl(),
			'connect_page' => admin_url('admin.php?page=' . ConnectPage::SLUG),
			'people'       => $people,
			'agents'       => $agents,
			'shares'       => self::shares(),
			'seats'        => self::seats(),
		];
	}

	/**
	 * Seats that came with a migration or a move: claimed through a share
	 * link this brain does not have (or none), so not yet used here.
	 *
	 * @return array<int, array{artifact:int, title:string, name:string, opens:int, last_seen_at:?string}>
	 */
	public static function seats(): array {
		$seats = StoreSeats::table();
		$links = ShareLinks::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names only; Db::prepare() is the enforcement point.
		$rows = Db::getResults("SELECT s.artifact_id, s.name, s.opens, s.last_seen_at FROM {$seats} s LEFT JOIN {$links} l ON l.id = s.link_id WHERE l.id IS NULL ORDER BY s.artifact_id ASC, s.name ASC");

		return array_map(static fn (array $row): array => [
			'artifact'     => (int) $row['artifact_id'],
			'title'        => (string) get_post_field('post_title', (int) $row['artifact_id']),
			'name'         => (string) $row['name'],
			'opens'        => (int) $row['opens'],
			'last_seen_at' => $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null,
		], $rows);
	}

	/**
	 * Frees a seat name so its viewer (or anyone) can claim it again here.
	 * Its page store documents stay. Curators and managers only.
	 */
	public static function releaseSeat(int $artifactId, string $name, int $byUserId): bool {
		if (! Binding::canBind($byUserId)) {
			return false;
		}

		return StoreSeats::release($artifactId, $name, $byUserId);
	}

	/**
	 * The share links a migration or move brought, to create again.
	 *
	 * @return array<int, array{kept:int, artifact:int, title:string, kind:string, privacy:string, viewer_mode:string, expires_at:string}>
	 */
	public static function shares(): array {
		$out = [];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name only; Db::prepare() is the enforcement point.
		$rows = Db::getResults('SELECT id, body FROM ' . Kept::table() . ' WHERE kind = %s ORDER BY id ASC', [Kept::KIND_SHARE]);
		foreach ($rows as $row) {
			$record   = json_decode((string) $row['body'], true);
			$artifact = is_array($record) && preg_match('/^artifact-([1-9][0-9]{0,17})$/', (string) ($record['artifact'] ?? ''), $m) === 1 ? (int) $m[1] : 0;
			$out[]    = [
				'kept'        => (int) $row['id'],
				'artifact'    => $artifact,
				'title'       => $artifact > 0 ? (string) get_post_field('post_title', $artifact) : '',
				'kind'        => (string) ($record['kind'] ?? 'link'),
				'privacy'     => (string) ($record['privacy'] ?? ShareLinks::PRIVACY_PRIVATE),
				'viewer_mode' => (string) ($record['viewer_mode'] ?? ShareLinks::VIEWER_ANONYMOUS),
				'expires_at'  => (string) ($record['expires_at'] ?? ''),
			];
		}

		return $out;
	}

	/**
	 * Creates a share link again from a kept record, with the same kind,
	 * privacy and viewer mode and what was left of its time (or the default
	 * when it ran out), then forgets the record.
	 *
	 * @return array<string, mixed>|\WP_Error ShareLinks::mint()'s result: the new address, shown once.
	 */
	public static function recreateShare(int $keptId, int $byUserId) {
		foreach (self::shares() as $share) {
			if ($share['kept'] !== $keptId) {
				continue;
			}
			if ($share['artifact'] <= 0 || get_post($share['artifact']) === null) {
				return new \WP_Error('ab_not_found', __('The artifact of this share link is not on this brain.', 'agent-brain'), ['status' => 404]);
			}

			$left   = $share['expires_at'] !== '' ? strtotime($share['expires_at']) - time() : 0;
			$result = ShareLinks::mint($share['artifact'], $byUserId, $left > 3600 ? $left : null, null, null, $share['kind'], $share['viewer_mode'], $share['privacy']);
			if (! is_wp_error($result)) {
				self::forgetShare($keptId);
			}

			return $result;
		}

		return new \WP_Error('ab_not_found', __('No such share link to create again.', 'agent-brain'), ['status' => 404]);
	}

	/** Drops a kept share link record (created again, or not wanted). */
	public static function forgetShare(int $keptId): void {
		global $wpdb;

		$wpdb->delete(Kept::table(), ['id' => $keptId, 'kind' => Kept::KIND_SHARE], ['%d', '%s']);
	}
}
