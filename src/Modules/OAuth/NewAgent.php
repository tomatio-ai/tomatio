<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Core\Db;
use AgentBrain\Modules\Identity\AgentProposals;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Portability\Principals;
use WP_Error;

/**
 * "Connect as a new agent" on the consent screen (#807): the person
 * approving an OAuth request on the fixed-agent address names a new agent,
 * and approving makes it and issues the code for it, so the whole token
 * family is that agent's. A team with many bots (each its own connector in
 * an AI app) gets one agent per bot with one consent click each, instead of
 * making every agent by hand first.
 *
 * Boundaries, all checked on the server when the form is posted:
 *  - Only a person who manages the brain and may grant scopes (ab_manage
 *    and ab_grant_scopes, the right a registration key needs) may create.
 *  - The agent's read and write scopes must be within the person's own.
 *    The form starts with the person's write scopes, the default an agent
 *    proposal has. An observer gets no write scopes.
 *  - Every class only drafts and proposes: an agent never approves
 *    knowledge, publishes or grants (Capabilities). The delegated-authority
 *    box stays a separate, unticked choice.
 *  - The name is unique: an agent, a person or a waiting proposal with the
 *    same name is a clash, refused with a message that says so.
 *  - The agent, its agent ID (and its `agent.issue` audit row), its scopes
 *    and the authorization code are written in ONE database transaction,
 *    like a registration key (#236): any failure leaves no agent and no
 *    code. A named lock keeps two consents from taking the same name.
 */
final class NewAgent {

	/** The value of the Delegation::FIELD radio that picks this option. */
	public const CHOICE = 'new';

	public const FIELD_NAME  = 'ab_new_agent_name';
	public const FIELD_CLASS = 'ab_new_agent_class';
	public const FIELD_READ  = 'ab_new_agent_read';
	public const FIELD_WRITE = 'ab_new_agent_write';

	/** The class the form starts with. */
	public const DEFAULT_CLASS = AgentMeta::CLASS_EXECUTOR;

	private const LOCK_WAIT = 5;

	/**
	 * Whether $userId may create an agent on the consent screen.
	 * `agent_brain/oauth_can_create_agent` ( bool $can, int $userId ) can
	 * narrow it (or widen it, at the site's own risk).
	 */
	public static function canCreate(int $userId): bool {
		$can = $userId > 0
			&& user_can($userId, Capabilities::CAP_MANAGE)
			&& user_can($userId, Capabilities::CAP_GRANT_SCOPES)
			&& ! Delegation::isPureAgent(new \WP_User($userId));

		return (bool) apply_filters('agent_brain/oauth_can_create_agent', $can, $userId);
	}

	/**
	 * The new agent's fields as the consent form posts them (slashed).
	 *
	 * @param array<string, mixed> $source
	 * @return array{name:string, class:string, read:int[], write:int[]}
	 */
	public static function readFields(array $source): array {
		return [
			'name'  => isset($source[self::FIELD_NAME]) ? trim(sanitize_text_field((string) wp_unslash($source[self::FIELD_NAME]))) : '',
			'class' => isset($source[self::FIELD_CLASS]) ? sanitize_key((string) wp_unslash($source[self::FIELD_CLASS])) : self::DEFAULT_CLASS,
			'read'  => self::ids($source[self::FIELD_READ] ?? []),
			'write' => self::ids($source[self::FIELD_WRITE] ?? []),
		];
	}

	/**
	 * What the consent form offers: the classes and the person's scopes,
	 * with the defaults ticked, or, after a refused attempt ($prefill),
	 * what the person posted, with this option chosen.
	 *
	 * @param array{name:string, class:string, read:int[], write:int[]}|null $prefill
	 * @return array{name:string, selected:bool, classes:array<int, array{value:string, label:string, selected:bool}>, read:array<int, array{id:int, name:string, checked:bool}>, write:array<int, array{id:int, name:string, checked:bool}>}
	 */
	public static function formFor(int $userId, Scopes $scopes, ?array $prefill = null): array {
		$own     = $scopes->writeScopeIds($userId);
		$class   = $prefill !== null && in_array($prefill['class'], AgentMeta::CLASSES, true) ? $prefill['class'] : self::DEFAULT_CLASS;
		$classes = [];
		foreach (AgentMeta::CLASSES as $value) {
			$classes[] = ['value' => $value, 'label' => self::classLabel($value), 'selected' => $value === $class];
		}

		return [
			'name'     => $prefill['name'] ?? '',
			'selected' => $prefill !== null,
			'classes'  => $classes,
			'read'     => self::scopeChoices($scopes->readScopeIds($userId), $prefill['read'] ?? $own),
			'write'    => self::scopeChoices($own, $prefill['write'] ?? $own),
		];
	}

	/**
	 * Checks the posted fields against what $userId may create. Returns the
	 * fields to use (observer writes dropped, ids deduplicated), or the
	 * first problem.
	 *
	 * @param array{name:string, class:string, read:int[], write:int[]} $fields
	 * @return array{name:string, agent_name:string, class:string, read:int[], write:int[]}|WP_Error
	 */
	public static function validate(int $userId, array $fields) {
		if (! self::canCreate($userId)) {
			return new WP_Error('ab_oauth_new_agent_forbidden', __('You cannot add agents to this brain. Connect as yourself or as an existing agent, or ask a curator.', 'tomatio'), ['status' => 403]);
		}

		$name = trim($fields['name']);
		if ($name === '') {
			return new WP_Error('ab_oauth_new_agent_name_required', __('Give the new agent a name, for example the name of the bot you are connecting.', 'tomatio'), ['status' => 422]);
		}
		if (self::length($name) > AgentProposals::NAME_MAX) {
			/* translators: %d: maximum length. */
			return new WP_Error('ab_oauth_new_agent_name_invalid', sprintf(__('The agent name can be at most %d characters.', 'tomatio'), AgentProposals::NAME_MAX), ['status' => 422]);
		}

		if (! in_array($fields['class'], AgentMeta::CLASSES, true)) {
			return new WP_Error('ab_oauth_new_agent_class_invalid', __('Pick one of the classes offered.', 'tomatio'), ['status' => 422]);
		}

		$scopes = new Scopes();
		$narrow = self::narrow($fields['class'], $fields['read'], $fields['write'], $scopes->readScopeIds($userId), $scopes->writeScopeIds($userId));
		if ($narrow['outside'] !== []) {
			return new WP_Error('ab_oauth_new_agent_scope_forbidden', __('A new agent can only get scopes you hold yourself. Untick the others, or ask a curator to grant them to you first.', 'tomatio'), ['status' => 403]);
		}

		if (AgentProposals::nameTaken($name)) {
			return self::nameClash($name);
		}

		return [
			'name'       => $name,
			'agent_name' => AgentIssuer::nameFor($name),
			'class'      => $fields['class'],
			'read'       => $narrow['read'],
			'write'      => $narrow['write'],
		];
	}

	/**
	 * Pure: the scope ids to give, deduplicated, with an observer's write
	 * scopes dropped, and the ids the person does not hold themselves
	 * ($outside; any of them refuses the whole request).
	 *
	 * @param int[] $read
	 * @param int[] $write
	 * @param int[] $ownRead
	 * @param int[] $ownWrite
	 * @return array{read:int[], write:int[], outside:int[]}
	 */
	public static function narrow(string $class, array $read, array $write, array $ownRead, array $ownWrite): array {
		$read  = self::ids($read);
		$write = $class === AgentMeta::CLASS_OBSERVER ? [] : self::ids($write);

		$outside = array_merge(
			array_diff($read, array_map('intval', $ownRead)),
			array_diff($write, array_map('intval', $ownWrite))
		);

		return ['read' => $read, 'write' => $write, 'outside' => array_values(array_unique($outside))];
	}

	/**
	 * Pure: whether an error code is one the person can fix on the consent
	 * screen (the new agent's name, class or scopes), so it is shown there
	 * again instead of as a dead-end error page.
	 */
	public static function isFixable(string $code): bool {
		return str_starts_with($code, 'ab_oauth_new_agent_') || $code === 'ab_fqdn_taken';
	}

	/**
	 * Makes the agent and runs $bind (which issues the authorization code
	 * for it) in one transaction. Either everything is written (the agent,
	 * its ID and `agent.issue` row, its scopes, the code) or nothing is.
	 *
	 * @param array{name:string, agent_name:string, class:string, read:int[], write:int[]} $agent from validate()
	 * @param callable(int): (string|WP_Error) $bind issues the code for the new agent's user id
	 * @return array{agent_id:int, fqdn:string, code:string}|WP_Error
	 */
	public static function createAndBind(int $userId, string $clientId, array $agent, callable $bind) {
		global $wpdb;

		$lock = 'ab_new_agent_' . md5(self::fold($agent['name']));
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Db::prepare() is the enforcement point; the rest are fixed statements.
		if ((string) $wpdb->get_var(Db::prepare('SELECT GET_LOCK(%s, %d)', [$lock, self::LOCK_WAIT])) !== '1') {
			return new WP_Error('ab_oauth_new_agent_busy', __('Another agent with this name is being added right now. Try again in a moment.', 'tomatio'), ['status' => 409]);
		}

		$nested   = (string) $wpdb->get_var('SELECT @@autocommit') === '0';
		$begin    = $nested ? 'SAVEPOINT ab_new_agent' : 'START TRANSACTION';
		$rollback = $nested ? 'ROLLBACK TO SAVEPOINT ab_new_agent' : 'ROLLBACK';
		$commit   = $nested ? 'RELEASE SAVEPOINT ab_new_agent' : 'COMMIT';

		$wpdb->query($begin);

		$agentId = 0;
		try {
			// Checked again under the lock: another consent may have taken
			// the name since validate().
			$result = AgentProposals::nameTaken($agent['name']) ? self::nameClash($agent['name']) : self::create($userId, $agent);
			if (! $result instanceof WP_Error) {
				$agentId = $result['agent_id'];
				$code    = $bind($agentId);
				$result  = $code instanceof WP_Error ? $code : $result + ['code' => $code];
			} else {
				$agentId = (int) ($result->get_error_data()['user_id'] ?? 0);
			}
		} catch (\Throwable $e) {
			$result = new WP_Error('ab_oauth_new_agent_failed', __('The agent could not be added. Nothing was changed; please try again.', 'tomatio'), ['status' => 500]);
		}

		if ($result instanceof WP_Error) {
			$wpdb->query($rollback);
			wp_cache_flush();
			$wpdb->get_var(Db::prepare('SELECT RELEASE_LOCK(%s)', [$lock]));
			// Outer safety for side effects a rollback cannot reach
			// (third-party user_register handlers); after a rollback the
			// account is already gone.
			self::undo($agentId);

			Audit::emit($userId, 'oauth.new_agent', 'error', ['client_id' => $clientId, 'reason' => $result->get_error_code()]);

			return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => $result->get_error_data()['status'] ?? 400]);
		}

		$wpdb->query($commit);
		$wpdb->get_var(Db::prepare('SELECT RELEASE_LOCK(%s)', [$lock]));
		// phpcs:enable

		return $result;
	}

	/**
	 * @param array{name:string, agent_name:string, class:string, read:int[], write:int[]} $agent
	 * @return array{agent_id:int, fqdn:string}|WP_Error
	 */
	private static function create(int $userId, array $agent) {
		// The ID is chosen first, so the login is its first label (#232),
		// like a proposal's and a registration key's.
		$registerId = AgentIssuer::freeRegisterId($agent['agent_name']);
		if ($registerId === null) {
			return new WP_Error('ab_fqdn_taken', __('Could not find a free agent ID for that name. Try again, or pick another name.', 'tomatio'), ['status' => 409]);
		}
		$login = $agent['agent_name'] . '-' . $registerId;

		AgentIssuer::deferAutoIssue(true);
		try {
			$agentId = wp_insert_user([
				'user_login'    => $login,
				'user_nicename' => $login,
				'user_pass'     => wp_generate_password(48, true, true),
				'display_name'  => $agent['name'],
				'nickname'      => $agent['name'],
				'role'          => Capabilities::ROLE_AGENT,
			]);
		} finally {
			AgentIssuer::deferAutoIssue(false);
		}
		if ($agentId instanceof WP_Error) {
			return new WP_Error('ab_oauth_new_agent_failed', __('The agent could not be added. Nothing was changed; please try again.', 'tomatio'), ['status' => 500]);
		}
		$agentId = (int) $agentId;

		// Writes the agent's ID and the `agent.issue` audit row, on this
		// same connection, so a rollback removes both.
		$fqdn = (new AgentIssuer())->issue($agentId, $agent['agent_name'], $agent['class'], $userId, $registerId);
		if ($fqdn instanceof WP_Error) {
			return new WP_Error($fqdn->get_error_code(), $fqdn->get_error_message(), ['status' => 409, 'user_id' => $agentId]);
		}
		Principals::ensure($agentId);

		// Validated against the person's own scopes. Set as a whole, so a
		// default-scope grant made while the account was created does not
		// widen what the person chose.
		update_user_meta($agentId, Scopes::META_READ_SCOPES, $agent['read']);
		update_user_meta($agentId, Scopes::META_WRITE_SCOPES, $agent['write']);

		return ['agent_id' => $agentId, 'fqdn' => $fqdn];
	}

	private static function undo(int $agentId): void {
		if ($agentId <= 0 || get_userdata($agentId) === false) {
			return;
		}

		if (! function_exists('wp_delete_user')) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		wp_delete_user($agentId);
		Principals::discardUnused($agentId);
	}

	private static function nameClash(string $name): WP_Error {
		return new WP_Error(
			'ab_oauth_new_agent_name_taken',
			/* translators: %s: the agent name the person typed. */
			sprintf(__('An agent called “%s” already exists. Pick another name, or choose it under “Connect as an existing agent”.', 'tomatio'), $name),
			['status' => 409]
		);
	}

	/** What the class means, in the words the consent screen uses. */
	public static function classLabel(string $class): string {
		switch ($class) {
			case AgentMeta::CLASS_OBSERVER:
				return __('Observer: reads only', 'tomatio');
			case AgentMeta::CLASS_EXECUTOR:
				return __('Executor: reads, saves memories and proposes (recommended)', 'tomatio');
			case AgentMeta::CLASS_CONTRIBUTOR:
				return __('Contributor: like executor, labelled as a contributor', 'tomatio');
			case AgentMeta::CLASS_LEAD:
				return __('Lead: like executor, labelled as a lead', 'tomatio');
		}

		return $class;
	}

	/**
	 * @param int[] $offered
	 * @param int[] $checked
	 * @return array<int, array{id:int, name:string, checked:bool}>
	 */
	private static function scopeChoices(array $offered, array $checked): array {
		$choices = [];
		foreach ($offered as $scopeId) {
			$term = get_term((int) $scopeId, Scopes::TAXONOMY);
			if ($term instanceof \WP_Term) {
				$choices[] = ['id' => (int) $term->term_id, 'name' => $term->name, 'checked' => in_array((int) $term->term_id, $checked, true)];
			}
		}

		return $choices;
	}

	/**
	 * @param mixed $raw
	 * @return int[]
	 */
	private static function ids($raw): array {
		$raw = is_array($raw) ? $raw : [];
		$ids = array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, $raw);

		return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
	}

	private static function fold(string $s): string {
		return strtolower(remove_accents(trim($s)));
	}

	private static function length(string $s): int {
		return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
	}
}
