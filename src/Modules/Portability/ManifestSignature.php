<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * The signed export manifest (PROV-8, #177): manifest.signature.json, the
 * small document next to manifest.json that proves the manifest — and so,
 * through the manifest's file hashes, the whole archive — is what the
 * exporting brain exported, unaltered.
 *
 * The signature is an HMAC-SHA-256 under the exporting brain's per-brain
 * SigningKey over the SHA-256 of manifest.json's bytes. Signing the hash
 * rather than the bytes keeps verification a fixed-size operation and lets
 * the pure ArchiveCheck validate the document's shape while the crypto runs
 * in the WordPress context of Imports and Restores, which call assess().
 *
 * The file is archive metadata like manifest.json itself: never listed in
 * the manifest's files[] and never imported as a page. An archive without
 * it (an older or foreign exporter) imports normally, with a note that its
 * origin cannot be verified. So does an archive signed by another brain
 * (#563): an HMAC can be checked only by the brain that holds the key, so
 * moving a brain to a new server would otherwise always be refused, while
 * removing the file would let the archive through anyway. An archive whose
 * signature names one of this brain's keys but does not verify, or whose
 * signature document is malformed, was altered: it is rejected before
 * anything is written.
 */
final class ManifestSignature {

	public const FILE = 'manifest.signature.json';

	/**
	 * The signature document for a manifest with this SHA-256, signed now
	 * with the brain's current key.
	 *
	 * @return array{alg:string, key_id:string, fingerprint:string, signed_at:string, signature:string}
	 */
	public static function create(string $manifestSha256): array {
		return [
			'alg'         => SigningKey::ALG,
			'key_id'      => SigningKey::id(),
			'fingerprint' => SigningKey::fingerprint(),
			'signed_at'   => gmdate('Y-m-d\TH:i:s\Z'),
			'signature'   => SigningKey::sign($manifestSha256),
		];
	}

	/**
	 * Whether $doc is a valid signature of $manifestSha256 under some key of
	 * this brain (current or retired). False for a malformed document, an
	 * unknown algorithm or key id — so anything but the unaltered manifest
	 * as signed by the exporting brain fails closed.
	 *
	 * @param array<string, mixed> $doc
	 */
	public static function verify(string $manifestSha256, array $doc): bool {
		if (! self::wellFormed($doc)) {
			return false;
		}

		return SigningKey::verify($manifestSha256, (string) $doc['key_id'], (string) $doc['signature']);
	}

	/**
	 * A signature document this version can read: the known algorithm, a
	 * key id and a 64-digit hex HMAC.
	 *
	 * @param array<string, mixed> $doc
	 */
	private static function wellFormed(array $doc): bool {
		return ($doc['alg'] ?? null) === SigningKey::ALG
			&& is_string($doc['key_id'] ?? null) && $doc['key_id'] !== ''
			&& is_string($doc['signature'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $doc['signature']) === 1;
	}

	/**
	 * What a restore or an import concludes from the archive's signature,
	 * in one place so both modes word it identically.
	 *
	 * @param array<string, mixed>|null $doc The parsed signature document.
	 * @return array{0:?string, 1:?string, 2:array<string, mixed>|null} An
	 *   error (reject the archive), a note (show the requester), and the
	 *   provenance imported items keep under x_agent_brain.import_signature
	 *   (null when the archive is rejected).
	 */
	public static function assess(?string $manifestSha256, ?array $doc): array {
		if ($doc === null || $manifestSha256 === '') {
			return [
				null,
				'This archive carries no manifest signature: it may be genuine, but its origin cannot be verified.',
				['signed' => false],
			];
		}

		if (! self::verify($manifestSha256, $doc)) {
			$keyId = is_string($doc['key_id'] ?? null) ? $doc['key_id'] : '';
			if (self::wellFormed($doc) && ! SigningKey::knows($keyId)) {
				$fingerprint = is_string($doc['fingerprint'] ?? null) ? $doc['fingerprint'] : '';

				return [
					null,
					sprintf('This archive was signed by another brain (key %s, fingerprint %s). Only that brain can check the signature, so its origin cannot be verified here.', $keyId, $fingerprint),
					['signed' => true, 'verified' => false, 'key_id' => $keyId, 'fingerprint' => $fingerprint],
				];
			}

			return [
				"The archive's manifest signature does not verify: manifest.json was altered after export, or the archive was signed by a different brain.",
				null,
				null,
			];
		}

		return [
			null,
			sprintf('Manifest signature verified (key %s).', $doc['key_id']),
			[
				'signed'      => true,
				'key_id'      => $doc['key_id'],
				'fingerprint' => is_string($doc['fingerprint'] ?? null) ? $doc['fingerprint'] : '',
			],
		];
	}
}
