<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Audit;

/**
 * FGT-2: the `agent_brain/audit_write` filter — a SYNCHRONOUS counterpart to
 * the fire-and-forget `agent_brain/audit` action. Callers that must not
 * proceed unless the audit row is durably persisted (Forget, before it
 * deletes anything) call `apply_filters('agent_brain/audit_write', false, $event)`
 * and only continue when the result is exactly `true`.
 *
 * Never throws: a malformed event or a failed insert both simply return
 * `false` so the caller's abort path is uniform.
 */
final class AuditWrite {

	/**
	 * @param mixed                $default Always false; ignored other than as the filter's base value.
	 * @param array<string, mixed> $event
	 */
	public static function write( $default, array $event ): bool {
		$result = EventValidator::validate( $event );
		if ( ! $result->valid ) {
			return false;
		}

		global $wpdb;

		$inserted = $wpdb->insert(
			Repository::tableName(),
			[
				'ts'          => current_time( 'mysql', true ),
				'actor_id'    => $event['actor_id'],
				'event'       => $event['event'],
				'status'      => $event['status'],
				'object_type' => $event['object_type'] ?? null,
				'object_id'   => $event['object_id'] ?? null,
				'args_hash'   => $event['args_hash'] ?? null,
				'meta'        => isset( $event['meta'] ) ? wp_json_encode( $event['meta'] ) : null,
				'duration_ms' => isset( $event['duration_ms'] ) ? (int) round( (float) $event['duration_ms'] ) : null,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		return $inserted !== false && $inserted > 0;
	}
}
