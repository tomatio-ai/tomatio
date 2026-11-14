<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Core\Untrusted;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\OAuth\RoleSwitching;
use WP_Post;
use WP_User;

/**
 * #658: review in chat. A curator's AI lists what waits for review (fact
 * proposals, open handoffs and, since #958, held memories) and, on the
 * curator's explicit answer in
 * chat, decides them through the same ReviewActions / HandoffQueue paths
 * the Review Queue page uses. The MCP initialize instructions and the
 * context-briefing orientation carry one paragraph saying so, only when the
 * person is a curator and something is waiting.
 *
 * Who reviews: the account the connection runs as when it may approve
 * knowledge, or, on a person's own role-switching connection with an agent
 * chosen (act-as), that person: the tools then run as the agent (OAuth\
 * RoleSwitching) but the decision is the person's. Nobody else, so an
 * agent's own connection never sees or uses these tools.
 */
final class ReviewChat {

	/** At most this many proposals and this many handoffs per queue read. */
	public const MAX_ITEMS = 50;

	/** Characters of each proposal's text the queue returns. */
	public const MAX_TEXT = 1500;

	/** Characters of the one-line summary every item carries (#958). */
	public const MAX_LINE = 120;

	/** The person deciding on this connection, or 0 when nobody here may. */
	public static function reviewer(): int {
		$userId = (int) get_current_user_id();
		if ($userId > 0 && user_can($userId, Capabilities::CAP_USE_MCP) && user_can($userId, Capabilities::CAP_APPROVE_KNOWLEDGE)) {
			return $userId;
		}

		$person = RoleSwitching::person();
		if ($person > 0 && $person !== $userId && user_can($person, Capabilities::CAP_APPROVE_KNOWLEDGE)) {
			return $person;
		}

		return 0;
	}

	/**
	 * What waits for $reviewerId: fact proposals and open handoffs they may
	 * see (#552), oldest first, each with a plain suggestion.
	 *
	 * @return array{proposals: array<int, array<string, mixed>>, handoffs: array<int, array<string, mixed>>, memories: array<int, array<string, mixed>>, total: int, expiry_days: int, auto_approve: bool}
	 */
	public static function queue(int $reviewerId): array {
		$proposals = [];
		foreach (self::proposalPosts($reviewerId) as $post) {
			$proposals[] = self::proposalRow($post);
			if (count($proposals) >= self::MAX_ITEMS) {
				break;
			}
		}

		$handoffs = [];
		foreach (array_reverse(HandoffQueue::openHandoffs($reviewerId)) as $row) {
			$handoffs[] = [
				'handoff_id' => (int) $row['id'],
				'title'      => (string) $row['title'],
				'line'       => self::line((string) $row['title']),
				'note'       => Untrusted::wrap((string) $row['note'], [
					'source'        => 'handoff',
					'agent_id'      => (int) $row['from_agent_id'],
					'handoff_id'    => (int) $row['id'],
					'agent_written' => true,
				]),
				'from'       => (string) $row['from_agent_name'],
				'to'         => (string) $row['to_agent_name'],
				'memory_ids' => $row['memory_ids'],
				'created'    => self::iso((string) $row['created']),
				'expires'    => self::iso((string) Expiry::expiresAt((string) $row['modified'])),
			];
			if (count($handoffs) >= self::MAX_ITEMS) {
				break;
			}
		}

		$memories = [];
		foreach (MemoryProposalQueue::pending($reviewerId) as $row) {
			$memories[] = self::memoryRow($row, $reviewerId);
			if (count($memories) >= self::MAX_ITEMS) {
				break;
			}
		}

		return [
			'proposals'    => $proposals,
			'handoffs'     => $handoffs,
			'memories'     => $memories,
			'total'        => self::counts($reviewerId)['total'],
			'expiry_days'  => Expiry::days(),
			'auto_approve' => AutoApprove::enabled(),
		];
	}

	/**
	 * How many proposals and open handoffs wait for $reviewerId.
	 *
	 * @return array{proposals:int, handoffs:int, memories:int, total:int}
	 */
	public static function counts(int $reviewerId): array {
		$proposals = count(self::proposalPosts($reviewerId));
		$handoffs  = count(HandoffQueue::openHandoffs($reviewerId));
		$memories  = count(MemoryProposalQueue::pending($reviewerId));

		return ['proposals' => $proposals, 'handoffs' => $handoffs, 'memories' => $memories, 'total' => $proposals + $handoffs + $memories];
	}

	/**
	 * One line per item waiting for $reviewerId, oldest first, for TíoDay
	 * (ReviewReminder, #958).
	 *
	 * @return string[]
	 */
	public static function lines(int $reviewerId): array {
		$lines = [];
		foreach (self::proposalPosts($reviewerId) as $post) {
			$lines[] = self::line((string) $post->post_title);
		}
		foreach (array_reverse(HandoffQueue::openHandoffs($reviewerId)) as $row) {
			$lines[] = self::line((string) $row['title']);
		}
		foreach (MemoryProposalQueue::pending($reviewerId) as $row) {
			$lines[] = self::line((string) $row['content']);
		}

		return $lines;
	}

	/**
	 * The first line of $text, plain, at most MAX_LINE characters: how an
	 * item is named in chat, in TíoDay and on the Review page (#958).
	 */
	public static function line(string $text): string {
		$text  = trim(wp_strip_all_tags($text));
		$first = trim((string) strtok($text, "\n"));
		$first = (string) preg_replace('/\s+/u', ' ', $first);
		if ($first === '') {
			return __('(no text)', 'tomatio');
		}

		return mb_strlen($first, 'UTF-8') > self::MAX_LINE ? rtrim(mb_substr($first, 0, self::MAX_LINE - 1, 'UTF-8')) . '…' : $first;
	}

	/**
	 * The paragraph the MCP initialize instructions and context-briefing add
	 * when the person behind this connection may review and something is
	 * waiting; '' otherwise.
	 */
	public static function instructions(): string {
		$reviewerId = self::reviewer();
		if ($reviewerId <= 0) {
			return '';
		}

		$counts = self::counts($reviewerId);
		if ($counts['total'] === 0) {
			return '';
		}

		/* translators: %d: number of items waiting for review */
		$waiting = sprintf(_n('%d item', '%d items', $counts['total'], 'tomatio'), $counts['total']);

		return sprintf(
			/* translators: 1: the person's name, 2: e.g. "3 items". */
			__('Review: %2$s waiting for %1$s. When it fits, list them with tomatio-review-queue and ask in one short line each, with your suggestion (keep or not). Act only on %1$s\'s explicit answer in this chat: their yes is the decision. Decide with tomatio-review-decide (proposal_id or memory_id), or close a review request with tomatio-handoff-dismiss. Never decide on your own.', 'tomatio'),
			self::name($reviewerId),
			$waiting
		);
	}

	/** A person's short name: first name, else display name. */
	private static function name(int $userId): string {
		$user = get_userdata($userId);
		if (! $user instanceof WP_User) {
			return __('the person', 'tomatio');
		}

		$first = trim((string) $user->first_name);

		return $first !== '' ? $first : (string) $user->display_name;
	}

	/**
	 * Proposed knowledge and procedures $viewerId may see, oldest first.
	 *
	 * @return array<int, WP_Post>
	 */
	private static function proposalPosts(int $viewerId): array {
		$posts = get_posts([
			'post_type'      => ['ab_knowledge', 'ab_procedure'],
			'post_status'    => PostStatuses::PROPOSED,
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		]);

		return array_values(array_filter(
			$posts,
			static fn ($post): bool => $post instanceof WP_Post && ScopeVisibility::canSeePost($viewerId, $post)
		));
	}

	/**
	 * One proposal for the queue: its text (as data), who proposed it, the
	 * closest official item when it is a close match, and a suggestion.
	 *
	 * @return array<string, mixed>
	 */
	private static function proposalRow(WP_Post $post): array {
		$scopeTerms = wp_get_post_terms($post->ID, 'ab_scope');
		$scopeId    = is_array($scopeTerms) && $scopeTerms !== [] ? (int) $scopeTerms[0]->term_id : 0;
		$scopeName  = is_array($scopeTerms) && $scopeTerms !== [] ? (string) $scopeTerms[0]->name : '';
		$agentId    = (int) get_post_meta($post->ID, ProposalService::META_PROPOSING_AGENT, true);
		$agent      = $agentId > 0 ? get_userdata($agentId) : false;
		$text       = ReviewQueuePage::blockExcerpt($post->post_content);
		if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT) {
			$text = mb_substr($text, 0, self::MAX_TEXT, 'UTF-8') . '…';
		}

		$nearest = $scopeId > 0 ? Diff::nearest($scopeId, $post->post_content, $post->post_type) : null;
		$match   = 'new';
		if ($nearest !== null && Diff::isCloseMatch($nearest['similarity'])) {
			$match = Diff::saysTheSame((int) $nearest['id'], $post->post_content) ? 'same' : 'close';
		}

		$suggestion = [
			/* translators: %s: title of the official item */
			'same'  => $nearest !== null ? sprintf(__('Already official as "%s": not needed.', 'tomatio'), $nearest['title']) : '',
			/* translators: %s: title of the official item */
			'close' => $nearest !== null ? sprintf(__('Changes the official "%s": make official if this is the newer truth, otherwise not needed.', 'tomatio'), $nearest['title']) : '',
			'new'   => __('New fact: make official if it is true and useful to the team, otherwise not needed.', 'tomatio'),
		][$match];

		return [
			'proposal_id' => (int) $post->ID,
			'kind'        => $post->post_type === 'ab_procedure' ? 'procedure' : 'knowledge',
			'title'       => (string) $post->post_title,
			'line'        => self::line((string) $post->post_title),
			'text'        => Untrusted::wrap($text, [
				'source'        => 'proposal',
				'agent_id'      => $agentId,
				'agent_written' => true,
			]),
			'scope'       => $scopeName,
			'proposed_by' => $agent instanceof WP_User ? (string) $agent->display_name : '',
			'importance'  => (int) get_post_meta($post->ID, ProposalService::META_IMPORTANCE, true),
			'match'       => $match,
			'closest'     => $match === 'new' ? null : $nearest,
			'suggestion'  => $suggestion,
			'proposed'    => self::iso((string) $post->post_date_gmt),
			'expires'     => self::iso((string) Expiry::expiresAt((string) $post->post_modified_gmt)),
		];
	}

	/**
	 * One held memory for the queue (#958): an agent's memory waiting to be
	 * kept in its scope, usually because it was built on a scope fewer people
	 * can read (Provenance\ScopeGuard).
	 *
	 * @param array{id:int, content:string, type:string, scope:string, scope_id:int, created:string, agent_id:int, held:int[]} $row
	 * @return array<string, mixed>
	 */
	private static function memoryRow(array $row, int $reviewerId): array {
		$text = (string) $row['content'];
		if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT) {
			$text = mb_substr($text, 0, self::MAX_TEXT, 'UTF-8') . '…';
		}
		$agent = $row['agent_id'] > 0 ? get_userdata($row['agent_id']) : false;
		$from  = $row['held'] !== [] ? \AgentBrain\Modules\Provenance\ScopeGuard::curatorLabels($reviewerId, $row['held']) : '';

		return [
			'memory_id'  => (int) $row['id'],
			'kind'       => 'memory',
			'text'       => Untrusted::wrap($text, [
				'source'        => 'memory',
				'agent_id'      => $row['agent_id'],
				'memory_id'     => (int) $row['id'],
				'agent_written' => $row['agent_id'] > 0,
			]),
			'scope'      => (string) $row['scope'],
			'written_by' => $agent instanceof WP_User ? (string) $agent->display_name : '',
			'built_from' => $from,
			'suggestion' => $from !== ''
				/* translators: 1: the restricted scope(s) it was built from, 2: the scope it was written into */
				? sprintf(__('Built from %1$s. Keep it if it is fine for everyone in %2$s to read, otherwise delete it.', 'tomatio'), $from, (string) $row['scope'])
				: __('Keep it if it is true and useful, otherwise delete it.', 'tomatio'),
			'created'    => self::iso((string) $row['created']),
		];
	}

	private static function iso(string $gmt): ?string {
		if ($gmt === '' || $gmt === '0000-00-00 00:00:00') {
			return null;
		}

		$time = strtotime($gmt . ' UTC');

		return $time === false ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
	}
}
