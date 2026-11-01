<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

use AgentBrain\Modules\Cloud\Connector;

/**
 * CMP-3 provider selection: the owner's own OpenAI-compatible endpoint if one
 * is configured (an explicit choice with their own key), else cloud if
 * connected+enabled, else WP AI client if supported, else compression is
 * disabled with an admin-visible degraded
 * notice (never silently, L26).
 *
 * decide() is pure and WP-free so it's unit-testable; select() wires it to
 * real WordPress/Cloud state and records the degraded reason for the admin
 * notice (see Module::adminNotices()).
 */
final class ProviderSelector {

	public const OPTION_DEGRADED_REASON = 'ab_compression_degraded_reason';
	public const REASON_NO_PROVIDER     = 'ab_compression_no_provider';

	/**
	 * @return 'openai_compatible'|'cloud'|'wp_ai_client'|null
	 */
	public static function decide( bool $cloudConnected, bool $cloudEnabled, bool $wpAiSupported, bool $endpointConfigured = false ): ?string {
		if ( $endpointConfigured ) {
			return 'openai_compatible';
		}

		if ( $cloudConnected && $cloudEnabled ) {
			return 'cloud';
		}

		if ( $wpAiSupported ) {
			return 'wp_ai_client';
		}

		return null;
	}

	public static function select(): ?ProviderInterface {
		$cloudEnabled   = (bool) get_option( 'ab_cloud_enabled', false );
		$cloudConnected = class_exists( Connector::class ) && Connector::isConnected();
		$wpAiSupported  = function_exists( 'wp_supports_ai' ) && \wp_supports_ai();

		$decision = self::decide( $cloudConnected, $cloudEnabled, $wpAiSupported, Endpoint::isConfigured() );

		if ( $decision === null ) {
			update_option( self::OPTION_DEGRADED_REASON, self::REASON_NO_PROVIDER, false );

			return null;
		}

		delete_option( self::OPTION_DEGRADED_REASON );

		return match ( $decision ) {
			'openai_compatible' => new OpenAiCompatibleProvider(),
			'cloud'             => new CloudProvider(),
			default             => new WpAiClientProvider(),
		};
	}
}
