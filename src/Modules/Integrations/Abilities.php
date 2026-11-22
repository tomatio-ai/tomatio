<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;

/**
 * #990: integration-list and integration-status. Any AI that can use
 * the brain may see whether a service is connected. The key is never
 * opened here, and never part of the answer.
 */
final class Abilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$row = self::rowSchema();
		AbilityNames::registerOnce( AbilityNames::INTEGRATION_LIST, [
			'label'               => __( 'List connected services', 'tomatio' ),
			'description'         => __( 'The outside services this brain can use (Linear and Notion first), each with whether it is connected, whether it is failing, and how many reads have been counted. Never includes a key or an app secret. Connecting, testing and disconnecting are done by a curator in Settings > Integrations. setup_done says whether the Get started step Connect Linear or Notion is done (a service connected, or the step skipped). While it is not, and you are leading the person through setup, ask once whether their team works in Linear or Notion, say what an integration does (a context source the brain reads and cites, and planning sync with Magic Backlog both ways, the first run a dry run) and send them to Settings > Integrations to connect it themselves; never ask for the key in the chat. If they use neither, they can click Skip this step on the Get started checklist (TomaTío > Overview).', 'tomatio' ),
			'category'            => McpModule::CATEGORY,
			'input_schema'        => [ 'type' => 'object', 'properties' => [] ],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'integrations' => [ 'type' => 'array', 'items' => $row ],
					'setup_done'   => [ 'type' => 'boolean', 'description' => 'Whether the Get started step Connect Linear or Notion is done (#993).' ],
				],
				'required'   => [ 'integrations' ],
			],
			'permission_callback' => [ self::class, 'canUse' ],
			'execute_callback'    => [ self::class, 'listAll' ],
		] );
		AbilityNames::registerOnce( AbilityNames::INTEGRATION_STATUS, [
			'label'               => __( 'Check a connected service', 'tomatio' ),
			'description'         => __( 'Whether one outside service is connected, failing, or being asked to slow down. id is the service, such as linear or notion. Never includes a key or an app secret.', 'tomatio' ),
			'category'            => McpModule::CATEGORY,
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id' => [ 'type' => 'string', 'description' => 'The service, such as linear or notion.' ],
				],
				'required'   => [ 'id' ],
			],
			'output_schema'       => $row,
			'permission_callback' => [ self::class, 'canUse' ],
			'execute_callback'    => [ self::class, 'one' ],
		] );
	}

	public static function canUse(): bool {
		$actor = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		return $actor > 0 && function_exists( 'user_can' ) && user_can( $actor, Capabilities::CAP_USE_MCP );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function listAll(): array {
		$catalog = new Catalog();
		$out     = [];
		foreach ( $catalog->services() as $id => $spec ) {
			$out[] = self::view( $id, $spec );
		}

		return [ 'integrations' => $out, 'setup_done' => SetupStep::done() ];
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public static function one( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$id    = sanitize_key( (string) ( $input['id'] ?? '' ) );
		$spec  = ( new Catalog() )->get( $id );
		if ( $spec === null ) {
			return [
				'id'        => $id,
				'name'      => '',
				'connected' => false,
				'auth'      => '',
				'account'   => '',
				'failing'   => false,
				'message'   => __( 'That integration is not installed.', 'tomatio' ),
				'reads'     => 0,
				'writes'    => 0,
				'limited'   => false,
				'last_test' => 0,
			];
		}

		return self::view( $id, $spec );
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>
	 */
	private static function view( string $id, array $spec ): array {
		$public = Vault::publicRow( Store::row( $id ), $id, (string) $spec['name'] );
		unset( $public['app_configured'] );
		$until = (int) $public['limited_until'];
		$public['limited'] = $until > time();
		unset( $public['limited_until'] );

		return $public;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function rowSchema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'        => [ 'type' => 'string' ],
				'name'      => [ 'type' => 'string' ],
				'connected' => [ 'type' => 'boolean' ],
				'auth'      => [ 'type' => 'string', 'description' => 'token, oauth, or empty.' ],
				'account'   => [ 'type' => 'string' ],
				'failing'   => [ 'type' => 'boolean' ],
				'message'   => [ 'type' => 'string' ],
				'reads'     => [ 'type' => 'integer' ],
				'writes'    => [ 'type' => 'integer' ],
				'limited'   => [ 'type' => 'boolean' ],
				'last_test' => [ 'type' => 'integer' ],
			],
		];
	}
}
