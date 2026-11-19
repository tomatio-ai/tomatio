<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

use AgentBrain\Core\Untrusted;

/**
 * Builds the compression prompt text handed to a provider. Every piece of
 * memory content (existing facts and raw rows alike) is framed via
 * Core\Untrusted per the binding contract ("Every prompt or briefing that
 * includes memory MUST use these") — memory content is data, never
 * instructions.
 */
final class PromptBuilder {

	/**
	 * @param array<int, array<string, mixed>>      $rows
	 * @param array<int, array{id:int, text:string}> $existingFacts
	 */
	public static function build( array $rows, array $existingFacts, int $agentId, int $scopeId ): string {
		if ( ! class_exists( Untrusted::class ) ) {
			// Recall unit (Wave 2) owns Core\Untrusted; if it hasn't landed yet
			// this fails loud rather than silently sending unframed memory
			// content to an LLM (L26).
			throw new \RuntimeException( 'AgentBrain\\Core\\Untrusted is not available yet; Compression cannot safely frame memory content.' );
		}

		$lines   = [ Untrusted::preamble(), '' ];
		$lines[] = 'Existing facts already stored in this scope (id => content):';

		foreach ( $existingFacts as $fact ) {
			$lines[] = '- ' . Untrusted::wrap( (string) $fact['text'], [
				'source'         => 'fact',
				'agent_id'       => (string) $agentId,
				'memory_id'      => (string) $fact['id'],
				'agent_written'  => '0',
			] );
		}

		if ( $existingFacts === [] ) {
			$lines[] = '(none yet)';
		}

		$lines[] = '';
		$lines[] = 'New raw memory rows to compress:';

		foreach ( $rows as $row ) {
			$lines[] = '- ' . Untrusted::wrap( (string) $row['content'], [
				'source'        => (string) ( $row['type'] ?? 'working' ),
				'agent_id'      => (string) $agentId,
				'memory_id'     => (string) $row['id'],
				'agent_written' => (string) ( (int) ( $row['agent_written'] ?? 1 ) ),
			] );
		}

		$lines[] = '';
		$lines[] = 'Task: summarize the new rows into one episode summary. For every fact worth ' .
			'remembering, decide add (new fact), update (revise an existing fact id from the list ' .
			'above), or skip (nothing changed). Only use scope-' . $scopeId . ' fact ids as target_id. ' .
			'Respond with exactly the compression output schema v1 JSON.';

		return implode( "\n", $lines );
	}
}
