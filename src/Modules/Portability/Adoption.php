<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * #582: an account on this brain that already holds a principal id of the
 * archive takes that person over when a whole brain is migrated here, if
 * the person migrating asks for it (adopt_accounts, --adopt-accounts, the
 * checkbox on "Migrate a brain here"). Without it the migration is refused,
 * as before, naming the account and this option.
 *
 * Matched on principal id only. The account keeps what is its own: login,
 * email, password, sessions, application passwords and WordPress role (an
 * account that has already signed in keeps working). It takes on the
 * archive person's registry entry, their access to the migrated scopes and
 * the authorship of everything they wrote, with its history; an agent
 * account also gets the archive agent's identity. A migration needs an
 * empty brain, so the account cannot have TomaTío content of its own to mix
 * with the archive's; other WordPress content it wrote stays as it is.
 *
 * Refused: a person the archive lists as removed (there is nobody to take
 * over), a person the migration leaves behind, and an id held here by a
 * registry entry with no account.
 */
final class Adoption {

	/**
	 * @param array<string, mixed> $context The archive's context.json.
	 * @return array{0:array<string, int>, 1:array<int, string>, 2:array<int, string>} principal id => account here; errors; notes for the report.
	 */
	public static function plan(array $context, int $requester, string $bindAs, bool $enabled, Selection $selection): array {
		$listed = [];
		foreach ((array) $context['principals'] as $principal) {
			$listed[(string) ($principal['id'] ?? '')] = $principal;
		}
		$own = $requester > 0 ? Principals::idFor($requester) : null;

		$adopt  = [];
		$errors = [];
		$notes  = [];
		foreach (Principals::all() as $row) {
			$id = $row['principal_id'];
			if (! isset($listed[$id]) || $id === $own || $id === $bindAs) {
				continue;
			}
			$human = str_starts_with($id, 'human:');
			if (! $human && ! str_starts_with($id, 'agent:')) {
				continue;
			}

			$user = $row['user_id'] > 0 ? get_userdata($row['user_id']) : false;
			if (! $user instanceof \WP_User) {
				$errors[] = sprintf('The principal id %s is already held on this brain, by no account.', $id);
				continue;
			}
			if (! $enabled) {
				$errors[] = sprintf('The principal id %1$s is already held on this brain by the account %2$s. To let that account take over %1$s from the archive, migrate with "Let accounts here take over their archive identity" (--adopt-accounts).', $id, $user->user_login);
				continue;
			}
			if (($listed[$id]['status'] ?? '') === Principals::STATUS_REMOVED) {
				$errors[] = sprintf('The account %1$s cannot take over %2$s: the archive lists %2$s as removed.', $user->user_login, $id);
				continue;
			}
			if ($selection->leavesPrincipal($id)) {
				$errors[] = sprintf('The account %1$s cannot take over %2$s: this migration leaves %2$s behind.', $user->user_login, $id);
				continue;
			}

			$adopt[$id] = (int) $user->ID;
			$notes[]    = self::describe($id, $user, $listed[$id]);
		}

		return [$adopt, $errors, $notes];
	}

	/**
	 * What an account keeps and gains, for the dry run.
	 *
	 * @param array<string, mixed> $principal The archive's entry.
	 */
	private static function describe(string $id, \WP_User $user, array $principal): string {
		$roles   = array_values(array_map('strval', (array) $user->roles));
		$archive = array_values(array_map('strval', (array) ($principal['roles'] ?? [])));
		sort($roles, SORT_STRING);
		sort($archive, SORT_STRING);

		$note = sprintf(
			'The account %1$s (user %2$d) takes over %3$s from the archive. It keeps its login, email, password, sessions, application passwords and role (%4$s); it gains the access %3$s had to the migrated scopes and the authorship of what %3$s wrote, with its history.',
			$user->user_login,
			$user->ID,
			$id,
			$roles !== [] ? implode(', ', $roles) : 'none'
		);
		if ($archive !== [] && $archive !== $roles) {
			$note .= sprintf(' The archive gave %1$s the role %2$s; the account keeps its own.', $id, implode(', ', $archive));
		}
		$posts = (int) count_user_posts($user->ID, ['post', 'page']);
		if ($posts > 0) {
			$note .= sprintf(' Its %d WordPress posts and pages stay as they are.', $posts);
		}

		return $note;
	}
}
