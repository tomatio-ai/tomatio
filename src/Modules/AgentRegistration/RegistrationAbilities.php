<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentRegistration;

use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;

/**
 * agent-register and agent-whoami (RFC section 6.3). The only abilities a
 * registration key can reach; agent-whoami is also open to every signed-in agent.
 */
final class RegistrationAbilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::registerOnce( AbilityNames::AGENT_REGISTER, [
			'label'               => __( 'Register this agent', 'agent-brain' ),
			'description'         => __( 'Register yourself with the one-time registration key you were given. Choose a short lowercase agent_name that describes your job (3 to 32 characters, a-z, 0-9 and "-"), unless the person already fixed one. Returns your agent ID, a permanent credential (shown once) and the Authorization header to reconnect with, in its own secret field (authorization_header). Never print, echo or log the credential, the header or the connect command, including their base64 form: store the header in a secret store or a private file and use connect_from_file. The registration key stops working afterwards.', 'agent-brain' ),
			'category'            => McpModule::CATEGORY,
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'agent_name' => [ 'type' => 'string', 'maxLength' => 64 ],
				],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'fqdn'       => [ 'type' => 'string' ],
					'login'      => [ 'type' => 'string' ],
					'class'      => [ 'type' => 'string' ],
					'scopes'     => [ 'type' => 'object' ],
					'credential' => [ 'type' => 'object' ],
					'connect'    => [ 'type' => 'string', 'description' => 'Secret: contains the Basic header. Do not print.' ],
					'authorization_header' => [
						'type'        => 'object',
						'description' => 'Secret. The header to reconnect with. Never print, including the base64 form.',
						'properties'  => [
							'name'   => [ 'type' => 'string' ],
							'value'  => [ 'type' => 'string' ],
							'secret' => [ 'type' => 'boolean' ],
						],
					],
					'connect_from_file' => [ 'type' => 'string', 'description' => 'Reconnect command that reads the header from ~/.config/agent-brain/authorization; holds no secret.' ],
					'secret_fields'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					'handling'          => [ 'type' => 'string' ],
					'note'       => [ 'type' => 'string' ],
				],
				'required'   => [ 'fqdn', 'login', 'class', 'scopes', 'credential', 'connect' ],
			],
			'permission_callback' => static fn (): bool => KeyAuth::active(),
			'execute_callback'    => static function ( $input ) {
				$record = KeyAuth::record();
				if ( $record === null ) {
					return new \WP_Error( 'ab_registration_unavailable', 'A valid registration key is required.', [ 'status' => 401 ] );
				}

				$name = is_array( $input ) && isset( $input['agent_name'] ) && is_string( $input['agent_name'] ) ? $input['agent_name'] : null;
				$hash = self::presentedHash();

				return Registrar::register( $record, $hash, $name );
			},
		] );

		self::registerOnce( AbilityNames::AGENT_WHOAMI, [
			'label'               => __( 'Who am I', 'agent-brain' ),
			'description'         => __( 'Show who you are: your agent ID, login, user_id (the number tools such as artifact-create take as lead_agent_id or owner_id; they also accept your login), class, status, scopes (id, name, read, write; the id is what memory-write takes as scope_id), and who issued you and when. With a registration key it shows what the key allows (class, scopes, fixed name) and when it expires.', 'agent-brain' ),
			'category'            => McpModule::CATEGORY,
			'input_schema'        => [ 'type' => 'object', 'properties' => new \stdClass() ],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'registered' => [ 'type' => 'boolean' ],
					'fqdn'       => [ 'type' => 'string' ],
					'login'      => [ 'type' => 'string' ],
					'user_id'    => [ 'type' => 'integer' ],
					'class'      => [ 'type' => 'string' ],
					'status'     => [ 'type' => 'string' ],
					'scopes'     => [
						'type'        => 'array',
						'description' => 'Each scope you can use: id (what memory-write takes as scope_id), name, read, write.',
						'items'       => [ 'type' => 'object' ],
					],
					'issued_by'  => [ 'type' => 'string' ],
					'issued_at'  => [ 'type' => 'string' ],
				],
				'required'   => [ 'registered' ],
			],
			// Harmless and useful: registration-key holders and every signed-in agent.
			'permission_callback' => static fn (): bool => KeyAuth::active() || self::currentUserIsAgent(),
			'execute_callback'    => static function () {
				return self::whoami();
			},
		] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function whoami(): array {
		$record = KeyAuth::record();
		if ( $record !== null ) {
			return [
				'registered'  => false,
				'class'       => (string) $record['agent_class'],
				'agent_name'  => $record['agent_name'] === null ? null : (string) $record['agent_name'],
				'scopes'      => self::scopeRows( $record['read_ids'], $record['write_ids'] ),
				'expires'     => (string) $record['expires'] . ' UTC',
				'message'     => 'Call agent-register to register. This key works once.',
			];
		}

		$userId = get_current_user_id();
		$fqdn   = AgentIssuer::fqdnFor( $userId );
		$user   = get_userdata( $userId );
		if ( $fqdn !== null && $user instanceof \WP_User ) {
			$scopes = new Scopes();
			$issued = AgentIssuer::issuedInfo( $userId );

			return [
				'registered' => true,
				'fqdn'       => $fqdn,
				'login'      => (string) $user->user_login,
				'user_id'    => $userId,
				'class'      => (string) get_user_meta( $userId, AgentMeta::CLASS_NAME, true ),
				'status'     => AgentIssuer::statusOf( $userId ) ?? AgentMeta::STATUS_ACTIVE,
				'scopes'     => self::scopeRows( $scopes->readScopeIds( $userId ), $scopes->writeScopeIds( $userId ) ),
				'issued_by'  => $issued['by'],
				'issued_at'  => $issued['at'],
			];
		}

		return [ 'registered' => false, 'message' => 'No registration key on this request.' ];
	}

	/**
	 * One row per scope the caller can read or write; ids are what tools take.
	 * Only scopes in the caller's own grants appear.
	 *
	 * @param array<int, int|string> $readIds
	 * @param array<int, int|string> $writeIds
	 * @return array<int, array{id:int, name:string, read:bool, write:bool}>
	 */
	private static function scopeRows( array $readIds, array $writeIds ): array {
		$readIds  = array_map( 'intval', $readIds );
		$writeIds = array_map( 'intval', $writeIds );
		$rows     = [];
		foreach ( array_values( array_unique( array_merge( $readIds, $writeIds ) ) ) as $id ) {
			$term = get_term( $id, Scopes::TAXONOMY );
			if ( $term instanceof \WP_Term ) {
				$rows[] = [
					'id'    => $id,
					'name'  => $term->name,
					'read'  => in_array( $id, $readIds, true ),
					'write' => in_array( $id, $writeIds, true ),
				];
			}
		}

		return $rows;
	}

	private static function currentUserIsAgent(): bool {
		$user = wp_get_current_user();

		return $user instanceof \WP_User && $user->ID > 0 && in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true );
	}

	/** Hash of the key the current request presented (record is already valid). */
	private static function presentedHash(): string {
		$record = KeyAuth::record();

		return $record === null ? '' : (string) $record['key_hash'];
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function registerOnce( string $name, array $args ): void {
		AbilityNames::registerOnce( $name, $args );
	}
}
