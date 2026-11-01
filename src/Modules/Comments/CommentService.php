<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Comments;

use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Mcp\Present;
use AgentBrain\Modules\Review\HandoffCpt;
use AgentBrain\Modules\Review\HandoffQueue;
use AgentBrain\Modules\Review\PostStatuses;
use AgentBrain\Modules\Review\ProposalService;
use WP_Error;
use WP_Post;
use WP_User;

/**
 * ART-28 (#168): comment threads on an artifact version.
 *
 * The permission model is the artifact's own read gate, nothing looser:
 * may-read-the-artifact (curator, lead, section owner, or a manager-agent
 * holding the approval grant with WRITE on the artifact's scope — the exact
 * audience Artifacts\ObjectAccess' read_post branch admits) is allowed to
 * read and add comments; default deny for everyone else. Resolving and
 * converting are narrower: curator, artifact lead, or the thread's root
 * author (resolve), with review-note conversion curator/lead only since the
 * target lands in the Review queue.
 *
 * Every method takes the acting user's id explicitly and checks it with
 * user_can($actorId, ...) / ObjectAccess predicates — never the ambient
 * current user — so MCP (current user) and wp-admin handlers share one
 * enforcement point. All failures are WP_Errors; nothing fails open.
 *
 * Attribution: post_author is the acting user (an agent id over MCP —
 * RoleSwitching already resolved person-via-agent upstream — or a curator's
 * user id), and agentFqdn() exposes the author's FQDN per house rules.
 */
class CommentService {

	public const CONVERT_HANDOFF      = 'handoff';
	public const CONVERT_REVIEW_NOTE  = 'review_note';

	/** Audit events (agent_brain/audit). */
	public const AUDIT_RESOLVED  = 'comment_resolved';
	public const AUDIT_REOPENED  = 'comment_reopened';
	public const AUDIT_CONVERTED = 'comment_converted';

	/**
	 * The artifact read gate: the identical audience
	 * Artifacts\ObjectAccess' read_post meta-cap branch admits, expressed
	 * through that same map_meta_cap filter so MCP, would-be REST readers
	 * and this service can never drift apart.
	 */
	public function canRead(int $userId, int $artifactId): bool {
		if ($userId <= 0 || $artifactId <= 0 || ! function_exists('user_can')) {
			return false;
		}

		$artifact = get_post($artifactId);
		if (! $artifact instanceof WP_Post || $artifact->post_type !== Cpts::ARTIFACT_POST_TYPE) {
			return false;
		}

		return (bool) user_can($userId, 'read_post', $artifactId);
	}

	/**
	 * Who may resolve/reopen a thread or escalate it to a handoff: a
	 * curator, the artifact's lead agent, or the thread's root author.
	 */
	public function canResolve(int $userId, int $commentId): bool {
		if ($userId <= 0 || $commentId <= 0) {
			return false;
		}

		$comment = get_post($commentId);
		if (! $comment instanceof WP_Post || $comment->post_type !== Cpt::POST_TYPE) {
			return false;
		}

		if (ObjectAccess::userIsCurator($userId)) {
			return true;
		}

		$artifactId = (int) get_post_meta($commentId, Cpt::META_ARTIFACT, true);

		return ObjectAccess::userIsLead($userId, $artifactId)
			|| (int) $this->root($comment)->post_author === $userId;
	}

	/**
	 * Start a thread on an artifact version. $version defaults to the
	 * artifact's current version (its ab_version meta); $sectionId must name
	 * a section that is a child of the artifact; $element is a free-text
	 * anchor (tag/selector/name) clipped to 191 chars.
	 *
	 * @return array<string, mixed>|WP_Error the stored comment, shaped like listForArtifact() entries.
	 */
	public function add(int $actorId, int $artifactId, string $body, ?int $version = null, ?int $sectionId = null, ?string $element = null) {
		$artifact = $this->requireArtifact($artifactId);
		if ($artifact instanceof WP_Error) {
			return $artifact;
		}

		if (! $this->canRead($actorId, $artifactId)) {
			return $this->denied();
		}

		$body = trim($body);
		if ($body === '') {
			return $this->invalid('body must not be empty');
		}

		if ($version !== null && $version < 1) {
			return $this->invalid('version must be a whole number from 1');
		}

		if ($sectionId !== null) {
			$section = get_post($sectionId);
			if (! $section instanceof WP_Post || $section->post_type !== Cpts::SECTION_POST_TYPE || (int) $section->post_parent !== $artifactId) {
				return $this->invalid('section_id must be a section of artifact_id');
			}
		}

		if ($version === null) {
			$version = max(1, (int) get_post_meta($artifactId, Cpts::META_VERSION, true));
		}

		$id = $this->insert($actorId, 0, $artifactId, $body, $version, $sectionId, $element);

		return $this->entry(get_post($id));
	}

	/**
	 * Reply to a comment. The reply attaches to the thread root (post_parent
	 * = root id) so a thread is always one level deep regardless of which
	 * comment was replied to.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function reply(int $actorId, int $commentId, string $body) {
		$parent = get_post($commentId);
		if (! $parent instanceof WP_Post || $parent->post_type !== Cpt::POST_TYPE) {
			return $this->notFound();
		}

		$artifactId = (int) get_post_meta($commentId, Cpt::META_ARTIFACT, true);
		if (! $this->canRead($actorId, $artifactId)) {
			return $this->denied();
		}

		$body = trim($body);
		if ($body === '') {
			return $this->invalid('body must not be empty');
		}

		$id = $this->insert(
			$actorId,
			(int) $this->root($parent)->ID,
			$artifactId,
			$body,
			(int) get_post_meta($commentId, Cpt::META_VERSION, true),
			null,
			null
		);

		return $this->entry(get_post($id));
	}

	/**
	 * Resolve or reopen a thread (the root comment carries the state).
	 *
	 * @return array<string, mixed>|WP_Error the thread root entry.
	 */
	public function resolve(int $actorId, int $commentId, bool $resolved = true) {
		$comment = get_post($commentId);
		if (! $comment instanceof WP_Post || $comment->post_type !== Cpt::POST_TYPE) {
			return $this->notFound();
		}

		if (! $this->canResolve($actorId, $commentId)) {
			return $this->denied();
		}

		$root = $this->root($comment);

		if ($resolved) {
			update_post_meta($root->ID, Cpt::META_RESOLVED, 1);
			update_post_meta($root->ID, Cpt::META_RESOLVED_BY, $actorId);
			update_post_meta($root->ID, Cpt::META_RESOLVED_AT, gmdate('Y-m-d H:i:s'));
		} else {
			delete_post_meta($root->ID, Cpt::META_RESOLVED);
			delete_post_meta($root->ID, Cpt::META_RESOLVED_BY);
			delete_post_meta($root->ID, Cpt::META_RESOLVED_AT);
		}

		$this->audit($actorId, $resolved ? self::AUDIT_RESOLVED : self::AUDIT_REOPENED, $root->ID, []);

		return $this->entry($root);
	}

	/**
	 * List threads on an artifact, newest activity last (root post_date/id
	 * ascending). $version narrows to one version; resolved threads are
	 * omitted unless $includeResolved.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function listForArtifact(int $actorId, int $artifactId, ?int $version = null, bool $includeResolved = false) {
		if ($this->requireArtifact($artifactId) instanceof WP_Error || ! $this->canRead($actorId, $artifactId)) {
			return $this->denied();
		}

		$metaQuery = [
			[
				'key'     => Cpt::META_ARTIFACT,
				'value'   => (string) $artifactId,
				'compare' => '=',
				'type'    => 'NUMERIC',
			],
		];
		if ($version !== null) {
			$metaQuery[] = [
				'key'     => Cpt::META_VERSION,
				'value'   => (string) $version,
				'compare' => '=',
				'type'    => 'NUMERIC',
			];
		}

		$posts = get_posts([
			'post_type'      => Cpt::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => $metaQuery, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- private CPT, curators+agents only, never front-end.
			'orderby'        => ['date' => 'ASC', 'ID' => 'ASC'],
		]);

		$roots = [];
		foreach ($posts as $post) {
			if ((int) $post->post_parent === 0) {
				$roots[(int) $post->ID] = $this->entry($post);
			}
		}

		foreach ($posts as $post) {
			$parentId = (int) $post->post_parent;
			if ($parentId > 0 && isset($roots[$parentId])) {
				$roots[$parentId]['replies'][] = $this->entry($post);
			}
		}

		$threads = array_values($roots);
		if (! $includeResolved) {
			$threads = array_values(array_filter(
				$threads,
				static fn (array $thread): bool => $thread['status'] === Cpt::STATUS_OPEN
			));
		}

		return $threads;
	}

	/**
	 * Turn a comment into a handoff (curator queue) or a review note (an
	 * ab_proposed ab_knowledge post in the artifact's scope, exactly what
	 * ProposalService creates for the Review queue — same post type, same
	 * status, same ab_proposing_agent meta, so the conversion targets are
	 * reused, not reinvented).
	 *
	 * Handoff: any thread participant audience (canResolve) may escalate;
	 * $toAgentId optionally addresses it. The note carries the artifact and
	 * version context and points back at the comment (ab_comment_source).
	 *
	 * Review note: curator or artifact lead only — the note lands in the
	 * Review queue and must scope to the artifact's ab_scope_id.
	 *
	 * @return array<string, int|string>|WP_Error
	 */
	public function convert(int $actorId, int $commentId, string $target, ?int $toAgentId = null) {
		$comment = get_post($commentId);
		if (! $comment instanceof WP_Post || $comment->post_type !== Cpt::POST_TYPE) {
			return $this->notFound();
		}

		$artifactId = (int) get_post_meta($commentId, Cpt::META_ARTIFACT, true);
		$version    = (int) get_post_meta($commentId, Cpt::META_VERSION, true);

		if ($target === self::CONVERT_HANDOFF) {
			if (! $this->canResolve($actorId, $commentId)) {
				return $this->denied();
			}

			if ($toAgentId !== null && ! $this->isAgentOrCurator($toAgentId)) {
				return $this->invalid('to_agent_id must be an existing ab_agent or ab_curator user');
			}

			/* translators: 1: artifact id, 2: version number. */
			$content = sprintf(__('Comment on artifact %1$d, version %2$d:', 'agent-brain'), $artifactId, $version)
				. "\n\n" . $comment->post_content;

			$handoffId = wp_insert_post([
				'post_type'    => HandoffCpt::POST_TYPE,
				'post_status'  => 'private',
				'post_author'  => $actorId,
				'post_title'   => $this->excerptTitle($comment->post_content),
				'post_content' => $content,
			], true);

			if ($handoffId instanceof WP_Error) {
				return $handoffId;
			}

			$handoffId = (int) $handoffId;
			update_post_meta($handoffId, Cpt::META_SOURCE, $commentId);
			update_post_meta($handoffId, Cpt::META_ARTIFACT, $artifactId);
			if ($toAgentId !== null) {
				update_post_meta($handoffId, HandoffQueue::META_TO, $toAgentId);
			}

			$this->audit($actorId, self::AUDIT_CONVERTED, $commentId, ['target' => $target, 'handoff_id' => $handoffId]);

			return ['handoff_id' => $handoffId, 'target' => $target];
		}

		if ($target === self::CONVERT_REVIEW_NOTE) {
			if (! ObjectAccess::canEditOutline($actorId, $artifactId)) {
				return $this->denied();
			}

			$scopeId = (int) get_post_meta($artifactId, Cpts::META_SCOPE_ID, true);
			if ($scopeId <= 0) {
				return new WP_Error('ab_no_scope', __('The artifact has no scope; a review note cannot be scoped.', 'agent-brain'), ['status' => 409]);
			}

			$knowledgeId = wp_insert_post([
				'post_type'    => 'ab_knowledge',
				'post_status'  => PostStatuses::PROPOSED,
				'post_author'  => $actorId,
				'post_title'   => $this->excerptTitle($comment->post_content),
				'post_content' => $comment->post_content,
			], true);

			if ($knowledgeId instanceof WP_Error) {
				return $knowledgeId;
			}

			$knowledgeId = (int) $knowledgeId;
			wp_set_object_terms($knowledgeId, [$scopeId], Scopes::TAXONOMY, false);
			update_post_meta($knowledgeId, ProposalService::META_PROPOSING_AGENT, $actorId);
			update_post_meta($knowledgeId, Cpt::META_SOURCE, $commentId);

			$this->audit($actorId, self::AUDIT_CONVERTED, $commentId, ['target' => $target, 'knowledge_id' => $knowledgeId]);

			return ['knowledge_id' => $knowledgeId, 'target' => $target];
		}

		return $this->invalid('target must be "handoff" or "review_note"');
	}

	// -----------------------------------------------------------------
	// Shape
	// -----------------------------------------------------------------

	/**
	 * One comment as callers (MCP output, tests) see it: attributed,
	 * anchored, status-bearing. `body` is raw post text — the MCP layer
	 * wraps it in Core\Untrusted before returning it to a model.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(WP_Post $post): array {
		$resolvedBy = (int) get_post_meta($post->ID, Cpt::META_RESOLVED_BY, true);

		return [
			'id'             => (int) $post->ID,
			'body'           => (string) $post->post_content,
			'author_id'      => (int) $post->post_author,
			'author_fqdn'    => self::agentFqdn((int) $post->post_author),
			'artifact_id'    => (int) get_post_meta($post->ID, Cpt::META_ARTIFACT, true),
			'version'        => (int) get_post_meta($post->ID, Cpt::META_VERSION, true),
			'section_id'     => self::optionalInt(get_post_meta($post->ID, Cpt::META_SECTION, true)),
			'element'        => self::optionalString(get_post_meta($post->ID, Cpt::META_ELEMENT, true)),
			'parent_id'      => (int) $post->post_parent,
			'status'         => (int) get_post_meta($post->ID, Cpt::META_RESOLVED, true) === 1 ? Cpt::STATUS_RESOLVED : Cpt::STATUS_OPEN,
			'resolved_by'    => $resolvedBy > 0 ? $resolvedBy : null,
			'resolved_at'    => self::optionalString(get_post_meta($post->ID, Cpt::META_RESOLVED_AT, true)),
			'created'        => Present::iso((string) $post->post_date_gmt),
			'replies'        => [],
		];
	}

	private function insert(int $actorId, int $parentId, int $artifactId, string $body, int $version, ?int $sectionId, ?string $element): int {
		$id = wp_insert_post([
			'post_type'    => Cpt::POST_TYPE,
			'post_status'  => 'private',
			'post_author'  => $actorId,
			'post_title'   => $this->excerptTitle($body),
			'post_content' => $body,
			'post_parent'  => $parentId,
		], true);

		if ($id instanceof WP_Error) {
			// wp_insert_post failures here are environmental (DB), not
			// validation: fail loud.
			throw new \RuntimeException('ab_comment insert failed: ' . $id->get_error_message()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a diagnostic for the log, never rendered.
		}

		$id = (int) $id;
		update_post_meta($id, Cpt::META_ARTIFACT, $artifactId);
		update_post_meta($id, Cpt::META_VERSION, $version);
		if ($sectionId !== null) {
			update_post_meta($id, Cpt::META_SECTION, $sectionId);
		}
		if ($element !== null && $element !== '') {
			update_post_meta($id, Cpt::META_ELEMENT, $element);
		}

		return $id;
	}

	/**
	 * The thread root: a root comment is its own root; a reply's root is one
	 * hop away, because replies always attach to the root (reply()).
	 */
	private function root(WP_Post $comment): WP_Post {
		if ((int) $comment->post_parent <= 0) {
			return $comment;
		}

		$parent = get_post((int) $comment->post_parent);

		return $parent instanceof WP_Post ? $parent : $comment;
	}

	/** @return WP_Post|WP_Error */
	private function requireArtifact(int $artifactId) {
		$artifact = get_post($artifactId);
		if (! $artifact instanceof WP_Post || $artifact->post_type !== Cpts::ARTIFACT_POST_TYPE) {
			return $this->notFound();
		}

		return $artifact;
	}

	private function isAgentOrCurator(int $userId): bool {
		$user = function_exists('get_userdata') ? get_userdata($userId) : false;
		if (! $user instanceof WP_User) {
			return false;
		}

		$roles = (array) $user->roles;

		return in_array(Capabilities::ROLE_AGENT, $roles, true) || in_array(Capabilities::ROLE_CURATOR, $roles, true);
	}

	/** Same 80-char excerpt title ProposalService uses for proposed knowledge. */
	private function excerptTitle(string $text): string {
		$title = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

		if (strlen($title) > 80) {
			$title = substr($title, 0, 77) . '...';
		}

		return $title;
	}

	/** The author's agent FQDN (docs/rfc/agent-id-fqdn.md), or null. */
	public static function agentFqdn(int $userId): ?string {
		if ($userId <= 0 || ! function_exists('get_user_meta')) {
			return null;
		}

		$fqdn = get_user_meta($userId, AgentMeta::FQDN, true);

		return is_string($fqdn) && $fqdn !== '' ? $fqdn : null;
	}

	/** @param array<string, mixed> $meta */
	private function audit(int $actorId, string $event, int $commentId, array $meta): void {
		if (! function_exists('do_action')) {
			return;
		}

		do_action('agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => $event,
			'status'      => 'ok',
			'object_type' => Cpt::POST_TYPE,
			'object_id'   => (string) $commentId,
			'meta'        => $meta,
		]);
	}

	private static function optionalInt(mixed $value): ?int {
		return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
	}

	private static function optionalString(mixed $value): ?string {
		return is_string($value) && $value !== '' ? $value : null;
	}

	private function denied(): WP_Error {
		return new WP_Error('ab_forbidden', __('You do not have permission to do this.', 'agent-brain'), ['status' => 403]);
	}

	private function notFound(): WP_Error {
		return new WP_Error('ab_not_found', __('Not found.', 'agent-brain'), ['status' => 404]);
	}

	private function invalid(string $message): WP_Error {
		return new WP_Error('ab_invalid_input', $message, ['status' => 422]);
	}
}
