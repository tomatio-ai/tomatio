<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * Whether the WordPress AI client can actually answer (#855).
 *
 * On WordPress 7, wp_supports_ai() is true by default: it only says AI
 * features are not switched off. A site with no AI provider set up still
 * gets true, and every wp_ai_client_prompt() call then fails with
 * `prompt_invalid_argument` before any request goes out. So the WP AI client
 * counts as available only when AI is supported AND the AI client registry
 * has at least one provider with credentials.
 *
 * The `tomatio/wp_ai_has_provider` filter overrides the provider half (tests,
 * or a site whose provider the registry cannot see).
 */
final class WpAi {

	public const FILTER_HAS_PROVIDER = 'tomatio/wp_ai_has_provider';

	public static function available(): bool {
		if ( ! function_exists( 'wp_supports_ai' ) || ! \wp_supports_ai() ) {
			return false;
		}

		return (bool) apply_filters( self::FILTER_HAS_PROVIDER, self::hasConfiguredProvider() );
	}

	/** Whether any provider registered with the WordPress AI client has credentials. */
	public static function hasConfiguredProvider(): bool {
		// The AI client WordPress 7 bundles; absent on older WordPress.
		$client = 'WordPress\\AiClient\\AiClient';
		if ( ! class_exists( $client ) ) {
			return false;
		}

		try {
			$registry = $client::defaultRegistry();
			if ( ! is_object( $registry ) || ! method_exists( $registry, 'getRegisteredProviderIds' ) || ! method_exists( $registry, 'isProviderConfigured' ) ) {
				return false;
			}

			foreach ( (array) $registry->getRegisteredProviderIds() as $id ) {
				if ( is_string( $id ) && $registry->isProviderConfigured( $id ) ) {
					return true;
				}
			}
		} catch ( \Throwable $e ) {
			return false;
		}

		return false;
	}
}
