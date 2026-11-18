<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\AgentId\Fqdn;
use AgentBrain\Modules\Identity\AgentProposals;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\OAuth\PcpDiscovery;
use AgentBrain\Modules\Recall\Orientation;
use WP_User;

/**
 * context.json (PCP v0.3 SPEC section 8.2 item 5) and profile/team.json
 * (section 4.2).
 *
 * context.json lists the controller, every principal in the registry
 * (removed ones too, so every reference in the archive resolves) with its
 * status, registry name and roles, and every scope as a partition with its
 * access list:
 *  - ab_read_scopes gives read;
 *  - ab_write_scopes gives propose and write;
 *  - ab_approve_knowledge (curators and administrators) gives review on
 *    every partition.
 * PCP requires a "default" partition; when no scope is named default, a
 * synthetic one with an empty access list is listed, which fails closed.
 * Each agent's full FQDN goes under x_agent_brain.agent_fqdns, since a
 * principal entry has no room for it, and its full identity (register id,
 * class, issuer, issue time) under x_agent_brain.agent_identities, so a
 * restore can put the meta back (docs/rfc/agent-id-fqdn.md section 3); and
 * agents proposed by an AI and not
 * yet approved or declined (Identity\AgentProposals) under
 * x_agent_brain.agent_proposals: name, role, the partitions they would
 * read and write, who proposed them and when. A proposal holds no
 * credential; approval creates the account.
 *
 * Display names come from the registry (the nickname, else the login),
 * never from WordPress display_name, which holds the FQDN for agents.
 */
final class ContextWriter {

	public const FEATURES = ['partitions', 'review', 'delegation'];

	private const RIGHTS_ORDER = ['read', 'propose', 'write', 'review'];

	/** Roles worth naming (SPEC 1.3 rule 4: informative). */
	private const ROLE_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

	/**
	 * @return array<string, mixed>
	 */
	public static function context(PageWriter $pages, PrincipalMap $map): array {
		$controller = Controller::ensure();
		// Before the registry is read: resolving a proposer can issue its id.
		$proposals = self::agentProposals($pages, $map);
		$registry  = Principals::all();

		usort($registry, static fn (array $a, array $b): int => strcmp($a['principal_id'], $b['principal_id']));

		$principals = [];
		$fqdns      = [];
		$identities = [];
		$rights     = [];
		$reviewers  = [];
		$hasController = false;

		foreach ($registry as $row) {
			$id = $row['principal_id'];
			if (! Names::isPrincipal($id)) {
				continue;
			}

			$hasController = $hasController || $id === $controller;
			$entry         = ['id' => $id];
			$name          = trim($row['display_name']);
			if ($name !== '') {
				$entry['display_name'] = ExportFormat::clip($name, 200);
			}

			$user = $row['user_id'] > 0 && $row['status'] !== Principals::STATUS_REMOVED ? get_userdata($row['user_id']) : false;
			if ($user instanceof WP_User) {
				// Unbound after a restore: the archive's roles (decision 9 holds
				// administrators back as curators until they are bound).
				$roles = array_values(array_filter(
					array_map('strval', Binding::archiveRoles((int) $user->ID) ?? (array) $user->roles),
					static fn (string $role): bool => preg_match(self::ROLE_PATTERN, $role) === 1
				));
				sort($roles, SORT_STRING);
				if ($roles !== []) {
					$entry['roles'] = $roles;
				}

				foreach (self::scopeIds($user->ID, Scopes::META_READ_SCOPES) as $termId) {
					$rights[$termId][$id]['read'] = true;
				}
				foreach (self::scopeIds($user->ID, Scopes::META_WRITE_SCOPES) as $termId) {
					$rights[$termId][$id]['propose'] = true;
					$rights[$termId][$id]['write']   = true;
				}
				if (user_can($user, Capabilities::CAP_APPROVE_KNOWLEDGE)) {
					$reviewers[] = $id;
				}
			}

			$entry['status'] = in_array($row['status'], [Principals::STATUS_ACTIVE, Principals::STATUS_SUSPENDED, Principals::STATUS_REMOVED], true)
				? $row['status']
				: Principals::STATUS_ACTIVE;

			if ($row['fqdn'] !== null && str_starts_with($id, 'agent:')) {
				$fqdns[$id] = $row['fqdn'];
				if ($user instanceof WP_User) {
					$identity = self::agentIdentity((int) $user->ID, (string) $row['fqdn'], $map);
					if ($identity !== null) {
						$identities[$id] = $identity;
					}
				}
			}

			$principals[] = $entry;
		}

		if (! $hasController) {
			$principals[] = ['id' => $controller, 'status' => Principals::STATUS_ACTIVE];
		}

		// Service principals the audit log names (AuditMapper::SERVICE_PRINCIPALS).
		// After a restore the registry already holds them, as it does the
		// controller: list each id once.
		$listed = array_column($principals, 'id');
		foreach (self::servicePrincipals() as $id) {
			if (! in_array($id, $listed, true)) {
				$principals[] = ['id' => $id, 'display_name' => 'Retention', 'status' => Principals::STATUS_ACTIVE];
			}
		}

		usort($principals, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

		$document = [
			'pcp_version' => '0.3',
			'kind'        => 'team',
			'controller'  => $controller,
			'features'    => self::features(),
			'principals'  => $principals,
			'partitions'  => self::partitions($pages, $rights, $reviewers),
		];

		$extra = [];
		if ($fqdns !== []) {
			ksort($fqdns, SORT_STRING);
			$extra['agent_fqdns'] = $fqdns;
		}
		if ($identities !== []) {
			ksort($identities, SORT_STRING);
			$extra['agent_identities'] = $identities;
		}
		if ($proposals !== []) {
			$extra['agent_proposals'] = $proposals;
		}
		if ($extra !== []) {
			$document['x_agent_brain'] = $extra;
		}

		return $document;
	}

	/**
	 * An agent's full identity for x_agent_brain.agent_identities: the FQDN
	 * plus the register id, class, issuer and issue time from the agent's
	 * meta, so a restore can put them back. Values the account does not
	 * hold are left out; the register id falls back to the FQDN's own, so
	 * even an agent whose meta was never written round-trips. The issuer
	 * travels as a principal id, not a user id. Null when the FQDN does
	 * not parse.
	 *
	 * @return array<string, string>|null
	 */
	private static function agentIdentity(int $userId, string $fqdn, PrincipalMap $map): ?array {
		$parsed = Fqdn::parse($fqdn);
		if ($parsed === null) {
			return null;
		}

		$identity = ['fqdn' => $fqdn];

		$registerId = (string) get_user_meta($userId, AgentMeta::REGISTER_ID, true);
		$identity['register_id'] = Fqdn::isValidRegisterId($registerId) ? $registerId : $parsed['register_id'];

		$class = (string) get_user_meta($userId, AgentMeta::CLASS_NAME, true);
		if (in_array($class, AgentMeta::CLASSES, true)) {
			$identity['class'] = $class;
		}

		$issuer = $map->idFor((int) get_user_meta($userId, AgentMeta::ISSUED_BY, true));
		if ($issuer !== null) {
			$identity['issued_by'] = $issuer;
		}

		$issuedAt = (string) get_user_meta($userId, AgentMeta::ISSUED_AT, true);
		if ($issuedAt !== '') {
			$identity['issued_at'] = $issuedAt;
		}

		return $identity;
	}

	/**
	 * The service principals of system jobs that have audit entries, so
	 * every principal the audit log names is listed.
	 *
	 * @return array<int, string>
	 */
	private static function servicePrincipals(): array {
		$table = \AgentBrain\Modules\Audit\Repository::tableName();
		$ids   = [];

		foreach (AuditMapper::SERVICE_PRINCIPALS as $event => $id) {
			$rows = \AgentBrain\Core\Db::getResults("SELECT 1 AS hit FROM {$table} WHERE event = %s AND actor_id = 0 LIMIT 1", [$event]);
			if ($rows !== []) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Pending agent proposals, oldest first, with scopes as partition names
	 * and people as principal ids.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function agentProposals(PageWriter $pages, PrincipalMap $map): array {
		$pending = array_values(AgentProposals::pending());
		usort($pending, static fn (array $a, array $b): int => [$a['created'], $a['id']] <=> [$b['created'], $b['id']]);

		$partitions = static function (array $scopeIds) use ($pages): array {
			$names = array_values(array_unique(array_map(static fn ($id): string => $pages->partitionFor((int) $id), $scopeIds)));
			sort($names, SORT_STRING);

			return $names;
		};

		$out = [];
		foreach ($pending as $proposal) {
			$person = $map->idFor((int) $proposal['person_id']);
			$acting = (int) $proposal['acting_id'] !== (int) $proposal['person_id'] ? $map->idFor((int) $proposal['acting_id']) : null;

			$out[] = array_filter([
				'id'               => (string) $proposal['id'],
				'name'             => ExportFormat::validUtf8((string) $proposal['name']),
				'role'             => ExportFormat::validUtf8((string) $proposal['role']),
				'read_partitions'  => $partitions($proposal['read_scope_ids']),
				'write_partitions' => $partitions($proposal['write_scope_ids']),
				'proposed_by'      => $person,
				'via_agent'        => $acting,
				'created'          => ExportFormat::utc((string) $proposal['created']),
			], static fn ($value): bool => $value !== null);
		}

		return $out;
	}

	/**
	 * profile/team.json: the brain's name (the site title), its purpose
	 * (the tagline) and the house rules from Settings > Onboarding as
	 * standing instructions.
	 *
	 * @return array<string, mixed>
	 */
	public static function teamProfile(): array {
		$name = trim(wp_strip_all_tags((string) get_option('blogname', '')));
		if ($name === '') {
			$name = (string) wp_parse_url(home_url(), PHP_URL_HOST);
		}

		$profile = [
			'pcp_version' => '0.3',
			'name'        => ExportFormat::validUtf8($name !== '' ? $name : 'Brain'),
		];

		$purpose = trim(wp_strip_all_tags((string) get_option('blogdescription', '')));
		if ($purpose !== '') {
			$profile['purpose'] = ExportFormat::validUtf8($purpose);
		}

		$profile['controller'] = Controller::ensure();

		$rules = Orientation::houseRules();
		if ($rules !== '') {
			$profile['ai_instructions'] = ExportFormat::validUtf8($rules);
		}

		return $profile;
	}

	/**
	 * The features discovery advertises, as context.json repeats them.
	 *
	 * @return array<int, string>
	 */
	public static function features(): array {
		$document = PcpDiscovery::contextDocument();
		$features = is_array($document['features'] ?? null) ? $document['features'] : [];

		return array_values(array_intersect(self::FEATURES, array_map('strval', $features)));
	}

	/**
	 * The discovery encryption declaration, for the manifest.
	 *
	 * @return array<string, mixed>
	 */
	public static function encryption(): array {
		$document   = PcpDiscovery::contextDocument();
		$encryption = is_array($document['encryption'] ?? null) ? $document['encryption'] : [];

		return [
			'key_custody' => (string) ($encryption['key_custody'] ?? 'host'),
			'readable_by' => array_values(array_map('strval', (array) ($encryption['readable_by'] ?? ['principals', 'host']))),
		];
	}

	/**
	 * @param array<int, array<string, array<string, true>>> $rights term id => principal => rights
	 * @param array<int, string>                             $reviewers
	 * @return array<int, array<string, mixed>>
	 */
	private static function partitions(PageWriter $pages, array $rights, array $reviewers): array {
		$out = [];

		foreach (Partitions::all() as $row) {
			$name = $pages->partitionFor($row['term_id']);
			if (isset($out[$name])) {
				continue;
			}

			$term  = get_term($row['term_id'], Scopes::TAXONOMY);
			$entry = ['name' => $name];
			if ($term instanceof \WP_Term && trim($term->name) !== '') {
				$entry['description'] = ExportFormat::clip(ExportFormat::validUtf8(trim($term->name)), 500);
			}

			$grants = $rights[$row['term_id']] ?? [];
			foreach ($reviewers as $reviewer) {
				$grants[$reviewer]['review'] = true;
			}
			ksort($grants, SORT_STRING);

			$access = [];
			foreach ($grants as $principal => $set) {
				$access[] = [
					'principal' => (string) $principal,
					'rights'    => array_values(array_filter(self::RIGHTS_ORDER, static fn (string $r): bool => isset($set[$r]))),
				];
			}

			$entry['access'] = $access;
			$out[$name]      = $entry;
		}

		if (! isset($out[Partitions::DEFAULT_NAME])) {
			$out[Partitions::DEFAULT_NAME] = ['name' => Partitions::DEFAULT_NAME, 'access' => []];
		}

		ksort($out, SORT_STRING);

		return array_values($out);
	}

	/**
	 * @return array<int, int>
	 */
	private static function scopeIds(int $userId, string $key): array {
		$raw = get_user_meta($userId, $key, true);
		if (! is_array($raw)) {
			return [];
		}

		$ids = [];
		foreach ($raw as $value) {
			if (is_int($value) || (is_string($value) && ctype_digit($value))) {
				$ids[] = (int) $value;
			}
		}

		return array_values(array_unique($ids));
	}
}
