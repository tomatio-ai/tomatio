<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Comments\AdminActions as CommentActions;
use AgentBrain\Modules\Comments\CommentService;
use AgentBrain\Modules\Comments\Cpt;
use AgentBrain\Modules\Review\InboxItems;

/**
 * #751: comment threads in Library, next to the documents they are on, as
 * the Review inbox's list and detail: threads grouped per document on the
 * left, the picked thread with its replies on the right, where a curator
 * replies, resolves or reopens it. LibraryPage routes
 * edit.php?post_type=ab_comment here.
 */
final class CommentsPage {

	public const SLUG = 'agent-brain-comments';

	private const VIEWS = ['open', 'resolved'];

	private const LIMIT = 200;

	/**
	 * @param array<string, string> $args
	 */
	public static function url(array $args = []): string {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	public static function render(): void {
		$object = get_post_type_object(Cpt::POST_TYPE);
		if (! $object instanceof \WP_Post_Type || ! current_user_can((string) $object->cap->edit_posts)) {
			wp_die(esc_html__('You do not have permission to view this page.', 'agent-brain'), 403);
		}

		$viewerId = get_current_user_id();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters, no state change.
		$view     = isset($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : 'open';
		$view     = in_array($view, self::VIEWS, true) ? $view : 'open';
		$document = isset($_GET['document']) ? absint(wp_unslash($_GET['document'])) : 0;
		$picked   = isset($_GET['item']) ? absint(wp_unslash($_GET['item'])) : 0;
		// phpcs:enable

		$threads = self::threads($viewerId);
		$counts  = ['open' => 0, 'resolved' => 0];
		$docs    = [];
		foreach ($threads as $thread) {
			$resolved = self::isResolved((int) $thread->ID);
			++$counts[$resolved ? 'resolved' : 'open'];
			if (! $resolved) {
				$docs[self::artifactOf($thread)] = true;
			}
		}

		$status = sprintf(
			/* translators: 1: open threads, 2: documents they are on */
			_n('%1$s open on %2$s document', '%1$s open on %2$s documents', count($docs), 'agent-brain'),
			'<b>' . esc_html(number_format_i18n($counts['open'])) . '</b>',
			'<b>' . esc_html(number_format_i18n(count($docs))) . '</b>'
		);
		$about = '<p>' . esc_html__('Agents and people comment on a version of a document without changing it, on the whole document or one section. A comment stays open until someone resolves it: the curator, the document\'s lead, or whoever started the thread.', 'agent-brain') . '</p>'
			. '<p>' . esc_html__('People who open an HTML page through a share link can comment too, when the page allows it.', 'agent-brain')
			. ' <a href="' . esc_url(LibraryPage::classicUrl(Cpt::POST_TYPE)) . '">' . esc_html__('Open the WordPress list', 'agent-brain') . '</a> ' . esc_html__('for trash and bulk actions.', 'agent-brain') . '</p>';

		$head = Ui::head(__('Comments', 'agent-brain'), $status, '', $about);
		echo '<div class="wrap ab-admin ab-admin-comments">' . $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		self::renderFilters($view, $document, $counts, $threads);

		$shown = array_values(array_filter($threads, static function (\WP_Post $thread) use ($view, $document): bool {
			return self::isResolved((int) $thread->ID) === ($view === 'resolved')
				&& ($document === 0 || self::artifactOf($thread) === $document);
		}));

		if ($shown === []) {
			$empty = EmptyState::html(
				$view === 'resolved' ? __('Nothing resolved yet', 'agent-brain') : __('No open comments', 'agent-brain'),
				$document > 0
					? __('This document has none here. Show every document to see the rest.', 'agent-brain')
					: __('Comments appear when an agent or a person comments on a document. Nothing waits for an answer.', 'agent-brain'),
				['label' => __('Open Documents', 'agent-brain'), 'url' => DocumentsPage::url()]
			);
			echo $empty . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmptyState escapes.

			return;
		}

		// Grouped per document, newest thread first within each.
		usort($shown, static fn (\WP_Post $a, \WP_Post $b): int => [self::documentTitle(self::artifactOf($a)), self::artifactOf($a), $b->post_date_gmt] <=> [self::documentTitle(self::artifactOf($b)), self::artifactOf($b), $a->post_date_gmt]);

		$args     = array_filter(['view' => $view !== 'open' ? $view : '', 'document' => $document > 0 ? (string) $document : '']);
		$selected = $picked > 0 ? $picked : (int) $shown[0]->ID;
		$rows     = '';
		$group    = -1;
		foreach ($shown as $thread) {
			$artifactId = self::artifactOf($thread);
			if ($artifactId !== $group) {
				$group = $artifactId;
				$rows .= '<li class="ab-ui-list-group">' . esc_html(self::documentTitle($artifactId)) . '</li>';
			}
			$rows .= Ui::row(
				self::url($args + ['item' => (string) $thread->ID]),
				self::excerpt((string) $thread->post_content),
				esc_html(InboxItems::who((int) $thread->post_author)['name']) . ' · ' . esc_html(self::versionWords($thread)) . ' · ' . self::ago($thread->post_date_gmt),
				(int) $thread->ID === $selected
			);
		}

		$split = Ui::split(__('Comments', 'agent-brain'), $rows, self::detailHtml($selected, self::url($args), $viewerId), $picked > 0);
		echo $split . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
	}

	/**
	 * Thread roots on documents the viewer may open.
	 *
	 * @return \WP_Post[]
	 */
	private static function threads(int $viewerId): array {
		$posts = get_posts([
			'post_type'      => Cpt::POST_TYPE,
			'post_status'    => 'private',
			'post_parent'    => 0,
			'post__not_in'   => ScopeVisibility::hiddenPostIds($viewerId, Cpt::POST_TYPE),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'posts_per_page' => self::LIMIT,
			'no_found_rows'  => true,
		]);
		$service = new CommentService();

		return array_values(array_filter($posts, static fn ($p): bool => $p instanceof \WP_Post && $service->canRead($viewerId, self::artifactOf($p))));
	}

	/**
	 * The picked thread: what was said, its replies, where it is anchored,
	 * a reply box, and Resolve or Reopen.
	 */
	private static function detailHtml(int $id, string $back, int $viewerId): string {
		$thread  = $id > 0 ? get_post($id) : null;
		$service = new CommentService();
		if (! $thread instanceof \WP_Post || $thread->post_type !== Cpt::POST_TYPE || (int) $thread->post_parent !== 0 || ! $service->canRead($viewerId, self::artifactOf($thread))) {
			return Ui::detail('', '<p class="ab-empty">' . esc_html__('That comment no longer exists, or it is on a document you cannot open.', 'agent-brain') . '</p>', '', $back);
		}

		$artifactId = self::artifactOf($thread);
		$resolved   = self::isResolved($id);

		$body = '<div class="ab-ui-text">' . esc_html((string) $thread->post_content) . '</div>';

		$facts = [
			__('By', 'agent-brain') => esc_html(InboxItems::who((int) $thread->post_author)['label']) . ', ' . DateFormat::html($thread->post_date_gmt),
			__('On', 'agent-brain') => esc_html(self::versionWords($thread, true) . ', ' . self::anchorWords($thread)),
		];
		if ($resolved) {
			$by = (int) get_post_meta($id, Cpt::META_RESOLVED_BY, true);
			$facts[__('Status', 'agent-brain')] = esc_html(sprintf(
				/* translators: %s: who resolved the thread */
				__('Resolved by %s', 'agent-brain'),
				$by > 0 ? InboxItems::who($by)['label'] : __('someone', 'agent-brain')
			)) . ', ' . DateFormat::html((string) get_post_meta($id, Cpt::META_RESOLVED_AT, true));
		} else {
			$facts[__('Status', 'agent-brain')] = esc_html__('Open', 'agent-brain');
		}
		$body .= Ui::facts($facts);

		$replies = get_posts([
			'post_type'      => Cpt::POST_TYPE,
			'post_status'    => 'private',
			'post_parent'    => $id,
			'orderby'        => ['date' => 'ASC', 'ID' => 'ASC'],
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		]);
		if ($replies !== []) {
			$body .= '<h2 class="ab-ui-kicker">' . esc_html(sprintf(
				/* translators: %d: number of replies */
				_n('%d reply', '%d replies', count($replies), 'agent-brain'),
				count($replies)
			)) . '</h2><ol class="ab-ui-thread">';
			foreach ($replies as $reply) {
				$body .= '<li><p class="ab-ui-who"><strong>' . esc_html(InboxItems::who((int) $reply->post_author)['label']) . '</strong> · ' . self::ago($reply->post_date_gmt) . '</p><div class="ab-ui-text">' . esc_html((string) $reply->post_content) . '</div></li>';
			}
			$body .= '</ol>';
		}

		$body .= '<form id="ab-comment-reply" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr(CommentActions::ACTION_REPLY) . '" />'
			. '<input type="hidden" name="' . esc_attr(CommentActions::FIELD_COMMENT) . '" value="' . esc_attr((string) $id) . '" />'
			. '<input type="hidden" name="' . esc_attr(CommentActions::NONCE_FIELD) . '" value="' . esc_attr(wp_create_nonce(CommentActions::NONCE_ACTION . '_' . $id)) . '" />'
			. '<p><label for="ab_comment_body">' . esc_html__('Reply', 'agent-brain') . '</label><br /><textarea id="ab_comment_body" name="body" rows="3" class="large-text" required></textarea></p>'
			. '</form>';

		$actions = '<button type="submit" form="ab-comment-reply" class="button button-primary">' . esc_html__('Reply', 'agent-brain') . '</button>';
		if ($service->canResolve($viewerId, $id)) {
			$actions .= '<span class="ab-ui-spacer"></span><a class="button" href="' . esc_url(CommentActions::resolveUrl($id, ! $resolved)) . '">' . esc_html($resolved ? __('Reopen', 'agent-brain') : __('Resolve', 'agent-brain')) . '</a>';
		}
		$edit = (string) get_edit_post_link($artifactId, 'raw');
		if ($edit !== '') {
			$actions .= '<a class="button-link" href="' . esc_url($edit) . '">' . esc_html__('Open the document', 'agent-brain') . '</a>';
		}

		/* translators: %s: document title */
		return Ui::detail(sprintf(__('Comment · %s', 'agent-brain'), self::documentTitle($artifactId)), $body, $actions, $back);
	}

	/**
	 * @param array<string, int> $counts view => threads
	 * @param \WP_Post[]         $threads
	 */
	private static function renderFilters(string $view, int $document, array $counts, array $threads): void {
		$labels = ['open' => __('Open', 'agent-brain'), 'resolved' => __('Resolved', 'agent-brain')];
		$chips  = [];
		foreach (self::VIEWS as $v) {
			$chips[] = ['label' => $labels[$v], 'url' => self::url(array_filter(['view' => $v === 'open' ? '' : $v, 'document' => $document > 0 ? (string) $document : ''])), 'count' => $counts[$v], 'current' => $v === $view];
		}

		$titles = [];
		foreach ($threads as $thread) {
			$titles[self::artifactOf($thread)] = self::documentTitle(self::artifactOf($thread));
		}
		asort($titles);

		echo '<form method="get" class="ab-ui-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />';
		if ($view !== 'open') {
			echo '<input type="hidden" name="view" value="' . esc_attr($view) . '" />';
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::chips($chips, __('Show', 'agent-brain'));
		echo '<label for="ab_comment_document" class="ab-ui-sr">' . esc_html__('Document', 'agent-brain') . '</label><select id="ab_comment_document" name="document"><option value="0">' . esc_html__('Every document', 'agent-brain') . '</option>';
		foreach ($titles as $id => $title) {
			echo '<option value="' . esc_attr((string) $id) . '"' . selected($document, $id, false) . '>' . esc_html($title) . '</option>';
		}
		echo '</select><button type="submit" class="button">' . esc_html__('Show', 'agent-brain') . '</button>';
		if ($document > 0) {
			echo '<a class="button-link" href="' . esc_url(self::url($view === 'open' ? [] : ['view' => $view])) . '">' . esc_html__('Clear', 'agent-brain') . '</a>';
		}
		echo '</form>';
	}

	private static function artifactOf(\WP_Post $comment): int {
		return (int) get_post_meta((int) $comment->ID, Cpt::META_ARTIFACT, true);
	}

	private static function isResolved(int $commentId): bool {
		return (int) get_post_meta($commentId, Cpt::META_RESOLVED, true) === 1;
	}

	private static function documentTitle(int $artifactId): string {
		$post = $artifactId > 0 ? get_post($artifactId) : null;

		return $post instanceof \WP_Post ? LibraryPage::title($post) : __('(no title)', 'agent-brain');
	}

	private static function versionWords(\WP_Post $comment, bool $capital = false): string {
		$version = max(1, (int) get_post_meta((int) $comment->ID, Cpt::META_VERSION, true));

		return $capital
			/* translators: %d: version number */
			? sprintf(__('Version %d', 'agent-brain'), $version)
			/* translators: %d: version number */
			: sprintf(__('on version %d', 'agent-brain'), $version);
	}

	private static function anchorWords(\WP_Post $comment): string {
		$parts     = [];
		$sectionId = (int) get_post_meta((int) $comment->ID, Cpt::META_SECTION, true);
		if ($sectionId > 0) {
			$section = get_post($sectionId);
			$parts[] = $section instanceof \WP_Post && $section->post_type === Cpts::SECTION_POST_TYPE && $section->post_title !== ''
				/* translators: %s: section title */
				? sprintf(__('section “%s”', 'agent-brain'), $section->post_title)
				: __('one section', 'agent-brain');
		}
		$element = trim((string) get_post_meta((int) $comment->ID, Cpt::META_ELEMENT, true));
		if ($element !== '') {
			$parts[] = $element;
		}

		return $parts !== [] ? implode(' › ', $parts) : __('whole document', 'agent-brain');
	}

	private static function excerpt(string $text): string {
		$text = trim((string) preg_replace('/\s+/', ' ', $text));

		return mb_strlen($text) > 90 ? mb_substr($text, 0, 87) . '…' : $text;
	}

	private static function ago(string $gmt): string {
		$ts = strtotime($gmt . ' UTC');
		if ($ts === false) {
			return '';
		}
		$iso = gmdate('Y-m-d\\TH:i:s\\Z', $ts);

		/* translators: %s: a time span such as "3 days" */
		return '<time datetime="' . esc_attr($iso) . '" title="' . esc_attr($iso) . '">' . esc_html(sprintf(__('%s ago', 'agent-brain'), human_time_diff($ts, time()))) . '</time>';
	}
}
