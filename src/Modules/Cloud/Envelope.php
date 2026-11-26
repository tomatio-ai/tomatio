<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Cloud;

/**
 * Builds and verifies the signed JSON envelope used by both connect and job
 * traffic (contracts.md "Cloud signing protocol v1"). The signature is
 * carried in the request body, never the Authorization header, so hosts
 * that strip that header cannot break the protocol.
 */
final class Envelope {

	public const CLOCK_SKEW_SECONDS = 300;

	/**
	 * @param array<string, mixed> $payload
	 * @return array{v:int, site_id:string, ts:int, nonce:string, payload:string, sig:string}
	 */
	public static function build(array $payload, string $siteId, string $skBase64): array {
		$ts    = time();
		$nonce = Signer::generateNonce();

		$payloadJson = wp_json_encode($payload);
		if (! is_string($payloadJson)) {
			throw new \RuntimeException('Failed to encode cloud envelope payload.');
		}

		$message = Signer::canonicalMessage($siteId, $ts, $nonce, $payloadJson);
		$sig     = Signer::sign($message, $skBase64);

		return [
			'v'       => 1,
			'site_id' => $siteId,
			'ts'      => $ts,
			'nonce'   => $nonce,
			'payload' => $payloadJson,
			'sig'     => $sig,
		];
	}

	/**
	 * Verifies structure, freshness (±300s), signature, then (last, so a bad
	 * signature never spends a nonce slot) replay protection.
	 *
	 * @param array<string, mixed> $envelope
	 */
	public static function verify(array $envelope, string $pkBase64): true|\WP_Error {
		foreach (['v', 'site_id', 'ts', 'nonce', 'payload', 'sig'] as $key) {
			if (! array_key_exists($key, $envelope)) {
				return new \WP_Error('ab_cloud_envelope_malformed', 'Cloud envelope is missing required fields.');
			}
		}

		if (
			(int) $envelope['v'] !== 1
			|| ! is_string($envelope['site_id'])
			|| ! is_numeric($envelope['ts'])
			|| ! is_string($envelope['nonce'])
			|| ! is_string($envelope['payload'])
			|| ! is_string($envelope['sig'])
		) {
			return new \WP_Error('ab_cloud_envelope_malformed', 'Cloud envelope has invalid field types.');
		}

		$siteId  = $envelope['site_id'];
		$ts      = (int) $envelope['ts'];
		$nonce   = $envelope['nonce'];
		$payload = $envelope['payload'];
		$sig     = $envelope['sig'];

		if (abs(time() - $ts) > self::CLOCK_SKEW_SECONDS) {
			return new \WP_Error('ab_cloud_envelope_expired', 'Cloud envelope timestamp is outside the allowed window.');
		}

		$message = Signer::canonicalMessage($siteId, $ts, $nonce, $payload);

		if (! Signer::verify($message, $sig, $pkBase64)) {
			return new \WP_Error('ab_cloud_envelope_bad_signature', 'Cloud envelope signature is invalid.');
		}

		if (! NonceStore::claim($siteId, $nonce)) {
			return new \WP_Error('ab_cloud_envelope_replayed', 'Cloud envelope nonce has already been used.');
		}

		return true;
	}
}
