<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

/**
 * Pure presentation helpers for MCP ability output (#66, #68, #72, #50):
 * ISO 8601 UTC timestamps, the handoff status vocabulary, empty-result hints
 * and the "person, not an agent" message. No WordPress dependency.
 */
final class Present {

	public const HANDOFF_PENDING_REVIEW = 'pending_review';
	public const HANDOFF_ASSIGNED       = 'assigned';
	public const HANDOFF_RESOLVED       = 'resolved';
	public const HANDOFF_DISMISSED      = 'dismissed';

	/**
	 * MySQL GMT datetime ("Y-m-d H:i:s") -> ISO 8601 UTC with a trailing Z.
	 * Already-ISO input is normalised to the same shape; blank, zero-date or
	 * unparseable input returns ''.
	 */
	public static function iso( string $value ): string {
		$value = trim( $value );
		if ( $value === '' || str_starts_with( $value, '0000-00-00' ) ) {
			return '';
		}

		$hasZone = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $value );
		$ts      = strtotime( $hasZone ? $value : $value . ' UTC' );

		return $ts === false ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	/**
	 * Map a handoff's stored state to the public vocabulary.
	 *
	 * @param string|null $resolution ab_handoff_status meta (approved|dismissed|null).
	 */
	public static function handoffStatus( ?string $resolution, ?int $toAgentId ): string {
		if ( $resolution === 'approved' ) {
			return self::HANDOFF_RESOLVED;
		}
		if ( $resolution === 'dismissed' ) {
			return self::HANDOFF_DISMISSED;
		}

		return $toAgentId !== null && $toAgentId > 0 ? self::HANDOFF_ASSIGNED : self::HANDOFF_PENDING_REVIEW;
	}

	public static function handoffNote( string $status ): string {
		switch ( $status ) {
			case self::HANDOFF_PENDING_REVIEW:
				return 'waiting for a curator';
			case self::HANDOFF_ASSIGNED:
				return 'assigned to a recipient, not yet resolved';
			case self::HANDOFF_RESOLVED:
				return 'resolved by a curator';
			default:
				return 'dismissed by a curator';
		}
	}

	/**
	 * Why a search/knowledge result is empty. Counts only; never content or
	 * scope names the caller cannot read.
	 *
	 * @param string[] $readScopeLabels "name (id)" of each scope the caller can read.
	 * @return array{hint:string}
	 */
	public static function emptyHint( int $readScopeCount, bool $hadQuery, bool $isAgent = true, array $readScopeLabels = [] ): array {
		// People read through their own grants like agents do; only a person
		// with nothing shared to read gets the person-specific hint.
		if ( ! $isAgent && $readScopeCount <= 0 ) {
			return [ 'hint' => 'You are connected as a person, not an agent, and nothing is shared with you to read yet. Ask a curator, or connect through one of your agents.' ];
		}

		if ( $readScopeCount <= 0 ) {
			return [ 'hint' => 'You have no read scopes, so nothing can be returned. Ask a curator to grant one on the Agents page.' ];
		}

		if ( $readScopeLabels !== [] ) {
			$scopes = implode( ', ', $readScopeLabels );
			$where  = "Searched {$scopes}";
		} else {
			$scopes = $readScopeCount === 1 ? '1 scope' : $readScopeCount . ' scopes';
			$where  = "Searched {$scopes} you can read";
		}

		return [
			'hint' => $hadQuery
				? "{$where}; nothing matched. Try fewer or different words."
				: "{$where}; there is nothing stored there yet.",
		];
	}

	/**
	 * Why a person's write was refused: writes go through an agent, and this
	 * person has no default one (docs/rfc/people-write-through-agents.md).
	 *
	 * @param string[] $agentNames agents the person may write through.
	 */
	public static function personNotAgent( string $login, array $agentNames = [] ): string {
		$how = $agentNames === []
			? 'No agent is set up for you yet; ask an admin to add one on the Agents page.'
			: 'Choose your default agent on your profile (Users > Profile > TomaTío), or connect at the role-switching address (the connector address ending in /mcp-roles) and use act-as. Agents you can use: ' . implode( ', ', $agentNames ) . '.';

		return sprintf( '%s is a person, not an agent: writes go through an agent. %s', $login, $how );
	}
}
