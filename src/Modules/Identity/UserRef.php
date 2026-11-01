<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\AgentId\Fqdn;

/**
 * Resolves how a caller names a user in a tool argument (#302): a numeric
 * user id, a login (fm-designer) or an agent id (FQDN). Agents are never
 * told their numeric id, so tools that take one also accept the names an
 * agent does know.
 */
final class UserRef {

	/**
	 * The user id for $value, or 0 when nothing matches (or the match is
	 * ambiguous).
	 *
	 * @param mixed $value
	 */
	public static function resolve( $value ): int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}
		if ( ! is_string( $value ) ) {
			return 0;
		}

		$value = trim( $value );
		if ( $value === '' ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		$user = get_user_by( 'login', $value );
		if ( $user instanceof \WP_User ) {
			return (int) $user->ID;
		}

		$fqdn = Fqdn::normalize( $value );
		if ( strpos( $fqdn, '.' ) === false ) {
			return 0;
		}

		$ids = get_users(
			[
				'meta_key'   => AgentMeta::FQDN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one exact lookup.
				'meta_value' => $fqdn, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one exact lookup.
				'fields'     => 'ID',
				'number'     => 2,
			]
		);

		return count( $ids ) === 1 ? (int) $ids[0] : 0;
	}

	/**
	 * How refusals and tool output name a user: "fm-designer (user 2)", or
	 * "nobody" for 0 and unknown ids.
	 */
	public static function describe( int $userId ): string {
		$user = $userId > 0 ? get_userdata( $userId ) : false;
		if ( ! $user instanceof \WP_User ) {
			return $userId > 0
				/* translators: %d: user id. */
				? sprintf( __( 'an unknown user (user %d)', 'agent-brain' ), $userId )
				: __( 'nobody', 'agent-brain' );
		}

		/* translators: 1: login, 2: user id. */
		return sprintf( __( '%1$s (user %2$d)', 'agent-brain' ), $user->user_login, $userId );
	}
}
