<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentId;

/**
 * Names for the agent-ID storage (docs/rfc/agent-id-fqdn.md section 3 and 4).
 * Constants only plus two option readers, so every unit uses the same keys.
 */
final class AgentMeta {

	public const FQDN        = 'ab_agent_fqdn';
	public const REGISTER_ID = 'ab_agent_register_id';
	public const CLASS_NAME  = 'ab_agent_class';
	public const STATUS      = 'ab_agent_status';
	public const ISSUED_BY   = 'ab_agent_issued_by';
	public const ISSUED_AT   = 'ab_agent_issued_at';

	public const OPTION_ORG  = 'ab_agent_org';
	public const OPTION_BASE = 'ab_agent_base';

	public const STATUS_PENDING   = 'pending';
	public const STATUS_ACTIVE    = 'active';
	public const STATUS_SUSPENDED = 'suspended';
	public const STATUS_REVOKED   = 'revoked';

	public const CLASS_OBSERVER    = 'observer';
	public const CLASS_EXECUTOR    = 'executor';
	public const CLASS_CONTRIBUTOR = 'contributor';
	public const CLASS_LEAD        = 'lead';

	/** @var array<int, string> */
	public const STATUSES = [
		self::STATUS_PENDING,
		self::STATUS_ACTIVE,
		self::STATUS_SUSPENDED,
		self::STATUS_REVOKED,
	];

	/** @var array<int, string> */
	public const CLASSES = [
		self::CLASS_OBSERVER,
		self::CLASS_EXECUTOR,
		self::CLASS_CONTRIBUTOR,
		self::CLASS_LEAD,
	];

	public const DEFAULT_ORG = 'agnt';

	/**
	 * Allowed status transitions (section 4). REVOKED is terminal.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const TRANSITIONS = [
		self::STATUS_PENDING   => [ self::STATUS_ACTIVE ],
		self::STATUS_ACTIVE    => [ self::STATUS_SUSPENDED, self::STATUS_REVOKED ],
		self::STATUS_SUSPENDED => [ self::STATUS_ACTIVE, self::STATUS_REVOKED ],
		self::STATUS_REVOKED   => [],
	];

	public static function canTransition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? [], true );
	}

	public static function org(): string {
		$org = function_exists( 'get_option' ) ? (string) get_option( self::OPTION_ORG, self::DEFAULT_ORG ) : self::DEFAULT_ORG;

		return Fqdn::isValidOrg( $org ) ? $org : self::DEFAULT_ORG;
	}

	/**
	 * The instance host (default: the site URL's host).
	 */
	public static function base(): string {
		$base = function_exists( 'get_option' ) ? trim( (string) get_option( self::OPTION_BASE, '' ) ) : '';

		if ( $base === '' && function_exists( 'home_url' ) ) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			$base = is_string( $host ) ? $host : '';
		}

		return Fqdn::normalize( $base );
	}
}
