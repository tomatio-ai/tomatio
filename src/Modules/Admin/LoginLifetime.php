<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * #790: people stay signed in to a dedicated brain.
 *
 * Each place on a phone keeps its own logins (Safari, every page added to
 * the Home Screen, the browser inside a chat app), and a password manager
 * that signs in for you leaves "Remember Me" unticked, so WordPress's
 * 48-hour login made people sign in again and again. On a dedicated brain
 * the sign-in page opens with "Remember Me" ticked, and a remembered login
 * lasts 30 days instead of 14. Unticking it (a shared computer) keeps the
 * 48-hour login. A website that runs the plugin keeps WordPress's defaults.
 *
 * `tomatio/remember_days` (int) changes the 30 days; 0 keeps
 * WordPress's 14.
 */
final class LoginLifetime {

	public const REMEMBER_DAYS = 30;

	public static function register(): void {
		add_filter('auth_cookie_expiration', [self::class, 'expiration'], 10, 3);
		add_action('login_footer', [self::class, 'tickRememberMe']);
	}

	/** Days a remembered login lasts on this site, or 0 for WordPress's default. */
	public static function rememberDays(): int {
		if (! DedicatedSite::enabled()) {
			return 0;
		}

		return max(0, (int) \AgentBrain\Core\Hooks::filter('remember_days', self::REMEMBER_DAYS));
	}

	/**
	 * @param int|mixed $length   seconds WordPress would use
	 * @param int|mixed $userId
	 * @param bool|mixed $remember whether "Remember Me" was ticked
	 * @return int|mixed
	 */
	public static function expiration($length, $userId = 0, $remember = false) {
		$days = self::rememberDays();

		return $remember && $days > 0 ? $days * DAY_IN_SECONDS : $length;
	}

	/**
	 * Ticks "Remember Me" when the sign-in form first opens. After a failed
	 * attempt the form keeps what the person chose, so an untick sticks.
	 */
	public static function tickRememberMe(): void {
		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))) : 'GET';
		if (self::rememberDays() === 0 || $method === 'POST') {
			return;
		}

		echo "<script>(function(){var b=document.getElementById('rememberme');if(b){b.checked=true;}})();</script>\n";
	}
}
