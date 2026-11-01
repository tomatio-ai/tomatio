<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Modules\Identity\UserRef;

/**
 * Refusals that say who may act and who the caller is (#302). A bare "You
 * do not have permission to do this." left an agent guessing; these name the
 * lead or owner, the caller, and the way forward. Same code and status as
 * before (ab_forbidden, 403), so callers that branch on them are unchanged.
 */
final class Refusals {

	/** Only the artifact's lead or a curator may change its outline or sections. */
	public static function notLead( int $actorId, int $artifactId ): \WP_Error {
		$leadId = (int) get_post_meta( $artifactId, Cpts::META_LEAD_AGENT, true );

		return new \WP_Error(
			'ab_forbidden',
			sprintf(
				/* translators: 1: artifact id, 2: the lead, 3: the caller. */
				__( 'Only artifact %1$d\'s lead or a curator can do this. Its lead is %2$s; you are %3$s. Act as the lead, or ask a curator.', 'agent-brain' ),
				$artifactId,
				UserRef::describe( $leadId ),
				UserRef::describe( $actorId )
			),
			[
				'status'      => 403,
				'artifact_id' => $artifactId,
				'lead_id'     => $leadId,
				'actor_id'    => $actorId,
			]
		);
	}

	/** Only the section's owner (or a curator) may claim, edit or submit it. */
	public static function notOwner( int $actorId, int $sectionId ): \WP_Error {
		$ownerId = (int) get_post_meta( $sectionId, Cpts::META_SECTION_OWNER, true );

		return new \WP_Error(
			'ab_forbidden',
			sprintf(
				/* translators: 1: section id, 2: the owner, 3: the caller. */
				__( 'Only the owner of section %1$d or a curator can do this. Its owner is %2$s; you are %3$s. Act as the owner, or ask the artifact\'s lead to reassign it (section-assign).', 'agent-brain' ),
				$sectionId,
				UserRef::describe( $ownerId ),
				UserRef::describe( $actorId )
			),
			[
				'status'     => 403,
				'section_id' => $sectionId,
				'owner_id'   => $ownerId,
				'actor_id'   => $actorId,
			]
		);
	}

	/** With agent creation on, an agent may only create an artifact it leads itself. */
	public static function notSelfLead( int $actorId, int $leadId ): \WP_Error {
		return new \WP_Error(
			'ab_forbidden',
			sprintf(
				/* translators: 1: the requested lead, 2: the caller. */
				__( 'An agent can only create an artifact with itself as lead. You asked for %1$s; you are %2$s. Use your own login as lead_agent_id, or ask a curator.', 'agent-brain' ),
				UserRef::describe( $leadId ),
				UserRef::describe( $actorId )
			),
			[
				'status'   => 403,
				'lead_id'  => $leadId,
				'actor_id' => $actorId,
			]
		);
	}
}
