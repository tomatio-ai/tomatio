<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * Settings > Review (#658): whether facts from curators' own AIs are made
 * official without review (AutoApprove, off by default) and how long an
 * untouched proposal or review request waits before it closes (Expiry,
 * default 14 days, 0 = never). Saving needs the Settings capability and the
 * right to approve knowledge, since turning auto-approval on is a review
 * decision. Values equal to their default are deleted, not stored, and
 * each changed setting writes one audit entry.
 */
final class SettingsTab {

	public const ACTION = 'ab_save_review_settings';

	public const FIELD_AUTO   = 'ab_review_auto_approve';
	public const FIELD_EXPIRY = 'ab_review_expiry_days';

	public static function register(): void {
		add_action('admin_post_' . self::ACTION, [self::class, 'handleSave']);
	}

	public static function renderBody(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flags set by our own redirect.
		$saved = isset($_GET['ab_review_saved']);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
		$error = isset($_GET['ab_review_error']) ? sanitize_key(wp_unslash((string) $_GET['ab_review_error'])) : '';

		if ($saved) {
			echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__('Review settings saved.', 'agent-brain') . '</p></div>';
		}
		if ($error === 'expiry_days') {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html(sprintf(
				/* translators: %d: maximum number of days */
				__('The waiting time must be a whole number of days from 0 to %d; nothing was saved.', 'agent-brain'),
				Expiry::MAX_DAYS
			)) . '</p></div>';
		}

		$canApprove = current_user_can(Capabilities::CAP_APPROVE_KNOWLEDGE);
		$auto       = AutoApprove::enabled();
		$days       = Expiry::days();

		$autoNow = $auto
			? esc_html__('On: a fact a curator\'s own Tío suggests becomes official at once, recorded as their approval.', 'agent-brain')
			: esc_html__('Off: every fact waits in the Review Queue.', 'agent-brain');
		if (! $canApprove) {
			$autoNow .= ' <em>' . esc_html__('Only someone who can approve knowledge can change this.', 'agent-brain') . '</em>';
		}

		echo '<section class="ab-ui-card">';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::ACTION);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::setting(
			__('Approve facts from curators\' own Tíos automatically', 'agent-brain'),
			$autoNow,
			Ui::toggle(self::FIELD_AUTO, 'ab-review-auto-approve', $auto, ! $canApprove),
			'ab-review-auto-approve',
			'<p>' . esc_html__('Off by default. When on, a fact a Tío suggests making official is approved at once when that Tío belongs to a person who can approve knowledge (the person who added the agent is a curator). It is recorded as an automatic approval for that person, in the audit log and the item\'s history, like any approval. Facts from other people\'s Tíos and from guests still wait for review here, and so do documents locked as "Review required".', 'agent-brain') . '</p>'
		);
		echo Ui::setting(
			__('Close untouched items after (days)', 'agent-brain'),
			$days === 0
				? esc_html__('Never: suggestions and review requests wait until someone decides.', 'agent-brain')
				: esc_html(sprintf(
					/* translators: %d: number of days */
					_n('%d day. Nothing is deleted; the memories behind them stay.', '%d days. Nothing is deleted; the memories behind them stay.', $days, 'agent-brain'),
					$days
				)),
			'<input type="number" class="small-text" min="0" max="' . esc_attr((string) Expiry::MAX_DAYS) . '" id="ab-review-expiry-days" name="' . esc_attr(self::FIELD_EXPIRY) . '" value="' . esc_attr((string) $days) . '" />',
			'ab-review-expiry-days',
			'<p>' . esc_html(sprintf(
				/* translators: %d: default number of days */
				__('A suggested fact nobody decides closes as not needed after this many days, and an open review request (handoff) closes without sharing anything. Default %d; 0 keeps them until someone decides. The memories behind them are always kept.', 'agent-brain'),
				Expiry::DEFAULT_DAYS
			)) . '</p>'
		);
		echo Ui::saveBar('ab_review_settings_submit');
		// phpcs:enable
		echo '</form></section>';
	}

	public static function handleSave(): void {
		if (! current_user_can(SettingsHub::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage TomaTío settings.', 'agent-brain'), 403);
		}

		check_admin_referer(self::ACTION);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$error = self::save(wp_unslash($_POST), current_user_can(Capabilities::CAP_APPROVE_KNOWLEDGE));

		wp_safe_redirect(SettingsHub::url(SettingsHub::TAB_REVIEW, $error !== '' ? ['ab_review_error' => $error] : ['ab_review_saved' => '1']));
		exit;
	}

	/**
	 * Validates everything first and writes nothing unless all of it is
	 * valid. Without the right to approve knowledge the auto-approval
	 * switch is left as it is. Returns an error key ('' on success).
	 *
	 * @param array<string, mixed> $post Unslashed form data.
	 */
	public static function save(array $post, bool $canApprove): string {
		$raw  = isset($post[self::FIELD_EXPIRY]) ? trim((string) $post[self::FIELD_EXPIRY]) : '';
		$days = $raw === '' ? Expiry::DEFAULT_DAYS : (ctype_digit($raw) ? (int) $raw : -1);
		if ($days < 0 || $days > Expiry::MAX_DAYS) {
			return 'expiry_days';
		}

		$wasDays = Expiry::days();
		$wasAuto = AutoApprove::enabled();
		$auto    = $canApprove ? (isset($post[self::FIELD_AUTO]) && $post[self::FIELD_AUTO] === '1') : $wasAuto;

		if ($auto) {
			update_option(AutoApprove::OPTION, '1', false);
		} else {
			delete_option(AutoApprove::OPTION);
		}

		if ($days === Expiry::DEFAULT_DAYS) {
			delete_option(Expiry::OPTION_DAYS);
		} else {
			// A numeric string, so 0 (never close) is stored like any other value.
			update_option(Expiry::OPTION_DAYS, (string) $days, false);
		}

		if ($wasAuto !== $auto || $wasDays !== $days) {
			do_action('agent_brain/audit', [
				'actor_id'    => get_current_user_id(),
				'event'       => 'review_settings_changed',
				'status'      => 'ok',
				'object_type' => 'setting',
				'object_id'   => 'review',
				'meta'        => ['auto_approve_own' => $auto, 'expiry_days' => $days],
			]);
		}

		return '';
	}
}
