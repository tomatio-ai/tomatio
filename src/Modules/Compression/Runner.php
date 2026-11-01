<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

use AgentBrain\Modules\Memory\InsertFailed;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * CMP-1/CMP-4: batched compression runner. Processes small (agent, scope)
 * groups under a wall-clock budget, checkpointing via BatchReader's
 * "not yet linked" query (nothing here needs its own cursor: a group that
 * times out mid-run simply isn't marked processed, so the next run picks it
 * up again) and de-duplicating via a per-source-batch idempotency key so a
 * resumed/retried run never creates duplicate facts (CMP-4).
 *
 * applyValidatedOutput()/alreadyProcessed()/markProcessed() are public
 * static so CloudProvider's async result handler (agent_brain/cloud_job_result)
 * can apply a result that arrives after this run already returned, using the
 * exact same write path as the synchronous WP AI Client path (CMP-3: identical
 * output handling regardless of provider).
 */
final class Runner {

	public const CRON_HOOK = 'agent_brain_compression_run';

	public const OPTION_BUDGET_SECONDS = 'ab_compression_budget_seconds';
	public const DEFAULT_BUDGET_SECONDS = 20;

	private const OPTION_PROCESSED_BATCHES = 'ab_compression_processed_batches';
	private const PROCESSED_BATCHES_MAX    = 500;

	public static function run(): void {
		( new self() )->process( new MemoryRepository(), ProviderSelector::select() );
	}

	public function process( MemoryRepository $repository, ?ProviderInterface $provider ): void {
		if ( $provider === null ) {
			do_action( 'agent_brain/audit', [
				'actor_id' => 0,
				'event'    => 'compression.disabled',
				'status'   => 'denied',
				'meta'     => [ 'reason' => (string) get_option( ProviderSelector::OPTION_DEGRADED_REASON, 'unknown' ) ],
			] );

			return;
		}

		$budgetSeconds = (int) get_option( self::OPTION_BUDGET_SECONDS, self::DEFAULT_BUDGET_SECONDS );
		$deadline      = microtime( true ) + max( 1, $budgetSeconds );

		$groups = [];
		foreach ( BatchReader::pendingRows() as $row ) {
			$key            = ( (int) $row['agent_id'] ) . ':' . ( (int) $row['scope_id'] );
			$groups[ $key ][] = $row;
		}

		foreach ( $groups as $groupRows ) {
			if ( microtime( true ) >= $deadline ) {
				return; // CMP-4: unfinished groups are simply left unlinked; next run's BatchReader query re-selects them.
			}

			$agentId = (int) $groupRows[0]['agent_id'];
			$scopeId = (int) $groupRows[0]['scope_id'];

			try {
				$this->processGroup( $repository, $provider, $agentId, $scopeId, $groupRows );
			} catch ( InsertFailed $e ) {
				// Not marked processed, so the next run tries this group again (#183).
				do_action( 'agent_brain/audit', [
					'actor_id' => $agentId,
					'event'    => 'compression.write_failed',
					'status'   => 'error',
					'meta'     => [ 'scope_id' => $scopeId, 'source_count' => count( $groupRows ) ],
				] );
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function processGroup( MemoryRepository $repository, ProviderInterface $provider, int $agentId, int $scopeId, array $rows ): void {
		$sourceIds = array_values( array_map( static fn( array $row ): int => (int) $row['id'], $rows ) );
		sort( $sourceIds );

		$idempotencyKey = self::idempotencyKey( $agentId, $scopeId, $sourceIds );

		if ( self::alreadyProcessed( $idempotencyKey ) ) {
			return; // CMP-4: idempotent rerun — never duplicates facts for the same source batch.
		}

		$estimatedTokens = self::estimateTokens( $rows );
		$spendCheck      = SpendGuard::checkAndReserve( $estimatedTokens );
		if ( $spendCheck instanceof \WP_Error ) {
			do_action( 'agent_brain/audit', [
				'actor_id' => $agentId,
				'event'    => 'compression.spend_blocked',
				'status'   => 'denied',
				'meta'     => [ 'scope_id' => $scopeId, 'reason' => $spendCheck->get_error_code() ],
			] );

			return;
		}

		$existingFacts = BatchReader::existingFacts( $agentId, $scopeId );

		try {
			$output = $provider->compress( $rows, $existingFacts, $agentId, $scopeId );
		} catch ( \Throwable $e ) {
			do_action( 'agent_brain/audit', [
				'actor_id' => $agentId,
				'event'    => 'compression.job_failed',
				'status'   => 'error',
				'meta'     => [ 'scope_id' => $scopeId, 'reason' => 'exception', 'message' => $e->getMessage() ],
			] );

			return;
		}

		if ( $output === null ) {
			if ( $provider->isAsync() ) {
				// CloudProvider has pushed the job and will resolve it later via
				// agent_brain/cloud_job_result -> CloudProvider::handleResult(),
				// which calls applyValidatedOutput()/markProcessed() itself.
				return;
			}

			$output = new \WP_Error( 'ab_compression_provider_no_output', 'Provider returned no output and is not async.' );
		}

		if ( $output instanceof \WP_Error ) {
			do_action( 'agent_brain/audit', [
				'actor_id' => $agentId,
				'event'    => 'compression.job_failed',
				'status'   => 'error',
				'meta'     => [ 'scope_id' => $scopeId, 'reason' => $output->get_error_code() ],
			] );

			return;
		}

		$validation = OutputSchema::validate( $output );
		if ( $validation instanceof \WP_Error ) {
			do_action( 'agent_brain/audit', [
				'actor_id' => $agentId,
				'event'    => 'compression.job_failed',
				'status'   => 'error',
				'meta'     => [ 'scope_id' => $scopeId, 'reason' => $validation->get_error_code() ],
			] );

			return; // No partial writes: nothing has been persisted before this point.
		}

		self::applyValidatedOutput( $repository, $agentId, $scopeId, $sourceIds, $rows, $output );
		self::markProcessed( $idempotencyKey );
	}

	/**
	 * Persists a validated schema-v1 output: stores the episode summary,
	 * applies add/update/skip per fact, links everything to its sources, and
	 * fires agent_brain/fact_extracted per stored fact. Used by both the
	 * synchronous path above and CloudProvider's async callback handler.
	 *
	 * @param int[]                             $sourceIds
	 * @param array<int, array<string, mixed>>  $rows
	 * @param array<string, mixed>              $output
	 */
	public static function applyValidatedOutput( MemoryRepository $repository, int $agentId, int $scopeId, array $sourceIds, array $rows, array $output ): void {
		$summaryId = $repository->insert( [
			'agent_id'    => $agentId,
			'scope_id'    => $scopeId,
			'type'        => 'episode',
			'content'     => $output['summary'],
			'importance'  => $output['importance'],
			'search_keys' => implode( ' ', $output['search_keys'] ),
			'source_ids'  => $sourceIds,
		] );
		$repository->link( 'memory', $summaryId, $sourceIds );

		$storedFactCount = self::applyFacts( $repository, $agentId, $scopeId, $sourceIds, $output );

		self::logRatio( $agentId, $scopeId, $rows, $output, $storedFactCount );
	}

	/**
	 * CMP-3/MEM-4 shared call site: enforces the CMP-5 spend cap + hourly
	 * limit, invokes the provider, validates its output, and applies the
	 * facts (add/update/skip) exactly like the batch path above — but
	 * without inserting an 'episode' row, because MEM-4's rollover call
	 * site (Module::onRolloverCompress) stores the returned summary text as
	 * its own episode row itself. Ratio logging still runs here so
	 * rollover-triggered compressions are audited per job like batch ones
	 * (CMP-5). Returns the validated summary text, or null on any
	 * degraded/blocked/invalid outcome so the caller can fall back.
	 *
	 * Guarded by the same idempotencyKey()/alreadyProcessed()/markProcessed()
	 * mechanism as processGroup() (CMP-1/CMP-4): Rollover::rolloverGroup()
	 * calls the agent_brain/rollover_compress filter — which reaches this
	 * method and already writes facts via applyFacts() — *before* its own
	 * episode-insert/link/mark-rolled-up sequence, and that sequence is
	 * intentionally non-transactional (Rollover.php: "no manual SQL
	 * transaction here on purpose"). Without a dedup key here, a thrown
	 * exception from that later sequence, or two overlapping rollover cron
	 * runs reading the same not-yet-rolled-up rows, would re-invoke this
	 * method for the same source rows and apply the same facts twice.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	public static function compressForRollover( MemoryRepository $repository, ProviderInterface $provider, int $agentId, int $scopeId, array $rows ): ?string {
		$sourceIds = array_values( array_map( static fn( array $row ): int => (int) $row['id'], $rows ) );
		sort( $sourceIds );

		$idempotencyKey = self::idempotencyKey( $agentId, $scopeId, $sourceIds );
		if ( self::alreadyProcessed( $idempotencyKey ) ) {
			// CMP-4: idempotent rerun — never duplicates facts for the same
			// rollover source batch. The caller (Rollover::rolloverGroup) will
			// still attempt its own episode-insert/mark-rolled-up retry, which
			// is safe on its own terms since those rows are keyed by id.
			return null;
		}

		$estimatedTokens = self::estimateTokens( $rows );
		$spendCheck      = SpendGuard::checkAndReserve( $estimatedTokens );
		if ( $spendCheck instanceof \WP_Error ) {
			do_action( 'agent_brain/audit', [
				'actor_id' => $agentId,
				'event'    => 'compression.spend_blocked',
				'status'   => 'denied',
				'meta'     => [ 'scope_id' => $scopeId, 'reason' => $spendCheck->get_error_code(), 'source' => 'rollover' ],
			] );

			return null;
		}

		$existingFacts = BatchReader::existingFacts( $agentId, $scopeId );

		try {
			$output = $provider->compress( $rows, $existingFacts, $agentId, $scopeId );
		} catch ( \Throwable $e ) {
			do_action( 'agent_brain/audit', [
				'actor_id' => $agentId,
				'event'    => 'compression.job_failed',
				'status'   => 'error',
				'meta'     => [ 'scope_id' => $scopeId, 'reason' => 'exception', 'message' => $e->getMessage(), 'source' => 'rollover' ],
			] );

			return null;
		}

		$validation = is_array( $output ) ? OutputSchema::validate( $output ) : new \WP_Error( 'ab_compression_provider_no_output', 'Provider returned a non-array output.' );
		if ( $validation instanceof \WP_Error ) {
			do_action( 'agent_brain/audit', [
				'actor_id' => $agentId,
				'event'    => 'compression.job_failed',
				'status'   => 'error',
				'meta'     => [ 'scope_id' => $scopeId, 'reason' => $validation->get_error_code(), 'source' => 'rollover' ],
			] );

			return null; // No partial writes: nothing has been persisted before this point.
		}

		$storedFactCount = self::applyFacts( $repository, $agentId, $scopeId, $sourceIds, $output );
		self::logRatio( $agentId, $scopeId, $rows, $output, $storedFactCount );
		self::markProcessed( $idempotencyKey );

		return $output['summary'];
	}

	/**
	 * Applies add/update/skip per fact (contract requirement: update targets
	 * must be in the same scope and type 'fact', otherwise treated as add
	 * with the reason recorded), links every stored fact to its sources, and
	 * fires agent_brain/fact_extracted per stored fact. Shared by the batch
	 * path (applyValidatedOutput) and the MEM-4 rollover path
	 * (compressForRollover) so both apply facts identically (CMP-3).
	 *
	 * @param int[]                 $sourceIds
	 * @param array<string, mixed>  $output
	 */
	private static function applyFacts( MemoryRepository $repository, int $agentId, int $scopeId, array $sourceIds, array $output ): int {
		$storedFactCount = 0;

		foreach ( $output['facts'] as $fact ) {
			$op       = $fact['op'];
			$targetId = $fact['target_id'] ?? null;

			if ( $op === 'skip' ) {
				continue;
			}

			if ( $op === 'update' ) {
				$target = $targetId !== null ? $repository->get( (int) $targetId, [ $scopeId ] ) : null;

				if ( $target === null || $target['type'] !== 'fact' ) {
					// Target must be in the same scope and type fact — otherwise
					// treat as add and record why (contract requirement).
					$op = 'add';
					do_action( 'agent_brain/audit', [
						'actor_id' => $agentId,
						'event'    => 'compression.update_target_invalid',
						'status'   => 'ok',
						'meta'     => [ 'scope_id' => $scopeId, 'target_id' => $targetId ],
					] );
				}
			}

			if ( $op === 'update' ) {
				$factId = (int) $targetId;
				self::updateFact( $factId, $fact );
			} else {
				$factId = $repository->insert( [
					'agent_id'    => $agentId,
					'scope_id'    => $scopeId,
					'type'        => 'fact',
					'content'     => $fact['text'],
					'importance'  => $fact['importance'],
					'search_keys' => implode( ' ', $fact['search_keys'] ),
					'source_ids'  => $sourceIds,
				] );
			}

			$repository->link( 'memory', $factId, $sourceIds );
			++$storedFactCount;

			do_action( 'agent_brain/fact_extracted', $factId, [
				'text'        => $fact['text'],
				'importance'  => $fact['importance'],
				'scope_id'    => $scopeId,
				'agent_id'    => $agentId,
				'search_keys' => $fact['search_keys'],
			] );
		}

		return $storedFactCount;
	}

	/**
	 * @param array<string, mixed> $fact
	 */
	private static function updateFact( int $factId, array $fact ): void {
		global $wpdb;

		$table = MemoryRepository::memoryTable();

		$wpdb->update(
			$table,
			[
				'content'     => $fact['text'],
				'importance'  => $fact['importance'],
				'search_keys' => implode( ' ', $fact['search_keys'] ),
			],
			[ 'id' => $factId ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<string, mixed>             $output
	 */
	private static function logRatio( int $agentId, int $scopeId, array $rows, array $output, int $storedFactCount ): void {
		$rawTokens = self::estimateTokens( $rows );

		$factTextChars = array_sum( array_map(
			static fn( array $fact ): int => strlen( (string) ( $fact['text'] ?? '' ) ),
			$output['facts']
		) );
		$summaryTokens = (int) ceil( ( strlen( (string) $output['summary'] ) + $factTextChars ) / 4 );

		$ratio = $summaryTokens > 0 ? round( $rawTokens / $summaryTokens, 2 ) : null;

		do_action( 'agent_brain/audit', [
			'actor_id' => $agentId,
			'event'    => 'compression.job_completed',
			'status'   => 'ok',
			'meta'     => [
				'scope_id'       => $scopeId,
				'raw_tokens'     => $rawTokens,
				'summary_tokens' => $summaryTokens,
				'ratio'          => $ratio,
				'facts_stored'   => $storedFactCount,
			],
		] );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private static function estimateTokens( array $rows ): int {
		$chars = array_sum( array_map( static fn( array $row ): int => strlen( (string) $row['content'] ), $rows ) );

		return (int) ceil( $chars / 4 );
	}

	/** @param int[] $sourceIds */
	public static function idempotencyKey( int $agentId, int $scopeId, array $sourceIds ): string {
		sort( $sourceIds );

		return hash( 'sha256', $agentId . ':' . $scopeId . ':' . implode( ',', $sourceIds ) );
	}

	public static function alreadyProcessed( string $key ): bool {
		$processed = get_option( self::OPTION_PROCESSED_BATCHES, [] );

		return is_array( $processed ) && in_array( $key, $processed, true );
	}

	public static function markProcessed( string $key ): void {
		$processed = get_option( self::OPTION_PROCESSED_BATCHES, [] );
		if ( ! is_array( $processed ) ) {
			$processed = [];
		}

		$processed[] = $key;

		if ( count( $processed ) > self::PROCESSED_BATCHES_MAX ) {
			$processed = array_slice( $processed, -self::PROCESSED_BATCHES_MAX );
		}

		update_option( self::OPTION_PROCESSED_BATCHES, $processed, false );
	}
}
