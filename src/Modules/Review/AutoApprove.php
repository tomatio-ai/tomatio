<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\OAuth\Delegation;
use WP_User;

/**
 * #658: facts from curators' own AIs approve themselves (opt-in). When the
 * setting is on (Settings > Review, off by default), a proposal made from
 * a fact (ProposalService) whose proposing agent belongs to a person who
 * can approve knowledge is approved at once, through
 * ReviewActions::approveAutomatically(): the same capability and object
 * checks as a click, recorded for that person and marked automatic in post
 * meta and the audit log, with the usual outcome memory.
 *
 * Whose agent: the person who issued it (AgentMeta::ISSUED_BY), never the
 * agent itself or another agent, and only while the agent may still sign
 * in. A person's own account (not a pure agent) counts as their own.
 * Agents of people who cannot approve, guests' agents and agents with no
 * issuer still go through review, and so does everything an artifact
 * assembles (only fact proposals pass through here), so artifacts locked
 * as "Review required" are unaffected.
 */
final class AutoApprove {

	/** Option: '1' on; anything else (the default, no option) off. */
	public const OPTION = 'ab_review_auto_approve_own';

	public static function enabled(): bool {
		return (bool) apply_filters('agent_brain/review_auto_approve', get_option(self::OPTION, '0') === '1');
	}

	/**
	 * The person whose own AI $agentId is, when that person may approve
	 * knowledge; 0 otherwise.
	 */
	public static function ownerFor(int $agentId): int {
		$agent = $agentId > 0 ? get_userdata($agentId) : false;
		if (! $agent instanceof WP_User) {
			return 0;
		}

		if (! Delegation::isPureAgent($agent)) {
			return user_can($agentId, Capabilities::CAP_APPROVE_KNOWLEDGE) ? $agentId : 0;
		}

		if (AgentIssuer::isBlocked($agentId)) {
			return 0;
		}

		$ownerId = (int) get_user_meta($agentId, AgentMeta::ISSUED_BY, true);
		$owner   = $ownerId > 0 && $ownerId !== $agentId ? get_userdata($ownerId) : false;
		if (! $owner instanceof WP_User || Delegation::isPureAgent($owner)) {
			return 0;
		}

		return user_can($ownerId, Capabilities::CAP_APPROVE_KNOWLEDGE) ? $ownerId : 0;
	}

	/**
	 * Approves the new proposal $postId when the setting is on and $agentId
	 * is a curator's own AI. True when it was approved.
	 */
	public static function maybeApprove(int $postId, int $agentId): bool {
		if (! self::enabled()) {
			return false;
		}

		$ownerId = self::ownerFor($agentId);
		if ($ownerId <= 0) {
			return false;
		}

		return (new ReviewActions())->approveAutomatically($ownerId, $postId) === true;
	}
}
