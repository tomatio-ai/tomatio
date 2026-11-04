<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * REV-1: listens to `agent_brain/fact_extracted` and turns
 * importance-worthy facts into `ab_proposed` `ab_knowledge` posts.
 *
 * Dedupe rule (binding): the same fact text proposed twice for the same
 * scope must never create two open proposals — the second occurrence is
 * folded into the existing one (its source memory id is linked and merged
 * into `ab_source_memory_ids`) rather than creating a duplicate post.
 */
class ProposalService {

	public const META_SOURCE_MEMORY_IDS = 'ab_source_memory_ids';
	public const META_PROPOSING_AGENT   = 'ab_proposing_agent';
	public const META_IMPORTANCE        = 'ab_importance';
	public const META_SEARCH_KEYS       = 'ab_search_keys';
	public const OPTION_THRESHOLD       = 'ab_proposal_threshold';
	public const DEFAULT_THRESHOLD      = 70;

	/**
	 * Pure decision: is $importance high enough to propose, given $threshold?
	 * Boundary is inclusive (>=), per contract wording "importance >= threshold".
	 */
	public static function shouldPropose(int $importance, int $threshold): bool {
		return $importance >= $threshold;
	}

	/**
	 * Pure dedupe key: same scope + same (trimmed, case-insensitive) text
	 * must collapse to the same key regardless of surrounding whitespace.
	 */
	public static function dedupeKey(int $scopeId, string $text): string {
		return $scopeId . ':' . md5(strtolower(trim($text)));
	}

	/**
	 * Hook handler for `agent_brain/fact_extracted( int $memoryId, array $fact )`.
	 *
	 * @param array{text?:string, importance?:int, scope_id?:int, agent_id?:int, search_keys?:string|string[]} $fact
	 */
	public static function onFactExtracted(int $memoryId, array $fact): void {
		if (! function_exists('wp_insert_post')) {
			return;
		}

		$importance = (int) ($fact['importance'] ?? 0);
		$threshold  = (int) get_option(self::OPTION_THRESHOLD, self::DEFAULT_THRESHOLD);

		if (! self::shouldPropose($importance, $threshold)) {
			return;
		}

		$text = trim((string) ($fact['text'] ?? ''));
		if ($text === '') {
			return;
		}

		$scopeId  = (int) ($fact['scope_id'] ?? 0);
		$agentId  = (int) ($fact['agent_id'] ?? 0);
		$rawSearchKeys = $fact['search_keys'] ?? '';
		$searchKeys    = is_array($rawSearchKeys) ? implode(' ', $rawSearchKeys) : (string) $rawSearchKeys;

		if ($scopeId <= 0 || $agentId <= 0) {
			return;
		}

		$existingId = self::findOpenProposal($scopeId, $text);

		if ($existingId !== null) {
			self::mergeIntoExisting($existingId, $memoryId);

			return;
		}

		self::createProposal($memoryId, $scopeId, $agentId, $importance, $text, $searchKeys);
	}

	private static function findOpenProposal(int $scopeId, string $text): ?int {
		$dedupeKey = self::dedupeKey($scopeId, $text);

		$candidates = get_posts([
			'post_type'      => ['ab_knowledge', 'ab_procedure'],
			'post_status'    => PostStatuses::PROPOSED,
			'posts_per_page' => -1,
			'tax_query'      => [
				[
					'taxonomy' => 'ab_scope',
					'field'    => 'term_id',
					'terms'    => [$scopeId],
				],
			],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		foreach ($candidates as $candidateId) {
			$candidateText = (string) get_post_field('post_content', (int) $candidateId);
			if (self::dedupeKey($scopeId, $candidateText) === $dedupeKey) {
				return (int) $candidateId;
			}
		}

		return null;
	}

	private static function mergeIntoExisting(int $postId, int $memoryId): void {
		$ids = get_post_meta($postId, self::META_SOURCE_MEMORY_IDS, true);
		$ids = is_array($ids) ? array_map('intval', $ids) : [];

		if (! in_array($memoryId, $ids, true)) {
			$ids[] = $memoryId;
			update_post_meta($postId, self::META_SOURCE_MEMORY_IDS, array_values($ids));
		}

		(new MemoryRepository())->link('post', $postId, [$memoryId]);
	}

	private static function createProposal(int $memoryId, int $scopeId, int $agentId, int $importance, string $text, string $searchKeys): void {
		$title = self::excerptTitle($text);

		$postId = wp_insert_post([
			'post_type'    => 'ab_knowledge',
			'post_status'  => PostStatuses::PROPOSED,
			'post_title'   => $title,
			'post_content' => $text,
			'post_author'  => $agentId,
		], true);

		if (is_wp_error($postId)) {
			return;
		}

		$postId = (int) $postId;

		wp_set_object_terms($postId, [$scopeId], 'ab_scope', false);

		update_post_meta($postId, self::META_SOURCE_MEMORY_IDS, [$memoryId]);
		update_post_meta($postId, self::META_PROPOSING_AGENT, $agentId);
		update_post_meta($postId, self::META_IMPORTANCE, $importance);
		if ($searchKeys !== '') {
			update_post_meta($postId, self::META_SEARCH_KEYS, $searchKeys);
		}

		(new MemoryRepository())->link('post', $postId, [$memoryId]);

		// #658: a curator's own AI needs no review when the setting is on.
		AutoApprove::maybeApprove($postId, $agentId);
	}

	private static function excerptTitle(string $text): string {
		$title = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

		if (strlen($title) > 80) {
			$title = substr($title, 0, 77) . '...';
		}

		return $title;
	}
}
