<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Cloud;

/**
 * Handles the one-time site <-> cloud connection: generate a keypair, POST
 * it to the cloud's /v1/connect, verify the cloud's signed response, and
 * persist the resulting site_id / cloud_pk. Capability + nonce checks
 * belong to the caller (Settings); this class only talks to the network.
 */
final class Connector {

	/**
	 * @return true|\WP_Error
	 */
	public static function connect(string $endpoint): bool|\WP_Error {
		$endpoint = untrailingslashit($endpoint);

		if (! self::isValidEndpoint($endpoint)) {
			return new \WP_Error('ab_cloud_invalid_endpoint', 'Cloud endpoint must be a valid http(s) URL.');
		}

		$keypair = Signer::generateKeypair();

		$payload  = [
			'site_url' => home_url(),
			'site_pk'  => $keypair['pk'],
		];
		$envelope = Envelope::build($payload, 'pending', $keypair['sk']);

		$response = wp_remote_post(
			$endpoint . '/v1/connect',
			[
				'headers' => ['Content-Type' => 'application/json'],
				'body'    => wp_json_encode($envelope),
				'timeout' => 15,
			]
		);

		if (is_wp_error($response)) {
			self::audit('cloud.connect', 'error', ['reason' => 'transport']);

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($code !== 200 || ! is_array($body)) {
			self::audit('cloud.connect', 'error', ['reason' => 'http_status', 'code' => $code]);

			return new \WP_Error('ab_cloud_connect_failed', sprintf('Cloud connect request failed (HTTP %d).', $code));
		}

		$responsePayload = isset($body['payload']) && is_string($body['payload'])
			? json_decode($body['payload'], true)
			: null;

		if (! is_array($responsePayload)) {
			self::audit('cloud.connect', 'error', ['reason' => 'bad_response_payload']);

			return new \WP_Error('ab_cloud_connect_failed', 'Cloud connect response payload was malformed.');
		}

		$cloudPk = $responsePayload['cloud_pk'] ?? null;
		$siteId  = $responsePayload['site_id'] ?? null;

		if (! is_string($cloudPk) || $cloudPk === '' || ! is_string($siteId) || $siteId === '') {
			self::audit('cloud.connect', 'error', ['reason' => 'missing_identity']);

			return new \WP_Error('ab_cloud_connect_failed', 'Cloud connect response was missing site_id or cloud_pk.');
		}

		// Trust-on-first-contact: this is the only point where the cloud's
		// public key is not yet known locally, so it is taken from its own
		// self-signed response and verified against itself. Every request
		// after this point verifies against the persisted ab_cloud_cloud_pk.
		$verified = Envelope::verify($body, $cloudPk);

		if (is_wp_error($verified)) {
			self::audit('cloud.connect', 'error', ['reason' => 'signature_verification_failed']);

			return $verified;
		}

		update_option('ab_cloud_site_sk', $keypair['sk'], false);
		update_option('ab_cloud_site_pk', $keypair['pk']);
		update_option('ab_cloud_site_id', $siteId);
		update_option('ab_cloud_cloud_pk', $cloudPk);
		update_option('ab_cloud_endpoint', $endpoint);

		self::audit('cloud.connect', 'ok', ['site_id' => $siteId]);

		return true;
	}

	public static function disconnect(): void {
		delete_option('ab_cloud_site_sk');
		delete_option('ab_cloud_site_pk');
		delete_option('ab_cloud_site_id');
		delete_option('ab_cloud_cloud_pk');
		delete_option('ab_cloud_endpoint');
		update_option('ab_cloud_enabled', false);

		self::audit('cloud.disconnect', 'ok');
	}

	/**
	 * Syntactic http(s) URL check. Deliberately not wp_http_validate_url():
	 * that function also performs a live DNS/SSRF check meant for
	 * requests built from untrusted user input, which is inappropriate
	 * for a curator-entered, ab_manage-gated admin setting and would
	 * reject perfectly valid endpoints when DNS is unavailable.
	 */
	private static function isValidEndpoint(string $endpoint): bool {
		if ($endpoint === '') {
			return false;
		}

		$parsed = wp_parse_url($endpoint);

		return is_array($parsed)
			&& isset($parsed['scheme'], $parsed['host'])
			&& in_array(strtolower((string) $parsed['scheme']), ['http', 'https'], true)
			&& $parsed['host'] !== '';
	}

	public static function isConnected(): bool {
		$siteId  = get_option('ab_cloud_site_id');
		$cloudPk = get_option('ab_cloud_cloud_pk');

		return is_string($siteId) && $siteId !== '' && is_string($cloudPk) && $cloudPk !== '';
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function audit(string $event, string $status, array $meta = []): void {
		do_action('agent_brain/audit', [
			'actor_id'    => get_current_user_id(),
			'event'       => $event,
			'status'      => $status,
			'object_type' => 'cloud_connection',
			'object_id'   => null,
			'meta'        => $meta,
		]);
	}
}
