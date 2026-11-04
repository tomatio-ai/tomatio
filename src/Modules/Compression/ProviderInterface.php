<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

/**
 * A compression provider turns a batch of raw memory rows plus the existing
 * facts already in that scope into a validated Compression output schema v1
 * payload (see the design contracts, "Compression output
 * schema v1"). Both implementations (WpAiClientProvider, CloudProvider) MUST
 * produce output that passes OutputSchema::validate() identically (CMP-3).
 */
interface ProviderInterface {

	public function id(): string;

	/**
	 * Whether this provider completes out-of-band (CloudProvider): when true,
	 * compress() may return null to mean "submitted, result will arrive later
	 * via agent_brain/cloud_job_result" rather than a failure.
	 */
	public function isAsync(): bool;

	/**
	 * @param array<int, array<string, mixed>>            $rows          Raw working/episode rows for one (agent, scope) batch.
	 * @param array<int, array{id:int, text:string}>       $existingFacts Existing active facts already stored in that one scope.
	 * @return array<string, mixed>|null|\WP_Error Schema v1 array (still needs OutputSchema::validate()),
	 *   null only when isAsync() is true (job submitted, no output yet), or WP_Error on failure.
	 */
	public function compress( array $rows, array $existingFacts, int $agentId, int $scopeId ): array|null|\WP_Error;
}
