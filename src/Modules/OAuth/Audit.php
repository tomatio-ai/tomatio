<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

/**
 * Thin emitter for `agent_brain/audit` events (grant/token/revoke). Never
 * includes a raw token/code value - only client_id, user_id and outcome.
 */
final class Audit {

	/**
	 * @param array<string, mixed> $meta
	 */
	public static function emit(int $actorId, string $event, string $status, array $meta = []): void {
		if (! function_exists('do_action')) {
			return;
		}

		do_action('agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => $event,
			'status'      => $status,
			'object_type' => 'oauth_client',
			'object_id'   => isset($meta['client_id']) ? (string) $meta['client_id'] : null,
			'meta'        => $meta,
		]);
	}
}
