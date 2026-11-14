<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

use AgentBrain\Core\AuthorizedBy;
use AgentBrain\Core\WriteLimiter;
use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Identity\AgentProposals;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\MasterRole;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\OAuth\RoleSwitching;
use WP_Error;

/**
 * The master tools (#921): set up the brain's agents, their jobs and
 * scopes over MCP. agent-list, agent-create, agent-update, scope-create and
 * agent-proposal-decide, for masters only (Identity\MasterRole); everyone
 * else neither sees nor can call them.
 *
 * Who the caller is: the account the tool runs as. On a person's
 * role-switching connection with an agent chosen (act-as), that is the
 * agent, at discovery as well as when a tool runs, so the tool list and the
 * calls agree; with no agent chosen it is the person.
 *
 *  - A master agent's powers hold only on its own connection, or when the
 *    person behind the call (the role-switching person who chose it with
 *    act-as, or who connected an AI as it) is a person master too, so
 *    acting as a master agent never gives a person more than they have
 *    (masterKind()).
 *  - A person master's changes take effect at once.
 *  - A master agent's new agents wait in the Review Queue; it changes only
 *    agents that are not masters (never itself or a person), and gives or
 *    takes away only scopes it holds itself.
 *  - Nobody gives an agent a scope they do not hold themselves, and a new
 *    agent is never a master. Every change is audited with the caller.
 */
final class MasterAbilities {

	/** Longest scope name, as on the Scopes page. */
	public const SCOPE_NAME_MAX = 100;

	/** masterKind(): a person master, a master agent, or neither. */
	public const KIND_PERSON = 'person';
	public const KIND_AGENT  = 'master_agent';
	public const KIND_NONE   = '';

	public static function register(): void {
		if (! function_exists('wp_register_ability')) {
			return;
		}

		self::registerAgentList();
		self::registerAgentCreate();
		self::registerAgentUpdate();
		self::registerScopeCreate();
		self::registerAgentProposalDecide();
	}

	/**
	 * The account a master tool runs as: the agent chosen with act-as on
	 * this connection, else the signed-in user.
	 */
	public static function caller(): int {
		$active = RoleSwitching::current();

		return $active > 0 ? $active : (int) get_current_user_id();
	}

	private static function canUseMcp(): bool {
		$userId = (int) get_current_user_id();

		return $userId > 0 && user_can($userId, Capabilities::CAP_USE_MCP);
	}

	/**
	 * The one place that decides what the caller may do with the master
	 * tools, at discovery and when a tool runs: KIND_PERSON, KIND_AGENT or
	 * KIND_NONE.
	 *
	 * A master agent counts only when nobody is behind the call (its own
	 * connection) or the person behind it is a person master. Otherwise a
	 * person who may switch into a master agent (act-as) or connect an AI
	 * as it would get powers they do not have themselves.
	 */
	public static function masterKind(): string {
		if (! self::canUseMcp()) {
			return self::KIND_NONE;
		}

		$caller = self::caller();
		if (MasterRole::isPersonMaster($caller)) {
			return self::KIND_PERSON;
		}

		if (MasterRole::isMasterAgent($caller)) {
			$behind = self::personBehind();
			if ($behind === 0 || $behind === $caller || MasterRole::isPersonMaster($behind)) {
				return self::KIND_AGENT;
			}
		}

		return self::KIND_NONE;
	}

	/**
	 * The person behind this call, if any: the person whose role-switching
	 * connection this is, else the person who connected the AI as this
	 * agent (a delegated token), else 0.
	 */
	private static function personBehind(): int {
		$person = RoleSwitching::person();

		return $person > 0 ? $person : AuthorizedBy::id();
	}

	private static function isMaster(): bool {
		return self::masterKind() !== self::KIND_NONE;
	}

	private static function isPersonMaster(): bool {
		return self::masterKind() === self::KIND_PERSON;
	}

	// -----------------------------------------------------------------
	// agent-list
	// -----------------------------------------------------------------

	private static function registerAgentList(): void {
		AbilityNames::registerOnce(AbilityNames::AGENT_LIST, [
			'label'               => __('List agents', 'tomatio'),
			'description'         => __('Masters only: every agent of this brain with its login, name, job (what it does), status, read and write scopes and whether it is a master agent; the scopes (with whether you hold them, which is what you can give); and, for a person who can add agents, the agent proposals waiting for review. Use the ids here with agent-create, agent-update and agent-proposal-decide.', 'tomatio'),
			'category'            => Module::CATEGORY,
			'input_schema'        => ['type' => 'object', 'properties' => new \stdClass()],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'you'       => ['type' => 'object'],
					'agents'    => ['type' => 'array', 'items' => ['type' => 'object']],
					'scopes'    => ['type' => 'array', 'items' => ['type' => 'object']],
					'proposals' => ['type' => 'array', 'items' => ['type' => 'object']],
				],
				'required'   => ['you', 'agents', 'scopes', 'proposals'],
			],
			'permission_callback' => static fn (): bool => self::isMaster(),
			'execute_callback'    => static function () {
				$kind = self::masterKind();
				if ($kind === self::KIND_NONE) {
					return self::forbidden(__('Only a master can do this.', 'tomatio'));
				}
				$caller = self::caller();
				$person = $kind === self::KIND_PERSON;
				$scopes = new Scopes();
				$read   = $scopes->readScopeIds($caller);
				$write  = $scopes->writeScopeIds($caller);

				$agents = [];
				foreach (get_users(['role' => Capabilities::ROLE_AGENT, 'orderby' => 'display_name', 'order' => 'ASC']) as $user) {
					if (MasterRole::isAgentAccount((int) $user->ID)) {
						$agents[] = self::agentRow((int) $user->ID, $person ? $caller : 0);
					}
				}

				$terms = get_terms(['taxonomy' => Scopes::TAXONOMY, 'hide_empty' => false]);
				$all   = [];
				foreach (is_array($terms) ? $terms : [] as $term) {
					if ($term instanceof \WP_Term) {
						$all[] = [
							'id'         => (int) $term->term_id,
							'name'       => (string) $term->name,
							'you_read'   => in_array((int) $term->term_id, $read, true),
							'you_write'  => in_array((int) $term->term_id, $write, true),
						];
					}
				}

				$proposals = [];
				if ($person) {
					AgentProposals::pruneExpired();
					foreach (AgentProposals::pending() as $proposal) {
						$by          = get_userdata($proposal['acting_id'] > 0 ? $proposal['acting_id'] : $proposal['person_id']);
						$proposals[] = [
							'proposal_id'  => $proposal['id'],
							'name'         => $proposal['name'],
							'job'          => $proposal['role'],
							'read_scopes'  => self::scopeRefs($proposal['read_scope_ids']),
							'write_scopes' => self::scopeRefs($proposal['write_scope_ids']),
							'proposed_by'  => $by instanceof \WP_User ? (string) $by->user_login : '',
							'created'      => $proposal['created'],
						];
					}
				}

				$you = get_userdata($caller);

				return [
					'you'       => [
						'user_id' => $caller,
						'login'   => $you instanceof \WP_User ? (string) $you->user_login : '',
						'kind'    => $kind,
						'note'    => $person
							? __('Your changes take effect at once.', 'tomatio')
							: __('You are a master agent: your new agents wait for a person in the Review Queue, and you can only give or take away scopes you hold yourself, on agents that are not masters.', 'tomatio'),
					],
					'agents'    => $agents,
					'scopes'    => $all,
					'proposals' => $proposals,
				];
			},
		]);
	}

	// -----------------------------------------------------------------
	// agent-create
	// -----------------------------------------------------------------

	private static function registerAgentCreate(): void {
		AbilityNames::registerOnce(AbilityNames::AGENT_CREATE, [
			'label'               => __('Create an agent', 'tomatio'),
			'description'         => __('Masters only: add an agent with a short name, its job (one sentence on what it does) and its read and write scopes (ids from agent-list; both default to the scopes you can write, and can never go beyond your own). For a person the agent exists at once (status "created"): switch to it with act-as, or connect another AI as it from Connect an AI. For a master agent it waits for a person in the Review Queue (status "pending", 24 hours). A new agent is never a master.', 'tomatio'),
			'category'            => Module::CATEGORY,
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'name'            => ['type' => 'string', 'maxLength' => AgentProposals::NAME_MAX],
					'job'             => ['type' => 'string', 'maxLength' => AgentProposals::ROLE_MAX, 'description' => 'One sentence on what the agent does.'],
					'read_scope_ids'  => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
					'write_scope_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
				],
				'required'   => ['name', 'job'],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'status'      => ['type' => 'string'],
					'agent'       => ['type' => 'object'],
					'proposal_id' => ['type' => 'string'],
					'review_url'  => ['type' => 'string'],
					'message'     => ['type' => 'string'],
				],
				'required'   => ['status', 'message'],
			],
			'permission_callback' => static fn (): bool => self::isMaster(),
			'execute_callback'    => static function ($input) {
				$input = is_array($input) ? $input : [];

				return self::withWriteLimiter(static function () use ($input) {
					$kind = self::masterKind();
					if ($kind === self::KIND_NONE) {
						return self::forbidden(__('Only a master can do this.', 'tomatio'));
					}
					$caller = self::caller();
					$name   = is_string($input['name'] ?? null) ? $input['name'] : '';
					$job    = is_string($input['job'] ?? null) ? $input['job'] : '';
					$read   = isset($input['read_scope_ids']) ? (array) $input['read_scope_ids'] : null;
					$write  = isset($input['write_scope_ids']) ? (array) $input['write_scope_ids'] : null;

					if ($kind === self::KIND_PERSON) {
						$agentId = AgentProposals::createNow($caller, $caller, $name, $job, $read, $write);
						if ($agentId instanceof WP_Error) {
							return $agentId;
						}

						$agent = self::agentRow($agentId, $caller);

						return [
							'status'  => 'created',
							'agent'   => $agent,
							'message' => $agent['can_act_as']
								/* translators: %s: the new agent's login. */
								? sprintf(__('Created %s. Switch to it with act-as, or connect another AI as it from Connect an AI.', 'tomatio'), $agent['login'])
								/* translators: %s: the new agent's login. */
								: sprintf(__('Created %s. Connect an AI as it from Connect an AI.', 'tomatio'), $agent['login']),
						];
					}

					$proposal = AgentProposals::proposeAsMaster($caller, $name, $job, $read, $write);
					if ($proposal instanceof WP_Error) {
						return $proposal;
					}

					return [
						'status'      => 'pending',
						'proposal_id' => $proposal['id'],
						'review_url'  => self::reviewUrl(),
						/* translators: %s: proposed agent name. */
						'message'     => sprintf(__('Proposed %s. As a master agent your new agents wait for a person: it exists once someone approves it in the Review Queue (review_url) within 24 hours.', 'tomatio'), $proposal['name']),
					];
				});
			},
		]);
	}

	// -----------------------------------------------------------------
	// agent-update
	// -----------------------------------------------------------------

	private static function registerAgentUpdate(): void {
		$ids = ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]];

		AbilityNames::registerOnce(AbilityNames::AGENT_UPDATE, [
			'label'               => __('Change an agent', 'tomatio'),
			'description'         => __('Masters only: change one agent (user_id from agent-list): its job, the scopes it reads and writes (add_* and remove_* lists of scope ids), its status (active, or suspended to cut it off until reactivated), and, for a person only, whether it is a master agent. Never a person\'s account and never yourself. You can only give scopes you hold yourself; a master agent can also only take away scopes it holds, and only from agents that are not masters. Every change is written to the audit log.', 'tomatio'),
			'category'            => Module::CATEGORY,
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'user_id'               => ['type' => 'integer', 'minimum' => 1],
					'job'                   => ['type' => 'string', 'maxLength' => AgentProposals::ROLE_MAX],
					'add_read_scope_ids'    => $ids,
					'add_write_scope_ids'   => $ids,
					'remove_read_scope_ids' => $ids,
					'remove_write_scope_ids' => $ids,
					'status'                => ['type' => 'string', 'enum' => [AgentMeta::STATUS_ACTIVE, AgentMeta::STATUS_SUSPENDED]],
					'master'                => ['type' => 'boolean'],
				],
				'required'   => ['user_id'],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'agent'   => ['type' => 'object'],
					'changed' => ['type' => 'array', 'items' => ['type' => 'string']],
					'message' => ['type' => 'string'],
				],
				'required'   => ['agent', 'changed', 'message'],
			],
			'permission_callback' => static fn (): bool => self::isMaster(),
			'execute_callback'    => static function ($input) {
				$input = is_array($input) ? $input : [];

				return self::withWriteLimiter(static function () use ($input) {
					$kind = self::masterKind();
					if ($kind === self::KIND_NONE) {
						return self::forbidden(__('Only a master can do this.', 'tomatio'));
					}

					return self::update(self::caller(), $kind === self::KIND_PERSON, $input);
				});
			},
		]);
	}

	/**
	 * Checks the whole request first, so a refused change leaves the agent
	 * as it was, then applies it.
	 *
	 * @param array<string, mixed> $input
	 * @return array{agent: array<string, mixed>, changed: string[], message: string}|WP_Error
	 */
	private static function update(int $caller, bool $person, array $input) {
		$target = (int) ($input['user_id'] ?? 0);

		if (! MasterRole::isAgentAccount($target)) {
			return self::forbidden(__('agent-update changes agents only: that user_id is not an agent. A person\'s account is never changed over MCP.', 'tomatio'));
		}
		if ($target === $caller) {
			return self::forbidden(__('A master cannot change itself. Ask a person to change this agent.', 'tomatio'));
		}
		if (! $person && MasterRole::isMasterAgent($target)) {
			return self::forbidden(__('A master agent cannot change another master agent.', 'tomatio'));
		}
		if (! $person && array_key_exists('master', $input)) {
			return self::forbidden(__('Only a person can make an agent a master or stop it being one.', 'tomatio'));
		}
		if (AgentIssuer::statusOf($target) === AgentMeta::STATUS_REVOKED) {
			return new WP_Error('ab_agent_revoked', __('That agent is revoked and cannot be changed.', 'tomatio'), ['status' => 409]);
		}

		$job = null;
		if (array_key_exists('job', $input)) {
			$job = trim(sanitize_textarea_field(is_string($input['job']) ? $input['job'] : ''));
			if ($job === '' || mb_strlen($job) > AgentProposals::ROLE_MAX) {
				/* translators: %d: maximum length. */
				return self::invalid(sprintf(__('job must be one sentence on what the agent does, at most %d characters.', 'tomatio'), AgentProposals::ROLE_MAX));
			}
		}

		$status = null;
		if (array_key_exists('status', $input)) {
			$status = is_string($input['status']) ? $input['status'] : '';
			if (! in_array($status, [AgentMeta::STATUS_ACTIVE, AgentMeta::STATUS_SUSPENDED], true)) {
				return self::invalid(__('status is "active" or "suspended".', 'tomatio'));
			}
		}

		$lists = [
			'add_read'     => self::ids($input['add_read_scope_ids'] ?? []),
			'add_write'    => self::ids($input['add_write_scope_ids'] ?? []),
			'remove_read'  => self::ids($input['remove_read_scope_ids'] ?? []),
			'remove_write' => self::ids($input['remove_write_scope_ids'] ?? []),
		];

		$unknown = array_filter(array_unique(array_merge(...array_values($lists))), static fn (int $id): bool => ! self::scopeExists($id));
		if ($unknown !== []) {
			/* translators: %s: comma-separated scope ids. */
			return self::invalid(sprintf(__('No scope with id %s. agent-list shows the scopes.', 'tomatio'), implode(', ', $unknown)));
		}

		$scopes   = new Scopes();
		$ownRead  = $scopes->readScopeIds($caller);
		$ownWrite = $scopes->writeScopeIds($caller);
		$outside  = array_merge(array_diff($lists['add_read'], $ownRead), array_diff($lists['add_write'], $ownWrite));
		if (! $person) {
			$outside = array_merge($outside, array_diff($lists['remove_read'], $ownRead), array_diff($lists['remove_write'], $ownWrite));
		}
		if ($outside !== []) {
			$notYours = implode(', ', array_unique($outside));
			if ($person) {
				/* translators: %s: comma-separated scope ids. */
				return self::forbidden(sprintf(__('You can only give scopes you hold yourself. Not yours: %s.', 'tomatio'), $notYours));
			}

			/* translators: %s: comma-separated scope ids. */
			return self::forbidden(sprintf(__('A master agent can only give or take away scopes it holds itself. Not yours: %s.', 'tomatio'), $notYours));
		}

		$master = array_key_exists('master', $input) ? (bool) $input['master'] : null;
		if ($job === null && $status === null && $master === null && array_merge(...array_values($lists)) === []) {
			return self::invalid(__('Nothing to change: give job, add_* or remove_* scope ids, status or master.', 'tomatio'));
		}

		$changed  = [];
		$failures = [];

		$user = get_userdata($target);
		if ($job !== null && $user instanceof \WP_User && trim((string) $user->description) !== $job) {
			$result = wp_update_user(['ID' => $target, 'description' => $job]);
			if ($result instanceof WP_Error) {
				$failures[] = 'job';
			} else {
				$changed[] = 'job';
				\AgentBrain\Core\Hooks::action('audit', [
					'actor_id'    => $caller,
					'event'       => 'agent.job_changed',
					'status'      => 'ok',
					'object_type' => 'user',
					'object_id'   => (string) $target,
					'meta'        => ['fqdn' => AgentIssuer::fqdnFor($target)],
				]);
			}
		}

		$steps = [
			['revoke', 'read', $lists['remove_read']],
			['revoke', 'write', $lists['remove_write']],
			['grant', 'read', $lists['add_read']],
			['grant', 'write', $lists['add_write']],
		];
		foreach ($steps as [$action, $list, $scopeIds]) {
			$current = $list === 'read' ? $scopes->readScopeIds($target) : $scopes->writeScopeIds($target);
			foreach ($scopeIds as $scopeId) {
				$holds = in_array($scopeId, $current, true);
				if (($action === 'grant') === $holds) {
					continue; // Already as asked: nothing to change or audit.
				}

				if ($person) {
					$ok = $action === 'grant' ? $scopes->grant($caller, $target, $list, $scopeId) : $scopes->revoke($caller, $target, $list, $scopeId);
				} else {
					$ok = $action === 'grant' ? $scopes->grantAsMaster($caller, $target, $list, $scopeId) : $scopes->revokeAsMaster($caller, $target, $list, $scopeId);
				}

				$key = $list . '_scopes';
				if ($ok) {
					$changed[] = $key;
				} else {
					$failures[] = $key;
				}
			}
		}

		if ($status !== null && (AgentIssuer::statusOf($target) ?? AgentMeta::STATUS_ACTIVE) !== $status) {
			$result = (new AgentIssuer())->setStatus($target, $status, $caller, 'agent-update');
			if ($result instanceof WP_Error) {
				$failures[] = 'status';
			} else {
				$changed[] = 'status';
			}
		}

		if ($master !== null && $master !== MasterRole::isMasterAgent($target)) {
			$result = MasterRole::set($caller, $target, $master);
			if ($result instanceof WP_Error) {
				return $result;
			}
			$changed[] = 'master';
		}

		$changed = array_values(array_unique($changed));
		if ($failures !== []) {
			return new WP_Error('ab_update_failed', sprintf(
				/* translators: 1: what could not be changed, 2: what was changed. */
				__('Could not change: %1$s. Changed: %2$s.', 'tomatio'),
				implode(', ', array_unique($failures)),
				$changed === [] ? __('nothing', 'tomatio') : implode(', ', $changed)
			), ['status' => 500]);
		}

		$agent = self::agentRow($target, $person ? $caller : 0);

		return [
			'agent'   => $agent,
			'changed' => $changed,
			'message' => $changed === []
				/* translators: %s: agent login. */
				? sprintf(__('%s already was as asked: nothing changed.', 'tomatio'), $agent['login'])
				/* translators: 1: agent login, 2: what changed. */
				: sprintf(__('Changed %1$s: %2$s.', 'tomatio'), $agent['login'], implode(', ', $changed)),
		];
	}

	// -----------------------------------------------------------------
	// scope-create
	// -----------------------------------------------------------------

	private static function registerScopeCreate(): void {
		AbilityNames::registerOnce(AbilityNames::SCOPE_CREATE, [
			'label'               => __('Create a scope', 'tomatio'),
			'description'         => __('Masters only: add a scope (an area of the brain, such as "Product" or "Finance") by name. You get read and write on it at once; for a master agent, so does the person who made it a master. Then give it to agents with agent-create or agent-update.', 'tomatio'),
			'category'            => Module::CATEGORY,
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'name' => ['type' => 'string', 'maxLength' => self::SCOPE_NAME_MAX],
				],
				'required'   => ['name'],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'scope_id'   => ['type' => 'integer'],
					'name'       => ['type' => 'string'],
					'granted_to' => ['type' => 'array', 'items' => ['type' => 'integer']],
					'message'    => ['type' => 'string'],
				],
				'required'   => ['scope_id', 'name', 'granted_to', 'message'],
			],
			'permission_callback' => static fn (): bool => self::isMaster(),
			'execute_callback'    => static function ($input) {
				$input = is_array($input) ? $input : [];

				return self::withWriteLimiter(static function () use ($input) {
					$kind = self::masterKind();
					if ($kind === self::KIND_NONE) {
						return self::forbidden(__('Only a master can do this.', 'tomatio'));
					}
					$caller = self::caller();
					$person = $kind === self::KIND_PERSON;
					$name   = mb_substr(trim(sanitize_text_field(is_string($input['name'] ?? null) ? $input['name'] : '')), 0, self::SCOPE_NAME_MAX);
					if ($name === '') {
						return self::invalid(__('name is required.', 'tomatio'));
					}

					$exists = term_exists($name, Scopes::TAXONOMY);
					if (is_array($exists)) {
						$existing = (int) $exists['term_id'];

						/* translators: %d: the existing scope's id. */
						return new WP_Error('ab_scope_exists', sprintf(__('A scope with that name exists already (id %d). agent-list shows the scopes.', 'tomatio'), $existing), ['status' => 409]);
					}

					$term = wp_insert_term($name, Scopes::TAXONOMY);
					if ($term instanceof WP_Error) {
						return $term;
					}
					$scopeId = (int) $term['term_id'];

					\AgentBrain\Core\Hooks::action('audit', [
						'actor_id'    => $caller,
						'event'       => 'scope.created',
						'status'      => 'ok',
						'object_type' => Scopes::TAXONOMY,
						'object_id'   => (string) $scopeId,
						'meta'        => ['name' => $name, 'via' => 'scope-create'],
					]);

					$scopes = new Scopes();
					if ($person) {
						$ok      = $scopes->grant($caller, $caller, 'read', $scopeId) && $scopes->grant($caller, $caller, 'write', $scopeId);
						$granted = [$caller];
					} else {
						update_term_meta($scopeId, MasterRole::TERM_META_PENDING_CREATOR, $caller);
						$ok      = $scopes->grantNewScopeAsMaster($caller, $scopeId);
						$granted = array_values(array_unique(array_filter([$caller, MasterRole::masterBy($caller)], static fn (int $id): bool => $id > 0)));
					}

					if (! $ok) {
						/* translators: %d: the new scope's id. */
						return new WP_Error('ab_grant_failed', sprintf(__('The scope was created (id %d), but it could not be granted to you. Ask a person to grant it on the Scopes page.', 'tomatio'), $scopeId), ['status' => 500]);
					}

					return [
						'scope_id'   => $scopeId,
						'name'       => $name,
						'granted_to' => $granted,
						/* translators: 1: scope name, 2: scope id. */
						'message'    => sprintf(__('Created the scope %1$s (id %2$d), with read and write for you. Give it to agents with agent-create or agent-update.', 'tomatio'), $name, $scopeId),
					];
				});
			},
		]);
	}

	// -----------------------------------------------------------------
	// agent-proposal-decide
	// -----------------------------------------------------------------

	private static function registerAgentProposalDecide(): void {
		AbilityNames::registerOnce(AbilityNames::AGENT_PROPOSAL_DECIDE, [
			'label'               => __('Decide an agent proposal', 'tomatio'),
			'description'         => __('For a person who can add agents: approve or decline an agent proposal waiting in the Review Queue (proposal_id from agent-list). Approving creates the agent with the proposed job and scopes, if the proposer and you still hold them all. Only on the person\'s yes.', 'tomatio'),
			'category'            => Module::CATEGORY,
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'proposal_id' => ['type' => 'string', 'maxLength' => 64],
					'decision'    => ['type' => 'string', 'enum' => ['approve', 'decline']],
				],
				'required'   => ['proposal_id', 'decision'],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'status'      => ['type' => 'string'],
					'proposal_id' => ['type' => 'string'],
					'agent'       => ['type' => 'object'],
					'message'     => ['type' => 'string'],
				],
				'required'   => ['status', 'proposal_id', 'message'],
			],
			'permission_callback' => static fn (): bool => self::isPersonMaster(),
			'execute_callback'    => static function ($input) {
				$input = is_array($input) ? $input : [];

				return self::withWriteLimiter(static function () use ($input) {
					if (self::masterKind() !== self::KIND_PERSON) {
						return self::forbidden(__('Only a person who can add agents decides agent proposals.', 'tomatio'));
					}
					$caller = self::caller();
					$id     = is_string($input['proposal_id'] ?? null) ? trim($input['proposal_id']) : '';

					if (($input['decision'] ?? '') === 'decline') {
						$result = AgentProposals::decline($caller, $id);

						return $result instanceof WP_Error ? $result : [
							'status'      => 'declined',
							'proposal_id' => $id,
							'message'     => __('Declined. No agent was created.', 'tomatio'),
						];
					}

					$agentId = AgentProposals::approve($caller, $id);
					if ($agentId instanceof WP_Error) {
						return $agentId;
					}

					$agent = self::agentRow($agentId, $caller);

					return [
						'status'      => 'approved',
						'proposal_id' => $id,
						'agent'       => $agent,
						/* translators: %s: the new agent's login. */
						'message'     => sprintf(__('Approved: %s now exists.', 'tomatio'), $agent['login']),
					];
				});
			},
		]);
	}

	// -----------------------------------------------------------------
	// Shared helpers
	// -----------------------------------------------------------------

	/**
	 * One agent as the master tools show it. $personId: the person master
	 * asking, for can_act_as (0: a master agent, which never acts as one).
	 *
	 * @return array{user_id:int, login:string, name:string, fqdn:string, job:string, status:string, has_id:bool, master:bool, read_scopes:array<int, array{id:int, name:string}>, write_scopes:array<int, array{id:int, name:string}>, can_act_as:bool}
	 */
	private static function agentRow(int $agentId, int $personId): array {
		$user   = get_userdata($agentId);
		$scopes = new Scopes();
		$fqdn   = AgentIssuer::fqdnFor($agentId);

		return [
			'user_id'      => $agentId,
			'login'        => $user instanceof \WP_User ? (string) $user->user_login : '',
			'name'         => $user instanceof \WP_User ? (string) $user->display_name : '',
			'fqdn'         => (string) $fqdn,
			'job'          => $user instanceof \WP_User ? trim((string) $user->description) : '',
			'status'       => AgentIssuer::statusOf($agentId) ?? AgentMeta::STATUS_ACTIVE,
			'has_id'       => $fqdn !== null,
			'master'       => MasterRole::isMasterAgent($agentId),
			'read_scopes'  => self::scopeRefs($scopes->readScopeIds($agentId)),
			'write_scopes' => self::scopeRefs($scopes->writeScopeIds($agentId)),
			'can_act_as'   => $personId > 0 && RoleSwitching::canSwitch($personId, $agentId),
		];
	}

	/**
	 * @param int[] $ids
	 * @return array<int, array{id:int, name:string}>
	 */
	private static function scopeRefs(array $ids): array {
		$refs = [];
		foreach ($ids as $id) {
			$term = get_term((int) $id, Scopes::TAXONOMY);
			if ($term instanceof \WP_Term) {
				$refs[] = ['id' => (int) $term->term_id, 'name' => (string) $term->name];
			}
		}

		return $refs;
	}

	private static function scopeExists(int $id): bool {
		return get_term($id, Scopes::TAXONOMY) instanceof \WP_Term;
	}

	/**
	 * @param mixed $value
	 * @return int[]
	 */
	private static function ids($value): array {
		return array_values(array_unique(array_filter(array_map('intval', is_array($value) ? $value : []), static fn (int $id): bool => $id > 0)));
	}

	private static function reviewUrl(): string {
		return admin_url('admin.php?page=agent-brain-review#ab-agent-proposals');
	}

	private static function forbidden(string $message): WP_Error {
		return new WP_Error('ab_forbidden', $message, ['status' => 403]);
	}

	private static function invalid(string $message): WP_Error {
		return new WP_Error('ab_invalid_input', $message, ['status' => 422]);
	}

	/**
	 * @param callable(): (mixed|WP_Error) $fn
	 * @return mixed|WP_Error
	 */
	private static function withWriteLimiter(callable $fn) {
		$limiter  = new WriteLimiter();
		$acquired = $limiter->acquire();

		if ($acquired instanceof WP_Error) {
			return $acquired;
		}

		try {
			return $fn();
		} finally {
			$limiter->release();
		}
	}
}
