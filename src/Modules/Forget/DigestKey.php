<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Forget;

/**
 * The brain's erasure digest key (PCP v0.3 SPEC 8.9.3 and 8.9.5): an
 * HMAC-SHA-256 key that turns a forgotten subject, and each erased page's
 * id, path and body, into keyed digests. A plain SHA-256 of a short name is
 * reversed by guessing; a keyed digest is not, as long as the key stays here.
 *
 * The key lives in the option `ab_erasure_key` (autoload no), which is
 * outside every place a copy is made: backups dump only the ab_* tables and
 * exports carry no settings. So it is never exported or backed up, and a
 * restored brain gets a new key (old receipts then keep their digest, but
 * it can no longer be confirmed there).
 *
 * Every digest is domain separated by a purpose ("subject", "page.id",
 * "page.path", "page.body", "ability.args"), so a digest made for one use
 * never matches another.
 */
final class DigestKey {

	public const OPTION = 'ab_erasure_key';
	public const ALG    = 'hmac-sha256';

	/** @var array{id:string, key:string}|null */
	private static ?array $cache = null;

	/**
	 * The key id receipts and tombstones record ("ek-" and 12 random hex
	 * characters, not derived from the key).
	 */
	public static function id(): string {
		return self::load()['id'];
	}

	/** HMAC-SHA-256 of $value for $purpose under the brain's key, lowercase hex. */
	public static function digest(string $purpose, string $value): string {
		return self::digestWith(self::load()['key'], $purpose, $value);
	}

	/** Pure: the same digest under a given raw key (unit tests). */
	public static function digestWith(string $key, string $purpose, string $value): string {
		return hash_hmac('sha256', $purpose . "\0" . $value, $key);
	}

	/**
	 * The raw key, for callers that digest many values in a pure helper
	 * (AbilityListener::hashInput()). Never log, store or send it.
	 */
	public static function raw(): string {
		return self::load()['key'];
	}

	/**
	 * How a subject is normalized before it is digested, so the same
	 * subject typed again gives the same digest: Unicode NFC when the intl
	 * extension is there, surrounding whitespace trimmed, every run of
	 * whitespace made one space, then lowercased (forget matches case
	 * insensitively, so "Ada Lovelace" and "ada  lovelace" are one subject).
	 */
	public static function normalizeSubject(string $subject): string {
		if (class_exists('\Normalizer')) {
			$nfc = \Normalizer::normalize($subject, \Normalizer::FORM_C);
			if (is_string($nfc)) {
				$subject = $nfc;
			}
		}

		$subject = (string) preg_replace('/\s+/u', ' ', trim($subject));

		return function_exists('mb_strtolower') ? mb_strtolower($subject, 'UTF-8') : strtolower($subject);
	}

	public static function subjectDigest(string $subject): string {
		return self::digest('subject', self::normalizeSubject($subject));
	}

	/** Forgets the cached key (tests, and after the option changes). */
	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * @return array{id:string, key:string}
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

		$key    = ['id' => 'ek-' . bin2hex(random_bytes(6)), 'key' => random_bytes(32)];
		$option = ['id' => $key['id'], 'key' => base64_encode($key['key'])];

		// add_option first, so two requests making the first key race to one winner.
		if (! add_option(self::OPTION, $option, '', false)) {
			wp_cache_delete(self::OPTION, 'options');
			$stored = self::parse(get_option(self::OPTION));
			if ($stored !== null) {
				self::$cache = $stored;

				return $stored;
			}
			update_option(self::OPTION, $option, false);
		}

		self::$cache = $key;

		return $key;
	}

	/**
	 * @param mixed $stored
	 * @return array{id:string, key:string}|null
	 */
	private static function parse($stored): ?array {
		if (! is_array($stored) || ! isset($stored['id'], $stored['key']) || ! is_string($stored['id']) || ! is_string($stored['key'])) {
			return null;
		}

		$raw = base64_decode($stored['key'], true);
		if ($raw === false || strlen($raw) !== 32 || preg_match('/^ek-[a-f0-9]{12}$/', $stored['id']) !== 1) {
			return null;
		}

		return ['id' => $stored['id'], 'key' => $raw];
	}
}
