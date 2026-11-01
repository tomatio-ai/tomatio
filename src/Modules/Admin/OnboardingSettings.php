<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Mcp\ServerIdentity;
use AgentBrain\Modules\OAuth\DelegatedAuthority;
use AgentBrain\Modules\OAuth\RoleSwitching;
use AgentBrain\Modules\Recall\Briefing;
use AgentBrain\Modules\Recall\Orientation;

/**
 * Settings > Briefing (slug onboarding; called Onboarding before #751): what this brain is for (told to every AI app that
 * connects, #580), the team's house rules (included in every agent
 * briefing) and a preview of exactly what a chosen agent sees on its first
 * briefings. The orientation itself is generated live (Recall\Orientation).
 */
final class OnboardingSettings {

	public const ACTION = 'agent_brain_save_house_rules';

	public static function register(): void {
		add_action('admin_post_' . self::ACTION, [self::class, 'handleSave']);
	}

	/**
	 * Tab body (SettingsHub already checked the capability).
	 */
	public static function renderBody(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only result flag set by our own redirect.
		if (isset($_GET['ab_onboarding_saved'])) {
			echo Notices::html('success', __('Briefing settings saved.', 'agent-brain')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notices::html() escapes.
		}

		// The template is only ever a placeholder, never the stored value.
		$rules   = Orientation::houseRules();
		$purpose = ServerIdentity::purpose();
		$default = sprintf(
			/* translators: %s: the brain's name. */
			__('%s is this team\'s TomaTío brain: its shared memory, decisions, knowledge and documents.', 'agent-brain'),
			ServerIdentity::brandName()
		);
		$tokens     = Briefing::defaultTokenBudget();
		$switching  = RoleSwitching::enabled();
		$delegation = DelegatedAuthority::allowed();

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__('What every agent is told', 'agent-brain') . '</h3>';
		echo '<p class="description">' . esc_html__('Every agent gets an orientation with its first briefings: who it is on this brain, what it may read and write, who the people are, and how review works. It is generated from the brain itself, so it stays accurate. Add your house rules below; they are included in every briefing.', 'agent-brain') . '</p>';

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::ACTION);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::setting(
			__('What this brain is for', 'agent-brain'),
			$purpose !== ''
				? esc_html__('Your own line, told to every AI app when it connects.', 'agent-brain')
				/* translators: %s: the default sentence. */
				: esc_html(sprintf(__('Not written yet, so apps hear: "%s"', 'agent-brain'), $default)),
			'<input type="text" name="ab_brain_purpose" id="ab-brain-purpose" class="large-text" maxlength="' . esc_attr((string) ServerIdentity::PURPOSE_MAX) . '" value="' . esc_attr($purpose) . '" />',
			'ab-brain-purpose',
			'<p>' . esc_html(sprintf(
				/* translators: %s: maximum length. */
				__('One line, up to %s characters, so an app with many connectors knows when to ask this brain. For example: "Acme\'s product brain: our roadmap, customer decisions and release notes."', 'agent-brain'),
				ServerIdentity::PURPOSE_MAX
			)) . '</p>',
			true
		);
		echo Ui::setting(
			__('House rules', 'agent-brain'),
			$rules !== ''
				? esc_html__('Written. They are included in every briefing.', 'agent-brain')
				: esc_html__('None yet.', 'agent-brain'),
			'<textarea name="ab_house_rules" id="ab-house-rules" rows="8" class="large-text" placeholder="' . esc_attr(Orientation::template()) . '">' . esc_textarea($rules) . '</textarea>',
			'ab-house-rules',
			'<p>' . esc_html__('Plain text, up to 4,000 characters. What you are building, who decides what, how you like to work. Leave empty for none.', 'agent-brain') . '</p>',
			true
		);
		echo Ui::setting(
			__('Default briefing size (tokens)', 'agent-brain'),
			esc_html(sprintf(
				/* translators: 1: token budget, 2: about how many characters that is. */
				__('%1$s tokens, about %2$s characters.', 'agent-brain'),
				number_format_i18n($tokens),
				number_format_i18n($tokens * 4)
			)),
			'<input type="number" name="ab_briefing_default_tokens" id="ab-briefing-tokens" class="small-text" min="' . esc_attr((string) Briefing::MIN_BUDGET) . '" max="' . esc_attr((string) Briefing::MAX_BUDGET) . '" step="100" value="' . esc_attr((string) $tokens) . '" />',
			'ab-briefing-tokens',
			'<p>' . esc_html(sprintf(
				/* translators: 1: default budget, 2: minimum, 3: maximum. */
				__('How much a context briefing may carry when the agent does not ask for a size (default %1$s, allowed %2$s to %3$s; roughly four characters per token). Working memory, recent episodes, approved knowledge and procedures share it; about a third is kept for knowledge and procedures. Items longer than their share are cut with a pointer to read the rest. To carry a project\'s essentials in full, pin them: use "Pin to briefings" on the Knowledge or Procedures list. Pinned items are briefed whole, before recent items, and the budget should be large enough to hold them.', 'agent-brain'),
				Briefing::DEFAULT_TOKEN_BUDGET,
				Briefing::MIN_BUDGET,
				Briefing::MAX_BUDGET
			)) . '</p>'
		);
		echo Ui::setting(
			__('Allow role-switching connections', 'agent-brain'),
			$switching
				? esc_html__('On: people can connect an AI once, as themselves, and it picks the agent that fits each task.', 'agent-brain')
				: esc_html__('Off: every connection is one fixed agent.', 'agent-brain'),
			Ui::toggle('ab_role_switching', 'ab-role-switching', $switching),
			'ab-role-switching',
			'<p>' . esc_html__('Adds a second connector address (shown on Connect an AI and the home page). Connecting with it, people connect an AI once, as themselves, and the AI picks the agent that fits each task (the act-as tool), attributed "via" the person. The normal address keeps connecting as one fixed agent. It can only become agents whose scopes the person already has, and every switch is in the audit log. Leave off when agents\' scopes are deliberately separate, so one conversation cannot reach all of them.', 'agent-brain') . '</p>'
		);
		echo Ui::setting(
			__('Let people give an AI connection their rights', 'agent-brain'),
			$delegation
				? esc_html__('Allowed: a person can tick it when connecting an AI, so it may create, edit and publish without review when they ask.', 'agent-brain')
				: esc_html__('Forbidden on this brain: the choice is not offered and earlier grants do not work.', 'agent-brain'),
			Ui::toggle('ab_delegated_authority', 'ab-delegated-authority', $delegation),
			'ab-delegated-authority',
			'<p>' . esc_html__('Off for every connection unless the person ticks it on the consent screen when connecting an AI; they can turn it off again from their profile. A connection with it may, when its person asks, create artifacts, publish document sections in one step and approve page versions, never more than that person may do, and every such change is in the audit log. Artifacts locked as "Review required" still go through review. Turn this off to forbid it on this brain: the choice is no longer offered and existing grants stop working at once.', 'agent-brain') . '</p>'
		);
		echo Ui::saveBar('ab_onboarding_submit');
		// phpcs:enable
		echo '</form></section>';

		self::renderPreview();
	}

	private static function renderPreview(): void {
		$agents = get_users(['role' => Capabilities::ROLE_AGENT, 'orderby' => 'display_name', 'order' => 'ASC']);

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__('What an agent sees', 'agent-brain') . '</h3>';
		if ($agents === []) {
			echo '<p>' . esc_html__('No agents yet. Add a user with the Agent role to preview its orientation.', 'agent-brain') . '</p></section>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview selector.
		$requested = isset($_GET['preview_agent']) ? absint(wp_unslash($_GET['preview_agent'])) : 0;
		$selected  = $agents[0];
		foreach ($agents as $agent) {
			if ((int) $agent->ID === $requested) {
				$selected = $agent;
			}
		}

		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr(SettingsHub::PAGE_SLUG) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr(SettingsHub::TAB_ONBOARDING) . '" />';
		echo '<label>' . esc_html__('Agent', 'agent-brain') . ' <select name="preview_agent">';
		foreach ($agents as $agent) {
			echo '<option value="' . esc_attr((string) $agent->ID) . '"' . selected((int) $agent->ID, (int) $selected->ID, false) . '>' . esc_html($agent->display_name) . '</option>';
		}
		echo '</select></label> <button type="submit" class="button">' . esc_html__('Preview', 'agent-brain') . '</button></form>';

		echo '<pre class="ab-orientation-preview" style="white-space:pre-wrap;max-width:760px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px">'
			. esc_html(Orientation::text((int) $selected->ID, true))
			. '</pre></section>';
	}

	public static function handleSave(): void {
		if (! current_user_can(SettingsHub::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage TomaTío settings.', 'agent-brain'), 403);
		}

		check_admin_referer(self::ACTION);

		$rules = isset($_POST['ab_house_rules']) ? (string) wp_unslash($_POST['ab_house_rules']) : '';
		Orientation::saveHouseRules($rules);
		if (isset($_POST['ab_brain_purpose'])) {
			ServerIdentity::savePurpose((string) wp_unslash($_POST['ab_brain_purpose']));
		}
		if (isset($_POST['ab_briefing_default_tokens'])) {
			$tokens = absint(wp_unslash($_POST['ab_briefing_default_tokens']));
			update_option(Briefing::OPTION_BUDGET, max(Briefing::MIN_BUDGET, min(Briefing::MAX_BUDGET, $tokens)), false);
		}
		// '1' / '0', never false: update_option() skips storing false for an
		// option that does not exist yet, so turning it off on a fresh brain
		// would silently leave the default (on).
		update_option(RoleSwitching::OPTION, isset($_POST['ab_role_switching']) && $_POST['ab_role_switching'] === '1' ? '1' : '0', false);
		update_option(DelegatedAuthority::OPTION, isset($_POST['ab_delegated_authority']) && $_POST['ab_delegated_authority'] === '1' ? '1' : '0', false);

		wp_safe_redirect(SettingsHub::url(SettingsHub::TAB_ONBOARDING, ['ab_onboarding_saved' => '1']));
		exit;
	}
}
