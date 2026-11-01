<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Core\ModuleInterface;
use AgentBrain\Modules\Artifacts\Cpts;

/**
 * Artifact share links (#56-#58, #60 ab_preview_host): data layer, mint
 * authority, MCP abilities, preview-host render route, admin panel and
 * settings tab. The render route itself is entered via Preview::maybeServe.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'sharing';
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', [ ShareAbilities::class, 'register' ] );
		add_action( 'wp_abilities_api_init', [ HtmlAbilities::class, 'register' ] );
		HtmlMetaBox::register();
		ActivityMetaBox::register();
		PreviewGate::register();
		PreviewFavicon::register();
		SettingsTab::register();
		AdminPanel::register();
		InteractiveMetaBox::register();
		ViewerMetaBox::register();
		EmbedRest::register();
		ViewerRest::register();
		PageEditor::register();
		add_filter( 'agent_brain/private_mode_allow_request', [ ShareRoute::class, 'allowPrivateRequest' ], 10, 3 );
		// #219: the main-site sign-in endpoint (ShareAuth) and the permanent
		// members address (MembersRoute). Priority 1: after the private-mode
		// mu-plugin (0) and Preview::maybeServe (0). Neither needs a
		// private-mode exception: an anonymous visitor the gate sends to
		// wp-login comes back to the same URL (query included), where these
		// handlers then run signed in.
		add_action( 'template_redirect', [ ShareAuth::class, 'serve' ], 1 );
		add_action( 'template_redirect', [ MembersRoute::class, 'serve' ], 1 );
		// #754: Approve all, from the members address's review bar.
		add_action( 'admin_post_' . WaitingSections::ACTION, [ WaitingSections::class, 'handle' ] );
		// #438: old slugs keep friendly members addresses working after a rename.
		add_action( 'post_updated', [ MembersAddress::class, 'rememberOldSlug' ], 10, 3 );
		// #415: the sign-in page says why a protected link sent the viewer there.
		add_filter( 'login_message', [ SignInNote::class, 'message' ] );
		// #645: `wp tomatio share remember <url>` (and the agent-brain alias).
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'agent-brain share', ShareCli::class );
			\WP_CLI::add_command( 'tomatio share', ShareCli::class );
		}
		add_action(
			ViewAnalytics::PRUNE_HOOK,
			static function (): void {
				ViewAnalytics::prune();
			}
		);

		add_action(
			'agent_brain/artifact_unpublished',
			static function ( $artifactId, $actorId = 0, $keepShareLinks = false ): void {
				unset( $actorId );
				if ( ! $keepShareLinks ) {
					ShareLinks::revokeAllForArtifact( (int) $artifactId, 'unpublished' );
				}
			},
			10,
			3
		);
		foreach ( [ 'wp_trash_post' => 'trashed', 'before_delete_post' => 'deleted' ] as $hook => $reason ) {
			add_action(
				$hook,
				static function ( $postId ) use ( $reason ): void {
					$post = get_post( (int) $postId );
					if ( $post instanceof \WP_Post && $post->post_type === Cpts::ARTIFACT_POST_TYPE ) {
						ShareLinks::revokeAllForArtifact( (int) $postId, $reason );
					}
				}
			);
		}
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			1 => static function (): void {
				self::dbDelta( ShareLinks::createTableSql() );
			},
			2 => static function (): void {
				self::dbDelta( RateLimiter::createTableSql() );
			},
			// #59: share link kind (link|embed). Idempotent: also present on fresh installs via migration 1.
			3 => static function (): void {
				global $wpdb;

				$table = ShareLinks::table();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from Db::table(), schema check.
				$exists = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'kind'" );
				if ( $exists === null ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name from Db::table().
					$wpdb->query( "ALTER TABLE {$table} ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'link' AFTER ip_mode" );
				}
			},
			// #59: single-use embed exchange codes.
			4 => static function (): void {
				self::dbDelta( EmbedExchange::createTableSql() );
			},
			// #164 (ART-24): link viewer mode (anonymous|members). Idempotent:
			// also present on fresh installs via migration 1.
			5 => static function (): void {
				global $wpdb;

				$table = ShareLinks::table();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from Db::table(), schema check.
				$exists = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'viewer_mode'" );
				if ( $exists === null ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name from Db::table().
					$wpdb->query( "ALTER TABLE {$table} ADD COLUMN viewer_mode VARCHAR(16) NOT NULL DEFAULT 'anonymous' AFTER kind" );
				}
			},
			// #164 (ART-24): single-use viewer exchange codes.
			6 => static function (): void {
				self::dbDelta( ViewerExchange::createTableSql() );
			},
			// #221: anonymous per-day view analytics (share_views).
			7 => static function (): void {
				self::dbDelta( ViewAnalytics::createTableSql() );
			},
			// #286: link privacy (private|public). Existing links read as
			// public: they were minted to be served to anyone holding them,
			// and only NEW links default to private. Idempotent: also
			// present on fresh installs via migration 1.
			8 => static function (): void {
				global $wpdb;

				$table = ShareLinks::table();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from Db::table(), schema check.
				$exists = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'privacy'" );
				if ( $exists === null ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name from Db::table().
					$wpdb->query( "ALTER TABLE {$table} ADD COLUMN privacy VARCHAR(8) NOT NULL DEFAULT 'public' AFTER viewer_mode" );
				}
			},
			// #219: single-use sign-in tickets for private share links.
			9 => static function (): void {
				self::dbDelta( ShareTicket::createTableSql() );
			},
			// #645: the sealed token of a public link (PublicLinks), NULL
			// for every other link. Idempotent: also present on fresh
			// installs via migration 1.
			10 => static function (): void {
				global $wpdb;

				$table = ShareLinks::table();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from Db::table(), schema check.
				$exists = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'token_box'" );
				if ( $exists === null ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name from Db::table().
					$wpdb->query( "ALTER TABLE {$table} ADD COLUMN token_box VARCHAR(255) NULL AFTER open_count" );
				}
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
		if ( ! wp_next_scheduled( ViewAnalytics::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', ViewAnalytics::PRUNE_HOOK );
		}
	}

	public function deactivate(): void {
		// Share data is removed only by uninstall, never on deactivation.
		$timestamp = wp_next_scheduled( ViewAnalytics::PRUNE_HOOK );
		if ( $timestamp !== false ) {
			wp_unschedule_event( $timestamp, ViewAnalytics::PRUNE_HOOK );
		}
	}
}
