<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Identity\AgentHumans;
use AgentBrain\Modules\Identity\AgentProposals;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\OAuth\ConnectChoices;
use WP_User;

/**
 * #701: everything waiting for the current person in the Review inbox, as
 * one list: facts and procedures agents propose, handoffs, memories from an
 * import, and agents an agent proposed. Oldest first, each with what the inbox
 * shows (full text, who suggested it and for whom, scope, how long it has
 * waited, when it closes by itself) and what its form posts. Reading only:
 * every decision still goes through the type's own handler, which
 * re-checks the person and the object.
 */
final class InboxItems {

	public const TYPE_FACT      = 'fact';
	public const TYPE_PROCEDURE = 'procedure';
	public const TYPE_HANDOFF   = 'handoff';
	public const TYPE_MEMORY    = 'memory';
	public const TYPE_AGENT     = 'agent';

	public const TYPES = [self::TYPE_FACT, self::TYPE_PROCEDURE, self::TYPE_HANDOFF, self::TYPE_MEMORY, self::TYPE_AGENT];

	/** Characters of a source memory shown under "From". */
	private const SOURCE_CHARS = 280;

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(?int $viewerId = null): array {
		$viewerId ??= get_current_user_id();

		$items = array_merge(
			self::proposals($viewerId),
			self::handoffs($viewerId),
			self::memories(),
			self::agentProposals($viewerId)
		);

		usort($items, static fn (array $a, array $b): int => [$a['since'], $a['key']] <=> [$b['since'], $b['key']]);

		return $items;
	}

	/**
	 * Proposed facts and procedures within the viewer's scopes (#552).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function proposals(int $viewerId): array {
		$posts = get_posts([
			'post_type'      => ['ab_knowledge', 'ab_procedure'],
			'post_status'    => PostStatuses::PROPOSED,
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		]);

		$items = [];
		foreach ($posts as $post) {
			if (! ScopeVisibility::canSeePost($viewerId, $post)) {
				continue;
			}

			$scopeTerms = wp_get_post_terms($post->ID, Scopes::TAXONOMY);
			$scope      = is_array($scopeTerms) && $scopeTerms !== [] ? $scopeTerms[0] : null;
			$scopeId    = $scope instanceof \WP_Term ? (int) $scope->term_id : 0;
			$agentId    = (int) get_post_meta($post->ID, ProposalService::META_PROPOSING_AGENT, true);
			$plain      = self::decode(ReviewQueuePage::blockExcerpt($post->post_content));
			$memoryIds  = get_post_meta($post->ID, ProposalService::META_SOURCE_MEMORY_IDS, true);
			$memoryIds  = is_array($memoryIds) ? array_values(array_filter(array_map('intval', $memoryIds))) : [];

			$items[] = [
				'key'       => 'k-' . $post->ID,
				'type'      => $post->post_type === 'ab_procedure' ? self::TYPE_PROCEDURE : self::TYPE_FACT,
				'id'        => $post->ID,
				'title'     => $post->post_title !== '' ? self::decode($post->post_title) : '#' . $post->ID,
				'text'      => $plain,
				'paragraphs' => self::paragraphs($plain),
				'words'     => self::wordCount($plain),
				'scope'     => $scope instanceof \WP_Term ? $scope->name : '',
				'scope_id'  => $scopeId,
				'by'        => self::who($agentId),
				'since'     => (string) $post->post_date_gmt,
				'closes'    => Expiry::expiresAt((string) $post->post_modified_gmt),
				'match'     => Diff::compare($scopeId, $post->post_content, $post->post_type),
				'sources'   => count($memoryIds),
				'source'    => self::sourceMemory($viewerId, $memoryIds),
				'edit_link' => (string) get_edit_post_link($post->ID, 'raw'),
			];
		}

		return $items;
	}

	/** @return array<int, array<string, mixed>> */
	private static function handoffs(int $viewerId): array {
		$items = [];
		foreach (HandoffQueue::openHandoffs($viewerId) as $row) {
			$items[] = [
				'key'      => 'h-' . $row['id'],
				'type'     => self::TYPE_HANDOFF,
				'id'       => (int) $row['id'],
				'title'    => (string) $row['title'] !== '' ? self::decode((string) $row['title']) : '#' . $row['id'],
				'text'     => self::decode(wp_strip_all_tags((string) $row['note'])),
				'paragraphs' => self::paragraphs(self::decode(wp_strip_all_tags((string) $row['note']))),
				'scope'    => '',
				'scope_id' => 0,
				'by'       => self::who((int) $row['from_agent_id']),
				'to'       => (int) $row['to_agent_id'] > 0 ? self::who((int) $row['to_agent_id']) : null,
				'memories' => count((array) $row['memory_ids']),
				'grants'   => self::scopeNames(HandoffQueue::scopeIds((array) $row['memory_ids'])),
				'since'    => (string) $row['created'],
				'closes'   => Expiry::expiresAt((string) $row['modified']),
			];
		}

		return $items;
	}

	/** @return array<int, array<string, mixed>> */
	private static function memories(): array {
		if (! current_user_can(ReviewQueuePage::CAPABILITY)) {
			return [];
		}

		$items = [];
		foreach (MemoryProposalQueue::pending() as $row) {
			$items[] = [
				'key'      => 'm-' . $row['id'],
				'type'     => self::TYPE_MEMORY,
				'id'       => $row['id'],
				'title'    => wp_trim_words($row['content'], 10),
				'text'     => $row['content'],
				'paragraphs' => self::paragraphs($row['content']),
				'kind'     => $row['type'],
				'scope'    => $row['scope'],
				'scope_id' => 0,
				'by'       => null,
				'since'    => $row['created'],
				'closes'   => null,
			];
		}

		return $items;
	}

	/** @return array<int, array<string, mixed>> */
	private static function agentProposals(int $viewerId): array {
		if (! AgentProposals::canReview($viewerId)) {
			return [];
		}

		AgentProposals::pruneExpired();

		$items = [];
		foreach (AgentProposals::pending() as $proposal) {
			$created = $proposal['created'] !== '' ? $proposal['created'] : gmdate('Y-m-d H:i:s');
			$by      = $proposal['acting_id'] > 0 ? $proposal['acting_id'] : $proposal['person_id'];

			$items[] = [
				'key'      => 'a-' . $proposal['id'],
				'type'     => self::TYPE_AGENT,
				'id'       => $proposal['id'],
				'title'    => $proposal['name'],
				'text'     => '',
				'paragraphs' => [],
				'role'     => $proposal['role'],
				'reads'    => self::scopeNames($proposal['read_scope_ids']),
				'writes'   => self::scopeNames($proposal['write_scope_ids']),
				'scope'    => '',
				'scope_id' => 0,
				'by'       => self::who((int) $by),
				'since'    => $created,
				'closes'   => gmdate('Y-m-d H:i:s', (int) strtotime($created . ' UTC') + AgentProposals::EXPIRES_AFTER),
			];
		}

		return $items;
	}

	/**
	 * Who an item came from, as people read it (#701): the agent's name
	 * (its nickname, else the name part of its agent ID: "dennis-ai" from
	 * dennis-ai-6aae48f5.agnt.example.com) and the person it works for.
	 * label is the short form the list shows: "Dennis's Tío" for a
	 * person's own default agent, "docs-writer · Roger's Tío" for another
	 * agent of theirs, the name alone otherwise.
	 *
	 * @return array{id:int, name:string, person:string, label:string, account:string}
	 */
	public static function who(int $userId): array {
		$user = $userId > 0 ? get_userdata($userId) : false;
		if (! $user instanceof WP_User) {
			return ['id' => $userId, 'name' => $userId > 0 ? '#' . $userId : __('Someone', 'agent-brain'), 'person' => '', 'label' => $userId > 0 ? '#' . $userId : __('Someone', 'agent-brain'), 'account' => ''];
		}

		$name    = ConnectChoices::label($user);
		$person  = (string) (AgentHumans::nameFor($userId) ?? '');
		$account = (string) $user->user_login !== $name ? (string) $user->user_login : '';

		return ['id' => $userId, 'name' => $name, 'person' => $person, 'label' => self::shortLabel($name, $person), 'account' => $account];
	}

	/**
	 * The list's short form of who() (#701). Public for its unit test.
	 */
	public static function shortLabel(string $name, string $person): string {
		if ($person === '') {
			return $name;
		}

		$first = explode(' ', trim($person))[0];
		$first = $first !== '' ? $first : $person;

		/* translators: %s: first name of the person the agent works for. Tío is TomaTío's word for a person's AI agent. */
		$agentOf = sprintf(__('%s\'s Tío', 'agent-brain'), $first);

		// A person's own agent is named after them ("dennis-ai" for Dennis).
		$slug = sanitize_title($first);
		if ($slug !== '' && (strpos(strtolower($name), $slug) === 0)) {
			return $agentOf;
		}

		/* translators: 1: agent name, 2: "Roger's Tío". */
		return sprintf(__('%1$s · %2$s', 'agent-brain'), $name, $agentOf);
	}

	/**
	 * The plain text in readable pieces: block text comes joined with " · "
	 * (Core\Text), which the inbox shows as paragraphs.
	 *
	 * @return string[]
	 */
	public static function paragraphs(string $plain): array {
		$parts = array_map('trim', explode(' · ', $plain));

		return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
	}

	/**
	 * Stored text can hold entities (the editor writes &quot;); the view
	 * escapes once, so decode them here (the same rule as Diff).
	 */
	private static function decode(string $text): string {
		return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private static function wordCount(string $text): int {
		$text = trim($text);

		return $text === '' ? 0 : count(preg_split('/\s+/', $text) ?: []);
	}

	/**
	 * The newest source memory the viewer may read, shortened, and when it
	 * was written; null when there is none.
	 *
	 * @param int[] $memoryIds
	 * @return array{content:string, created:string}|null
	 */
	private static function sourceMemory(int $viewerId, array $memoryIds): ?array {
		if ($memoryIds === []) {
			return null;
		}

		$memoryIds = array_slice($memoryIds, -20);
		$rows      = Db::getResults(
			'SELECT id, content, created, scope_id FROM ' . MemoryRepository::memoryTable()
				. ' WHERE id IN (' . implode(', ', array_fill(0, count($memoryIds), '%d')) . ') ORDER BY id DESC',
			$memoryIds
		);

		foreach ($rows as $row) {
			if (ScopeVisibility::canSeeScope($viewerId, (int) $row['scope_id'])) {
				$content = trim((string) preg_replace('/\s+/', ' ', self::decode(wp_strip_all_tags((string) $row['content']))));

				return [
					'content' => mb_strlen($content) > self::SOURCE_CHARS ? \AgentBrain\Core\Text::trimToWordBoundary($content, self::SOURCE_CHARS) . '…' : $content,
					'created' => (string) $row['created'],
				];
			}
		}

		return null;
	}

	/** @param int[] $ids */
	public static function scopeNames(array $ids): string {
		$names = [];
		foreach ($ids as $id) {
			$term    = get_term($id, Scopes::TAXONOMY);
			$names[] = $term instanceof \WP_Term ? $term->name : (string) $id;
		}

		return $names === [] ? __('none', 'agent-brain') : implode(', ', $names);
	}
}
