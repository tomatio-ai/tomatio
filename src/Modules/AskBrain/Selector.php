<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AskBrain;

use AgentBrain\Modules\Compression\Endpoint;

/**
 * ART-26 (#166): ask provider selection, mirroring
 * Compression\ProviderSelector's decision order with the two synchronous
 * options. The cloud connector is deliberately not offered: its provider is
 * an async batch pipeline (compression jobs), the wrong latency and shape
 * for an interactive page answer. Nothing configured -> null -> the callers
 * fail loud (503 + clear error), never silently (L26).
 *
 * The `agent_brain/ask_provider` filter lets a deployment substitute the
 * provider and the test suite inject a fake; it must return an
 * AnswerProvider (non-matching values are ignored).
 */
final class Selector {

	public const FILTER = 'agent_brain/ask_provider';

	/**
	 * @return 'openai_compatible'|'wp_ai_client'|null
	 */
	public static function decide( bool $endpointConfigured, bool $wpAiSupported ): ?string {
		if ( $endpointConfigured ) {
			return 'openai_compatible';
		}

		if ( $wpAiSupported ) {
			return 'wp_ai_client';
		}

		return null;
	}

	public static function select(): ?AnswerProvider {
		if ( function_exists( 'apply_filters' ) ) {
			$override = apply_filters( self::FILTER, null );
			if ( $override instanceof AnswerProvider ) {
				return $override;
			}
		}

		$wpAiSupported = function_exists( 'wp_supports_ai' ) && \wp_supports_ai();

		return match ( self::decide( Endpoint::isConfigured(), $wpAiSupported ) ) {
			'openai_compatible' => new OpenAiEndpointProvider(),
			'wp_ai_client'      => new WpAiProvider(),
			default             => null,
		};
	}
}
