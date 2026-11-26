<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * ART-24 (#164): resolves a brain member (a person or an agent — both are
 * WordPress users; agents carry the agent registration meta) into the viewer
 * descriptor an artifact page receives:
 *
 *   { principal_id, principal_type, name, roles }
 *
 * principal_id is the WordPress user id in both cases; principal_type
 * disambiguates. Roles are the artifact-relevant ones: every member has
 * "member", plus "curator" / "lead" / "manage" when they hold that position
 * on this artifact. Sibling tasks (ART-23 data store, ART-25 brain-read,
 * ART-27 uploads) apply these roles when attributing writes or applying the
 * viewer's grants. The descriptor is snapshotted into the signed
 * ViewerToken at exchange time, so it reflects the member as they were when
 * the page loaded; revocation of the share link or key rotation bounds how
 * long a stale snapshot lives (at most the token TTL).
 */
final class ViewerIdentity {

	/**
	 * The anonymous viewer every share page gets unless a member completes
	 * the viewer code exchange.
	 *
	 * @return array{anonymous:true}
	 */
	public static function anonymous(): array {
		return [ 'anonymous' => true ];
	}

	/**
	 * The viewer descriptor for a user opening an artifact, or null when the
	 * user no longer exists (a code outlives a deleted account by at most its
	 * 60 second TTL).
	 *
	 * @return array{principal_id:int,principal_type:string,name:string,roles:array<int,string>}|null
	 */
	public static function describe( int $userId, int $artifactId ): ?array {
		$user = $userId > 0 ? get_userdata( $userId ) : false;
		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		$type = self::isAgent( $user ) ? ViewerToken::TYPE_AGENT : ViewerToken::TYPE_PERSON;
		$name = (string) get_user_meta( $userId, AgentMeta::FQDN, true );
		if ( $name === '' ) {
			$name = (string) $user->display_name;
		}

		$roles = [ 'member' ];
		if ( ObjectAccess::userIsCurator( $userId ) ) {
			$roles[] = 'curator';
		}
		if ( ObjectAccess::userIsLead( $userId, $artifactId ) ) {
			$roles[] = 'lead';
		}
		if ( user_can( $userId, Capabilities::CAP_MANAGE ) ) {
			$roles[] = 'manage';
		}

		return [
			'principal_id'   => $userId,
			'principal_type' => $type,
			'name'           => $name,
			'roles'          => $roles,
		];
	}

	private static function isAgent( \WP_User $user ): bool {
		if ( get_user_meta( $user->ID, AgentMeta::FQDN, true ) !== '' ) {
			return true;
		}

		return in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true );
	}
}
