<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\Identity\Capabilities;
use WP_Error;
use WP_User;

/**
 * Restored principals stay unbound until an administrator binds them (PCP
 * v0.3 SPEC section 8.7.2 item 3): authority travels in the archive as
 * declarative grants (roles, partition access), credentials never do.
 *
 * A restore creates one placeholder account per principal with user meta
 * ab_principal_bound = "0", a random password nobody sees and no
 * application passwords. An unbound account cannot sign in, use an
 * application password, OAuth, Connect as or the agent key: the gates below
 * turn it away on every path WordPress and TomaTío authenticate by.
 *
 * Binding re-issues authority on this host:
 *  - a person gets an email address and a one-time link to set a password
 *    (the handover flow, in the plugin), and an administrator in the
 *    archive gets the administrator role back only now (decision 9: until
 *    then, a curator);
 *  - an agent gets a new application password, shown once; clients and
 *    OAuth connections consent again on this host.
 * Accounts without the meta (everyone who was not restored) are bound.
 */
final class Binding {

	public const META_BOUND = 'ab_principal_bound';

	/** The roles the archive gave an unbound principal, until it is bound. */
	public const META_ROLES = 'ab_pcp_roles';

	public const AUDIT_EVENT = 'principal.bind';

	public static function isUnbound(int $userId): bool {
		return $userId > 0 && get_user_meta($userId, self::META_BOUND, true) === '0';
	}

	/**
	 * The archive's roles for an unbound principal, or null.
	 *
	 * @return array<int, string>|null
	 */
	public static function archiveRoles(int $userId): ?array {
		if (! self::isUnbound($userId)) {
			return null;
		}

		$roles = get_user_meta($userId, self::META_ROLES, true);

		return is_array($roles) ? array_values(array_map('strval', $roles)) : null;
	}

	// -----------------------------------------------------------------
	// Gates
	// -----------------------------------------------------------------

	/**
	 * determine_current_user, last: whichever method found the user
	 * (cookie, application password, OAuth bearer, agent key), an unbound
	 * one is nobody.
	 *
	 * @param mixed $userId
	 * @return mixed
	 */
	public static function gateCurrentUser($userId) {
		return is_numeric($userId) && self::isUnbound((int) $userId) ? false : $userId;
	}

	/**
	 * authenticate, last: the login form and XML-RPC.
	 *
	 * @param mixed $user
	 * @return mixed
	 */
	public static function gateLogin($user) {
		if ($user instanceof WP_User && self::isUnbound((int) $user->ID)) {
			return new WP_Error('agent_brain_unbound', __('This account was migrated from another brain and is not bound yet. Ask an administrator to bind it.', 'agent-brain'));
		}

		return $user;
	}

	/**
	 * wp_is_application_passwords_available_for_user.
	 *
	 * @param mixed $available
	 * @param mixed $user
	 * @return mixed
	 */
	public static function gateApplicationPasswords($available, $user) {
		return $user instanceof WP_User && self::isUnbound((int) $user->ID) ? false : $available;
	}

	/**
	 * agent_brain/oauth_delegable_agents: nobody connects as an unbound agent.
	 *
	 * @param mixed $ids
	 * @return mixed
	 */
	public static function gateDelegation($ids) {
		if (! is_array($ids)) {
			return $ids;
		}

		return array_values(array_filter($ids, static fn ($id): bool => ! self::isUnbound((int) $id)));
	}

	// -----------------------------------------------------------------
	// Listing and binding
	// -----------------------------------------------------------------

	/**
	 * Every principal in the registry, as the management API's
	 * GET /principals shows them: context.json fields plus bound.
	 *
	 * @return array<int, array{id:string, display_name:string, status:string, type:string, roles:array<int, string>, bound:bool, user_id:int}>
	 */
	public static function principals(): array {
		$out = [];
		foreach (Principals::all() as $row) {
			$userId = $row['user_id'];
			$user   = $userId > 0 && $row['status'] !== Principals::STATUS_REMOVED ? get_userdata($userId) : false;
			$roles  = [];
			if ($user instanceof WP_User) {
				$roles = self::archiveRoles($userId) ?? array_values(array_map('strval', (array) $user->roles));
				sort($roles, SORT_STRING);
			}

			$out[] = [
				'id'           => $row['principal_id'],
				'display_name' => $row['display_name'],
				'status'       => $row['status'],
				'type'         => (string) strtok($row['principal_id'], ':'),
				'roles'        => $roles,
				'bound'        => ! ($user instanceof WP_User && self::isUnbound($userId)),
				'user_id'      => $user instanceof WP_User ? $userId : 0,
			];
		}

		usort($out, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

		return $out;
	}

	/**
	 * Binds an unbound principal. People need an email address; the
	 * set-password link is mailed to it (and returned, so the administrator
	 * can pass it on when mail fails). Agents get a new application
	 * password, returned once.
	 *
	 * @return array{principal:string, type:string, link?:string, mailed?:bool, application_password?:string}
	 * @throws ImportFailed
	 */
	public static function bind(string $principalId, int $byUserId, string $email = '', bool $sendMail = true): array {
		$userId = self::userFor($principalId);
		if ($userId === null) {
			throw new ImportFailed('not_found', 'No such principal on this brain.');
		}
		if (! self::isUnbound($userId)) {
			throw new ImportFailed('conflict', 'This principal is already bound.');
		}

		$user = get_userdata($userId);
		if (! $user instanceof WP_User) {
			throw new ImportFailed('not_found', 'The account of this principal is gone.');
		}

		$type   = str_starts_with($principalId, 'agent:') ? 'agent' : 'human';
		$result = ['principal' => $principalId, 'type' => $type];

		if ($type === 'human') {
			$email = sanitize_email($email);
			if ($email === '' || ! is_email($email)) {
				throw new ImportFailed('invalid_input', 'Binding a person needs a valid email address.');
			}
			$owner = email_exists($email);
			if ($owner !== false && (int) $owner !== $userId) {
				throw new ImportFailed('conflict', 'Another account already uses this email address.');
			}

			$updated = wp_update_user(['ID' => $userId, 'user_email' => $email]);
			if ($updated instanceof WP_Error) {
				throw new ImportFailed('internal', 'The email address could not be saved.');
			}
		}

		$roles = self::archiveRoles($userId) ?? [];
		self::markBound($userId);

		// Decision 9: an administrator in the archive is one again only now.
		if (in_array('administrator', $roles, true)) {
			$user = new WP_User($userId);
			$user->set_role('administrator');
		}

		if ($type === 'human') {
			$user = get_userdata($userId);
			$key  = $user instanceof WP_User ? get_password_reset_key($user) : new WP_Error('no_user');
			if ($key instanceof WP_Error || ! $user instanceof WP_User) {
				throw new ImportFailed('internal', 'A set-password link could not be made.');
			}

			$link           = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login), 'login');
			$result['link'] = $link;
			$result['mailed'] = $sendMail && self::mail($user, $link);
		} else {
			// Named after the agent's FQDN, as at registration (an agent
			// without an ID keeps the restore's generic name).
			$fqdn    = AgentIssuer::fqdnFor($userId);
			$created = \WP_Application_Passwords::create_new_application_password($userId, ['name' => $fqdn ?? 'TomaTío (bound after migration)']);
			if ($created instanceof WP_Error) {
				throw new ImportFailed('internal', 'An application password could not be created.');
			}
			$result['application_password'] = (string) $created[0];
		}

		do_action('agent_brain/audit', [
			'actor_id'    => max(0, $byUserId),
			'event'       => self::AUDIT_EVENT,
			'status'      => 'ok',
			'object_type' => 'user',
			'object_id'   => (string) $userId,
		]);

		return $result;
	}

	/** The live account holding a principal id, or null. */
	public static function userFor(string $principalId): ?int {
		foreach (Principals::all() as $row) {
			if ($row['principal_id'] === $principalId && $row['user_id'] > 0 && $row['status'] !== Principals::STATUS_REMOVED) {
				return get_userdata($row['user_id']) instanceof WP_User ? $row['user_id'] : null;
			}
		}

		return null;
	}

	private static function markBound(int $userId): void {
		delete_user_meta($userId, self::META_BOUND);
		delete_user_meta($userId, self::META_ROLES);
		clean_user_cache($userId);
	}

	private static function mail(WP_User $user, string $link): bool {
		$site = wp_specialchars_decode((string) get_option('blogname', ''), ENT_QUOTES);

		$subject = sprintf(
			/* translators: %s: site title. */
			__('[%s] Your account on this brain', 'agent-brain'),
			$site
		);
		$body = sprintf(
			/* translators: 1: site title, 2: login name, 3: link. */
			__("Your account on %1\$s was moved to this server.\n\nLogin: %2\$s\n\nSet your password here (the link works once):\n%3\$s\n", 'agent-brain'),
			$site,
			$user->user_login,
			$link
		);

		return (bool) wp_mail($user->user_email, $subject, $body);
	}

	/** Whether $userId may bind principals: administrators (decision 6). */
	public static function canBind(int $userId): bool {
		return Exports::canExport($userId) && user_can($userId, Capabilities::CAP_MANAGE);
	}
}
