<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * The brain's export signing key (PROV-8, #177): an HMAC-SHA-256 key with
 * which every export signs its manifest, so a brain importing or restoring
 * an archive can tell "exported by that brain, unaltered" from "tampered
 * with after export, or signed by someone else" (DigestKey in Forget is the
 * established pattern for a per-brain key).
 *
 * The decision on the epic (#169): the key is per-brain, generated on the
 * first export — not controller-held — so signatures keep verifying after a
 * move to a different host or organisation, where the controller
 * relationship may be gone.
 *
 * The key lives in the option `ab_export_signing_key` (autoload no), which
 * is outside every place a copy is made: backups dump only the ab_* tables
 * and exports carry no settings or key material. So it is never exported or
 * backed up; a restored brain gets its own key. The signature itself travels
 * in the archive (manifest.signature.json), and keys retired by rotate()
 * stay in the option, so older archives keep verifying after a rotation.
 */
final class SigningKey {

	public const OPTION  = 'ab_export_signing_key';
	public const ALG     = 'hmac-sha256';
	public const PURPOSE = 'agent-brain.export-manifest.v1';

	/** Retired keys are kept for verifying older archives, up to this many rotations. */
	private const MAX_RETIRED = 8;

	/** @var array{current:array{id:string, key:string}, retired:array<int, array{id:string, key:string}>}|null */
	private static ?array $cache = null;

	/**
	 * The key id signature documents record ("sk-" and 12 random hex
	 * characters, not derived from the key).
	 */
	public static function id(): string {
		return self::load()['current']['id'];
	}

	/**
	 * The first 16 hex characters of the key's SHA-256: safe to show in
	 * wp-admin and CLI, enough to tell two of the brain's keys apart.
	 */
	public static function fingerprint(): string {
		return substr(hash('sha256', self::raw()), 0, 16);
	}

	/**
	 * The raw key. Never log, store or send it.
	 */
	public static function raw(): string {
		return self::load()['current']['key'];
	}

	/**
	 * HMAC-SHA-256 of the manifest's SHA-256 (its 64 lowercase hex
	 * characters) for the export-manifest purpose, under the current key.
	 */
	public static function sign(string $manifestSha256): string {
		return self::mac(self::load()['current']['key'], $manifestSha256);
	}

	/**
	 * Whether $signature is this brain's HMAC of $manifestSha256 under the
	 * key named $keyId — the current key or a retired one, so archives of
	 * earlier key eras keep verifying. Compared in constant time; an
	 * archive signed by another brain has an unknown key id and never
	 * verifies here.
	 */
	public static function verify(string $manifestSha256, string $keyId, string $signature): bool {
		$keys      = self::load();
		$candidate = array_merge([$keys['current']], $keys['retired']);

		foreach ($candidate as $key) {
			if ($key['id'] !== $keyId) {
				continue;
			}

			return hash_equals(self::mac($key['key'], $manifestSha256), strtolower($signature));
		}

		return false;
	}

	/** Whether $keyId is one of this brain's keys, current or retired. */
	public static function knows(string $keyId): bool {
		$keys = self::load();
		foreach (array_merge([$keys['current']], $keys['retired']) as $key) {
			if ($key['id'] === $keyId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rotates the key: the current one moves to the retired list (which is
	 * cut at MAX_RETIRED oldest) and a new key is generated. Exports after
	 * this are signed with the new key; archives signed before keep
	 * verifying while their key stays retired here.
	 */
	public static function rotate(): void {
		$loaded  = self::load();
		$retired = array_merge([$loaded['current']], $loaded['retired']);
		$retired = array_slice($retired, 0, self::MAX_RETIRED);

		self::save(['current' => self::generate(), 'retired' => $retired]);
	}

	/** Forgets the cached keys (tests, and after the option changes). */
	public static function reset(): void {
		self::$cache = null;
	}

	private static function mac(string $key, string $manifestSha256): string {
		return hash_hmac('sha256', self::PURPOSE . "\0" . $manifestSha256, $key);
	}

	/**
	 * @return array{id:string, key:string}
	 */
	private static function generate(): array {
		return ['id' => 'sk-' . bin2hex(random_bytes(6)), 'key' => random_bytes(32)];
	}

	/**
	 * @return array{current:array{id:string, key:string}, retired:array<int, array{id:string, key:string}>}
	 */
	private static function load(): array {
		if (self::$cache !== null) {
			return self::$cache;
		}

		$stored = self::parse(get_option(self::OPTION));
		if ($stored !== null) {
			self::$cache = $stored;

			return $stored;
		}

		$keys = ['current' => self::generate(), 'retired' => []];

		// add_option first, so two requests making the first key race to one winner.
		if (! add_option(self::OPTION, self::encode($keys), '', false)) {
			wp_cache_delete(self::OPTION, 'options');
			$stored = self::parse(get_option(self::OPTION));
			if ($stored !== null) {
				self::$cache = $stored;

				return $stored;
			}
			update_option(self::OPTION, self::encode($keys), false);
		}

		self::$cache = $keys;

		return $keys;
	}

	/**
	 * @param array{current:array{id:string, key:string}, retired:array<int, array{id:string, key:string}>} $keys
	 */
	private static function save(array $keys): void {
		update_option(self::OPTION, self::encode($keys), false);
		self::$cache = $keys;
	}

	/**
	 * @param array{current:array{id:string, key:string}, retired:array<int, array{id:string, key:string}>} $keys
	 * @return array{current:array{id:string, key:string}, retired:array<int, array{id:string, key:string}>}
	 */
	private static function encode(array $keys): array {
		$current = ['id' => $keys['current']['id'], 'key' => base64_encode($keys['current']['key'])];

		$retired = [];
		foreach ($keys['retired'] as $key) {
			$retired[] = ['id' => $key['id'], 'key' => base64_encode($key['key'])];
		}

		return ['current' => $current, 'retired' => $retired];
	}

	/**
	 * @param mixed $stored
	 * @return array{current:array{id:string, key:string}, retired:array<int, array{id:string, key:string}>}|null
	 */
	private static function parse($stored): ?array {
		if (! is_array($stored) || ! isset($stored['current']) || ! is_array($stored['current'])) {
			return null;
		}

		$current = self::parseKey($stored['current']);
		if ($current === null) {
			return null;
		}

		$retired = [];
		foreach ((array) ($stored['retired'] ?? []) as $key) {
			$parsed = is_array($key) ? self::parseKey($key) : null;
			if ($parsed !== null) {
				$retired[] = $parsed;
			}
		}

		return ['current' => $current, 'retired' => $retired];
	}

	/**
	 * @param mixed $stored
	 * @return array{id:string, key:string}|null
	 */
	private static function parseKey($stored): ?array {
		if (! is_array($stored) || ! isset($stored['id'], $stored['key']) || ! is_string($stored['id']) || ! is_string($stored['key'])) {
			return null;
		}

		$raw = base64_decode($stored['key'], true);
		if ($raw === false || strlen($raw) !== 32 || preg_match('/^sk-[a-f0-9]{12}$/', $stored['id']) !== 1) {
			return null;
		}

		return ['id' => $stored['id'], 'key' => $raw];
	}
}
