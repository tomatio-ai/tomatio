<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Artifacts\Preview;

/**
 * Single-use exchange codes for embed links (#59). A logged-in person who may
 * read the artifact mints a code bound to (embed link, embedding origin); the
 * preview host consumes it exactly once and answers with a session cookie. The
 * code is stored only as a sha256 digest and lives 60 seconds.
 */
final class EmbedExchange {

	public const CODE_TTL = 60;

	public const RATE_PER_HOUR = 60;

	public static function table(): string {
		return Db::table( 'embed_codes' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			code_hash CHAR(64) NOT NULL,
			share_link_id BIGINT UNSIGNED NOT NULL,
			artifact_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			origin VARCHAR(255) NOT NULL,
			expires_at INT UNSIGNED NOT NULL,
			PRIMARY KEY  (code_hash),
			KEY expires_at (expires_at)
		) {$charset};";
	}

	/**
	 * Mints a single-use code for an embed link.
	 *
	 * @return array{code:string,url:string,expires_in:int,origin:string}|\WP_Error
	 */
	public static function issueCode( int $userId, int $linkId, string $origin, ?int $now = null ) {
		global $wpdb;

		$now = $now ?? time();
		$deny = new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );

		$link = $userId > 0 ? ShareLinks::find( $linkId ) : null;
		if ( $link === null || EmbedPolicy::normalizeKind( (string) $link['kind'] ) !== EmbedPolicy::KIND_EMBED ) {
			return $deny;
		}

		$artifactId = (int) $link['artifact_id'];
		if ( Config::disabled() || ! Preview::isEnabled() || ! ShareLinks::isServable( get_post( $artifactId ) ) || ! user_can( $userId, 'read_post', $artifactId ) ) {
			return $deny;
		}

		$normalized = EmbedPolicy::validateOrigin( $origin );
		if ( $normalized === null || ! in_array( $normalized, EmbedSettings::allowedOrigins(), true ) ) {
			return new \WP_Error( 'ab_embed_origin', __( 'That origin is not on the embed allowlist.', 'agent-brain' ), [ 'status' => 422 ] );
		}

		// Live link only: not revoked, expired or awaiting approval (IP binding is checked when the code is used).
		if ( $link['revoked_at'] !== null || $link['approved_at'] === null || Policy::isExpired( (string) $link['expires_at'], $now ) ) {
			return $deny;
		}

		if ( ! self::withinRate( $userId ) ) {
			return new \WP_Error( 'ab_embed_rate_limited', __( 'Too many embed codes requested. Try again later.', 'agent-brain' ), [ 'status' => 429 ] );
		}

		$code     = Token::generate();
		$inserted = $wpdb->insert(
			self::table(),
			[
				'code_hash'     => Token::hash( $code ),
				'share_link_id' => $linkId,
				'artifact_id'   => $artifactId,
				'user_id'       => $userId,
				'origin'        => $normalized,
				'expires_at'    => $now + self::CODE_TTL,
			],
			[ '%s', '%d', '%d', '%d', '%s', '%d' ]
		);
		if ( ! $inserted ) {
			return new \WP_Error( 'ab_embed_failed', __( 'Could not create the embed code.', 'agent-brain' ), [ 'status' => 500 ] );
		}

		ShareLinks::audit( $userId, 'embed_code_issued', 'ok', $linkId, [ 'artifact_id' => $artifactId, 'origin' => $normalized ] );

		return [
			'code'       => $code,
			'url'        => sprintf( '%s/share/%d/embed?code=%s', ShareLinks::baseUrl(), $artifactId, $code ),
			'expires_in' => self::CODE_TTL,
			'origin'     => $normalized,
		];
	}

	/**
	 * Consumes a code exactly once. The DELETE is the atomic step: of any
	 * number of concurrent requests only the one whose DELETE removes the row
	 * wins; an expired code is deleted too but never accepted.
	 *
	 * @return array{share_link_id:int,artifact_id:int,user_id:int,origin:string}|null
	 */
	public static function consume( string $code, ?int $now = null ): ?array {
		global $wpdb;

		if ( ! Token::isWellFormed( $code ) ) {
			return null;
		}

		$now   = $now ?? time();
		$table = self::table();
		$hash  = Token::hash( $code );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT * FROM {$table} WHERE code_hash = %s", [ $hash ] );
		if ( $rows === [] ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$deleted = $wpdb->query( Db::prepare( "DELETE FROM {$table} WHERE code_hash = %s", [ $hash ] ) );
		if ( $deleted !== 1 || (int) $rows[0]['expires_at'] < $now ) {
			return null;
		}

		if ( wp_rand( 1, 20 ) === 1 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
			$wpdb->query( Db::prepare( "DELETE FROM {$table} WHERE expires_at < %d", [ $now - 3600 ] ) );
		}

		return [
			'share_link_id' => (int) $rows[0]['share_link_id'],
			'artifact_id'   => (int) $rows[0]['artifact_id'],
			'user_id'       => (int) $rows[0]['user_id'],
			'origin'        => (string) $rows[0]['origin'],
		];
	}

	/**
	 * Per-user hourly cap on issued codes (best effort, transient based).
	 */
	private static function withinRate( int $userId ): bool {
		$key   = 'ab_embed_code_rate_' . $userId;
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_PER_HOUR ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}
}
