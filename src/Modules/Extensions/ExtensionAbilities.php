<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use AgentBrain\Modules\OAuth\RoleSwitching;
use AgentBrain\Modules\Sharing\ShareAbilities;
use AgentBrain\Modules\Sharing\ShareLinks;

/**
 * #987: a setup led by an AI turns the extensions on. Two tools for
 * curators and managers only (Switches::chooser(): the person behind the
 * connection; never an agent on its own), listed for nobody else:
 *
 * - tomatio-extension-list: TíoDay and Tomagic, each with one line on what
 *   it does and whether it is on.
 * - tomatio-extension-enable: turn one on or off, on the person's answer.
 *
 * #994: tomatio-extension-bind-request asks for an existing HTML page to
 * run an extension (RFC artifact-extensions 5.2). For the page's lead, a
 * curator or a manager, also through their agent; it only puts a "Bind
 * page to extension" item in the Review queue, which a person decides in
 * wp-admin. There is no tool for approving.
 *
 * #994: three tools for a manager who is a person (ab_manage, curators
 * included; Dennis, 16 Nov), through their own connection, on their
 * explicit answer in chat. The person behind the connection runs them
 * (runner()); an agent on its own, even with ab_manage, is refused and
 * never sees them:
 *
 * - tomatio-extension-turn-off: turn an extension off or on again.
 * - tomatio-extension-rollback: roll an installed extension back to a kept
 *   release, with store_breaking warnings (dry_run shows them first).
 * - tomatio-extension-pin: pin a page to its release, or unpin it.
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
				'description'         => __( 'Curators and managers only. The extensions this brain can turn on, each with one line on what it does and whether it is on: TíoDay (one private page per person, kept by their AIs) and Tomagic (one board for the whole brain, for any project: the work ranked now, next and later, milestones, ideas, and an assignee per item, a person or an agent). TíoDay is on by default. Tomagic is on for every brain and hidden until TíoDay offers it. Do not ask about Tomagic during setup. Change an extension only when the person asks, with tomatio-extension-enable.', 'tomatio' ),
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
				'description'         => __( 'Curators and managers only, and only on the person\'s explicit answer in this chat. extension is tioday, tomagic or voice; enabled true turns it on, false off. TíoDay on: every person gets their page the first time they sign in or their AI uses it (each can still turn theirs off on their profile); off: no new ones, existing pages stay. Tomagic is on by default and hidden until TíoDay offers it. Turning it on when it was off makes the board (in the team scope, led by the person\'s agent) or brings the archived one back, and shows the link. Turning it off archives the board, with everything on it. Voice (off by default) lists the voice tools (writing voices per person and agent); turning it off hides them and keeps the voices.', 'tomatio' ),
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

		ShareAbilities::registerOnce(
			AbilityNames::EXTENSION_BIND_REQUEST,
			[
				'label'               => __( 'Ask to bind a page to an extension', 'tomatio' ),
				'description'         => __( 'The page\'s lead, a curator or a manager (also through their AI). Asks for an existing HTML page to run an extension that is bundled with the plugin or installed on this brain (extension, such as tomatio.tioday), with a reason in one or two sentences. Nothing changes yet: it puts a "Bind page to extension" item in the Review queue showing the files against the extension\'s release, each store collection against its manifest, and what the page gains. A curator or a manager approves it in wp-admin; an AI never can. Once bound, the page\'s files come from the extension only and artifact-html-put is refused. Refused when the page is not an HTML page, already runs an extension or already waits.', 'tomatio' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer' ],
						'extension'   => [ 'type' => 'string', 'description' => 'The extension id, such as tomatio.tioday.' ],
						'reason'      => [ 'type' => 'string', 'maxLength' => BindRequests::REASON_MAX ],
					],
					'required'   => [ 'artifact_id', 'extension', 'reason' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'request'   => [ 'type' => 'string' ],
						'status'    => [ 'type' => 'string' ],
						'extension' => [ 'type' => 'string' ],
						'version'   => [ 'type' => 'string' ],
						'files'     => [ 'type' => 'string', 'description' => 'identical, or differ (the paths are in differ).' ],
						'differ'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'store_ok'  => [ 'type' => 'boolean' ],
						'unknown'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Store collections the manifest does not list: the bind waits until it lists them.' ],
						'looser'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Store collections the manifest gives looser rules than the page has: the bind waits until the manifest gives the same rules or tighter.' ],
						'next'      => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => static fn ( $input ): bool => self::mayRequest( $input ),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter( static fn () => self::bindRequest( $input ) );
				},
			]
		);

		$runOnly = __( 'Managers who are people only (curators included), through their own connection, and only on the person\'s explicit answer in this chat; an agent on its own never can.', 'tomatio' );

		ShareAbilities::registerOnce(
			AbilityNames::EXTENSION_TURN_OFF,
			[
				'label'               => __( 'Turn an extension\'s pages off or on', 'tomatio' ),
				'description'         => $runOnly . ' ' . __( 'extension is an extension id, such as tomatio.tioday or one installed from a zip. off true (the default) serves every page that runs it as "not found", on share links and the members address, and takes it off the home page; nothing is deleted. off false serves them again, with the same links. This is not extension-enable, which decides whether TíoDay and Tomagic make new pages.', 'tomatio' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'extension' => [ 'type' => 'string' ],
						'off'       => [ 'type' => 'boolean', 'default' => true ],
					],
					'required'   => [ 'extension' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'extension' => [ 'type' => 'string' ],
						'off'       => [ 'type' => 'boolean', 'description' => 'Whether its pages are off now, by its own switch or "Turn off all extensions".' ],
						'all_off'   => [ 'type' => 'boolean' ],
						'pages'     => [ 'type' => 'integer' ],
					],
				],
				'permission_callback' => static fn (): bool => self::runner() > 0,
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter( static fn () => self::turnOff( $input ) );
				},
			]
		);

		$warning = [
			'type'       => 'object',
			'properties' => [
				'collection' => [ 'type' => 'string' ],
				'kind'       => [ 'type' => 'string', 'description' => 'missing (the older release does not list the collection), required_added (its schema requires fields the running one does not) or stricter (it allows less).' ],
				'fields'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'docs'       => [ 'type' => 'integer', 'description' => 'Documents the pages hold in that collection.' ],
			],
		];

		ShareAbilities::registerOnce(
			AbilityNames::EXTENSION_ROLLBACK,
			[
				'label'               => __( 'Roll an extension back to a kept release', 'tomatio' ),
				'description'         => $runOnly . ' ' . __( 'Only an extension installed from a zip keeps releases (the last five); TíoDay and Tomagic have none. Call it with dry_run true first: it lists the kept releases and, for version, store_breaking: where the older release\'s store rules may break the data the pages have now (a collection it does not list, fields its schema requires, or rules that allow less). Tell the person in one line and roll back only on their answer. Without dry_run it serves version at once to every page that is not pinned, with no approval; store data is never rolled back, and store_breaking warns without blocking.', 'tomatio' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'extension' => [ 'type' => 'string' ],
						'version'   => [ 'type' => 'string', 'description' => 'A kept release, such as 1.2.0.' ],
						'dry_run'   => [ 'type' => 'boolean', 'default' => false ],
					],
					'required'   => [ 'extension' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'extension'      => [ 'type' => 'string' ],
						'running'        => [ 'type' => 'string' ],
						'releases'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'version'        => [ 'type' => 'string' ],
						'from'           => [ 'type' => 'string' ],
						'dry_run'        => [ 'type' => 'boolean' ],
						'pages'          => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
						'store_breaking' => [ 'type' => 'array', 'items' => $warning ],
						'warning'        => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => static fn (): bool => self::runner() > 0,
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter( static fn () => self::rollBack( $input ) );
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::EXTENSION_PIN,
			[
				'label'               => __( 'Pin a page to its extension release', 'tomatio' ),
				'description'         => $runOnly . ' ' . __( 'pinned true (the default) keeps a page that runs an extension on the release it serves now: updates and rollbacks skip it. pinned false unpins it, and it gets the extension\'s running release at once.', 'tomatio' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer' ],
						'pinned'      => [ 'type' => 'boolean', 'default' => true ],
					],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer' ],
						'extension'   => [ 'type' => 'string' ],
						'pinned'      => [ 'type' => 'string', 'description' => 'The release it is pinned to, or empty.' ],
						'serves'      => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => static fn (): bool => self::runner() > 0,
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter( static fn () => self::pin( $input ) );
				},
			]
		);
	}

	/**
	 * #994: the person who runs turn-off, rollback and pin: the person
	 * behind this connection (their own token, also while it acts as one
	 * of their agents), when they are a manager (Installer::mayRun). An
	 * agent's own connection, even one a person authorized, runs nothing.
	 */
	public static function runner(): int {
		if ( ! ShareAbilities::canUseMcp() ) {
			return 0;
		}
		$person = 0;
		foreach ( [ RoleSwitching::person(), RoleSwitching::writer() ] as $id ) {
			if ( $id > 0 ) {
				$person = $id;
				break;
			}
		}
		if ( $person === 0 && ! Capabilities::isPureAgent( ShareAbilities::actorId() ) ) {
			$person = ShareAbilities::actorId();
		}

		return $person > 0 && Installer::mayRun( $person ) === true ? $person : 0;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function turnOff( array $input ) {
		$id  = (string) ( $input['extension'] ?? '' );
		$off = ! array_key_exists( 'off', $input ) || ! empty( $input['off'] );
		$set = TurnedOff::set( $id, $off, self::runner() );
		if ( $set instanceof \WP_Error ) {
			return $set;
		}

		return [
			'extension' => $id,
			'off'       => TurnedOff::isOff( $id ),
			'all_off'   => TurnedOff::allOff(),
			'pages'     => count( Installer::pages( $id ) ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function rollBack( array $input ) {
		$id      = (string) ( $input['extension'] ?? '' );
		$version = (string) ( $input['version'] ?? '' );
		$record  = Installed::record( $id );
		if ( ! empty( $input['dry_run'] ) ) {
			$runner = self::runner();
			if ( $runner <= 0 ) {
				return new \WP_Error( 'ab_forbidden', __( 'Only a manager can roll an extension back, and only through their own connection.', 'tomatio' ), [ 'status' => 403 ] );
			}
			$warns = $version !== '' ? Installer::rollbackWarnings( $id, $version ) : [];

			return [
				'extension'      => $id,
				'running'        => $record !== null ? (string) $record['version'] : '',
				'releases'       => Installed::releases( $id ),
				'version'        => $version,
				'dry_run'        => true,
				'store_breaking' => $warns,
				'warning'        => Installer::describeBreaking( $warns ),
			];
		}
		$done = Installer::rollBack( $id, $version, self::runner() );
		if ( $done instanceof \WP_Error ) {
			return $done;
		}

		return [
			'extension'      => $done['id'],
			'running'        => $done['version'],
			'releases'       => Installed::releases( $id ),
			'version'        => $done['version'],
			'from'           => $done['from'],
			'dry_run'        => false,
			'pages'          => array_map( static fn ( $page, string $what ): array => [ 'artifact_id' => (int) $page, 'result' => $what ], array_keys( $done['pages'] ), array_values( $done['pages'] ) ),
			'store_breaking' => $done['store_breaking'],
			'warning'        => Installer::describeBreaking( $done['store_breaking'] ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function pin( array $input ) {
		$page   = (int) ( $input['artifact_id'] ?? 0 );
		$pinned = ! array_key_exists( 'pinned', $input ) || ! empty( $input['pinned'] );
		$result = $pinned ? Pin::set( $page, self::runner() ) : Pin::clear( $page, self::runner() );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return [
			'artifact_id' => $page,
			'extension'   => Binding::extensionId( $page ),
			'pinned'      => Pin::version( $page ),
			'serves'      => (string) get_post_meta( $page, PageMaker::META_VERSION, true ),
		];
	}

	/**
	 * Discovery passes no artifact_id: approximate with "may manage any
	 * artifact", for the caller or the person behind it, like html-approve.
	 *
	 * @param mixed $input
	 */
	private static function mayRequest( $input ): bool {
		if ( ! ShareAbilities::canUseMcp() ) {
			return false;
		}
		$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;
		foreach ( [ ShareAbilities::actorId(), HtmlFiles::delegatedPerson( ShareAbilities::actorId() ) ] as $user ) {
			if ( $user > 0 && ( $artifactId > 0 ? HtmlFiles::canEdit( $user, $artifactId ) : ShareLinks::canManageAny( $user ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function bindRequest( array $input ) {
		$artifactId = (int) ( $input['artifact_id'] ?? 0 );
		$request    = BindRequests::request( ShareAbilities::actorId(), $artifactId, (string) ( $input['extension'] ?? '' ), (string) ( $input['reason'] ?? '' ) );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}
		$package = Sandbox::package( $request['extension'] );
		$check   = $package !== null ? BindRequests::check( $artifactId, $package ) : null;

		return [
			'request'   => $request['id'],
			'status'    => 'waiting',
			'extension' => $request['extension'],
			'version'   => $request['version'],
			'files'     => $check !== null && $check['same'] ? 'identical' : 'differ',
			'differ'    => $check['differ'] ?? [],
			'store_ok'  => $check['store_ok'] ?? false,
			'unknown'   => $check['unknown'] ?? [],
			'looser'    => $check['looser'] ?? [],
			'next'      => __( 'It waits in the Review queue for a curator or a manager to approve in wp-admin. Tell the person; you cannot approve it.', 'tomatio' ),
		];
	}
}
