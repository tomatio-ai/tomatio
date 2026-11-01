<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

use AgentBrain\Core\Db;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Portability\Partitions;

/**
 * The team scope a new brain starts with (issue #182). Without it, every
 * agent write and proposal on a fresh install has nowhere to go until an
 * admin creates a scope and grants it.
 *
 * Created once per brain, by the Identity migration, and only when the
 * brain has no scope at all. The option records the outcome (the term id,
 * or 0 when scopes already existed), so deleting the scope later never
 * brings it back. Administrators and curators get read and write on it,
 * then and when they are added later; agents get it while it is the only
 * scope (read only for the observer class).
 *
 * The agent_brain/create_default_scope filter turns it off (the
 * integration suite does, so other tests start with no scope).
 */
final class DefaultScope {

	public const OPTION = 'ab_default_scope';

	public static function enabled(): bool {
		return (bool) apply_filters('agent_brain/create_default_scope', true);
	}

	/**
	 * Migration entry point. Taxonomy and the other modules' hooks are only
	 * ready once init has run, so a migration that runs at plugins_loaded
	 * waits for it.
	 */
	public static function migrate(): void {
		if (did_action('init') > 0) {
			self::ensure();

			return;
		}

		add_action('init', static function (): void {
			self::ensure();
		}, 20, 0);
	}

	/** The default scope's term id, or null when there is none (never created, or deleted). */
	public static function id(): ?int {
		$id = (int) get_option(self::OPTION, 0);

		return $id > 0 && self::isScopeTerm($id) ? $id : null;
	}

	/**
	 * Creates the scope when the brain has none, names its partition and
	 * grants it to every administrator and curator. Returns the term id, or
	 * null when nothing was created.
	 */
	public static function ensure(): ?int {
		if (! self::enabled() || get_option(self::OPTION, null) !== null) {
			return self::id();
		}

		if (self::scopeCount() > 0) {
			update_option(self::OPTION, 0, false);

			return null;
		}

		if (! taxonomy_exists(Scopes::TAXONOMY)) {
			( new Module() )->registerScopeTaxonomy();
		}

		$name   = self::name();
		$result = wp_insert_term($name, Scopes::TAXONOMY);
		if (is_wp_error($result)) {
			return null;
		}

		$termId = (int) $result['term_id'];
		// The same naming every new scope gets (created_ab_scope); a no-op
		// when that hook already ran.
		Partitions::assign($termId);
		update_option(self::OPTION, $termId, false);

		do_action('agent_brain/audit', [
			'actor_id'    => 0,
			'event'       => 'scope.created',
			'status'      => 'ok',
			'object_type' => Scopes::TAXONOMY,
			'object_id'   => (string) $termId,
			'meta'        => ['name' => $name, 'default' => true],
		]);

		$people = get_users([
			'role__in' => ['administrator', Capabilities::ROLE_CURATOR],
			'fields'   => 'ID',
		]);
		foreach ($people as $userId) {
			self::grant((int) $userId, $termId, true);
		}

		return $termId;
	}

	/**
	 * user_register, set_user_role, add_user_role (after the AgentId module,
	 * so an agent's class is known): administrators and curators always get
	 * the default scope; agents only while it is the only scope.
	 */
	public static function onUserChanged(int $userId): void {
		if (! self::enabled()) {
			return;
		}

		$scopeId = self::id();
		$user    = get_userdata($userId);
		if ($scopeId === null || ! $user instanceof \WP_User) {
			return;
		}

		$roles = (array) $user->roles;
		if (in_array('administrator', $roles, true) || in_array(Capabilities::ROLE_CURATOR, $roles, true)) {
			self::grant($userId, $scopeId, true);

			return;
		}

		if (in_array(Capabilities::ROLE_AGENT, $roles, true) && self::scopeCount() === 1) {
			$observer = (string) get_user_meta($userId, AgentMeta::CLASS_NAME, true) === AgentMeta::CLASS_OBSERVER;
			self::grant($userId, $scopeId, ! $observer);
		}
	}

	/**
	 * A restore needs an empty brain and must leave exactly the archive's
	 * scopes, so it removes the default scope (and every grant of it) first.
	 * It is not created again afterwards.
	 */
	public static function removeForRestore(): void {
		$scopeId = self::id();
		update_option(self::OPTION, 0, false);
		if ($scopeId === null) {
			return;
		}

		$scopes = new Scopes();
		foreach ([Scopes::META_READ_SCOPES, Scopes::META_WRITE_SCOPES] as $metaKey) {
			foreach (get_users(['meta_key' => $metaKey, 'fields' => 'ID']) as $userId) {
				$userId = (int) $userId;
				$ids    = $metaKey === Scopes::META_READ_SCOPES ? $scopes->readScopeIds($userId) : $scopes->writeScopeIds($userId);
				if (in_array($scopeId, $ids, true)) {
					update_user_meta($userId, $metaKey, array_values(array_diff($ids, [$scopeId])));
				}
			}
		}

		if (! taxonomy_exists(Scopes::TAXONOMY)) {
			( new Module() )->registerScopeTaxonomy();
		}
		wp_delete_term($scopeId, Scopes::TAXONOMY);
	}

	private static function grant(int $userId, int $scopeId, bool $write): void {
		$scopes = new Scopes();
		if (! $scopes->canRead($userId, $scopeId)) {
			$scopes->grantBySystem($userId, 'read', $scopeId);
		}
		if ($write && ! $scopes->canWrite($userId, $scopeId)) {
			$scopes->grantBySystem($userId, 'write', $scopeId);
		}
	}

	private static function name(): string {
		$site = trim(wp_strip_all_tags((string) get_bloginfo('name')));

		/* translators: %s: the site name. */
		return $site !== '' ? sprintf(__('%s team', 'agent-brain'), $site) : __('Team', 'agent-brain');
	}

	/** SQL, not get_terms(): the taxonomy may not be registered yet. */
	private static function scopeCount(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT COUNT(*) AS n FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
			[Scopes::TAXONOMY]
		);
		// phpcs:enable

		return isset($rows[0]) ? (int) $rows[0]['n'] : 0;
	}

	private static function isScopeTerm(int $termId): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$rows = Db::getResults(
			"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s LIMIT 1",
			[$termId, Scopes::TAXONOMY]
		);
		// phpcs:enable

		return $rows !== [];
	}
}
