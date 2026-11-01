<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Core\Untrusted;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use AgentBrain\Modules\Sharing\ShareAbilities;
use WP_Error;

/**
 * MCP abilities for the unified artifact version history (#220):
 * artifact-version-list / -get / -compare / -restore. One history per
 * artifact covers BOTH kinds of content: section submissions (snapshotted
 * by Versions::recordSection()) and HTML uploads (referenced by number,
 * stored by HtmlFiles). Read authority is the artifact read gate ("anyone
 * who may read the artifact may view the history"); restore authority is
 * the approval-role set (curator, lead, or a #252 manager-grant holder;
 * HTML restore additionally refuses a pure agent, mirroring
 * HtmlFiles::approve()). Viewing is never audited; restoring is.
 */
final class VersionAbilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_VERSION_LIST,
			[
				'label'               => __( 'List the version history of an artifact', 'agent-brain' ),
				'description'         => __( 'One version history per artifact, covering both section submissions and HTML file uploads. Every entry carries who made it (agent, and the MCP client behind it), when, its note, and which one is the current/approved/served version. Anyone who may read the artifact may list its history; viewing is not audited.', 'agent-brain' ),
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
						'versions' => [ 'type' => 'array' ],
					],
					'required'   => [ 'versions' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::canRead( $input ),
				'execute_callback'    => static function ( $input ) {
					$input  = is_array( $input ) ? $input : [];
					$result = Versions::list( ShareAbilities::actorId(), (int) ( $input['artifact_id'] ?? 0 ) );

					return $result instanceof WP_Error ? $result : [ 'versions' => $result ];
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_VERSION_GET,
			[
				'label'               => __( 'Read one version of an artifact', 'agent-brain' ),
				'description'         => __( 'Read a single version from the artifact history, exactly as recorded — including a pending version before anyone approves it. For a section version: the snapshot content of that section version. For an HTML version: the file listing of that upload; pass path to also read one file\'s content (text as UTF-8, images and fonts as base64). Use artifact-version-list to find seq numbers. Never audited.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'seq'         => [ 'type' => 'integer', 'minimum' => 1 ],
						'path'        => [ 'type' => 'string', 'description' => 'HTML versions only: also return this file\'s content.' ],
					],
					'required'   => [ 'artifact_id', 'seq' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'seq'     => [ 'type' => 'integer' ],
						'kind'    => [ 'type' => 'string' ],
						'section' => [ 'type' => 'object' ],
						'html'    => [ 'type' => 'object' ],
						'file'    => [ 'type' => 'object' ],
					],
					'required'   => [ 'seq', 'kind' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::canRead( $input ),
				'execute_callback'    => static function ( $input ) {
					return self::get( is_array( $input ) ? $input : [] );
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_VERSION_COMPARE,
			[
				'label'               => __( 'Compare two versions of an artifact', 'agent-brain' ),
				'description'         => __( 'Diff two history entries of the same artifact (seq numbers from artifact-version-list). Two section versions: a line-based text diff of their plain text (entries: type same/added/removed). Two HTML versions: which files were added, removed, or changed. Comparing entries of different kinds is refused (422). Never audited.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'from'        => [ 'type' => 'integer', 'minimum' => 1 ],
						'to'          => [ 'type' => 'integer', 'minimum' => 1 ],
					],
					'required'   => [ 'artifact_id', 'from', 'to' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'kind'      => [ 'type' => 'string' ],
						'identical' => [ 'type' => 'boolean' ],
						'lines'     => [ 'type' => 'array' ],
						'added'     => [ 'type' => 'array' ],
						'removed'   => [ 'type' => 'array' ],
						'changed'   => [ 'type' => 'array' ],
					],
					'required'   => [ 'kind', 'identical' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::canRead( $input ),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return Versions::compare(
						ShareAbilities::actorId(),
						(int) ( $input['artifact_id'] ?? 0 ),
						(int) ( $input['from'] ?? 0 ),
						(int) ( $input['to'] ?? 0 )
					);
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_VERSION_RESTORE,
			[
				'label'               => __( 'Restore an old version of an artifact as a new version', 'agent-brain' ),
				'description'         => __( 'Make an old version the new latest one by creating a NEW version from it — nothing is overwritten. A restored section goes back to `submitted` and must be approved through the normal review path; a restored HTML version waits for artifact-html-approve and share links keep serving the approved version until then. Authority is the approval-role set: curator, artifact lead, or a manager-agent holding the scoped approval grant (a pure agent cannot restore on its own authority). Restoring is audited.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'seq'         => [ 'type' => 'integer', 'minimum' => 1 ],
						'note'        => [ 'type' => 'string', 'maxLength' => 200 ],
					],
					'required'   => [ 'artifact_id', 'seq' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'seq'     => [ 'type' => 'integer' ],
						'version' => [ 'type' => 'integer' ],
						'state'   => [ 'type' => 'string' ],
						'files'   => [ 'type' => 'array' ],
					],
					'required'   => [ 'seq', 'version' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::canRestore( $input ),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter(
						static fn () => self::restore(
							(int) ( $input['artifact_id'] ?? 0 ),
							(int) ( $input['seq'] ?? 0 ),
							(string) ( $input['note'] ?? '' )
						)
					);
				},
			]
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	private static function get( array $input ) {
		$result = Versions::get( ShareAbilities::actorId(), (int) ( $input['artifact_id'] ?? 0 ), (int) ( $input['seq'] ?? 0 ) );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( ( $result['kind'] ?? '' ) === 'section' ) {
			// Stored agent-authored content: same Untrusted framing
			// Mcp\Abilities gives live section reads.
			$result['content'] = Untrusted::wrap( (string) ( $result['content'] ?? '' ), [
				'source'    => 'section',
				'agent_id'  => (int) ( $result['actor'] ?? 0 ),
				'memory_id' => (int) ( $result['section_id'] ?? 0 ),
				'client'    => $result['client'] ?? null,
			] );
			$result['section'] = [
				'id'             => (int) ( $result['section_id'] ?? 0 ),
				'title'          => (string) ( $result['title'] ?? '' ),
				'section_version' => (int) ( $result['section_version'] ?? 0 ),
				'content'        => $result['content'],
			];
			unset( $result['content'], $result['title'] );
		} elseif ( ( $result['kind'] ?? '' ) === 'html' ) {
			$html = [
				'version' => (int) ( $result['html_version'] ?? 0 ),
				'pruned'  => (bool) ( $result['pruned'] ?? false ),
				'files'   => is_array( $result['files'] ?? null ) ? $result['files'] : [],
			];
			unset( $result['files'], $result['pruned'] );

			$path = (string) ( $input['path'] ?? '' );
			if ( $path !== '' ) {
				$row = HtmlFiles::version( (int) ( $input['artifact_id'] ?? 0 ), $html['version'] );
				if ( $row === null || ! isset( $row['files'][ $path ] ) ) {
					return new WP_Error( 'ab_not_found', __( 'File not found.', 'agent-brain' ), [ 'status' => 404 ] );
				}
				$file           = $row['files'][ $path ];
				$result['file'] = [ 'path' => $path, 'type' => $file['type'], 'encoding' => $file['enc'], 'content' => $file['data'], 'size' => $file['size'] ];
			}
			$result['html'] = $html;
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private static function restore( int $artifactId, int $seq, string $note ) {
		$result = Versions::restore( ShareAbilities::actorId(), $artifactId, $seq, $note );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$out = [ 'seq' => (int) $result['seq'] ];
		foreach ( (array) $result['result'] as $key => $value ) {
			$out[ (string) $key ] = $value;
		}

		return $out;
	}

	/**
	 * Read gate: artifact read_post for a concrete id; at discovery time
	 * (no id) the "may read ANY artifact" approximation.
	 *
	 * @param mixed $input
	 */
	private static function canRead( $input ): bool {
		if ( ! ShareAbilities::canUseMcp() ) {
			return false;
		}

		$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;
		$actorId    = ShareAbilities::actorId();

		return $artifactId > 0
			? user_can( $actorId, 'read_post', $artifactId )
			: Versions::canReadAny( $actorId );
	}

	/**
	 * Restore gate: the approval-role set (curator, lead, or #252
	 * manager-grant) for a concrete artifact; at discovery time the
	 * "may restore ANY artifact" approximation. The real per-kind check
	 * runs again at execute time inside Versions::restore().
	 *
	 * @param mixed $input
	 */
	private static function canRestore( $input ): bool {
		if ( ! ShareAbilities::canUseMcp() ) {
			return false;
		}

		$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;
		$actorId    = ShareAbilities::actorId();

		if ( $artifactId <= 0 ) {
			return Versions::canRestoreAny( $actorId );
		}

		return ObjectAccess::userIsCurator( $actorId )
			|| ObjectAccess::userIsLead( $actorId, $artifactId )
			|| ObjectAccess::userCanReviewAndAssemble( $actorId, $artifactId );
	}
}
