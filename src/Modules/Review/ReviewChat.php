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
 * proposals and open handoffs) and, on the curator's explicit answer in
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
	 * @return array{proposals: array<int, array<string, mixed>>, handoffs: array<int, array<string, mixed>>, total: int, expiry_days: int, auto_approve: bool}
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

		return [
			'proposals'    => $proposals,
			'handoffs'     => $handoffs,
			'total'        => self::counts($reviewerId)['total'],
			'expiry_days'  => Expiry::days(),
			'auto_approve' => AutoApprove::enabled(),
		];
	}

	/**
	 * How many proposals and open handoffs wait for $reviewerId.
	 *
	 * @return array{proposals:int, handoffs:int, total:int}
	 */
	public static function counts(int $reviewerId): array {
		$proposals = count(self::proposalPosts($reviewerId));
		$handoffs  = count(HandoffQueue::openHandoffs($reviewerId));

		return ['proposals' => $proposals, 'handoffs' => $handoffs, 'total' => $proposals + $handoffs];
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

		$waiting = [];
		if ($counts['proposals'] > 0) {
			/* translators: %d: number of suggested facts */
			$waiting[] = sprintf(_n('%d suggested fact', '%d suggested facts', $counts['proposals'], 'agent-brain'), $counts['proposals']);
		}
		if ($counts['handoffs'] > 0) {
			/* translators: %d: number of review requests (handoffs) */
			$waiting[] = sprintf(_n('%d review request', '%d review requests', $counts['handoffs'], 'agent-brain'), $counts['handoffs']);
		}

		return sprintf(
			/* translators: 1: the person's name, 2: what is waiting, e.g. "3 suggested facts and 1 review request". */
			__('Review: %1$s decides what becomes official team knowledge. Waiting: %2$s. When it fits the conversation, list them with tomatio-review-queue and summarise each in one line with your suggestion (make official, not needed, or edit first). Act only on %1$s\'s explicit answer in this chat: their yes is the approval. Then decide with tomatio-review-decide, or close a review request with tomatio-handoff-dismiss. Never decide on your own. Whatever they choose, the memories are kept.', 'agent-brain'),
			self::name($reviewerId),
			implode(__(' and ', 'agent-brain'), $waiting)
		);
	}

	/** A person's short name: first name, else display name. */
	private static function name(int $userId): string {
		$user = get_userdata($userId);
		if (! $user instanceof WP_User) {
			return __('the person', 'agent-brain');
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
			'same'  => $nearest !== null ? sprintf(__('Already official as "%s": not needed.', 'agent-brain'), $nearest['title']) : '',
			/* translators: %s: title of the official item */
			'close' => $nearest !== null ? sprintf(__('Changes the official "%s": make official if this is the newer truth, otherwise not needed.', 'agent-brain'), $nearest['title']) : '',
			'new'   => __('New fact: make official if it is true and useful to the team, otherwise not needed.', 'agent-brain'),
		][$match];

		return [
			'proposal_id' => (int) $post->ID,
			'kind'        => $post->post_type === 'ab_procedure' ? 'procedure' : 'knowledge',
			'title'       => (string) $post->post_title,
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

	private static function iso(string $gmt): ?string {
		if ($gmt === '' || $gmt === '0000-00-00 00:00:00') {
			return null;
		}

		$time = strtotime($gmt . ' UTC');

		return $time === false ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
	}
}
