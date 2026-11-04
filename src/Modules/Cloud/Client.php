<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Cloud;

/**
 * Outbound job pushes to the cloud. Never contacts the network unless
 * ab_cloud_enabled is true and the site has completed Connector::connect().
 * Errors are always surfaced as WP_Error — never swallowed (L26 fail loud).
 */
final class Client {

	/**
	 * @param array<string, mixed> $input
	 * @return array{job_id: string}|\WP_Error
	 */
	public static function pushJob(string $kind, array $input): array|\WP_Error {
		if (! (bool) get_option('ab_cloud_enabled', false)) {
			return new \WP_Error('ab_cloud_disabled', 'Cloud connector is disabled for this site.');
		}

		if (! Connector::isConnected()) {
			return new \WP_Error('ab_cloud_not_connected', 'Site is not connected to the cloud.');
		}

		$endpoint = get_option('ab_cloud_endpoint');
		$siteId   = get_option('ab_cloud_site_id');
		$sk       = get_option('ab_cloud_site_sk');

		if (! is_string($endpoint) || $endpoint === '' || ! is_string($siteId) || ! is_string($sk)) {
			return new \WP_Error('ab_cloud_not_connected', 'Cloud connection state is incomplete.');
		}

		$jobId   = wp_generate_uuid4();
		$payload = [
			'job_id' => $jobId,
			'kind'   => $kind,
			'input'  => $input,
		];

		$envelope = Envelope::build($payload, $siteId, $sk);

		$response = wp_remote_post(
			untrailingslashit($endpoint) . '/v1/jobs',
			[
				'headers' => ['Content-Type' => 'application/json'],
				'body'    => wp_json_encode($envelope),
				'timeout' => 15,
			]
		);

		if (is_wp_error($response)) {
			self::audit($jobId, $kind, 'error', ['reason' => 'transport']);

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);

		if ($code !== 202) {
			self::audit($jobId, $kind, 'error', ['reason' => 'http_status', 'code' => $code]);

			return new \WP_Error('ab_cloud_job_rejected', sprintf('Cloud rejected job push (HTTP %d).', $code));
		}

		self::audit($jobId, $kind, 'ok');

		return ['job_id' => $jobId];
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function audit(string $jobId, string $kind, string $status, array $meta = []): void {
		do_action('agent_brain/audit', [
			'actor_id'    => get_current_user_id(),
			'event'       => 'cloud.push_job',
			'status'      => $status,
			'object_type' => 'cloud_job',
			'object_id'   => $jobId,
			'meta'        => array_merge(['kind' => $kind], $meta),
		]);
	}
}
