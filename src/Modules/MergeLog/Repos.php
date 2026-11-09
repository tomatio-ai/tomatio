<?php

declare(strict_types=1);

namespace AgentBrain\Modules\MergeLog;

use AgentBrain\Core\SecretBox;
use AgentBrain\Core\SecretBoxException;

/**
 * The repositories that report merges (#870). One option (autoload=no)
 * maps a random 16-character id, which is part of the webhook address, to
 * the repository, the scope and agent its rows are saved under, the
 * webhook secret, an optional read token and (#885) the TíoDay its merges
 * are also recorded on (`tioday`: an artifact id, 0 for none, absent or
 * null for automatic: the TíoDay its agent leads, see TioDaySync::target).
 * Secret and token are stored as SecretBox envelopes, never as plaintext.
 */
final class Repos {

	public const OPTION  = 'ab_merge_log_repos';
	public const PURPOSE = 'merge-log';

	/**
	 * @return array<string, array{repo:string, scope_id:int, agent_id:int, secret:string, token:string, tioday?:int|null}>
	 */
	public static function all(): array {
		$stored = get_option(self::OPTION, []);

		return is_array($stored) ? $stored : [];
	}

	/** @return array{repo:string, scope_id:int, agent_id:int, secret:string, token:string, tioday?:int|null}|null */
	public static function get(string $id): ?array {
		return self::all()[$id] ?? null;
	}

	/** owner/name, lower case, or '' when $repo is not one. */
	public static function normalize(string $repo): string {
		$repo = strtolower(trim($repo));

		return preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $repo) === 1 ? $repo : '';
	}

	/**
	 * Adds a repository and returns [id, secret]; the secret is shown once.
	 *
	 * @return array{0:string, 1:string}
	 */
	public static function add(string $repo, int $scopeId, int $agentId, string $token, ?int $tioday = null): array {
		$id     = strtolower(wp_generate_password(16, false));
		$secret = wp_generate_password(40, false);
		$all    = self::all();

		$all[$id] = [
			'repo'     => $repo,
			'scope_id' => $scopeId,
			'agent_id' => $agentId,
			'secret'   => SecretBox::seal($secret, SecretBox::currentMaterial(), self::PURPOSE),
			'token'    => $token !== '' ? SecretBox::seal($token, SecretBox::currentMaterial(), self::PURPOSE) : '',
			'tioday'   => $tioday === null ? null : max(0, $tioday),
		];
		update_option(self::OPTION, $all, false);

		return [$id, $secret];
	}

	/**
	 * #885: the TíoDay chosen for a repository: an artifact id, 0 for none,
	 * null for automatic (also every repository added before 1.9.13).
	 *
	 * @param array<string, mixed> $repo
	 */
	public static function tioday(array $repo): ?int {
		$chosen = $repo['tioday'] ?? null;

		return is_numeric($chosen) ? max(0, (int) $chosen) : null;
	}

	/** #885: choose the TíoDay of repository $id: an artifact id, 0 for none, null for automatic. */
	public static function setTioDay(string $id, ?int $artifactId): bool {
		$all = self::all();
		if (! isset($all[$id])) {
			return false;
		}
		$all[$id]['tioday'] = $artifactId === null ? null : max(0, $artifactId);
		update_option(self::OPTION, $all, false);

		return true;
	}

	public static function remove(string $id): bool {
		$all = self::all();
		if (! isset($all[$id])) {
			return false;
		}
		unset($all[$id]);
		update_option(self::OPTION, $all, false);

		return true;
	}

	/** The plaintext of a stored envelope, '' when empty or unreadable. */
	public static function open(string $envelope): string {
		if ($envelope === '') {
			return '';
		}
		foreach ([SecretBox::currentMaterial(), SecretBox::previousMaterial()] as $material) {
			if ($material === null) {
				continue;
			}
			try {
				return SecretBox::open($envelope, $material, self::PURPOSE);
			} catch (SecretBoxException $e) {
				continue;
			}
		}

		return '';
	}

	/** The webhook address for repository $id. */
	public static function url(string $id): string {
		return rest_url(\AgentBrain\Core\RestNamespaces::ADVERTISED . '/merge-log/' . $id);
	}
}
