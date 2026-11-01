<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\RestNamespaces;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Management routes for migrations, imports and principals, shaped like
 * PCP's docs/management-api.md:
 *
 *   POST agent-brain/v1/manage/imports                  upload an archive (multipart: archive, options)
 *   GET  agent-brain/v1/manage/imports/{id}             the report
 *   GET  agent-brain/v1/manage/principals               principals, with bound
 *   POST agent-brain/v1/manage/principals/{id}/bind     bind an unbound principal
 *   POST agent-brain/v1/manage/principals/invite        invite people in bulk (#551)
 *   POST agent-brain/v1/manage/agents/keys              a new key for every unbound agent, once (#551)
 *   GET  agent-brain/v1/manage/reconnect                the reconnect checklist, no secrets (#551)
 *
 * The browser login of an administrator only, like the export routes
 * (ExportRest::permission(), decision 8).
 */
final class ImportRest {

	public const IMPORTS    = '/manage/imports';
	public const PRINCIPALS = '/manage/principals';
	public const AGENT_KEYS = '/manage/agents/keys';
	public const RECONNECT  = '/manage/reconnect';

	/**
	 * Migrate a whole brain here: PCP's restore mode (SPEC 8.7.2), which is
	 * still the mode the report names. "restore" stays accepted, as the spec
	 * calls it; people see "migrate", because restoring a backup is another
	 * flow (Backups).
	 */
	public const MODE_MIGRATE = 'migrate';
	public const MODE_RESTORE = 'restore';
	public const MODE_IMPORT  = 'import';

	/** #550: chosen scopes, with their artifacts, history and people, into this brain (an import that brings the scopes). */
	public const MODE_MOVE = 'move';

	public static function registerRoutes(): void {
		RestNamespaces::register( self::IMPORTS, [
			'methods'             => 'POST',
			'callback'            => [self::class, 'create'],
			'permission_callback' => [ExportRest::class, 'permission'],
		]);

		RestNamespaces::register( self::IMPORTS . '/(?P<id>[a-f0-9-]{36})', [
			'methods'             => 'GET',
			'callback'            => [self::class, 'show'],
			'permission_callback' => [ExportRest::class, 'permission'],
		]);

		RestNamespaces::register( self::PRINCIPALS, [
			'methods'             => 'GET',
			'callback'            => [self::class, 'principals'],
			'permission_callback' => [ExportRest::class, 'permission'],
		]);

		RestNamespaces::register( self::PRINCIPALS . '/invite', [
			'methods'             => 'POST',
			'callback'            => [self::class, 'invite'],
			'permission_callback' => [ExportRest::class, 'permission'],
		]);

		RestNamespaces::register( self::AGENT_KEYS, [
			'methods'             => 'POST',
			'callback'            => [self::class, 'agentKeys'],
			'permission_callback' => [ExportRest::class, 'permission'],
		]);

		RestNamespaces::register( self::RECONNECT, [
			'methods'             => 'GET',
			'callback'            => [self::class, 'reconnect'],
			'permission_callback' => [ExportRest::class, 'permission'],
		]);

		// "@" is escaped: WordPress delimits route patterns with it.
		RestNamespaces::register( self::PRINCIPALS . '/(?P<id>(?:human|agent):[A-Za-z0-9._\@+\/-]{1,128})/bind', [
			'methods'             => 'POST',
			'callback'            => [self::class, 'bind'],
			'permission_callback' => [ExportRest::class, 'permission'],
		]);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create(WP_REST_Request $request) {
		$files   = $request->get_file_params();
		$archive = is_array($files['archive'] ?? null) ? $files['archive'] : null;
		if ($archive === null || (int) ($archive['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ! is_uploaded_file((string) $archive['tmp_name'])) {
			return self::error('invalid_input', 'Upload the archive as multipart/form-data in the field "archive".', 400);
		}

		$options = self::options($request);
		$mode    = (string) ($options['mode'] ?? 'import');

		try {
			$result = self::run($mode, (string) $archive['tmp_name'], get_current_user_id(), $options);
		} catch (ImportFailed $e) {
			return self::error($e->errorCode(), $e->getMessage(), $e->errorCode() === 'forbidden' ? 403 : 400);
		}

		$report = $result['report'];
		$status = 200;
		if ($report['status'] === 'rejected') {
			$status = self::isMigration($mode) && Restores::notEmpty() !== [] ? 409 : 422;
		}

		$response = new WP_REST_Response($report, $status);
		$response->header('Location', rest_url(ExportRest::NAMESPACE . self::IMPORTS . '/' . $report['id']));
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * Runs a migration (PCP restore mode) or an import of an archive file.
	 *
	 * @param array<string, mixed> $options
	 * @return array{report:array<string, mixed>, errors:array<int, string>, notes:array<int, string>}
	 * @throws ImportFailed
	 */
	public static function run(string $mode, string $archive, int $userId, array $options): array {
		if (self::isMigration($mode)) {
			return ( new Restores() )->run($archive, $userId, [
				'dry_run'      => ! empty($options['dry_run']),
				'bind_as'      => (string) ($options['bind_as'] ?? ''),
				'leave_scopes' => $options['leave_scopes'] ?? [],
				'keep_scopes'  => $options['keep_scopes'] ?? [],
				'leave_people' => $options['leave_people'] ?? [],
				'keep_people'  => $options['keep_people'] ?? [],
				'adopt_accounts' => ! empty($options['adopt_accounts']),
			]);
		}

		if ($mode === self::MODE_MOVE) {
			return ( new Moves() )->run($archive, $userId, $options);
		}

		if ($mode !== self::MODE_IMPORT) {
			throw new ImportFailed('invalid_input', 'mode must be migrate (restore), move or import.');
		}
		if (($options['format'] ?? 'pcp') !== 'pcp') {
			throw new ImportFailed('unsupported', 'Only PCP archives can be imported (format pcp).');
		}

		return ( new Imports() )->run($archive, $userId, $options);
	}

	public static function isMigration(string $mode): bool {
		return $mode === self::MODE_MIGRATE || $mode === self::MODE_RESTORE;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function show(WP_REST_Request $request) {
		$report = ImportReport::get((string) $request['id'], get_current_user_id());
		if ($report === null) {
			return self::error('not_found', 'No such report (reports are kept for a day).', 404);
		}

		$response = new WP_REST_Response($report, 200);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	public static function principals(WP_REST_Request $request): WP_REST_Response {
		unset($request);

		$items = array_map(static function (array $principal): array {
			unset($principal['user_id']);

			return $principal;
		}, Binding::principals());

		$response = new WP_REST_Response(['items' => $items, 'next_cursor' => null], 200);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function bind(WP_REST_Request $request) {
		try {
			$result = Binding::bind(
				(string) $request['id'],
				get_current_user_id(),
				(string) ($request->get_param('email') ?? ''),
				$request->get_param('send_mail') === null ? true : (bool) rest_sanitize_boolean((string) $request->get_param('send_mail'))
			);
		} catch (ImportFailed $e) {
			$status = match ($e->errorCode()) {
				'not_found'     => 404,
				'conflict'      => 409,
				'invalid_input' => 400,
				default         => 500,
			};

			return self::error($e->errorCode(), $e->getMessage(), $status);
		}

		$response = new WP_REST_Response($result, 200);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * #551: {"invites": {"human:roger": "roger@example.org", ...}}. A link
	 * comes back only for an invitation whose mail failed.
	 */
	public static function invite(WP_REST_Request $request): WP_REST_Response {
		$invites = $request->get_param('invites');
		$results = Reconnect::invite(is_array($invites) ? array_map('strval', $invites) : [], get_current_user_id());

		$response = new WP_REST_Response(['results' => $results], 200);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	/** #551: a new key for every unbound agent (or "agents": [ids]), returned once. */
	public static function agentKeys(WP_REST_Request $request): WP_REST_Response {
		$only = $request->get_param('agents');
		$keys = Reconnect::keys(is_array($only) ? array_values(array_map('strval', $only)) : null, get_current_user_id());

		$response = new WP_REST_Response(['keys' => $keys, 'connector' => \AgentBrain\Modules\Admin\ConnectPage::mcpUrl()], 200);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	public static function reconnect(WP_REST_Request $request): WP_REST_Response {
		unset($request);

		$response = new WP_REST_Response(Reconnect::checklist(), 200);
		$response->header('Cache-Control', 'no-store');

		return $response;
	}

	/**
	 * Options as form fields, or as one JSON field "options" (SPEC
	 * management API).
	 *
	 * @return array<string, mixed>
	 */
	private static function options(WP_REST_Request $request): array {
		$json = $request->get_param('options');
		if (is_string($json) && $json !== '') {
			$decoded = json_decode($json, true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}

		$out = [];
		foreach (['mode', 'dry_run', 'bind_as', 'on_conflict', 'partition', 'partition_map', 'principal_map', 'approve_all', 'as', 'format', 'confirm', 'leave_scopes', 'keep_scopes', 'leave_people', 'keep_people', 'scopes', 'scope_map', 'keep_approved', 'adopt_accounts'] as $key) {
			$value = $request->get_param($key);
			if ($value === null) {
				continue;
			}
			if (in_array($key, ['dry_run', 'approve_all', 'confirm', 'keep_approved', 'adopt_accounts'], true)) {
				$value = (bool) rest_sanitize_boolean(is_bool($value) ? $value : (string) $value);
			} elseif (in_array($key, ['partition_map', 'principal_map', 'scope_map'], true) && is_string($value)) {
				$value = json_decode($value, true);
			}
			$out[$key] = $value;
		}

		return $out;
	}

	private static function error(string $code, string $message, int $status): WP_Error {
		return new WP_Error($code, $message, ['status' => $status]);
	}
}
