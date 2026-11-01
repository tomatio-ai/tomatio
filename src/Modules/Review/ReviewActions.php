<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Core\ActionContext;
use AgentBrain\Core\Db;
use AgentBrain\Core\Text;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Memory\InsertFailed;
use AgentBrain\Modules\Memory\MemoryRepository;
use WP_Error;
use WP_Post;

/**
 * REV-1/REV-2: curator actions on a proposal (approve, edit-and-approve,
 * reject) plus bulk variants. Every method takes the acting curator's user
 * id first and re-checks both the capability and the specific object
 * (object-level cap check per contract), never trusting a caller's own
 * current_user_can() context. Fails closed: any check failure returns a
 * WP_Error instead of mutating anything.
 *
 * #658: the same path closes a proposal without a click, either approved
 * automatically for the curator whose own agent proposed it
 * (approveAutomatically(), see AutoApprove) or expired after the expiry
 * period (expire(), see Expiry). Both write the reviewer record, audit
 * entry and outcome memory a click writes, marked as such.
 */
class ReviewActions {

	public const OUTCOME_APPROVED = 'approved';
	public const OUTCOME_EDITED   = 'edited';
	public const OUTCOME_REJECTED = 'rejected';

	/** Who reviewed a knowledge/procedure proposal, when, and how (post meta). */
	public const META_REVIEWED_BY    = 'ab_reviewed_by';
	public const META_REVIEWED_AT    = 'ab_reviewed_at';
	public const META_REVIEW_OUTCOME = 'ab_review_outcome';

	/**
	 * PROV-6 (#175): the approval's action context (post meta). client is
	 * the MCP client the approval arrived on (null from wp-admin, where
	 * the review queue runs); via is the person a delegated token acted
	 * for; ai is true when an AI client relayed the approval on a person's
	 * behalf; screen is the wp-admin screen id when the action ran in
	 * wp-admin. Entries approved before PROV-6 simply lack these keys.
	 */
	public const META_REVIEWED_CLIENT = 'ab_reviewed_client';
	public const META_REVIEWED_VIA    = 'ab_reviewed_via';
	public const META_REVIEWED_AI     = 'ab_reviewed_ai';
	public const META_REVIEWED_SCREEN = 'ab_reviewed_screen';

	/**
	 * #658: how a review closed when nobody clicked a button (post meta).
	 * auto is 1 on a proposal approved automatically because its proposing
	 * agent belongs to a curator (AutoApprove); expired is 1 on a proposal
	 * nobody decided within the expiry period (Expiry), with the reason in
	 * note. Reviews made by a person carry neither.
	 */
	public const META_REVIEWED_AUTO = 'ab_reviewed_auto';
	public const META_REVIEW_EXPIRED = 'ab_review_expired';
	public const META_REVIEW_NOTE    = 'ab_review_note';

	/** Audit event recorded for every knowledge/procedure review. */
	public const AUDIT_EVENT = 'knowledge_review';

	/** @return true|WP_Error */
	public function approve(int $curatorId, int $postId) {
		return $this->apply($curatorId, $postId, self::OUTCOME_APPROVED, null);
	}

	/**
	 * #658: approve on behalf of $ownerId, the curator whose own agent
	 * proposed the item (AutoApprove decides that). The same checks and
	 * records as a click on Make official, with the review marked
	 * automatic in post meta and the audit entry.
	 *
	 * @return true|WP_Error
	 */
	public function approveAutomatically(int $ownerId, int $postId) {
		return $this->apply($ownerId, $postId, self::OUTCOME_APPROVED, null, true);
	}

	/**
	 * #658: close a proposal nobody decided within $days as not made
	 * official (rejected), with an expiry note. No person acts, so there is
	 * no reviewer and no capability to check; only a proposal that is
	 * still open can expire. The memory it came from is never touched.
	 *
	 * @return true|WP_Error
	 */
	public function expire(int $postId, int $days) {
		$post = get_post($postId);
		if (! $post instanceof WP_Post || ! in_array($post->post_type, ['ab_knowledge', 'ab_procedure'], true)) {
			return new WP_Error('ab_not_found', 'Proposal not found.');
		}

		if ($post->post_status !== PostStatuses::PROPOSED) {
			return new WP_Error('ab_not_proposed', 'Only proposed items can expire.');
		}

		$note = sprintf(
			/* translators: %d: number of days */
			_n('Nobody decided within %d day, so it closed as not needed. The memory it came from is kept.', 'Nobody decided within %d days, so it closed as not needed. The memory it came from is kept.', $days, 'agent-brain'),
			$days
		);

		return $this->close($post, self::OUTCOME_REJECTED, $post->post_content, 0, ['expired' => true, 'days' => $days, 'note' => $note]);
	}

	/** @return true|WP_Error */
	public function editAndApprove(int $curatorId, int $postId, string $newContent) {
		return $this->apply($curatorId, $postId, self::OUTCOME_EDITED, $newContent);
	}

	/**
	 * #701: $note is the curator's optional reason (Review inbox). It is
	 * kept as the review note and added to the outcome memory, so the
	 * proposing agent learns why.
	 *
	 * @return true|WP_Error
	 */
	public function reject(int $curatorId, int $postId, string $note = '') {
		return $this->apply($curatorId, $postId, self::OUTCOME_REJECTED, null, false, $note);
	}

	/**
	 * @param int[] $postIds
	 * @return array<int, true|WP_Error> keyed by post id
	 */
	public function bulk(int $curatorId, array $postIds, string $outcome): array {
		$results = [];

		foreach ($postIds as $postId) {
			$postId = (int) $postId;
			$results[$postId] = $this->apply($curatorId, $postId, $outcome, null);
		}

		return $results;
	}

	/** @return true|WP_Error */
	private function apply(int $curatorId, int $postId, string $outcome, ?string $newContent, bool $automatic = false, string $note = '') {
		if (! in_array($outcome, [self::OUTCOME_APPROVED, self::OUTCOME_EDITED, self::OUTCOME_REJECTED], true)) {
			return new WP_Error('ab_invalid_outcome', 'Unknown review outcome.');
		}

		if (! function_exists('user_can') || ! user_can($curatorId, Capabilities::CAP_APPROVE_KNOWLEDGE)) {
			return new WP_Error('ab_forbidden', 'Missing ab_approve_knowledge capability.');
		}

		$post = get_post($postId);
		if (! $post instanceof WP_Post || ! in_array($post->post_type, ['ab_knowledge', 'ab_procedure'], true)) {
			return new WP_Error('ab_not_found', 'Proposal not found.');
		}

		// Object-level check: the object-level meta cap (map_meta_cap =>
		// true on both CPTs) resolves to edit_others_{plural} for a post
		// authored by someone else (the proposing agent), which only the
		// curator role/administrator holds.
		if (! user_can($curatorId, 'edit_post', $postId)) {
			return new WP_Error('ab_forbidden', 'Cannot edit this proposal.');
		}

		if ($post->post_status !== PostStatuses::PROPOSED) {
			return new WP_Error('ab_not_proposed', 'Only proposed items can be reviewed.');
		}

		$finalText = $outcome === self::OUTCOME_EDITED ? (string) $newContent : $post->post_content;

		$how = ['automatic' => $automatic];
		$note = trim($note);
		if ($note !== '' && $outcome === self::OUTCOME_REJECTED) {
			$how['note'] = $note;
		}

		return $this->close($post, $outcome, $finalText, $curatorId, $how);
	}

	/**
	 * Closes a proposal that apply() or expire() already checked: the status
	 * change, stats, reviewer record, audit entry, outcome memory and the
	 * agent_brain/review_outcome action, in that order. $how carries the
	 * #658 markers: automatic (bool), or expired (bool) with days and note.
	 *
	 * @param array{automatic?:bool, expired?:bool, days?:int, note?:string} $how
	 * @return true|WP_Error
	 */
	private function close(WP_Post $post, string $outcome, string $finalText, int $reviewerId, array $how) {
		$update = ['ID' => $post->ID];
		if ($outcome === self::OUTCOME_EDITED) {
			$update['post_content'] = $finalText;
		}
		$update['post_status'] = $outcome === self::OUTCOME_REJECTED ? PostStatuses::REJECTED : 'private';

		$result = wp_update_post($update, true);
		if (is_wp_error($result)) {
			return $result;
		}

		// The approval rate counts decisions; an expiry is nobody's decision.
		if (empty($how['expired'])) {
			Stats::record($outcome);
		}
		$this->recordReviewer($post, $outcome, $reviewerId, $how);
		$this->writeOutcomeMemory($post, $outcome, $finalText, ! empty($how['expired']), empty($how['expired']) ? (string) ($how['note'] ?? '') : '');

		if (function_exists('do_action')) {
			do_action('agent_brain/review_outcome', $post->ID, $outcome, $reviewerId);
		}

		return true;
	}

	/**
	 * Attribution for multi-human brains: section reviews already record
	 * their reviewer (Stages::appendReviewLog), but a knowledge/procedure
	 * review only fired agent_brain/review_outcome, so with more than one
	 * curator nobody could later tell who approved or rejected a fact (an
	 * approve-as-is leaves no revision, and post_author stays the proposing
	 * agent). Record the curator, time and outcome on the post and in the
	 * audit log.
	 *
	 * #658: an automatic approval records the curator it was made for and
	 * is marked automatic; an expiry has no reviewer and is marked expired
	 * with its note. Neither records PROV-6 action context, since no person
	 * acted through a client.
	 *
	 * @param array{automatic?:bool, expired?:bool, days?:int, note?:string} $how
	 */
	private function recordReviewer(WP_Post $post, string $outcome, int $curatorId, array $how = []): void {
		$automatic = ! empty($how['automatic']);
		$expired   = ! empty($how['expired']);

		if ($curatorId > 0) {
			update_post_meta($post->ID, self::META_REVIEWED_BY, $curatorId);
		}
		update_post_meta($post->ID, self::META_REVIEWED_AT, gmdate('Y-m-d H:i:s'));
		update_post_meta($post->ID, self::META_REVIEW_OUTCOME, $outcome);
		if ($automatic) {
			update_post_meta($post->ID, self::META_REVIEWED_AUTO, 1);
		}
		if ($expired) {
			update_post_meta($post->ID, self::META_REVIEW_EXPIRED, 1);
		}
		if ($expired || (string) ($how['note'] ?? '') !== '') {
			update_post_meta($post->ID, self::META_REVIEW_NOTE, (string) ($how['note'] ?? ''));
		}

		// PROV-6 (#175): the approval's action context — read from the Core
		// request registries (never caller input): which MCP client the
		// approval arrived on (null from wp-admin), which person a delegated
		// token acted for, whether an AI client relayed it, and the wp-admin
		// screen when the action ran there (a future approval UI that tracks
		// "did the curator open the diff" extends these keys, #176/#173).
		$context = ['client' => null, 'via' => 0, 'ai' => false];
		if (! $automatic && ! $expired) {
			$context = ActionContext::actorContext();
			update_post_meta($post->ID, self::META_REVIEWED_CLIENT, $context['client']);
			update_post_meta($post->ID, self::META_REVIEWED_AI, $context['ai'] ? 1 : 0);
			if ($context['via'] > 0) {
				update_post_meta($post->ID, self::META_REVIEWED_VIA, $context['via']);
			}
			if (function_exists('get_current_screen')) {
				$screen = get_current_screen();
				if ($screen && $screen->id !== '') {
					update_post_meta($post->ID, self::META_REVIEWED_SCREEN, (string) $screen->id);
				}
			}
		}

		$meta = [
			'outcome'     => $outcome,
			'proposer_id' => (int) get_post_meta($post->ID, ProposalService::META_PROPOSING_AGENT, true),
			'client'      => $context['client'],
			'via'         => $context['via'] > 0 ? $context['via'] : null,
			'ai'          => $context['ai'],
		];
		if ($automatic) {
			$meta['automatic'] = true;
		}
		if ($expired) {
			$meta['expired'] = true;
			$meta['days']    = (int) ($how['days'] ?? 0);
		}

		if (function_exists('do_action')) {
			do_action('agent_brain/audit', [
				'actor_id'    => $curatorId,
				'event'       => self::AUDIT_EVENT,
				'status'      => 'ok',
				'object_type' => $post->post_type,
				'object_id'   => (string) $post->ID,
				'meta'        => $meta,
			]);
		}
	}

	/**
	 * Outcome memory content is capped so a long-form proposal (an assembled
	 * artifact's block markup can run tens of thousands of characters) never
	 * lands as a single oversized, markup-laden episode memory row -- see
	 * B2 in the fix-wave contract. This is a CHARACTER cap (mb_strlen), not
	 * a byte cap -- see boundedPlainText(). Public: the Review migration
	 * (Unit F, Module::migrations()) reads this to detect legacy oversized
	 * rows without duplicating the constant.
	 */
	public const OUTCOME_TEXT_CAP = 1500;

	/**
	 * The exact prefixes writeOutcomeMemory() prepends per outcome, keyed by
	 * outcome. Public so the Unit F migration can find legacy rows by
	 * content prefix using the same literal strings (one source of truth --
	 * never hand-duplicate these in the migration).
	 *
	 * @var array<string, string>
	 */
	public const OUTCOME_PREFIXES = [
		self::OUTCOME_APPROVED => 'Your proposal was approved as-is: ',
		self::OUTCOME_EDITED   => 'Your proposal was approved with edits. Final text: ',
		self::OUTCOME_REJECTED => 'Your proposal was rejected: ',
	];

	/**
	 * #658: the outcome memory of a proposal that expired (rejected, but by
	 * nobody). Kept out of OUTCOME_PREFIXES: the Unit F migration only
	 * rewrites rows written before the cap, and these never were.
	 */
	public const OUTCOME_EXPIRED_PREFIX = 'Nobody reviewed your proposal in time, so it was not made official (the memory is kept): ';

	/** #701: the curator's reason when there is one. */
	public const OUTCOME_REASON_PREFIX = ' Reason given by the curator: ';

	private function writeOutcomeMemory(WP_Post $post, string $outcome, string $finalText, bool $expired = false, string $reason = ''): void {
		$agentId = (int) get_post_meta($post->ID, ProposalService::META_PROPOSING_AGENT, true);
		if ($agentId <= 0) {
			return;
		}

		$scopeTerms = wp_get_post_terms($post->ID, 'ab_scope', ['fields' => 'ids']);
		$scopeId    = is_array($scopeTerms) && $scopeTerms !== [] ? (int) $scopeTerms[0] : 0;
		if ($scopeId <= 0) {
			return;
		}

		$plainText = self::boundedPlainText($finalText, $post->ID);
		$prefix    = $expired ? self::OUTCOME_EXPIRED_PREFIX : (self::OUTCOME_PREFIXES[$outcome] ?? null);
		// Unreachable in practice: apply() already rejects any outcome not in
		// self::OUTCOME_PREFIXES before writeOutcomeMemory() is ever called.
		// Kept only so this match stays total and byte-identical to the
		// pre-refactor behaviour if that invariant is ever loosened.
		$content = $prefix !== null ? $prefix . $plainText : 'Review outcome: ' . $outcome;
		if ($reason !== '') {
			$content .= self::OUTCOME_REASON_PREFIX . $reason;
		}

		try {
			(new MemoryRepository())->insert([
				'agent_id'      => $agentId,
				'scope_id'      => $scopeId,
				'type'          => 'episode',
				'content'       => $content,
				'agent_written' => 0,
				'status'        => 'active',
				'importance'    => 50,
				'source_ids'    => [$post->ID],
			]);
		} catch (\InvalidArgumentException | InsertFailed $e) {
			// Fail loud, not fatal: an admin-visible degraded signal instead
			// of a silent drop (L26).
			if (function_exists('do_action')) {
				do_action('agent_brain/audit', [
					'actor_id'    => $agentId,
					'event'       => 'review_outcome_memory_write_failed',
					'status'      => 'error',
					'object_type' => 'ab_knowledge',
					'object_id'   => (string) $post->ID,
					'meta'        => ['message' => $e->getMessage()],
				]);
			}
		}
	}

	/**
	 * Plain-text, block-markup-stripped version of $rawContent (reusing the
	 * queue's own block-to-text renderer so headings/paragraphs stay
	 * separated instead of gluing together), capped at
	 * {@see self::OUTCOME_TEXT_CAP} CHARACTERS (mb_strlen, not bytes -- a
	 * CJK/emoji-heavy proposal costs more bytes per character, but the
	 * stored row is still bounded to the same reader-facing length) on a
	 * word boundary via the shared {@see Text::trimToWordBoundary()} helper,
	 * which is also what Briefing uses for B1 -- one UTF-8-safe cut
	 * implementation, not two. Content at or under the cap (the common
	 * case: a short proposal) is returned unchanged; content over the cap
	 * is trimmed and suffixed with a pointer back to the full knowledge
	 * post.
	 *
	 * Public static so both write-time (writeOutcomeMemory, $postId always
	 * known) and the Unit F legacy-row migration (Module::migrations(),
	 * $postId recovered from the memory row's source_ids when possible)
	 * share one implementation -- write-time behaviour/output is unchanged
	 * by this widening. When $postId is null (migration couldn't recover
	 * the source knowledge id) the "[full text: knowledge id N]" pointer is
	 * omitted entirely rather than printed with a fabricated id; the text is
	 * still cut to the same cap on the same word boundary.
	 */
	public static function boundedPlainText(string $rawContent, ?int $postId): string {
		$plain = ReviewQueuePage::blockExcerpt($rawContent);

		if (mb_strlen($plain, 'UTF-8') <= self::OUTCOME_TEXT_CAP) {
			return $plain;
		}

		$marker      = $postId !== null ? sprintf('… [full text: knowledge id %d]', $postId) : '…';
		$budgetChars = self::OUTCOME_TEXT_CAP - mb_strlen($marker, 'UTF-8');

		if ($budgetChars <= 0) {
			return $marker;
		}

		$cut = Text::trimToWordBoundary($plain, $budgetChars);

		return $cut . $marker;
	}

	/**
	 * Unit F: rewrites legacy REV-2 outcome memory rows written before the
	 * B2 cap existed -- rows whose content starts with one of
	 * {@see self::OUTCOME_PREFIXES} and whose text after the prefix still
	 * exceeds {@see self::OUTCOME_TEXT_CAP} once run through the same
	 * {@see self::boundedPlainText()} write-time uses. Called from the
	 * Review module's versioned migration (Module::migrations()) so it runs
	 * once per install on activation/upgrade, per Core\Migrations.
	 *
	 * Batched: each SELECT is cursor-paginated by id (`id > $lastId ...
	 * LIMIT 200`), never loading more than 200 rows at a time regardless of
	 * table size. Idempotent: a row whose bounded output already equals its
	 * current (post-prefix) content -- either a short legacy row that was
	 * never oversized, or a row this method already rewrote -- is left
	 * untouched, so a second run changes nothing. Touches only rows that
	 * matched a known outcome prefix; every other memory row is never
	 * selected, let alone written. Recovers the source knowledge id for the
	 * "[full text: knowledge id N]" pointer from the row's own `source_ids`
	 * column (writeOutcomeMemory always stores `[$post->ID]` there); when
	 * that column is missing/empty/unparseable the pointer is omitted
	 * rather than guessed (see boundedPlainText()'s $postId === null path).
	 *
	 * @return array{scanned:int, rewritten:int}
	 */
	public static function migrateLegacyOutcomeMemories(): array {
		global $wpdb;

		$table     = MemoryRepository::memoryTable();
		$scanned   = 0;
		$rewritten = 0;
		$lastId    = 0;

		do {
			$likeClauses = [];
			$params      = [$lastId];
			foreach (self::OUTCOME_PREFIXES as $prefix) {
				$likeClauses[] = 'content LIKE %s';
				$params[]      = $wpdb->esc_like($prefix) . '%';
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; Db::prepare() below is the enforcement point for every value.
			$sql = "SELECT id, content, source_ids FROM {$table}
				WHERE id > %d AND (" . implode(' OR ', $likeClauses) . ')
				ORDER BY id ASC
				LIMIT 200';

			$rows = Db::getResults($sql, $params);
			if ($rows === []) {
				break;
			}

			foreach ($rows as $row) {
				$rowId  = (int) $row['id'];
				$lastId = max($lastId, $rowId);
				$scanned++;

				$content = (string) $row['content'];
				$prefix  = self::matchingOutcomePrefix($content);
				if ($prefix === null) {
					continue;
				}

				$remainder   = substr($content, strlen($prefix));
				$knowledgeId = self::recoverKnowledgeId(isset($row['source_ids']) ? (string) $row['source_ids'] : '');
				$bounded     = self::boundedPlainText($remainder, $knowledgeId);

				if ($bounded === $remainder) {
					// Already within the cap (never oversized, or a row this
					// method already rewrote on a prior run) -- idempotent.
					continue;
				}

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
				$wpdb->query(Db::prepare(
					"UPDATE {$table} SET content = %s WHERE id = %d",
					[$prefix . $bounded, $rowId]
				));
				// phpcs:enable
				$rewritten++;
			}
		} while (count($rows) === 200);

		return ['scanned' => $scanned, 'rewritten' => $rewritten];
	}

	private static function matchingOutcomePrefix(string $content): ?string {
		foreach (self::OUTCOME_PREFIXES as $prefix) {
			if (str_starts_with($content, $prefix)) {
				return $prefix;
			}
		}

		return null;
	}

	/**
	 * Recovers the source knowledge post id from a memory row's raw
	 * `source_ids` column (JSON int[] mirror of ab_memory_links, per the
	 * contract) -- writeOutcomeMemory() always stores `[$post->ID]` there.
	 * Returns null (not a fabricated id) when the column is empty, not
	 * valid JSON, not an array, or its first element isn't a positive int.
	 */
	private static function recoverKnowledgeId(string $sourceIdsJson): ?int {
		if (trim($sourceIdsJson) === '') {
			return null;
		}

		$decoded = json_decode($sourceIdsJson, true);
		if (! is_array($decoded) || $decoded === []) {
			return null;
		}

		$id = (int) reset($decoded);

		return $id > 0 ? $id : null;
	}
}
