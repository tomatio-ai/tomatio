<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

/**
 * Owns the symmetric backup encryption key (`ab_backup_key`, autoload=no).
 *
 * The key is generated once, on first use, and is never sent to the cloud,
 * logged, or echoed anywhere except the explicit "download your backup key"
 * curator action (AdminActions::handleDownloadKey()). Losing it means every
 * existing encrypted backup is unrecoverable — there is no server-side
 * recovery path by design (the cloud never sees it).
 */
final class KeyManager {

	public const OPTION = 'ab_backup_key';

	/**
	 * Returns the raw (binary) secretstream key, generating and persisting
	 * one (base64-encoded, autoload=no) on first call.
	 */
	public static function getOrCreateKey(): string {
		$stored = get_option(self::OPTION);

		if (is_string($stored) && $stored !== '') {
			$raw = base64_decode($stored, true);

			if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
				return $raw;
			}
		}

		$raw = sodium_crypto_secretstream_xchacha20poly1305_keygen();

		update_option(self::OPTION, base64_encode($raw), false);

		do_action('agent_brain/audit', [
			'actor_id'    => function_exists('get_current_user_id') ? get_current_user_id() : 0,
			'event'       => 'backup.key_generated',
			'status'      => 'ok',
			'object_type' => 'backup_key',
			'object_id'   => null,
			// Never include the key itself in audit meta.
			'meta'        => [],
		]);

		return $raw;
	}

	public static function exists(): bool {
		$stored = get_option(self::OPTION);

		return is_string($stored) && $stored !== '';
	}
}
