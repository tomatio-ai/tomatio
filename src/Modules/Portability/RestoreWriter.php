<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\DataStore\StoreRestore;
use AgentBrain\Modules\AgentId\Fqdn;
use AgentBrain\Modules\Audit\Repository as AuditRepository;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Recall\Orientation;
use AgentBrain\Modules\Review\Lifecycle;
use AgentBrain\Modules\Review\PostStatuses;
use AgentBrain\Modules\Review\ProposalService;
use AgentBrain\Modules\Review\ReviewActions;

/**
 * Writes a verified archive into an empty brain (PCP v0.3 SPEC section
 * 8.7.2), so that the brain's own next export gives the same files (8.8):
 * it is the inverse of PageWriter, ContextWriter, ExportBuilder::writeAudit()
 * and ExportBuilder::writeReceipts().
 *
 * Everything goes straight into the tables, not through wp_insert_post(),
 * wp_insert_user() or wp_insert_term(): those would run kses over the
 * bodies (changing their bytes), fire the hooks that propose, publish,
 * compress, notify, issue agent FQDNs and assign new ids and paths, and
 * write audit entries of their own. Side effects are off because nothing
 * that has them is called. The caller runs this inside a transaction and
 * clears the caches afterwards.
 *
 * Ids: memory rows keep their numeric ids (the table is empty, and the
 * counter moves past them); posts get new ids and keep their page ids and
 * paths in ab_pcp_index; principals keep their ids on placeholder accounts
 * (Binding), agents with their identity meta (FQDN, register id, class,
 * issuer, issue time) restored from x_agent_brain.agent_identities and the
 * FQDN validated; partitions keep their names on new scope terms; audit entries
 * keep their ids and times; receipts are kept verbatim (Kept). Artifacts,
 * sections, compiled documents and handoffs keep their post ids
 * (ExtensionRestore), or are kept verbatim when they cannot.
 */
final class RestoreWriter {

	use WritesTables;

	/** @var array<string, int> principal id => user id */
	private array $users = [];

	/** @var array<int, true> placeholder accounts of removed principals, deleted at the end */
	private array $removed = [];

	/** @var array<string, int> partition name => term id */
	private array $terms = [];

	/** @var array<string, int> term id => term_taxonomy id */
	private array $taxonomyIds = [];

	/** @var array<string, array{kind:string, id:int}> page path => object */
	private array $objects = [];

	/** @var array<string, array<string, mixed>> page path => what was written, for the kept keys */
	private array $written = [];

	/** @var array<string, int> */
	private array $counts = ['principals' => 0, 'partitions' => 0, 'rows' => 0, 'posts' => 0, 'history' => 0, 'audit' => 0, 'receipts' => 0, 'extension_files' => 0, 'documents' => 0, 'store' => 0, 'audit_left' => 0];

	/** @var array<string, array<string, mixed>> principal id => the archive's x_agent_brain.agent_identities entry */
	private array $identities = [];

	/** @var array<int, string> restored user id => principal id of the issuer, given an account once every principal has one */
	private array $issuedBy = [];

	/** @var array<int, int> Created user ids (for cache clearing). */
	private array $createdUsers = [];

	private ExtensionRestore $documents;

	private StoreRestore $store;

	private Selection $selection;

	/**
	 * @param array<string, mixed> $check     ArchiveCheck::run() result, without errors.
	 * @param int                  $requester The administrator restoring (0 for WP-CLI without --user).
	 * @param string               $bindAs    A human principal of the archive to bind the requester to, or ''.
	 * @param ExtensionRestore|null $documents The archive's documents and handoffs, when already read.
	 * @param StoreRestore|null     $store     The archive's page store data, when already read.
	 * @param Selection|null        $selection What the migration leaves behind (#549).
	 * @param array<string, int>    $adopt     #582: principal id => the account here that takes that person over.
	 */
	public function __construct(private string $dir, private array $check, private int $requester, private string $bindAs, ?ExtensionRestore $documents = null, ?StoreRestore $store = null, ?Selection $selection = null, private array $adopt = []) {
		$this->selection = $selection ?? Selection::none();
		$this->documents = $documents ?? new ExtensionRestore($dir, $check, $this->selection);
		$this->store     = $store ?? self::storeFor($dir, $check, $this->documents);
	}

	/**
	 * The page store data of the artifacts the documents rebuild (#547).
	 *
	 * @param array<string, mixed> $check
	 */
	public static function storeFor(string $dir, array $check, ExtensionRestore $documents): StoreRestore {
		return new StoreRestore($dir, $check['extension_files'], $documents->artifactIds(), $documents->principalIds(), $documents->leftArtifactIds());
	}

	/**
	 * @return array<string, int> What was written, by kind.
	 */
	public function write(): array {
		$this->controller();
		$this->principals();
		$this->partitions();
		$this->grants();
		// Documents and handoffs first: they keep their post ids, and the
		// posts written after them take new ones past those.
		$this->documents->writePosts(fn ($principal): int => $this->userOf($principal), $this->terms);
		$this->store->write(fn ($principal): int => $this->userOf($principal));
		$this->counts['store'] = $this->store->rebuilds() ? $this->store->records() : 0;
		$this->pages();
		$this->links();
		$this->documents->writeLinks(fn (string $path): ?int => ($this->objects[$path]['kind'] ?? '') === 'memory' ? $this->objects[$path]['id'] : null);
		$this->counts['documents'] = $this->documents->rebuilds() ? $this->documents->records() : 0;
		$this->extras();
		$this->audit();
		$this->receipts();
		$this->extensions();
		$this->profile();
		$this->agentProposals();
		$this->dropRemovedAccounts();

		update_option('ab_pcp_audit_truncated', ! empty($this->check['manifest']['audit']['truncated']) ? '1' : '', false);

		/**
		 * Fires when a restore has written everything, inside its
		 * transaction: throwing here undoes the whole restore.
		 *
		 * @param array<string, int> $counts
		 */
		do_action('agent_brain/restore_written', $this->counts);

		return $this->counts;
	}

	/** @return array<int, string> Files written to disk (uploads), removed again if the migration is undone. */
	public function writtenFiles(): array {
		return $this->documents->writtenFiles();
	}

	/** @return array<int, int> Created post ids, documents included (for cache clearing). */
	public function createdPosts(): array {
		return array_merge($this->createdPosts, $this->documents->createdPosts());
	}

	/** @return array<int, int> */
	public function createdUsers(): array {
		return $this->createdUsers;
	}

	/** @return array<int, int> Term ids of the new scopes. */
	public function createdTerms(): array {
		return array_values($this->terms);
	}

	// -----------------------------------------------------------------
	// Context: controller, principals, partitions, access
	// -----------------------------------------------------------------

	private function controller(): void {
		global $wpdb;

		$controller = (string) $this->check['context']['controller'];
		$previous   = Controller::stored();

		update_option(Controller::OPTION, $controller, false);

		// The empty brain's own controller id named nothing; the archive's replaces it.
		if ($previous !== null && $previous !== $controller) {
			$wpdb->delete(Principals::table(), ['principal_id' => $previous, 'user_id' => 0], ['%s', '%d']);
		}
	}

	private function principals(): void {
		global $wpdb;

		$context = $this->check['context'];
		$fqdns   = is_array($context['x_agent_brain']['agent_fqdns'] ?? null) ? $context['x_agent_brain']['agent_fqdns'] : [];
		$this->identities = array_filter(
			is_array($context['x_agent_brain']['agent_identities'] ?? null) ? $context['x_agent_brain']['agent_identities'] : [],
			'is_array'
		);

		foreach ((array) $context['principals'] as $principal) {
			$id     = (string) $principal['id'];
			$status = (string) $principal['status'];
			$name   = (string) ($principal['display_name'] ?? '');
			$roles  = array_values(array_map('strval', (array) ($principal['roles'] ?? [])));
			$fqdn   = is_string($fqdns[$id] ?? null) ? self::rehomed((string) $fqdns[$id]) : null;
			$type   = (string) strtok($id, ':');

			if ($type === 'org' || $type === 'service') {
				// The controller may already be registered (same host).
				$wpdb->delete(Principals::table(), ['principal_id' => $id, 'user_id' => 0], ['%s', '%d']);
				$this->registryRow($id, 0, $name, $status, null);
				continue;
			}

			if ($id === $this->bindAs && $this->requester > 0) {
				// The requester's own id on the empty brain named nothing.
				$wpdb->delete(Principals::table(), ['user_id' => $this->requester], ['%d']);
				$this->setUserMeta($this->requester, Principals::META, $id);
				$this->registryRow($id, $this->requester, $name, $status, $fqdn);
				$this->users[$id] = $this->requester;
				$this->restoreIdentity($id, $fqdn, $this->requester, $status);
				continue;
			}

			if (isset($this->adopt[$id])) {
				// #582: the account keeps its login, password and role; it becomes this person.
				$adopted = $this->adopt[$id];
				$wpdb->delete(Principals::table(), ['principal_id' => $id], ['%s']);
				$this->setUserMeta($adopted, Principals::META, $id);
				$this->registryRow($id, $adopted, $name, $status, $fqdn);
				$this->users[$id] = $adopted;
				$this->restoreIdentity($id, $fqdn, $adopted, $status);
				continue;
			}

			$userId = $this->placeholder($id, $type, $name, $roles, $status, $fqdn);
			$this->registryRow($id, $userId, $name, $status, $fqdn);
			$this->users[$id] = $userId;

			if ($status === Principals::STATUS_REMOVED) {
				$this->removed[$userId] = true;
			}
		}

		// Issuers travel as principal ids; the accounts exist only now.
		foreach ($this->issuedBy as $userId => $issuer) {
			$issuerId = $this->users[$issuer] ?? 0;
			if ($issuerId > 0 && ! isset($this->removed[$issuerId])) {
				$this->setUserMeta($userId, AgentMeta::ISSUED_BY, $issuerId);
			}
		}
	}

	/**
	 * An agent ID on this brain's own host. A restore moves the brain, so
	 * an agent issued on the old host keeps its name, register id and org
	 * and takes this brain's base: the consent screen, the audit log and
	 * the agent's next key then name the brain it lives on. An ID that
	 * does not parse comes back unchanged, for restoreIdentity() to refuse.
	 */
	public static function rehomed(string $fqdn): string {
		$parsed = Fqdn::parse($fqdn);
		if ($parsed === null) {
			return $fqdn;
		}

		$base = AgentMeta::base();
		if ($base === '' || $base === $parsed['base']) {
			return $parsed['fqdn'];
		}

		return Fqdn::build($parsed['name'], $parsed['register_id'], $parsed['org'], $base) ?? $parsed['fqdn'];
	}

	/**
	 * One account per principal: the login from the id's local part (with
	 * -2, -3 when taken), no email, a random password, no application
	 * password, unbound.
	 *
	 * @param array<int, string> $roles
	 */
	private function placeholder(string $id, string $type, string $name, array $roles, string $status, ?string $fqdn): int {
		$userId               = Placeholders::account($id, $name, $roles, $fqdn);
		$this->createdUsers[] = $userId;
		++$this->counts['principals'];

		if ($type === 'agent') {
			$this->restoreIdentity($id, $fqdn, $userId, $status);
		}

		return $userId;
	}

	/**
	 * An agent's identity meta back on its account, from
	 * x_agent_brain.agent_identities, with archives that only carry
	 * agent_fqdns still getting the FQDN and its register id. An FQDN
	 * that does not parse, or identity data that disagrees with it,
	 * refuses the restore (the transaction undoes every account, so an
	 * agent is never half-created; docs/rfc/agent-id-fqdn.md section 3).
	 */
	private function restoreIdentity(string $id, ?string $fqdn, int $userId, string $status): void {
		$identity     = $this->identities[$id] ?? [];
		$fromIdentity = is_string($identity['fqdn'] ?? null) && $identity['fqdn'] !== '' ? self::rehomed((string) $identity['fqdn']) : null;

		if ($fqdn !== null && $fromIdentity !== null && Fqdn::normalize($fromIdentity) !== Fqdn::normalize($fqdn)) {
			throw new ImportFailed('invalid_archive', 'The archive holds two different agent IDs for one agent.');
		}
		$fqdn ??= $fromIdentity;
		if ($fqdn === null) {
			return;
		}

		$parsed = Fqdn::parse($fqdn);
		if ($parsed === null) {
			throw new ImportFailed('invalid_archive', 'The archive names an agent ID that is not valid.');
		}

		$registerId = is_string($identity['register_id'] ?? null) ? (string) $identity['register_id'] : '';
		if ($registerId !== '' && $registerId !== $parsed['register_id']) {
			throw new ImportFailed('invalid_archive', 'The archive holds an agent register id that does not match its agent ID.');
		}

		$class = is_string($identity['class'] ?? null) ? (string) $identity['class'] : '';
		if ($class !== '' && ! in_array($class, AgentMeta::CLASSES, true)) {
			throw new ImportFailed('invalid_archive', 'The archive names an agent class that is not valid.');
		}

		$this->setUserMeta($userId, AgentMeta::FQDN, $parsed['fqdn']);
		$this->setUserMeta($userId, AgentMeta::REGISTER_ID, $parsed['register_id']);
		if ($class !== '') {
			$this->setUserMeta($userId, AgentMeta::CLASS_NAME, $class);
		}
		$this->setUserMeta($userId, AgentMeta::STATUS, match ($status) {
			Principals::STATUS_SUSPENDED => AgentMeta::STATUS_SUSPENDED,
			Principals::STATUS_REMOVED   => AgentMeta::STATUS_REVOKED,
			default                      => AgentMeta::STATUS_ACTIVE,
		});

		$issuedAt = is_string($identity['issued_at'] ?? null) ? trim((string) $identity['issued_at']) : '';
		if ($issuedAt !== '') {
			$this->setUserMeta($userId, AgentMeta::ISSUED_AT, $issuedAt);
		}

		$issuedBy = is_string($identity['issued_by'] ?? null) ? (string) $identity['issued_by'] : '';
		if ($issuedBy !== '' && $issuedBy !== $id) {
			$this->issuedBy[$userId] = $issuedBy;
		}
	}

	/**
	 * The roles an unbound placeholder gets: the archive's roles that exist
	 * here, with administrator as curator (decision 9).
	 *
	 * @param array<int, string> $roles
	 * @return array<int, string>
	 */
	public static function roles(array $roles): array {
		$out = [];
		foreach ($roles as $role) {
			if ($role === 'administrator') {
				$role = Capabilities::ROLE_CURATOR;
			}
			if (function_exists('get_role') && get_role($role) !== null) {
				$out[$role] = true;
			}
		}

		return array_keys($out);
	}

	private function registryRow(string $id, int $userId, string $name, string $status, ?string $fqdn): void {
		Placeholders::register($id, $userId, $name, $status, $fqdn);
	}

	private function partitions(): void {
		global $wpdb;

		foreach ((array) $this->check['context']['partitions'] as $partition) {
			$name = (string) $partition['name'];
			if (self::isSyntheticDefault($partition)) {
				continue;
			}

			$label = trim((string) ($partition['description'] ?? ''));
			$wpdb->insert($wpdb->terms, ['name' => $label !== '' ? $label : $name, 'slug' => $name, 'term_group' => 0], ['%s', '%s', '%d']);
			$termId = (int) $wpdb->insert_id;
			if ($termId <= 0) {
				throw new ImportFailed('internal', 'A scope could not be created.');
			}
			$wpdb->insert($wpdb->term_taxonomy, ['term_id' => $termId, 'taxonomy' => Scopes::TAXONOMY, 'description' => '', 'parent' => 0, 'count' => 0], ['%d', '%s', '%s', '%d', '%d']);
			$this->taxonomyIds[(string) $termId] = (int) $wpdb->insert_id;
			$wpdb->insert($wpdb->termmeta, ['term_id' => $termId, 'meta_key' => Partitions::META, 'meta_value' => $name], ['%d', '%s', '%s']); // phpcs:ignore WordPress.DB.SlowDBQuery -- one insert per scope.

			$this->terms[$name] = $termId;
			++$this->counts['partitions'];
		}
	}

	/**
	 * The default partition PCP requires, which TomaTío lists without a
	 * scope when no scope is named default: no description, no access.
	 *
	 * @param array<string, mixed> $partition
	 */
	public static function isSyntheticDefault(array $partition): bool {
		return ($partition['name'] ?? '') === Partitions::DEFAULT_NAME
			&& trim((string) ($partition['description'] ?? '')) === ''
			&& (array) ($partition['access'] ?? []) === [];
	}

	/** Access lists back to scope grants: read, and propose or write. Review follows the role. */
	private function grants(): void {
		$read  = [];
		$write = [];

		foreach ((array) $this->check['context']['partitions'] as $partition) {
			$termId = $this->terms[(string) $partition['name']] ?? null;
			if ($termId === null) {
				continue;
			}
			foreach ((array) $partition['access'] as $access) {
				$userId = $this->users[(string) $access['principal']] ?? null;
				if ($userId === null || isset($this->removed[$userId])) {
					continue;
				}
				$rights = (array) $access['rights'];
				if (in_array('read', $rights, true)) {
					$read[$userId][] = $termId;
				}
				if (in_array('write', $rights, true) || in_array('propose', $rights, true)) {
					$write[$userId][] = $termId;
				}
			}
		}

		foreach ($read as $userId => $ids) {
			$this->setUserMeta($userId, Scopes::META_READ_SCOPES, array_values(array_unique($ids)));
		}
		foreach ($write as $userId => $ids) {
			$this->setUserMeta($userId, Scopes::META_WRITE_SCOPES, array_values(array_unique($ids)));
		}
	}

	// -----------------------------------------------------------------
	// Pages
	// -----------------------------------------------------------------

	private function pages(): void {
		$rows  = [];
		$posts = [];

		foreach ($this->check['pages'] as $path => $page) {
			if (self::isRowPage($page['frontmatter'])) {
				$rows[$path] = $page;
			} else {
				$posts[$path] = $page;
			}
		}

		uksort($rows, static fn (string $a, string $b): int => self::idOrder($rows[$a]['frontmatter'], $rows[$b]['frontmatter']) ?: strcmp($a, $b));
		uksort($posts, static fn (string $a, string $b): int => self::idOrder($posts[$a]['frontmatter'], $posts[$b]['frontmatter']) ?: strcmp($a, $b));

		foreach ($rows as $path => $page) {
			$this->writeRow((string) $path, $page);
		}
		foreach ($posts as $path => $page) {
			$this->writePost((string) $path, $page);
		}
	}

	/**
	 * Whether a page is an TomaTío memory row (it names its row type),
	 * rather than a knowledge or procedure post.
	 *
	 * @param array<string, mixed> $frontmatter
	 */
	public static function isRowPage(array $frontmatter): bool {
		return is_array($frontmatter['x_agent_brain'] ?? null) && isset($frontmatter['x_agent_brain']['type']);
	}

	/**
	 * TomaTío page ids end in the source's numeric id; ordering by it
	 * keeps the source's order.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	private static function idOrder(array $a, array $b): int {
		return self::numericId((string) ($a['id'] ?? '')) <=> self::numericId((string) ($b['id'] ?? ''));
	}

	private static function numericId(string $id): int {
		return preg_match('/^ab-[a-z]+-([0-9]{1,18})$/', $id, $m) === 1 ? (int) $m[1] : PHP_INT_MAX;
	}

	/**
	 * @param array{file:string, sha256:string, frontmatter:array<string, mixed>} $page
	 */
	private function writeRow(string $path, array $page): void {
		$fm     = $page['frontmatter'];
		$extra  = (array) $fm['x_agent_brain'];
		$source = is_array($fm['source'] ?? null) ? $fm['source'] : [];
		$review = is_array($fm['review'] ?? null) ? $fm['review'] : [];
		$type   = (string) $extra['type'];
		if (! in_array($type, MemoryRepository::TYPES, true)) {
			$type = ($fm['type'] ?? '') === 'episodic' ? 'episode' : 'fact';
		}

		$status = 'active';
		if (($review['state'] ?? '') === 'proposed') {
			$status = 'proposed';
		} elseif (($fm['lifecycle'] ?? '') === 'archived') {
			$status = 'rolled_up';
		}

		$data = [
			'agent_id'      => $this->userOf($source['principal'] ?? null),
			'scope_id'      => $this->terms[(string) ($fm['partition'] ?? Partitions::DEFAULT_NAME)] ?? 0,
			'type'          => $type,
			'content'       => $this->body($page['file']),
			'search_keys'   => is_string($extra['search_keys'] ?? null) ? $extra['search_keys'] : null,
			'importance'    => isset($fm['importance']) ? (int) round(((float) $fm['importance']) * 100) : 50,
			'source_ids'    => null,
			'status'        => $status,
			'agent_written' => ($source['origin'] ?? '') === 'agent' ? 1 : 0,
			'client'        => is_string($source['client'] ?? null) ? $source['client'] : null,
			'via'           => isset($source['on_behalf_of']) ? $this->userOf($source['on_behalf_of']) : null,
			'created'       => self::mysql((string) ($fm['recorded_at'] ?? $fm['updated'])),
			'expires'       => is_string($extra['rollover_at'] ?? null) ? self::mysql($extra['rollover_at']) : null,
		];
		$formats = ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s'];

		$wanted = self::numericId((string) ($fm['id'] ?? ''));
		if ($wanted !== PHP_INT_MAX && $wanted > 0 && str_starts_with((string) ($fm['id'] ?? ''), 'ab-memory-')) {
			$data    = ['id' => $wanted] + $data;
			$formats = array_merge(['%d'], $formats);
		}

		if ($data['via'] === 0) {
			$data['via'] = null;
		}

		$rowId  = $this->insertRow($data, $formats);
		$pcpId  = (string) ($fm['id'] ?? 'ab-memory-' . $rowId);
		$this->index(PageIndex::KIND_MEMORY, $rowId, $pcpId, $path);
		$this->objects[$path] = ['kind' => 'memory', 'id' => $rowId];
		$this->written[$path] = ['row' => ['id' => $rowId, 'pcp_id' => $pcpId] + $data, 'author' => $source['principal'] ?? null, 'via' => $source['on_behalf_of'] ?? null];
		++$this->counts['rows'];
	}

	/**
	 * @param array{file:string, sha256:string, frontmatter:array<string, mixed>} $page
	 */
	private function writePost(string $path, array $page): void {
		global $wpdb;

		$fm       = $page['frontmatter'];
		$extra    = is_array($fm['x_agent_brain'] ?? null) ? $fm['x_agent_brain'] : [];
		$source   = is_array($fm['source'] ?? null) ? $fm['source'] : [];
		$review   = is_array($fm['review'] ?? null) ? $fm['review'] : [];
		$postType = ($fm['type'] ?? '') === 'procedural' ? 'ab_procedure' : 'ab_knowledge';
		$state    = (string) ($review['state'] ?? 'approved');
		$status   = match ($state) {
			'proposed' => PostStatuses::PROPOSED,
			'rejected' => PostStatuses::REJECTED,
			default    => 'private',
		};

		$author   = $this->userOf($source['principal'] ?? null);
		$recorded = self::mysql((string) ($fm['recorded_at'] ?? $fm['updated']));
		$updated  = self::mysql((string) $fm['updated']);
		$segments = explode('/', $path);
		$body     = $this->body($page['file']);

		$postId = $this->insertPost([
			'post_author'       => $author,
			'post_date'         => get_date_from_gmt($recorded),
			'post_date_gmt'     => $recorded,
			'post_content'      => $body,
			'post_title'        => (string) $fm['title'],
			'post_status'       => $status,
			'post_name'         => substr(strtolower((string) end($segments)), 0, 200),
			'post_modified'     => get_date_from_gmt($updated),
			'post_modified_gmt' => $updated,
			'post_parent'       => 0,
			'post_type'         => $postType,
		]);

		if (($source['origin'] ?? '') === 'agent' && $author > 0) {
			$this->setPostMeta($postId, ProposalService::META_PROPOSING_AGENT, $author);
		}
		if (isset($fm['importance'])) {
			$this->setPostMeta($postId, ProposalService::META_IMPORTANCE, (int) round(((float) $fm['importance']) * 100));
		}
		if (is_string($extra['search_keys'] ?? null)) {
			$this->setPostMeta($postId, ProposalService::META_SEARCH_KEYS, $extra['search_keys']);
		}
		// #594: a reference (stale) or archived page stays one.
		$lifecycle = Lifecycle::stored($fm['lifecycle'] ?? null);
		if ($lifecycle !== null) {
			$this->setPostMeta($postId, Lifecycle::META, $lifecycle);
		}

		$reviewer = null;
		$at       = null;
		if ($state === 'rejected' && empty($extra['review_by_unknown'])) {
			$reviewer = $review['rejected']['by'] ?? null;
			$at       = $review['rejected']['at'] ?? null;
		} elseif ($state === 'approved' && is_array($review['verified'][0] ?? null)) {
			$reviewer = $review['verified'][0]['by'] ?? null;
			$at       = $review['verified'][0]['at'] ?? null;
		}
		if ($state === 'rejected' && ! empty($extra['review_by_unknown'])) {
			$at = $review['rejected']['at'] ?? null;
		}
		if (is_string($reviewer) && $this->userOf($reviewer) > 0) {
			$this->setPostMeta($postId, ReviewActions::META_REVIEWED_BY, $this->userOf($reviewer));
		}
		if (is_string($at) && ($state === 'rejected' || (is_string($reviewer) && $this->userOf($reviewer) > 0))) {
			$this->setPostMeta($postId, ReviewActions::META_REVIEWED_AT, self::mysql($at));
		}
		if ($state === 'rejected') {
			$this->setPostMeta($postId, ReviewActions::META_REVIEW_OUTCOME, ReviewActions::OUTCOME_REJECTED);
		} elseif (($extra['review_outcome'] ?? '') === ReviewActions::OUTCOME_EDITED) {
			$this->setPostMeta($postId, ReviewActions::META_REVIEW_OUTCOME, ReviewActions::OUTCOME_EDITED);
		}

		// The page's partition, and for legacy posts the others it was in.
		$names = [(string) ($fm['partition'] ?? Partitions::DEFAULT_NAME)];
		foreach ((array) ($extra['also_in'] ?? []) as $name) {
			$names[] = (string) $name;
		}
		$order = 0;
		foreach ($names as $name) {
			$termId = $this->terms[$name] ?? null;
			if ($termId !== null) {
				$wpdb->insert($wpdb->term_relationships, ['object_id' => $postId, 'term_taxonomy_id' => $this->taxonomyIds[(string) $termId], 'term_order' => $order++], ['%d', '%d', '%d']);
			}
		}

		$kind  = $postType === 'ab_procedure' ? PageIndex::KIND_PROCEDURE : PageIndex::KIND_KNOWLEDGE;
		$pcpId = (string) ($fm['id'] ?? 'ab-' . $kind . '-' . $postId);
		$this->index($kind, $postId, $pcpId, $path);
		$this->objects[$path] = ['kind' => 'post', 'id' => $postId];
		$this->written[$path] = [
			'post' => [
				'ID'                => $postId,
				'post_type'         => $postType,
				'post_status'       => $status,
				'post_title'        => (string) $fm['title'],
				'post_content'      => $body,
				'post_date_gmt'     => $recorded,
				'post_modified_gmt' => $updated,
				'path'              => $path,
				'pcp_id'            => $pcpId,
			],
			'in'   => [
				'author'         => $author > 0 ? (string) ($source['principal'] ?? '') : null,
				'by_agent'       => ($source['origin'] ?? '') === 'agent' && $author > 0,
				'names'          => $names,
				'search_keys'    => is_string($extra['search_keys'] ?? null) ? $extra['search_keys'] : '',
				'importance'     => isset($fm['importance']) ? (int) round(((float) $fm['importance']) * 100) : null,
				'reviewer'       => is_string($reviewer) && $this->userOf($reviewer) > 0 ? $reviewer : null,
				'reviewed_at'    => is_string($at) && ($state === 'rejected' || (is_string($reviewer) && $this->userOf($reviewer) > 0)) ? self::mysql($at) : '',
				'review_outcome' => $state === 'rejected' ? ReviewActions::OUTCOME_REJECTED : (($extra['review_outcome'] ?? '') === ReviewActions::OUTCOME_EDITED ? ReviewActions::OUTCOME_EDITED : ''),
				'lifecycle'      => $lifecycle,
			],
		];
		++$this->counts['posts'];

		$this->history($postId, $path, $author, (string) $fm['title'], $body, $updated);
	}

	/**
	 * Earlier versions as WordPress revisions, oldest first, then one for
	 * the current text, as WordPress keeps one (the export leaves it out).
	 */
	private function history(int $postId, string $path, int $author, string $title, string $body, string $updated): void {
		$n = 0;
		foreach ($this->check['history'][$path] ?? [] as $version) {
			$fm   = $version['frontmatter'];
			$when = self::mysql((string) $fm['updated']);
			$this->insertPost([
				'post_author'       => $author,
				'post_date'         => get_date_from_gmt($when),
				'post_date_gmt'     => $when,
				'post_content'      => $this->body($version['file']),
				'post_title'        => (string) $fm['title'],
				'post_status'       => 'inherit',
				'post_name'         => $postId . '-revision-v1',
				'post_modified'     => get_date_from_gmt($when),
				'post_modified_gmt' => $when,
				'post_parent'       => $postId,
				'post_type'         => 'revision',
			]);
			++$n;
		}

		if ($n === 0) {
			return;
		}

		$this->insertPost([
			'post_author'       => $author,
			'post_date'         => get_date_from_gmt($updated),
			'post_date_gmt'     => $updated,
			'post_content'      => $body,
			'post_title'        => $title,
			'post_status'       => 'inherit',
			'post_name'         => $postId . '-revision-v1',
			'post_modified'     => get_date_from_gmt($updated),
			'post_modified_gmt' => $updated,
			'post_parent'       => $postId,
			'post_type'         => 'revision',
		]);
		$this->counts['history'] += $n;
	}

	/** derived_from back into the links table (sources are memory rows). */
	private function links(): void {
		global $wpdb;

		foreach ($this->check['pages'] as $path => $page) {
			$target = $this->objects[(string) $path] ?? null;
			if ($target === null) {
				continue;
			}
			foreach ((array) ($page['frontmatter']['derived_from'] ?? []) as $sourcePath) {
				$source = $this->objects[(string) $sourcePath] ?? null;
				if ($source === null || $source['kind'] !== 'memory') {
					continue;
				}
				$wpdb->query($wpdb->prepare(
					'INSERT IGNORE INTO ' . MemoryRepository::linksTable() . ' (derived_kind, derived_id, source_id) VALUES (%s, %d, %d)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.
					$target['kind'],
					$target['id'],
					$source['id']
				));
			}
		}
	}

	/**
	 * Keys the columns cannot give back (a row's own title, an imported
	 * page's source and x_import, tags, relations, ...) are kept, so the
	 * next export gives the archive's frontmatter again (SPEC 8.8).
	 */
	private function extras(): void {
		$paths = [];
		foreach ($this->objects as $path => $object) {
			$paths[$object['kind'] . ':' . $object['id']] = $path;
		}
		$derived = $this->derivedPaths($paths);

		foreach ($this->written as $path => $written) {
			$page = $this->check['pages'][$path]['frontmatter'];
			if (isset($written['row'])) {
				$row    = $written['row'];
				$native = PageWriter::rowFrontmatter(
					$row,
					is_string($written['author']) && $this->userOf($written['author']) > 0 ? $written['author'] : null,
					is_string($written['via']) && $this->userOf($written['via']) > 0 ? $written['via'] : null,
					$this->partitionName((int) $row['scope_id']),
					$derived['memory:' . $row['id']] ?? []
				);
				$this->keepExtra('memory', (int) $row['id'], $page, $native);
				continue;
			}

			$in    = $written['in'];
			$names = array_values(array_unique(array_filter($in['names'], fn (string $name): bool => isset($this->terms[$name]))));
			sort($names, SORT_STRING);
			[$native] = PageWriter::postFrontmatter($written['post'], [
				'author'         => $in['author'],
				'by_agent'       => $in['by_agent'],
				'partition'      => $names === [] ? Partitions::DEFAULT_NAME : (string) array_shift($names),
				'also_in'        => $names,
				'search_keys'    => $in['search_keys'],
				'importance'     => $in['importance'],
				'reviewer'       => $in['reviewer'],
				'reviewed_at'    => $in['reviewed_at'],
				'review_outcome' => $in['review_outcome'],
				'derived_from'   => $derived['post:' . $written['post']['ID']] ?? [],
				'lifecycle'      => $in['lifecycle'] ?? null,
			]);
			$this->keepExtra('post', (int) $written['post']['ID'], $page, $native);
		}
	}

	/**
	 * What the links table now gives each page as derived_from: paths of
	 * memory rows, by row id.
	 *
	 * @param array<string, string> $paths "kind:id" => path
	 * @return array<string, array<int, string>>
	 */
	private function derivedPaths(array $paths): array {
		$out = [];
		foreach ($this->check['pages'] as $path => $page) {
			$target = $this->objects[(string) $path] ?? null;
			if ($target === null) {
				continue;
			}
			$sources = [];
			foreach ((array) ($page['frontmatter']['derived_from'] ?? []) as $sourcePath) {
				$source = $this->objects[(string) $sourcePath] ?? null;
				if ($source !== null && $source['kind'] === 'memory') {
					$sources[$source['id']] = $paths['memory:' . $source['id']] ?? (string) $sourcePath;
				}
			}
			ksort($sources, SORT_NUMERIC);
			$out[$target['kind'] . ':' . $target['id']] = array_values($sources);
		}

		return $out;
	}

	private function partitionName(int $termId): string {
		$name = array_search($termId, $this->terms, true);

		return is_string($name) ? $name : Partitions::DEFAULT_NAME;
	}

	// -----------------------------------------------------------------
	// Audit, receipts, extensions, profile
	// -----------------------------------------------------------------

	/**
	 * Audit entries keep their ids and times (SPEC 8.7.2 item 4): each line
	 * is kept as written in ab_audit.meta (object_type pcp_restored), which
	 * the export writes back verbatim. The empty brain's own entries (of
	 * the install) are replaced, so the ids cannot clash.
	 */
	private function audit(): void {
		global $wpdb;

		$file = $this->dir . '/audit/log.jsonl';
		if (! is_file($file)) {
			return;
		}

		$table = AuditRepository::tableName();
		$wpdb->query("DELETE FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.

		$lines = [];
		$ids   = [];
		$handle = fopen($file, 'rb');
		if ($handle === false) {
			throw new ImportFailed('internal', 'The audit log could not be read.');
		}
		while (($line = fgets($handle)) !== false) {
			$line = rtrim($line, "\r\n");
			if (trim($line) === '') {
				continue;
			}
			$entry = json_decode($line, true);
			// #549: what people left behind did stays behind with them.
			if (is_array($entry) && ($this->selection->leavesPrincipal($entry['principal'] ?? null) || $this->selection->leavesPrincipal($entry['on_behalf_of'] ?? null))) {
				++$this->counts['audit_left'];
				continue;
			}
			$lines[] = $line;
			$ids[]   = is_array($entry) && preg_match('/^ab-audit-([0-9]{1,18})$/', (string) ($entry['id'] ?? ''), $m) === 1 ? (int) $m[1] : 0;
		}
		fclose($handle);

		// Keep the numeric ids only when every line has one, in rising order.
		$keepIds = $ids !== [] && ! in_array(0, $ids, true);
		for ($i = 1; $keepIds && $i < count($ids); $i++) {
			$keepIds = $ids[$i] > $ids[$i - 1];
		}

		foreach ($lines as $i => $line) {
			$entry = (array) json_decode($line, true);
			$row   = [
				'ts'          => self::mysql((string) ($entry['at'] ?? ''), true),
				'actor_id'    => $this->userOf($entry['principal'] ?? null),
				'event'       => substr((string) ($entry['action'] ?? 'event'), 0, 191),
				'status'      => match ((string) ($entry['outcome'] ?? '')) {
					'ok'        => AuditRepository::STATUS_OK,
					'forbidden' => AuditRepository::STATUS_DENIED,
					default     => AuditRepository::STATUS_ERROR,
				},
				'object_type' => Restores::RESTORED_AUDIT_TYPE,
				'object_id'   => null,
				'args_hash'   => null,
				'meta'        => $line,
				'duration_ms' => isset($entry['duration_ms']) && is_int($entry['duration_ms']) ? $entry['duration_ms'] : null,
			];
			$formats = ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d'];
			if ($keepIds) {
				$row     = ['id' => $ids[$i]] + $row;
				$formats = array_merge(['%d'], $formats);
			}
			if ($wpdb->insert($table, $row, $formats) === false) {
				throw new ImportFailed('internal', 'The audit log could not be migrated.');
			}
			++$this->counts['audit'];
		}
	}

	private function receipts(): void {
		$file = $this->dir . '/erasures/receipts.jsonl';
		if (! is_file($file)) {
			return;
		}

		$handle = fopen($file, 'rb');
		if ($handle === false) {
			throw new ImportFailed('internal', 'The erasure receipts could not be read.');
		}
		while (($line = fgets($handle)) !== false) {
			$line = rtrim($line, "\r\n");
			if (trim($line) !== '') {
				Kept::add(Kept::KIND_RECEIPT, '', $line);
				++$this->counts['receipts'];
			}
		}
		fclose($handle);
	}

	/**
	 * Extension data this version does not rebuild is kept verbatim and
	 * exported again unchanged (SPEC 8.5 item 4): other tools' extensions,
	 * and TomaTío's own documents and handoffs when ExtensionRestore
	 * could not rebuild them.
	 */
	private function extensions(): void {
		$rebuilt = $this->documents->rebuilds();
		$store   = $this->store->rebuilds();
		foreach ($this->check['extensions'] as $entry) {
			if (($rebuilt && ExtensionRestore::owns((string) $entry['path'])) || ($store && StoreRestore::owns((string) $entry['path']))) {
				continue;
			}
			Kept::add(Kept::KIND_EXTENSION_ENTRY, (string) $entry['path'], (string) wp_json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}
		foreach ($this->check['extension_files'] as $path) {
			if (($rebuilt && ExtensionRestore::owns($path)) || ($store && StoreRestore::owns($path))) {
				continue;
			}
			Kept::add(Kept::KIND_EXTENSION, $path, (string) file_get_contents($this->dir . '/' . $path));
			++$this->counts['extension_files'];
		}
	}

	/** profile/team.json: the brain's name, purpose and house rules. */
	private function profile(): void {
		$profile = $this->check['profile'];
		if (! is_array($profile)) {
			return;
		}

		if (is_string($profile['name'] ?? null)) {
			update_option('blogname', $profile['name']);
		}
		update_option('blogdescription', is_string($profile['purpose'] ?? null) ? $profile['purpose'] : '');
		if (is_string($profile['ai_instructions'] ?? null)) {
			Orientation::saveHouseRules($profile['ai_instructions']);
		}
	}

	/**
	 * Agents an AI proposed and nobody decided yet, from context.json's
	 * x_agent_brain.agent_proposals, back where Identity\AgentProposals
	 * keeps them. A proposal holds no credential.
	 */
	private function agentProposals(): void {
		$proposals = $this->check['context']['x_agent_brain']['agent_proposals'] ?? null;
		if (! is_array($proposals) || $proposals === []) {
			return;
		}

		$scopeIds = fn ($names): array => array_values(array_filter(array_map(fn ($name): int => $this->terms[(string) $name] ?? 0, (array) $names)));

		$pending = [];
		foreach ($proposals as $proposal) {
			if (! is_array($proposal) || ! is_string($proposal['id'] ?? null) || ! isset($proposal['name'], $proposal['role'])) {
				continue;
			}
			$person = $this->userOf($proposal['proposed_by'] ?? null);
			$acting = $this->userOf($proposal['via_agent'] ?? null);

			$pending[$proposal['id']] = [
				'id'              => $proposal['id'],
				'name'            => (string) $proposal['name'],
				'role'            => (string) $proposal['role'],
				'read_scope_ids'  => $scopeIds($proposal['read_partitions'] ?? []),
				'write_scope_ids' => $scopeIds($proposal['write_partitions'] ?? []),
				'person_id'       => $person,
				'acting_id'       => $acting > 0 ? $acting : $person,
				'created'         => is_string($proposal['created'] ?? null) ? self::mysql($proposal['created']) : gmdate('Y-m-d H:i:s'),
			];
		}

		update_option('agent_brain_agent_proposals', $pending, false);
	}

	/**
	 * Removed principals had accounts the source deleted: their placeholder
	 * accounts go, and the registry keeps their ids as removed, as on the
	 * source (rows still name the account id).
	 */
	private function dropRemovedAccounts(): void {
		global $wpdb;

		foreach (array_keys($this->removed) as $userId) {
			$wpdb->delete($wpdb->usermeta, ['user_id' => $userId], ['%d']);
			$wpdb->delete($wpdb->users, ['ID' => $userId], ['%d']);
		}
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * @param mixed $principal
	 */
	private function userOf($principal): int {
		return is_string($principal) ? ($this->users[$principal] ?? 0) : 0;
	}

	private function body(string $file): string {
		$page = PageFile::parse((string) file_get_contents($this->dir . '/' . $file));

		return $page['body'];
	}

	/**
	 * @param mixed $value
	 */
	private function setUserMeta(int $userId, string $key, $value): void {
		global $wpdb;

		$wpdb->delete($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $key], ['%d', '%s']); // phpcs:ignore WordPress.DB.SlowDBQuery -- one account at a time.
		$wpdb->insert($wpdb->usermeta, ['user_id' => $userId, 'meta_key' => $key, 'meta_value' => maybe_serialize($value)], ['%d', '%s', '%s']); // phpcs:ignore WordPress.DB.SlowDBQuery -- one account at a time.
	}
}
