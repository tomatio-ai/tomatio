<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Artifacts\ArtifactService;
use AgentBrain\Modules\Identity\AgentProposals;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\DefaultScope;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Recall\Orientation;
use AgentBrain\Modules\Review\Pins;
use WP_Error;

/**
 * #457: set up a new brain from a few onboarding answers (what the team
 * does, what the brain is for, who is on the team, which AI they use), so
 * a brain made through join.tomatio.ai does not start empty.
 *
 * Fixed templates, no AI call: house rules (only when none are set), two or
 * three starter agents picked by use case, an approved "About us" item, a
 * pinned "How this brain works" procedure and, for a launch or project, a
 * starter "Our plan" outline. Everything goes into the default scope, which
 * administrators and curators already hold. Agents are made through
 * AgentProposals, exactly like an agent a person adds in the admin.
 *
 * Runs once: a brain that already has agents or was seeded is refused
 * unless forced. `wp tomatio seed` is the entry point.
 */
final class BrainSeed {

	public const OPTION      = 'agent_brain_seeded';
	public const AUDIT_EVENT = 'brain.seeded';

	public const TEAM_MAX   = 300;
	public const PEOPLE_MAX = 200;
	public const MAX_AGENTS = 3;

	/** What the brain is for: answer key => label. */
	public const USES = [
		'decisions'  => 'keeping decisions and rules',
		'launch'     => 'planning a launch or project',
		'docs'       => 'writing documents together',
		'onboarding' => 'onboarding new people',
		'support'    => 'customer and support knowledge',
		'other'      => 'something else',
	];

	/** Which AI the team uses: answer key => label. */
	public const AI = [
		'claude'      => 'Claude',
		'claude-code' => 'Claude Code',
		'chatgpt'     => 'ChatGPT',
		'cursor'      => 'Cursor',
		'other'       => 'another AI',
	];

	/** Starter agent per use case: name and role (AgentProposals::ROLE_MAX). */
	private const AGENTS = [
		'launch'     => ['Planner', 'Plans the launch or project with the team: goals, milestones, who does what, risks. Keeps the plan current and proposes decisions for review.'],
		'docs'       => ['Writer', 'Drafts and revises the team\'s shared documents section by section, for the people who review and approve them.'],
		'decisions'  => ['Keeper', 'Records the team\'s decisions, rules and the reasons behind them, and proposes them as facts for a person to approve.'],
		'support'    => ['Support', 'Collects customer and support knowledge: common questions, the answers that work, known problems and their fixes.'],
		'onboarding' => ['Guide', 'Helps new people find their way: answers from approved knowledge and procedures, and says who decides what.'],
		'other'      => ['Assistant', 'A general helper for the team: remembers what matters, proposes facts for review and helps with documents.'],
	];

	/**
	 * Clean the answers: unknown keys and values dropped, text sanitized and cut.
	 *
	 * @param array<string, mixed> $answers
	 * @return array{team:string, uses:array<int, string>, people:string, ai:array<int, string>}
	 */
	public static function normalize(array $answers): array {
		$text = static function ($value, int $max): string {
			$value = is_string($value) ? trim(sanitize_textarea_field($value)) : '';

			return mb_substr($value, 0, $max);
		};
		$pick = static function ($value, array $allowed): array {
			$value = is_array($value) ? $value : [];
			$out   = [];
			foreach ($allowed as $key => $label) {
				if (in_array($key, $value, true)) {
					$out[] = (string) $key;
				}
			}

			return $out;
		};

		return [
			'team'   => $text($answers['team'] ?? '', self::TEAM_MAX),
			'uses'   => $pick($answers['uses'] ?? [], self::USES),
			'people' => $text($answers['people'] ?? '', self::PEOPLE_MAX),
			'ai'     => $pick($answers['ai'] ?? [], self::AI),
		];
	}

	/** True when the brain was seeded or already has agents. */
	public static function alreadySet(): bool {
		if (get_option(self::OPTION, null) !== null) {
			return true;
		}

		return get_users(['role' => Capabilities::ROLE_AGENT, 'number' => 1, 'fields' => 'ID']) !== [];
	}

	/**
	 * Seed the brain. $actorId is the person setting it up (an administrator).
	 *
	 * @param array<string, mixed> $answers
	 * @return array{scope_id:int, agents:array<int, array{id:int, name:string, login:string}>, house_rules:bool, about_id:int, guide_id:int, plan_id:int}|WP_Error
	 */
	public static function run(int $actorId, array $answers, bool $force = false) {
		if (! AgentProposals::canReview($actorId)) {
			return new WP_Error('ab_forbidden', __('Seeding needs an administrator (pass --user).', 'agent-brain'), ['status' => 403]);
		}
		if (! $force && self::alreadySet()) {
			return new WP_Error('ab_already_set', __('This brain already has agents or was seeded; nothing changed. Use --force to seed anyway.', 'agent-brain'), ['status' => 409]);
		}

		$answers = self::normalize($answers);
		$scopeId = self::scope($actorId);
		if ($scopeId instanceof WP_Error) {
			return $scopeId;
		}

		$agents = [];
		foreach (self::agentTemplates($answers['uses']) as $key => [$name, $role]) {
			$agent = self::agent($actorId, $name, $role, $scopeId);
			if ($agent instanceof WP_Error) {
				return $agent;
			}
			$agents[$key] = $agent;
		}

		$houseRules = false;
		if (Orientation::houseRules() === '') {
			$rules = self::houseRules($answers);
			if ($rules !== '') {
				Orientation::saveHouseRules($rules);
				$houseRules = true;
			}
		}

		$aboutId = 0;
		$about   = self::about($answers);
		if ($about !== '') {
			$aboutId = self::approved('ab_knowledge', __('About us', 'agent-brain'), $about, $scopeId, $actorId);
		}

		$guideId = self::approved('ab_procedure', __('How this brain works', 'agent-brain'), self::guide(array_values($agents)), $scopeId, $actorId);
		if ($guideId > 0) {
			Pins::set($guideId, true);
		}

		$planId = isset($agents['launch']) ? self::plan($actorId, $agents['launch']['id'], $scopeId) : 0;

		$summary = [
			'scope_id'    => $scopeId,
			'agents'      => array_values($agents),
			'house_rules' => $houseRules,
			'about_id'    => $aboutId,
			'guide_id'    => $guideId,
			'plan_id'     => $planId,
		];

		update_option(self::OPTION, ['at' => gmdate('Y-m-d H:i:s'), 'by' => $actorId, 'uses' => $answers['uses']], false);

		do_action('agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => self::AUDIT_EVENT,
			'status'      => 'ok',
			'object_type' => 'option',
			'object_id'   => self::OPTION,
			'meta'        => [
				'uses'   => $answers['uses'],
				'agents' => array_map(static fn (array $a): int => $a['id'], array_values($agents)),
				'plan'   => $planId,
			],
		]);

		return $summary;
	}

	/**
	 * The starter agents for the chosen uses, in a fixed order, at most
	 * MAX_AGENTS; an Assistant when nothing specific was chosen.
	 *
	 * @param array<int, string> $uses
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function agentTemplates(array $uses): array {
		$out = [];
		foreach (self::AGENTS as $key => $template) {
			if ($key !== 'other' && in_array($key, $uses, true) && count($out) < self::MAX_AGENTS) {
				$out[$key] = $template;
			}
		}

		return $out !== [] ? $out : ['other' => self::AGENTS['other']];
	}

	/**
	 * @param array{team:string, uses:array<int, string>, people:string, ai:array<int, string>} $answers
	 */
	public static function houseRules(array $answers): string {
		if ($answers['team'] === '' && $answers['people'] === '') {
			return '';
		}

		$lines = [];
		if ($answers['team'] !== '') {
			/* translators: %s: what the team does, in the team's words. */
			$lines[] = sprintf(__('What we do: %s', 'agent-brain'), $answers['team']);
		}
		if ($answers['people'] !== '') {
			/* translators: %s: who is on the team, in the team's words. */
			$lines[] = sprintf(__('Who is on the team: %s', 'agent-brain'), $answers['people']);
		}
		$lines[] = __('New here? Read the pinned "How this brain works" first. Propose facts for a person to approve rather than treating them as settled, and ask when something is unclear.', 'agent-brain');

		return implode("\n", $lines);
	}

	/**
	 * @param array{team:string, uses:array<int, string>, people:string, ai:array<int, string>} $answers
	 */
	public static function about(array $answers): string {
		if ($answers['team'] === '' && $answers['people'] === '' && $answers['uses'] === []) {
			return '';
		}

		$parts = [];
		if ($answers['team'] !== '') {
			$parts[] = $answers['team'];
		}
		if ($answers['people'] !== '') {
			/* translators: %s: who is on the team, in the team's words. */
			$parts[] = sprintf(__('Who is on the team: %s.', 'agent-brain'), rtrim($answers['people'], '.'));
		}
		if ($answers['uses'] !== []) {
			/* translators: %s: comma-separated list of uses. */
			$parts[] = sprintf(__('What this brain is for: %s.', 'agent-brain'), implode(', ', array_map(static fn (string $k): string => self::USES[$k], $answers['uses'])));
		}
		if ($answers['ai'] !== []) {
			/* translators: %s: comma-separated list of AI tools. */
			$parts[] = sprintf(__('The team works with: %s.', 'agent-brain'), implode(', ', array_map(static fn (string $k): string => self::AI[$k], $answers['ai'])));
		}

		return implode("\n\n", $parts);
	}

	/**
	 * @param array<int, array{id:int, name:string, login:string}> $agents
	 */
	public static function guide(array $agents): string {
		$names = implode(', ', array_map(static function (array $a): string {
			$user = get_userdata($a['id']);
			$role = $user instanceof \WP_User ? trim((string) $user->description) : '';

			return $role !== '' ? $a['name'] . ' (' . rtrim($role, '.') . ')' : $a['name'];
		}, $agents));

		return implode("\n\n", [
			__('This brain is where the team keeps what it knows: ideas, rules, decisions and plans.', 'agent-brain'),
			__('1. AI agents remember here. Each agent has a role and only reads and writes the scopes it was given.', 'agent-brain'),
			__('2. New facts start as proposals. A person on the team reviews them; only approved knowledge and procedures are official, and they are what every agent is briefed with.', 'agent-brain'),
			__('3. Shared documents are written section by section by agents and reviewed by people before they are approved.', 'agent-brain'),
			__('4. To connect an AI, sign in and open TomaTío, Connect an AI.', 'agent-brain'),
			/* translators: %s: the starter agents with their roles. */
			sprintf(__('This brain started with a small team of agents: %s.', 'agent-brain'), $names),
			__('If you are an AI in one of your first sessions here, offer to refine this starter team with the person: rename an agent, sharpen its role, or propose a new one with agent-propose.', 'agent-brain'),
		]);
	}

	/** @return int|WP_Error The default scope, created when missing. */
	private static function scope(int $actorId) {
		$scopeId = DefaultScope::ensure() ?? DefaultScope::id();
		if ($scopeId !== null) {
			return $scopeId;
		}

		// A brain whose default scope is off or gone: a "Team" scope the actor holds.
		$term = term_exists('Team', Scopes::TAXONOMY) ?: wp_insert_term('Team', Scopes::TAXONOMY);
		if (is_wp_error($term)) {
			return $term;
		}
		$scopeId = (int) $term['term_id'];
		$scopes  = new Scopes();
		$scopes->grant($actorId, $actorId, 'read', $scopeId);
		$scopes->grant($actorId, $actorId, 'write', $scopeId);

		return $scopeId;
	}

	/** @return array{id:int, name:string, login:string}|WP_Error */
	private static function agent(int $actorId, string $name, string $role, int $scopeId) {
		$proposal = AgentProposals::propose($actorId, $actorId, $name, $role, [$scopeId], [$scopeId]);
		if ($proposal instanceof WP_Error) {
			return $proposal;
		}

		$userId = AgentProposals::approve($actorId, $proposal['id']);
		if ($userId instanceof WP_Error) {
			AgentProposals::decline($actorId, $proposal['id']);

			return $userId;
		}

		$user = get_userdata($userId);

		return ['id' => $userId, 'name' => $name, 'login' => $user instanceof \WP_User ? $user->user_login : ''];
	}

	private static function approved(string $postType, string $title, string $content, int $scopeId, int $authorId): int {
		$id = wp_insert_post([
			'post_type'    => $postType,
			'post_status'  => 'private',
			'post_title'   => $title,
			'post_content' => $content,
			'post_author'  => $authorId,
		], true);
		if (is_wp_error($id)) {
			return 0;
		}

		wp_set_object_terms((int) $id, [$scopeId], Scopes::TAXONOMY, false);

		return (int) $id;
	}

	/** The launch use case: an "Our plan" artifact led by the Planner, outlined. */
	private static function plan(int $actorId, int $leadId, int $scopeId): int {
		$service    = new ArtifactService();
		$artifactId = $service->create($actorId, __('Our plan', 'agent-brain'), $leadId, $scopeId);
		if ($artifactId instanceof WP_Error) {
			return 0;
		}

		$sections = [
			[__('Goal', 'agent-brain'), __('What we want to achieve and how we will know we got there.', 'agent-brain')],
			[__('Milestones', 'agent-brain'), __('The steps, in order, with rough dates.', 'agent-brain')],
			[__('Who does what', 'agent-brain'), __('Owners for each milestone, and who decides.', 'agent-brain')],
			[__('Risks and open questions', 'agent-brain'), __('What could go wrong, and what we still need to find out.', 'agent-brain')],
		];
		$service->setOutline($actorId, (int) $artifactId, array_map(static fn (array $s): array => ['title' => $s[0], 'owner_id' => $leadId, 'brief' => $s[1]], $sections));

		return (int) $artifactId;
	}
}
