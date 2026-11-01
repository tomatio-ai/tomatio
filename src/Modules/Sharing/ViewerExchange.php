<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Artifacts\Preview;

/**
 * ART-24 (#164): single-use viewer exchange codes, mirroring the embed code
 * exchange (#59). A logged-in brain member (person or agent) who may read
 * the artifact mints a code bound to (share link, artifact, principal); the
 * sandboxed artifact page consumes it exactly once within 60 seconds and
 * receives a short-lived signed ViewerToken naming the viewer. The code is
 * stored only as a sha256 digest.
 *
 * Codes exist only for share links minted for named members
 * (viewer_mode=members) of an artifact with viewer identity enabled
 * (ViewerMode); every other link and artifact stays anonymous.
 */
final class ViewerExchange {

	public const CODE_TTL = 60;

	public const RATE_PER_HOUR = 60;

	public static function table(): string {
		return Db::table( 'viewer_codes' );
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
			expires_at INT UNSIGNED NOT NULL,
			PRIMARY KEY  (code_hash),
			KEY expires_at (expires_at)
		) {$charset};";
	}

	/**
	 * Mints a single-use viewer code for a members link of an artifact with
	 * viewer identity enabled.
	 *
	 * @return array{code:string,expires_in:int,fragment:string,artifact_id:int,share_link_id:int}|\WP_Error
	 */
	public static function issueCode( int $userId, int $linkId, ?int $now = null ) {
		global $wpdb;

		$now = $now ?? time();
		$deny = new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );

		$link = $userId > 0 ? ShareLinks::find( $linkId ) : null;
		if ( $link === null ) {
			return $deny;
		}

		$artifactId = (int) $link['artifact_id'];
		if ( Config::disabled() || ! Preview::isEnabled() || ! ShareLinks::isServable( get_post( $artifactId ) ) || ! user_can( $userId, 'read_post', $artifactId ) ) {
			return $deny;
		}

		// Only links minted for named members ever admit a named viewer.
		if ( ShareLinks::normalizeViewerMode( (string) ( $link['viewer_mode'] ?? '' ) ) !== ShareLinks::VIEWER_MEMBERS ) {
			return new \WP_Error( 'ab_viewer_anonymous_link', __( 'This share link is anonymous and cannot name its viewers.', 'agent-brain' ), [ 'status' => 422 ] );
		}

		if ( ! ViewerMode::isActive( $artifactId ) ) {
			return new \WP_Error( 'ab_viewer_identity_off', __( 'Viewer identity is not enabled for this artifact.', 'agent-brain' ), [ 'status' => 409 ] );
		}

		// Live link only: not revoked, expired or awaiting approval.
		if ( $link['revoked_at'] !== null || $link['approved_at'] === null || Policy::isExpired( (string) $link['expires_at'], $now ) ) {
			return $deny;
		}

		if ( ! self::withinRate( $userId ) ) {
			return new \WP_Error( 'ab_viewer_rate_limited', __( 'Too many viewer codes requested. Try again later.', 'agent-brain' ), [ 'status' => 429 ] );
		}

		$code     = Token::generate();
		$inserted = $wpdb->insert(
			self::table(),
			[
				'code_hash'     => Token::hash( $code ),
				'share_link_id' => $linkId,
				'artifact_id'   => $artifactId,
				'user_id'       => $userId,
				'expires_at'    => $now + self::CODE_TTL,
			],
			[ '%s', '%d', '%d', '%d', '%d' ]
		);
		if ( ! $inserted ) {
			return new \WP_Error( 'ab_viewer_code_failed', __( 'Could not create the viewer code.', 'agent-brain' ), [ 'status' => 500 ] );
		}

		ShareLinks::audit( $userId, 'viewer_code_issued', 'ok', $linkId, [ 'artifact_id' => $artifactId ] );

		return [
			'code'         => $code,
			'expires_in'   => self::CODE_TTL,
			// Appended to the share URL as the fragment: never sent to any
			// server, never in an access log; the page reads it from
			// location.hash and POSTs it to viewer.json.
			'fragment'     => ViewerRoute::FRAGMENT_PARAM . '=' . $code,
			'artifact_id'  => $artifactId,
			'share_link_id' => $linkId,
		];
	}

	/**
	 * Consumes a code exactly once. The DELETE is the atomic step: of any
	 * number of concurrent requests only the one whose DELETE removes the row
	 * wins; an expired code is deleted too but never accepted.
	 *
	 * @return array{share_link_id:int,artifact_id:int,user_id:int}|null
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
		];
	}

	/**
	 * Per-user hourly cap on issued codes (best effort, transient based).
	 */
	private static function withinRate( int $userId ): bool {
		$key   = 'ab_viewer_code_rate_' . $userId;
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_PER_HOUR ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}
}
