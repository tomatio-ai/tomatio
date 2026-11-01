<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Uploads;

use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use AgentBrain\Modules\Sharing\ShareAbilities;
use AgentBrain\Modules\Sharing\ShareLinks;
use WP_Error;

/**
 * ART-27 (#167): MCP abilities for the per-artifact asset store:
 * artifact-upload-put / -list / -delete. Authority is the Sharing module's
 * own — the artifact's lead, a curator, or an ab_manage person
 * (ShareLinks::canManage), with the same discovery approximation and
 * WriteLimiter wrapper, so upload authority cannot drift from share-link
 * authority. Everything is additionally gated by the per-artifact
 * UploadMode switch. Uploads travel as name + content (utf8 or base64),
 * exactly like the HTML artifact abilities.
 */
final class UploadAbilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_UPLOAD_PUT,
			[
				'label'               => __( 'Upload a file to an artifact', 'agent-brain' ),
				'description'         => __( 'Store one file in the artifact\'s asset store (artifact lead, curator or manager; the artifact must have uploads enabled). The file type must be on the allowlist (images, pdf, text, csv, json) and is magic-number checked; content travels as utf8 or base64, like the HTML artifact abilities.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
						'name'         => [ 'type' => 'string', 'description' => 'File name; the extension picks the allowed type.' ],
						'content'      => [ 'type' => 'string', 'description' => 'The file content (base64 for binary types).' ],
						'encoding'     => [ 'type' => 'string', 'enum' => [ 'utf8', 'base64' ] ],
						'content_type' => [ 'type' => 'string', 'description' => 'Optional declared MIME type; must match the extension.' ],
					],
					'required'   => [ 'artifact_id', 'name', 'content' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'         => [ 'type' => 'integer' ],
						'name'       => [ 'type' => 'string' ],
						'mime'       => [ 'type' => 'string' ],
						'size'       => [ 'type' => 'integer' ],
						'created_at' => [ 'type' => 'string' ],
					],
					'required'   => [ 'id', 'name', 'mime', 'size', 'created_at' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter(
						static function () use ( $input ) {
							$artifactId = (int) ( $input['artifact_id'] ?? 0 );
							$active     = self::requireActive( $artifactId );
							if ( $active instanceof WP_Error ) {
								return $active;
							}

							$file = UploadPolicy::normalize( $input );
							if ( $file instanceof WP_Error ) {
								return $file;
							}

							$stored = UploadStore::store( $artifactId, ShareAbilities::actorId(), $file );
							if ( $stored instanceof WP_Error ) {
								return $stored;
							}

							self::audit( ShareAbilities::actorId(), 'artifact_upload_created', 'ok', (int) $stored['id'], [ 'artifact_id' => $artifactId, 'size' => $stored['size'] ] );

							return [
								'id'         => $stored['id'],
								'name'       => $stored['name'],
								'mime'       => $stored['mime'],
								'size'       => $stored['size'],
								'created_at' => $stored['created_at'],
							];
						}
					);
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_UPLOAD_LIST,
			[
				'label'               => __( 'List the uploads of an artifact', 'agent-brain' ),
				'description'         => __( 'List the files in the artifact\'s asset store with name, type, size and uploader. Never returns share-link tokens.', 'agent-brain' ),
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
					'properties' => [ 'uploads' => [ 'type' => 'array' ] ],
					'required'   => [ 'uploads' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input  = is_array( $input ) ? $input : [];
					$result = self::requireActive( (int) ( $input['artifact_id'] ?? 0 ) );
					if ( $result instanceof WP_Error ) {
						return $result;
					}

					$uploads = [];
					foreach ( UploadStore::listFor( (int) ( $input['artifact_id'] ?? 0 ) ) as $row ) {
						$uploads[] = [
							'id'          => (int) $row['id'],
							'artifact_id' => (int) $row['artifact_id'],
							'name'        => (string) $row['name'],
							'mime'        => (string) $row['mime'],
							'size'        => (int) $row['size'],
							'uploaded_by' => (int) $row['uploaded_by'],
							'created_at'  => (string) $row['created_at'],
						];
					}

					return [ 'uploads' => $uploads ];
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_UPLOAD_DELETE,
			[
				'label'               => __( 'Delete an artifact upload', 'agent-brain' ),
				'description'         => __( 'Delete one upload from an artifact\'s asset store by id; the uploader themselves, the artifact lead, a curator or a manager may delete.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'upload_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					],
					'required'   => [ 'upload_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'deleted' => [ 'type' => 'boolean' ] ],
					'required'   => [ 'deleted' ],
				],
				'permission_callback' => static function ( $input ): bool {
					if ( ! ShareAbilities::canUseMcp() ) {
						return false;
					}

					$uploadId = is_array( $input ) ? (int) ( $input['upload_id'] ?? 0 ) : 0;
					$row      = $uploadId > 0 ? UploadStore::find( $uploadId ) : null;
					if ( $row === null ) {
						return self::canManageAny();
					}

					$actor = ShareAbilities::actorId();

					return (int) $row['uploaded_by'] === $actor || ShareLinks::canManage( $actor, (int) $row['artifact_id'] );
				},
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter(
						static function () use ( $input ) {
							$row = UploadStore::find( (int) ( $input['upload_id'] ?? 0 ) );
							if ( $row === null ) {
								return new WP_Error( 'ab_not_found', __( 'Upload not found.', 'agent-brain' ), [ 'status' => 404 ] );
							}

							$result = self::requireActive( (int) $row['artifact_id'] );
							if ( $result instanceof WP_Error ) {
								return $result;
							}

							$id = (int) $row['id'];
							UploadStore::delete( $row );
							self::audit( ShareAbilities::actorId(), 'artifact_upload_deleted', 'ok', $id, [ 'artifact_id' => (int) $row['artifact_id'] ] );

							return [ 'deleted' => true ];
						}
					);
				},
			]
		);
	}

	/**
	 * @param mixed $input
	 */
	private static function can( $input ): bool {
		if ( ! ShareAbilities::canUseMcp() ) {
			return false;
		}

		$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;

		// Discovery passes no artifact_id: approximate with "may manage any".
		return $artifactId > 0
			? ShareLinks::canManage( ShareAbilities::actorId(), $artifactId )
			: self::canManageAny();
	}

	private static function canManageAny(): bool {
		$actor = ShareAbilities::actorId();

		return ObjectAccess::userIsCurator( $actor ) || user_can( $actor, Capabilities::CAP_MANAGE );
	}

	/**
	 * @return true|WP_Error
	 */
	private static function requireActive( int $artifactId ) {
		if ( $artifactId <= 0 ) {
			return new WP_Error( 'ab_not_found', __( 'Artifact not found.', 'agent-brain' ), [ 'status' => 404 ] );
		}

		if ( ! UploadMode::isActive( $artifactId ) ) {
			return new WP_Error( 'ab_uploads_disabled', __( 'Uploads are not enabled for this artifact.', 'agent-brain' ), [ 'status' => 409 ] );
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $meta Never token material.
	 */
	private static function audit( int $actorId, string $event, string $status, int $uploadId, array $meta ): void {
		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => $event,
				'status'      => $status,
				'object_type' => 'artifact_upload',
				'object_id'   => (string) $uploadId,
				'meta'        => $meta,
			]
		);
	}
}
