<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\ModuleInterface;
use AgentBrain\Modules\Artifacts\Cpts;

/**
 * ART-23 (#163): the per-artifact shared data store. The JS API lives on the
 * preview host below the validated share link (StoreRoute, dispatched from
 * Sharing\ShareRoute); this module wires the opt-in toggle (StoreMode +
 * StoreMetaBox), the MCP abilities (StoreAbilities), the PCP export
 * extension (StoreExport, via the agent_brain/pcp_extensions filter), the
 * Forget cascade (StoreForget), the artifact-deletion cascade and the
 * memories of remembered collections (StoreMemory, #678).
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'data-store';
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', [ StoreAbilities::class, 'register' ] );
		// #654: the TíoDay tools, on top of the store.
		add_action( 'wp_abilities_api_init', [ TioDayAbilities::class, 'register' ] );
		StoreMetaBox::register();
		add_filter( 'agent_brain/pcp_extensions', [ StoreExport::class, 'collect' ], 10, 2 );
		add_filter( 'agent_brain/forget_collect', [ StoreForget::class, 'collect' ], 10, 2 );
		add_action( 'agent_brain/forget_delete', [ StoreForget::class, 'delete' ], 10, 2 );
		// #678: chosen collections (a TíoDay's done, decisions, comments and
		// closed tasks) become brain memories.
		add_action( 'agent_brain/store_written', [ StoreMemory::class, 'written' ], 10, 5 );
		add_action( 'agent_brain/store_deleted', [ StoreMemory::class, 'deleted' ], 10, 3 );

		// The store belongs to the artifact: a hard delete takes the rows
		// with it (trash keeps them, exactly like the artifact's sections).
		add_action(
			'before_delete_post',
			static function ( $postId ): void {
				$post = get_post( (int) $postId );
				if ( $post instanceof \WP_Post && $post->post_type === Cpts::ARTIFACT_POST_TYPE ) {
					StoreData::deleteForArtifact( (int) $postId );
					StoreSeats::deleteForArtifact( (int) $postId );
					StoreMemory::deleteForArtifact( (int) $postId );
				}
			}
		);
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			// #163 (ART-23): per-artifact document store + its rate limiter.
			1 => static function (): void {
				self::dbDelta( StoreData::createTableSql() );
				self::dbDelta( StoreRate::createTableSql() );
			},
			// #375: document versions (expected_version) and the share link
			// each page write came through.
			2 => static function (): void {
				self::dbDelta( StoreData::createTableSql() );
			},
			// #384: document owners, the history behind restore and undo by
			// link, and the per-collection write limits.
			3 => static function (): void {
				self::dbDelta( StoreData::createTableSql() );
				self::dbDelta( StoreHistory::createTableSql() );
				self::dbDelta( StoreLimits::createTableSql() );
			},
			// #469: seats, personal links on one public link.
			4 => static function (): void {
				self::dbDelta( StoreSeats::createTableSql() );
			},
			// #678: which brain memory mirrors which document.
			5 => static function (): void {
				self::dbDelta( StoreMemory::createTableSql() );
			},
		];
	}

	private static function dbDelta( string $sql ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
	}

	public function activate(): void {
		// Tables come from migrations.
	}

	public function deactivate(): void {
		// Store data is removed only by uninstall, never on deactivation.
	}
}
