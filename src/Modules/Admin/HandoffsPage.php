<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\MemoriesAdmin\Page as MemoriesPage;
use AgentBrain\Modules\Review\Expiry;
use AgentBrain\Modules\Review\HandoffCpt;
use AgentBrain\Modules\Review\HandoffQueue;
use AgentBrain\Modules\Review\InboxItems;
use AgentBrain\Modules\Review\InboxView;
use WP_Post;

/**
 * Handoffs (#751): their history as a list beside the selected one, instead
 * of WordPress's post table. Open handoffs are decided in the Review inbox,
 * so here they lead there. The Handoffs menu row stays the post-type list,
 * which redirects here.
 */
final class HandoffsPage {

	public const SLUG = 'agent-brain-handoffs';

	/** The post-type list's capability: curators and administrators. */
	public const CAPABILITY = 'edit_ab_handoffs';

	/** Chips: open, approved, dismissed by a person, closed by time. */
	public const VIEWS = ['open', 'approved', 'dismissed', 'expired'];

	private const LIMIT = 500;

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addPage']);
		add_action('admin_menu', [self::class, 'hideRow'], 1000);
		add_action('load-edit.php', [self::class, 'redirectList']);
	}

	public static function addPage(): void {
		$label = __('Handoffs', 'agent-brain');
		$hook  = add_submenu_page(Menu::PARENT, $label, $label, self::CAPABILITY, self::SLUG, [self::class, 'render']);
		if (is_string($hook) && $hook !== '') {
			// Without a visible menu row WordPress cannot find the page's title.
			add_action('load-' . $hook, static function () use ($label): void {
				$GLOBALS['title'] = $label;
			});
		}
	}

	/** The row stays (WordPress only opens pages that have one) and is hidden; Handoffs leads to it. */
	public static function hideRow(): void {
		global $submenu;

		if (! isset($submenu[Menu::PARENT]) || ! is_array($submenu[Menu::PARENT])) {
			return;
		}

		foreach ($submenu[Menu::PARENT] as $i => $item) {
			if ((string) ($item[2] ?? '') === self::SLUG) {
				$submenu[Menu::PARENT][$i][4] = trim((string) ($item[4] ?? '') . ' ' . MenuGroups::HIDDEN_CLASS); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- our own row.
			}
		}
	}

	public static function redirectList(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing, no state change.
		$args     = array_map('strval', array_keys((array) $_GET));
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing, no state change.
		$postType = isset($_GET['post_type']) ? sanitize_key((string) wp_unslash($_GET['post_type'])) : '';

		if ($postType !== HandoffCpt::POST_TYPE || array_diff($args, ['post_type']) !== []) {
			return;
		}

		wp_safe_redirect(self::url());
		exit;
	}

	/**
	 * @param array<string, string|int> $args
	 */
	public static function url(array $args = []): string {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to view handoffs.', 'agent-brain'), 403);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change.
		$view   = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : 'open';
		$view   = in_array($view, self::VIEWS, true) ? $view : 'open';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector, no state change.
		$itemId = isset($_GET['item']) ? absint(wp_unslash($_GET['item'])) : 0;

		$byView = array_fill_keys(self::VIEWS, []);
		foreach (self::handoffs(get_current_user_id()) as $post) {
			$byView[self::state((int) $post->ID)][] = $post;
		}

		$about = '<p>' . esc_html__('An agent hands work to a person with a handoff: a note and the memories it is about, for one curator or any curator. Approving it lets the recipient read the scopes those memories are in; dismissing it shares nothing.', 'agent-brain') . '</p>'
			. '<p>' . esc_html__('Open handoffs are decided in Review. This page keeps their history: what was approved, dismissed, or closed because nobody acted in time.', 'agent-brain') . '</p>';

		$open = count($byView['open']);
		$head = Ui::head(
			__('Handoffs', 'agent-brain'),
			sprintf(
				/* translators: %s: number of open handoffs */
				__('%s open · decide them in Review', 'agent-brain'),
				'<b>' . esc_html(number_format_i18n($open)) . '</b>'
			),
			$open > 0 ? '<a class="button" href="' . esc_url(InboxView::url(['ab_type' => InboxItems::TYPE_HANDOFF])) . '">' . esc_html__('Open Review', 'agent-brain') . '</a>' : '',
			$about
		);
		echo '<div class="wrap ab-admin ab-admin-handoffs">' . $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		$chips = [];
		foreach ([
			'open'      => __('Open', 'agent-brain'),
			'approved'  => __('Approved', 'agent-brain'),
			'dismissed' => __('Dismissed', 'agent-brain'),
			'expired'   => __('Closed by time', 'agent-brain'),
		] as $key => $label) {
			$chips[] = ['label' => $label, 'url' => self::url($key === 'open' ? [] : ['view' => $key]), 'count' => count($byView[$key]), 'current' => $view === $key];
		}
		echo '<div class="ab-ui-filters">' . Ui::chips($chips, __('Show', 'agent-brain')) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		$posts = $byView[$view];
		if ($posts === [] && $itemId === 0) {
			EmptyState::render(...self::emptyWords($view, array_sum(array_map('count', $byView)) === 0));
			echo '</div>';

			return;
		}

		$listArgs = $view === 'open' ? [] : ['view' => $view];
		$selected = $itemId > 0 ? $itemId : (int) $posts[0]->ID;
		$rows     = '';
		foreach ($posts as $post) {
			$id    = (int) $post->ID;
			$meta  = Ui::tag(self::stateLabel(self::state($id)), $view === 'open' ? 'new' : ($view === 'approved' ? 'ok' : ''))
				. ' ' . esc_html(InboxItems::who((int) $post->post_author)['label'])
				. ' · ' . esc_html(self::toLabel($id))
				. ' · ' . DateFormat::ago((string) $post->post_date_gmt);
			$rows .= Ui::row(self::url($listArgs + ['item' => $id]), self::title($post), $meta, $id === $selected);
		}

		echo Ui::split(__('Handoffs', 'agent-brain'), $rows, self::detailHtml($selected, self::url($listArgs)), $itemId > 0); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo '</div>';
	}

	/**
	 * Every handoff the viewer may see, newest first (#552: only those
	 * whose memories they can read).
	 *
	 * @return array<int, WP_Post>
	 */
	public static function handoffs(int $viewerId): array {
		$posts = get_posts([
			'post_type'      => HandoffCpt::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => self::LIMIT,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		]);

		return array_values(array_filter($posts, static fn (WP_Post $post): bool => ScopeVisibility::canSeePost($viewerId, $post)));
	}

	/** open, approved, dismissed or expired. */
	public static function state(int $handoffId): string {
		$status = HandoffQueue::status($handoffId);
		if ($status === null) {
			return 'open';
		}
		if ($status === HandoffQueue::STATUS_APPROVED) {
			return 'approved';
		}

		return (int) get_post_meta($handoffId, HandoffQueue::META_EXPIRED, true) === 1 ? 'expired' : 'dismissed';
	}

	private static function stateLabel(string $state): string {
		switch ($state) {
			case 'approved':
				return __('Approved', 'agent-brain');
			case 'dismissed':
				return __('Dismissed', 'agent-brain');
			case 'expired':
				return __('Closed by time', 'agent-brain');
			default:
				return __('Open', 'agent-brain');
		}
	}

	private static function title(WP_Post $post): string {
		$title = html_entity_decode((string) $post->post_title, ENT_QUOTES, 'UTF-8');

		return $title !== '' ? $title : '#' . $post->ID;
	}

	private static function recipient(int $handoffId): string {
		$to = (int) get_post_meta($handoffId, HandoffQueue::META_TO, true);

		return $to > 0 ? InboxItems::who($to)['label'] : __('Any curator', 'agent-brain');
	}

	private static function toLabel(int $handoffId): string {
		$to = (int) get_post_meta($handoffId, HandoffQueue::META_TO, true);

		return $to > 0
			/* translators: %s: who the handoff is for */
			? sprintf(__('to %s', 'agent-brain'), InboxItems::who($to)['label'])
			: __('to any curator', 'agent-brain');
	}

	private static function detailHtml(int $id, string $back): string {
		$post = get_post($id);
		if (! $post instanceof WP_Post || $post->post_type !== HandoffCpt::POST_TYPE || ! ScopeVisibility::canSeePost(get_current_user_id(), $post)) {
			return Ui::detail('', '<p class="ab-empty">' . esc_html__('That handoff does not exist.', 'agent-brain') . '</p>', '', $back);
		}

		$state     = self::state($id);
		$memoryIds = get_post_meta($id, HandoffQueue::META_MEMORY_IDS, true);
		$memoryIds = is_array($memoryIds) ? array_map('intval', $memoryIds) : [];
		$scopes    = InboxItems::scopeNames(HandoffQueue::scopeIds($memoryIds));
		$from      = InboxItems::who((int) $post->post_author);

		$body = '<h2>' . esc_html(self::title($post)) . '</h2>'
			. '<div class="ab-ui-who"><span class="ab-ui-avatar" aria-hidden="true">' . esc_html(strtoupper(mb_substr($from['name'], 0, 1))) . '</span><div>'
			. sprintf(
				/* translators: 1: who sent it, 2: when */
				esc_html__('From %1$s, %2$s', 'agent-brain'),
				'<strong>' . esc_html($from['label']) . '</strong>',
				DateFormat::ago((string) $post->post_date_gmt)
			) . '</div></div>';

		$note = trim(wp_strip_all_tags((string) $post->post_content));
		if ($note !== '') {
			$body .= '<div class="ab-ui-text">' . esc_html(html_entity_decode($note, ENT_QUOTES, 'UTF-8')) . '</div>';
		}

		$links = [];
		foreach ($memoryIds as $memoryId) {
			$links[] = '<a href="' . esc_url(add_query_arg(['page' => MemoriesPage::SLUG, 'memory' => $memoryId], admin_url('admin.php'))) . '">#' . (int) $memoryId . '</a>';
		}

		$facts = [
			__('Status', 'agent-brain') => Ui::tag(self::stateLabel($state), $state === 'open' ? 'new' : ($state === 'approved' ? 'ok' : '')),
			__('To', 'agent-brain')     => esc_html(self::recipient($id)),
			__('Shares', 'agent-brain') => esc_html(sprintf(
				/* translators: 1: number of memories, 2: scope names */
				_n('%1$d memory, read access to %2$s', '%1$d memories, read access to %2$s', count($memoryIds), 'agent-brain'),
				count($memoryIds),
				$scopes
			)) . ($links !== [] ? ' · ' . implode(' ', $links) : ''),
		];

		if ($state === 'open') {
			$closes = Expiry::expiresAt((string) $post->post_modified_gmt);
			$facts[__('If nobody decides', 'agent-brain')] = $closes !== null
				/* translators: %s: date it closes */
				? sprintf(esc_html__('Closes %s', 'agent-brain'), DateFormat::html($closes))
				: esc_html__('Stays open', 'agent-brain');
		} else {
			$facts[__('Decided', 'agent-brain')] = DateFormat::html((string) $post->post_modified_gmt);
			$why = (string) get_post_meta($id, HandoffQueue::META_NOTE, true);
			if ($why !== '') {
				$facts[__('Why', 'agent-brain')] = esc_html($why);
			}
		}
		$body .= Ui::facts($facts);

		$actions = $state === 'open'
			? '<a class="button button-primary" href="' . esc_url(InboxView::url(['item' => 'h-' . $id])) . '">' . esc_html__('Open in Review', 'agent-brain') . '</a>'
			: '';

		return Ui::detail(__('Handoff', 'agent-brain') . ' · ' . $scopes, $body, $actions, $back);
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private static function emptyWords(string $view, bool $none): array {
		if ($none) {
			return [
				__('No handoffs yet.', 'agent-brain'),
				__('An agent creates one with Ask a person to review work when it needs a person to share memory or check its work. Open ones wait in Review.', 'agent-brain'),
			];
		}

		switch ($view) {
			case 'approved':
				return [__('Nothing approved yet.', 'agent-brain'), __('Handoffs a curator approved show up here.', 'agent-brain')];
			case 'dismissed':
				return [__('Nothing dismissed.', 'agent-brain'), __('Handoffs a curator dismissed show up here, with the reason when one was given.', 'agent-brain')];
			case 'expired':
				return [__('Nothing closed by time.', 'agent-brain'), __('A handoff nobody decides closes by itself after the review window set in Settings > Review, without sharing anything.', 'agent-brain')];
			default:
				return [__('No open handoffs.', 'agent-brain'), __('Nothing is waiting. New handoffs are decided in Review.', 'agent-brain')];
		}
	}
}
