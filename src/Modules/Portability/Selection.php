<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * #549: what a migration leaves behind. Moving a brain off the public demo
 * console must not bring its demo scopes and demo people along, so the
 * person migrating names scopes (archive partitions) and people (principal
 * ids) to leave behind, or the ones to keep, with "*" at the end of an id
 * matching every id that starts so (human:try-* for the demo logins; a
 * pattern may match nobody, an id must exist).
 *
 * A scope left behind: its pages and their history, its artifacts with
 * everything of theirs (sections, comments, compiled documents, HTML
 * versions, page store data, seats, uploads, share links), its handoffs,
 * the access entries that name it and the agent proposals that would only
 * read or write it. A person left behind: no account and no access; their
 * audit entries stay behind too. What they wrote in a scope that moves
 * keeps their principal id, so they come back as a removed principal, the
 * way people removed on the source brain do (ContextWriter lists those).
 *
 * The person migrating cannot be left behind, and the default partition
 * stays (PCP requires it).
 */
final class Selection {

	/**
	 * @param array<string, true> $partitions partition names left behind
	 * @param array<string, true> $principals principal ids left behind
	 */
	private function __construct(private array $partitions, private array $principals) {
	}

	public static function none(): self {
		return new self([], []);
	}

	/**
	 * The selection the options ask for, checked against the archive's
	 * context.
	 *
	 * @param array<string, mixed> $options leave_scopes, keep_scopes, leave_people, keep_people: lists, or comma-separated text.
	 * @param array<string, mixed> $context The archive's context.json.
	 * @return array{0:self, 1:array<int, string>} The selection and the errors.
	 */
	public static function fromOptions(array $options, array $context, string $bindAs = ''): array {
		$errors = [];

		$names = [];
		foreach ((array) ($context['partitions'] ?? []) as $partition) {
			$names[(string) ($partition['name'] ?? '')] = true;
		}
		$ids = [];
		foreach ((array) ($context['principals'] ?? []) as $principal) {
			$id = (string) ($principal['id'] ?? '');
			if (str_starts_with($id, 'human:') || str_starts_with($id, 'agent:')) {
				$ids[$id] = true;
			}
		}

		$leaveScopes = self::list($options['leave_scopes'] ?? []);
		$keepScopes  = self::list($options['keep_scopes'] ?? []);
		$leavePeople = self::list($options['leave_people'] ?? []);
		$keepPeople  = self::list($options['keep_people'] ?? []);

		if ($leaveScopes !== [] && $keepScopes !== []) {
			$errors[] = 'Name the scopes to leave behind or the scopes to keep, not both.';
		}
		if ($leavePeople !== [] && $keepPeople !== []) {
			$errors[] = 'Name the people to leave behind or the people to keep, not both.';
		}

		$partitions = [];
		foreach (array_merge($leaveScopes, $keepScopes) as $name) {
			if (! isset($names[$name])) {
				$errors[] = sprintf('The archive has no scope (partition) %s.', $name);
			}
		}
		if ($keepScopes !== []) {
			$keepScopes[] = Partitions::DEFAULT_NAME;
			foreach (array_keys($names) as $name) {
				if (! in_array($name, $keepScopes, true)) {
					$partitions[$name] = true;
				}
			}
		} else {
			foreach ($leaveScopes as $name) {
				if ($name === Partitions::DEFAULT_NAME) {
					$errors[] = 'The default partition cannot be left behind: PCP needs it. Leave its pages behind by keeping only other scopes.';
					continue;
				}
				$partitions[$name] = true;
			}
		}

		$matched = static function (string $pattern) use ($ids): array {
			if (str_ends_with($pattern, '*')) {
				$prefix = substr($pattern, 0, -1);

				return array_values(array_filter(array_keys($ids), static fn (string $id): bool => $prefix !== '' && str_starts_with($id, $prefix)));
			}

			return isset($ids[$pattern]) ? [$pattern] : [];
		};

		$principals = [];
		$named      = [];
		foreach (array_merge($leavePeople, $keepPeople) as $pattern) {
			$found = $matched($pattern);
			// A pattern may match nobody (no /try login yet); a mistyped id is an error.
			if ($found === [] && ! str_ends_with($pattern, '*')) {
				$errors[] = sprintf('No person or agent of the archive is %s.', $pattern);
			}
			foreach ($found as $id) {
				$named[$id] = true;
			}
		}
		if ($keepPeople !== []) {
			foreach (array_keys($ids) as $id) {
				if (! isset($named[$id])) {
					$principals[$id] = true;
				}
			}
		} else {
			$principals = $named;
		}
		if ($bindAs !== '' && isset($principals[$bindAs])) {
			$errors[] = sprintf('You are migrating as %s, who cannot be left behind.', $bindAs);
		}

		return [new self($partitions, $principals), $errors];
	}

	/**
	 * #550: moving scopes into an existing brain brings only the chosen
	 * partitions, the default one included only when chosen, and every
	 * person (the people stay as they are; moving decides their accounts).
	 *
	 * @param mixed                $scopes  The partitions to move: a list, or comma-separated text.
	 * @param array<string, mixed> $context The archive's context.json.
	 * @return array{0:self, 1:array<int, string>, 2:array<int, string>} The selection, the errors, the partitions moved.
	 */
	public static function only($scopes, array $context): array {
		$chosen = self::list($scopes);
		$errors = $chosen === [] ? ['Name the scopes (archive partitions) to move.'] : [];

		$names = [];
		foreach ((array) ($context['partitions'] ?? []) as $partition) {
			$names[(string) ($partition['name'] ?? '')] = true;
		}
		foreach ($chosen as $name) {
			if (! isset($names[$name])) {
				$errors[] = sprintf('The archive has no scope (partition) %s.', $name);
			}
		}

		$left = [];
		foreach (array_keys($names) as $name) {
			if (! in_array($name, $chosen, true)) {
				$left[$name] = true;
			}
		}

		return [new self($left, []), $errors, $chosen];
	}

	/**
	 * @param mixed $value
	 * @return array<int, string>
	 */
	private static function list($value): array {
		$items = is_array($value) ? $value : explode(',', (string) $value);

		return array_values(array_unique(array_filter(array_map(static fn ($item): string => trim((string) $item), $items), static fn (string $item): bool => $item !== '')));
	}

	public function isEmpty(): bool {
		return $this->partitions === [] && $this->principals === [];
	}

	public function leavesPartition(string $name): bool {
		return isset($this->partitions[$name]);
	}

	/**
	 * @param mixed $principal
	 */
	public function leavesPrincipal($principal): bool {
		return is_string($principal) && isset($this->principals[$principal]);
	}

	/** @return array<int, string> */
	public function partitions(): array {
		return array_keys($this->partitions);
	}

	/** @return array<int, string> */
	public function principals(): array {
		return array_keys($this->principals);
	}

	/**
	 * The archive check without what is left behind: pages and their
	 * history in those partitions, the partitions and the access entries
	 * that name them or the people left behind, and those people as removed
	 * principals. Extension records are left behind by ExtensionRestore,
	 * audit entries by RestoreWriter.
	 *
	 * @param array<string, mixed> $check ArchiveCheck::run() result.
	 * @return array<string, mixed>
	 */
	public function apply(array $check): array {
		if ($this->isEmpty()) {
			return $check;
		}

		foreach ($check['pages'] as $path => $page) {
			if ($this->leavesPartition((string) ($page['frontmatter']['partition'] ?? Partitions::DEFAULT_NAME))) {
				unset($check['pages'][$path], $check['history'][$path]);
			}
		}

		$partitions = [];
		foreach ((array) $check['context']['partitions'] as $partition) {
			if ($this->leavesPartition((string) $partition['name'])) {
				continue;
			}
			$partition['access'] = array_values(array_filter(
				(array) ($partition['access'] ?? []),
				fn ($access): bool => ! $this->leavesPrincipal($access['principal'] ?? null)
			));
			$partitions[] = $partition;
		}
		$check['context']['partitions'] = $partitions;

		foreach ((array) $check['context']['principals'] as $i => $principal) {
			if ($this->leavesPrincipal($principal['id'] ?? null)) {
				$check['context']['principals'][$i]['status'] = Principals::STATUS_REMOVED;
				unset($check['context']['principals'][$i]['roles']);
			}
		}

		$proposals = $check['context']['x_agent_brain']['agent_proposals'] ?? null;
		if (is_array($proposals)) {
			$check['context']['x_agent_brain']['agent_proposals'] = array_values(array_filter($proposals, function ($proposal): bool {
				if (! is_array($proposal) || $this->leavesPrincipal($proposal['proposed_by'] ?? null) || $this->leavesPrincipal($proposal['via_agent'] ?? null)) {
					return false;
				}
				$names = array_merge((array) ($proposal['read_partitions'] ?? []), (array) ($proposal['write_partitions'] ?? []));

				return $names === [] || array_filter($names, fn ($name): bool => ! $this->leavesPartition((string) $name)) !== [];
			}));
		}

		return $check;
	}

	/**
	 * What stays behind, in counts, for the dry run and the notes.
	 *
	 * @param array<string, mixed> $check The archive check before apply().
	 * @return array<int, string>
	 */
	public function describe(array $check): array {
		if ($this->isEmpty()) {
			return [];
		}

		$pages = 0;
		foreach ($check['pages'] as $page) {
			if ($this->leavesPartition((string) ($page['frontmatter']['partition'] ?? Partitions::DEFAULT_NAME))) {
				++$pages;
			}
		}

		$notes = [];
		if ($this->partitions !== []) {
			$notes[] = sprintf('Left behind: the scopes %s, with %d pages and every artifact, handoff and page store document in them.', implode(', ', $this->partitions()), $pages);
		}
		if ($this->principals !== []) {
			$notes[] = sprintf('Left behind: %d people and agents (%s), with no account, no access and none of their audit entries; what they wrote in the scopes that move keeps their id, as a removed principal.', count($this->principals), implode(', ', array_slice($this->principals(), 0, 20)) . (count($this->principals) > 20 ? ', …' : ''));
		}

		return $notes;
	}

	/**
	 * Every scope and person of the archive, so the person migrating can
	 * choose what to leave behind (dry run).
	 *
	 * @param array<string, mixed> $check
	 * @return array<int, string>
	 */
	public static function inventory(array $check): array {
		$pages = [];
		foreach ($check['pages'] as $page) {
			$name         = (string) ($page['frontmatter']['partition'] ?? Partitions::DEFAULT_NAME);
			$pages[$name] = ($pages[$name] ?? 0) + 1;
		}

		$scopes = [];
		foreach ((array) $check['context']['partitions'] as $partition) {
			$name     = (string) $partition['name'];
			$scopes[] = sprintf('%s (%d pages)', $name, $pages[$name] ?? 0);
		}
		$people = [];
		foreach ((array) $check['context']['principals'] as $principal) {
			$id = (string) ($principal['id'] ?? '');
			if ((str_starts_with($id, 'human:') || str_starts_with($id, 'agent:')) && ($principal['status'] ?? '') !== Principals::STATUS_REMOVED) {
				$people[] = $id;
			}
		}

		return [
			'Scopes in the archive: ' . implode('; ', $scopes) . '.',
			'People and agents in the archive: ' . implode(', ', $people) . '.',
		];
	}
}
