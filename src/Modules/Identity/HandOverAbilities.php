<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Identity;

use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use AgentBrain\Modules\Sharing\ShareAbilities;

/**
 * #850 (B18): hand-over-list and hand-over, the offboarding step from a
 * connector. Authority: curators and administrators (ab_manage and
 * ab_grant_scopes) on their own connection; an agent never sees either.
 */
final class HandOverAbilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$item = [
			'type'       => 'object',
			'properties' => [
				'kind'     => [ 'type' => 'string', 'enum' => HandOver::KINDS ],
				'id'       => [ 'type' => 'integer' ],
				'title'    => [ 'type' => 'string' ],
				'status'   => [ 'type' => 'string' ],
				'held_by'  => [ 'type' => 'integer' ],
				'scope_id' => [ 'type' => 'integer' ],
			],
		];

		AbilityNames::registerOnce(
			AbilityNames::HAND_OVER_LIST,
			[
				'label'               => __( 'List what someone owns', 'tomatio' ),
				'description'         => __( 'Before someone leaves the team (#850): everything a person or agent owns (documents and pages they lead, sections they own, open handoffs addressed to them, knowledge and procedures they wrote or proposed), the agents they issued as their own AIs (and what those own), their own TíoDay (which stays), what access hand-over would revoke, who may take over (new_owners), and scope_grants: the scopes hand-over with grant_scopes would give the new owner (per agents option, suspend or move; each scope with read and write as the current holder has them, never wider). Changes nothing. Authority: curators and administrators.', 'tomatio' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'user_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The person or agent who is leaving.' ],
					],
					'required'   => [ 'user_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'principal'    => [ 'type' => 'object' ],
						'items'        => [ 'type' => 'array', 'items' => $item ],
						'own_agents'   => [ 'type' => 'array' ],
						'kept'         => [ 'type' => 'array' ],
						'access'       => [ 'type' => 'object' ],
						'counts'       => [ 'type' => 'object' ],
						'new_owners'   => [ 'type' => 'array' ],
						'scope_grants' => [ 'type' => 'object' ],
					],
				],
				'permission_callback' => static fn (): bool => self::can(),
				'execute_callback'    => static function ( $input ) {
					$input    = is_array( $input ) ? $input : [];
					$leaverId = (int) ( $input['user_id'] ?? 0 );
					if ( $leaverId === get_current_user_id() ) {
						return new \WP_Error( 'ab_self', __( 'You cannot hand yourself over. Ask another curator.', 'tomatio' ), [ 'status' => 422 ] );
					}
					$inventory = HandOver::inventory( $leaverId );
					if ( $inventory instanceof \WP_Error ) {
						return $inventory;
					}
					$inventory['items']      = array_map( [ HandOver::class, 'publicItem' ], $inventory['items'] );
					$inventory['new_owners'] = HandOver::candidates( $leaverId );

					return $inventory;
				},
			]
		);

		AbilityNames::registerOnce(
			AbilityNames::HAND_OVER,
			[
				'label'               => __( 'Hand someone over', 'tomatio' ),
				'description'         => __( 'When someone leaves the team (#850), in one step: move everything they own (see hand-over-list) to new_owner_id, revoke their read and write scope grants, approval grant, application passwords, OAuth connections, unused registration keys and sign-in sessions, suspend them if they are an agent, and send the new owner a handoff listing what they inherited. Agents they issued as their own AIs are suspended (agents: "suspend", the default) or, when the new owner is a person, move to them (agents: "move"). With grant_scopes: true (off by default) the new owner also gets the scopes of what they inherit, as listed in hand-over-list scope_grants: show the person that list and ask before you set it. Every step is in the audit log. Their WordPress account stays. Authority: curators and administrators, never on themselves; only an administrator hands over an administrator. Confirm with the person first.', 'tomatio' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'user_id'      => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The person or agent who is leaving.' ],
						'new_owner_id' => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Who takes over: one of hand-over-list\'s new_owners.' ],
						'agents'       => [ 'type' => 'string', 'enum' => [ HandOver::AGENTS_SUSPEND, HandOver::AGENTS_MOVE ], 'description' => 'Their own agents: suspend (default) or move to the new owner (a person).' ],
						'grant_scopes' => [ 'type' => 'boolean', 'description' => 'Also grant the new owner the scopes of what they inherit (hand-over-list scope_grants). Off by default.' ],
					],
					'required'   => [ 'user_id', 'new_owner_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'handed_over'    => [ 'type' => 'boolean' ],
						'from'           => [ 'type' => 'object' ],
						'to'             => [ 'type' => 'object' ],
						'moved'          => [ 'type' => 'array', 'items' => $item ],
						'counts'         => [ 'type' => 'object' ],
						'agents'         => [ 'type' => 'array' ],
						'kept'           => [ 'type' => 'array' ],
						'revoked'        => [ 'type' => 'object' ],
						'handoff_id'     => [ 'type' => 'integer' ],
						'scopes_granted' => [ 'type' => 'array' ],
					],
					'required'   => [ 'handed_over' ],
				],
				'permission_callback' => static fn (): bool => self::can(),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter( static fn () => HandOver::run(
						get_current_user_id(),
						(int) ( $input['user_id'] ?? 0 ),
						(int) ( $input['new_owner_id'] ?? 0 ),
						is_string( $input['agents'] ?? null ) && $input['agents'] !== '' ? $input['agents'] : HandOver::AGENTS_SUSPEND,
						( $input['grant_scopes'] ?? false ) === true
					) );
				},
			]
		);
	}

	private static function can(): bool {
		$actorId = get_current_user_id();

		return $actorId > 0 && user_can( $actorId, Capabilities::CAP_USE_MCP ) && HandOver::canHandOver( $actorId );
	}
}
