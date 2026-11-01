<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Memory\MemoryRepository;
use WP_Post;

/**
 * Multi-agent authoring pipeline: listens on `agent_brain/artifact_assembled`
 * (fired by ArtifactService::assemble()) and creates an `ab_proposed`
 * `ab_knowledge` post in the artifact's scope from the assembled document,
 * so a curator can approve it into knowledge like any other proposal
 * (principle 4). Hands the created id back to the caller via the
 * `agent_brain/artifact_assembled_knowledge_id` filter, per the binding
 * contract.
 */
class AssembledArtifactListener {

	public const META_SOURCE_ARTIFACT = 'ab_source_artifact';

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('agent_brain/artifact_assembled', [self::class, 'onAssembled'], 10, 6);
		add_filter('agent_brain/artifact_assembled_knowledge_id', [self::class, 'filterKnowledgeId'], 10, 2);
	}

	/**
	 * @param int[] $sectionIds
	 */
	public static function onAssembled(
		int $artifactId,
		int $assembledPostId,
		int $scopeId,
		int $leadId,
		array $sectionIds,
		int $memoryId
	): void {
		self::proposalFor($artifactId, $assembledPostId, $scopeId, $leadId, $memoryId);
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function filterKnowledgeId($value, int $artifactId) {
		$existing = self::findExisting($artifactId);

		return $existing ?? $value;
	}

	private static function findExisting(int $artifactId): ?int {
		if (! function_exists('get_posts')) {
			return null;
		}

		$found = get_posts([
			'post_type'      => 'ab_knowledge',
			'post_status'    => array_merge(PostStatuses::all(), ['private']),
			'meta_key'       => self::META_SOURCE_ARTIFACT,
			'meta_value'     => (string) $artifactId,
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		]);

		return $found !== [] ? (int) $found[0] : null;
	}

	/**
	 * One proposal per artifact (no duplicates on re-fire). A later assemble
	 * (after a reopen) writes the revised text into that proposal and puts it
	 * back to `ab_proposed`, so the new content goes through review again
	 * (ART-20). A repeat event with the same title and text leaves the
	 * proposal as it is, so an approval is never undone by a re-fire.
	 */
	private static function proposalFor(int $artifactId, int $assembledPostId, int $scopeId, int $leadId, int $memoryId): ?int {
		if (! function_exists('wp_insert_post')) {
			return null;
		}

		$assembled = get_post($assembledPostId);
		$content   = $assembled instanceof WP_Post ? $assembled->post_content : '';
		$title     = $assembled instanceof WP_Post && $assembled->post_title !== ''
			? $assembled->post_title
			: sprintf('Assembled artifact #%d', $artifactId);

		$existing = self::findExisting($artifactId);
		if ($existing !== null) {
			$current = get_post($existing);
			$changed = ! $current instanceof WP_Post
				|| $current->post_content !== $content
				|| $current->post_title !== $title;

			if ($changed) {
				wp_update_post([
					'ID'           => $existing,
					'post_status'  => PostStatuses::PROPOSED,
					'post_title'   => $title,
					'post_content' => $content,
				]);
			}

			return $existing;
		}

		$postId = wp_insert_post([
			'post_type'    => 'ab_knowledge',
			'post_status'  => PostStatuses::PROPOSED,
			'post_title'   => $title,
			'post_content' => $content,
			'post_author'  => $leadId,
		], true);

		if (is_wp_error($postId)) {
			if (function_exists('do_action')) {
				do_action('agent_brain/audit', [
					'actor_id'    => $leadId,
					'event'       => 'artifact_assembled_proposal_failed',
					'status'      => 'error',
					'object_type' => 'ab_artifact',
					'object_id'   => (string) $artifactId,
					'meta'        => ['message' => $postId->get_error_message()],
				]);
			}

			return null;
		}

		$postId = (int) $postId;

		if ($scopeId > 0) {
			wp_set_object_terms($postId, [$scopeId], 'ab_scope', false);
		}

		update_post_meta($postId, self::META_SOURCE_ARTIFACT, $artifactId);
		update_post_meta($postId, ProposalService::META_PROPOSING_AGENT, $leadId);
		update_post_meta($postId, ProposalService::META_IMPORTANCE, 70);

		if ($memoryId > 0) {
			(new MemoryRepository())->link('post', $postId, [$memoryId]);
		}

		return $postId;
	}
}
