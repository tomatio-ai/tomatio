<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

use AgentBrain\Modules\Cloud\Client;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * CMP-3 paid-tier provider: pushes a 'compress' job to the cloud via
 * Cloud\Client::pushJob() and returns null (isAsync() === true) — the result
 * arrives later on the `agent_brain/cloud_job_result` action (fired by
 * Cloud\Rest::handleCallback()) and is applied by handleResult() below, using
 * the exact same Runner::applyValidatedOutput() write path as the
 * synchronous WP AI Client provider (CMP-3: identical output).
 */
final class CloudProvider implements ProviderInterface {

	private const OPTION_PENDING = 'ab_compression_cloud_pending';

	public function id(): string {
		return 'cloud';
	}

	public function isAsync(): bool {
		return true;
	}

	public function compress( array $rows, array $existingFacts, int $agentId, int $scopeId ): array|null|\WP_Error {
		$sourceIds = array_values( array_map( static fn( array $row ): int => (int) $row['id'], $rows ) );

		$input = [
			'rows'           => array_map(
				static fn( array $row ): array => [
					'id'      => (int) $row['id'],
					'content' => (string) $row['content'],
					'created' => (string) $row['created'],
				],
				$rows
			),
			'existing_facts' => array_map(
				static fn( array $fact ): array => [ 'id' => $fact['id'], 'text' => $fact['text'] ],
				$existingFacts
			),
			'scope_id'       => $scopeId,
			'agent_id'       => $agentId,
		];

		$pushed = Client::pushJob( 'compress', $input );
		if ( $pushed instanceof \WP_Error ) {
			return $pushed;
		}

		$this->rememberPending( $pushed['job_id'], $agentId, $scopeId, $sourceIds, $rows );

		return null;
	}

	/**
	 * @param int[]                            $sourceIds
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function rememberPending( string $jobId, int $agentId, int $scopeId, array $sourceIds, array $rows ): void {
		$pending = get_option( self::OPTION_PENDING, [] );
		if ( ! is_array( $pending ) ) {
			$pending = [];
		}

		$pending[ $jobId ] = [
			'agent_id'   => $agentId,
			'scope_id'   => $scopeId,
			'source_ids' => $sourceIds,
			'rows'       => $rows,
			'key'        => Runner::idempotencyKey( $agentId, $scopeId, $sourceIds ),
		];

		update_option( self::OPTION_PENDING, $pending, false );
	}

	/**
	 * Hooked to `agent_brain/cloud_job_result` by Module::register(). Ignores
	 * anything that isn't a 'compress' job or that we have no pending record
	 * for (e.g. a stale/duplicate callback after a prior success already
	 * consumed and removed the pending entry — that keeps this idempotent).
	 *
	 * @param mixed $output
	 */
	public static function handleResult( string $jobId, string $kind, string $status, $output ): void {
		if ( $kind !== 'compress' ) {
			return;
		}

		$pending = get_option( self::OPTION_PENDING, [] );
		if ( ! is_array( $pending ) || ! isset( $pending[ $jobId ] ) ) {
			return;
		}

		$job = $pending[ $jobId ];
		unset( $pending[ $jobId ] );
		update_option( self::OPTION_PENDING, $pending, false );

		if ( Runner::alreadyProcessed( $job['key'] ) ) {
			return; // Idempotent: a batch already applied (e.g. by a retried callback) is never reapplied.
		}

		if ( $status !== 'ok' || ! is_array( $output ) ) {
			do_action( 'agent_brain/audit', [
				'actor_id'    => $job['agent_id'],
				'event'       => 'compression.job_failed',
				'status'      => 'error',
				'object_type' => 'cloud_job',
				'object_id'   => $jobId,
				'meta'        => [ 'scope_id' => $job['scope_id'], 'reason' => 'cloud_job_' . $status ],
			] );

			return;
		}

		$validation = OutputSchema::validate( $output );
		if ( $validation instanceof \WP_Error ) {
			do_action( 'agent_brain/audit', [
				'actor_id'    => $job['agent_id'],
				'event'       => 'compression.job_failed',
				'status'      => 'error',
				'object_type' => 'cloud_job',
				'object_id'   => $jobId,
				'meta'        => [ 'scope_id' => $job['scope_id'], 'reason' => $validation->get_error_code() ],
			] );

			return; // No partial writes.
		}

		Runner::applyValidatedOutput(
			new MemoryRepository(),
			$job['agent_id'],
			$job['scope_id'],
			$job['source_ids'],
			$job['rows'],
			$output
		);
		Runner::markProcessed( $job['key'] );
	}
}
