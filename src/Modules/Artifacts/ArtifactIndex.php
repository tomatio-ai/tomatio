<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

/**
 * The artifacts a person or agent may open, newest first: one query for the
 * signed-in home page (#617) and the artifact-list ability (#627), so the
 * two can never disagree. Access is read_post (ObjectAccess::mapMetaCap:
 * curator, lead, section owner or approval-grant reviewer), checked per
 * artifact for the given user, never the ambient one.
 */
final class ArtifactIndex {

	/** Artifacts scanned per call; a brain with more lists its newest. */
	public const SCAN = 500;

	/**
	 * @return array<int, \WP_Post> readable by $userId, newest change first
	 */
	public static function readable(int $userId, bool $archived = false): array {
		if ($userId <= 0) {
			return [];
		}

		$posts = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => ['publish', 'private', 'draft', 'pending'],
				'posts_per_page' => self::SCAN,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			]
		);

		$out  = [];
		$when = [];
		foreach ($posts as $post) {
			$id = (int) $post->ID;
			if (! user_can($userId, 'read_post', $id)) {
				continue;
			}
			if (! $archived && self::isArchived($id)) {
				continue;
			}
			$out[]     = $post;
			$when[$id] = self::updated($post);
		}

		usort($out, static fn (\WP_Post $a, \WP_Post $b): int => strcmp($when[(int) $b->ID], $when[(int) $a->ID]));

		return $out;
	}

	public static function isArchived(int $artifactId): bool {
		return get_post_meta($artifactId, ArtifactService::META_ARCHIVED_AT, true) !== '';
	}

	/**
	 * When it last changed (MySQL GMT): the post, or for an HTML artifact its
	 * newest version when that is later (uploading files leaves the post as is).
	 */
	public static function updated(\WP_Post $post): string {
		$id      = (int) $post->ID;
		$updated = (string) $post->post_modified_gmt;
		if (HtmlFiles::isHtml($id)) {
			$latest = HtmlFiles::latestNumber($id);
			$row    = $latest > 0 ? HtmlFiles::version($id, $latest) : null;
			$at     = is_array($row) ? strtotime((string) $row['created_at']) : false;
			if ($at !== false && gmdate('Y-m-d H:i:s', $at) > $updated) {
				$updated = gmdate('Y-m-d H:i:s', $at);
			}
		}

		return $updated;
	}

	/**
	 * Whether $title holds every word of $query, ignoring case and accents
	 * ("loic" finds "Loïc").
	 */
	public static function matches(string $title, string $query): bool {
		$fold  = static fn (string $s): string => mb_strtolower(remove_accents($s), 'UTF-8');
		$title = $fold($title);
		foreach (preg_split('/\s+/u', $fold(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
			if (! str_contains($title, $word)) {
				return false;
			}
		}

		return true;
	}
}
