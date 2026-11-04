<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\RestNamespaces;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Management routes for exports, shaped like PCP's docs/management-api.md:
 *
 *   POST   agent-brain/v1/manage/exports               make a full export (201)
 *   GET    agent-brain/v1/manage/exports               archives held, with expires_at
 *   GET    agent-brain/v1/manage/exports/{id}          one archive's status
 *   DELETE agent-brain/v1/manage/exports/{id}          delete it (204)
 *   GET    agent-brain/v1/manage/exports/{id}/archive  the archive, with Repr-Digest
 *
 * The browser login only (decision 8): a signed-in administrator
 * (Exports::canExport()) with the wp_rest nonce. OAuth bearer tokens, application
 * passwords and any other Authorization header are refused, because
 * management and MCP must not share credentials. Scripts use WP-CLI.
 */
final class ExportRest {

	public const NAMESPACE = 'agent-brain/v1';
	public const BASE      = '/manage/exports';

	private const ID = '(?P<id>[a-f0-9-]{36})';

	public static function registerRoutes(): void {
		RestNamespaces::register( self::BASE, [
			[
				'methods'             => 'POST',
				'callback'            => [self::class, 'create'],
				'permission_callback' => [self::class, 'permission'],
			],
			[
				'methods'             => 'GET',
				'callback'            => [self::class, 'index'],
				'permission_callback' => [self::class, 'permission'],
			],
		]);

		RestNamespaces::register( self::BASE . '/' . self::ID, [
			[
				'methods'             => 'GET',
				'callback'            => [self::class, 'show'],
				'permission_callback' => [self::class, 'permission'],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [self::class, 'destroy'],
				'permission_callback' => [self::class, 'permission'],
			],
		]);

		RestNamespaces::register( self::BASE . '/' . self::ID . '/archive', [
			'methods'             => 'GET',
			'callback'            => [self::class, 'archive'],
			'permission_callback' => [self::class, 'permission'],
		]);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function permission(WP_REST_Request $request) {
		if ((string) $request->get_header('authorization') !== '' || self::viaApplicationPassword() || (bool) apply_filters('agent_brain/transport_oauth_authenticated', false)) {
			return self::error('forbidden', 'Management routes accept the browser login only. Tokens and application passwords are refused; use WP-CLI (wp tomatio export) for scripts.', 401);
		}

		if (! is_user_logged_in()) {
			return self::error('forbidden', 'Sign in to WordPress first.', 401);
		}

		$nonce = (string) ($request->get_header('x_wp_nonce') ?? '');
		if ($nonce === '') {
			$nonce = (string) $request->get_param('_wpnonce');
		}
		if ($nonce === '' || wp_verify_nonce($nonce, 'wp_rest') === false) {
			return self::error('forbidden', 'The request needs the wp_rest nonce of a signed-in session.', 403);
		}

		if (! Exports::canExport(get_current_user_id())) {
			return self::error('forbidden', 'Only administrators can manage exports.', 403);
		}

		return true;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create(WP_REST_Request $request) {
		unset($request);

		try {
			$record = ( new Exports() )->create(get_current_user_id());
		} catch (ExportFailed $e) {
			$status = match ($e->errorCode()) {
				'too_large'   => 413,
				'forbidden'   => 403,
				'unsupported' => 501,
				default       => 500,
			};

			return self::error($e->errorCode(), $e->getMessage(), $status);
		}

		$response = new WP_REST_Response(Exports::view($record), 201);
		$response->header('Location', rest_url(self::NAMESPACE . self::BASE . '/' . $record['id']));
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	public static function index(WP_REST_Request $request): WP_REST_Response {
		unset($request);

		$items    = array_map([Exports::class, 'view'], ( new ExportStore() )->all());
		$response = new WP_REST_Response(['items' => $items, 'next_cursor' => null], 200);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function show(WP_REST_Request $request) {
		$record = ( new ExportStore() )->get((string) $request['id']);
		if ($record === null) {
			return self::error('not_found', 'No such archive (it may have expired).', 404);
		}

		$response = new WP_REST_Response(Exports::view($record), 200);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function destroy(WP_REST_Request $request) {
		if (! ( new ExportStore() )->delete((string) $request['id'])) {
			return self::error('not_found', 'No such archive (it may have expired).', 404);
		}

		return new WP_REST_Response(null, 204);
	}

	/**
	 * The archive itself. The response carries the file's path; serve()
	 * sends its bytes in place of a JSON body.
	 *
	 * @return ArchiveResponse|WP_Error
	 */
	public static function archive(WP_REST_Request $request) {
		$store  = new ExportStore();
		$record = $store->get((string) $request['id']);
		if ($record === null) {
			return self::error('not_found', 'No such archive (it may have expired).', 404);
		}

		$path     = $store->pathOf($record);
		$name     = (string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) $record['name']);
		$response = new ArchiveResponse($path);
		$response->header('Content-Type', str_ends_with($name, '.tar') ? 'application/x-tar' : 'application/zip');
		$response->header('Content-Disposition', 'attachment; filename="' . $name . '"');
		$response->header('Content-Length', (string) filesize($path));
		$response->header('Repr-Digest', ExportStore::reprDigest((string) $record['sha256']));
		$response->header('Cache-Control', 'no-store');
		$response->header('X-Content-Type-Options', 'nosniff');

		return $response;
	}

	/**
	 * rest_pre_serve_request: the archive's bytes instead of JSON. Headers
	 * were already sent by the REST server.
	 *
	 * @param mixed $served
	 * @param mixed $result
	 * @return mixed
	 */
	public static function serve($served, $result) {
		if ($served || ! $result instanceof ArchiveResponse || ! is_file($result->file)) {
			return $served;
		}

		readfile($result->file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a private file to its owner.

		return true;
	}

	private static function viaApplicationPassword(): bool {
		return did_action('application_password_did_authenticate') > 0 || ! empty($GLOBALS['wp_rest_application_password_uuid'] ?? null);
	}

	private static function error(string $code, string $message, int $status): WP_Error {
		return new WP_Error($code, $message, ['status' => $status]);
	}
}
