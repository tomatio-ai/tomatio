<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * Shared admin feedback (issue #81): one markup for success / error /
 * warning notices, plus per-user "flash" messages that survive the
 * admin-post redirect (a short-lived transient, consumed on the next admin
 * page load) so every action handler can confirm what it just did.
 */
final class Notices {

	public const TYPES = ['success', 'error', 'warning', 'info'];

	private const TRANSIENT_PREFIX = 'ab_flash_';
	private const TTL              = 120;

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_notices', [self::class, 'renderFlash']);
	}

	public static function html(string $type, string $message, bool $dismissible = true): string {
		$type = in_array($type, self::TYPES, true) ? $type : 'info';

		return '<div class="notice notice-' . esc_attr($type) . ($dismissible ? ' is-dismissible' : '') . ' ab-notice" role="' . ('error' === $type ? 'alert' : 'status') . '"><p>'
			. esc_html($message) . '</p></div>';
	}

	public static function success(string $message): void {
		self::emit('success', $message);
	}

	public static function error(string $message): void {
		self::emit('error', $message);
	}

	public static function warning(string $message): void {
		self::emit('warning', $message);
	}

	/**
	 * Queue a message for the current user to be shown on their next admin
	 * page load (call before wp_safe_redirect()).
	 */
	public static function flash(string $type, string $message): void {
		$userId = get_current_user_id();
		if ($userId <= 0 || $message === '') {
			return;
		}

		$queue   = get_transient(self::TRANSIENT_PREFIX . $userId);
		$queue   = is_array($queue) ? $queue : [];
		$queue[] = [
			'type'    => in_array($type, self::TYPES, true) ? $type : 'info',
			'message' => $message,
		];

		set_transient(self::TRANSIENT_PREFIX . $userId, array_slice($queue, -5), self::TTL);
	}

	/**
	 * Print and clear this user's queued messages.
	 */
	public static function renderFlash(): void {
		$userId = get_current_user_id();
		if ($userId <= 0) {
			return;
		}

		$queue = get_transient(self::TRANSIENT_PREFIX . $userId);
		if (! is_array($queue) || $queue === []) {
			return;
		}

		delete_transient(self::TRANSIENT_PREFIX . $userId);

		foreach ($queue as $item) {
			if (is_array($item) && isset($item['type'], $item['message'])) {
				self::emit((string) $item['type'], (string) $item['message']);
			}
		}
	}

	private static function emit(string $type, string $message): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value it emits.
		echo self::html($type, $message);
	}
}
