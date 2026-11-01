<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AskBrain;

/**
 * ART-26 (#166): ask provider on WordPress core's AI Client
 * (wp_ai_client_prompt()), the free-tier fallback the compression stack
 * uses. Token usage is not reported, so the cost caps account the call by
 * character estimate (Spend::estimateTokens).
 */
final class WpAiProvider implements AnswerProvider {

	public function id(): string {
		return 'wp_ai_client';
	}

	/**
	 * @return array{text:string, prompt_tokens:int, completion_tokens:int, model:string}|\WP_Error
	 */
	public function answer( string $system, string $user ): array|\WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) || ! \wp_supports_ai() ) {
			return new \WP_Error( 'ab_ask_wp_ai_unavailable', 'WordPress AI Client is not available or not supported in this environment.' );
		}

		$result = \wp_ai_client_prompt( $user )
			->using_system_instruction( $system )
			->generate_text();

		if ( $result instanceof \WP_Error ) {
			return new \WP_Error( 'ab_ask_wp_ai_failed', 'WordPress AI Client could not answer the question.' );
		}

		$text = trim( (string) $result );

		return $text === ''
			? new \WP_Error( 'ab_ask_wp_ai_empty', 'WordPress AI Client returned an empty answer.' )
			: [
				'text'              => $text,
				'prompt_tokens'     => 0,
				'completion_tokens' => 0,
				'model'             => 'wp_ai_client',
			];
	}
}
