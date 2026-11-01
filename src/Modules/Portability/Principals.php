<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\AgentId\Fqdn;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\Cpts;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\OAuth\Delegation;
use WP_User;

/**
 * Stable principal ids (PCP v0.3 SPEC section 1.3) for the people and agents
 * of this brain.
 *
 * Built on the agent identity of the AgentId module (docs/rfc/agent-id-fqdn.md):
 * a pure agent's id is "agent:" plus the first label of its FQDN, its name and
 * register id ("agent:claude-code-a3f2c819"), so the id carries neither the
 * brain's host nor a product name and survives a move to another address. An
 * agent that has no FQDN (issuing turned off) gets "agent:<login>". Everyone
 * else who takes part (an TomaTío role, ab_manage, or a scope grant) is
 * "human:<login>". The id is set once, in user meta ab_principal_id, and never
 * derived again: a role change, a new FQDN or a renamed login never moves it.
 *
 * The registry table ab_principals keeps one row per id ever issued, so:
 *  - an id is never issued twice: a new login whose id is reserved is refused
 *    (illegal_user_logins), and a clash found later gets a "-2" suffix;
 *  - a deleted user stays resolvable, with status removed, for the rows and
 *    audit entries that still name them;
 *  - each agent's full FQDN sits beside its id, and its status follows the
 *    agent's AgentMeta status (pending and suspended are suspended, revoked
 *    is removed).
 * An FQDN is held by one principal for good (unique index; AgentIssuer also
 * refuses to issue one the registry holds, removed users included).
 * The stored name is the nickname (else the login), never display_name, which
 * the agent-ID issuer replaces with the FQDN. Logins are never renamed.
 */
final class Principals {

	public const META             = 'ab_principal_id';
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_SUSPENDED = 'suspended';
	public const STATUS_REMOVED   = 'removed';

	private const MAX_ATTEMPTS = 100;

	/** True while this class writes META, which the meta guard otherwise refuses. */
	private static bool $writing = false;

	/** @var array<int, true> Agents whose id waits for their FQDN this request. */
	private static array $waiting = [];

	/** The login wp_insert_user() is about to use, for reservedLogins(). */
	private static ?string $pendingLogin = null;

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'ab_principals';
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// ascii_general_ci: no two ids may differ only in case, matching
		// WordPress logins, which are unique regardless of case.
		return "CREATE TABLE {$table} (
			principal_id VARCHAR(140) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			fqdn VARCHAR(255) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
			display_name VARCHAR(200) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			issued_at DATETIME NOT NULL,
			removed_at DATETIME NULL,
			PRIMARY KEY  (principal_id),
			KEY user_id (user_id),
			UNIQUE KEY fqdn (fqdn)
		) ENGINE=InnoDB {$charset};";
	}

	/** Option: duplicate FQDNs that kept the fqdn index from becoming UNIQUE. */
	public const OPTION_FQDN_DUPLICATES = 'agent_brain_principal_fqdn_duplicates';

	/**
	 * Upgrades the plain KEY on fqdn to UNIQUE (installs from before the
	 * constraint). Idempotent. When non-null FQDNs repeat, the index stays as
	 * it is and the duplicates are recorded for an admin notice instead of
	 * failing the migration.
	 *
	 * @param string|null $table Defaults to the registry; a name is for tests.
	 * @return bool True when the index is UNIQUE afterwards.
	 */
	public static function upgradeFqdnIndex(?string $table = null): bool {
		global $wpdb;

		$table ??= self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no values.
		$index = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'fqdn'", ARRAY_A);
		if (is_array($index) && $index !== [] && (int) $index[0]['Non_unique'] === 0) {
			delete_option(self::OPTION_FQDN_DUPLICATES);

			return true;
		}

		$dupes = $wpdb->get_col("SELECT fqdn FROM {$table} WHERE fqdn IS NOT NULL AND fqdn <> '' GROUP BY fqdn HAVING COUNT(*) > 1");
		if (is_array($dupes) && $dupes !== []) {
			update_option(self::OPTION_FQDN_DUPLICATES, array_values(array_map('strval', $dupes)), false);

			return false;
		}

		// Empty strings are not FQDNs; NULL is what the unique index allows many of.
		$wpdb->query("UPDATE {$table} SET fqdn = NULL WHERE fqdn = ''");
		if (is_array($index) && $index !== []) {
			$wpdb->query("ALTER TABLE {$table} DROP INDEX fqdn, ADD UNIQUE KEY fqdn (fqdn)");
		} else {
			$wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY fqdn (fqdn)");
		}
		// phpcs:enable
		delete_option(self::OPTION_FQDN_DUPLICATES);

		return true;
	}

	/** admin_notices: the registry holds the same FQDN twice. */
	public static function renderDuplicateNotice(): void {
		$dupes = get_option(self::OPTION_FQDN_DUPLICATES, []);
		if (! is_array($dupes) || $dupes === [] || ! current_user_can('manage_options')) {
			return;
		}

		printf(
			'<div class="notice notice-warning" role="alert"><p>%s</p></div>',
			esc_html(sprintf(
				'TomaTío: the principal registry holds these agent IDs more than once, so they cannot be made unique yet: %s. Fix the duplicates, then re-run the migration.',
				implode(', ', array_map('strval', $dupes))
			))
		);
	}

	/**
	 * The registry row that holds this FQDN (case, whitespace and a trailing
	 * dot do not matter), or null. Includes removed users: an FQDN stays
	 * reserved after its user is gone.
	 *
	 * @return array{principal_id:string, user_id:int, fqdn:?string, display_name:string, status:string, issued_at:string, removed_at:?string}|null
	 */
	public static function byFqdn(string $fqdn): ?array {
		$fqdn = Fqdn::normalize($fqdn);
		if ($fqdn === '') {
			return null;
		}

		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT principal_id, user_id, fqdn, display_name, status, issued_at, removed_at FROM {$table} WHERE fqdn = %s LIMIT 1",
			[$fqdn]
		);
		// phpcs:enable

		return $rows === [] ? null : self::shape($rows[0]);
	}

	/**
	 * The FQDN recorded for the user's principal, or null (humans, agents
	 * without one, unknown users). Works for deleted users.
	 */
	public static function fqdnFor(int $userId): ?string {
		$row = $userId > 0 ? self::rowForUser($userId) : null;

		return $row !== null && is_string($row['fqdn']) && $row['fqdn'] !== '' ? $row['fqdn'] : null;
	}

	/**
	 * What an export names a principal by. Null when the user has no
	 * principal id. Reads only.
	 *
	 * @return array{id:string, fqdn:?string, display_name:string}|null
	 */
	public static function exportIdentity(int $userId): ?array {
		$row = $userId > 0 ? self::rowForUser($userId) : null;
		if ($row === null) {
			return null;
		}

		return [
			'id'           => (string) $row['principal_id'],
			'fqdn'         => is_string($row['fqdn']) && $row['fqdn'] !== '' ? $row['fqdn'] : null,
			'display_name' => (string) $row['display_name'],
		];
	}

	/**
	 * The user's principal id, or null when none was issued. Never issues
	 * one. Works for deleted users through the registry.
	 */
	public static function idFor(int $userId): ?string {
		if ($userId <= 0) {
			return null;
		}

		$row = self::rowForUser($userId);

		return $row !== null ? (string) $row['principal_id'] : null;
	}

	/**
	 * The user's principal id, issuing it first when the user takes part in
	 * the brain (or always, with $force). Null for unknown users.
	 */
	public static function ensure(int $userId, bool $force = false): ?string {
		$existing = self::idFor($userId);
		$user     = $userId > 0 ? get_userdata($userId) : false;

		if ($existing !== null) {
			if ($user instanceof WP_User) {
				self::sync($user, $existing);
			}

			return $existing;
		}

		if (! $user instanceof WP_User || (! $force && ! self::qualifies($user))) {
			return null;
		}

		// AgentProposals and AgentRegistration create the account first and
		// issue its FQDN right after; the id waits for the FQDN (onUserMeta).
		if (! $force && AgentIssuer::autoIssueDeferred() && self::typeFor($user) === 'agent') {
			return null;
		}

		$id = self::adoptMeta($user) ?? self::issue($user);
		if ($id !== null) {
			self::sync($user, $id);
		}

		return $id;
	}

	/**
	 * Takes part in the brain: an TomaTío role, ab_manage (so every
	 * administrator), or a read or write scope grant.
	 */
	public static function qualifies(WP_User $user): bool {
		$roles = (array) $user->roles;
		if (array_intersect([Capabilities::ROLE_AGENT, Capabilities::ROLE_CURATOR], $roles) !== []) {
			return true;
		}

		if (user_can($user, Capabilities::CAP_MANAGE)) {
			return true;
		}

		foreach ([Scopes::META_READ_SCOPES, Scopes::META_WRITE_SCOPES] as $key) {
			$scopes = get_user_meta($user->ID, $key, true);
			if (is_array($scopes) && $scopes !== []) {
				return true;
			}
		}

		return false;
	}

	/**
	 * "agent" for a pure agent (role ab_agent only, not a person), "human"
	 * for everyone else, matching Delegation and the agent-ID issuer.
	 */
	public static function typeFor(WP_User $user): string {
		return Delegation::isPureAgent($user) ? 'agent' : 'human';
	}

	/**
	 * Registry status from the agent's AgentMeta status. People, and agents
	 * without one, are active.
	 */
	public static function statusFor(int $userId): string {
		return match (AgentIssuer::statusOf($userId)) {
			AgentMeta::STATUS_PENDING, AgentMeta::STATUS_SUSPENDED => self::STATUS_SUSPENDED,
			AgentMeta::STATUS_REVOKED                               => self::STATUS_REMOVED,
			default                                                 => self::STATUS_ACTIVE,
		};
	}

	/**
	 * The name the registry keeps: the nickname, else the login. Never
	 * display_name, which holds the FQDN for issued agents.
	 */
	public static function nameFor(WP_User $user): string {
		$nickname = trim((string) get_user_meta($user->ID, 'nickname', true));

		return $nickname !== '' ? $nickname : (string) $user->user_login;
	}

	/**
	 * True when a new account with this login would get an id that was
	 * already issued, of either type, to anyone (including removed users).
	 */
	public static function loginReserved(string $login): bool {
		$local = Names::localId($login);
		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT principal_id FROM {$table} WHERE principal_id IN (%s, %s) LIMIT 1",
			['human:' . $local, 'agent:' . $local]
		);
		// phpcs:enable

		return $rows !== [];
	}

	/**
	 * Every registry row, oldest first.
	 *
	 * @return array<int, array{principal_id:string, user_id:int, fqdn:?string, display_name:string, status:string, issued_at:string, removed_at:?string}>
	 */
	public static function all(): array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no values.
		$rows = Db::getResults("SELECT principal_id, user_id, fqdn, display_name, status, issued_at, removed_at FROM {$table} ORDER BY issued_at ASC, principal_id ASC");

		return array_map([self::class, 'shape'], $rows);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{principal_id:string, user_id:int, fqdn:?string, display_name:string, status:string, issued_at:string, removed_at:?string}
	 */
	private static function shape(array $row): array {
		return [
			'principal_id' => (string) $row['principal_id'],
			'user_id'      => (int) $row['user_id'],
			'fqdn'         => $row['fqdn'] !== null && $row['fqdn'] !== '' ? (string) $row['fqdn'] : null,
			'display_name' => (string) $row['display_name'],
			'status'       => (string) $row['status'],
			'issued_at'    => (string) $row['issued_at'],
			'removed_at'   => $row['removed_at'] !== null ? (string) $row['removed_at'] : null,
		];
	}

	/**
	 * Registers a non-user principal (the controller) once. Never changes an
	 * existing row.
	 */
	public static function reserve(string $id, string $displayName): void {
		if (! Names::isPrincipal($id)) {
			return;
		}

		self::insertRow($id, 0, $displayName);
	}

	/**
	 * Backfill: every user who takes part, and every user that memory rows
	 * or knowledge and procedure posts name, in id order.
	 *
	 * @return int Number of ids issued.
	 */
	public static function backfill(): int {
		$issued = 0;
		$named  = self::referencedUserIds();
		$offset = 0;

		do {
			// Paged by offset: stable here because nothing is deleted.
			$ids = get_users([
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 500,
				'offset'  => $offset,
			]);
			$offset += count($ids);

			foreach ($ids as $id) {
				$id  = (int) $id;
				$had = self::idFor($id) !== null;
				if (self::ensure($id, isset($named[$id])) !== null && ! $had) {
					++$issued;
				}
			}
		} while (count($ids) === 500);

		return $issued;
	}

	/**
	 * Removes the registry row of an account that never acted, so undoing a
	 * half-created agent (AgentRegistration) does not reserve its id. Rows
	 * stay for anyone who wrote memory or posts.
	 */
	public static function discardUnused(int $userId): void {
		if ($userId <= 0 || isset(self::referencedUserIds([$userId])[$userId])) {
			return;
		}

		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query(Db::prepare("DELETE FROM {$table} WHERE user_id = %d", [$userId]));
		// phpcs:enable

		self::$writing = true;
		try {
			delete_user_meta($userId, self::META);
		} finally {
			self::$writing = false;
		}
	}

	// -----------------------------------------------------------------
	// Hooks
	// -----------------------------------------------------------------

	public static function onUserChanged(int $userId): void {
		self::ensure($userId);
	}

	/**
	 * set_user_role and add_user_role: every role change, including the one
	 * wp_insert_user() makes while creating the account. A new agent whose
	 * FQDN is still to come waits for it (onUserMeta), for user_register, or
	 * at the latest for the end of the request, so its id never falls back to
	 * the login only because the hooks ran in a different order.
	 */
	public static function onRoleChanged(int $userId): void {
		$user = get_userdata($userId);
		if ($user instanceof WP_User && self::idFor($userId) === null && self::waitsForFqdn($user)) {
			if (self::$waiting === []) {
				add_action('shutdown', [self::class, 'ensureWaiting']);
			}
			self::$waiting[$userId] = true;

			return;
		}

		self::ensure($userId);
	}

	/** shutdown: agents that never got an FQDN get their id from the login. */
	public static function ensureWaiting(): void {
		$waiting       = array_keys(self::$waiting);
		self::$waiting = [];
		foreach ($waiting as $userId) {
			self::ensure((int) $userId);
		}
	}

	/**
	 * added_user_meta / updated_user_meta: a scope grant makes a person take
	 * part even without an TomaTío role; an issued FQDN gives a waiting
	 * agent its id; FQDN, status and nickname changes reach the registry.
	 *
	 * @param mixed $value
	 */
	public static function onUserMeta(int $metaId, int $userId, string $key, $value): void {
		unset($metaId, $value);
		$watched = [Scopes::META_READ_SCOPES, Scopes::META_WRITE_SCOPES, AgentMeta::FQDN, AgentMeta::STATUS, 'nickname'];
		if (in_array($key, $watched, true)) {
			self::ensure($userId);
		}
	}

	/** profile_update: keep the registry's name current. */
	public static function onProfileUpdate(int $userId): void {
		self::ensure($userId);
	}

	/**
	 * delete_user (before the account goes): make sure anyone the brain's
	 * rows name has an id that will outlive the account.
	 */
	public static function beforeDelete(int $userId): void {
		self::ensure($userId, isset(self::referencedUserIds([$userId])[$userId]));
	}

	/** deleted_user and remove_user_from_blog: the id stays, as removed. */
	public static function onDeleted(int $userId): void {
		global $wpdb;
		$table = self::table();
		// Quiet: the uninstall routine drops this table before it deletes anything.
		$quiet = $wpdb->suppress_errors(true);
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query(Db::prepare(
			"UPDATE {$table} SET status = %s, removed_at = %s WHERE user_id = %d AND status = %s",
			[self::STATUS_REMOVED, gmdate('Y-m-d H:i:s'), $userId, self::STATUS_ACTIVE]
		));
		// phpcs:enable
		$wpdb->suppress_errors($quiet);
	}

	public static function onRemovedFromBlog(int $userId, int $blogId): void {
		if ($blogId === (int) get_current_blog_id()) {
			self::onDeleted($userId);
		}
	}

	/** pre_user_login, last: remember the login wp_insert_user() will check. */
	public static function rememberLogin(string $login): string {
		self::$pendingLogin = $login;

		return $login;
	}

	/**
	 * illegal_user_logins: refuse a new account whose principal id was
	 * already issued. Existing accounts (updates) are never refused.
	 *
	 * @param mixed $logins
	 * @return array<int, string>
	 */
	public static function reservedLogins($logins): array {
		$logins = is_array($logins) ? array_values(array_map('strval', $logins)) : [];
		$login  = self::$pendingLogin;
		self::$pendingLogin = null;

		if ($login === null || $login === '' || username_exists($login) !== false) {
			return $logins;
		}

		if (self::loginReserved($login)) {
			$logins[] = $login;
		}

		return $logins;
	}

	/**
	 * add_user_metadata / update_user_metadata / delete_user_metadata: the
	 * id is written only by this class, so it cannot be changed afterwards.
	 *
	 * @param mixed $check
	 * @param mixed $objectId
	 * @param mixed $metaKey
	 * @return mixed
	 */
	public static function guardMeta($check, $objectId, $metaKey) {
		unset($objectId);

		return $metaKey === self::META && ! self::$writing ? false : $check;
	}

	// -----------------------------------------------------------------
	// Internals
	// -----------------------------------------------------------------

	private static function issue(WP_User $user): ?string {
		$base = self::baseFor($user);

		for ($n = 1; $n <= self::MAX_ATTEMPTS; $n++) {
			$candidate = Names::principalCandidate($base, $n);
			$inserted  = self::insertRow($candidate, (int) $user->ID, self::nameFor($user));

			if ($inserted === null) {
				return null;
			}

			if ($inserted) {
				self::writeMeta((int) $user->ID, $candidate);

				return $candidate;
			}
		}

		return null;
	}

	/**
	 * @return bool|null True when inserted, false when the id is taken, null on a database error.
	 */
	private static function insertRow(string $id, int $userId, string $displayName): ?bool {
		global $wpdb;

		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$result = $wpdb->query(Db::prepare(
			"INSERT IGNORE INTO {$table} (principal_id, user_id, display_name, status, issued_at) VALUES (%s, %d, %s, %s, %s)",
			[$id, $userId, self::clip($displayName), self::STATUS_ACTIVE, gmdate('Y-m-d H:i:s')]
		));
		// phpcs:enable

		if ($result === false) {
			return null;
		}

		return (int) $result === 1;
	}

	/**
	 * User meta is shared by every site of a multisite network, while the
	 * registry is per site: a user who already holds an id on another site
	 * keeps it here when it is free, so one person has one id.
	 */
	private static function adoptMeta(WP_User $user): ?string {
		$meta = get_user_meta($user->ID, self::META, true);
		if (! is_string($meta) || ! Names::isPrincipal($meta)) {
			return null;
		}

		return self::insertRow($meta, (int) $user->ID, self::nameFor($user)) === true ? $meta : null;
	}

	/**
	 * A pure agent without an FQDN yet, on a brain that issues them.
	 */
	private static function waitsForFqdn(WP_User $user): bool {
		return self::typeFor($user) === 'agent'
			&& AgentIssuer::fqdnFor((int) $user->ID) === null
			&& (AgentIssuer::autoIssueDeferred() || (bool) apply_filters('agent_brain/auto_issue_agent_ids', true));
	}

	/**
	 * The first candidate: "agent:" and the FQDN's first label for an issued
	 * agent, else the type and the login.
	 */
	private static function baseFor(WP_User $user): string {
		$type   = self::typeFor($user);
		$source = (string) $user->user_login;

		if ($type === 'agent') {
			$fqdn  = AgentIssuer::fqdnFor((int) $user->ID);
			$label = $fqdn !== null ? Fqdn::login($fqdn) : null;
			if ($label !== null && $label !== '') {
				$source = $label;
			}
		}

		return $type . ':' . Names::localId($source);
	}

	/**
	 * A live user with an id: the meta mirrors the registry, and the row's
	 * FQDN, status and name follow the account. A user who came back to the
	 * site (multisite) is active again unless their agent ID was revoked.
	 */
	private static function sync(WP_User $user, string $id): void {
		$meta = get_user_meta($user->ID, self::META, true);
		if ($meta !== $id) {
			self::writeMeta((int) $user->ID, $id);
		}

		if (is_multisite() && ! is_user_member_of_blog((int) $user->ID)) {
			return;
		}

		$status = self::statusFor((int) $user->ID);
		$fqdn   = AgentIssuer::fqdnFor((int) $user->ID);

		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$wpdb->query(Db::prepare(
			"UPDATE {$table}
				SET status = %s,
					removed_at = IF(%s = %s, COALESCE(removed_at, %s), NULL),
					fqdn = COALESCE(NULLIF(%s, ''), fqdn),
					display_name = %s
				WHERE principal_id = %s AND user_id = %d",
			[$status, $status, self::STATUS_REMOVED, gmdate('Y-m-d H:i:s'), (string) $fqdn, self::clip(self::nameFor($user)), $id, (int) $user->ID]
		));
		// phpcs:enable
	}

	/**
	 * On a network the meta is shared, so it keeps the first site's id and
	 * this site's registry stays authoritative for its own.
	 */
	private static function writeMeta(int $userId, string $id): void {
		$current = get_user_meta($userId, self::META, true);
		if (is_multisite() && is_string($current) && $current !== '') {
			return;
		}

		self::$writing = true;
		try {
			update_user_meta($userId, self::META, $id);
		} finally {
			self::$writing = false;
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function rowForUser(int $userId): ?array {
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT principal_id, fqdn, display_name FROM {$table} WHERE user_id = %d ORDER BY issued_at ASC LIMIT 1",
			[$userId]
		);
		// phpcs:enable

		return $rows[0] ?? null;
	}

	/**
	 * User ids that memory rows (author or via) or knowledge and procedure
	 * posts (author) name; limited to $only when given.
	 *
	 * @param int[]|null $only
	 * @return array<int, true>
	 */
	private static function referencedUserIds(?array $only = null): array {
		global $wpdb;

		$memory = MemoryRepository::memoryTable();
		$types  = Cpts::postTypes();
		$filter = '';
		$params = [];

		if ($only !== null) {
			$only = array_values(array_filter(array_map('intval', $only), static fn (int $id): bool => $id > 0));
			if ($only === []) {
				return [];
			}
			$filter = ' IN (' . implode(', ', array_fill(0, count($only), '%d')) . ')';
		}

		$sql = "SELECT DISTINCT agent_id AS user_id FROM {$memory}" . ($filter !== '' ? " WHERE agent_id{$filter}" : '')
			. " UNION SELECT DISTINCT via FROM {$memory} WHERE via IS NOT NULL" . ($filter !== '' ? " AND via{$filter}" : '')
			. " UNION SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type IN (%s, %s)" . ($filter !== '' ? " AND post_author{$filter}" : '');

		if ($only !== null) {
			$params = array_merge($only, $only, $types, $only);
		} else {
			$params = $types;
		}

		$out = [];
		foreach (Db::getResults($sql, $params) as $row) {
			$id = (int) $row['user_id'];
			if ($id > 0) {
				$out[$id] = true;
			}
		}

		return $out;
	}

	private static function clip(string $text): string {
		return function_exists('mb_substr') ? mb_substr($text, 0, 200) : substr($text, 0, 200);
	}
}
