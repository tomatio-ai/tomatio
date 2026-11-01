<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Identity\AgentProposals;
use WP_Error;

/**
 * Proposed agents in the Review inbox (#701, InboxItems): agents an AI suggested with
 * `agent-propose`, waiting for a person (Identity\AgentProposals). Shown
 * only to people who can add agents; Add agent / Decline are nonce-protected
 * and AgentProposals re-checks the reviewer on every action.
 */
final class AgentProposalQueue {

	public const ACTION       = 'ab_agent_proposal';
	public const NONCE_ACTION = 'ab_agent_proposal_';
	public const NONCE_FIELD  = 'ab_agent_proposal_nonce';

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_post_' . self::ACTION, [self::class, 'handleAction']);
	}

	public static function handleAction(): void {
		if (! AgentProposals::canReview(get_current_user_id())) {
			wp_die(esc_html__('You cannot review agent proposals.', 'agent-brain'), 403);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified explicitly below (per-proposal nonce).
		$id = isset($_POST['proposal']) ? sanitize_text_field(wp_unslash($_POST['proposal'])) : '';
		check_admin_referer(self::NONCE_ACTION . $id, self::NONCE_FIELD);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
		$result   = $decision === 'approve'
			? AgentProposals::approve(get_current_user_id(), $id)
			: AgentProposals::decline(get_current_user_id(), $id);

		if (! $result instanceof WP_Error) {
			$result = true;
		}

		ReviewQueuePage::respond($result, $decision === 'approve'
			? __('Agent added. The agent that asked for it can switch to it with act-as now.', 'agent-brain')
			: __('Proposal declined.', 'agent-brain'), admin_url('admin.php?page=' . ReviewQueuePage::SLUG));
	}
}
