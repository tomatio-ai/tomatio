<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\Db;

/**
 * #219: single-use sign-in tickets for private share links, mirroring the
 * embed/viewer exchange codes (#59, ART-24). A viewer of a private link who
 * has just signed in on the MAIN site (ShareAuth) receives one ticket bound
 * to (share link, artifact, user); the share address consumes it exactly
 * once within 60 seconds and hands back the signed ViewerToken (as a
 * host-only, path-scoped cookie plus a URL fragment for the page's own
 * scripts). The ticket is stored only as a sha256 digest.
 */
final class ShareTicket {

	public const TICKET_TTL = 60;

	public const QUERY_PARAM = 'ab_ticket';

	public const COOKIE = 'ab_viewer_token';

	public static function table(): string {
		return Db::table( 'share_tickets' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			ticket_hash CHAR(64) NOT NULL,
			share_link_id BIGINT UNSIGNED NOT NULL,
			artifact_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			expires_at INT UNSIGNED NOT NULL,
			PRIMARY KEY  (ticket_hash),
			KEY expires_at (expires_at)
		) {$charset};";
	}

	/**
	 * Mints a single-use ticket for a user who just signed in on the main
	 * site and may read the link's artifact.
	 *
	 * @return array{ticket:string,expires_in:int}|\WP_Error
	 */
	public static function mint( int $userId, int $linkId, int $artifactId, ?int $now = null ) {
		global $wpdb;

		$now = $now ?? time();
		if ( $userId <= 0 || $linkId <= 0 || $artifactId <= 0 ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		$ticket   = Token::generate();
		$inserted = $wpdb->insert(
			self::table(),
			[
				'ticket_hash'   => Token::hash( $ticket ),
				'share_link_id' => $linkId,
				'artifact_id'   => $artifactId,
				'user_id'       => $userId,
				'expires_at'    => $now + self::TICKET_TTL,
			],
			[ '%s', '%d', '%d', '%d', '%d' ]
		);
		if ( ! $inserted ) {
			return new \WP_Error( 'ab_share_ticket_failed', __( 'Could not create the sign-in ticket.', 'agent-brain' ), [ 'status' => 500 ] );
		}

		return [ 'ticket' => $ticket, 'expires_in' => self::TICKET_TTL ];
	}

	/**
	 * Consumes a ticket exactly once. The DELETE is the atomic step (same
	 * pattern as ViewerExchange::consume): of any number of concurrent
	 * requests only the one whose DELETE removes the row wins; an expired
	 * ticket is deleted too but never accepted. The (link, artifact) binding
	 * was stored at mint time, so a ticket minted for one link can never be
	 * replayed against another link or artifact.
	 *
	 * @return array{share_link_id:int,artifact_id:int,user_id:int}|null
	 */
	public static function consume( string $ticket, int $linkId, int $artifactId, ?int $now = null ): ?array {
		global $wpdb;

		if ( ! Token::isWellFormed( $ticket ) ) {
			return null;
		}

		$now   = $now ?? time();
		$table = self::table();
		$hash  = Token::hash( $ticket );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT * FROM {$table} WHERE ticket_hash = %s", [ $hash ] );
		if ( $rows === [] ) {
			return null;
		}

		$row = $rows[0];
		if ( (int) $row['share_link_id'] !== $linkId || (int) $row['artifact_id'] !== $artifactId ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$deleted = $wpdb->query( Db::prepare( "DELETE FROM {$table} WHERE ticket_hash = %s", [ $hash ] ) );
		if ( $deleted !== 1 || (int) $row['expires_at'] < $now ) {
			return null;
		}

		if ( wp_rand( 1, 20 ) === 1 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
			$wpdb->query( Db::prepare( "DELETE FROM {$table} WHERE expires_at < %d", [ $now - 3600 ] ) );
		}

		return [
			'share_link_id' => (int) $row['share_link_id'],
			'artifact_id'   => $artifactId,
			'user_id'       => (int) $row['user_id'],
		];
	}
}
