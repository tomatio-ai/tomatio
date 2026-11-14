<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AskBrain;

/**
 * An ask provider answers one question (system + user prompt built by
 * Asker) with plain text plus token usage for the cost caps. Both
 * implementations share the security posture of the compression providers:
 * no key, endpoint internals, or raw transport error ever reaches an error
 * message, log line, or audit row.
 */
interface AnswerProvider {

	public function id(): string;

	/**
	 * @return array{text:string, prompt_tokens:int, completion_tokens:int, model:string}|\WP_Error
	 */
	public function answer( string $system, string $user ): array|\WP_Error;
}
