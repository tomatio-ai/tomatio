<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

/**
 * Streaming file encryption/decryption with libsodium's
 * crypto_secretstream_xchacha20poly1305 (authenticated, chunked, detects
 * tampering and truncation). Pure PHP/sodium — no WordPress dependency —
 * so this is exercisable from the WordPress-free unit suite.
 */
final class Encryptor {

	private const PLAINTEXT_CHUNK = 64 * 1024;

	/**
	 * Encrypts $plainPath into $cipherPath with $key
	 * (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES raw bytes).
	 * Streams from/to disk in bounded chunks.
	 */
	public static function encryptFile(string $plainPath, string $cipherPath, string $key): void {
		[$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

		$in = fopen($plainPath, 'rb');
		if ($in === false) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to open plaintext for encryption: {$plainPath}");
		}

		$out = fopen($cipherPath, 'wb');
		if ($out === false) {
			fclose($in);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to open cipher output: {$cipherPath}");
		}

		fwrite($out, $header);

		do {
			$chunk  = fread($in, self::PLAINTEXT_CHUNK);
			$isLast = feof($in);
			$tag    = $isLast
				? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
				: SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

			$cipherChunk = sodium_crypto_secretstream_xchacha20poly1305_push($state, (string) $chunk, '', $tag);
			fwrite($out, $cipherChunk);
		} while (! $isLast);

		fclose($in);
		fclose($out);
	}

	/**
	 * Decrypts $cipherPath into $plainPath with $key. Throws on any
	 * authentication failure (tampered or corrupt ciphertext) and removes
	 * the partial plaintext output before rethrowing — never leaves
	 * unverified plaintext behind.
	 */
	public static function decryptFile(string $cipherPath, string $plainPath, string $key): void {
		$in = fopen($cipherPath, 'rb');
		if ($in === false) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to open ciphertext for decryption: {$cipherPath}");
		}

		$header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
		if ($header === false || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
			fclose($in);
			throw new \RuntimeException('Backup ciphertext is truncated or missing its header.');
		}

		try {
			$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
		} catch (\SodiumException $exception) {
			fclose($in);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- static message, chained exception is not echoed.
			throw new \RuntimeException('Backup ciphertext header is invalid for this key.', 0, $exception);
		}

		$out = fopen($plainPath, 'wb');
		if ($out === false) {
			fclose($in);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal file path, not user-facing output.
			throw new \RuntimeException("Unable to open plaintext output: {$plainPath}");
		}

		$cipherChunkSize = self::PLAINTEXT_CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
		$sawFinal        = false;

		while (! feof($in)) {
			$chunk = fread($in, $cipherChunkSize);
			if ($chunk === false || $chunk === '') {
				break;
			}

			$result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);

			if ($result === false) {
				fclose($in);
				fclose($out);
				@unlink($plainPath);
				throw new \RuntimeException('Backup decryption failed: ciphertext is tampered or corrupt.');
			}

			[$decrypted, $tag] = $result;
			fwrite($out, $decrypted);

			if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
				$sawFinal = true;
				break;
			}
		}

		fclose($in);
		fclose($out);

		if (! $sawFinal) {
			@unlink($plainPath);
			throw new \RuntimeException('Backup decryption failed: ciphertext was truncated before its final chunk.');
		}
	}
}
