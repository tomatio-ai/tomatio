<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * M1 (the design contracts "Fix wave — mixed Kimi + Claude
 * team test", Unit M): which MCP client (name/version from the `initialize`
 * request's `clientInfo`) is acting on the current request.
 *
 * Captured once per MCP session from the `initialize` request (see
 * Mcp\AdapterBridge, the only other file allowed to reference `WP\MCP\*`,
 * which is where the raw `clientInfo` is read off the transport and handed
 * to set()) and persisted for the lifetime of that MCP session (keyed by the
 * adapter's own `Mcp-Session-Id`) so later requests over the same session
 * resolve the same client without trusting a client-supplied value on those
 * later requests — only the value captured at initialize is ever stored.
 * Non-MCP requests (REST admin UI, WP-CLI, etc.) never call set(), so
 * current() is null there.
 */
final class ClientContext {

	/** Allowed characters in a sanitized name/version component. */
	private const SAFE_PATTERN = '/[^A-Za-z0-9 ._\/-]/';

	private const MAX_LEN = 64;

	/** Transient key prefix; values are short-lived, session-scoped strings. */
	private const TRANSIENT_PREFIX = 'ab_mcp_client_';

	private const TRANSIENT_TTL = DAY_IN_SECONDS;

	/** In-process cache for the lifetime of the current PHP request. */
	private static ?string $current = null;

	private static bool $isSet = false;

	/**
	 * Records the client for the current request (and, when $sessionId is
	 * given, persists it so a later request presenting the same
	 * Mcp-Session-Id header can restore it via restoreFromSession()).
	 * Passing null for both $name and $version clears the value (current()
	 * returns null) without touching any persisted session entry.
	 */
	public static function set(?string $name, ?string $version, ?string $sessionId = null): void {
		self::$current = self::compose($name, $version);
		self::$isSet   = true;

		if ($sessionId !== null && $sessionId !== '' && function_exists('set_transient')) {
			$sessionKey = self::sessionKey($sessionId);
			if ($sessionKey !== null) {
				if (self::$current === null) {
					if (function_exists('delete_transient')) {
						delete_transient($sessionKey);
					}
				} else {
					set_transient($sessionKey, self::$current, self::TRANSIENT_TTL);
				}
			}
		}
	}

	/**
	 * Restores a previously set() client for $sessionId into the current
	 * request's in-process value, if one was persisted and set() has not
	 * already been called this request. Never trusts anything other than
	 * the adapter's own session id to look the value up — the client name
	 * itself is never accepted from a later request.
	 */
	public static function restoreFromSession(?string $sessionId): void {
		if (self::$isSet || $sessionId === null || $sessionId === '' || ! function_exists('get_transient')) {
			return;
		}

		$sessionKey = self::sessionKey($sessionId);
		if ($sessionKey === null) {
			return;
		}

		$value = get_transient($sessionKey);
		self::$current = is_string($value) && $value !== '' ? $value : null;
		self::$isSet   = true;
	}

	/** "name/version" (sanitized, max 64 chars total) or null if unknown. */
	public static function current(): ?string {
		return self::$current;
	}

	/** Resets the in-process value only; any persisted session entry is untouched. */
	public static function reset(): void {
		self::$current = null;
		self::$isSet   = false;
	}

	private static function compose(?string $name, ?string $version): ?string {
		$name    = self::sanitizeComponent($name);
		$version = self::sanitizeComponent($version);

		if ($name === null && $version === null) {
			return null;
		}

		$value = $version === null ? (string) $name : sprintf('%s/%s', (string) $name, $version);

		return mb_substr($value, 0, self::MAX_LEN);
	}

	private static function sanitizeComponent(?string $value): ?string {
		if ($value === null) {
			return null;
		}

		$value = trim(preg_replace(self::SAFE_PATTERN, '', $value) ?? '');

		return $value === '' ? null : $value;
	}

	private static function sessionKey(string $sessionId): ?string {
		$sessionId = preg_replace('/[^A-Za-z0-9._-]/', '', $sessionId) ?? '';
		if ($sessionId === '') {
			return null;
		}

		// Transient option names are capped at 172 chars by wp_options; our
		// prefix plus a sanitized session id is always well under that.
		return self::TRANSIENT_PREFIX . mb_substr($sessionId, 0, 100);
	}
}
