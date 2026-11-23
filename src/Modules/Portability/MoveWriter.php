<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\DataStore\StoreRestore;
use AgentBrain\Modules\Identity\Scopes;

/**
 * #550: writes a checked move (Moves) inside one transaction: the new
 * scopes, the unbound accounts of people new here, the access the archive
 * gave them on the moved scopes, the pages (ImportWriter) with the history
 * of knowledge and procedures, then the documents and handoffs
 * (ExtensionRestore) and the page store (StoreRestore) under new ids.
 * Like the migration, it writes straight into the tables: no hook
 * proposes, publishes or notifies.
 */
final class MoveWriter {

	use WritesTables;

	/** @var array<string, int> partition name here => scope term id */
	private array $terms = [];

	/** @var array<string, int> archive principal id => user id here */
	private array $users = [];

	/** @var array<int, int> */
	private array $createdUsers = [];

	/** @var array<int, int> */
	private array $createdTerms = [];

	private ?ImportWriter $pages = null;

	/**
	 * @param array<string, mixed> $check The archive check, with Selection::apply().
	 * @param array<string, mixed> $plan  Imports::plan() for these pages.
	 */
	public function __construct(private string $dir, private array $check, private array $plan, private ExtensionRestore $documents, private StoreRestore $data) {
	}

	/**
	 * @param array<string, string>                                              $targets archive partition => partition here
	 * @param array<int, string>                                                 $create  partitions to create here
	 * @param array<string, array{user:int, principal:array<string, mixed>}>     $people  archive principal => user here (0: new)
	 * @param array<string, array<string, mixed>>                                $pages   the pages to import, by path
	 * @return array<string, int> What was written, by kind (counts for the audit entry).
	 */
	public function write(array $targets, array $create, array $people, array $pages, ImportReport $report): array {
		$counts = ['scopes' => 0, 'principals' => 0, 'pages' => 0, 'history' => 0, 'documents' => 0, 'store' => 0];

		foreach (Partitions::all() as $row) {
			if ($row['name'] !== null) {
				$this->terms[(string) $row['name']] = (int) $row['term_id'];
			}
		}
		$labels = [];
		foreach ((array) $this->check['context']['partitions'] as $partition) {
			$labels[(string) $partition['name']] = trim((string) ($partition['description'] ?? ''));
		}
		foreach ($create as $name) {
			$this->terms[$name] = $this->scope($name, $labels[$name] ?? '');
			++$counts['scopes'];
		}

		foreach ($people as $id => $person) {
			$user = $person['user'];
			if ($user === 0) {
				$principal = $person['principal'];
				$name      = (string) ($principal['display_name'] ?? '');
				$user      = Placeholders::account($id, $name, array_values(array_map('strval', (array) ($principal['roles'] ?? []))), null);
				Placeholders::register($id, $user, $name, (string) ($principal['status'] ?? Principals::STATUS_ACTIVE), null);
				$this->createdUsers[] = $user;
				++$counts['principals'];
			}
			$this->users[$id] = $user;
		}

		$this->grants($targets);

		// Pages: as an import, with everyone mapped to who they are here.
		$plan = $this->plan;
		foreach ($targets as $to) {
			$plan['partitions'][$to] = $this->terms[$to];
		}
		foreach ($this->users as $id => $user) {
			$plan['principal_map'][$id]   = $user;
			$plan['principal_ids'][$user] = $id;
		}
		$this->pages = new ImportWriter($this->dir, $plan);

		$landed = [];
		foreach ($pages as $path => $page) {
			$result = $this->pages->import((string) $path, $page, false);
			$report->item($page['file'], $result['outcome'], $result['path'], $result['message']);
			if (in_array($result['outcome'], ['created', 'renamed', 'replaced'], true) && $result['path'] !== null) {
				$landed[(string) $path] = $result['path'];
				++$counts['pages'];
				$counts['history'] += $this->history((string) $path, $result['path']);
			}
		}

		// Documents, handoffs and the page store, by the archive's partition names.
		$terms = [];
		foreach ($targets as $from => $to) {
			$terms[$from] = $this->terms[$to];
		}
		$userOf = fn ($principal): int => is_string($principal) ? ($this->users[$principal] ?? 0) : 0;
		$this->documents->writePosts($userOf, $terms);
		$this->documents->writeLinks(static function (string $path) use ($landed): ?int {
			$object = isset($landed[$path]) ? PageIndex::byPath($landed[$path]) : null;

			return $object !== null && $object['kind'] === PageIndex::KIND_MEMORY ? $object['id'] : null;
		});
		$this->data->write($userOf, fn (int $old): int => $this->documents->to($old));
		$counts['documents'] = $this->documents->rebuilds() ? $this->documents->records() : 0;
		$counts['store']     = $this->data->rebuilds() ? $this->data->records() : 0;

		return $counts;
	}

	/** Caches, and the scope counts. */
	public function afterWrite(): void {
		wp_cache_flush();
		foreach ($this->createdUsers as $userId) {
			clean_user_cache($userId);
		}
		foreach (array_merge($this->createdPosts, $this->pages !== null ? $this->pages->createdPosts() : [], $this->documents->createdPosts()) as $postId) {
			clean_post_cache($postId);
		}

		$ttIds = [];
		foreach (array_unique(array_values($this->terms)) as $termId) {
			$term = get_term($termId, Scopes::TAXONOMY);
			if ($term instanceof \WP_Term) {
				$ttIds[] = (int) $term->term_taxonomy_id;
			}
		}
		if ($ttIds !== []) {
			clean_term_cache($this->createdTerms, Scopes::TAXONOMY);
			wp_update_term_count_now($ttIds, Scopes::TAXONOMY);
		}
	}

	/** A new scope with its partition name, as a migration creates one. */
	private function scope(string $name, string $label): int {
		global $wpdb;

		$wpdb->insert($wpdb->terms, ['name' => $label !== '' ? $label : $name, 'slug' => $name, 'term_group' => 0], ['%s', '%s', '%d']);
		$termId = (int) $wpdb->insert_id;
		if ($termId <= 0) {
			throw new ImportFailed('internal', 'A scope could not be created.');
		}
		$wpdb->insert($wpdb->term_taxonomy, ['term_id' => $termId, 'taxonomy' => Scopes::TAXONOMY, 'description' => '', 'parent' => 0, 'count' => 0], ['%d', '%s', '%s', '%d', '%d']);
		$wpdb->insert($wpdb->termmeta, ['term_id' => $termId, 'meta_key' => Partitions::META, 'meta_value' => $name], ['%d', '%s', '%s']); // phpcs:ignore WordPress.DB.SlowDBQuery -- one insert per scope.
		$this->createdTerms[] = $termId;

		return $termId;
	}

	/**
	 * The archive's access lists of the moved partitions, added to what each
	 * person can already read and write here.
	 *
	 * @param array<string, string> $targets
	 */
	private function grants(array $targets): void {
		global $wpdb;

		$add = [];
		foreach ((array) $this->check['context']['partitions'] as $partition) {
			$name = (string) $partition['name'];
			if (! isset($targets[$name])) {
				continue;
			}
			$termId = $this->terms[$targets[$name]];
			foreach ((array) ($partition['access'] ?? []) as $access) {
				$user = $this->users[(string) ($access['principal'] ?? '')] ?? 0;
				if ($user <= 0) {
					continue;
				}
				$rights = (array) ($access['rights'] ?? []);
				if (in_array('read', $rights, true)) {
					$add[$user][Scopes::META_READ_SCOPES][] = $termId;
				}
				if (in_array('write', $rights, true) || in_array('propose', $rights, true)) {
					$add[$user][Scopes::META_WRITE_SCOPES][] = $termId;
				}
			}
		}

		foreach ($add as $user => $lists) {
			foreach ($lists as $key => $termIds) {
				$raw     = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1", $user, $key));
				$current = $raw !== null ? maybe_unserialize((string) $raw) : [];
				$merged  = array_values(array_unique(array_map('intval', array_merge(is_array($current) ? $current : [], $termIds))));
				$wpdb->delete($wpdb->usermeta, ['user_id' => $user, 'meta_key' => $key], ['%d', '%s']); // phpcs:ignore WordPress.DB.SlowDBQuery -- one account at a time.
				$wpdb->insert($wpdb->usermeta, ['user_id' => $user, 'meta_key' => $key, 'meta_value' => maybe_serialize($merged)], ['%d', '%s', '%s']); // phpcs:ignore WordPress.DB.SlowDBQuery -- one account at a time.
			}
		}
	}

	/**
	 * Earlier versions of a knowledge or procedure page as revisions of the
	 * post it became, oldest first, then its current text, as a migration
	 * writes them.
	 *
	 * @return int Versions written.
	 */
	private function history(string $sourcePath, string $target): int {
		$versions = $this->check['history'][$sourcePath] ?? [];
		$object   = $versions !== [] ? PageIndex::byPath($target) : null;
		if ($object === null || $object['kind'] === PageIndex::KIND_MEMORY) {
			return 0;
		}

		$post = get_post($object['id']);
		if (! $post instanceof \WP_Post) {
			return 0;
		}
		ksort($versions, SORT_NUMERIC);

		$n = 0;
		foreach ($versions as $version) {
			$this->revision($object['id'], (int) $post->post_author, (string) ($version['frontmatter']['title'] ?? $post->post_title), PageFile::parse((string) file_get_contents($this->dir . '/' . $version['file']))['body'], self::mysql((string) ($version['frontmatter']['updated'] ?? '')));
			++$n;
		}
		$this->revision($object['id'], (int) $post->post_author, (string) $post->post_title, (string) $post->post_content, (string) $post->post_modified_gmt);

		return $n;
	}

	private function revision(int $parent, int $author, string $title, string $content, string $when): void {
		$this->insertPost([
			'post_author'       => $author,
			'post_date'         => get_date_from_gmt($when),
			'post_date_gmt'     => $when,
			'post_content'      => $content,
			'post_title'        => $title,
			'post_status'       => 'inherit',
			'post_name'         => $parent . '-revision-v1',
			'post_modified'     => get_date_from_gmt($when),
			'post_modified_gmt' => $when,
			'post_parent'       => $parent,
			'post_type'         => 'revision',
		]);
	}
}
