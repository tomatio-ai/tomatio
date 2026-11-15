<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use WP_Error;

/**
 * #796: artifact-settings-get / -set, every per-artifact setting of the
 * artifact screen over MCP, from the ArtifactSettings registry. Listed for
 * whoever may manage an artifact's share links (its lead, curators and
 * managers, or the person behind a role-switching connection); each setting
 * then keeps its own rule.
 */
final class SettingsAbilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$keys = array_keys( ArtifactSettings::UI_FIELDS );

		AbilityNames::registerOnce(
			AbilityNames::ARTIFACT_SETTINGS_GET,
			[
				'label'               => __( 'Read an artifact\'s settings', 'tomatio' ),
				'description'         => sprintf(
					/* translators: %s: the setting keys */
					__( 'Every per-artifact setting of the artifact screen, for its lead, curators and managers: for each key (%s) its value, default, type, allowed values, a short description, who may change it, can_change (whether you may change it now), applies (false for the HTML-page settings of an artifact that is not an HTML page) and, for features a site can turn off, off_site_wide. Change them with artifact-settings-set.', 'tomatio' ),
					implode( ', ', $keys )
				),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer' ],
						'settings'    => [ 'type' => 'object' ],
					],
					'required'   => [ 'artifact_id', 'settings' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;
					$settings   = ArtifactSettings::describe( $artifactId, ArtifactSettings::decider() );

					return $settings instanceof WP_Error ? $settings : [ 'artifact_id' => $artifactId, 'settings' => $settings ];
				},
			]
		);

		AbilityNames::registerOnce(
			AbilityNames::ARTIFACT_SETTINGS_SET,
			[
				'label'               => __( 'Change an artifact\'s settings', 'tomatio' ),
				'description'         => sprintf(
					/* translators: %s: the setting keys */
					__( 'Change per-artifact settings, the same as their checkboxes on the artifact screen: settings is a map of only the keys to change (%s), each validated against artifact-settings-get. All or nothing: an unknown key, a bad value or a setting you may not change refuses the whole call, naming the setting, and nothing is saved. Each setting keeps its own rule: most are for curators and managers; review_required its lead may turn on, and only a person may turn off. dry_run=true returns the changes (old and new) without saving. A change that opens the artifact\'s share pages to more (scripts, file uploads, live brain reads, ask the brain or a higher ask budget, a collection anyone with the link may write, the page store on with one, serving the latest HTML version without approval; widening lists them) is never saved at once: the call returns needs_confirmation, the changes and a confirm_token valid for 10 minutes; show the person the changes, and only on their explicit yes call again with the same artifact_id and settings plus confirm_token. On an artifact locked as "Review required" such a change is refused: a person makes it on the artifact screen. The result lists the changes, every setting after them, and warnings (for example brain_reads without viewer_identity). Turning page_store on needs page_store_collections. Change settings only on the person\'s explicit request in this chat.', 'tomatio' ),
					implode( ', ', $keys )
				),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'settings'    => [
							'type'                 => [ 'object', 'string' ],
							'description'          => 'Setting key => new value, only the keys to change. Example: {"uploads": true, "ask_daily_budget_usd": 0.5}.',
						],
						'dry_run'       => [ 'type' => 'boolean', 'description' => 'Return what would change, without saving.' ],
						'confirm_token' => [ 'type' => 'string', 'description' => 'The token a previous call returned for these same changes, once the person confirmed them.' ],
					],
					'required'   => [ 'artifact_id', 'settings' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer' ],
						'dry_run'     => [ 'type' => 'boolean' ],
						'changes'     => [ 'type' => 'object' ],
						'settings'    => [ 'type' => 'object' ],
						'warnings'           => [ 'type' => 'array' ],
						'widening'           => [ 'type' => 'array' ],
						'needs_confirmation' => [ 'type' => 'boolean' ],
						'confirm_token'      => [ 'type' => 'string' ],
						'expires_in'         => [ 'type' => 'integer' ],
					],
					'required'   => [ 'artifact_id', 'dry_run', 'changes', 'settings', 'needs_confirmation' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input      = is_array( $input ) ? $input : [];
					$artifactId = (int) ( $input['artifact_id'] ?? 0 );
					$settings   = $input['settings'] ?? null;
					// #634: some clients send an object as its JSON text.
					if ( is_string( $settings ) ) {
						$settings = json_decode( $settings, true );
					}
					if ( ! is_array( $settings ) ) {
						return new WP_Error( 'ab_settings_invalid', __( 'settings is an object of setting key to new value.', 'tomatio' ), [ 'status' => 400 ] );
					}

					$dryRun  = ! empty( $input['dry_run'] );
					$confirm = isset( $input['confirm_token'] ) && is_string( $input['confirm_token'] ) ? trim( $input['confirm_token'] ) : '';
					$run     = static function () use ( $artifactId, $settings, $dryRun, $confirm ) {
						$result = ArtifactSettings::apply( $artifactId, $settings, ArtifactSettings::decider(), $dryRun, ShareAbilities::actorId(), $confirm );

						return $result instanceof WP_Error ? $result : [ 'artifact_id' => $artifactId ] + $result;
					};

					return $dryRun ? $run() : ShareAbilities::withLimiter( $run );
				},
			]
		);
	}

	/**
	 * The lead, a curator or a manager of the artifact, as the caller or as
	 * the person behind a role-switching connection. Discovery passes no
	 * artifact_id: "may manage any".
	 *
	 * @param mixed $input
	 */
	private static function can( $input ): bool {
		if ( ! ShareAbilities::canUseMcp() ) {
			return false;
		}

		$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;
		foreach ( array_unique( [ ShareAbilities::actorId(), ArtifactSettings::decider() ] ) as $userId ) {
			if ( $artifactId > 0 ? ShareLinks::canManage( $userId, $artifactId ) : ShareLinks::canManageAny( $userId ) ) {
				return true;
			}
		}

		return false;
	}
}
