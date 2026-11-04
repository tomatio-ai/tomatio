<?php

declare(strict_types=1);

namespace AgentBrain\Core;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are static strings, never output as HTML.

/**
 * Authenticated encryption for small secrets stored in the database (for
 * example the OpenAI-compatible endpoint API key), unlike WordPress core's
 * AI settings which keep keys in plain options.
 *
 * Cipher: libsodium sodium_crypto_secretbox (XSalsa20-Poly1305), a fresh
 * random 24 byte nonce per seal.
 *
 * Key derivation (HKDF-SHA256, RFC 5869, via hash_hkdf):
 *
 *   key = HKDF(ikm = key material, length = 32,
 *              info = "agent-brain/secretbox/v1/" . purpose,
 *              salt = "agent-brain-secretbox-hkdf-salt-v1")
 *
 * The key material is, in order of preference, the AGENT_BRAIN_SECRET_KEY
 * constant (a dedicated secret in wp-config.php) or wp_salt('auth') (the
 * AUTH_KEY and AUTH_SALT values from wp-config.php). Because the purpose is
 * part of the HKDF info, a secret sealed for one purpose cannot be opened as
 * another even with the same material.
 *
 * Envelope (opaque, versioned, ASCII, safe in a text column):
 *
 *   ab1.<key id>.<base64url(nonce . ciphertext)>
 *
 * The key id is 4 bytes of a second HKDF output (different info), it is not
 * derived from the encryption key and reveals nothing usable. It lets
 * open() tell "the key material changed" (key id differs) from "the value
 * was tampered with" (key id matches but authentication fails) and report
 * each clearly instead of failing silently.
 *
 * This class is WP-free apart from the two material helpers at the bottom,
 * so it is unit-testable.
 */
final class SecretBox {

	public const VERSION = 'ab1';

	public const REASON_MALFORMED   = 'malformed';
	public const REASON_VERSION     = 'unsupported_version';
	public const REASON_KEY_CHANGED = 'key_changed';
	public const REASON_TAMPERED    = 'tampered';
	public const REASON_WEAK_KEY    = 'weak_key_material';

	private const INFO_PREFIX = 'agent-brain/secretbox/v1/';
	private const HKDF_SALT   = 'agent-brain-secretbox-hkdf-salt-v1';
	private const MIN_MATERIAL = 16;

	/**
	 * Derives the 32 byte secretbox key for a purpose.
	 */
	public static function deriveKey(string $material, string $purpose): string {
		self::assertMaterial($material);

		return hash_hkdf('sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::INFO_PREFIX . $purpose, self::HKDF_SALT);
	}

	/**
	 * Short public identifier of a key (8 hex chars), independent of the
	 * encryption key itself.
	 */
	public static function keyId(string $material, string $purpose): string {
		self::assertMaterial($material);

		return bin2hex(hash_hkdf('sha256', $material, 4, self::INFO_PREFIX . 'kid/' . $purpose, self::HKDF_SALT));
	}

	public static function seal(string $plaintext, string $material, string $purpose): string {
		$key   = self::deriveKey($material, $purpose);
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$box   = sodium_crypto_secretbox($plaintext, $nonce, $key);

		sodium_memzero($key);

		return self::VERSION . '.' . self::keyId($material, $purpose) . '.' . self::b64uEncode($nonce . $box);
	}

	/**
	 * @throws SecretBoxException With a REASON_* code; never leaks the key or the value.
	 */
	public static function open(string $envelope, string $material, string $purpose): string {
		$parts = explode('.', $envelope);

		if (count($parts) !== 3) {
			throw new SecretBoxException(self::REASON_MALFORMED, 'The stored secret is not a valid envelope.');
		}

		[$version, $kid, $payload] = $parts;

		if ($version !== self::VERSION) {
			throw new SecretBoxException(self::REASON_VERSION, 'The stored secret uses an unsupported format version.');
		}

		if (! hash_equals(self::keyId($material, $purpose), $kid)) {
			throw new SecretBoxException(self::REASON_KEY_CHANGED, 'The stored secret was sealed with different key material.');
		}

		$raw = self::b64uDecode($payload);

		if ($raw === null || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
			throw new SecretBoxException(self::REASON_MALFORMED, 'The stored secret is not a valid envelope.');
		}

		$key       = self::deriveKey($material, $purpose);
		$plaintext = sodium_crypto_secretbox_open(
			substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
			substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
			$key
		);

		sodium_memzero($key);

		if ($plaintext === false) {
			throw new SecretBoxException(self::REASON_TAMPERED, 'The stored secret failed authentication and may have been modified.');
		}

		return $plaintext;
	}

	/**
	 * The key id recorded in an envelope, or null when it is not one of ours.
	 */
	public static function envelopeKeyId(string $envelope): ?string {
		$parts = explode('.', $envelope);

		return count($parts) === 3 && $parts[0] === self::VERSION && preg_match('/^[0-9a-f]{8}$/', $parts[1]) === 1 ? $parts[1] : null;
	}

	/**
	 * Current key material: the AGENT_BRAIN_SECRET_KEY constant when set,
	 * otherwise the site's wp-config.php AUTH_KEY + AUTH_SALT.
	 */
	public static function currentMaterial(): string {
		if (defined('AGENT_BRAIN_SECRET_KEY') && is_string(AGENT_BRAIN_SECRET_KEY) && AGENT_BRAIN_SECRET_KEY !== '') {
			return AGENT_BRAIN_SECRET_KEY;
		}

		return wp_salt('auth');
	}

	/**
	 * Optional previous material (AGENT_BRAIN_SECRET_KEY_PREVIOUS) so a key
	 * can be rotated without re-entering secrets: values sealed under it are
	 * opened and re-sealed under the current material on next use.
	 */
	public static function previousMaterial(): ?string {
		if (defined('AGENT_BRAIN_SECRET_KEY_PREVIOUS') && is_string(AGENT_BRAIN_SECRET_KEY_PREVIOUS) && AGENT_BRAIN_SECRET_KEY_PREVIOUS !== '') {
			return AGENT_BRAIN_SECRET_KEY_PREVIOUS;
		}

		return null;
	}

	private static function assertMaterial(string $material): void {
		if (strlen($material) < self::MIN_MATERIAL) {
			throw new SecretBoxException(self::REASON_WEAK_KEY, 'Key material is missing or too short.');
		}
	}

	private static function b64uEncode(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}

	private static function b64uDecode(string $text): ?string {
		$decoded = base64_decode(strtr($text, '-_', '+/'), true);

		return $decoded === false ? null : $decoded;
	}
}
