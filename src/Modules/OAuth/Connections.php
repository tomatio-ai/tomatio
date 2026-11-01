<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * #225: per-connection status and notices. A "connection" here is one OAuth
 * client_id as one user (the shape of a connected AI client), matching
 * TokenStore's grants.
 *
 * What is stored, per user, in the `ab_connections` user meta (one row per
 * client):
 *	- `tool_set_hash`  — hash of the effective tool list (the one filtered by
 *	  the caller's permissions) the client last listed via `tools/list`;
 *	- `surface_hash`   — hash of the full, unfiltered tool surface at that
 *	  time, so an out-of-date marker can be computed without re-running
 *	  permission callbacks in wp-admin;
 *	- `tool_set_at`	   — UTC datetime of that `tools/list`;
 *	- `version`		   — plugin version at that `tools/list` (input to the
 *	  "reconnect required since version X" marker);
 *	- `last_seen`	   — UTC datetime of the last authenticated MCP request
 *	  from this connection (throttled, see TOUCH_INTERVAL_SECONDS).
 *
 * The hash is recorded from Mcp\AdapterBridge on every real `tools/list`
 * (the adapter's `mcp_adapter_tools_list` filter), which is exactly the
 * moment a client refreshes its snapshot: a client that never re-lists keeps
 * its old hash and is then told, on its next briefing, that its tool list is
 * out of date. A reconnect (a fresh `tools/list`) records the fresh set and
 * clears the notice.
 *
 * Notices are delivered through the `context-briefing` ability's `notices`
 * output field (see Mcp\Abilities) — the least invasive seam: one place,
 * read by the agent at session start, no protocol change. The same data
 * drives the Connect an AI page's per-connection status table and the
 * wp-admin notice.
 */
final class Connections {

	public const META_KEY = 'ab_connections';

	/** Option holding the version a release set when it truly needs a reconnect. */
	public const OPTION_RECONNECT_REQUIRED = 'ab_reconnect_required_since';

	/** A refresh token expiring within this window warns its connection (#225). */
	public const EXPIRY_WARNING_SECONDS = 7 * DAY_IN_SECONDS;

	/** last_seen is rewritten at most once per interval per connection. */
	private const TOUCH_INTERVAL_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Stable hash of a tool-name set: sorted, deduplicated, sha256 with a
	 * scheme prefix so the format can evolve. Order of names never matters.
	 *
	 * @param array<int, mixed> $toolNames
	 */
	public static function hashFor(array $toolNames): string {
		$names = [];
		foreach ($toolNames as $name) {
			if (is_string($name) && $name !== '') {
				$names[$name] = true;
			}
		}
		$names = array_keys($names);
		sort($names, SORT_STRING);

		return 'sha256.' . hash('sha256', implode("\n", $names));
	}

	/**
	 * Records the tool set a connection last listed: the effective (filtered)
	 * names, the full surface names, and the plugin version, all timestamped.
	 * Returns the effective-set hash.
	 *
	 * @param array<int, string> $effectiveNames
	 * @param array<int, string> $surfaceNames
	 */
	public static function recordToolSet(int $userId, string $clientId, array $effectiveNames, array $surfaceNames, string $version): string {
		$hash = self::hashFor($effectiveNames);
		if ($userId <= 0 || $clientId === '') {
			return $hash;
		}

		$now = current_time('mysql', true);
		$all = self::readAll($userId);

		$row = is_array($all[self::key($clientId)] ?? null) ? $all[self::key($clientId)] : [];
		$row['client_id']	  = $clientId;
		$row['tool_set_hash'] = $hash;
		$row['surface_hash']  = self::hashFor($surfaceNames);
		$row['tool_set_at']	  = $now;
		$row['version']		  = $version;
		$row['last_seen']	  = $now;

		$all[self::key($clientId)] = $row;
		self::writeAll($userId, $all);

		return $hash;
	}

	/**
	 * Marks the connection as seen now. Creates the row (without a tool set)
	 * on first sight so the Connect an AI page can list the connection before
	 * its first `tools/list`. Throttled: at most one write per connection per
	 * TOUCH_INTERVAL_SECONDS.
	 */
	public static function touch(int $userId, string $clientId): void {
		if ($userId <= 0 || $clientId === '') {
			return;
		}

		$all = self::readAll($userId);
		$row = is_array($all[self::key($clientId)] ?? null) ? $all[self::key($clientId)] : [];

		$lastSeen = is_string($row['last_seen'] ?? null) ? (string) $row['last_seen'] : '';
		if ($lastSeen !== '' && strtotime($lastSeen . ' UTC') > time() - self::TOUCH_INTERVAL_SECONDS) {
			return;
		}

		$row['client_id'] = $clientId;
		$row['last_seen'] = current_time('mysql', true);

		$all[self::key($clientId)] = $row;
		self::writeAll($userId, $all);
	}

	/**
	 * The stored row for one connection, or null when nothing was recorded.
	 *
	 * @return array{client_id?:string, tool_set_hash?:string, surface_hash?:string, tool_set_at?:string, version?:string, last_seen?:string}|null
	 */
	public static function recorded(int $userId, string $clientId): ?array {
		if ($userId <= 0 || $clientId === '') {
			return null;
		}

		$all = self::readAll($userId);
		$row = $all[self::key($clientId)] ?? null;

		return is_array($row) ? $row : null;
	}

	/**
	 * A release that truly needs a reconnect (not a routine tool-only update)
	 * sets this marker; every connection whose recorded plugin version
	 * predates the marker gets the reconnect-required notice (#225).
	 */
	public static function requireReconnectSince(string $version): void {
		if ($version === '' || ! function_exists('update_option')) {
			return;
		}

		update_option(self::OPTION_RECONNECT_REQUIRED, $version);
	}

	/**
	 * The notice this connection should see in its next briefing, or null
	 * when nothing needs attention. Precedence: release-declared reconnect >
	 * out-of-date tool set > refresh-token expiry. $currentEffectiveHash /
	 * $currentSurfaceHash come from Mcp\AdapterBridge and may be null outside
	 * an MCP request (e.g. tests), in which case the corresponding staleness
	 * check is skipped.
	 */
	public static function noticeFor(int $userId, ?string $clientId, ?string $currentEffectiveHash, ?string $currentSurfaceHash): ?string {
		if ($userId <= 0 || $clientId === null || $clientId === '') {
			return null;
		}

		$row = self::recorded($userId, $clientId);

		$marker = function_exists('get_option') ? get_option(self::OPTION_RECONNECT_REQUIRED) : '';
		$marker = is_string($marker) ? trim($marker) : '';
		if ($marker !== '') {
			$recordedVersion = is_array($row) && is_string($row['version'] ?? null) ? (string) $row['version'] : '';
			if ($recordedVersion === '' || version_compare($recordedVersion, $marker, '<')) {
				return sprintf(
					/* translators: %s: the plugin version that requires a reconnect. */
					__('This brain was updated to version %s and this connection needs a reconnect to keep working. Reconnect in your AI\'s connector settings; everything resumes as before.', 'agent-brain'),
					$marker
				);
			}
		}

		if (is_array($row)) {
			$recordedSurface = is_string($row['surface_hash'] ?? null) ? (string) $row['surface_hash'] : '';
			if ($recordedSurface !== '' && $currentSurfaceHash !== null && $currentSurfaceHash !== '' && $recordedSurface !== $currentSurfaceHash) {
				return sprintf(
					/* translators: %s: date the connection last listed its tools. */
					__('This connection was set up before the brain\'s last update and its tool list changed on %s: it is missing new tools or still lists removed ones. Reconnect in your AI\'s connector settings to refresh the tool list.', 'agent-brain'),
					self::dateLabel(is_string($row['tool_set_at'] ?? null) ? (string) $row['tool_set_at'] : '')
				);
			}

			$recordedEffective = is_string($row['tool_set_hash'] ?? null) ? (string) $row['tool_set_hash'] : '';
			if ($recordedEffective !== '' && $currentEffectiveHash !== null && $currentEffectiveHash !== '' && $recordedEffective !== $currentEffectiveHash) {
				return sprintf(
					/* translators: %s: date the connection last listed its tools. */
					__('This connection\'s tool list changed on %s (its permissions or the brain\'s tools changed since it connected). Reconnect in your AI\'s connector settings to refresh the tool list.', 'agent-brain'),
					self::dateLabel(is_string($row['tool_set_at'] ?? null) ? (string) $row['tool_set_at'] : '')
				);
			}
		}

		return self::expiryNotice($userId, $clientId);
	}

	/**
	 * One status row per client the user has a grant with or a recorded
	 * connection, merged and ranked: `reconnect-required` > `update-available`
	 * > `expiring` > `current`.
	 *
	 * @return array<int, array{client_id:string, connected:string, last_seen:?string, tool_set_hash:?string, out_of_date:bool, reconnect_required:?string, expires:?string, expires_in_days:?int, expiring:bool, expired:bool, status:string, notice:?string}>
	 */
	public static function statusesFor(int $userId, ?string $currentSurfaceHash): array {
		if ($userId <= 0) {
			return [];
		}

		$byClient = [];
		foreach (TokenStore::grantsForUser($userId) as $grant) {
			$client = (string) $grant['client_id'];
			$row	= $byClient[$client] ?? ['connected' => (string) $grant['created'], 'expires' => (string) $grant['expires']];
			// MySQL datetime strings compare lexicographically.
			$row['connected'] = min($row['connected'], (string) $grant['created']);
			$row['expires']	  = max($row['expires'], (string) $grant['expires']);
			$byClient[$client] = $row;
		}

		foreach (self::readAll($userId) as $stored) {
			if (is_array($stored) && is_string($stored['client_id'] ?? null) && $stored['client_id'] !== '') {
				$byClient[(string) $stored['client_id']] = $byClient[(string) $stored['client_id']] ?? ['connected' => '', 'expires' => ''];
			}
		}

		$marker = function_exists('get_option') ? get_option(self::OPTION_RECONNECT_REQUIRED) : '';
		$marker = is_string($marker) ? trim($marker) : '';

		$statuses = [];
		foreach ($byClient as $client => $info) {
			$row	= self::recorded($userId, (string) $client);
			$stored = is_array($row) && is_string($row['surface_hash'] ?? null) ? (string) $row['surface_hash'] : '';

			$outOfDate = $stored !== '' && $currentSurfaceHash !== null && $currentSurfaceHash !== '' && $stored !== $currentSurfaceHash;

			$recordedVersion = is_array($row) && is_string($row['version'] ?? null) ? (string) $row['version'] : '';
			$reconnectSince	 = null;
			if ($marker !== '' && ($recordedVersion === '' || version_compare($recordedVersion, $marker, '<'))) {
				$reconnectSince = $marker;
			}

			$expiresTs	   = $info['expires'] !== '' ? strtotime($info['expires'] . ' UTC') : false;
			$expired	   = $expiresTs !== false && $expiresTs <= time();
			$expiring	   = $expiresTs !== false && ! $expired && $expiresTs - time() <= self::EXPIRY_WARNING_SECONDS;
			$expiresInDays = $expiresTs !== false && $expiresTs > time()
				? (int) max(1, (int) round(($expiresTs - time()) / DAY_IN_SECONDS))
				: null;

			$status = 'current';
			if ($reconnectSince !== null) {
				$status = 'reconnect-required';
			} elseif ($outOfDate) {
				$status = 'update-available';
			} elseif ($expiring || $expired) {
				$status = 'expiring';
			}

			$statuses[] = [
				'client_id'			 => (string) $client,
				'connected'			 => $info['connected'],
				'last_seen'			 => is_array($row) && is_string($row['last_seen'] ?? null) && $row['last_seen'] !== '' ? (string) $row['last_seen'] : null,
				'tool_set_hash'		 => is_array($row) && is_string($row['tool_set_hash'] ?? null) && $row['tool_set_hash'] !== '' ? (string) $row['tool_set_hash'] : null,
				'out_of_date'		 => $outOfDate,
				'reconnect_required' => $reconnectSince,
				'expires'			 => $info['expires'] !== '' ? $info['expires'] : null,
				'expires_in_days'	 => $expiresInDays,
				'expiring'			 => $expiring,
				'expired'			 => $expired,
				'status'			 => $status,
				'notice'			 => self::noticeFor($userId, (string) $client, null, $currentSurfaceHash),
			];
		}

		$rank = ['reconnect-required' => 0, 'update-available' => 1, 'expiring' => 2, 'current' => 3];
		usort($statuses, static function (array $a, array $b) use ($rank): int {
			return ($rank[$a['status']] ?? 4) <=> ($rank[$b['status']] ?? 4) ?: strcmp($a['client_id'], $b['client_id']);
		});

		return $statuses;
	}

	/** Whether any of the user's connections needs attention right now. */
	public static function needsAttention(int $userId, ?string $currentSurfaceHash): bool {
		foreach (self::statusesFor($userId, $currentSurfaceHash) as $status) {
			if ($status['status'] !== 'current') {
				return true;
			}
		}

		return false;
	}

	private static function expiryNotice(int $userId, string $clientId): ?string {
		$latest = null;
		foreach (TokenStore::grantsForUser($userId) as $grant) {
			if (! hash_equals((string) $grant['client_id'], $clientId)) {
				continue;
			}
			$ts = strtotime((string) $grant['expires'] . ' UTC');
			if ($ts !== false && ($latest === null || $ts > $latest)) {
				$latest = $ts;
			}
		}

		if ($latest === null) {
			return null;
		}

		if ($latest <= time()) {
			return __('This connection\'s sign-in has expired. Reconnect in your AI\'s connector settings to sign in again.', 'agent-brain');
		}

		if ($latest - time() <= self::EXPIRY_WARNING_SECONDS) {
			return sprintf(
				/* translators: 1: the expiry date, 2: whole days until expiry. */
				__('This connection\'s sign-in expires on %1$s (in %2$d days). Reconnect in your AI\'s connector settings before then to stay signed in.', 'agent-brain'),
				self::dateLabel(gmdate('Y-m-d H:i:s', $latest)),
				(int) max(1, (int) round(($latest - time()) / DAY_IN_SECONDS))
			);
		}

		return null;
	}

	private static function dateLabel(string $utcMysql): string {
		if ($utcMysql === '') {
			return '';
		}

		if (function_exists('mysql2date')) {
			return (string) mysql2date(get_option('date_format'), $utcMysql);
		}

		return substr($utcMysql, 0, 10);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function readAll(int $userId): array {
		if ($userId <= 0 || ! function_exists('get_user_meta')) {
			return [];
		}

		$all = get_user_meta($userId, self::META_KEY, true);

		return is_array($all) ? $all : [];
	}

	/**
	 * @param array<string, array<string, mixed>> $all
	 */
	private static function writeAll(int $userId, array $all): void {
		if ($userId <= 0 || ! function_exists('update_user_meta')) {
			return;
		}

		update_user_meta($userId, self::META_KEY, $all);
	}

	private static function key(string $clientId): string {
		return hash('sha256', $clientId);
	}
}
