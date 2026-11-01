<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Provenance\Panel as ProvenancePanel;
use AgentBrain\Modules\Review\GrantTaxonomy;
use AgentBrain\Modules\Review\InboxItems;
use AgentBrain\Modules\Review\Lifecycle;
use AgentBrain\Modules\Review\Pins;
use AgentBrain\Modules\Review\PostStatuses;
use AgentBrain\Modules\Review\ProposalService;

/**
 * #751: Knowledge and Procedures as the Review inbox's list and detail
 * (Ui::split). Official items (private posts) are the default view;
 * Suggested ones wait in Review and open there; Not needed are the ones
 * Review turned down. Writing and editing stay in the block editor, and
 * WordPress's own list stays one link away for trash and bulk actions:
 * opening edit.php?post_type=ab_knowledge with no other arguments (the
 * menu, the group tabs) comes here instead. Documents (artifacts) and
 * Comments are routed the same way to DocumentsPage and CommentsPage.
 */
final class LibraryPage {

	/** post type => admin page slug */
	public const SLUGS = [
		'ab_knowledge' => 'agent-brain-knowledge',
		'ab_procedure' => 'agent-brain-procedures',
		'ab_artifact'  => DocumentsPage::SLUG,
		'ab_comment'   => CommentsPage::SLUG,
	];

	/** Ask for WordPress's own list with this query argument. */
	public const CLASSIC = 'ab_classic';

	private const PER_PAGE = 30;

	private const VIEWS = ['official', 'suggested', 'not-needed'];

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addPages']);
		add_action('admin_menu', [self::class, 'hideRows'], 1000);
		add_action('load-edit.php', [self::class, 'redirectList']);
	}

	/**
	 * Registered under the TomaTío menu, so it opens on these pages and
	 * MenuGroups marks Library (MenuGroups::ALIASES); hideRows() then hides
	 * their own rows, since the Library entries stay the post-type lists,
	 * which redirect here.
	 */
	public static function addPages(): void {
		foreach (self::SLUGS as $postType => $slug) {
			$object = get_post_type_object($postType);
			if (! $object instanceof \WP_Post_Type) {
				continue;
			}
			$label = (string) $object->labels->menu_name;
			$hook  = add_submenu_page(
				Menu::PARENT,
				$label,
				$label,
				(string) $object->cap->edit_posts,
				$slug,
				static function () use ($postType): void {
					self::page($postType);
				}
			);
			if (is_string($hook) && $hook !== '') {
				// Without a menu row WordPress cannot find the page's title.
				add_action('load-' . $hook, static function () use ($label): void {
					$GLOBALS['title'] = $label;
				});
			}
		}
	}

	/**
	 * WordPress only opens a page that keeps its menu row, so the row stays
	 * and is hidden the way MenuGroups hides a group's other rows.
	 */
	public static function hideRows(): void {
		global $submenu;

		if (! isset($submenu[Menu::PARENT]) || ! is_array($submenu[Menu::PARENT])) {
			return;
		}

		foreach ($submenu[Menu::PARENT] as $i => $item) {
			if (in_array((string) ($item[2] ?? ''), self::SLUGS, true)) {
				$submenu[Menu::PARENT][$i][4] = trim((string) ($item[4] ?? '') . ' ' . MenuGroups::HIDDEN_CLASS); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- our own rows.
			}
		}
	}

	public static function redirectList(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing, no state change.
		$args     = array_map('strval', array_keys((array) $_GET));
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing, no state change.
		$postType = isset($_GET['post_type']) ? sanitize_key((string) wp_unslash($_GET['post_type'])) : '';

		if (! isset(self::SLUGS[$postType]) || array_diff($args, ['post_type']) !== []) {
			return;
		}

		wp_safe_redirect(self::url($postType, []));
		exit;
	}

	/**
	 * @param array<string, string> $args
	 */
	public static function url(string $postType, array $args): string {
		return add_query_arg(array_merge(['page' => self::SLUGS[$postType] ?? ''], $args), admin_url('admin.php'));
	}

	public static function classicUrl(string $postType): string {
		return add_query_arg(['post_type' => $postType, self::CLASSIC => '1'], admin_url('edit.php'));
	}

	private static function page(string $postType): void {
		if ($postType === 'ab_artifact') {
			DocumentsPage::render();
		} elseif ($postType === 'ab_comment') {
			CommentsPage::render();
		} else {
			self::render($postType);
		}
	}

	public static function render(string $postType): void {
		$object = get_post_type_object($postType);
		if (! $object instanceof \WP_Post_Type || ! current_user_can((string) $object->cap->edit_posts)) {
			wp_die(esc_html__('You do not have permission to view this page.', 'agent-brain'), 403);
		}

		$knowledge = $postType === 'ab_knowledge';
		$viewerId  = get_current_user_id();
		$hidden    = ScopeVisibility::hiddenPostIds($viewerId, $postType);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters, no state change.
		$view   = isset($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : 'official';
		$view   = in_array($view, self::VIEWS, true) ? $view : 'official';
		$q      = isset($_GET['q']) ? trim(sanitize_text_field(wp_unslash((string) $_GET['q']))) : '';
		$scope  = isset($_GET['scope_id']) ? absint(wp_unslash($_GET['scope_id'])) : 0;
		$picked = isset($_GET['item']) ? absint(wp_unslash($_GET['item'])) : 0;
		$paged  = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
		// phpcs:enable

		$counts = [];
		foreach (self::VIEWS as $v) {
			$counts[$v] = self::count($postType, $v, $hidden);
		}

		$status = sprintf(
			/* translators: 1: official items, 2: suggested items, 3: link to Review */
			$knowledge ? __('%1$s official facts · %2$s suggested, %3$s', 'agent-brain') : __('%1$s official · %2$s suggested, %3$s', 'agent-brain'),
			'<b>' . esc_html(number_format_i18n($counts['official'])) . '</b>',
			'<b>' . esc_html(number_format_i18n($counts['suggested'])) . '</b>',
			'<a href="' . esc_url(admin_url('admin.php?page=agent-brain-review')) . '">' . esc_html__('waiting in Review', 'agent-brain') . '</a>'
		);
		$write = current_user_can((string) $object->cap->create_posts)
			? '<a class="button" href="' . esc_url(admin_url('post-new.php?post_type=' . $postType)) . '">' . esc_html($knowledge ? __('Write a fact', 'agent-brain') : __('Write a procedure', 'agent-brain')) . '</a>'
			: '';
		$about = '<p>' . esc_html(
			$knowledge
				? __('Knowledge is the approved facts agents can read, in the scopes they have. Agents suggest facts, and they wait in Review until a curator makes them official; you can also write one yourself.', 'agent-brain')
				: __('Procedures are approved playbooks agents can follow, in the scopes they have. Agents suggest them, and they wait in Review until a curator makes them official; you can also write one yourself.', 'agent-brain')
		) . '</p><p>' . esc_html__('Pinned items go into every briefing. Reference items are dated records from an import: agents find them after current ones, and briefings leave them out.', 'agent-brain')
			. ' <a href="' . esc_url(self::classicUrl($postType)) . '">' . esc_html__('Open the WordPress list', 'agent-brain') . '</a> ' . esc_html__('for trash and bulk actions.', 'agent-brain') . '</p>';

		$head = Ui::head((string) $object->labels->menu_name, $status, $write, $about);
		echo '<div class="wrap ab-admin ab-admin-library">' . $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		self::renderFilters($postType, $view, $q, $scope, $counts);

		$query = new \WP_Query([
			'post_type'      => $postType,
			'post_status'    => self::statuses($view),
			's'              => $q,
			'tax_query'      => $scope > 0 ? [['taxonomy' => 'ab_scope', 'field' => 'term_id', 'terms' => [$scope]]] : [], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one admin list.
			'post__not_in'   => $hidden,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'no_found_rows'  => false,
		]);
		$posts = array_values(array_filter($query->posts, static fn ($p): bool => $p instanceof \WP_Post));

		if ($posts === [] && $picked === 0) {
			$filtered = $q !== '' || $scope > 0;
			$empty    = EmptyState::html(
				$filtered ? __('Nothing matches', 'agent-brain') : self::emptyTitle($postType, $view),
				$filtered ? __('Clear the search or pick another scope.', 'agent-brain') : self::emptyWhy($postType, $view),
				['label' => __('Open the Review Queue', 'agent-brain'), 'url' => admin_url('admin.php?page=agent-brain-review')]
			);
			echo $empty . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmptyState escapes.

			return;
		}

		$args     = array_filter(['view' => $view !== 'official' ? $view : '', 'q' => $q, 'scope_id' => $scope > 0 ? (string) $scope : '', 'paged' => $paged > 1 ? (string) $paged : '']);
		$selected = $picked > 0 ? $picked : (int) $posts[0]->ID;

		$rows = '';
		foreach ($posts as $post) {
			$rows .= Ui::row(
				self::url($postType, $args + ['item' => (string) $post->ID]),
				self::title($post),
				self::rowMeta($post),
				(int) $post->ID === $selected
			);
		}

		$split = Ui::split(
			(string) $object->labels->menu_name,
			$rows,
			self::detailHtml($postType, $selected, self::url($postType, $args)),
			$picked > 0,
			self::pagination($postType, $args, $paged, (int) $query->max_num_pages)
		);
		echo $split . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
	}

	/**
	 * @param array<string, int> $counts view => items
	 */
	private static function renderFilters(string $postType, string $view, string $q, int $scope, array $counts): void {
		$labels = ['official' => __('Official', 'agent-brain'), 'suggested' => __('Suggested', 'agent-brain'), 'not-needed' => __('Not needed', 'agent-brain')];
		$chips  = [];
		foreach (self::VIEWS as $v) {
			$chips[] = ['label' => $labels[$v], 'url' => self::url($postType, $v === 'official' ? [] : ['view' => $v]), 'count' => $counts[$v], 'current' => $v === $view];
		}

		echo '<form method="get" class="ab-ui-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUGS[$postType]) . '" />';
		if ($view !== 'official') {
			echo '<input type="hidden" name="view" value="' . esc_attr($view) . '" />';
		}
		echo '<label for="ab_lib_q" class="ab-ui-sr">' . esc_html__('Search', 'agent-brain') . '</label><input type="search" id="ab_lib_q" name="q" data-ab-search placeholder="' . esc_attr($postType === 'ab_knowledge' ? __('Search knowledge', 'agent-brain') : __('Search procedures', 'agent-brain')) . '" value="' . esc_attr($q) . '" /><kbd class="ab-ui-kbd">/</kbd>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::chips($chips, __('Show', 'agent-brain'));

		echo '<label for="ab_lib_scope" class="ab-ui-sr">' . esc_html__('Scope', 'agent-brain') . '</label><select id="ab_lib_scope" name="scope_id"><option value="0">' . esc_html__('Every scope', 'agent-brain') . '</option>';
		$terms = get_terms(['taxonomy' => 'ab_scope', 'hide_empty' => false]);
		foreach (is_array($terms) ? $terms : [] as $term) {
			if ($term instanceof \WP_Term && ScopeVisibility::canSeeScope(get_current_user_id(), (int) $term->term_id)) {
				echo '<option value="' . esc_attr((string) $term->term_id) . '"' . selected($scope, (int) $term->term_id, false) . '>' . esc_html($term->name) . '</option>';
			}
		}
		echo '</select><button type="submit" class="button">' . esc_html__('Search', 'agent-brain') . '</button>';
		if ($q !== '' || $scope > 0) {
			echo '<a class="button-link" href="' . esc_url(self::url($postType, $view === 'official' ? [] : ['view' => $view])) . '">' . esc_html__('Clear', 'agent-brain') . '</a>';
		}
		echo '</form>';
	}

	private static function rowMeta(\WP_Post $post): string {
		$parts = [self::statusTag($post)];
		if (Lifecycle::of((int) $post->ID) === Lifecycle::STALE) {
			$parts[] = Ui::tag(__('Reference', 'agent-brain'), 'warn');
		}
		if (Pins::isPinned((int) $post->ID)) {
			$parts[] = Ui::tag(__('Pinned', 'agent-brain'), 'type');
		}

		$scope = self::scopeNames((int) $post->ID);
		$from  = self::source($post);

		return implode(' ', $parts)
			. ($scope !== '' ? ' ' . esc_html($scope) : '')
			. ($from !== '' ? ' · ' . esc_html(sprintf(
				/* translators: %s: who suggested or wrote it */
				__('from %s', 'agent-brain'),
				$from
			)) : '')
			. ' · ' . self::ago($post->post_modified_gmt);
	}

	/**
	 * The selected item: its title and text, the facts about it (scope,
	 * who suggested it, pinning, reference, sharing, history), then Edit.
	 */
	private static function detailHtml(string $postType, int $id, string $back): string {
		$post = $id > 0 ? get_post($id) : null;
		if (! $post instanceof \WP_Post || $post->post_type !== $postType || ! current_user_can('read_post', $id) || in_array($id, ScopeVisibility::hiddenPostIds(get_current_user_id(), $postType), true)) {
			return Ui::detail('', '<p class="ab-empty">' . esc_html__('That item no longer exists, or it is in a scope you cannot see.', 'agent-brain') . '</p>', '', $back);
		}

		$knowledge = $postType === 'ab_knowledge';
		$suggested = $post->post_status === PostStatuses::PROPOSED;
		$canDecide = current_user_can(Capabilities::CAP_APPROVE_KNOWLEDGE);
		$scope     = self::scopeNames($id);

		$body  = '<h2>' . esc_html(self::title($post)) . '</h2>';
		$content = (string) $post->post_content;
		$body   .= '<div class="ab-ui-prose">' . wp_kses_post(has_blocks($content) ? do_blocks($content) : wpautop($content)) . '</div>';

		$facts = [__('Scope', 'agent-brain') => esc_html($scope !== '' ? $scope : __('No scope', 'agent-brain'))];

		$agentId = (int) get_post_meta($id, ProposalService::META_PROPOSING_AGENT, true);
		if ($agentId > 0) {
			$facts[__('Suggested by', 'agent-brain')] = esc_html(InboxItems::who($agentId)['label']);
		} else {
			$facts[__('Written by', 'agent-brain')] = esc_html((int) $post->post_author > 0 ? InboxItems::who((int) $post->post_author)['label'] : __('A curator', 'agent-brain'));
		}
		$facts[__('Last changed', 'agent-brain')] = DateFormat::html($post->post_modified_gmt);

		if (! $suggested) {
			$pinned = Pins::isPinned($id);
			$facts[__('Briefings', 'agent-brain')] = esc_html($pinned ? __('Pinned: every briefing includes it.', 'agent-brain') : __('Not pinned', 'agent-brain'))
				. ($canDecide ? ' <a href="' . esc_url(wp_nonce_url(add_query_arg(['action' => Pins::ACTION, 'post_id' => $id], admin_url('admin-post.php')), Pins::ACTION . $id)) . '">' . esc_html($pinned ? __('Unpin', 'agent-brain') : __('Pin to briefings', 'agent-brain')) . '</a>' : '');
		}

		$lifecycle = Lifecycle::of($id);
		if ($lifecycle !== null) {
			$facts[__('Lifecycle', 'agent-brain')] = esc_html($lifecycle === Lifecycle::STALE
				/* translators: %s: date */
				? sprintf(__('Reference, as of %s', 'agent-brain'), mysql2date((string) get_option('date_format'), $post->post_modified_gmt))
				: __('Archived', 'agent-brain'))
				. ($canDecide && current_user_can('edit_post', $id) ? ' <a href="' . esc_url(wp_nonce_url(add_query_arg(['action' => Lifecycle::ACTION, 'post_id' => $id], admin_url('admin-post.php')), Lifecycle::ACTION . $id)) . '">' . esc_html__('Mark as current', 'agent-brain') . '</a>' : '');
		}

		$grants = wp_get_post_terms($id, GrantTaxonomy::TAXONOMY, ['fields' => 'names']);
		$facts[__('Shared with', 'agent-brain')] = esc_html(is_array($grants) && $grants !== [] ? implode(', ', $grants) : __('Nobody outside its scope', 'agent-brain'));

		$versions = count(wp_get_post_revisions($id));
		$facts[__('History', 'agent-brain')] = esc_html(sprintf(
			/* translators: %d: number of earlier versions */
			_n('%d earlier version', '%d earlier versions', $versions, 'agent-brain'),
			$versions
		));
		if (current_user_can(ProvenancePanel::CAPABILITY)) {
			$facts[__('Where it came from', 'agent-brain')] = '<a href="' . esc_url(ProvenancePanel::url('knowledge', $id)) . '">' . esc_html__('Trace it in Provenance', 'agent-brain') . '</a>';
		}
		$body .= Ui::facts($facts);

		$actions = '';
		if ($suggested) {
			$actions .= '<a class="button button-primary" href="' . esc_url(add_query_arg(['page' => 'agent-brain-review', 'item' => 'k-' . $id], admin_url('admin.php'))) . '">' . esc_html__('Decide in Review', 'agent-brain') . '</a>';
		} elseif (current_user_can('edit_post', $id)) {
			$actions .= '<a class="button button-primary" href="' . esc_url((string) get_edit_post_link($id, 'raw')) . '">' . esc_html__('Edit', 'agent-brain') . '</a>';
		}
		if (current_user_can('delete_post', $id)) {
			$actions .= '<span class="ab-ui-spacer"></span><a class="button-link button-link-delete" href="' . esc_url((string) get_delete_post_link($id)) . '">' . esc_html__('Move to Trash', 'agent-brain') . '</a>';
		}

		$kind = $suggested
			? ($knowledge ? __('Suggested fact', 'agent-brain') : __('Suggested procedure', 'agent-brain'))
			: ($post->post_status === PostStatuses::REJECTED
				? ($knowledge ? __('Fact not needed', 'agent-brain') : __('Procedure not needed', 'agent-brain'))
				: ($knowledge ? __('Official fact', 'agent-brain') : __('Official procedure', 'agent-brain')));

		return Ui::detail($scope !== '' ? $kind . ' · ' . $scope : $kind, $body, $actions, $back);
	}

	/**
	 * @return string[]
	 */
	private static function statuses(string $view): array {
		switch ($view) {
			case 'suggested':
				return [PostStatuses::PROPOSED];
			case 'not-needed':
				return [PostStatuses::REJECTED];
		}

		return ['private', 'publish'];
	}

	/**
	 * @param int[] $hidden
	 */
	private static function count(string $postType, string $view, array $hidden): int {
		$query = new \WP_Query([
			'post_type'      => $postType,
			'post_status'    => self::statuses($view),
			'post__not_in'   => $hidden,
			'fields'         => 'ids',
			'posts_per_page' => 1,
		]);

		return (int) $query->found_posts;
	}

	private static function statusTag(\WP_Post $post): string {
		if ($post->post_status === PostStatuses::PROPOSED) {
			return Ui::tag(__('Suggested', 'agent-brain'), 'new');
		}
		if ($post->post_status === PostStatuses::REJECTED) {
			return Ui::tag(__('Not needed', 'agent-brain'), 'bad');
		}

		return Ui::tag(__('Official', 'agent-brain'), 'ok');
	}

	/**
	 * The title as written: get_the_title() would add "Private:", and every
	 * item here is private.
	 */
	public static function title(\WP_Post $post): string {
		$title = trim((string) $post->post_title);

		return $title !== '' ? $title : __('(no title)', 'agent-brain');
	}

	private static function scopeNames(int $postId): string {
		$terms = wp_get_post_terms($postId, 'ab_scope', ['fields' => 'names']);

		return is_array($terms) ? implode(', ', array_map('strval', $terms)) : '';
	}

	private static function source(\WP_Post $post): string {
		$agentId = (int) get_post_meta((int) $post->ID, ProposalService::META_PROPOSING_AGENT, true);
		$authorId = $agentId > 0 ? $agentId : (int) $post->post_author;

		return $authorId > 0 ? InboxItems::who($authorId)['name'] : '';
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

	/**
	 * @param array<string, string> $args
	 */
	private static function pagination(string $postType, array $args, int $paged, int $pages): string {
		if ($pages <= 1) {
			return '';
		}
		unset($args['paged']);

		return '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
			'base'      => self::url($postType, $args + ['paged' => '%#%']),
			'format'    => '',
			'current'   => $paged,
			'total'     => $pages,
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
		]) . '</div></div>';
	}

	private static function emptyTitle(string $postType, string $view): string {
		if ($view === 'suggested') {
			return __('Nothing suggested', 'agent-brain');
		}
		if ($view === 'not-needed') {
			return __('Nothing turned down', 'agent-brain');
		}

		return $postType === 'ab_knowledge' ? __('No knowledge yet', 'agent-brain') : __('No procedures yet', 'agent-brain');
	}

	private static function emptyWhy(string $postType, string $view): string {
		if ($view !== 'official') {
			return __('Agents suggest items through their tools; each one waits in the Review Queue until a curator decides.', 'agent-brain');
		}

		return $postType === 'ab_knowledge'
			? __('Knowledge fills as you approve proposals from agents, or add items yourself. Nothing has been approved yet.', 'agent-brain')
			: __('Procedures fill as you approve proposals from agents, or add playbooks yourself. Nothing has been approved yet.', 'agent-brain');
	}
}
