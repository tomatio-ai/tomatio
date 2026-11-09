<?php

declare(strict_types=1);

namespace AgentBrain\Modules\MergeLog;

use AgentBrain\Core\RestNamespaces;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\MemoryRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /tomatio/v1/merge-log/<id> (#870): the webhook a Forgejo, Gitea or
 * GitHub repository calls on pull request events. The body must be signed
 * with the repository's secret (HMAC-SHA256: X-Forgejo-Signature,
 * X-Gitea-Signature or X-Hub-Signature-256). A merged pull request that
 * adds a changelog.d/ fragment is saved as one memory: a pointer and a
 * one-line summary, linked to the pull request, never a copy of it. A
 * pull request labelled no-brain-log stays out, and a redelivery writes
 * nothing new. #885: the merge is also recorded on the repository's
 * TíoDay, by default the one its agent leads (TioDaySync).
 */
final class Receiver {

	public const SKIP_LABEL = 'no-brain-log';
	private const MAX_BODY  = 2000000;
	private const MAX_PAGES = 3;

	public static function registerRoutes(): void {
		RestNamespaces::register('/merge-log/(?P<id>[a-z0-9]{16})', [
			'methods'             => 'POST',
			'callback'            => [self::class, 'handle'],
			// Signed requests from a repository, not a signed-in user: the
			// handler checks the signature before it reads anything.
			'permission_callback' => '__return_true',
		]);
	}

	public static function handle(WP_REST_Request $request): WP_REST_Response {
		$id   = (string) $request->get_param('id');
		$repo = Repos::get($id);
		$body = $request->get_body();
		if ($repo === null) {
			return self::reply(404, 'unknown_hook');
		}
		if (strlen($body) > self::MAX_BODY || ! self::signed($body, $request, Repos::open($repo['secret']))) {
			self::note($id, 'denied', 'bad_signature');

			return self::reply(401, 'bad_signature');
		}

		$event = (string) ($request->get_header('x_forgejo_event') ?? $request->get_header('x_gitea_event') ?? $request->get_header('x_github_event') ?? '');
		if ($event !== 'pull_request') {
			return self::reply(200, $event === 'ping' ? 'pong' : 'ignored_event');
		}

		$data = json_decode($body, true);
		$pr   = is_array($data) && is_array($data['pull_request'] ?? null) ? $data['pull_request'] : null;
		if ($pr === null || ($data['action'] ?? '') !== 'closed' || empty($pr['merged'])) {
			return self::reply(200, 'not_merged');
		}

		$fullName = strtolower((string) ($data['repository']['full_name'] ?? ''));
		if ($fullName !== $repo['repo']) {
			return self::reply(422, 'other_repository');
		}

		$number = (int) ($pr['number'] ?? 0);
		$key    = 'merged-pr:' . $fullName . '#' . $number;
		if ($number <= 0 || self::recorded($key, (int) $repo['scope_id'])) {
			return self::reply(200, 'already_recorded');
		}

		foreach ((array) ($pr['labels'] ?? []) as $label) {
			if (is_array($label) && strtolower((string) ($label['name'] ?? '')) === self::SKIP_LABEL) {
				return self::reply(200, 'skipped');
			}
		}

		$fragments = self::fragments((string) ($data['repository']['url'] ?? ''), $number, Repos::open($repo['token']));
		if ($fragments === null) {
			self::note($id, 'error', 'files_unreadable');

			return self::reply(502, 'files_unreadable');
		}
		if ($fragments === [] && ! (bool) \AgentBrain\Core\Hooks::filter('merge_log_important', false, $data)) {
			return self::reply(200, 'not_important');
		}

		$agentId = (int) $repo['agent_id'];
		$scopeId = (int) $repo['scope_id'];
		if (! (new Scopes())->canWrite($agentId, $scopeId)) {
			self::note($id, 'error', 'agent_cannot_write');

			return self::reply(409, 'agent_cannot_write');
		}

		$memoryId = (new MemoryRepository())->insert([
			'agent_id'      => $agentId,
			'scope_id'      => $scopeId,
			'type'          => 'episode',
			'importance'    => 60,
			'content'       => self::content($fullName, $number, $pr),
			'search_keys'   => self::keys($key, $fullName, $number, $pr, $fragments),
			'agent_written' => 0,
			'client'        => \AgentBrain\Modules\DataStore\StoreMemory::MERGE_LOG_CLIENT,
		]);
		self::note($id, 'ok', 'recorded', $memoryId);

		$reply  = ['recorded' => true, 'memory_id' => $memoryId];
		$tioday = TioDaySync::target($repo);
		if ($tioday > 0) {
			// The memory stands either way: a TíoDay that can't be written is noted, not a failed delivery.
			$reply['tioday'] = TioDaySync::record($tioday, $agentId, $data, $memoryId, $fragments, Repos::open($repo['token']));
			if (! in_array($reply['tioday']['result'] ?? '', ['recorded', 'promotion'], true)) {
				self::note($id, 'error', 'tioday_' . (string) ($reply['tioday']['result'] ?? 'error'), $memoryId);
			}
		}

		return new WP_REST_Response($reply, 201);
	}

	public static function signed(string $body, WP_REST_Request $request, string $secret): bool {
		if ($secret === '') {
			return false;
		}
		$expected = hash_hmac('sha256', $body, $secret);
		$given    = (string) ($request->get_header('x_forgejo_signature') ?? $request->get_header('x_gitea_signature') ?? '');
		if ($given === '') {
			$given = preg_replace('/^sha256=/', '', (string) ($request->get_header('x_hub_signature_256') ?? '')) ?? '';
		}

		return $given !== '' && hash_equals($expected, strtolower($given));
	}

	/**
	 * The changelog.d/ fragments the pull request adds (file names), or null
	 * when its file list can't be read.
	 *
	 * @return string[]|null
	 */
	private static function fragments(string $apiUrl, int $number, string $token): ?array {
		if (! str_starts_with($apiUrl, 'https://')) {
			return null;
		}
		$headers = ['Accept' => 'application/json'];
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$found = [];
		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$response = wp_safe_remote_get(rtrim($apiUrl, '/') . '/pulls/' . $number . '/files?limit=50&per_page=50&page=' . $page, ['timeout' => 10, 'headers' => $headers]);
			$files    = is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 ? null : json_decode(wp_remote_retrieve_body($response), true);
			if (! is_array($files)) {
				return null;
			}
			foreach ($files as $file) {
				$name = is_array($file) ? (string) ($file['filename'] ?? '') : '';
				if (str_starts_with($name, 'changelog.d/') && str_ends_with($name, '.md') && $name !== 'changelog.d/README.md' && ! in_array($file['status'] ?? '', ['removed', 'deleted'], true)) {
					$found[] = $name;
				}
			}
			if (count($files) < 50) {
				break;
			}
		}

		return $found;
	}

	/** @param array<string, mixed> $pr */
	private static function content(string $repo, int $number, array $pr): string {
		$title = trim((string) preg_replace('/\s+/u', ' ', (string) ($pr['title'] ?? '')));
		$base  = (string) ($pr['base']['ref'] ?? '');
		$by    = (string) ($pr['merged_by']['login'] ?? '');

		/* translators: 1: owner/repository#number, 2: branch, 3: who merged it, 4: pull request title, 5: its address. */
		return sprintf(__('Merged %1$s into %2$s (by %3$s): %4$s. %5$s', 'tomatio'), $repo . '#' . $number, $base !== '' ? $base : '?', $by !== '' ? $by : '?', rtrim($title, '.'), esc_url_raw((string) ($pr['html_url'] ?? '')));
	}

	/**
	 * @param array<string, mixed> $pr
	 * @param string[]             $fragments
	 */
	private static function keys(string $key, string $repo, int $number, array $pr, array $fragments): string {
		$words = [$key, 'merge merged pull request', $repo, (string) $number, (string) ($pr['base']['ref'] ?? '')];
		if (preg_match_all('/#(\d+)/', (string) ($pr['title'] ?? '') . ' ' . (string) ($pr['body'] ?? ''), $refs) > 0) {
			$words[] = implode(' ', array_unique($refs[1]));
		}
		foreach ($fragments as $fragment) {
			$words[] = str_replace('-', ' ', basename($fragment, '.md'));
		}

		return mb_substr(trim(implode(' ', array_filter($words))), 0, 1000);
	}

	private static function recorded(string $key, int $scopeId): bool {
		global $wpdb;
		$table = MemoryRepository::memoryTable();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from our own constant.
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE scope_id = %d AND search_keys LIKE %s", $scopeId, $wpdb->esc_like($key) . ' %')) > 0;
	}

	private static function note(string $id, string $status, string $reason, int $memoryId = 0): void {
		\AgentBrain\Core\Hooks::action('audit', [
			'actor_id'    => 0,
			'event'       => 'merge_log',
			'status'      => $status,
			'object_type' => $memoryId > 0 ? 'memory' : 'merge_log',
			'object_id'   => $memoryId > 0 ? (string) $memoryId : $id,
			'meta'        => ['reason' => $reason],
		]);
	}

	private static function reply(int $code, string $result): WP_REST_Response {
		return new WP_REST_Response(['recorded' => false, 'result' => $result], $code);
	}
}
