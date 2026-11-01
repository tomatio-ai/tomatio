<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

use AgentBrain\Core\ModuleInterface;
use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * Self-registering "compression" module (Wave 2, E04). Turns raw
 * working/episode memory into episode summaries + facts via a pluggable
 * provider (WP AI Client or Cloud, CMP-3). Uses no migrations of its own:
 * everything is stored in the `ab_memory`/`ab_memory_links` tables Memory
 * already owns.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'compression';
	}

	/**
	 * User meta key: set once a curator dismisses the free-tier
	 * unencrypted-key warning on the TomaTío Settings screen. Per-user
	 * (not per-site) so one curator dismissing it never hides it from
	 * another.
	 */
	public const DISMISSED_KEY_NOTICE_META = 'ab_dismissed_compression_key_notice';

	public const DISMISS_KEY_NOTICE_ACTION = 'agent_brain_dismiss_compression_key_notice';

	public function register(): void {
		add_action( Runner::CRON_HOOK, [ Runner::class, 'run' ] );
		add_action( 'agent_brain/cloud_tick', [ Runner::class, 'run' ] );
		add_action( 'agent_brain/cloud_job_result', [ CloudProvider::class, 'handleResult' ], 10, 4 );

		// MEM-4 hook (contracts.md): rollover uses compression when a
		// provider is available; returning null falls back to Memory's
		// built-in digest, so this never breaks rollover when compression is
		// disabled/degraded (L26 fail loud, not fail broken).
		add_filter( 'agent_brain/rollover_compress', [ self::class, 'onRolloverCompress' ], 10, 4 );

		EndpointSettings::register();

		add_action( 'admin_notices', [ self::class, 'adminNotices' ] );
		add_action( 'admin_post_' . self::DISMISS_KEY_NOTICE_ACTION, [ self::class, 'handleDismissKeyNotice' ] );

		if ( ! wp_next_scheduled( Runner::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', Runner::CRON_HOOK );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $workingRows
	 */
	public static function onRolloverCompress( ?string $episode, array $workingRows, int $agentId, int $scopeId ): ?string {
		$provider = ProviderSelector::select();
		if ( $provider === null || $provider->isAsync() ) {
			// Degraded (no provider) or async (cloud) providers cannot produce
			// a synchronous episode text for MEM-4's rollover call site —
			// fall back to Memory's built-in digest rather than blocking or
			// silently dropping rollover (L26).
			return null;
		}

		// Delegate into Runner's shared apply path so this call site is
		// fail-closed on the CMP-5 spend cap/hourly limit and ratio-logged
		// exactly like Runner::processGroup()'s batch path (contract: every
		// provider->compress() call site enforces CMP-5).
		return Runner::compressForRollover( new MemoryRepository(), $provider, $agentId, $scopeId, $workingRows );
	}

	public static function adminNotices(): void {
		if ( ! current_user_can( 'ab_manage' ) ) {
			return;
		}

		$degraded = get_option( ProviderSelector::OPTION_DEGRADED_REASON );
		if ( is_string( $degraded ) && $degraded !== '' ) {
			printf(
				'<div class="notice notice-warning" role="status"><p>%s</p></div>',
				esc_html__( 'TomaTío: compression is disabled — no AI provider is available (no OpenAI-compatible endpoint, no cloud connection, and the WordPress AI Client is not supported in this environment).', 'agent-brain' )
			);
		}

		// An endpoint is saved but its key can no longer be decrypted: say so
		// loudly instead of silently falling back to another provider (L26).
		$keyStatus = Endpoint::keyStatus();
		if ( Endpoint::baseUrl() !== '' && ( $keyStatus === Endpoint::KEY_CHANGED || $keyStatus === Endpoint::KEY_BROKEN ) ) {
			printf(
				'<div class="notice notice-error" role="alert"><p>%s</p></div>',
				esc_html__( 'TomaTío: the API key for your OpenAI-compatible endpoint cannot be read (the site key material changed or the value was modified), so the endpoint is not being used. Enter the key again in Settings.', 'agent-brain' )
			);
		}

		self::maybeRenderKeyNotice();
	}

	/**
	 * The free-tier unencrypted-key warning used to render on every Agent
	 * Brain admin screen (curators saw it on the Overview, Review Queue,
	 * etc. — not just where it's actionable). It now only renders on the
	 * TomaTío Settings screen, and is dismissible per user, without
	 * changing the capability gate above (the "existing condition" for
	 * whether it applies at all stays current_user_can('ab_manage')).
	 */
	private static function maybeRenderKeyNotice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( (string) $screen->id, 'agent-brain-settings' ) === false ) {
			return;
		}

		// #52: only on the Cloud tab (where the provider / compression
		// setup lives), and only when a provider key is actually stored.
		if ( SettingsHub::currentTab() !== SettingsHub::TAB_CLOUD || ! self::providerKeyStored() ) {
			return;
		}

		if ( (bool) get_user_meta( get_current_user_id(), self::DISMISSED_KEY_NOTICE_META, true ) ) {
			return;
		}

		$dismissUrl = wp_nonce_url(
			add_query_arg( 'action', self::DISMISS_KEY_NOTICE_ACTION, admin_url( 'admin-post.php' ) ),
			self::DISMISS_KEY_NOTICE_ACTION
		);

		printf(
			'<div class="notice notice-warning ab-compression-key-notice" role="status"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'TomaTío: free-tier compression stores your AI provider key via WordPress core unencrypted.', 'agent-brain' ),
			esc_url( $dismissUrl ),
			esc_html__( 'Dismiss', 'agent-brain' )
		);
	}

	/**
	 * True when the WordPress AI Client has a provider API key saved. Core
	 * keeps those as `connectors_ai_<provider>_api_key` options (stored in
	 * wp_options, i.e. unencrypted). The `agent_brain/compression_provider_key_stored`
	 * filter lets a site override the detection.
	 */
	public static function providerKeyStored(): bool {
		global $wpdb;

		$found = false;
		if ( isset( $wpdb ) ) {
			$like = $wpdb->esc_like( 'connectors_ai_' ) . '%' . $wpdb->esc_like( '_api_key' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded existence check on the options table.
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value <> ''", $like ) );
			$found = (int) $count > 0;
		}

		return (bool) apply_filters( 'agent_brain/compression_provider_key_stored', $found );
	}

	/**
	 * Nonce'd admin-post GET action (no AJAX/JS build) that records the
	 * per-user dismissal and redirects back where the curator was.
	 */
	public static function handleDismissKeyNotice(): void {
		if ( ! current_user_can( 'ab_manage' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'agent-brain' ) );
		}

		check_admin_referer( self::DISMISS_KEY_NOTICE_ACTION );

		update_user_meta( get_current_user_id(), self::DISMISSED_KEY_NOTICE_META, 1 );

		$referer = wp_get_referer();
		wp_safe_redirect( $referer !== false ? $referer : admin_url() );
		exit;
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [];
	}

	public function activate(): void {
		if ( ! wp_next_scheduled( Runner::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', Runner::CRON_HOOK );
		}
	}

	public function deactivate(): void {
		$timestamp = wp_next_scheduled( Runner::CRON_HOOK );
		if ( $timestamp !== false ) {
			wp_unschedule_event( $timestamp, Runner::CRON_HOOK );
		}
	}
}
