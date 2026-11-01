<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\WriteLimiter;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use AgentBrain\Modules\Sharing\AppIcon;
use AgentBrain\Modules\Sharing\ShareAbilities;
use AgentBrain\Modules\Sharing\ShareLinks;
use WP_Error;

/**
 * ART-23 (#163): MCP abilities for the per-artifact data store:
 * artifact-store-get / -set / -delete, plus artifact-store-config (#375,
 * #384: the page-store switch and each collection's rules) and
 * artifact-store-history / -restore / -undo-link (#384). Authority is the artifact's lead, a
 * curator, or an ab_manage person — the same rule as the share links
 * themselves (ShareLinks::canManage). Writes carry the calling member's
 * attribution (ViewerIdentity) and go through Core\WriteLimiter.
 */
final class StoreAbilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_STORE_GET,
			[
				'label'               => __( 'Read artifact data store', 'agent-brain' ),
				'description'         => __( 'Read the shared data store of an artifact (artifact lead, curator or manager). Without collection: collection statistics. With collection: every document of the collection. With collection and key: one document, with its value and write attribution.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'collection'  => [ 'type' => 'string' ],
						'key'         => [ 'type' => 'string' ],
					],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'collections' => [ 'type' => 'array' ],
						'docs'        => [ 'type' => 'array' ],
						'doc'         => [ 'type' => 'object' ],
					],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input      = is_array( $input ) ? $input : [];
					$artifactId = (int) ( $input['artifact_id'] ?? 0 );
					$collection = isset( $input['collection'] ) && is_string( $input['collection'] ) ? StoreData::normalizeCollection( $input['collection'] ) : null;
					$key        = isset( $input['key'] ) && is_string( $input['key'] ) ? StoreData::normalizeKey( $input['key'] ) : null;

					if ( $collection === null && ! empty( $input['collection'] ) ) {
						return new WP_Error( 'ab_store_invalid_collection', __( 'Invalid collection name.', 'agent-brain' ), [ 'status' => 400 ] );
					}

					if ( $collection === null ) {
						return [ 'collections' => StoreData::collections( $artifactId ) ];
					}

					if ( $key === null && ! empty( $input['key'] ) ) {
						return new WP_Error( 'ab_store_invalid_key', __( 'Invalid document key.', 'agent-brain' ), [ 'status' => 400 ] );
					}

					if ( $key === null ) {
						return [ 'docs' => StoreData::list( $artifactId, $collection ) ];
					}

					$doc = StoreData::get( $artifactId, $collection, $key );

					return $doc === null
						? new WP_Error( 'ab_store_not_found', __( 'Document not found.', 'agent-brain' ), [ 'status' => 404 ] )
						: [ 'doc' => $doc ];
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_STORE_SET,
			[
				'label'               => __( 'Write artifact data store', 'agent-brain' ),
				'description'         => __( 'Create or replace a document in the shared data store of an artifact (artifact lead, curator or manager). The write is attributed to the calling member. Every document has a version; pass expected_version to write only if nobody changed it since you read it. Per-document, per-collection and per-collection-count limits apply.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'collection'  => [ 'type' => 'string' ],
						'key'         => [ 'type' => 'string' ],
						'value'       => [ 'type' => [ 'object', 'array', 'string', 'number', 'boolean', 'null' ], 'description' => 'Any JSON value. Send a record as a JSON object; an object or array sent as a JSON string (some AI clients do this) is decoded and stored as the object.' ],
						'expected_version' => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Write only if the document is still at this version (0: only if it does not exist yet).' ],
					],
					'required'   => [ 'artifact_id', 'collection', 'key' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'doc' => [ 'type' => 'object' ] ],
					'required'   => [ 'doc' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter(
						static function () use ( $input ) {
							$artifactId = (int) ( $input['artifact_id'] ?? 0 );
							$collection = isset( $input['collection'] ) && is_string( $input['collection'] ) ? StoreData::normalizeCollection( $input['collection'] ) : null;
							$key        = isset( $input['key'] ) && is_string( $input['key'] ) ? StoreData::normalizeKey( $input['key'] ) : null;
							if ( $collection === null || $key === null ) {
								return new WP_Error( 'ab_store_invalid_name', __( 'Invalid collection or key.', 'agent-brain' ), [ 'status' => 400 ] );
							}

							$expected = isset( $input['expected_version'] ) && is_numeric( $input['expected_version'] ) ? max( 0, (int) $input['expected_version'] ) : null;
							$stored   = StoreData::set( $artifactId, $collection, $key, self::decodedValue( $input['value'] ?? null ), StoreData::attributionFromUser( ShareAbilities::actorId(), $artifactId ), null, $expected );

							return $stored instanceof WP_Error ? $stored : [ 'doc' => $stored ];
						}
					);
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_STORE_DELETE,
			[
				'label'               => __( 'Delete artifact data store', 'agent-brain' ),
				'description'         => __( 'Delete one document (collection + key) or a whole collection (collection only) from the shared data store of an artifact (artifact lead, curator or manager).', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'collection'  => [ 'type' => 'string' ],
						'key'         => [ 'type' => 'string' ],
					],
					'required'   => [ 'artifact_id', 'collection' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'deleted' => [ 'type' => 'integer' ] ],
					'required'   => [ 'deleted' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter(
						static function () use ( $input ) {
							$artifactId = (int) ( $input['artifact_id'] ?? 0 );
							$collection = isset( $input['collection'] ) && is_string( $input['collection'] ) ? StoreData::normalizeCollection( $input['collection'] ) : null;
							if ( $collection === null ) {
								return new WP_Error( 'ab_store_invalid_collection', __( 'Invalid collection name.', 'agent-brain' ), [ 'status' => 400 ] );
							}

							if ( ! empty( $input['key'] ) ) {
								$key = is_string( $input['key'] ) ? StoreData::normalizeKey( $input['key'] ) : null;
								if ( $key === null ) {
									return new WP_Error( 'ab_store_invalid_key', __( 'Invalid document key.', 'agent-brain' ), [ 'status' => 400 ] );
								}

								return [ 'deleted' => StoreData::delete( $artifactId, $collection, $key, null, StoreData::attributionFromUser( ShareAbilities::actorId(), $artifactId ) ) ? 1 : 0 ];
							}

							return [ 'deleted' => StoreData::deleteCollection( $artifactId, $collection, StoreData::attributionFromUser( ShareAbilities::actorId(), $artifactId ) ) ];
						}
					);
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_STORE_CONFIG,
			[
				'label'               => __( 'Configure artifact page store', 'agent-brain' ),
				'description'         => sprintf(
					/* translators: 1: supported schema keywords, 2: an example schema */
					__( 'Read or change whether the share pages of an artifact may use its data store, and the rules of each collection they may use. Default deny: a page reaches only listed collections, and only as the collection\'s preset allows. Presets: counter (anyone increments a whole-number field by a fixed step: leaderboards, likes), ballot (one document per link or member, changeable by its owner: polls, ratings), entries (anyone adds with generated keys, only the writer edits or deletes: sign-ups, guest books), personal (each link or member sees and writes only its own: checklists, saved games), inbox (anyone adds, nobody reads from the page: forms), shared (named members only, version-checked: team boards), read (read-only). Give a preset name, or {"preset": name, ...} to tighten it: ops, writers (anonymous, link, member), owner (anyone, writer, self, seat) and visibility (all, own, none) only tighten; key (a regular expression), schema (a JSON Schema subset with the keywords %1$s, for example %2$s), increment {fields, step}, append {fields} and limits {per_key, per_identity: {max, seconds}, per_ip_day: {max}} can be set. owner "seat" means only the seat whose name is the document key may write it. seats: true turns seats on: on one public link each viewer claims a name (tomatio.seat.claim) and gets a personal link; the result then lists the seats with their opens, and release_seat frees a name (its personal link stops working). import_seats turns claims a page kept itself into seats with no secret needed: a collection name whose documents are {name, hash} (hash: the hex sha256 of the secret; the oldest claim of a name wins), or a list of {name, hash}; links already handed out keep working, with the secret after #seat= or #p=. seat_icon sets how a seat\'s Home Screen icon differs from the link\'s: recolor (the icon\'s background and the letter badge in the seat\'s own colour, the default), badge (a letter badge only) or off; the link\'s own icon never changes. Example: {"scores": {"preset": "counter", "key": "^[A-Z0-9 ]{1,14}$", "increment": {"fields": ["wins"], "step": 1}, "limits": {"per_key": {"max": 1, "seconds": 90}, "per_identity": {"max": 40, "seconds": 3600}}}}. "write" means shared. Turning the store on needs a collection list. Without enabled, collections, seats, seat_icon, release_seat or import_seats: the current settings. Changes are for curators and managers; the artifact lead may read them. Pages load /share/{id}/{token}/_tomatio.js (window.tomatio.store, tomatio.seat). Stored values come from viewers: pages must render them as text, never as HTML.', 'agent-brain' ),
					implode( ', ', StoreSchema::KEYWORDS ),
					'{"type": "object", "required": ["wins"], "properties": {"wins": {"type": "integer", "minimum": 0, "monotonic": true}, "name": {"type": "string", "pattern": "^[A-Z0-9 ]{1,14}$"}}}'
				),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'enabled'     => [ 'type' => 'boolean', 'description' => 'Turn the page store on or off.' ],
						'collections' => [
							'type'                 => 'object',
							'description'          => 'Replaces the list: collection name to a preset name or {"preset": name, ...tightening}. {} allows none.',
							'additionalProperties' => [ 'type' => [ 'string', 'object' ] ],
						],
						'seats'        => [ 'type' => 'boolean', 'description' => 'Turn seats (personal links on one public link) on or off.' ],
						'seat_icon'    => [ 'type' => 'string', 'enum' => AppIcon::STYLES, 'description' => 'How a seat\'s Home Screen icon differs from the link\'s: recolor (default), badge or off.' ],
						'release_seat' => [ 'type' => 'string', 'description' => 'Free this seat name: its personal link stops working and the name can be claimed again. Its documents stay.' ],
						'import_seats' => [
							'type'        => [ 'string', 'array' ],
							'description' => 'Existing claims to import as seats: a collection of {name, hash} documents, or a list of {name, hash}. hash is the hex sha256 of the secret.',
							'items'       => [ 'type' => 'object' ],
						],
					],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'enabled'      => [ 'type' => 'boolean' ],
						'active'       => [ 'type' => 'boolean' ],
						'collections'  => [ 'type' => 'object' ],
						'presets'      => [ 'type' => 'array' ],
						'api_path'     => [ 'type' => 'string' ],
						'client_path'  => [ 'type' => 'string' ],
						'seats_enabled' => [ 'type' => 'boolean' ],
						'seat_icon'    => [ 'type' => 'string' ],
						'seats'        => [ 'type' => 'array' ],
						'released'     => [ 'type' => 'boolean' ],
						'imported'     => [ 'type' => 'array' ],
						'skipped'      => [ 'type' => 'array' ],
					],
					'required'   => [ 'enabled', 'active', 'collections' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input      = is_array( $input ) ? $input : [];
					$artifactId = (int) ( $input['artifact_id'] ?? 0 );

					if ( ! array_key_exists( 'enabled', $input ) && ! array_key_exists( 'collections', $input ) && ! array_key_exists( 'seats', $input ) && ! array_key_exists( 'seat_icon', $input ) && ! array_key_exists( 'release_seat', $input ) && ! array_key_exists( 'import_seats', $input ) ) {
						return self::describeConfig( $artifactId );
					}

					if ( ! StoreMode::canToggle( ShareAbilities::actorId() ) ) {
						return new WP_Error( 'ab_forbidden', __( 'Only a curator or manager can change the page store. Ask one, or act as them.', 'agent-brain' ), [ 'status' => 403 ] );
					}

					$collections = null;
					if ( array_key_exists( 'collections', $input ) ) {
						$collections = is_array( $input['collections'] ) ? StoreMode::normalizeCollections( $input['collections'] ) : 'collections must be an object';
						if ( is_string( $collections ) ) {
							return new WP_Error(
								'ab_store_invalid_collections',
								/* translators: %s: what is wrong with the collection list */
								sprintf( __( 'Invalid collections: %s.', 'agent-brain' ), $collections ),
								[ 'status' => 400 ]
							);
						}
					}

					$enable  = isset( $input['enabled'] ) ? (bool) $input['enabled'] : null;
					$seats   = isset( $input['seats'] ) ? (bool) $input['seats'] : null;
					$icon    = isset( $input['seat_icon'] ) ? (string) $input['seat_icon'] : null;
					$release = isset( $input['release_seat'] ) && is_string( $input['release_seat'] ) ? StoreSeats::normalizeName( $input['release_seat'] ) : null;
					if ( array_key_exists( 'release_seat', $input ) && $release === null ) {
						return new WP_Error( 'ab_store_invalid_seat', __( 'That is not a seat name.', 'agent-brain' ), [ 'status' => 400 ] );
					}
					$import = $input['import_seats'] ?? null;
					if ( is_string( $import ) ) {
						$import = StoreData::normalizeCollection( $import );
						if ( $import === null ) {
							return new WP_Error( 'ab_store_invalid_collection', __( 'Invalid collection name.', 'agent-brain' ), [ 'status' => 400 ] );
						}
					} elseif ( $import !== null && ! is_array( $import ) ) {
						return new WP_Error( 'ab_store_invalid_import', __( 'import_seats is a collection name or a list of {name, hash}.', 'agent-brain' ), [ 'status' => 400 ] );
					}
					if ( $enable === true && ( $collections ?? StoreMode::collections( $artifactId ) ) === [] ) {
						return new WP_Error( 'ab_store_collections_required', __( 'List the collections a page may use, each with a preset (collections: {"scores": "counter"}), when turning the page store on.', 'agent-brain' ), [ 'status' => 400 ] );
					}

					return ShareAbilities::withLimiter(
						static function () use ( $artifactId, $collections, $enable, $seats, $icon, $release, $import ) {
							$actorId = ShareAbilities::actorId();
							if ( $collections !== null ) {
								$done = StoreMode::setCollections( $artifactId, $collections, $actorId );
								if ( $done instanceof WP_Error ) {
									return $done;
								}
							}

							if ( $enable !== null ) {
								$done = StoreMode::set( $artifactId, $enable, $actorId );
								if ( $done instanceof WP_Error ) {
									return $done;
								}
							}

							if ( $seats !== null ) {
								$done = StoreMode::setSeats( $artifactId, $seats, $actorId );
								if ( $done instanceof WP_Error ) {
									return $done;
								}
							}

							if ( $icon !== null ) {
								$done = StoreMode::setSeatIconStyle( $artifactId, $icon, $actorId );
								if ( $done instanceof WP_Error ) {
									return $done;
								}
							}

							$imported = null;
							if ( is_string( $import ) ) {
								$imported = StoreSeats::importCollection( $artifactId, $import, $actorId );
							} elseif ( is_array( $import ) ) {
								$imported = [ 'imported' => [], 'skipped' => [] ];
								foreach ( $import as $claim ) {
									$name = is_array( $claim ) && is_string( $claim['name'] ?? null ) ? $claim['name'] : '';
									$done = StoreSeats::import( $artifactId, $name, is_array( $claim ) && is_string( $claim['hash'] ?? null ) ? $claim['hash'] : '', $actorId );
									$imported[ $done === true ? 'imported' : 'skipped' ][] = $name;
								}
							}

							$out = self::describeConfig( $artifactId ) + ( $imported ?? [] );
							if ( $release !== null ) {
								$out['released'] = StoreSeats::release( $artifactId, $release, $actorId );
								$out['seats']    = StoreSeats::all( $artifactId );
							}

							return $out;
						}
					);
				},
			]
		);

		self::registerRecovery();
	}

	/**
	 * #384: recovery for what rules cannot stop — a document's history,
	 * restoring a version, and undoing every write of one share link.
	 */
	private static function registerRecovery(): void {
		$doc = [
			'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'collection'  => [ 'type' => 'string' ],
			'key'         => [ 'type' => 'string', 'description' => 'The stored key, as artifact-store-get lists it.' ],
		];

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_STORE_HISTORY,
			[
				'label'               => __( 'Read artifact data store history', 'agent-brain' ),
				'description'         => __( 'The recent versions of one document of an artifact\'s data store, newest first: each version\'s value (null after a delete), the operation, who wrote it and through which share link (artifact lead, curator or manager). Kept 30 days, at most 200 versions a document.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => $doc + [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => StoreHistory::MAX_PER_DOC ] ],
					'required'   => [ 'artifact_id', 'collection', 'key' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'versions' => [ 'type' => 'array' ] ],
					'required'   => [ 'versions' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$target = self::docTarget( $input );
					if ( $target instanceof WP_Error ) {
						return $target;
					}

					return [ 'versions' => StoreHistory::forDoc( $target[0], $target[1], $target[2], (int) ( $input['limit'] ?? StoreHistory::MAX_PER_DOC ) ) ];
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_STORE_RESTORE,
			[
				'label'               => __( 'Restore artifact data store version', 'agent-brain' ),
				'description'         => __( 'Write an earlier version of a data store document back as its newest version (artifact lead, curator or manager); restoring the version of a deleted document brings it back. The restore is attributed to the calling member and kept in the history.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => $doc + [ 'version' => [ 'type' => 'integer', 'minimum' => 1 ] ],
					'required'   => [ 'artifact_id', 'collection', 'key', 'version' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [ 'doc' => [ 'type' => 'object' ] ],
					'required'   => [ 'doc' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$target = self::docTarget( $input );
					if ( $target instanceof WP_Error ) {
						return $target;
					}

					return ShareAbilities::withLimiter(
						static function () use ( $target, $input ) {
							[ $artifactId, $collection, $key ] = $target;
							$entry = StoreHistory::version( $artifactId, $collection, $key, (int) ( $input['version'] ?? 0 ) );
							if ( $entry === null || $entry['deleted'] ) {
								return new WP_Error( 'ab_store_version_not_found', __( 'That version is not in the history (or is a delete).', 'agent-brain' ), [ 'status' => 404 ] );
							}

							$current = StoreData::get( $artifactId, $collection, $key );
							$stored  = StoreData::write( $artifactId, $collection, $key, $entry['value'], StoreData::attributionFromUser( ShareAbilities::actorId(), $artifactId ), 'restore', [ 'version' => $entry['version'] ], $current === null ? 0 : (int) $current['version'], $current['owner'] ?? null );

							return $stored instanceof WP_Error ? $stored : [ 'doc' => $stored ];
						}
					);
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_STORE_UNDO_LINK,
			[
				'label'               => __( 'Undo a share link\'s data store writes', 'agent-brain' ),
				'description'         => __( 'Undo every data store write made through one share link (for example after revoking a leaked link), in one collection or the whole artifact (artifact lead, curator or manager). Each document the link touched is replayed without the link\'s writes: other viewers\' increments and appends are kept, documents the link created alone are deleted, documents it deleted come back. Reports what changed and what it skipped (history older than 30 days is gone).', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'link_id'     => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'The share link id (artifact-share-list).' ],
						'collection'  => [ 'type' => 'string', 'description' => 'Only this collection; omit for every collection of the artifact.' ],
					],
					'required'   => [ 'artifact_id', 'link_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'undone'  => [ 'type' => 'array' ],
						'skipped' => [ 'type' => 'array' ],
					],
					'required'   => [ 'undone', 'skipped' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input      = is_array( $input ) ? $input : [];
					$artifactId = (int) ( $input['artifact_id'] ?? 0 );
					$collection = null;
					if ( ! empty( $input['collection'] ) ) {
						$collection = is_string( $input['collection'] ) ? StoreData::normalizeCollection( $input['collection'] ) : null;
						if ( $collection === null ) {
							return new WP_Error( 'ab_store_invalid_collection', __( 'Invalid collection name.', 'agent-brain' ), [ 'status' => 400 ] );
						}
					}

					return ShareAbilities::withLimiter(
						static fn () => StoreHistory::undoLink( $artifactId, (int) ( $input['link_id'] ?? 0 ), $collection, StoreData::attributionFromUser( ShareAbilities::actorId(), $artifactId ) )
					);
				},
			]
		);
	}

	/**
	 * [artifact id, collection, key] of a history/restore call, or the error.
	 *
	 * @param mixed $input
	 * @return array{0:int,1:string,2:string}|WP_Error
	 */
	private static function docTarget( $input ) {
		$input      = is_array( $input ) ? $input : [];
		$collection = isset( $input['collection'] ) && is_string( $input['collection'] ) ? StoreData::normalizeCollection( $input['collection'] ) : null;
		$key        = isset( $input['key'] ) && is_string( $input['key'] ) ? StoreData::normalizeKey( $input['key'] ) : null;
		if ( $collection === null || $key === null ) {
			return new WP_Error( 'ab_store_invalid_name', __( 'Invalid collection or key.', 'agent-brain' ), [ 'status' => 400 ] );
		}

		return [ (int) ( $input['artifact_id'] ?? 0 ), $collection, $key ];
	}

	/**
	 * The page-store settings of an artifact, as artifact-store-config
	 * returns them.
	 *
	 * @return array<string, mixed>
	 */
	private static function describeConfig( int $artifactId ): array {
		$out = [
			'enabled'     => StoreMode::isFlagged( $artifactId ),
			'active'      => StoreMode::isActive( $artifactId ),
			// A JSON object even when empty: {} allows no collection.
			'collections' => (object) StoreMode::collections( $artifactId ),
			'presets'     => array_keys( StoreRules::presets() ),
			'api_path'    => '/share/' . $artifactId . '/{token}/' . StoreRoute::PATH,
			'client_path' => '/share/' . $artifactId . '/{token}/' . StoreClient::PATH,
		];

		// #469: seats and their opens; never a secret (only hashes are stored).
		$out['seats_enabled'] = StoreMode::seatsOn( $artifactId );
		$out['seat_icon']     = StoreMode::seatIconStyle( $artifactId );
		if ( $out['seats_enabled'] || StoreSeats::count( $artifactId ) > 0 ) {
			$out['seats'] = StoreSeats::all( $artifactId );
		}

		return $out;
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
			: ShareLinks::canManageAny( ShareAbilities::actorId() );
	}

	/**
	 * #634: some AI clients (claude.ai) send an object for a mixed-type
	 * input as its JSON text, which would be stored as one long string. A
	 * string that is a JSON object or array is decoded; any other value,
	 * including a plain string, is stored as given.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function decodedValue( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$text = trim( $value );
		if ( $text === '' || ( $text[0] !== '{' && $text[0] !== '[' ) ) {
			return $value;
		}
		$decoded = json_decode( $text, true );

		return is_array( $decoded ) && json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
	}
}
