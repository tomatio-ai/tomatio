<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * #219: the permanent members address of an artifact, `/a/{artifactId}` on
 * the MAIN site, or `/a/{slug}` (#438) when the artifact has a slug. One fixed address per artifact: it never changes, never
 * expires and carries no token, so members can bookmark it; a new approval
 * shows up at the same address because the route always renders the current
 * APPROVED version (§Versions for older ones via `?v=`).
 *
 * Who may OPEN it is decided in two layers:
 *
 * 1. the artifact read check (read_post: curator, lead, any section owner,
 *    or a #252 reviewer grant) — the same audience a private link admits;
 * 2. the operator's role setting (ab_members_address_roles): which of the
 *    member / curator / manager / lead roles are allowed onto the address at
 *    all. Every role on by default (= layer 1 alone); an operator can narrow
 *    it, e.g. to leads and curators only.
 *
 * Older versions (`?v={seq}`, #220's Versions API) are stricter by design:
 * lead of the artifact, a curator, or an ab_manage person — exactly the
 * people Versions itself admits to section content.
 */
final class MembersAddress {

	public const PATH_PREFIX = '/a/';

	public const OPTION_ROLES = 'ab_members_address_roles';

	/** #438: slugs an artifact had before, so old friendly addresses redirect. */
	public const META_OLD_SLUGS = 'ab_members_old_slug';

	public const ROLE_MEMBER  = 'member';
	public const ROLE_CURATOR = 'curator';
	public const ROLE_MANAGER = 'manager';
	public const ROLE_LEAD    = 'lead';

	public const ROLES = [ self::ROLE_MEMBER, self::ROLE_CURATOR, self::ROLE_MANAGER, self::ROLE_LEAD ];

	/**
	 * The address itself carries no secret: `/a/{artifactId}` is enumerable.
	 * The gate is the sign-in plus the checks above, on every request.
	 */
	public static function url( int $artifactId ): string {
		$slug = self::slug( $artifactId );

		return home_url( self::PATH_PREFIX . ( $slug !== '' ? $slug : (string) $artifactId ) );
	}

	/**
	 * #438: the artifact's slug (post_name) when it makes a friendly address:
	 * lower-case letters, digits and hyphens, starting with a letter. WordPress
	 * makes it from the title; an admin can change it with Quick Edit.
	 */
	public static function slug( int $artifactId ): string {
		$post = get_post( $artifactId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return '';
		}

		return preg_match( '/^[a-z][a-z0-9-]*$/', (string) $post->post_name ) === 1 ? (string) $post->post_name : '';
	}

	/**
	 * #438: the artifact an address segment names: an id, a current slug, or
	 * a slug it had before (redirect true, so the route sends a 301 to the
	 * current address). artifact_id 0 when nothing matches.
	 *
	 * @return array{artifact_id:int,redirect:bool}
	 */
	public static function resolve( string $segment ): array {
		if ( ctype_digit( $segment ) ) {
			return [ 'artifact_id' => (int) $segment, 'redirect' => false ];
		}

		$current = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'name'           => $segment,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		if ( $current !== [] ) {
			return [ 'artifact_id' => (int) $current[0], 'redirect' => false ];
		}

		$previous = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_OLD_SLUGS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one exact lookup, only for an unknown slug.
				'meta_value'     => $segment, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one exact lookup, only for an unknown slug.
			]
		);

		return [ 'artifact_id' => $previous !== [] ? (int) $previous[0] : 0, 'redirect' => $previous !== [] ];
	}

	/**
	 * `post_updated`: remember an artifact's previous slug. WordPress keeps old
	 * slugs only for published posts, and artifacts are private.
	 */
	public static function rememberOldSlug( int $postId, \WP_Post $after, \WP_Post $before ): void {
		if ( $after->post_type !== Cpts::ARTIFACT_POST_TYPE || $before->post_name === '' || $before->post_name === $after->post_name ) {
			return;
		}
		if ( ! in_array( $before->post_name, get_post_meta( $postId, self::META_OLD_SLUGS ), true ) ) {
			add_post_meta( $postId, self::META_OLD_SLUGS, $before->post_name );
		}
	}

	/**
	 * The roles the operator allows onto members addresses (default: all,
	 * which makes layer 1 — the artifact read check — the only gate).
	 *
	 * @return array<int, string>
	 */
	public static function allowedRoles(): array {
		$raw = get_option( self::OPTION_ROLES, self::ROLES );

		return array_values( array_intersect( is_array( $raw ) ? $raw : self::ROLES, self::ROLES ) );
	}

	/**
	 * May this member be told / open the members address of the artifact:
	 * the artifact read check plus the operator's role setting.
	 */
	public static function canSeeAddress( int $actorId, int $artifactId ): bool {
		if ( $actorId <= 0 || ! user_can( $actorId, 'read_post', $artifactId ) ) {
			return false;
		}

		$roles = self::allowedRoles();

		return ( in_array( self::ROLE_MEMBER, $roles, true ) )
			|| ( in_array( self::ROLE_CURATOR, $roles, true ) && ObjectAccess::userIsCurator( $actorId ) )
			|| ( in_array( self::ROLE_MANAGER, $roles, true ) && user_can( $actorId, Capabilities::CAP_MANAGE ) )
			|| ( in_array( self::ROLE_LEAD, $roles, true ) && ObjectAccess::userIsLead( $actorId, $artifactId ) );
	}

	/**
	 * Who may browse older versions on purpose (`?v=`): the artifact's lead,
	 * a curator, or an ab_manage person — the same audience Versions (#220)
	 * admits to section snapshot content.
	 */
	public static function canViewVersions( int $actorId, int $artifactId ): bool {
		return $actorId > 0
			&& ( ObjectAccess::userIsCurator( $actorId )
				|| ObjectAccess::userIsLead( $actorId, $artifactId )
				|| user_can( $actorId, Capabilities::CAP_MANAGE ) );
	}
}
