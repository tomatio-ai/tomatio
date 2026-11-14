<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Cloud;

/**
 * Ed25519 primitives for the cloud signing protocol v1 (contracts.md).
 *
 * Pure PHP/sodium, no WordPress dependency, so this class is safe to
 * exercise from the unit test suite (no WP boot required).
 */
final class Signer {

	/**
	 * @return array{sk: string, pk: string} base64 (standard, padded) keys.
	 */
	public static function generateKeypair(): array {
		$keypair = sodium_crypto_sign_keypair();

		return [
			'sk' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
			'pk' => base64_encode(sodium_crypto_sign_publickey($keypair)),
		];
	}

	/**
	 * Exact canonical message per contracts.md: 'ab1\n'+site_id+'\n'+ts+'\n'+nonce+'\n'+hex(sha256(payload)).
	 */
	public static function canonicalMessage(string $siteId, int $ts, string $nonce, string $payloadJson): string {
		return "ab1\n" . $siteId . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $payloadJson);
	}

	public static function sign(string $message, string $skBase64): string {
		$sk = base64_decode($skBase64, true);

		if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
			throw new \InvalidArgumentException('Invalid Ed25519 secret key.');
		}

		return base64_encode(sodium_crypto_sign_detached($message, $sk));
	}

	public static function verify(string $message, string $sigBase64, string $pkBase64): bool {
		$sig = base64_decode($sigBase64, true);
		$pk  = base64_decode($pkBase64, true);

		if ($sig === false || $pk === false) {
			return false;
		}

		if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
			return false;
		}

		return sodium_crypto_sign_verify_detached($sig, $message, $pk);
	}

	/**
	 * 16+ random bytes, base64url (unpadded) — matches the cloud worker's randomNonce().
	 */
	public static function generateNonce(): string {
		$encoded = base64_encode(random_bytes(16));

		return rtrim(strtr($encoded, '+/', '-_'), '=');
	}
}
