<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

use AgentBrain\Core\Untrusted;

/**
 * CMP-3 free-tier provider: WordPress core's AI Client
 * (wp_ai_client_prompt(), see vendor/wordpress-core/wp-includes/ai-client.php
 * and ai-client/class-wp-ai-client-prompt-builder.php), using the builder's
 * JSON-schema structured output (as_json_response()) so the model is
 * constrained to the compression output schema v1 shape.
 */
final class WpAiClientProvider implements ProviderInterface {

	public function id(): string {
		return 'wp_ai_client';
	}

	public function isAsync(): bool {
		return false;
	}

	public function compress( array $rows, array $existingFacts, int $agentId, int $scopeId ): array|null|\WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) || ! \wp_supports_ai() ) {
			return new \WP_Error( 'ab_compression_wp_ai_unavailable', 'WordPress AI Client is not available or not supported in this environment.' );
		}

		try {
			$prompt = PromptBuilder::build( $rows, $existingFacts, $agentId, $scopeId );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'ab_compression_prompt_build_failed', $e->getMessage() );
		}

		$result = \wp_ai_client_prompt( $prompt )
			->using_system_instruction( Untrusted::preamble() )
			->as_json_response( self::jsonSchema() )
			->generate_text();

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$decoded = json_decode( (string) $result, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'ab_compression_wp_ai_bad_json', 'WordPress AI Client returned output that was not valid JSON.' );
		}

		return $decoded;
	}

	/**
	 * JSON schema handed to as_json_response() so the client requests
	 * structured output matching the compression output schema v1 shape
	 * whenever the underlying model supports it.
	 *
	 * @return array<string, mixed>
	 */
	public static function jsonSchema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'v', 'summary', 'importance', 'search_keys', 'facts' ],
			'properties' => [
				'v'           => [ 'type' => 'integer', 'const' => 1 ],
				'summary'     => [ 'type' => 'string' ],
				'importance'  => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ],
				'search_keys' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'facts'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'required'   => [ 'op', 'text', 'target_id', 'importance', 'search_keys' ],
						'properties' => [
							'op'          => [ 'type' => 'string', 'enum' => [ 'add', 'update', 'skip' ] ],
							'text'        => [ 'type' => 'string' ],
							'target_id'   => [ 'type' => [ 'integer', 'null' ] ],
							'importance'  => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ],
							'search_keys' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						],
					],
				],
			],
		];
	}
}
