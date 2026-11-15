<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use AgentBrain\Modules\Sharing\ShareAbilities;

/**
 * #987: a setup led by an AI turns the extensions on. Two tools for
 * curators and managers only (Switches::chooser(): the person behind the
 * connection; never an agent on its own), listed for nobody else:
 *
 * - tomatio-extension-list: TíoDay and Tomagic, each with one line on what
 *   it does and whether it is on.
 * - tomatio-extension-enable: turn one on or off, on the person's answer.
 */
final class ExtensionAbilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$row = [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'string' ],
				'name'    => [ 'type' => 'string' ],
				'per'     => [ 'type' => 'string', 'description' => 'person (one page each) or brain (one for everyone).' ],
				'enabled' => [ 'type' => 'boolean' ],
				'what'    => [ 'type' => 'string' ],
				'pages'   => [ 'type' => 'integer' ],
				'url'     => [ 'type' => 'string' ],
			],
		];

		ShareAbilities::registerOnce(
			AbilityNames::EXTENSION_LIST,
			[
				'label'               => __( 'List the brain\'s extensions', 'tomatio' ),
				'description'         => __( 'Curators and managers only. The extensions this brain can turn on, each with one line on what it does and whether it is on: TíoDay (one private page per person, kept by their AIs) and Tomagic (where software magic happens: one board for the whole brain, for software projects: the team\'s work ranked now, next and later, a release roadmap, ideas, and an assignee per item). TíoDay is on by default; Tomagic is off until someone turns it on. During setup, ask the person in one short line whether they want Tomagic (and whether to keep TíoDay), then change only what they answer with tomatio-extension-enable.', 'tomatio' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [ 'type' => 'object', 'properties' => [] ],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'extensions' => [ 'type' => 'array', 'items' => $row ],
						'chosen'     => [ 'type' => 'boolean', 'description' => 'Whether someone already chose (the Get started step is done).' ],
					],
					'required'   => [ 'extensions' ],
				],
				'permission_callback' => static fn (): bool => Switches::chooser() > 0,
				'execute_callback'    => static fn (): array => [ 'extensions' => Switches::describeAll(), 'chosen' => Switches::chosen() ],
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::EXTENSION_ENABLE,
			[
				'label'               => __( 'Turn an extension on or off', 'tomatio' ),
				'description'         => __( 'Curators and managers only, and only on the person\'s explicit answer in this chat. extension is tioday or tomagic; enabled true turns it on, false off. TíoDay on: every person gets their page the first time they sign in or their AI uses it (each can still turn theirs off on their profile); off: no new ones, existing pages stay. Tomagic on: the brain\'s board is made (in the team scope, led by the person\'s agent) or the archived one comes back; off: it is archived, with everything on it.', 'tomatio' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'extension' => [ 'type' => 'string', 'enum' => array_keys( Switches::IDS ) ],
						'enabled'   => [ 'type' => 'boolean' ],
					],
					'required'   => [ 'extension', 'enabled' ],
				],
				'output_schema'       => $row,
				'permission_callback' => static fn (): bool => Switches::chooser() > 0,
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter( static fn () => Switches::set( (string) ( $input['extension'] ?? '' ), ! empty( $input['enabled'] ), Switches::chooser() ) );
				},
			]
		);
	}
}
