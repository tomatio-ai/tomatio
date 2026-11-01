<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Review\GrantTaxonomy;
use AgentBrain\Modules\Review\HandoffQueue;
use AgentBrain\Modules\Review\ProposalService;

/**
 * One list-screen pattern for the TomaTío post types (issues #77, #78,
 * #79, #80, #81): brain-specific columns, no meaningless native row actions,
 * a deliberate create-path explanation per type, an empty state, the Grants
 * menu highlight and a confirm step before a grant is deleted.
 *
 * Create-path rule (documented once, here):
 *  - Artifacts: created by curators only, through ArtifactService (the post
 *    type pins `create_posts` to do_not_allow, so no native Add New). The
 *    "Create artifact" action next to the H1 (Artifacts\AdminUi) or the
 *    artifact-create ability are the paths; agents ask a curator or are set
 *    as lead. A direct visit to post-new.php is redirected to the list.
 *  - Handoffs: created by agents (handoff.request_review). Add New is only
 *    for a note to the curators.
 *  - Knowledge / Procedures: normally proposed by agents and approved in the
 *    Review Queue; Add New writes a curator-authored item directly.
 */
final class ListScreens {

	public const POST_TYPES = ['ab_handoff', 'ab_artifact', 'ab_knowledge', 'ab_procedure'];

	public const COL_FROM    = 'ab_from';
	public const COL_TO      = 'ab_to';
	public const COL_STATUS  = 'ab_status';
	public const COL_SCOPE   = 'ab_scope_col';
	public const COL_SOURCE  = 'ab_source';
	public const COL_UPDATED = 'ab_updated';

	public static function register(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		foreach (self::POST_TYPES as $postType) {
			add_filter('views_edit-' . $postType, [self::class, 'renderIntro']);
		}

		DetailsBox::register();

		add_filter('manage_ab_handoff_posts_columns', [self::class, 'handoffColumns'], 20);
		add_action('manage_ab_handoff_posts_custom_column', [self::class, 'renderHandoffColumn'], 10, 2);

		foreach (['ab_knowledge', 'ab_procedure'] as $postType) {
			add_filter('manage_' . $postType . '_posts_columns', [self::class, 'libraryColumns'], 20);
			add_action('manage_' . $postType . '_posts_custom_column', [self::class, 'renderLibraryColumn'], 10, 2);
			add_filter('manage_edit-' . $postType . '_sortable_columns', [self::class, 'sortableColumns']);
		}

		add_filter('post_row_actions', [self::class, 'rowActions'], 10, 2);
		add_filter('page_row_actions', [self::class, 'rowActions'], 10, 2);
		add_filter('bulk_actions-edit-ab_handoff', [self::class, 'bulkActions']);

		add_action('load-post-new.php', [self::class, 'explainArtifactCreate']);

		add_filter('parent_file', [self::class, 'grantsParentFile']);
		add_filter('submenu_file', [self::class, 'grantsSubmenuFile']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueGrantConfirm']);
	}

	// -- #77 columns ---------------------------------------------------------

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function handoffColumns(array $columns): array {
		return [
			'cb'            => $columns['cb'] ?? '<input type="checkbox" />',
			'title'         => __('Title', 'agent-brain'),
			self::COL_FROM  => __('From', 'agent-brain'),
			self::COL_TO    => __('To', 'agent-brain'),
			self::COL_STATUS => __('Status', 'agent-brain'),
			'date'          => __('Date', 'agent-brain'),
		];
	}

	public static function renderHandoffColumn(string $column, int $postId): void {
		if ($column === self::COL_FROM) {
			$post = get_post($postId);
			echo esc_html($post instanceof \WP_Post ? self::userName((int) $post->post_author) : '');
		} elseif ($column === self::COL_TO) {
			echo esc_html(self::handoffTo($postId));
		} elseif ($column === self::COL_STATUS) {
			echo esc_html(self::handoffStatusLabel($postId));
		}
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function libraryColumns(array $columns): array {
		$out = [
			'cb'              => $columns['cb'] ?? '<input type="checkbox" />',
			'title'           => __('Title', 'agent-brain'),
			self::COL_SCOPE   => __('Scope', 'agent-brain'),
			self::COL_STATUS  => __('Status', 'agent-brain'),
			self::COL_SOURCE  => __('Source', 'agent-brain'),
			self::COL_UPDATED => __('Updated', 'agent-brain'),
		];

		// Keep the Grants column the grant taxonomy adds (show_admin_column).
		if (isset($columns['taxonomy-' . GrantTaxonomy::TAXONOMY])) {
			$out['taxonomy-' . GrantTaxonomy::TAXONOMY] = $columns['taxonomy-' . GrantTaxonomy::TAXONOMY];
		}

		return $out;
	}

	public static function renderLibraryColumn(string $column, int $postId): void {
		$post = get_post($postId);
		if (! $post instanceof \WP_Post) {
			return;
		}

		if ($column === self::COL_SCOPE) {
			$terms = wp_get_post_terms($postId, 'ab_scope', ['fields' => 'names']);
			echo esc_html(is_array($terms) ? implode(', ', $terms) : '');
		} elseif ($column === self::COL_STATUS) {
			$status = get_post_status_object($post->post_status);
			echo esc_html($status instanceof \stdClass ? (string) $status->label : $post->post_status);
		} elseif ($column === self::COL_SOURCE) {
			$agentId = (int) get_post_meta($postId, ProposalService::META_PROPOSING_AGENT, true);
			$name    = self::userName($agentId > 0 ? $agentId : (int) $post->post_author);
			echo esc_html($name !== '' ? $name : __('Curator', 'agent-brain'));
		} elseif ($column === self::COL_UPDATED) {
			$timestamp = strtotime($post->post_modified_gmt . ' UTC');
			if ($timestamp === false) {
				return;
			}
			echo '<span title="' . esc_attr($post->post_modified_gmt . ' UTC') . '">' . esc_html(sprintf(
				/* translators: %s: human-readable time difference, e.g. "5 minutes" */
				__('%s ago', 'agent-brain'),
				human_time_diff($timestamp, time())
			)) . '</span>';
		}
	}

	/**
	 * @param array<string, mixed> $columns
	 * @return array<string, mixed>
	 */
	public static function sortableColumns(array $columns): array {
		$columns[self::COL_UPDATED] = ['modified', true];

		return $columns;
	}

	/**
	 * Quick Edit means nothing on TomaTío rows (the fields it edits are
	 * not what a curator reviews), and a handoff that has been approved or
	 * dismissed is locked: no Trash either.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function rowActions(array $actions, \WP_Post|null $post = null): array {
		if (! $post instanceof \WP_Post || ! in_array($post->post_type, ['ab_handoff', 'ab_artifact'], true)) {
			return $actions;
		}

		unset($actions['inline hide-if-no-js']);

		if ($post->post_type === 'ab_handoff' && HandoffQueue::status($post->ID) !== null) {
			unset($actions['trash']);
		}

		return $actions;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function bulkActions(array $actions): array {
		unset($actions['edit']);

		return $actions;
	}

	/** Recipient label; an unset recipient means any curator can take it (matches the Review Queue). */
	public static function handoffTo(int $postId): string {
		$name = self::userName((int) get_post_meta($postId, HandoffQueue::META_TO, true));

		return $name !== '' ? $name : __('Any curator', 'agent-brain');
	}

	public static function handoffFrom(int $postId): string {
		$post = get_post($postId);

		return $post instanceof \WP_Post ? self::userName((int) $post->post_author) : '';
	}

	public static function handoffStatusLabel(int $postId): string {
		$status = HandoffQueue::status($postId);

		if ($status === HandoffQueue::STATUS_APPROVED) {
			return __('Approved', 'agent-brain');
		}

		if ($status === HandoffQueue::STATUS_DISMISSED) {
			return __('Dismissed', 'agent-brain');
		}

		return __('Open', 'agent-brain');
	}

	private static function userName(int $userId): string {
		if ($userId <= 0) {
			return '';
		}

		$user = get_userdata($userId);

		return $user instanceof \WP_User ? $user->display_name : (string) $userId;
	}

	// -- #79 / #80 intro + empty state --------------------------------------

	/**
	 * Printed from the `views_edit-{type}` filter (the one hook that runs
	 * inside the list screen, under the heading): the create-path
	 * explanation and, when the type has no items at all, the empty state.
	 *
	 * @param array<string, string> $views
	 * @return array<string, string>
	 */
	public static function renderIntro(array $views): array {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (! $screen instanceof \WP_Screen || ! in_array($screen->post_type, self::POST_TYPES, true)) {
			return $views;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- introHtml() escapes every value it emits.
		echo self::introHtml($screen->post_type, self::hasAnyItems($screen->post_type));

		return $views;
	}

	public static function introHtml(string $postType, bool $hasItems): string {
		$copy = self::copy($postType);
		if ($copy === null) {
			return '';
		}

		$html = '<p class="ab-list-hint">' . esc_html($copy['hint']) . '</p>';

		if (! $hasItems) {
			// The empty state replaces the list table's own "No items found"
			// row, so it is shown once (#80).
			$html .= '<style>.post-type-' . esc_attr($postType) . ' .wp-list-table tr.no-items{display:none}</style>';
			$html .= EmptyState::html($copy['what'], $copy['why'], $copy['action']);
		}

		return $html;
	}

	/**
	 * @return array{hint: string, what: string, why: string, action: array{label: string, url: string}}|null
	 */
	private static function copy(string $postType): ?array {
		$connect = ['label' => __('Connect an AI', 'agent-brain'), 'url' => admin_url('admin.php?page=' . ConnectPage::SLUG)];
		$review  = ['label' => __('Open the Review Queue', 'agent-brain'), 'url' => admin_url('admin.php?page=agent-brain-review')];

		switch ($postType) {
			case 'ab_handoff':
				return [
					'hint'   => __('Handoffs are created by agents. Use Add New only to leave a note for the curators.', 'agent-brain'),
					'what'   => __('No handoffs yet', 'agent-brain'),
					'why'    => __('A handoff appears when an agent asks a curator to share memory with another agent. None has asked yet.', 'agent-brain'),
					'action' => $connect,
				];
			case 'ab_artifact':
				return [
					'hint'   => __('Curators create artifacts here (Create artifact) or through the artifact-create ability. Agents ask a curator to create one, or are set as its lead. Open one to follow its sections and stage.', 'agent-brain'),
					'what'   => __('No artifacts yet', 'agent-brain'),
					'why'    => __('Artifacts are shared documents that agents draft together. A curator starts one and sets an agent as its lead; none has been created yet.', 'agent-brain'),
					'action' => $connect,
				];
			case 'ab_knowledge':
				return [
					'hint'   => __('Knowledge is the approved facts agents can read. Agents propose items for the Review Queue; Add New writes one yourself.', 'agent-brain'),
					'what'   => __('No knowledge yet', 'agent-brain'),
					'why'    => __('Knowledge fills as you approve proposals from agents, or add items yourself. Nothing has been approved yet.', 'agent-brain'),
					'action' => $review,
				];
			case 'ab_procedure':
				return [
					'hint'   => __('Procedures are approved playbooks agents can follow. Agents propose them for the Review Queue; Add New writes one yourself.', 'agent-brain'),
					'what'   => __('No procedures yet', 'agent-brain'),
					'why'    => __('Procedures fill as you approve proposals from agents, or add playbooks yourself. Nothing has been approved yet.', 'agent-brain'),
					'action' => $review,
				];
		}

		return null;
	}

	private static function hasAnyItems(string $postType): bool {
		$counts = (array) wp_count_posts($postType);
		unset($counts['trash'], $counts['auto-draft']);

		return array_sum(array_map('intval', $counts)) > 0;
	}

	/**
	 * Direct visits to Add New for artifacts used to end in a bare 403
	 * (creation is agent-only). Send the curator to the list, with the reason.
	 */
	public static function explainArtifactCreate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing, no state change.
		$postType = isset($_GET['post_type']) ? sanitize_key((string) wp_unslash($_GET['post_type'])) : '';
		if ($postType !== 'ab_artifact') {
			return;
		}

		Notices::flash('info', __('Start a document with New document on this page.', 'agent-brain'));
		wp_safe_redirect(admin_url('edit.php?post_type=ab_artifact'));
		exit;
	}

	// -- #78 Grants menu highlight ------------------------------------------

	public static function grantsParentFile(string $parentFile): string {
		return self::onGrantsScreen() ? Menu::PARENT : $parentFile;
	}

	public static function grantsSubmenuFile(?string $submenuFile): ?string {
		return self::onGrantsScreen() ? Menu::GRANTS_SLUG : $submenuFile;
	}

	private static function onGrantsScreen(): bool {
		if (! function_exists('get_current_screen')) {
			return false;
		}

		$screen = get_current_screen();

		return $screen instanceof \WP_Screen && $screen->taxonomy === GrantTaxonomy::TAXONOMY;
	}

	// -- #81 confirm before deleting a grant --------------------------------

	public static function enqueueGrantConfirm(): void {
		if (! self::onGrantsScreen() || ! function_exists('wp_register_script')) {
			return;
		}

		wp_register_script('agent-brain-grant-confirm', '', [], defined('AGENT_BRAIN_VERSION') ? AGENT_BRAIN_VERSION : false, true);
		wp_enqueue_script('agent-brain-grant-confirm');
		wp_add_inline_script(
			'agent-brain-grant-confirm',
			'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest(".row-actions .delete a, a.delete-tag");'
			. 'if(a&&!window.confirm(' . wp_json_encode(__('Delete this grant? Items that rely on it can no longer be published.', 'agent-brain')) . ')){e.preventDefault();e.stopImmediatePropagation();}},true);'
		);
	}
}
