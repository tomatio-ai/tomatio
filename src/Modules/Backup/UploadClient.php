<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backup;

use AgentBrain\Modules\Cloud\Connector;
use AgentBrain\Modules\Cloud\Envelope;

/**
 * Signed, chunked upload of an encrypted backup blob to the cloud:
 * init -> chunks (<=512 KiB each) -> complete, per contracts.md "Backups".
 * Mirrors Cloud\Client's fail-loud/no-network-unless-connected posture.
 * Streams the file from disk one chunk at a time so memory stays bounded
 * regardless of backup size, and is resumable within a single run (a
 * chunk failure does not re-send already-acknowledged chunks).
 */
final class UploadClient {

	public const CHUNK_BYTES = 512 * 1024;

	/**
	 * @return true|\WP_Error
	 */
	public static function upload(string $backupId, string $filePath, int $bytes, string $sha256): bool|\WP_Error {
		$connection = self::requireConnection();
		if (is_wp_error($connection)) {
			return $connection;
		}

		$init = self::init($backupId, $bytes, $sha256);
		if (is_wp_error($init)) {
			self::audit($backupId, 'error', ['stage' => 'init']);

			return $init;
		}

		$uploadPath = $init['upload_url_path'];

		$in = fopen($filePath, 'rb');
		if ($in === false) {
			self::audit($backupId, 'error', ['stage' => 'open_file']);

			return new \WP_Error('ab_backup_upload_failed', 'Unable to open backup file for upload.');
		}

		$n = 0;
		while (! feof($in)) {
			$chunk = fread($in, self::CHUNK_BYTES);
			if ($chunk === false || $chunk === '') {
				break;
			}

			$result = self::sendChunk($uploadPath, $n, $chunk);

			if (is_wp_error($result)) {
				fclose($in);
				self::audit($backupId, 'error', ['stage' => 'chunk', 'n' => $n]);

				return $result;
			}

			$n++;
		}
		fclose($in);

		$complete = self::complete($uploadPath, $backupId, $n);

		if (is_wp_error($complete)) {
			self::audit($backupId, 'error', ['stage' => 'complete']);

			return $complete;
		}

		self::audit($backupId, 'ok', ['chunks' => $n, 'bytes' => $bytes]);

		return true;
	}

	/**
	 * Resumable-upload primitive: init only. Used directly by both the
	 * single-shot upload() above and BackupStateMachine's per-tick upload
	 * phase, so both paths speak the exact same wire protocol.
	 *
	 * @return array{upload_url_path:string}|\WP_Error
	 */
	public static function init(string $backupId, int $bytes, string $sha256): array|\WP_Error {
		$connection = self::requireConnection();
		if (is_wp_error($connection)) {
			return $connection;
		}

		[$endpoint, $siteId, $sk] = $connection;

		$result = self::post($endpoint . '/v1/backups/init', $siteId, $sk, [
			'backup_id' => $backupId,
			'bytes'     => $bytes,
			'sha256'    => $sha256,
		]);

		if (is_wp_error($result)) {
			return $result;
		}

		$uploadPath = $result['upload_url_path'] ?? ('/v1/backups/' . $backupId);
		if (! is_string($uploadPath) || $uploadPath === '') {
			$uploadPath = '/v1/backups/' . $backupId;
		}

		return ['upload_url_path' => $uploadPath];
	}

	/**
	 * Resumable-upload primitive: sends one <=512 KiB chunk at index $n to
	 * the upload path returned by init().
	 *
	 * @return true|\WP_Error
	 */
	public static function sendChunk(string $uploadPath, int $n, string $chunk): bool|\WP_Error {
		$connection = self::requireConnection();
		if (is_wp_error($connection)) {
			return $connection;
		}

		[$endpoint, $siteId, $sk] = $connection;

		$result = self::post($endpoint . $uploadPath . '/chunk?n=' . $n, $siteId, $sk, [
			'n'        => $n,
			'data_b64' => base64_encode($chunk),
		]);

		return is_wp_error($result) ? $result : true;
	}

	/**
	 * Resumable-upload primitive: finalises an upload once every chunk has
	 * been sent.
	 *
	 * @return true|\WP_Error
	 */
	public static function complete(string $uploadPath, string $backupId, int $chunks): bool|\WP_Error {
		$connection = self::requireConnection();
		if (is_wp_error($connection)) {
			return $connection;
		}

		[$endpoint, $siteId, $sk] = $connection;

		$result = self::post($endpoint . $uploadPath . '/complete', $siteId, $sk, [
			'backup_id' => $backupId,
			'chunks'    => $chunks,
		]);

		return is_wp_error($result) ? $result : true;
	}

	/**
	 * Signed GET of every remote backup for this site
	 * (contracts.md "Final fix wave additions" / privacy F1: forget
	 * propagation needs to enumerate and delete remote backups).
	 *
	 * @return array<int, array{backup_id:string, bytes:int, sha256:string, status:string, created:int, completed:?int}>|\WP_Error
	 */
	public static function listBackups(): array|\WP_Error {
		$connection = self::requireConnection();
		if (is_wp_error($connection)) {
			return $connection;
		}

		[$endpoint, $siteId, $sk] = $connection;

		$envelope = \AgentBrain\Modules\Cloud\Envelope::build([], $siteId, $sk);
		$envelopeJson = wp_json_encode($envelope);
		if (! is_string($envelopeJson)) {
			return new \WP_Error('ab_backup_list_failed', 'Unable to encode the signed list request.');
		}

		$encoded = rtrim(strtr(base64_encode($envelopeJson), '+/', '-_'), '=');

		$response = wp_remote_get(
			$endpoint . '/v1/backups/list?envelope=' . $encoded,
			['timeout' => 15]
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return new \WP_Error('ab_backup_list_rejected', sprintf('Cloud rejected the backup list request (HTTP %d).', $code));
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (! is_array($body) || ! isset($body['backups']) || ! is_array($body['backups'])) {
			return new \WP_Error('ab_backup_list_malformed', 'Cloud backup list response was malformed.');
		}

		return $body['backups'];
	}

	/**
	 * Signed delete of a single remote backup by id. Both a 200 {deleted:
	 * true} and a 404 (already gone / never completed) are treated as
	 * success — the goal ("this backup no longer exists remotely") is met
	 * either way.
	 *
	 * @return true|\WP_Error
	 */
	public static function deleteBackup(string $backupId): bool|\WP_Error {
		$connection = self::requireConnection();
		if (is_wp_error($connection)) {
			return $connection;
		}

		[$endpoint, $siteId, $sk] = $connection;

		$envelope = \AgentBrain\Modules\Cloud\Envelope::build([], $siteId, $sk);

		$response = wp_remote_post(
			$endpoint . '/v1/backups/' . rawurlencode($backupId) . '/delete',
			[
				'headers' => ['Content-Type' => 'application/json'],
				'body'    => wp_json_encode($envelope),
				'timeout' => 15,
			]
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code === 404 || ($code >= 200 && $code < 300)) {
			return true;
		}

		return new \WP_Error('ab_backup_delete_rejected', sprintf('Cloud rejected the backup delete request (HTTP %d).', $code));
	}

	/**
	 * @return array{0:string,1:string,2:string}|\WP_Error [endpoint, site_id, site_sk]
	 */
	private static function requireConnection(): array|\WP_Error {
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

		return [untrailingslashit($endpoint), $siteId, $sk];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function post(string $url, string $siteId, string $skBase64, array $payload): array|\WP_Error {
		$envelope = Envelope::build($payload, $siteId, $skBase64);

		$response = wp_remote_post(
			$url,
			[
				'headers' => ['Content-Type' => 'application/json'],
				'body'    => wp_json_encode($envelope),
				'timeout' => 30,
			]
		);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return new \WP_Error('ab_backup_upload_rejected', sprintf('Cloud rejected backup request (HTTP %d): %s', $code, $url));
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		return is_array($body) ? $body : [];
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function audit(string $backupId, string $status, array $meta = []): void {
		do_action('agent_brain/audit', [
			'actor_id'    => function_exists('get_current_user_id') ? get_current_user_id() : 0,
			'event'       => 'backup.upload',
			'status'      => $status,
			'object_type' => 'backup',
			'object_id'   => $backupId,
			'meta'        => $meta,
		]);
	}
}
