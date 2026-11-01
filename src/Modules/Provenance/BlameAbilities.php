<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Core\Untrusted;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Artifacts\Versions;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use AgentBrain\Modules\Recall\KnowledgeSearch;
use AgentBrain\Modules\Sharing\ShareAbilities;
use WP_Error;

/**
 * PROV-5 (#174): the `agent-brain/section-blame` and `agent-brain/knowledge-blame`
 * MCP abilities. Both return the block-level blame of an item's current
 * content from RevisionBlame: each top-level block with the author (actor),
 * the person behind the actor (via), the MCP client, the version and the
 * time — carried forward until the block's text changes.
 *
 * Read authority mirrors the existing read abilities: a section's blame
 * requires the per-section read gate (same as a section version's content —
 * blame reveals block text); knowledge/procedure blame requires the item to
 * be approved and in one of the caller's read scopes (same as knowledge-read).
 * Viewing is never audited, exactly like the version-history abilities.
 */
final class BlameAbilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		ShareAbilities::registerOnce(
			AbilityNames::SECTION_BLAME,
			[
				'label'               => __( 'Blame a section block by block', 'agent-brain' ),
				'description'         => __( 'Block-level blame of a section\'s current content: every top-level block with the author who wrote it (actor), the person behind the actor (via), the MCP client, the section version and the time. An author is carried forward until the block\'s text changes. Authority is the per-section read gate (owner, curator, lead, or a scoped manager-grant); viewing is never audited.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'section_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					],
					'required'   => [ 'section_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'section_id' => [ 'type' => 'integer' ],
						'version'    => [ 'type' => [ 'integer', 'null' ] ],
						'blocks'     => [ 'type' => 'array' ],
					],
					'required'   => [ 'section_id', 'blocks' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::canReadSection( $input ),
				'execute_callback'    => static function ( $input ) {
					$input     = is_array( $input ) ? $input : [];
					$sectionId = (int) ( $input['section_id'] ?? 0 );
					$post      = get_post( $sectionId );

					if ( ! $post || $post->post_type !== 'ab_artifact_section' ) {
						return new WP_Error( 'ab_not_found', __( 'Section not found.', 'agent-brain' ), [ 'status' => 404 ] );
					}

					$result = RevisionBlame::blame( $sectionId );

					return [
						'section_id' => $sectionId,
						'version'    => $result['version'],
						'blocks'     => self::wrapBlocks( $result['blocks'], 'section', $sectionId ),
					];
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::KNOWLEDGE_BLAME,
			[
				'label'               => __( 'Blame a knowledge or procedure entry block by block', 'agent-brain' ),
				'description'         => __( 'Block-level blame of an approved knowledge/procedure entry\'s current content: every top-level block with the author who wrote it (actor), the person behind the actor (via), the MCP client and the time (knowledge has no version counter; version is null). An author is carried forward until the block\'s text changes. The entry must be approved and in one of your read scopes (same gate as knowledge-read); viewing is never audited.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'knowledge_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					],
					'required'   => [ 'knowledge_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'knowledge_id' => [ 'type' => 'integer' ],
						'type'         => [ 'type' => 'string' ],
						'blocks'       => [ 'type' => 'array' ],
					],
					'required'   => [ 'knowledge_id', 'blocks' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::canReadKnowledge( $input ),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];
					$id    = (int) ( $input['knowledge_id'] ?? 0 );
					$post  = get_post( $id );

					if ( ! $post || ! in_array( $post->post_type, [ 'ab_knowledge', 'ab_procedure' ], true ) ) {
						return new WP_Error( 'ab_not_found', __( 'Knowledge entry not found.', 'agent-brain' ), [ 'status' => 404 ] );
					}

					// Same scope gate as knowledge-read, re-checked at execute
					// time with the concrete id.
					$denied = self::gateKnowledge( $id );
					if ( $denied instanceof WP_Error ) {
						return $denied;
					}

					$result = RevisionBlame::blame( $id );

					return [
						'knowledge_id' => $id,
						'type'         => $post->post_type === 'ab_procedure' ? 'procedure' : 'knowledge',
						'blocks'       => self::wrapBlocks( $result['blocks'], $post->post_type, $id ),
					];
				},
			]
		);
	}

	/**
	 * Stored agent-authored block text gets the same Untrusted framing
	 * Mcp\Abilities gives live reads.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function wrapBlocks( array $blocks, string $source, int $postId ): array {
		foreach ( $blocks as $i => $block ) {
			$author = is_array( $block['author'] ?? null ) ? $block['author'] : [];

			$blocks[ $i ]['text'] = Untrusted::wrap( (string) ( $block['text'] ?? '' ), [
				'source'    => $source,
				'agent_id'  => (int) ( $author['actor_id'] ?? 0 ),
				'memory_id' => $postId,
				'client'    => $author['client'] ?? null,
			] );
		}

		return $blocks;
	}

	/**
	 * Section blame gate: the per-section read gate for a concrete id; at
	 * discovery time the "may read ANY artifact" approximation the version
	 * abilities use.
	 *
	 * @param mixed $input
	 */
	private static function canReadSection( $input ): bool {
		if ( ! ShareAbilities::canUseMcp() ) {
			return false;
		}

		$sectionId = is_array( $input ) ? (int) ( $input['section_id'] ?? 0 ) : 0;
		$actorId   = ShareAbilities::actorId();

		return $sectionId > 0
			? ObjectAccess::canReadSection( $actorId, $sectionId )
			: Versions::canReadAny( $actorId );
	}

	/**
	 * Knowledge blame gate: knowledge-read's gate (canUseMcp) at discovery
	 * time; the concrete approved-and-in-scope check runs again at execute
	 * time in gateKnowledge() so a guessed id gets the same generic denial
	 * as knowledge-read.
	 *
	 * @param mixed $input
	 */
	private static function canReadKnowledge( $input ): bool {
		if ( ! ShareAbilities::canUseMcp() ) {
			return false;
		}

		$id = is_array( $input ) ? (int) ( $input['knowledge_id'] ?? 0 ) : 0;
		if ( $id <= 0 ) {
			return true;
		}

		return ! self::gateKnowledge( $id ) instanceof WP_Error;
	}

	/**
	 * @return true|WP_Error
	 */
	private static function gateKnowledge( int $id ) {
		$actorId = ShareAbilities::actorId();
		$scopes  = ( new \AgentBrain\Modules\Identity\Scopes() )->readScopeIds( $actorId );

		return ( new KnowledgeSearch() )->getScoped( $id, $scopes ) !== null
			? true
			: new WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
	}
}
