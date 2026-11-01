<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

/**
 * #400: never hand out a private link the artifact can never serve.
 *
 * A private link only opens for brain members who sign in, which needs viewer
 * identity on for the artifact (ART-24, ViewerMode). Minted while it is off,
 * the link ends every sign-in in the uniform 404. The two places people and
 * agents mint links (the artifact-share-create ability and the admin Share
 * panel) ask this first; ShareLinks::mint() itself is unchanged.
 */
final class PrivateLinkGuard {

	/**
	 * Null when the link can be served (or the mint will fail for another,
	 * reported reason); otherwise a WP_Error that says why and how to fix it.
	 */
	public static function check( int $artifactId, string $privacy ): ?\WP_Error {
		if ( ShareLinks::normalizePrivacy( $privacy ) !== ShareLinks::PRIVACY_PRIVATE || Config::disabled() || ViewerMode::isActive( $artifactId ) ) {
			return null;
		}

		$data = [
			'status'      => 409,
			'artifact_id' => $artifactId,
			'edit_url'    => admin_url( 'post.php?post=' . $artifactId . '&action=edit' ),
		];

		if ( ViewerMode::killSwitchOn() ) {
			return new \WP_Error(
				'ab_share_private_needs_viewer_identity',
				__( 'A private link only opens for members who sign in, and viewer identity is switched off for the whole site, so this link could never open. An administrator can turn it back on in Settings, Sharing; until then, mint a public link (privacy=public).', 'agent-brain' ),
				$data
			);
		}

		return new \WP_Error(
			'ab_share_private_needs_viewer_identity',
			sprintf(
				/* translators: %d: artifact id. */
				__( 'A private link only opens for members who sign in, and viewer identity is off for artifact %d, so this link could never open. A curator or manager can turn on "Viewer identity" on the artifact\'s edit screen and mint it again, or mint a public link instead (privacy=public).', 'agent-brain' ),
				$artifactId
			),
			$data
		);
	}
}
