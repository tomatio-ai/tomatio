<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * Unbound accounts for the people and agents of an archive (Binding),
 * written straight into the tables like the rest of a migration: a login
 * from the principal id's local part (with -2, -3 when taken), no email, a
 * random password nobody knows, no application password, the roles of the
 * archive (an administrator comes back as a curator until bound). Used by
 * a whole-brain migration (RestoreWriter) and by moving scopes (Moves).
 */
final class Placeholders {

	/**
	 * @param array<int, string> $roles
	 * @return int The new account's user id.
	 */
	public static function account(string $id, string $name, array $roles, ?string $fqdn): int {
		global $wpdb;

		$type  = (string) strtok($id, ':');
		$local = substr($id, strpos($id, ':') + 1);
		$base  = substr(trim((string) preg_replace('/[^A-Za-z0-9_.@-]+/', '-', $local), '-'), 0, 60);
		$base  = $base !== '' ? $base : $type;
		$login = $base;
		for ($n = 2; username_exists($login) !== false || self::nicenameTaken($login); $n++) {
			$suffix = '-' . $n;
			$login  = substr($base, 0, 60 - strlen($suffix)) . $suffix;
		}

		$now      = gmdate('Y-m-d H:i:s');
		$inserted = $wpdb->insert(
			$wpdb->users,
			[
				'user_login'          => $login,
				'user_pass'           => wp_hash_password(wp_generate_password(64, true, true)),
				'user_nicename'       => substr(sanitize_title($login), 0, 50),
				'user_email'          => '',
				'user_url'            => '',
				'user_registered'     => $now,
				'user_activation_key' => '',
				'user_status'         => 0,
				'display_name'        => $fqdn ?? ($name !== '' ? $name : $login),
			],
			['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
		);
		if ($inserted === false) {
			throw new ImportFailed('internal', 'A placeholder account could not be created.');
		}

		$userId = (int) $wpdb->insert_id;

		$capabilities = [];
		foreach (RestoreWriter::roles($roles) as $role) {
			$capabilities[$role] = true;
		}

		self::setUserMeta($userId, $wpdb->get_blog_prefix() . 'capabilities', $capabilities);
		self::setUserMeta($userId, $wpdb->get_blog_prefix() . 'user_level', 0);
		self::setUserMeta($userId, 'nickname', $name !== '' ? $name : $login);
		self::setUserMeta($userId, Principals::META, $id);
		self::setUserMeta($userId, Binding::META_BOUND, '0');
		sort($roles, SORT_STRING);
		self::setUserMeta($userId, Binding::META_ROLES, $roles);

		return $userId;
	}

	/** The principal registry row of an account (or of an id with no account, user id 0). */
	public static function register(string $id, int $userId, string $name, string $status, ?string $fqdn): void {
		global $wpdb;

		$now = gmdate('Y-m-d H:i:s');
		$ok  = $wpdb->insert(
			Principals::table(),
			[
				'principal_id' => $id,
				'user_id'      => $userId,
				'fqdn'         => $fqdn,
				'display_name' => function_exists('mb_substr') ? mb_substr($name, 0, 200) : substr($name, 0, 200),
				'status'       => in_array($status, [Principals::STATUS_ACTIVE, Principals::STATUS_SUSPENDED, Principals::STATUS_REMOVED], true) ? $status : Principals::STATUS_ACTIVE,
				'issued_at'    => $now,
				'removed_at'   => $status === Principals::STATUS_REMOVED ? $now : null,
			],
			['%s', '%d', '%s', '%s', '%s', '%s', '%s']
		);
		if ($ok === false) {
			throw new ImportFailed('conflict', 'A principal id of the archive is already taken on this brain.');
		}
	}

	private static function nicenameTaken(string $login): bool {
		global $wpdb;

		$nicename = substr(sanitize_title($login), 0, 50);

		return $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_nicename = %s LIMIT 1", $nicename)) !== null;
	}

	/**
	 * @param mixed $value
	 */
	private static function setUserMeta(int $userId, string $key, $value): void {
		global $wpdb;

		$wpdb->delete($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $key], ['%d', '%s']); // phpcs:ignore WordPress.DB.SlowDBQuery -- one account at a time.
		$wpdb->insert($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $key, 'meta_value' => maybe_serialize($value)], ['%d', '%s', '%s']); // phpcs:ignore WordPress.DB.SlowDBQuery -- one account at a time.
	}
}
