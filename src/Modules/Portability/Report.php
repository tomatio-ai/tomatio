<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * What a PCP export will call things, for `wp agent-brain pcp-ids`: the
 * controller, principals, partitions, counts and a sample of page ids and
 * paths, and the checks that should all be zero. Read only, and never any
 * memory or post content.
 */
final class Report {

	/**
	 * @return array{
	 *   controller: ?string,
	 *   principals: array<int, array{id:string, status:string, user_id:int, fqdn:?string}>,
	 *   users_without_id: array<int, int>,
	 *   partitions: array<int, array{term_id:int, name:?string}>,
	 *   pages: array<string, array{indexed:int, missing:int}>,
	 *   sample: array<string, array<int, array{pcp_id:string, path:string}>>,
	 *   multi_scope_posts: array<int, int>,
	 *   invalid: array{principals:int, partitions:int, paths:int}
	 * }
	 */
	public static function build(int $sample = 10): array {
		$sample = max(0, min(200, $sample));

		$principals = array_map(static fn (array $row): array => [
			'id'      => $row['principal_id'],
			'status'  => $row['status'],
			'user_id' => $row['user_id'],
			'fqdn'    => $row['fqdn'],
		], Principals::all());

		$partitions = Partitions::all();

		$invalid = [
			'principals' => count(array_filter($principals, static fn (array $p): bool => ! Names::isPrincipal($p['id']))),
			'partitions' => count(array_filter($partitions, static fn (array $p): bool => $p['name'] === null || ! Names::isPartition($p['name']))),
			'paths'      => self::invalidPaths(),
		];

		return [
			'controller'        => Controller::stored(),
			'principals'        => $principals,
			'users_without_id'  => self::usersWithoutId(),
			'partitions'        => $partitions,
			'pages'             => self::pageCounts(),
			'sample'            => self::sample($sample),
			'multi_scope_posts' => ScopeGuard::multiScopePostIds(),
			'invalid'           => $invalid,
		];
	}

	/**
	 * The report as plain lines.
	 *
	 * @param array<string, mixed> $report From build().
	 * @return array<int, string>
	 */
	public static function lines(array $report): array {
		$lines   = [];
		$lines[] = 'Controller: ' . ($report['controller'] ?? '(not set)');

		$lines[] = sprintf('Principals (%d):', count($report['principals']));
		foreach ($report['principals'] as $p) {
			$lines[] = sprintf('  %s  %s  user %d', $p['id'], $p['status'], $p['user_id']) . ($p['fqdn'] !== null ? '  fqdn ' . $p['fqdn'] : '');
		}

		$without = $report['users_without_id'];
		$lines[] = sprintf('Users who take part but have no principal id: %d', count($without)) . ($without !== [] ? ' (user ids: ' . implode(', ', $without) . ')' : '');

		$lines[] = sprintf('Partitions (%d, plus the synthetic default):', count($report['partitions']));
		foreach ($report['partitions'] as $p) {
			$lines[] = sprintf('  %s  term %d', $p['name'] ?? '(none)', $p['term_id']);
		}

		$parts = [];
		foreach ($report['pages'] as $kind => $count) {
			$parts[] = sprintf('%s %d (missing %d)', $kind, $count['indexed'], $count['missing']);
		}
		$lines[] = 'Pages: ' . implode(', ', $parts);

		foreach ($report['sample'] as $kind => $rows) {
			$lines[] = sprintf('Sample %s paths (%d):', $kind, count($rows));
			foreach ($rows as $row) {
				$lines[] = sprintf('  %s  %s', $row['pcp_id'], $row['path']);
			}
		}

		$multi   = $report['multi_scope_posts'];
		$lines[] = sprintf('Posts with several scopes: %d', count($multi)) . ($multi !== [] ? ' (post ids: ' . implode(', ', $multi) . ')' : '');

		$bad     = $report['invalid'];
		$lines[] = sprintf(
			'Invalid under PCP v0.3: principals %d, partitions %d, paths %d',
			$bad['principals'],
			$bad['partitions'],
			$bad['paths']
		);

		return $lines;
	}

	/**
	 * @return array<int, int>
	 */
	private static function usersWithoutId(): array {
		$out = [];
		foreach (get_users(['fields' => 'all', 'orderby' => 'ID', 'order' => 'ASC']) as $user) {
			if ($user instanceof \WP_User && Principals::qualifies($user) && Principals::idFor((int) $user->ID) === null) {
				$out[] = (int) $user->ID;
			}
		}

		return $out;
	}

	/**
	 * @return array<string, array{indexed:int, missing:int}>
	 */
	private static function pageCounts(): array {
		global $wpdb;

		$index  = PageIndex::table();
		$memory = MemoryRepository::memoryTable();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
		$counts = [];
		foreach (Db::getResults("SELECT object_kind, COUNT(*) AS n FROM {$index} GROUP BY object_kind") as $row) {
			$counts[(string) $row['object_kind']] = (int) $row['n'];
		}

		$missingMemory = Db::getResults(
			"SELECT COUNT(*) AS n FROM {$memory} m LEFT JOIN {$index} i ON i.object_kind = %s AND i.object_id = m.id WHERE i.object_id IS NULL",
			[PageIndex::KIND_MEMORY]
		);

		$out = [
			PageIndex::KIND_MEMORY => [
				'indexed' => $counts[PageIndex::KIND_MEMORY] ?? 0,
				'missing' => (int) ($missingMemory[0]['n'] ?? 0),
			],
		];

		foreach (PageIndex::POST_KINDS as $postType => [$kind]) {
			$missing = Db::getResults(
				"SELECT COUNT(*) AS n FROM {$wpdb->posts} p LEFT JOIN {$index} i ON i.object_kind = %s AND i.object_id = p.ID
					WHERE p.post_type = %s AND p.post_status NOT IN ('auto-draft', 'inherit') AND i.object_id IS NULL",
				[$kind, $postType]
			);
			$out[$kind] = [
				'indexed' => $counts[$kind] ?? 0,
				'missing' => (int) ($missing[0]['n'] ?? 0),
			];
		}
		// phpcs:enable

		return $out;
	}

	/**
	 * The first $limit rows of each kind, by object id.
	 *
	 * @return array<string, array<int, array{pcp_id:string, path:string}>>
	 */
	private static function sample(int $limit): array {
		$index = PageIndex::table();
		$out   = [];

		foreach ([PageIndex::KIND_MEMORY, PageIndex::KIND_KNOWLEDGE, PageIndex::KIND_PROCEDURE] as $kind) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$rows = $limit > 0 ? Db::getResults(
				"SELECT pcp_id, path FROM {$index} WHERE object_kind = %s ORDER BY object_id ASC LIMIT %d",
				[$kind, $limit]
			) : [];
			// phpcs:enable

			$out[$kind] = array_map(static fn (array $row): array => [
				'pcp_id' => (string) $row['pcp_id'],
				'path'   => (string) $row['path'],
			], $rows);
		}

		return $out;
	}

	private static function invalidPaths(): int {
		$index  = PageIndex::table();
		$bad    = 0;
		$lastId = '';

		// Checked in PHP with the same pattern the export uses, a page at a time.
		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$rows = Db::getResults(
				"SELECT pcp_id, path FROM {$index} WHERE pcp_id > %s ORDER BY pcp_id ASC LIMIT 1000",
				[$lastId]
			);
			// phpcs:enable

			foreach ($rows as $row) {
				$lastId = (string) $row['pcp_id'];
				if (! Names::isPath((string) $row['path']) || ! Names::isPageId($lastId)) {
					++$bad;
				}
			}
		} while (count($rows) === 1000);

		return $bad;
	}
}
