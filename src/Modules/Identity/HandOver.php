<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\AgentRegistration\RegistrationStore;
use AgentBrain\Modules\Artifacts\Cpts as ArtifactCpts;
use AgentBrain\Modules\Memory\Cpts as MemoryCpts;
use AgentBrain\Modules\OAuth\Delegation;
use AgentBrain\Modules\OAuth\TokenStore;
use AgentBrain\Modules\Review\HandoffCpt;
use AgentBrain\Modules\Review\HandoffQueue;
use AgentBrain\Modules\Review\PostStatuses;
use AgentBrain\Modules\Review\ProposalService;
use WP_Error;
use WP_User;

/**
 * When someone leaves (#850, B18): one "hand over" step for a person or an
 * agent. It lists everything they own, moves it to a new owner, revokes
 * their access in the same step, and writes who handed what to whom into the
 * audit log.
 *
 * What moves (the owner fields):
 *  - artifact lead (`ab_lead_agent`, and `post_author` when it is the leaver);
 *  - section owner (`ab_section_owner`, and `post_author`);
 *  - open handoffs addressed to them (`ab_handoff_to`);
 *  - knowledge and procedures they wrote (`post_author`, the decision
 *    author) or proposed (`ab_proposing_agent`, the proposing agent).
 * A person's own TíoDay (an artifact whose `ab_home_owner` is the leaver)
 * stays where it is: it is their private day, not team work.
 *
 * Agents the leaver issued (`ab_agent_issued_by`: their own AIs) are
 * suspended, and what they own moves too. When the new owner is a person,
 * the caller may move those agents to them instead (option `agents: move`),
 * and the agents keep what they own.
 *
 * Revoked from the leaver: every read and write scope grant (one audited
 * revoke each, through Scopes), the approval grant, application passwords,
 * OAuth connections (their own and those they authorized for an agent),
 * unused registration keys they made, and open sign-in sessions. An agent
 * leaver is also suspended. A person's WordPress account and role stay:
 * removing the account is a separate step under Users.
 *
 * With grant_scopes (B222, off by default) the new owner also gets the
 * scopes of what they inherit: for each scope an inherited item lives in,
 * the lists (read, write) its holder had on it, never wider (scopeGrants()).
 * The screen and hand-over-list show that plan before it runs.
 *
 * The new owner gets a handoff listing what they inherited. Every moved
 * object keeps its history in `ab_handed_over` post meta, next to the audit
 * lines. Only someone who manages the brain and grants scopes may do it
 * (curators and administrators), never on themselves.
 */
final class HandOver {

	/** Post meta: one entry per hand over of this object (from, to, by, at, fields). */
	public const META_HISTORY = 'ab_handed_over';

	public const AGENTS_SUSPEND = 'suspend';
	public const AGENTS_MOVE    = 'move';

	public const KIND_ARTIFACT = 'artifact_lead';
	public const KIND_SECTION  = 'section_owner';
	public const KIND_HANDOFF  = 'handoff_to';
	public const KIND_DECISION = 'decision_author';
	public const KIND_PROPOSAL = 'proposing_agent';

	/** @var array<int, string> */
	public const KINDS = [ self::KIND_ARTIFACT, self::KIND_SECTION, self::KIND_HANDOFF, self::KIND_DECISION, self::KIND_PROPOSAL ];

	/** Cap per kind and holder, so a huge brain still answers in one request. */
	private const LIMIT = 500;

	/**
	 * Whether $actorId may hand someone over: manages the brain and grants
	 * scopes (curators and administrators), and is a person.
	 */
	public static function canHandOver( int $actorId ): bool {
		if ( $actorId <= 0 || ! user_can( $actorId, Capabilities::CAP_MANAGE ) || ! user_can( $actorId, Capabilities::CAP_GRANT_SCOPES ) ) {
			return false;
		}

		$user = get_userdata( $actorId );

		return $user instanceof WP_User && ! Delegation::isPureAgent( $user );
	}

	/**
	 * Everything $leaverId owns and holds, without changing anything.
	 *
	 * scope_grants is what grant_scopes would give the new owner, per agents
	 * option: suspend (everything moves) and move (only what the leaver holds).
	 *
	 * @return array{principal:array<string, mixed>, items:array<int, array<string, mixed>>, own_agents:array<int, array<string, mixed>>, kept:array<int, array<string, mixed>>, access:array<string, mixed>, counts:array<string, int>, scope_grants:array<string, array<int, array<string, mixed>>>}|WP_Error
	 */
	public static function inventory( int $leaverId ) {
		$leaver = get_userdata( $leaverId );
		if ( ! $leaver instanceof WP_User ) {
			return new WP_Error( 'ab_not_found', __( 'No such person or agent.', 'tomatio' ), [ 'status' => 404 ] );
		}

		$ownAgents = self::ownAgents( $leaverId );
		$items     = self::itemsHeldBy( $leaverId, $leaverId );
		foreach ( $ownAgents as $agent ) {
			$items = array_merge( $items, self::itemsHeldBy( (int) $agent['id'], $leaverId ) );
		}

		$counts = array_fill_keys( self::KINDS, 0 );
		foreach ( $items as $item ) {
			++$counts[ (string) $item['kind'] ];
		}

		return [
			'principal'    => self::principal( $leaver ),
			'items'        => $items,
			'own_agents'   => $ownAgents,
			'kept'         => self::personalPages( $leaverId ),
			'access'       => self::access( $leaverId ),
			'counts'       => $counts,
			'scope_grants' => [
				self::AGENTS_SUSPEND => self::scopeGrants( $items ),
				self::AGENTS_MOVE    => self::scopeGrants( $items, $leaverId ),
			],
		];
	}

	/**
	 * The scope grants a new owner gets with grant_scopes (B222): for each
	 * scope an item lives in, the lists its holder has on it now. Never
	 * wider than the holder's own access, and an item without a scope adds
	 * nothing. With $onlyHolder > 0, only the items that holder holds.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array{scope_id:int, name:string, read:bool, write:bool}>
	 */
	public static function scopeGrants( array $items, int $onlyHolder = 0 ): array {
		$scopes = new Scopes();
		$held   = [];
		$out    = [];
		foreach ( $items as $item ) {
			$scopeId = (int) ( $item['scope_id'] ?? 0 );
			$holder  = (int) ( $item['holder'] ?? 0 );
			if ( $scopeId <= 0 || $holder <= 0 || ( $onlyHolder > 0 && $holder !== $onlyHolder ) ) {
				continue;
			}
			if ( ! isset( $held[ $holder ] ) ) {
				$held[ $holder ] = [ 'read' => $scopes->readScopeIds( $holder ), 'write' => $scopes->writeScopeIds( $holder ) ];
			}
			$read  = in_array( $scopeId, $held[ $holder ]['read'], true );
			$write = in_array( $scopeId, $held[ $holder ]['write'], true );
			if ( ! $read && ! $write ) {
				continue;
			}
			if ( ! isset( $out[ $scopeId ] ) ) {
				$out[ $scopeId ] = [ 'scope_id' => $scopeId, 'name' => self::scopeName( $scopeId ), 'read' => false, 'write' => false ];
			}
			$out[ $scopeId ]['read']  = $out[ $scopeId ]['read'] || $read;
			$out[ $scopeId ]['write'] = $out[ $scopeId ]['write'] || $write;
		}
		ksort( $out );

		return array_values( $out );
	}

	private static function scopeName( int $scopeId ): string {
		$term = get_term( $scopeId, Scopes::TAXONOMY );

		return $term instanceof \WP_Term ? (string) $term->name : sprintf( '#%d', $scopeId );
	}

	/**
	 * Who may take over from $leaverId: anyone else who may use the brain,
	 * except an agent that is not active and the leaver's own agents.
	 *
	 * @return array<int, array{id:int, name:string, kind:string}>
	 */
	public static function candidates( int $leaverId ): array {
		$own = array_map( static fn ( array $agent ): int => (int) $agent['id'], self::ownAgents( $leaverId ) );
		$out = [];
		foreach ( get_users( [ 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 500 ] ) as $user ) {
			if ( ! $user instanceof WP_User || (int) $user->ID === $leaverId || in_array( (int) $user->ID, $own, true ) ) {
				continue;
			}
			if ( self::refuseNewOwner( (int) $user->ID ) !== null ) {
				continue;
			}
			$principal = self::principal( $user );
			$out[]     = [ 'id' => (int) $user->ID, 'name' => (string) $principal['name'], 'kind' => (string) $principal['kind'] ];
		}

		return $out;
	}

	/**
	 * Hand $leaverId over to $newOwnerId: move, revoke, audit, tell the new
	 * owner. $agents is AGENTS_SUSPEND (default) or AGENTS_MOVE. With
	 * $grantScopes (off by default) the new owner also gets the scopes of
	 * what moved to them (scopeGrants()).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function run( int $actorId, int $leaverId, int $newOwnerId, string $agents = self::AGENTS_SUSPEND, bool $grantScopes = false ) {
		$refused = self::refuse( $actorId, $leaverId, $newOwnerId, $agents );
		if ( $refused instanceof WP_Error ) {
			self::audit( $actorId, 'hand_over.denied', 'denied', $leaverId, [ 'to' => $newOwnerId, 'code' => $refused->get_error_code() ] );

			return $refused;
		}

		$inventory = self::inventory( $leaverId );
		if ( $inventory instanceof WP_Error ) {
			return $inventory;
		}

		$moveAgents = $agents === self::AGENTS_MOVE;
		$at         = gmdate( 'Y-m-d\TH:i:s\Z' );

		// 1. What they own. With agents moving, what those agents hold stays with them.
		$moved = [];
		foreach ( $inventory['items'] as $item ) {
			if ( $moveAgents && (int) $item['holder'] !== $leaverId ) {
				continue;
			}
			if ( self::moveItem( $actorId, $item, $newOwnerId, $at ) ) {
				$moved[] = $item;
			}
		}

		// 2. Their own agents: suspended, or moved to the new owner.
		$agentsDone = [];
		foreach ( $inventory['own_agents'] as $agent ) {
			$agentId = (int) $agent['id'];
			if ( $moveAgents ) {
				update_user_meta( $agentId, AgentMeta::ISSUED_BY, $newOwnerId );
				if ( (int) get_user_meta( $agentId, MasterRole::META_BY, true ) === $leaverId ) {
					update_user_meta( $agentId, MasterRole::META_BY, $newOwnerId );
				}
				self::audit( $actorId, 'hand_over.agent_moved', 'ok', $agentId, [ 'from' => $leaverId, 'to' => $newOwnerId ] );
				$agentsDone[] = [ 'id' => $agentId, 'name' => (string) $agent['name'], 'done' => 'moved' ];
				continue;
			}
			$status = AgentIssuer::statusOf( $agentId ) ?? AgentMeta::STATUS_ACTIVE;
			if ( AgentMeta::canTransition( $status, AgentMeta::STATUS_SUSPENDED ) ) {
				( new AgentIssuer() )->setStatus( $agentId, AgentMeta::STATUS_SUSPENDED, $actorId, 'hand over' );
			}
			self::audit( $actorId, 'hand_over.agent_suspended', 'ok', $agentId, [ 'from' => $leaverId, 'was' => $status ] );
			$agentsDone[] = [ 'id' => $agentId, 'name' => (string) $agent['name'], 'done' => 'suspended' ];
		}

		// 3. If asked, the scopes of what moved, read from the holders before step 4 revokes them.
		$granted = $grantScopes ? self::grantScopes( $actorId, $leaverId, $newOwnerId, self::scopeGrants( $moved ) ) : [];

		// 4. Their access, in the same step.
		$revoked = self::revokeAccess( $actorId, $leaverId );

		// 5. The new owner hears what they inherited.
		$handoffId = self::inheritanceHandoff( $actorId, $leaverId, $newOwnerId, $moved, $agentsDone, $granted );

		$counts = array_fill_keys( self::KINDS, 0 );
		foreach ( $moved as $item ) {
			++$counts[ (string) $item['kind'] ];
		}

		self::audit( $actorId, 'hand_over.completed', 'ok', $leaverId, [
			'from'       => $leaverId,
			'to'         => $newOwnerId,
			'moved'      => $counts,
			'agents'     => $moveAgents ? self::AGENTS_MOVE : self::AGENTS_SUSPEND,
			'agent_ids'  => array_map( static fn ( array $a ): int => (int) $a['id'], $agentsDone ),
			'revoked'    => $revoked,
			'handoff_id' => $handoffId,
			'scopes'     => $grantScopes ? count( $granted ) : null,
		] );

		return [
			'handed_over'    => true,
			'from'           => $inventory['principal'],
			'to'             => self::principal( get_userdata( $newOwnerId ) ),
			'moved'          => array_map( [ self::class, 'publicItem' ], $moved ),
			'counts'         => $counts,
			'agents'         => $agentsDone,
			'kept'           => $inventory['kept'],
			'revoked'        => $revoked,
			'handoff_id'     => $handoffId,
			'scopes_granted' => $granted,
		];
	}

	/**
	 * Grant $newOwnerId each planned scope list they do not hold yet, one
	 * audited grant each (through Scopes, as the curator). Returns what was
	 * newly granted.
	 *
	 * @param array<int, array{scope_id:int, name:string, read:bool, write:bool}> $plan
	 * @return array<int, array{scope_id:int, name:string, read:bool, write:bool}>
	 */
	private static function grantScopes( int $actorId, int $leaverId, int $newOwnerId, array $plan ): array {
		$scopes = new Scopes();
		$out    = [];
		foreach ( $plan as $grant ) {
			$scopeId = (int) $grant['scope_id'];
			$done    = [ 'scope_id' => $scopeId, 'name' => (string) $grant['name'], 'read' => false, 'write' => false ];
			foreach ( [ 'read', 'write' ] as $list ) {
				if ( ! $grant[ $list ] ) {
					continue;
				}
				$has = $list === 'read' ? $scopes->canRead( $newOwnerId, $scopeId ) : $scopes->canWrite( $newOwnerId, $scopeId );
				if ( ! $has && $scopes->grant( $actorId, $newOwnerId, $list, $scopeId ) ) {
					$done[ $list ] = true;
				}
			}
			if ( $done['read'] || $done['write'] ) {
				$out[] = $done;
			}
		}

		self::audit( $actorId, 'hand_over.scopes_granted', 'ok', $newOwnerId, [
			'from'   => $leaverId,
			'to'     => $newOwnerId,
			'scopes' => array_map( static fn ( array $g ): array => [ 'scope_id' => $g['scope_id'], 'read' => $g['read'], 'write' => $g['write'] ], $out ),
		] );

		return $out;
	}

	/**
	 * Why this hand over may not run, or null when it may.
	 */
	public static function refuse( int $actorId, int $leaverId, int $newOwnerId, string $agents = self::AGENTS_SUSPEND ): ?WP_Error {
		if ( ! self::canHandOver( $actorId ) ) {
			return new WP_Error( 'ab_forbidden', __( 'Only curators and administrators can hand someone over.', 'tomatio' ), [ 'status' => 403 ] );
		}

		$leaver = get_userdata( $leaverId );
		if ( ! $leaver instanceof WP_User ) {
			return new WP_Error( 'ab_not_found', __( 'No such person or agent.', 'tomatio' ), [ 'status' => 404 ] );
		}
		if ( $leaverId === $actorId ) {
			return new WP_Error( 'ab_self', __( 'You cannot hand yourself over. Ask another curator.', 'tomatio' ), [ 'status' => 422 ] );
		}
		if ( user_can( $leaverId, 'manage_options' ) && ! user_can( $actorId, 'manage_options' ) ) {
			return new WP_Error( 'ab_forbidden', __( 'Only an administrator can hand over an administrator.', 'tomatio' ), [ 'status' => 403 ] );
		}
		if ( ! in_array( $agents, [ self::AGENTS_SUSPEND, self::AGENTS_MOVE ], true ) ) {
			return new WP_Error( 'ab_invalid_agents', __( 'agents must be "suspend" or "move".', 'tomatio' ), [ 'status' => 422 ] );
		}
		if ( $newOwnerId === $leaverId ) {
			return new WP_Error( 'ab_invalid_owner', __( 'The new owner must be someone else.', 'tomatio' ), [ 'status' => 422 ] );
		}
		foreach ( self::ownAgents( $leaverId ) as $agent ) {
			if ( (int) $agent['id'] === $newOwnerId ) {
				return new WP_Error( 'ab_invalid_owner', __( 'The new owner cannot be one of the leaver\'s own agents.', 'tomatio' ), [ 'status' => 422 ] );
			}
		}

		$why = self::refuseNewOwner( $newOwnerId );
		if ( $why !== null ) {
			return new WP_Error( 'ab_invalid_owner', $why, [ 'status' => 422 ] );
		}

		if ( $agents === self::AGENTS_MOVE ) {
			$owner = get_userdata( $newOwnerId );
			if ( ! $owner instanceof WP_User || Delegation::isPureAgent( $owner ) ) {
				return new WP_Error( 'ab_invalid_agents', __( 'Agents can only move to a person. Choose a person as the new owner, or suspend the agents.', 'tomatio' ), [ 'status' => 422 ] );
			}
		}

		return null;
	}

	/**
	 * Why $userId cannot take things over, or null when they can.
	 */
	private static function refuseNewOwner( int $userId ): ?string {
		$user = $userId > 0 ? get_userdata( $userId ) : false;
		if ( ! $user instanceof WP_User ) {
			return __( 'Choose a new owner.', 'tomatio' );
		}
		if ( ! user_can( $userId, Capabilities::CAP_USE_MCP ) ) {
			return __( 'The new owner must be an agent, curator or administrator.', 'tomatio' );
		}
		if ( in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			$status = AgentIssuer::statusOf( $userId ) ?? AgentMeta::STATUS_ACTIVE;
			if ( $status !== AgentMeta::STATUS_ACTIVE ) {
				return __( 'The new owner must be an active agent.', 'tomatio' );
			}
		}

		return null;
	}

	/**
	 * @param mixed $user A WP_User, or whatever get_userdata() returned.
	 * @return array{id:int, name:string, kind:string, login:string, status:string}
	 */
	private static function principal( $user ): array {
		if ( ! $user instanceof WP_User ) {
			return [ 'id' => 0, 'name' => '', 'kind' => 'person', 'login' => '', 'status' => '' ];
		}

		$isAgent = in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true );

		return [
			'id'     => (int) $user->ID,
			'name'   => (string) $user->display_name !== '' ? (string) $user->display_name : (string) $user->user_login,
			'kind'   => $isAgent ? 'agent' : 'person',
			'login'  => (string) $user->user_login,
			'status' => $isAgent ? ( AgentIssuer::statusOf( (int) $user->ID ) ?? AgentMeta::STATUS_ACTIVE ) : '',
		];
	}

	/**
	 * Agents $leaverId issued (their own AIs), not yet revoked.
	 *
	 * @return array<int, array{id:int, name:string, status:string}>
	 */
	public static function ownAgents( int $leaverId ): array {
		if ( $leaverId <= 0 ) {
			return [];
		}

		$users = get_users( [
			'role'       => Capabilities::ROLE_AGENT,
			'meta_key'   => AgentMeta::ISSUED_BY, // phpcs:ignore WordPress.DB.SlowDBQuery -- once per hand over screen.
			'meta_value' => (string) $leaverId, // phpcs:ignore WordPress.DB.SlowDBQuery
			'number'     => self::LIMIT,
		] );

		$out = [];
		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User || (int) $user->ID === $leaverId ) {
				continue;
			}
			$status = AgentIssuer::statusOf( (int) $user->ID ) ?? AgentMeta::STATUS_ACTIVE;
			if ( $status === AgentMeta::STATUS_REVOKED ) {
				continue;
			}
			$out[] = [ 'id' => (int) $user->ID, 'name' => self::principal( $user )['name'], 'status' => $status ];
		}

		return $out;
	}

	/**
	 * What $holderId holds in each owner field. $leaverId tells whose
	 * personal pages stay.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function itemsHeldBy( int $holderId, int $leaverId ): array {
		$items    = [];
		$kept     = array_map( static fn ( array $page ): int => (int) $page['id'], self::personalPages( $leaverId ) );
		$statuses = self::liveStatuses();

		// Artifact lead.
		foreach ( self::postsWithMeta( ArtifactCpts::ARTIFACT_POST_TYPE, ArtifactCpts::META_LEAD_AGENT, $holderId, $statuses ) as $post ) {
			if ( in_array( (int) $post->ID, $kept, true ) ) {
				continue;
			}
			$items[] = self::item( self::KIND_ARTIFACT, $post, $holderId, [ ArtifactCpts::META_LEAD_AGENT ], (int) get_post_meta( (int) $post->ID, ArtifactCpts::META_SCOPE_ID, true ) );
		}

		// Section owner.
		foreach ( self::postsWithMeta( ArtifactCpts::SECTION_POST_TYPE, ArtifactCpts::META_SECTION_OWNER, $holderId, $statuses ) as $post ) {
			if ( in_array( (int) $post->post_parent, $kept, true ) ) {
				continue;
			}
			$items[] = self::item( self::KIND_SECTION, $post, $holderId, [ ArtifactCpts::META_SECTION_OWNER ], (int) get_post_meta( (int) $post->post_parent, ArtifactCpts::META_SCOPE_ID, true ) );
		}

		// Open handoffs addressed to them.
		foreach ( self::postsWithMeta( HandoffCpt::POST_TYPE, HandoffQueue::META_TO, $holderId, [ 'private' ] ) as $post ) {
			if ( (string) get_post_meta( (int) $post->ID, HandoffQueue::META_STATUS, true ) !== '' ) {
				continue;
			}
			$items[] = self::item( self::KIND_HANDOFF, $post, $holderId, [ HandoffQueue::META_TO ], 0 );
		}

		// Knowledge and procedures: the proposing agent, else the author.
		$seen = [];
		foreach ( self::postsWithMeta( MemoryCpts::postTypes(), ProposalService::META_PROPOSING_AGENT, $holderId, $statuses ) as $post ) {
			$seen[ (int) $post->ID ] = true;
			$fields                  = [ ProposalService::META_PROPOSING_AGENT ];
			$kind                    = $post->post_status === PostStatuses::PROPOSED ? self::KIND_PROPOSAL : self::KIND_DECISION;
			$items[]                 = self::item( $kind, $post, $holderId, $fields, self::termScope( (int) $post->ID ) );
		}
		$authored = get_posts( [
			'post_type'      => MemoryCpts::postTypes(),
			'post_status'    => $statuses,
			'author'         => $holderId,
			'posts_per_page' => self::LIMIT,
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] );
		foreach ( $authored as $post ) {
			if ( ! $post instanceof \WP_Post || isset( $seen[ (int) $post->ID ] ) ) {
				continue;
			}
			$kind    = $post->post_status === PostStatuses::PROPOSED ? self::KIND_PROPOSAL : self::KIND_DECISION;
			$items[] = self::item( $kind, $post, $holderId, [], self::termScope( (int) $post->ID ) );
		}

		return $items;
	}

	/**
	 * @param array<int, string> $metaFields
	 * @return array<string, mixed>
	 */
	private static function item( string $kind, \WP_Post $post, int $holderId, array $metaFields, int $scopeId ): array {
		return [
			'kind'        => $kind,
			'id'          => (int) $post->ID,
			'title'       => get_the_title( $post ) !== '' ? (string) get_the_title( $post ) : sprintf( '#%d', (int) $post->ID ),
			'post_type'   => (string) $post->post_type,
			'status'      => (string) $post->post_status,
			'holder'      => $holderId,
			'meta_fields' => $metaFields,
			'author'      => (int) $post->post_author === $holderId,
			'scope_id'    => $scopeId,
		];
	}

	/**
	 * An item as abilities and screens show it (no internal field names).
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	public static function publicItem( array $item ): array {
		return [
			'kind'     => (string) $item['kind'],
			'id'       => (int) $item['id'],
			'title'    => (string) $item['title'],
			'status'   => (string) $item['status'],
			'held_by'  => (int) $item['holder'],
			'scope_id' => (int) $item['scope_id'],
		];
	}

	/**
	 * Move one item to $newOwnerId, keep its history and audit it.
	 *
	 * @param array<string, mixed> $item
	 */
	private static function moveItem( int $actorId, array $item, int $newOwnerId, string $at ): bool {
		global $wpdb;

		$postId = (int) $item['id'];
		$from   = (int) $item['holder'];
		$fields = [];

		foreach ( (array) $item['meta_fields'] as $key ) {
			if ( (int) get_post_meta( $postId, (string) $key, true ) === $from ) {
				update_post_meta( $postId, (string) $key, $newOwnerId );
				$fields[] = (string) $key;
			}
		}

		$post = get_post( $postId );
		if ( $post instanceof \WP_Post && (int) $post->post_author === $from && in_array( $item['kind'], [ self::KIND_ARTIFACT, self::KIND_SECTION, self::KIND_DECISION, self::KIND_PROPOSAL ], true ) ) {
			// Straight to the row: wp_update_post() would run the content
			// through the save filters as the curator and could change it.
			$wpdb->update( $wpdb->posts, [ 'post_author' => $newOwnerId ], [ 'ID' => $postId ], [ '%d' ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $postId );
			$fields[] = 'post_author';
		}

		if ( $fields === [] ) {
			return false;
		}

		add_post_meta( $postId, self::META_HISTORY, [ 'from' => $from, 'to' => $newOwnerId, 'by' => $actorId, 'at' => $at, 'fields' => $fields ] );

		self::audit( $actorId, 'hand_over.moved', 'ok', $postId, [
			'kind'   => (string) $item['kind'],
			'from'   => $from,
			'to'     => $newOwnerId,
			'fields' => $fields,
			'title'  => (string) $item['title'],
		], 'post' );

		return true;
	}

	/**
	 * Revoke every grant and credential $leaverId holds. Counts per kind.
	 *
	 * @return array{read_scopes:int, write_scopes:int, approval:bool, app_passwords:int, connections:int, registration_keys:int, sessions:bool, suspended:bool}
	 */
	private static function revokeAccess( int $actorId, int $leaverId ): array {
		$scopes = new Scopes();
		$out    = [ 'read_scopes' => 0, 'write_scopes' => 0, 'approval' => false, 'app_passwords' => 0, 'connections' => 0, 'registration_keys' => 0, 'sessions' => false, 'suspended' => false ];

		foreach ( $scopes->readScopeIds( $leaverId ) as $scopeId ) {
			$out['read_scopes'] += $scopes->revoke( $actorId, $leaverId, 'read', $scopeId ) ? 1 : 0;
		}
		foreach ( $scopes->writeScopeIds( $leaverId ) as $scopeId ) {
			$out['write_scopes'] += $scopes->revoke( $actorId, $leaverId, 'write', $scopeId ) ? 1 : 0;
		}
		// A scope whose term is gone cannot be revoked one by one: drop what is left.
		delete_user_meta( $leaverId, Scopes::META_READ_SCOPES );
		delete_user_meta( $leaverId, Scopes::META_WRITE_SCOPES );

		$grants = new ApprovalGrants();
		if ( $grants->hasGrant( $leaverId ) ) {
			$out['approval'] = $grants->revoke( $actorId, $leaverId );
		}

		if ( class_exists( '\WP_Application_Passwords' ) ) {
			$out['app_passwords'] = count( \WP_Application_Passwords::get_user_application_passwords( $leaverId ) );
			\WP_Application_Passwords::delete_all_application_passwords( $leaverId );
		}

		foreach ( TokenStore::grantsForUser( $leaverId ) as $grant ) {
			if ( TokenStore::revokeGrantForUser( $leaverId, (string) $grant['family_id'] ) ) {
				++$out['connections'];
			}
		}
		foreach ( self::familiesAuthorizedBy( $leaverId ) as $familyId ) {
			TokenStore::revokeFamily( $familyId );
			++$out['connections'];
		}

		foreach ( self::pendingRegistrationKeys( $leaverId ) as $keyId ) {
			if ( RegistrationStore::revoke( $keyId ) ) {
				++$out['registration_keys'];
			}
		}

		if ( class_exists( '\WP_Session_Tokens' ) ) {
			\WP_Session_Tokens::get_instance( $leaverId )->destroy_all();
			$out['sessions'] = true;
		}

		$leaver = get_userdata( $leaverId );
		if ( $leaver instanceof WP_User && in_array( Capabilities::ROLE_AGENT, (array) $leaver->roles, true ) ) {
			$status = AgentIssuer::statusOf( $leaverId ) ?? AgentMeta::STATUS_ACTIVE;
			if ( AgentMeta::canTransition( $status, AgentMeta::STATUS_SUSPENDED ) ) {
				$out['suspended'] = ( new AgentIssuer() )->setStatus( $leaverId, AgentMeta::STATUS_SUSPENDED, $actorId, 'hand over' ) === true;
			}
		}

		self::audit( $actorId, 'hand_over.revoked', 'ok', $leaverId, $out );

		return $out;
	}

	/**
	 * Open connections $personId authorized for one of their agents.
	 *
	 * @return array<int, string>
	 */
	private static function familiesAuthorizedBy( int $personId ): array {
		global $wpdb;

		$table = TokenStore::tokensTable();
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Db::prepare() is the enforcement point.
		$rows = $wpdb->get_col( Db::prepare(
			"SELECT DISTINCT family_id FROM {$table} WHERE authorized_by = %d AND user_id <> %d AND revoked = 0",
			[ $personId, $personId ]
		) );
		// phpcs:enable

		return array_values( array_map( 'strval', (array) $rows ) );
	}

	/**
	 * Registration keys $userId made that can still register an agent.
	 *
	 * @return array<int, int>
	 */
	private static function pendingRegistrationKeys( int $userId ): array {
		$table = RegistrationStore::table();
		$rows  = Db::getResults( "SELECT id FROM {$table} WHERE issued_by = %d AND status = %s", [ $userId, RegistrationStore::STATUS_PENDING ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table().

		return array_values( array_map( static fn ( array $row ): int => (int) $row['id'], $rows ) );
	}

	/**
	 * What the leaver can do now, for the screen.
	 *
	 * @return array{read_scopes:array<int, int>, write_scopes:array<int, int>, approval:bool, app_passwords:int, connections:int, registration_keys:int}
	 */
	private static function access( int $leaverId ): array {
		$scopes = new Scopes();

		return [
			'read_scopes'       => $scopes->readScopeIds( $leaverId ),
			'write_scopes'      => $scopes->writeScopeIds( $leaverId ),
			'approval'          => ( new ApprovalGrants() )->hasGrant( $leaverId ),
			'app_passwords'     => class_exists( '\WP_Application_Passwords' ) ? count( \WP_Application_Passwords::get_user_application_passwords( $leaverId ) ) : 0,
			'connections'       => count( TokenStore::grantsForUser( $leaverId ) ) + count( self::familiesAuthorizedBy( $leaverId ) ),
			'registration_keys' => count( self::pendingRegistrationKeys( $leaverId ) ),
		];
	}

	/**
	 * The leaver's own TíoDay and other pages pinned to them: these stay.
	 *
	 * @return array<int, array{id:int, title:string}>
	 */
	private static function personalPages( int $leaverId ): array {
		$out = [];
		foreach ( self::postsWithMeta( ArtifactCpts::ARTIFACT_POST_TYPE, HomeWorkspace::META_OWNER, $leaverId, self::liveStatuses() ) as $post ) {
			$out[] = [ 'id' => (int) $post->ID, 'title' => (string) get_the_title( $post ) ];
		}

		return $out;
	}

	/**
	 * @param string|array<int, string> $postType
	 * @param array<int, string>        $statuses
	 * @return array<int, \WP_Post>
	 */
	private static function postsWithMeta( $postType, string $key, int $value, array $statuses ): array {
		$posts = get_posts( [
			'post_type'        => $postType,
			'post_status'      => $statuses,
			'posts_per_page'   => self::LIMIT,
			'no_found_rows'    => true,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'meta_query'       => [ [ 'key' => $key, 'value' => (string) $value ] ], // phpcs:ignore WordPress.DB.SlowDBQuery -- once per hand over.
		] );

		return array_values( array_filter( $posts, static fn ( $post ): bool => $post instanceof \WP_Post ) );
	}

	/** @return array<int, string> every post status except trash and the internal ones. */
	private static function liveStatuses(): array {
		return array_values( array_diff( array_keys( get_post_stati() ), [ 'trash', 'auto-draft', 'inherit' ] ) );
	}

	private static function termScope( int $postId ): int {
		$terms = wp_get_object_terms( $postId, Scopes::TAXONOMY, [ 'fields' => 'ids' ] );

		return is_array( $terms ) && $terms !== [] ? (int) $terms[0] : 0;
	}

	/**
	 * A private handoff to the new owner listing what they inherited.
	 *
	 * @param array<int, array<string, mixed>> $moved
	 * @param array<int, array<string, mixed>> $agents
	 * @param array<int, array<string, mixed>> $granted
	 */
	private static function inheritanceHandoff( int $actorId, int $leaverId, int $newOwnerId, array $moved, array $agents, array $granted = [] ): int {
		if ( $moved === [] && $agents === [] ) {
			return 0;
		}

		$leaverName = self::principal( get_userdata( $leaverId ) )['name'];
		$labels     = self::kindLabels();
		$lines      = [];
		foreach ( $moved as $item ) {
			$lines[] = sprintf( '- %s: %s (#%d)', $labels[ (string) $item['kind'] ] ?? (string) $item['kind'], (string) $item['title'], (int) $item['id'] );
		}
		foreach ( $agents as $agent ) {
			$lines[] = $agent['done'] === 'moved'
				/* translators: %s: agent name */
				? sprintf( __( '- Agent %s now works for you', 'tomatio' ), (string) $agent['name'] )
				/* translators: %s: agent name */
				: sprintf( __( '- Agent %s is suspended; what it owned is listed above', 'tomatio' ), (string) $agent['name'] );
		}
		foreach ( $granted as $grant ) {
			$lines[] = sprintf(
				/* translators: 1: scope name, 2: "read", "write" or "read and write" */
				__( '- Scope %1$s: you can now %2$s it', 'tomatio' ),
				(string) $grant['name'],
				self::listsLabel( (bool) $grant['read'], (bool) $grant['write'] )
			);
		}

		/* translators: %s: name of the person or agent who left */
		$title = sprintf( __( 'Handed over to you from %s', 'tomatio' ), $leaverName );
		/* translators: %s: name of the person or agent who left */
		$note = sprintf( __( '%s left the team. You are now the owner of:', 'tomatio' ), $leaverName ) . "\n\n" . implode( "\n", $lines );

		$postId = wp_insert_post( [
			'post_type'    => HandoffCpt::POST_TYPE,
			'post_status'  => 'private',
			'post_author'  => $actorId,
			'post_title'   => $title,
			'post_content' => $note,
		], true );
		if ( $postId instanceof WP_Error ) {
			return 0;
		}

		update_post_meta( (int) $postId, HandoffQueue::META_TO, $newOwnerId );
		self::audit( $actorId, 'hand_over.handoff', 'ok', (int) $postId, [ 'from' => $leaverId, 'to' => $newOwnerId, 'items' => count( $moved ) ], HandoffCpt::POST_TYPE );

		return (int) $postId;
	}

	/** "read", "write" or "read and write", for a scope grant. */
	public static function listsLabel( bool $read, bool $write ): string {
		if ( $read && $write ) {
			return __( 'read and write', 'tomatio' );
		}

		return $write ? __( 'write', 'tomatio' ) : __( 'read', 'tomatio' );
	}

	/** @return array<string, string> */
	public static function kindLabels(): array {
		return [
			self::KIND_ARTIFACT => __( 'Leads', 'tomatio' ),
			self::KIND_SECTION  => __( 'Owns section', 'tomatio' ),
			self::KIND_HANDOFF  => __( 'Open handoff', 'tomatio' ),
			self::KIND_DECISION => __( 'Wrote', 'tomatio' ),
			self::KIND_PROPOSAL => __( 'Proposed', 'tomatio' ),
		];
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function audit( int $actorId, string $event, string $status, int $objectId, array $meta, string $objectType = 'user' ): void {
		\AgentBrain\Core\Hooks::action( 'audit', [
			'actor_id'    => $actorId,
			'event'       => $event,
			'status'      => $status,
			'object_type' => $objectType,
			'object_id'   => (string) $objectId,
			'meta'        => $meta,
		] );
	}
}
