<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * #471: on a fresh WordPress (no other plugins, only the sample content, no
 * other admins: SharedInstall's "dedicated" verdict), ask the person who
 * manages the brain once whether this WordPress is just for TomaTío. Yes
 * turns on DedicatedSite (the focused admin and the designed home page);
 * no keeps the website. Either answer hides the question for good.
 *
 * Shown on the Overview and, as a notice, on the Plugins screen (the first
 * screen after activating). Never on a site with shared-site signs, when a
 * host decides the setting, or when dedicated mode is already on.
 */
final class DedicatedPrompt {

	public const OPTION = 'agent_brain_dedicated_prompt';
	public const ACTION = 'agent_brain_dedicated_prompt';

	public static function register(): void {
		add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
		add_action('admin_notices', [self::class, 'pluginsNotice']);
	}

	public static function shouldShow(int $userId): bool {
		if ($userId <= 0 || ! user_can($userId, SettingsHub::CAPABILITY)) {
			return false;
		}
		if (get_option(self::OPTION, '') !== '' || DedicatedSite::enabled() || DedicatedSite::forced()) {
			return false;
		}

		return ! SharedInstall::detect()['shared'];
	}

	/**
	 * The question with its two buttons, or '' when it should not show.
	 * $compact (the Overview, #751) puts the question and the buttons on
	 * one line and folds the explanation behind "What changes?".
	 */
	public static function html(bool $compact = false): string {
		if (! self::shouldShow(get_current_user_id())) {
			return '';
		}

		$why = '<p>' . esc_html__('It looks like a fresh install. Make it a dedicated brain: the website screens (Posts, Media, Pages, Comments, Appearance) leave the menu, signing in opens the Overview, and the front page becomes a designed home page with sign-in and how to connect an AI. Nothing is deleted, and you can change it any time in Settings, Site.', 'agent-brain') . '</p>';

		// One form, two submit buttons: a single nonce field (ids stay unique).
		return '<div class="ab-dedicated-prompt' . ($compact ? ' ab-dedicated-prompt--line' : '') . '" role="region" aria-labelledby="ab-dedicated-prompt-title">'
			. '<h2 id="ab-dedicated-prompt-title">' . esc_html__('Is this WordPress just for TomaTío?', 'agent-brain') . '</h2>'
			. ($compact ? '' : $why)
			. '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ab-dedicated-prompt-actions">'
			. wp_nonce_field(self::ACTION, '_wpnonce', true, false)
			. '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />'
			. '<button type="submit" name="answer" value="yes" class="button button-primary">' . esc_html__('Yes, make it a dedicated brain', 'agent-brain') . '</button>'
			. '<button type="submit" name="answer" value="no" class="button button-secondary">' . esc_html__('No, keep the website', 'agent-brain') . '</button>'
			. '</form>'
			. ($compact ? '<details class="ab-dedicated-prompt-why"><summary>' . esc_html__('What changes?', 'agent-brain') . '</summary>' . $why . '</details>' : '')
			. '</div>';
	}

	public static function pluginsNotice(): void {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (! $screen instanceof \WP_Screen || $screen->id !== 'plugins') {
			return;
		}

		$html = self::html();
		if ($html !== '') {
			echo '<div class="notice notice-info ab-notice">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes.
		}
	}

	/**
	 * Record the answer; yes turns dedicated mode on. False when the user may not answer.
	 */
	public static function answer(int $userId, bool $yes): bool {
		if ($userId <= 0 || ! user_can($userId, SettingsHub::CAPABILITY)) {
			return false;
		}

		if ($yes && ! DedicatedSite::forced()) {
			update_option(DedicatedSite::OPTION, true);
		}
		update_option(self::OPTION, $yes ? 'yes' : 'no', false);

		return true;
	}

	public static function handle(): void {
		check_admin_referer(self::ACTION);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$yes = isset($_POST['answer']) && sanitize_key(wp_unslash((string) $_POST['answer'])) === 'yes';
		if (! self::answer(get_current_user_id(), $yes)) {
			wp_die(esc_html__('You do not have permission to manage TomaTío settings.', 'agent-brain'), 403);
		}

		wp_safe_redirect($yes ? DedicatedSite::overviewUrl() : (wp_get_referer() ?: DedicatedSite::overviewUrl()));
		exit;
	}
}
