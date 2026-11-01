<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Artifacts\Preview;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * Share-link service: mint / revoke / list / validate. A sha256 of the
 * token is the only lookup key; the raw token is returned exactly once by
 * mint(). #645: a public link (kind link) also keeps its token sealed under
 * the site's key material (PublicLinks, column token_box) so the home page
 * can show it; nothing else stores token material. Audit events reference
 * the link by row id and never carry token material.
 */
final class ShareLinks {

	public const STATUS_OK          = 'ok';
	public const STATUS_INVALID     = 'invalid';
	public const STATUS_EXPIRED     = 'expired';
	public const STATUS_REVOKED     = 'revoked';
	public const STATUS_UNAPPROVED  = 'unapproved';
	public const STATUS_IP_MISMATCH = 'ip_mismatch';
	public const STATUS_DISABLED    = 'disabled';

	/**
	 * ART-24 (#164): who a link admits as a named viewer. Every link is
	 * anonymous by default; only a link minted for named members may yield a
	 * signed viewer token (and only on an artifact with viewer identity on).
	 */
	public const VIEWER_ANONYMOUS = 'anonymous';
	public const VIEWER_MEMBERS   = 'members';

	public const VIEWER_MODES = [ self::VIEWER_ANONYMOUS, self::VIEWER_MEMBERS ];

	/**
	 * #286: who a link serves, decided by the operator (new links are PRIVATE
	 * by default). A public link serves anyone holding it (the pre-#286
	 * behaviour). A private link serves only a named viewer: the request must
	 * carry a valid ViewerToken for this link, so the viewer identity flow
	 * (viewer.json code exchange, ART-24) must happen before the page renders;
	 * an anonymous request is the uniform 404. A private link is always a
	 * members link (viewer_mode is forced), and only works on an artifact
	 * with viewer identity enabled — until it is, nobody can open it.
	 */
	public const PRIVACY_PRIVATE = 'private';
	public const PRIVACY_PUBLIC  = 'public';

	public const PRIVACY_MODES = [ self::PRIVACY_PRIVATE, self::PRIVACY_PUBLIC ];

	public static function table(): string {
		return Db::table( 'share_links' );
	}

	public static function createTableSql(): string {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			artifact_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL,
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			approved_at DATETIME NULL,
			ip_mode VARCHAR(16) NOT NULL DEFAULT 'off',
			kind VARCHAR(8) NOT NULL DEFAULT 'link',
			viewer_mode VARCHAR(16) NOT NULL DEFAULT 'anonymous',
			privacy VARCHAR(8) NOT NULL DEFAULT 'private',
			bound_ip_hash CHAR(64) NULL,
			first_open_at DATETIME NULL,
			last_open_at DATETIME NULL,
			open_count INT UNSIGNED NOT NULL DEFAULT 0,
			token_box VARCHAR(255) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY artifact_id (artifact_id)
		) {$charset};";
	}

	/**
	 * Lead of the artifact, a curator, or an ab_manage person. Agents never
	 * need ab_publish (which no agent can hold).
	 */
	public static function canManage( int $actorId, int $artifactId ): bool {
		if ( $actorId <= 0 || ! self::isArtifact( $artifactId ) ) {
			return false;
		}

		return ObjectAccess::userIsLead( $actorId, $artifactId )
			|| ObjectAccess::userIsCurator( $actorId )
			|| user_can( $actorId, Capabilities::CAP_MANAGE );
	}

	/**
	 * Discovery-time approximation: may this user manage share links of ANY artifact?
	 */
	public static function canManageAny( int $actorId ): bool {
		if ( $actorId <= 0 ) {
			return false;
		}

		if ( ObjectAccess::userIsCurator( $actorId ) || user_can( $actorId, Capabilities::CAP_MANAGE ) ) {
			return true;
		}

		$led = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => Cpts::META_LEAD_AGENT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $actorId, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		return $led !== [];
	}

	/**
	 * @return array{id:int,token:string,url:string,expires_at:string,ip_mode:string,pending_approval:bool,kind:string,viewer_mode:string,privacy:string}|\WP_Error
	 */
	public static function mint( int $artifactId, int $actorId, ?int $ttlSeconds = null, ?string $ipMode = null, ?string $clientIp = null, string $kind = EmbedPolicy::KIND_LINK, string $viewerMode = self::VIEWER_ANONYMOUS, string $privacy = self::PRIVACY_PRIVATE ) {
		global $wpdb;

		if ( Config::disabled() ) {
			return new \WP_Error( 'ab_sharing_disabled', __( 'Sharing is disabled on this site.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( ! self::canManage( $actorId, $artifactId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		// #286: without a preview host the share address IS this site, so
		// there is nothing to prove. The old "previews are disabled" refusal
		// is gone: same-address mode works with no settings at all.
		$reachability = null;
		if ( Preview::configuredHost() !== '' ) {
			// #285: never mint a link the preview host cannot serve. The check is
			// cached (HostReachability) so this does no live HTTP per mint. A
			// check that could not run at all (outbound HTTP blocked) stays
			// allowed, flagged below — a finished check that did not verify is
			// proof of a dead link and refuses.
			$reachability = HostReachability::check();
			if ( ! HostReachability::allowsMint( $reachability ) ) {
				return new \WP_Error(
					'ab_preview_host_unreachable',
					sprintf(
						/* translators: 1: preview host, 2: outcome of the last reachability check. */
						__( 'This preview host does not reach this site: point its DNS or proxy at this site. Last check of %1$s: %2$s', 'agent-brain' ),
						$reachability['host'],
						PreviewCheck::message( $reachability['result'] )
					),
					[ 'status' => 409, 'settings_url' => \AgentBrain\Modules\Admin\SettingsHub::url( 'sharing' ) ]
				);
			}
		}

		if ( self::mintedInLastHour( $actorId ) >= Config::mintRatePerHour() ) {
			return new \WP_Error( 'ab_share_rate_limited', __( 'Too many share links minted recently. Try again later.', 'agent-brain' ), [ 'status' => 429 ] );
		}

		// ip_bind not given: fall back to the site default (ab_share_default_ip_mode).
		$mode  = Policy::effectiveIpMode( $ipMode ?? Config::defaultIpMode(), Config::enforceIpBinding() );
		$bound = null;
		if ( $mode === Policy::IP_FIXED ) {
			$ip    = $clientIp ?? Config::clientIp();
			$bound = $ip !== '' ? Config::ipHash( $ip ) : '';
			if ( $bound === '' ) {
				return new \WP_Error( 'ab_share_no_ip', __( 'Cannot bind to the minting client IP: it could not be determined.', 'agent-brain' ), [ 'status' => 422 ] );
			}
		}

		$kind     = EmbedPolicy::normalizeKind( $kind );
		$privacy  = self::normalizePrivacy( $privacy );
		// A private link only serves named viewers, so it is always a members
		// link: the viewer code exchange must be able to name them.
		$viewer   = $privacy === self::PRIVACY_PRIVATE ? self::VIEWER_MEMBERS : self::normalizeViewerMode( $viewerMode );
		$now      = time();
		$expires  = $now + EmbedPolicy::ttlFor( $kind, $ttlSeconds );
		$token    = Token::generate();
		// #230: a pure agent identity (no person acting via it) may only
		// propose a link — it is created pending_approval whatever the global
		// ab_share_require_approval default, and a person approves it live.
		$pending  = ( Config::requireApproval() && ! ObjectAccess::userIsCurator( $actorId ) && ! user_can( $actorId, Capabilities::CAP_MANAGE ) )
			|| Capabilities::isPureAgent( $actorId );
		$inserted = $wpdb->insert(
			self::table(),
			[
				'artifact_id'   => $artifactId,
				'token_hash'    => Token::hash( $token ),
				'created_by'    => $actorId,
				'created_at'    => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'    => gmdate( 'Y-m-d H:i:s', $expires ),
				'approved_at'   => $pending ? null : gmdate( 'Y-m-d H:i:s', $now ),
				'ip_mode'       => $mode,
				'bound_ip_hash' => $bound,
				'kind'          => $kind,
				'viewer_mode'   => $viewer,
				'privacy'       => $privacy,
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'ab_share_failed', __( 'Could not create the share link.', 'agent-brain' ), [ 'status' => 500 ] );
		}

		$id = (int) $wpdb->insert_id;
		// #645: a public link keeps its token, sealed, for the home page.
		// A separate write, so a failure to seal never fails the mint.
		if ( PublicLinks::qualifies( $privacy, $kind ) ) {
			PublicLinks::store( $id, $token );
		}
		self::audit( $actorId, 'share_link_minted', 'ok', $id, [ 'artifact_id' => $artifactId, 'ip_mode' => $mode, 'pending_approval' => $pending, 'kind' => $kind, 'viewer_mode' => $viewer, 'privacy' => $privacy ] );

		$result = [
			'id'               => $id,
			'token'            => $token,
			'url'              => self::url( $artifactId, $token ),
			'expires_at'       => gmdate( 'c', $expires ),
			'ip_mode'          => $mode,
			'pending_approval' => $pending,
			'kind'             => $kind,
			'viewer_mode'      => $viewer,
			'privacy'          => $privacy,
		];

		// The host was saved but the reachability check cannot run on this
		// server (outbound HTTP blocked): allowed, visibly unverified.
		if ( $reachability !== null && $reachability['result'] === PreviewCheck::UNKNOWN ) {
			$result['preview_host_check'] = 'unverified';
		}

		return $result;
	}

	/**
	 * #286: the address share links are served from. A configured preview
	 * host wins (https, its own certificate); otherwise the site's own
	 * address (same-address mode), keeping home_url()'s scheme and port so
	 * plain-http local installs produce working links too.
	 */
	public static function baseUrl(): string {
		if ( Preview::configuredHost() !== '' ) {
			return 'https://' . Preview::configuredHost();
		}

		return untrailingslashit( home_url() );
	}

	public static function url( int $artifactId, string $token ): string {
		return sprintf( '%s/share/%d/%s', self::baseUrl(), $artifactId, $token );
	}

	/**
	 * #645: the full URL of the artifact's newest live public link (not
	 * revoked, not expired, approved, kind link), or null. Null too when
	 * its stored token does not open (for example after the site keys
	 * changed); never throws.
	 */
	public static function publicUrlFor( int $artifactId ): ?string {
		if ( ! self::isServable( $artifactId > 0 ? get_post( $artifactId ) : null ) ) {
			return null;
		}

		return PublicLinks::urlsFor( [ $artifactId ] )[ $artifactId ] ?? null;
	}

	/**
	 * publicUrlFor() for many artifacts in one query.
	 *
	 * @param array<int, int> $artifactIds
	 * @return array<int, string> artifact id => URL, only those with a live public link.
	 */
	public static function publicUrlsFor( array $artifactIds ): array {
		return PublicLinks::urlsFor( $artifactIds );
	}

	/**
	 * Every stored value predating ART-24 (#164) reads as anonymous.
	 */
	public static function normalizeViewerMode( string $mode ): string {
		return $mode === self::VIEWER_MEMBERS ? self::VIEWER_MEMBERS : self::VIEWER_ANONYMOUS;
	}

	/**
	 * #286: values predating the privacy column read as public, matching how
	 * they behaved when they were minted.
	 */
	public static function normalizePrivacy( string $privacy ): string {
		return $privacy === self::PRIVACY_PUBLIC ? self::PRIVACY_PUBLIC : self::PRIVACY_PRIVATE;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function revoke( int $linkId, int $actorId ) {
		global $wpdb;

		$row = self::find( $linkId );
		if ( ! $row || ! self::canManage( $actorId, (int) $row['artifact_id'] ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( $row['revoked_at'] === null ) {
			$wpdb->update( self::table(), [ 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $linkId ], [ '%s' ], [ '%d' ] );
			self::audit( $actorId, 'share_link_revoked', 'ok', $linkId, [ 'artifact_id' => (int) $row['artifact_id'] ] );
		}
		PublicLinks::forget( $linkId );

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function approve( int $linkId, int $actorId ) {
		global $wpdb;

		$row = self::find( $linkId );
		if ( ! $row || ! ( ObjectAccess::userIsCurator( $actorId ) || user_can( $actorId, Capabilities::CAP_MANAGE ) ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( $row['approved_at'] === null ) {
			$wpdb->update( self::table(), [ 'approved_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $linkId ], [ '%s' ], [ '%d' ] );
			self::audit( $actorId, 'share_link_approved', 'ok', $linkId, [ 'artifact_id' => (int) $row['artifact_id'] ] );
		}

		return true;
	}

	/**
	 * Links of an artifact (never includes token material or the IP hash).
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function listForArtifact( int $artifactId, int $actorId ) {
		if ( ! self::canManage( $actorId, $artifactId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT id, artifact_id, created_by, created_at, expires_at, revoked_at, approved_at, ip_mode, kind, viewer_mode, privacy, bound_ip_hash, first_open_at, last_open_at, open_count FROM {$table} WHERE artifact_id = %d ORDER BY id DESC", [ $artifactId ] );
		$now   = time();
		$out   = [];

		foreach ( $rows as $row ) {
			$out[] = [
				'id'            => (int) $row['id'],
				'artifact_id'   => (int) $row['artifact_id'],
				'created_by'    => (int) $row['created_by'],
				'created_at'    => (string) $row['created_at'],
				'expires_at'    => (string) $row['expires_at'],
				'revoked_at'    => $row['revoked_at'],
				'ip_mode'       => (string) $row['ip_mode'],
				'kind'          => EmbedPolicy::normalizeKind( (string) ( $row['kind'] ?? '' ) ),
				'viewer_mode'   => self::normalizeViewerMode( (string) ( $row['viewer_mode'] ?? '' ) ),
				'privacy'       => self::normalizePrivacy( (string) ( $row['privacy'] ?? '' ) ),
				'ip_bound'      => ! empty( $row['bound_ip_hash'] ),
				'first_open_at' => $row['first_open_at'],
				'last_open_at'  => $row['last_open_at'],
				'open_count'    => (int) $row['open_count'],
				'status'        => self::displayStatus( $row, $now ),
			];
		}

		return $out;
	}

	/**
	 * Links that still open, across every document $actorId may manage,
	 * newest first (#751: the Grants page lists what is public).
	 *
	 * @return array<int, array{id:int, artifact_id:int, created_by:int, created_at:string, expires_at:string, kind:string, viewer_mode:string, privacy:string, open_count:int, last_open_at:?string, approved:bool}>
	 */
	public static function live( int $actorId, int $limit = 200 ): array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT id, artifact_id, created_by, created_at, expires_at, approved_at, kind, viewer_mode, privacy, last_open_at, open_count FROM {$table} WHERE revoked_at IS NULL ORDER BY id DESC LIMIT %d", [ max( 1, $limit ) ] );
		$now  = time();
		$out  = [];

		foreach ( $rows as $row ) {
			$artifactId = (int) $row['artifact_id'];
			if ( Policy::isExpired( (string) $row['expires_at'], $now ) || ! self::canManage( $actorId, $artifactId ) ) {
				continue;
			}
			$out[] = [
				'id'           => (int) $row['id'],
				'artifact_id'  => $artifactId,
				'created_by'   => (int) $row['created_by'],
				'created_at'   => (string) $row['created_at'],
				'expires_at'   => (string) $row['expires_at'],
				'kind'         => EmbedPolicy::normalizeKind( (string) ( $row['kind'] ?? '' ) ),
				'viewer_mode'  => self::normalizeViewerMode( (string) ( $row['viewer_mode'] ?? '' ) ),
				'privacy'      => self::normalizePrivacy( (string) ( $row['privacy'] ?? '' ) ),
				'open_count'   => (int) $row['open_count'],
				'last_open_at' => $row['last_open_at'] !== null ? (string) $row['last_open_at'] : null,
				'approved'     => $row['approved_at'] !== null,
			];
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function displayStatus( array $row, int $now ): string {
		if ( $row['revoked_at'] !== null ) {
			return 'revoked';
		}
		if ( Policy::isExpired( (string) $row['expires_at'], $now ) ) {
			return 'expired';
		}

		return $row['approved_at'] === null ? 'pending_approval' : 'active';
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find( int $linkId ): ?array {
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT * FROM {$table} WHERE id = %d", [ $linkId ] );

		return $rows[0] ?? null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function findByToken( string $token ): ?array {
		if ( ! Token::isWellFormed( $token ) ) {
			return null;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT * FROM {$table} WHERE token_hash = %s", [ Token::hash( $token ) ] );

		return $rows[0] ?? null;
	}

	/**
	 * Validates a presented token for an artifact. Revocation, expiry and IP
	 * binding are evaluated on every call (no caching). Any non-ok status must
	 * be rendered as the same 404.
	 *
	 * @return array{status:string,link:array<string,mixed>|null}
	 */
	public static function validate( string $token, int $artifactId, string $clientIpHash, ?int $now = null ): array {
		if ( Config::disabled() ) {
			return [ 'status' => self::STATUS_DISABLED, 'link' => null ];
		}

		$link = self::findByToken( $token );
		if ( $link === null || (int) $link['artifact_id'] !== $artifactId || ! self::isServable( $artifactId > 0 ? get_post( $artifactId ) : null ) ) {
			return [ 'status' => self::STATUS_INVALID, 'link' => null ];
		}

		return self::evaluate( $link, $clientIpHash, $now ?? time() );
	}

	/**
	 * Revocation, expiry, approval and IP binding of an already-found link
	 * (shared by token validation and the embed cookie re-check).
	 *
	 * @param array<string, mixed> $link
	 * @return array{status:string,link:array<string,mixed>|null}
	 */
	public static function evaluate( array $link, string $clientIpHash, int $now ): array {
		global $wpdb;

		$id = (int) $link['id'];

		if ( $link['revoked_at'] !== null ) {
			self::audit( 0, 'share_link_revoked_access_attempt', 'denied', $id, [] );

			return [ 'status' => self::STATUS_REVOKED, 'link' => $link ];
		}

		if ( Policy::isExpired( (string) $link['expires_at'], $now ) ) {
			self::audit( 0, 'share_link_expired_access_attempt', 'denied', $id, [] );

			return [ 'status' => self::STATUS_EXPIRED, 'link' => $link ];
		}

		if ( $link['approved_at'] === null ) {
			return [ 'status' => self::STATUS_UNAPPROVED, 'link' => $link ];
		}

		$mode = Policy::effectiveIpMode( (string) $link['ip_mode'], Config::enforceIpBinding() );
		if ( $mode !== Policy::IP_OFF ) {
			$bound = $link['bound_ip_hash'] !== null ? (string) $link['bound_ip_hash'] : null;

			if ( $bound === null && $mode === Policy::IP_FIRST_OPEN && $clientIpHash !== '' ) {
				$table = self::table();
				// Atomic bind: only the first opener wins the NULL -> hash update.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
				$wpdb->query( Db::prepare( "UPDATE {$table} SET bound_ip_hash = %s WHERE id = %d AND bound_ip_hash IS NULL", [ $clientIpHash, $id ] ) );
				$fresh = self::find( $id );
				$bound = $fresh !== null && $fresh['bound_ip_hash'] !== null ? (string) $fresh['bound_ip_hash'] : null;
			}

			if ( ! Policy::ipMatches( $mode, $bound, $clientIpHash ) || $bound === null ) {
				self::audit( 0, 'share_link_ip_mismatch', 'denied', $id, [ 'ip_mode' => $mode ] );

				return [ 'status' => self::STATUS_IP_MISMATCH, 'link' => $link ];
			}
		}

		return [ 'status' => self::STATUS_OK, 'link' => $link ];
	}

	public static function recordOpen( int $linkId ): void {
		global $wpdb;

		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$wpdb->query( Db::prepare( "UPDATE {$table} SET open_count = open_count + 1, last_open_at = %s, first_open_at = COALESCE(first_open_at, %s) WHERE id = %d", [ $now, $now, $linkId ] ) );
		self::audit( 0, 'share_link_viewed', 'ok', $linkId, [] );
	}

	private static function mintedInLastHour( int $actorId ): int {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		return (int) $wpdb->get_var( Db::prepare( "SELECT COUNT(*) FROM {$table} WHERE created_by = %d AND created_at >= %s", [ $actorId, gmdate( 'Y-m-d H:i:s', time() - 3600 ) ] ) );
	}

	private static function isArtifact( int $artifactId ): bool {
		$post = $artifactId > 0 ? get_post( $artifactId ) : null;

		return $post !== null && $post->post_type === Cpts::ARTIFACT_POST_TYPE;
	}

	/**
	 * An artifact may be served through a share link only while it exists and
	 * has not been trashed (or is an unsaved auto-draft).
	 *
	 * @param \WP_Post|null|false $post
	 */
	public static function isServable( $post ): bool {
		return $post instanceof \WP_Post
			&& $post->post_type === Cpts::ARTIFACT_POST_TYPE
			&& ! in_array( $post->post_status, [ 'trash', 'auto-draft' ], true );
	}

	/**
	 * Revokes every still-live link of an artifact (system action, no actor
	 * permission check): used on unpublish / trash / delete.
	 *
	 * @return int Number of links revoked.
	 */
	public static function revokeAllForArtifact( int $artifactId, string $reason = '' ): int {
		global $wpdb;

		if ( $artifactId <= 0 ) {
			return 0;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$rows = Db::getResults( "SELECT id FROM {$table} WHERE artifact_id = %d AND revoked_at IS NULL", [ $artifactId ] );
		$now  = gmdate( 'Y-m-d H:i:s' );

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$wpdb->update( self::table(), [ 'revoked_at' => $now ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
			self::audit( 0, 'share_link_revoked', 'ok', $id, [ 'artifact_id' => $artifactId, 'reason' => $reason ] );
			PublicLinks::forget( $id );
		}

		return count( $rows );
	}

	/**
	 * @param array<string, mixed> $meta Never token material.
	 */
	public static function audit( int $actorId, string $event, string $status, int $linkId, array $meta ): void {
		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => $event,
				'status'      => $status,
				'object_type' => 'share_link',
				'object_id'   => (string) $linkId,
				'meta'        => $meta,
			]
		);
	}
}
