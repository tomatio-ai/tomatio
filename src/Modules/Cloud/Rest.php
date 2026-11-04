<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Cloud;

use AgentBrain\Core\RestNamespaces;

/**
 * REST surface the cloud calls back into: signed callback + tick. The
 * permission_callback IS the security boundary here (never __return_true):
 * it verifies the envelope against the persisted cloud public key and
 * rejects everything if the site isn't connected/enabled.
 */
final class Rest {

	public const NAMESPACE = 'agent-brain/v1';

	private const ENVELOPE_PARAM = '_ab_cloud_envelope';

	public static function registerRoutes(): void {
		RestNamespaces::register(
			
			'/cloud/callback',
			[
				'methods'             => 'POST',
				'callback'            => [self::class, 'handleCallback'],
				'permission_callback' => [self::class, 'verifyRequest'],
			]
		);

		RestNamespaces::register(
			
			'/cloud/tick',
			[
				'methods'             => 'POST',
				'callback'            => [self::class, 'handleTick'],
				'permission_callback' => [self::class, 'verifyRequest'],
			]
		);
	}

	public static function verifyRequest(\WP_REST_Request $request): bool|\WP_Error {
		if (! (bool) get_option('ab_cloud_enabled', false) || ! Connector::isConnected()) {
			self::audit('cloud.rest_request', 'denied', ['reason' => 'not_connected']);

			return new \WP_Error('ab_cloud_not_connected', 'Site is not connected to the cloud.', ['status' => 403]);
		}

		$cloudPk = get_option('ab_cloud_cloud_pk');

		if (! is_string($cloudPk) || $cloudPk === '') {
			self::audit('cloud.rest_request', 'denied', ['reason' => 'missing_cloud_pk']);

			return new \WP_Error('ab_cloud_not_connected', 'Site is not connected to the cloud.', ['status' => 403]);
		}

		$envelope = json_decode($request->get_body(), true);

		if (! is_array($envelope)) {
			self::audit('cloud.rest_request', 'denied', ['reason' => 'malformed_body']);

			return new \WP_Error('ab_cloud_bad_request', 'Request body is not a valid JSON envelope.', ['status' => 400]);
		}

		$verified = Envelope::verify($envelope, $cloudPk);

		if (is_wp_error($verified)) {
			self::audit('cloud.rest_request', 'denied', ['reason' => $verified->get_error_code()]);

			$verified->add_data(['status' => 401]);

			return $verified;
		}

		$request->set_param(self::ENVELOPE_PARAM, $envelope);

		return true;
	}

	public static function handleCallback(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$payload = self::decodedPayload($request);

		if (! is_array($payload)) {
			return new \WP_Error('ab_cloud_bad_request', 'Callback payload was malformed.', ['status' => 400]);
		}

		$jobId  = isset($payload['job_id']) ? (string) $payload['job_id'] : '';
		$kind   = isset($payload['kind']) ? (string) $payload['kind'] : '';
		$status = isset($payload['status']) ? (string) $payload['status'] : '';
		$output = $payload['output'] ?? null;

		if ($jobId === '' || $kind === '' || $status === '') {
			return new \WP_Error('ab_cloud_bad_request', 'Callback payload is missing required fields.', ['status' => 400]);
		}

		do_action('agent_brain/cloud_job_result', $jobId, $kind, $status, $output);

		self::audit('cloud.callback', 'ok', ['job_id' => $jobId, 'kind' => $kind, 'job_status' => $status]);

		return new \WP_REST_Response(['ok' => true], 200);
	}

	public static function handleTick(\WP_REST_Request $request): \WP_REST_Response {
		do_action('agent_brain/cloud_tick');

		self::audit('cloud.tick', 'ok');

		return new \WP_REST_Response(['ok' => true], 200);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function decodedPayload(\WP_REST_Request $request): ?array {
		$envelope = $request->get_param(self::ENVELOPE_PARAM);

		if (! is_array($envelope) || ! isset($envelope['payload']) || ! is_string($envelope['payload'])) {
			return null;
		}

		$payload = json_decode($envelope['payload'], true);

		return is_array($payload) ? $payload : null;
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function audit(string $event, string $status, array $meta = []): void {
		do_action('agent_brain/audit', [
			'actor_id'    => 0,
			'event'       => $event,
			'status'      => $status,
			'object_type' => 'cloud_rest',
			'object_id'   => null,
			'meta'        => $meta,
		]);
	}
}
