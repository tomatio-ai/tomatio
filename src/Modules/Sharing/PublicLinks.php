<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\Db;
use AgentBrain\Core\SecretBox;

/**
 * #645: public share links stay retrievable, so the signed-in home page can
 * show and copy each page's public link. Only a public link of kind `link`
 * keeps its token, sealed with SecretBox (libsodium secretbox, a fresh
 * random nonce per seal) in the share_links `token_box` column. The key is
 * derived by HKDF from the site's secret key material (AGENT_BRAIN_SECRET_KEY,
 * otherwise wp-config.php's AUTH_KEY and AUTH_SALT) for this purpose only.
 * Private (sign-in) links and embed links store nothing; revoking a link
 * clears its box. The token_hash stays the only lookup key: an opened token
 * is used only when its hash still matches the row it came from.
 *
 * When the site key material changes, the boxes no longer open: the home
 * page then shows no public marker (never an error), the links themselves
 * keep working, and `wp tomatio share remember <url>` stores them again.
 * AGENT_BRAIN_SECRET_KEY_PREVIOUS lets a rotation re-seal them on next read.
 */
final class PublicLinks {

	public const PURPOSE = 'share_link_token';

	/**
	 * Whether a link of this privacy and kind keeps its token.
	 */
	public static function qualifies( string $privacy, string $kind ): bool {
		return ShareLinks::normalizePrivacy( $privacy ) === ShareLinks::PRIVACY_PUBLIC
			&& EmbedPolicy::normalizeKind( $kind ) === EmbedPolicy::KIND_LINK;
	}

	/**
	 * The sealed token, or null when the site has no usable key material.
	 */
	public static function seal( string $token ): ?string {
		try {
			return SecretBox::seal( $token, SecretBox::currentMaterial(), self::PURPOSE );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Seals and stores the token of a link. Never fails the caller: false
	 * when nothing could be stored.
	 */
	public static function store( int $linkId, string $token ): bool {
		global $wpdb;

		$box = self::seal( $token );
		if ( $box === null || $linkId <= 0 ) {
			return false;
		}

		$updated = $wpdb->update( ShareLinks::table(), [ 'token_box' => $box ], [ 'id' => $linkId ], [ '%s' ], [ '%d' ] );

		return $updated !== false;
	}

	/**
	 * Clears the stored token of a link (revoked, or no longer public).
	 */
	public static function forget( int $linkId ): void {
		global $wpdb;

		if ( $linkId <= 0 ) {
			return;
		}

		$table = ShareLinks::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table().
		$wpdb->query( Db::prepare( "UPDATE {$table} SET token_box = NULL WHERE id = %d AND token_box IS NOT NULL", [ $linkId ] ) );
	}

	/**
	 * The token of a row, or null: no box, a box that does not open under
	 * the current (or previous) key material, or a token whose hash is not
	 * the row's. Never throws.
	 *
	 * @param array<string, mixed> $row A share_links row with id, token_hash and token_box.
	 */
	public static function open( array $row ): ?string {
		$box = isset( $row['token_box'] ) ? (string) $row['token_box'] : '';
		if ( $box === '' ) {
			return null;
		}

		$token = null;
		try {
			$token = SecretBox::open( $box, SecretBox::currentMaterial(), self::PURPOSE );
		} catch ( \Throwable $e ) {
			$token = self::openWithPrevious( $box, (int) ( $row['id'] ?? 0 ) );
		}

		if ( ! is_string( $token ) || ! Token::isWellFormed( $token ) || ! hash_equals( (string) ( $row['token_hash'] ?? '' ), Token::hash( $token ) ) ) {
			return null;
		}

		return $token;
	}

	/**
	 * Rotation: a box sealed under AGENT_BRAIN_SECRET_KEY_PREVIOUS opens with
	 * it and is re-sealed under the current material at once.
	 */
	private static function openWithPrevious( string $box, int $linkId ): ?string {
		try {
			$previous = SecretBox::previousMaterial();
			if ( $previous === null ) {
				return null;
			}
			$token = SecretBox::open( $box, $previous, self::PURPOSE );
		} catch ( \Throwable $e ) {
			return null;
		}

		self::store( $linkId, $token );

		return $token;
	}

	/**
	 * The newest live public link of each artifact: not revoked, not
	 * expired, approved, kind link, with a stored token that opens. Nothing
	 * while sharing is disabled.
	 *
	 * @param array<int, int> $artifactIds
	 * @return array<int, string> artifact id => full share URL
	 */
	public static function urlsFor( array $artifactIds ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $artifactIds ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( $ids === [] || Config::disabled() ) {
			return [];
		}

		$table        = ShareLinks::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( $ids, [ ShareLinks::PRIVACY_PUBLIC, EmbedPolicy::KIND_LINK, gmdate( 'Y-m-d H:i:s' ) ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from Db::table(), placeholders built above.
		$rows = Db::getResults( "SELECT id, artifact_id, token_hash, token_box FROM {$table} WHERE artifact_id IN ({$placeholders}) AND privacy = %s AND kind = %s AND token_box IS NOT NULL AND revoked_at IS NULL AND approved_at IS NOT NULL AND expires_at > %s ORDER BY id DESC", $params );

		$out = [];
		foreach ( $rows as $row ) {
			$artifactId = (int) $row['artifact_id'];
			if ( isset( $out[ $artifactId ] ) ) {
				continue;
			}
			$token = self::open( $row );
			if ( $token !== null ) {
				$out[ $artifactId ] = ShareLinks::url( $artifactId, $token );
			}
		}

		return $out;
	}

	/**
	 * Backfill (`wp tomatio share remember <url>`): stores the token of an
	 * existing public link from its full address. The token must hash to a
	 * live, approved, public link (kind link) of the artifact the address
	 * names; anything else is refused. Messages never carry the token.
	 *
	 * @return array{link_id:int, artifact_id:int}|\WP_Error
	 */
	public static function remember( string $url, int $actorId = 0 ) {
		$path = wp_parse_url( trim( $url ), PHP_URL_PATH );
		if ( ! is_string( $path ) || preg_match( '#/share/(\d+)/([A-Za-z0-9_-]+)/?$#', $path, $m ) !== 1 || ! Token::isWellFormed( $m[2] ) ) {
			return new \WP_Error( 'ab_share_not_a_link', __( 'That is not a share link address. Give the full link, such as https://brain.example.com/share/12/<token>.', 'agent-brain' ) );
		}

		$artifactId = (int) $m[1];
		$token      = $m[2];
		$link       = ShareLinks::findByToken( $token );

		if ( $link === null ) {
			return new \WP_Error( 'ab_share_unknown', __( 'No share link on this brain matches that address. Nothing was stored.', 'agent-brain' ) );
		}

		$linkId = (int) $link['id'];
		if ( (int) $link['artifact_id'] !== $artifactId ) {
			/* translators: %d: the artifact id in the address. */
			return new \WP_Error( 'ab_share_wrong_artifact', sprintf( __( 'That token is not a link of artifact %d. Nothing was stored.', 'agent-brain' ), $artifactId ) );
		}
		if ( $link['revoked_at'] !== null ) {
			/* translators: %d: share link id. */
			return new \WP_Error( 'ab_share_revoked', sprintf( __( 'Share link #%d was revoked. Nothing was stored.', 'agent-brain' ), $linkId ) );
		}
		if ( Policy::isExpired( (string) $link['expires_at'], time() ) ) {
			/* translators: %d: share link id. */
			return new \WP_Error( 'ab_share_expired', sprintf( __( 'Share link #%d has expired. Nothing was stored.', 'agent-brain' ), $linkId ) );
		}
		if ( ShareLinks::normalizePrivacy( (string) ( $link['privacy'] ?? '' ) ) !== ShareLinks::PRIVACY_PUBLIC ) {
			/* translators: %d: share link id. */
			return new \WP_Error( 'ab_share_private', sprintf( __( 'Share link #%d is a private (sign-in) link. Only public links are remembered. Nothing was stored.', 'agent-brain' ), $linkId ) );
		}
		if ( EmbedPolicy::normalizeKind( (string) ( $link['kind'] ?? '' ) ) !== EmbedPolicy::KIND_LINK ) {
			/* translators: %d: share link id. */
			return new \WP_Error( 'ab_share_embed', sprintf( __( 'Share link #%d is an embed link. Only public links are remembered. Nothing was stored.', 'agent-brain' ), $linkId ) );
		}
		if ( $link['approved_at'] === null ) {
			/* translators: %d: share link id. */
			return new \WP_Error( 'ab_share_unapproved', sprintf( __( 'Share link #%d is waiting for approval. Nothing was stored.', 'agent-brain' ), $linkId ) );
		}
		if ( ! self::store( $linkId, $token ) ) {
			return new \WP_Error( 'ab_share_seal', __( 'The link could not be encrypted: the site has no usable key material (set AUTH_KEY and AUTH_SALT in wp-config.php). Nothing was stored.', 'agent-brain' ) );
		}

		ShareLinks::audit( $actorId, 'share_link_remembered', 'ok', $linkId, [ 'artifact_id' => $artifactId ] );

		return [ 'link_id' => $linkId, 'artifact_id' => $artifactId ];
	}
}
