<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * Settings > Review (#658): whether facts from curators' own Tíos are made
 * official without review (AutoApprove, on by default since #958). Saving
 * needs the Settings capability and the right to approve knowledge, since
 * the switch is a review decision. On is the default and stored as no
 * option; off is stored as '0'. A change writes one audit entry.
 *
 * #958 removed the "close untouched items after" field: nothing closes on
 * its own, open items go to the person's TíoDay (ReviewReminder).
 */
final class SettingsTab {

	public const ACTION = 'ab_save_review_settings';

	public const FIELD_AUTO = 'ab_review_auto_approve';

	public static function register(): void {
		add_action('admin_post_' . self::ACTION, [self::class, 'handleSave']);
	}

	public static function renderBody(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flags set by our own redirect.
		$saved = isset($_GET['ab_review_saved']);

		if ($saved) {
			echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__('Review settings saved.', 'tomatio') . '</p></div>';
		}
		$canApprove = current_user_can(Capabilities::CAP_APPROVE_KNOWLEDGE);
		$auto       = AutoApprove::enabled();

		$autoNow = $auto
			? esc_html__('On: a fact a curator\'s own Tío suggests becomes official at once, recorded as their approval.', 'tomatio')
			: esc_html__('Off: every fact waits for a curator.', 'tomatio');
		if (! $canApprove) {
			$autoNow .= ' <em>' . esc_html__('Only someone who can approve knowledge can change this.', 'tomatio') . '</em>';
		}

		echo '<section class="ab-ui-card">';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::ACTION);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::setting(
			__('Approve facts from curators\' own Tíos automatically', 'tomatio'),
			$autoNow,
			Ui::toggle(self::FIELD_AUTO, 'ab-review-auto-approve', $auto, ! $canApprove),
			'ab-review-auto-approve',
			'<p>' . esc_html__('On by default. A fact your own Tío suggests needs no second yes from you. Facts from other people\'s Tíos and from guests still wait, and so do documents locked as "Review required".', 'tomatio') . '</p>'
		);
		echo Ui::saveBar('ab_review_settings_submit');
		// phpcs:enable
		echo '</form></section>';
	}

	public static function handleSave(): void {
		if (! current_user_can(SettingsHub::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage TomaTío settings.', 'tomatio'), 403);
		}

		check_admin_referer(self::ACTION);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$error = self::save(wp_unslash($_POST), current_user_can(Capabilities::CAP_APPROVE_KNOWLEDGE));

		wp_safe_redirect(SettingsHub::url(SettingsHub::TAB_REVIEW, $error !== '' ? ['ab_review_error' => $error] : ['ab_review_saved' => '1']));
		exit;
	}

	/**
	 * Without the right to approve knowledge the switch is left as it is.
	 * Returns an error key ('' on success).
	 *
	 * @param array<string, mixed> $post Unslashed form data.
	 */
	public static function save(array $post, bool $canApprove): string {
		$wasAuto = AutoApprove::enabled();
		$auto    = $canApprove ? (isset($post[self::FIELD_AUTO]) && $post[self::FIELD_AUTO] === '1') : $wasAuto;

		if ($auto) {
			delete_option(AutoApprove::OPTION);
		} else {
			update_option(AutoApprove::OPTION, '0', false);
		}

		if ($wasAuto !== $auto) {
			\AgentBrain\Core\Hooks::action('audit', [
				'actor_id'    => get_current_user_id(),
				'event'       => 'review_settings_changed',
				'status'      => 'ok',
				'object_type' => 'setting',
				'object_id'   => 'review',
				'meta'        => ['auto_approve_own' => $auto],
			]);
		}

		return '';
	}
}
