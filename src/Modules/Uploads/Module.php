<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Uploads;

use AgentBrain\Core\ModuleInterface;
use AgentBrain\Modules\Artifacts\Cpts;

/**
 * ART-27 (#167): per-artifact file uploads. Asset store (table
 * `ab_artifact_uploads` + bytes under uploads/agent-brain/artifacts/<id>/),
 * the share-path preview-host endpoints (members upload from the page with
 * their ART-24 viewer token; anyone holding the link lists and downloads),
 * the MCP abilities (artifact lead / curators), the per-artifact curator
 * switch, PCP export and the Forget cascade. Everything is gated by
 * UploadMode, off by default.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'uploads';
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', [ UploadAbilities::class, 'register' ] );
		UploadsMetaBox::register();
		add_filter( 'agent_brain/export_extensions', [ UploadsExport::class, 'extend' ], 10, 2 );
		add_filter( 'agent_brain/forget_collect', [ UploadForget::class, 'collect' ], 10, 2 );
		add_action( 'agent_brain/forget_delete', [ UploadForget::class, 'delete' ], 10, 2 );
		add_action(
			'before_delete_post',
			static function ( $postId ): void {
				$post = get_post( (int) $postId );
				if ( $post instanceof \WP_Post && $post->post_type === Cpts::ARTIFACT_POST_TYPE ) {
					UploadStore::deleteAllForArtifact( (int) $postId );
				}
			}
		);
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			1 => static function (): void {
				if ( ! function_exists( 'dbDelta' ) ) {
					require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				}

				dbDelta( UploadStore::createTableSql() );
			},
		];
	}

	public function activate(): void {
		// Tables come from migrations.
	}

	public function deactivate(): void {
		// Upload data is removed only by uninstall/artifact deletion, never
		// on deactivation.
	}
}
