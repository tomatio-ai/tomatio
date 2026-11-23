<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Clipper;

use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use AgentBrain\Modules\Mcp\Present;
use AgentBrain\Modules\OAuth\RoleSwitching;
use AgentBrain\Modules\Voice\Access;
use WP_Error;

/**
 * B228: the web clipper's two tools, for any connection that may use MCP
 * (the TomaTío web clipper signs in like any connector, through the
 * brain's own OAuth consent).
 *
 * - tomatio-clip-save: save a page, a selection or a bookmark with a note,
 *   in a scope the person picks (their private scope by default), with
 *   tags; the person's Read later list gets it too. Agent-only like
 *   memory-write: a person's own connection writes through their agent
 *   (RoleSwitching::ROUTED_WRITES).
 * - tomatio-clip-lookup: whether an address is saved already.
 *
 * The person is always the one behind the connection (TioDay::person()),
 * never one named in the input.
 */
final class Abilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$tags = [
			'type'        => [ 'array', 'string' ],
			'items'       => [ 'type' => 'string' ],
			'description' => 'Up to ' . Clips::MAX_TAGS . ' tags (or one comma-separated string); stored lower case.',
		];

		AbilityNames::registerOnce(
			AbilityNames::CLIP_SAVE,
			[
				'label'               => __( 'Clip save', 'tomatio' ),
				'description'         => __( 'Save a web page the person is reading: the whole page as readable Markdown (mode page), a selection from it (mode selection) or just the address with a note (mode bookmark). It is saved as an outside source (kind url) linked to an episode memory row holding the Markdown with a header (title, address, site, author, published date, tags, note), so any AI that reads the scope can find and cite it. It is never knowledge: making it knowledge needs a proposal and someone\'s approval. scope_id is where it goes; leave it out for the person\'s private scope. The clip is also added to the person\'s Read later list (made the first time), where they mark it read or archive it; an address clipped again keeps its place and state there (already_saved: true). Returns memory_id, source_id, scope_id and read_later {artifact_id, url, key, state}.', 'tomatio' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'url'       => [ 'type' => 'string', 'description' => 'The page\'s address (http or https).' ],
						'title'     => [ 'type' => 'string' ],
						'mode'      => [ 'type' => 'string', 'enum' => Clips::MODES, 'description' => 'page (default), selection or bookmark.' ],
						'markdown'  => [ 'type' => 'string', 'description' => 'The page or the selection as Markdown, at most ' . Clips::MAX_MARKDOWN . ' characters. Not used for a bookmark.' ],
						'note'      => [ 'type' => 'string', 'description' => 'The person\'s own note, at most ' . Clips::MAX_NOTE . ' characters.' ],
						'author'    => [ 'type' => 'string' ],
						'published' => [ 'type' => 'string', 'description' => 'When the page was published, as the page says (ISO 8601 when known).' ],
						'site'      => [ 'type' => 'string', 'description' => 'The site\'s name; the host when left out.' ],
						'tags'      => $tags,
						'scope_id'  => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'A scope you can write to; default the person\'s private scope.' ],
					],
					'required'   => [ 'url' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'saved'         => [ 'type' => 'boolean' ],
						'memory_id'     => [ 'type' => 'integer' ],
						'source_id'     => [ 'type' => 'integer' ],
						'scope_id'      => [ 'type' => 'integer' ],
						'url'           => [ 'type' => 'string', 'description' => 'The address as saved: no fragment, no tracking arguments.' ],
						'title'         => [ 'type' => 'string' ],
						'mode'          => [ 'type' => 'string' ],
						'tags'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'already_saved' => [ 'type' => 'boolean' ],
						'knowledge'     => [ 'type' => 'boolean', 'description' => 'Always false: a clip is outside reading, not approved knowledge.' ],
						'read_later'    => [ 'type' => 'object' ],
						'note'          => [ 'type' => 'string', 'description' => 'Why the clip is not on a Read later list, when it is not.' ],
					],
					'required'   => [ 'saved', 'memory_id', 'scope_id', 'url' ],
				],
				'permission_callback' => static fn (): bool => self::allowed(),
				'execute_callback'    => static function ( $input = null ) {
					$input   = is_array( $input ) ? $input : [];
					$actorId = (int) get_current_user_id();
					if ( ! Access::isAgent( $actorId ) ) {
						return self::personNotAgent( $actorId );
					}

					return Clips::save( $input, $actorId, TioDay::person() );
				},
			]
		);

		AbilityNames::registerOnce(
			AbilityNames::CLIP_LOOKUP,
			[
				'label'               => __( 'Clip lookup', 'tomatio' ),
				'description'         => __( 'Whether a web address is saved already: in the Read later list of the person behind this connection, or among the url sources this connection registered. The address is compared without its fragment and tracking arguments. Says nothing about anyone else\'s clips. Returns saved, and when it is on the Read later list its title, state (unread, read, archived), saved_at, tags, scope_id and memory_ids; also scopes (where a clip from this connection may go) and default_scope_id.', 'tomatio' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'url' => [ 'type' => 'string', 'description' => 'The page\'s address (http or https).' ],
					],
					'required'   => [ 'url' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'saved'      => [ 'type' => 'boolean' ],
						'url'        => [ 'type' => 'string' ],
						'title'      => [ 'type' => 'string' ],
						'state'      => [ 'type' => 'string' ],
						'saved_at'   => [ 'type' => 'string' ],
						'tags'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'scope_id'   => [ 'type' => 'integer' ],
						'memory_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
						'read_later' => [ 'type' => 'object' ],
						'scopes'           => [ 'type' => 'array', 'description' => 'Where a clip from this connection may go: {id, name, private}.' ],
						'default_scope_id' => [ 'type' => 'integer', 'description' => 'Where clip-save puts it when no scope_id is given (0: none, name one).' ],
					],
					'required'   => [ 'saved', 'url' ],
				],
				'permission_callback' => static fn (): bool => self::allowed(),
				'execute_callback'    => static function ( $input = null ) {
					$input = is_array( $input ) ? $input : [];

					return Clips::lookup( is_string( $input['url'] ?? null ) ? (string) $input['url'] : '', (int) get_current_user_id(), TioDay::person(), TioDay::writerId() );
				},
			]
		);
	}

	/** Listed for every connection that may use MCP. */
	public static function allowed(): bool {
		$userId = (int) get_current_user_id();

		return $userId > 0 && user_can( $userId, Capabilities::CAP_USE_MCP );
	}

	private static function personNotAgent( int $userId ): WP_Error {
		$user  = $userId > 0 ? get_userdata( $userId ) : false;
		$names = array_map( static fn ( \WP_User $agent ): string => (string) $agent->display_name, RoleSwitching::agentsFor( $userId ) );

		return new WP_Error( 'ab_forbidden', Present::personNotAgent( $user instanceof \WP_User ? (string) $user->user_login : (string) $userId, $names ), [ 'status' => 403 ] );
	}
}
