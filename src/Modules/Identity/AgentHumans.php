<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\OAuth\Delegation;

/**
 * The human behind an agent (#139). Agents' display_name holds the FQDN, so
 * the person an agent works for — the WordPress user recorded as its issuer
 * (AgentMeta::ISSUED_BY) — is resolved here at display time. No per-agent
 * meta is written for it, and there is nothing to keep in sync.
 */
final class AgentHumans {

	/**
	 * The display name of the person who issued the agent, or null when the
	 * agent has no recorded issuer, the issuer account is gone, the agent
	 * issued itself, or the issuer is itself a pure agent rather than a
	 * person. Read fresh on every call (WordPress's user cache is enough).
	 */
	public static function nameFor( int $agentUserId ): ?string {
		if ( $agentUserId <= 0 ) {
			return null;
		}

		$issuerId = (int) get_user_meta( $agentUserId, AgentMeta::ISSUED_BY, true );
		if ( $issuerId <= 0 || $issuerId === $agentUserId ) {
			return null;
		}

		$issuer = get_userdata( $issuerId );
		if ( ! $issuer instanceof \WP_User || Delegation::isPureAgent( $issuer ) ) {
			return null;
		}

		$name = (string) $issuer->display_name;

		return $name !== '' ? $name : null;
	}
}
