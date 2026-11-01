<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Core\Text;
use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\Notices;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * Curator-facing review queue. Since #701 it is one inbox (InboxItems,
 * drawn by InboxView, driven by assets/review-inbox.js) for proposed facts
 * and procedures, handoffs, imported memories and proposed agents; this
 * class keeps the page, the knowledge handlers (per-row nonce +
 * object-level cap, re-checked in ReviewActions regardless of what this
 * page allows through) and the bulk form's handler.
 *
 * #658: plainer words. One sentence on top says what these are and that
 * the memory is kept whatever the curator chooses; the buttons read "Make
 * official", "Not needed" and "Make official as edited"; the diff shows
 * only against a close match (Diff::CLOSE_MATCH_THRESHOLD), otherwise the
 * column says "New fact".
 */
final class ReviewQueuePage {

	public const CAPABILITY = Capabilities::CAP_APPROVE_KNOWLEDGE;
	public const SLUG       = 'agent-brain-review';
	public const NONCE_ACTION = 'ab_review_action';
	public const NONCE_FIELD  = 'ab_review_nonce';

	/** #701: seconds a decision waits in the inbox, with Undo, before it is sent. */
	public const UNDO_SECONDS = 6;

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addMenu']);
		add_action('admin_post_ab_review_action', [self::class, 'handleAction']);
		add_action('admin_post_ab_review_bulk', [self::class, 'handleBulk']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
	}

	/**
	 * Admin IA wave: re-parented under the single "TomaTío" top-level
	 * menu (Menu::PARENT) instead of registering its own top-level page.
	 * Same SLUG, same CAPABILITY, registered at the default admin_menu
	 * priority (10) via add_submenu_page() -- existing
	 * `admin.php?page=agent-brain-review` URLs (including the e2e suite's)
	 * keep working unchanged; only the parent changes.
	 */
	public static function addMenu(): void {
		add_submenu_page(
			Menu::PARENT,
			__('Review Queue', 'agent-brain'),
			__('Review Queue', 'agent-brain'),
			self::CAPABILITY,
			self::SLUG,
			[self::class, 'render']
		);
	}

	/**
	 * Proposals the current user may see: only those within their scopes
	 * (#552).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function proposals(): array {
		$posts = get_posts([
			'post_type'      => ['ab_knowledge', 'ab_procedure'],
			'post_status'    => PostStatuses::PROPOSED,
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		]);

		$viewerId = get_current_user_id();
		$rows     = [];
		foreach ($posts as $post) {
			if (! ScopeVisibility::canSeePost($viewerId, $post)) {
				continue;
			}

			$scopeTerms = wp_get_post_terms($post->ID, 'ab_scope');
			$scopeName  = is_array($scopeTerms) && $scopeTerms !== [] ? $scopeTerms[0]->name : '';
			$scopeId    = is_array($scopeTerms) && $scopeTerms !== [] ? (int) $scopeTerms[0]->term_id : 0;
			$agentId    = (int) get_post_meta($post->ID, ProposalService::META_PROPOSING_AGENT, true);
			$agent      = $agentId > 0 ? get_userdata($agentId) : false;
			$excerpt    = self::blockExcerpt($post->post_content);

			$rows[] = [
				'id'         => $post->ID,
				'post_type'  => $post->post_type,
				// B3: the queue previously showed only a 3-line excerpt with
				// no title and no way to read the rest -- a curator asked to
				// approve a 3,442-word assembled paper had no way to see it
				// in full before deciding. title/edit_link/word_count let the
				// row show a title linked to the full readable content plus
				// its size.
				'title'      => $post->post_title !== '' ? $post->post_title : (string) $post->ID,
				'edit_link'  => (string) get_edit_post_link($post->ID, 'raw'),
				'word_count' => self::wordCount($excerpt),
				'excerpt'    => $excerpt,
				'scope_id'   => $scopeId,
				'scope_name' => $scopeName,
				'agent_id'   => $agentId,
				'agent_name' => $agent instanceof \WP_User ? $agent->display_name : (string) $agentId,
				'importance' => (int) get_post_meta($post->ID, ProposalService::META_IMPORTANCE, true),
				'diff'       => Diff::renderAgainstMostSimilar($scopeId, $post->post_content, $post->post_type),
			];
		}

		return $rows;
	}

	/**
	 * Turns serialized block markup (`post_content` from an assembled
	 * artifact, e.g. `<!-- wp:heading --><h2>Storage Model</h2><!-- /wp:heading
	 * --><!-- wp:paragraph --><p>Every memory row carries...</p>...`) into a
	 * readable excerpt.
	 *
	 * Bug fixed here: stripping tags from the raw serialized markup with
	 * `wp_strip_all_tags()` (via `wp_trim_words()`) glues adjacent blocks
	 * together with no whitespace between them -- e.g. a heading
	 * "Storage Model" immediately followed by a paragraph "Every memory row
	 * carries..." rendered in the queue as "Storage ModelEvery memory row
	 * carries...". Parsing blocks and joining each block's own text with a
	 * separator keeps them apart regardless of which tags surround them.
	 *
	 * Follow-up wave, Unit G: the actual extraction now lives in
	 * `Core\Text::plainTextFromBlocks()` (also used by ArtifactService's
	 * assembly-episode summary and, via Diff, the review-queue's similarity
	 * scoring/diff column) -- this stays public and delegates, so every
	 * existing caller of `blockExcerpt()` keeps working unchanged.
	 */
	public static function blockExcerpt(string $content): string {
		return Text::plainTextFromBlocks($content);
	}

	/**
	 * Whitespace-delimited word count of already-plain-text $text (the
	 * output of {@see self::blockExcerpt()}, not raw block markup).
	 */
	private static function wordCount(string $text): int {
		$text = trim($text);
		if ($text === '') {
			return 0;
		}

		return count(preg_split('/\s+/', $text) ?: []);
	}

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to review knowledge proposals.', 'agent-brain'));
		}

		// #701: one inbox for everything waiting (InboxItems), drawn by
		// InboxView. ?item= selects an item and ab_type / ab_scope /
		// ab_agent filter the list, so the page works without JavaScript.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$type    = isset($_GET['ab_type']) ? sanitize_key(wp_unslash($_GET['ab_type'])) : '';
		$filters = [
			'type'  => in_array($type, InboxItems::TYPES, true) ? $type : '',
			'scope' => isset($_GET['ab_scope']) ? absint(wp_unslash($_GET['ab_scope'])) : 0,
			'agent' => isset($_GET['ab_agent']) ? absint(wp_unslash($_GET['ab_agent'])) : 0,
		];
		$item    = isset($_GET['item']) ? sanitize_key(wp_unslash($_GET['item'])) : '';
		// phpcs:enable

		InboxView::render(
			InboxItems::all(),
			$filters,
			$item,
			__('These are facts your Tíos suggest making official team knowledge, which every agent with access then relies on. The memory each one came from is kept whatever you choose.', 'agent-brain'),
			self::howItRunsItself()
		);
	}

	/**
	 * #701: assets/review-inbox.js on this page only, with its words.
	 */
	public static function enqueueAssets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
		if ($page !== self::SLUG || ! function_exists('wp_enqueue_script') || ! defined('AGENT_BRAIN_PLUGIN_FILE')) {
			return;
		}

		wp_enqueue_script(
			'agent-brain-review-inbox',
			plugins_url('assets/review-inbox.js', AGENT_BRAIN_PLUGIN_FILE),
			[],
			\AgentBrain\Core\AssetVersion::of('assets/review-inbox.js'),
			true
		);

		wp_add_inline_script(
			'agent-brain-review-inbox',
			'window.agentBrainReview = ' . wp_json_encode([
				'endpoint' => admin_url('admin-post.php'),
				'delay'    => self::UNDO_SECONDS * 1000,
				'text'     => [
					/* translators: 1: items left, 2: items at the start */
					'left'     => __('%1$d of %2$d left', 'agent-brain'),
					/* translators: %d: number of selected items */
					'selected' => __('%d selected', 'agent-brain'),
					'undo'     => __('Undo', 'agent-brain'),
					'undone'   => __('Undone. It is back in the list.', 'agent-brain'),
					'failed'   => __('That did not go through, so the item is back in the list.', 'agent-brain'),
					/* translators: 1: item title, 2: what happened, e.g. "made official" */
					'decided'  => __('"%1$s" %2$s.', 'agent-brain'),
					/* translators: 1: number of items, 2: what happened, e.g. "made official" */
					'decidedN' => __('%1$d items %2$s.', 'agent-brain'),
					'noChange' => __('No changes yet.', 'agent-brain'),
					'changes'  => __('Your changes to the agent\'s text:', 'agent-brain'),
					'caught'   => __('All caught up. Reload the page to see anything new.', 'agent-brain'),
				],
			]) . ';',
			'before'
		);
	}

	/**
	 * #701: the inbox script posts each form with ab_js=1 and reads the
	 * answer as JSON; a plain form post gets the notice and the redirect,
	 * as before. Shared by the four handlers the inbox posts to.
	 *
	 * @param true|\WP_Error|null $result
	 */
	public static function respond($result, string $successMessage, string $redirect): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller verified the nonce.
		if (! empty($_POST['ab_js'])) {
			if ($result === true) {
				wp_send_json_success(['message' => $successMessage]);
			}
			wp_send_json_error(['message' => $result instanceof \WP_Error ? $result->get_error_message() : __('Nothing was changed.', 'agent-brain')], 400);
		}

		if ($result instanceof \WP_Error) {
			Notices::flash('error', $result->get_error_message());
		} elseif ($result === true && $successMessage !== '') {
			Notices::flash('success', $successMessage);
		}

		wp_safe_redirect($redirect);
		exit;
	}

	/**
	 * #701: the reason for Not needed, from the inbox's choices and words,
	 * as one line ("Duplicate: says the same as the zip cap"); empty when
	 * the curator gave none.
	 */
	public static function reasonFromRequest(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller verified the nonce.
		$choice = isset($_POST['review_reason']) ? sanitize_key(wp_unslash($_POST['review_reason'])) : '';
		$words  = isset($_POST['review_note']) ? sanitize_text_field(wp_unslash($_POST['review_note'])) : '';
		// phpcs:enable

		$label = InboxView::reasons()[$choice] ?? '';
		$words = mb_substr(trim($words), 0, 300);

		if ($label !== '' && $words !== '') {
			/* translators: 1: chosen reason, 2: the curator's words */
			return sprintf(__('%1$s: %2$s', 'agent-brain'), $label, $words);
		}

		return $label !== '' ? $label : $words;
	}

	/**
	 * #658: one line on what happens without a click: automatic approval
	 * for curators' own Tíos when it is on, and when untouched items close.
	 */
	private static function howItRunsItself(): string {
		$days  = Expiry::days();
		$parts = [];

		$parts[] = AutoApprove::enabled()
			? __('Facts from curators\' own Tíos are made official automatically and do not wait here.', 'agent-brain')
			: __('Facts from curators\' own Tíos wait here too; you can have them made official automatically.', 'agent-brain');

		$parts[] = $days > 0
			? sprintf(
				/* translators: %d: number of days */
				_n('Anything nobody decides within %d day closes as not needed.', 'Anything nobody decides within %d days closes as not needed.', $days, 'agent-brain'),
				$days
			)
			: __('Items wait here until someone decides.', 'agent-brain');

		return implode(' ', $parts);
	}

	public static function handleAction(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to review knowledge proposals.', 'agent-brain'));
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified explicitly below (per-row nonce).
		$postId = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
		check_admin_referer(self::NONCE_ACTION . '_' . $postId, self::NONCE_FIELD);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$outcome = isset($_POST['outcome']) ? sanitize_key(wp_unslash($_POST['outcome'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$content = isset($_POST['edited_content']) ? sanitize_textarea_field(wp_unslash($_POST['edited_content'])) : '';

		$curatorId = get_current_user_id();
		$actions   = new ReviewActions();

		$result = null;
		if ($outcome === 'approved') {
			$result = $actions->approve($curatorId, $postId);
		} elseif ($outcome === 'rejected') {
			$result = $actions->reject($curatorId, $postId, self::reasonFromRequest());
		} elseif ($outcome === 'edited') {
			$result = $actions->editAndApprove($curatorId, $postId, $content);
		}

		self::respond($result, self::outcomeMessage($outcome, $postId), admin_url('admin.php?page=' . self::SLUG));
	}

	private static function outcomeMessage(string $outcome, int $postId): string {
		$title = get_the_title($postId);
		if ($title === '') {
			$title = '#' . $postId;
		}

		$messages = [
			/* translators: %s: item title */
			'approved' => __('"%s" is official now. Agents with access to its scope can rely on it.', 'agent-brain'),
			/* translators: %s: item title */
			'edited'   => __('"%s" is official now, with your edits.', 'agent-brain'),
			/* translators: %s: item title */
			'rejected' => __('"%s" was not made official. The memory it came from is kept.', 'agent-brain'),
		];

		return isset($messages[$outcome]) ? sprintf($messages[$outcome], $title) : '';
	}

	public static function handleBulk(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to review knowledge proposals.', 'agent-brain'));
		}

		check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$postIds = isset($_POST['post_ids']) && is_array($_POST['post_ids'])
			? array_map('absint', wp_unslash($_POST['post_ids']))
			: [];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$outcome = isset($_POST['bulk_outcome']) ? sanitize_key(wp_unslash($_POST['bulk_outcome'])) : '';

		if (in_array($outcome, [ReviewActions::OUTCOME_APPROVED, ReviewActions::OUTCOME_REJECTED], true) && $postIds !== []) {
			$results = (new ReviewActions())->bulk(get_current_user_id(), $postIds, $outcome);
			$failed  = count(array_filter($results, static fn ($r): bool => $r instanceof \WP_Error));
			$done    = count($results) - $failed;
			if ($done > 0) {
				Notices::flash('success', sprintf(
					/* translators: 1: number of items, 2: outcome (approved or rejected) */
					_n('%1$d item %2$s.', '%1$d items %2$s.', $done, 'agent-brain'),
					$done,
					$outcome === ReviewActions::OUTCOME_APPROVED ? __('made official', 'agent-brain') : __('marked not needed', 'agent-brain')
				));
			}
			if ($failed > 0) {
				Notices::flash('error', sprintf(
					/* translators: %d: number of items that could not be updated */
					_n('%d item could not be updated.', '%d items could not be updated.', $failed, 'agent-brain'),
					$failed
				));
			}
		} else {
			Notices::flash('warning', __('Nothing was changed: select at least one item and a bulk action.', 'agent-brain'));
		}

		wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
		exit;
	}
}
