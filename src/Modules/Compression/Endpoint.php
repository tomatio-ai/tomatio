<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

use AgentBrain\Core\SecretBox;
use AgentBrain\Core\SecretBoxException;

/**
 * Stored configuration of the owner's OpenAI-compatible summarising endpoint
 * (OpenAI, Cloudflare Workers AI / AI Gateway, a local server, ...).
 *
 * The API key is the only secret. It is kept in `ab_openai_api_key`
 * (autoload=no) as a SecretBox envelope, never as plaintext, and it is never
 * returned by any getter that reaches the browser: the settings screen only
 * ever sees keyStatus(). Only OpenAiCompatibleProvider calls apiKey(), to
 * build the Authorization header. The `ab_*` option prefix means the key is
 * also removed on uninstall, and it is not part of the backup (which dumps
 * only `ab_*` tables) or of the memory export.
 */
final class Endpoint {

	public const OPTION_URL   = 'ab_openai_base_url';
	public const OPTION_MODEL = 'ab_openai_model';
	public const OPTION_KEY   = 'ab_openai_api_key';
	public const OPTION_LOCAL = 'ab_openai_allow_local';

	public const PURPOSE = 'openai-endpoint-api-key';

	public const KEY_NONE    = 'none';
	public const KEY_OK      = 'ok';
	public const KEY_CHANGED = 'key_changed';
	public const KEY_BROKEN  = 'unreadable';

	public static function baseUrl(): string {
		$value = get_option(self::OPTION_URL, '');

		return is_string($value) ? $value : '';
	}

	public static function model(): string {
		$value = get_option(self::OPTION_MODEL, '');

		return is_string($value) ? $value : '';
	}

	public static function allowLocal(): bool {
		return (bool) get_option(self::OPTION_LOCAL, false);
	}

	/**
	 * Whether anything has been entered; says nothing about whether it works.
	 */
	public static function isSet(): bool {
		return self::baseUrl() !== '' || self::model() !== '' || self::hasStoredKey();
	}

	public static function hasStoredKey(): bool {
		$stored = get_option(self::OPTION_KEY, '');

		return is_string($stored) && $stored !== '';
	}

	/**
	 * What state the stored key is in, without exposing it.
	 *
	 * @return 'none'|'ok'|'key_changed'|'unreadable'
	 */
	public static function keyStatus(): string {
		if (! self::hasStoredKey()) {
			return self::KEY_NONE;
		}

		$result = self::readKey();

		return $result instanceof \WP_Error ? (string) $result->get_error_data() : self::KEY_OK;
	}

	/**
	 * Usable by Compression: a URL that passes the guard, a model, and either
	 * a readable key or (for an own-network server) no key at all.
	 */
	public static function isConfigured(): bool {
		if (self::baseUrl() === '' || self::model() === '') {
			return false;
		}

		$status = self::keyStatus();

		if ($status === self::KEY_OK) {
			return true;
		}

		return $status === self::KEY_NONE && self::allowLocal();
	}

	/**
	 * The decrypted key, or '' when none is stored. Server-side use only
	 * (request headers); never echo, log, audit or store the result.
	 */
	public static function apiKey(): string|\WP_Error {
		if (! self::hasStoredKey()) {
			return '';
		}

		return self::readKey();
	}

	/**
	 * @return string|\WP_Error Plaintext, or a WP_Error whose data is a KEY_* status.
	 */
	private static function readKey(): string|\WP_Error {
		$stored = (string) get_option(self::OPTION_KEY, '');

		try {
			return SecretBox::open($stored, SecretBox::currentMaterial(), self::PURPOSE);
		} catch (SecretBoxException $e) {
			if ($e->getReason() === SecretBox::REASON_KEY_CHANGED) {
				$rotated = self::openWithPrevious($stored);
				if ($rotated !== null) {
					return $rotated;
				}

				return new \WP_Error(
					'ab_endpoint_key_changed',
					'The stored API key was encrypted with different site key material (wp-config.php salts or AGENT_BRAIN_SECRET_KEY changed). Enter the API key again.',
					self::KEY_CHANGED
				);
			}

			return new \WP_Error(
				'ab_endpoint_key_unreadable',
				'The stored API key could not be decrypted (' . $e->getReason() . '). Enter the API key again.',
				self::KEY_BROKEN
			);
		}
	}

	/**
	 * Rotation: when AGENT_BRAIN_SECRET_KEY_PREVIOUS matches the envelope,
	 * open with it and immediately re-seal under the current material.
	 */
	private static function openWithPrevious(string $stored): ?string {
		$previous = SecretBox::previousMaterial();
		if ($previous === null) {
			return null;
		}

		try {
			$plain = SecretBox::open($stored, $previous, self::PURPOSE);
		} catch (SecretBoxException $e) {
			return null;
		}

		update_option(self::OPTION_KEY, SecretBox::seal($plain, SecretBox::currentMaterial(), self::PURPOSE), false);

		return $plain;
	}

	/**
	 * Validates and persists the settings. $newKey === null keeps the stored
	 * key; '' with $clearKey false also keeps it (blank means "unchanged").
	 *
	 * @return array{url_changed: bool, model_changed: bool, local_changed: bool, key: string}|\WP_Error key is 'unchanged'|'replaced'|'cleared'
	 */
	public static function save(string $url, string $model, bool $allowLocal, ?string $newKey, bool $clearKey): array|\WP_Error {
		$url   = UrlGuard::normalize($url);
		$model = trim($model);

		$error = UrlGuard::check($url, $allowLocal);
		if ($error !== null) {
			return new \WP_Error('ab_endpoint_' . $error, UrlGuard::message($error));
		}

		if ($model === '' || strlen($model) > 200 || preg_match('/[\x00-\x1F\x7F]/', $model) === 1) {
			return new \WP_Error('ab_endpoint_model', __('Enter the model name.', 'agent-brain'));
		}

		$newKey = $newKey !== null ? trim($newKey) : '';
		if (strlen($newKey) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $newKey) === 1) {
			return new \WP_Error('ab_endpoint_key_invalid', __('The API key contains characters that are not allowed.', 'agent-brain'));
		}

		// Seal first so a crypto failure leaves nothing half-saved.
		$sealed = null;
		if ($newKey !== '') {
			try {
				$sealed = SecretBox::seal($newKey, SecretBox::currentMaterial(), self::PURPOSE);
			} catch (SecretBoxException $e) {
				return new \WP_Error('ab_endpoint_encrypt', __('The API key could not be encrypted because the site has no usable key material. Set AUTH_KEY and AUTH_SALT in wp-config.php.', 'agent-brain'));
			}
		}

		$result = [
			'url_changed'   => $url !== self::baseUrl(),
			'model_changed' => $model !== self::model(),
			'local_changed' => $allowLocal !== self::allowLocal(),
			'key'           => 'unchanged',
		];

		update_option(self::OPTION_URL, $url, false);
		update_option(self::OPTION_MODEL, $model, false);
		update_option(self::OPTION_LOCAL, $allowLocal ? 1 : 0, false);

		if ($sealed !== null) {
			update_option(self::OPTION_KEY, $sealed, false);
			$result['key'] = 'replaced';
		} elseif ($clearKey && self::hasStoredKey()) {
			delete_option(self::OPTION_KEY);
			$result['key'] = 'cleared';
		}

		return $result;
	}

	/**
	 * Removes the whole endpoint configuration including the key.
	 */
	public static function clearAll(): void {
		delete_option(self::OPTION_URL);
		delete_option(self::OPTION_MODEL);
		delete_option(self::OPTION_KEY);
		delete_option(self::OPTION_LOCAL);
	}
}
