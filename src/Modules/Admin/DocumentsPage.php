<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Artifacts\AdminUi as ArtifactAdminUi;
use AgentBrain\Modules\Artifacts\ArtifactService;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Artifacts\ObjectAccess;
use AgentBrain\Modules\Artifacts\Stages;
use AgentBrain\Modules\Comments\Cpt as CommentCpt;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Review\InboxItems;
use AgentBrain\Modules\Sharing\ShareLinks;

/**
 * #751: artifacts, shown to people as Documents, as cards. Each card says
 * where the document stands (one bar per section, or the live version of
 * an HTML page) and what waits for the viewer, and opens the document's
 * own screen. LibraryPage routes edit.php?post_type=ab_artifact here; the
 * WordPress list stays one link away under About this page.
 */
final class DocumentsPage {

	public const SLUG = 'agent-brain-documents';

	private const VIEWS = ['active', 'live', 'archived'];

	/** Cards on one page; documents are few, and the rest stay in the WordPress list. */
	private const LIMIT = 200;

	/**
	 * @param array<string, string> $args
	 */
	public static function url(array $args = []): string {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	public static function render(): void {
		$object = get_post_type_object(Cpts::ARTIFACT_POST_TYPE);
		if (! $object instanceof \WP_Post_Type || ! current_user_can((string) $object->cap->edit_posts)) {
			wp_die(esc_html__('You do not have permission to view this page.', 'agent-brain'), 403);
		}

		$viewerId = get_current_user_id();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters, no state change.
		$view  = isset($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : 'active';
		$view  = in_array($view, self::VIEWS, true) ? $view : 'active';
		$scope = isset($_GET['scope_id']) ? absint(wp_unslash($_GET['scope_id'])) : 0;
		$error = isset($_GET['ab_error']) ? sanitize_text_field(wp_unslash((string) $_GET['ab_error'])) : '';
		// phpcs:enable

		$cards = [];
		foreach (self::documents($viewerId) as $post) {
			$cards[] = self::card($post, $viewerId);
		}

		$counts = ['active' => 0, 'live' => 0, 'archived' => 0];
		$waiting = 0;
		foreach ($cards as $card) {
			if ($card['archived']) {
				++$counts['archived'];
				continue;
			}
			++$counts['active'];
			$counts['live'] += $card['live'] ? 1 : 0;
			$waiting        += $card['attention'] ? 1 : 0;
		}

		$status = sprintf(
			/* translators: 1: documents waiting for the viewer, 2: active documents */
			__('%1$s waiting for you · %2$s in all', 'agent-brain'),
			'<b>' . esc_html(number_format_i18n($waiting)) . '</b>',
			'<b>' . esc_html(number_format_i18n($counts['active'])) . '</b>'
		);
		$canCreate = current_user_can(Capabilities::CAP_MANAGE_ARTIFACTS);
		$about     = '<p>' . esc_html__('Documents are shared pages agents write together. A curator starts one and sets an agent as its lead; the lead plans the sections, agents draft them, and a curator approves each one. An HTML page is a document an agent uploads whole, and its approved version is what share links show.', 'agent-brain') . '</p>'
			. '<p>' . esc_html__('Each card has one bar per section: approved, waiting for review, changes asked, or still a draft. Agents call documents "artifacts" in their tools.', 'agent-brain')
			. ' <a href="' . esc_url(LibraryPage::classicUrl(Cpts::ARTIFACT_POST_TYPE)) . '">' . esc_html__('Open the WordPress list', 'agent-brain') . '</a> ' . esc_html__('for trash and bulk actions.', 'agent-brain') . '</p>';

		$head = Ui::head(__('Documents', 'agent-brain'), $status, $canCreate ? '<a class="button button-primary" href="#ab-doc-new">' . esc_html__('New document', 'agent-brain') . '</a>' : '', $about);
		echo '<div class="wrap ab-admin ab-admin-documents">' . $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		if ($error !== '') {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
			echo Ui::callout('bad', __('The document was not created.', 'agent-brain'), esc_html($error));
		}
		if ($canCreate) {
			echo self::newForm($error !== ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}

		self::renderFilters($view, $scope, $counts);

		$shown = array_values(array_filter($cards, static function (array $card) use ($view, $scope): bool {
			if ($scope > 0 && $card['scope_id'] !== $scope) {
				return false;
			}
			if ($view === 'archived') {
				return $card['archived'];
			}

			return ! $card['archived'] && ($view !== 'live' || $card['live']);
		}));

		if ($shown === []) {
			$empty = EmptyState::html(
				$scope > 0 || $view !== 'active' ? __('No documents here', 'agent-brain') : __('No documents yet', 'agent-brain'),
				$scope > 0 || $view !== 'active'
					? __('Pick another scope, or show the active documents.', 'agent-brain')
					: __('A curator starts a document and sets an agent as its lead, or an agent starts one when the brain allows it. None has been started yet.', 'agent-brain'),
				$canCreate && $view === 'active' && $scope === 0 ? ['label' => __('New document', 'agent-brain'), 'url' => '#ab-doc-new'] : null
			);
			echo $empty . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmptyState escapes.

			return;
		}

		// Waiting for the viewer first, then the most recently changed.
		usort($shown, static fn (array $a, array $b): int => [$b['attention'], $b['modified']] <=> [$a['attention'], $a['modified']]);

		$out = '<ul class="ab-ui-cards" aria-label="' . esc_attr__('Documents', 'agent-brain') . '">';
		foreach ($shown as $card) {
			$out .= $card['html'];
		}
		echo $out . '</ul></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card() escapes what it emits.
	}

	/**
	 * Documents the viewer may open: not trashed, in the scopes they see.
	 *
	 * @return \WP_Post[]
	 */
	private static function documents(int $viewerId): array {
		$posts = get_posts([
			'post_type'      => Cpts::ARTIFACT_POST_TYPE,
			'post_status'    => ['private', 'publish', 'draft', 'pending', 'future'],
			'post__not_in'   => ScopeVisibility::hiddenPostIds($viewerId, Cpts::ARTIFACT_POST_TYPE),
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'posts_per_page' => self::LIMIT,
			'no_found_rows'  => true,
		]);

		return array_values(array_filter($posts, static fn ($p): bool => $p instanceof \WP_Post && current_user_can('read_post', (int) $p->ID)));
	}

	/**
	 * One card and the facts the page counts and sorts by.
	 *
	 * @return array{html: string, archived: bool, live: bool, attention: bool, scope_id: int, modified: string}
	 */
	private static function card(\WP_Post $post, int $viewerId): array {
		$id       = (int) $post->ID;
		$archived = (string) get_post_meta($id, ArtifactService::META_ARCHIVED_AT, true) !== '';
		$scopeId  = (int) get_post_meta($id, Cpts::META_SCOPE_ID, true);
		$scope    = $scopeId > 0 ? get_term($scopeId) : null;
		$leadId   = (int) get_post_meta($id, Cpts::META_LEAD_AGENT, true);
		$edit     = (string) get_edit_post_link($id, 'raw');
		$comments = self::openComments($id);

		$kicker = implode(' · ', array_filter([
			$scope instanceof \WP_Term ? $scope->name : '',
			/* translators: %s: the lead agent's name */
			$leadId > 0 ? sprintf(__('lead: %s', 'agent-brain'), InboxItems::who($leadId)['name']) : '',
		]));

		$summary = [];
		$attention = false;
		$live      = $post->post_status === 'publish';

		if (HtmlFiles::isHtml($id)) {
			$latest   = HtmlFiles::latestNumber($id);
			$served   = HtmlFiles::servedNumber($id);
			$live     = $live || $served > 0;
			$bars     = '<span class="ab-ui-bars" role="img" aria-label="' . esc_attr($served > 0 ? __('Live', 'agent-brain') : __('Not live yet', 'agent-brain')) . '"><span class="ab-ui-bar ab-ui-bar--' . ($served > 0 ? 'live' : 'draft') . '"></span></span>';
			$summary[] = $served > 0
				/* translators: %d: version number */
				? sprintf(__('HTML page · version %d live', 'agent-brain'), $served)
				: __('HTML page · not live yet', 'agent-brain');
			if ($latest > $served && HtmlFiles::mode($id) === HtmlFiles::MODE_APPROVED && HtmlFiles::canEdit($viewerId, $id)) {
				$attention = true;
				/* translators: %d: version number */
				$summary[] = sprintf(__('version %d waits for your approval', 'agent-brain'), $latest);
			}
			$links = ShareLinks::canManage($viewerId, $id) ? ShareLinks::listForArtifact($id, $viewerId) : [];
			$active = is_array($links) ? count(array_filter($links, static fn (array $l): bool => $l['status'] === 'active')) : 0;
			if ($active > 0) {
				/* translators: %d: number of share links */
				$summary[] = sprintf(_n('%d share link', '%d share links', $active, 'agent-brain'), $active);
			}
			$primary = $attention ? __('Approve the new version', 'agent-brain') : '';
		} else {
			$states   = self::sectionStates($id);
			// Who may approve a section: ArtifactService::reviewSection()'s rule.
			$canReview = ObjectAccess::userIsCurator($viewerId) || ObjectAccess::userIsLead($viewerId, $id) || ObjectAccess::userCanReviewAndAssemble($viewerId, $id);
			$tally    = array_count_values(array_column($states, 1));
			$labels   = self::stateLabels();
			$bars     = '<span class="ab-ui-bars" role="img" aria-label="' . esc_attr(self::barsLabel($tally)) . '">';
			foreach ($states as [$name, $state]) {
				$bars .= '<span class="ab-ui-bar ab-ui-bar--' . esc_attr(str_replace('_', '-', $state)) . '" title="' . esc_attr($name . ': ' . $labels[$state]) . '"></span>';
			}
			$bars .= $states === [] ? '<span class="ab-ui-bar ab-ui-bar--none"></span>' : '';
			$bars .= '</span>';

			$submitted = (int) ($tally[Cpts::SECTION_STATE_SUBMITTED] ?? 0);
			if ($states === []) {
				$summary[] = __('Outline: no sections yet', 'agent-brain');
			} elseif ($submitted > 0 && $canReview) {
				$attention = true;
				/* translators: %d: number of sections */
				$summary[] = sprintf(_n('%d section waits for you', '%d sections wait for you', $submitted, 'agent-brain'), $submitted);
			} elseif ($submitted > 0) {
				/* translators: %d: number of sections */
				$summary[] = sprintf(_n('%d section waits for review', '%d sections wait for review', $submitted, 'agent-brain'), $submitted);
			}
			$drafts = (int) ($tally[Cpts::SECTION_STATE_DRAFTING] ?? 0) + (int) ($tally[Cpts::SECTION_STATE_CHANGES_REQUESTED] ?? 0);
			if ($drafts > 0) {
				/* translators: %d: number of sections */
				$summary[] = sprintf(_n('%d draft', '%d drafts', $drafts, 'agent-brain'), $drafts);
			}
			if ($states !== [] && $submitted === 0 && $drafts === 0) {
				$summary[] = $live ? __('Published', 'agent-brain') : __('Every section approved', 'agent-brain');
			}
			$primary = $attention ? __('Review sections', 'agent-brain') : '';
		}

		$sum = esc_html(implode(' · ', $summary));
		if ($comments > 0) {
			$sum .= ' · <a href="' . esc_url(CommentsPage::url(['document' => (string) $id])) . '">' . esc_html(sprintf(
				/* translators: %d: number of open comments */
				_n('%d comment open', '%d comments open', $comments, 'agent-brain'),
				$comments
			)) . '</a>';
		}

		$title   = LibraryPage::title($post);
		$actions = $primary !== '' && $edit !== '' ? '<a class="button button-primary button-small" href="' . esc_url($edit) . '">' . esc_html($primary) . '</a>' : '';
		$actions .= $edit !== '' ? '<a class="button button-small" href="' . esc_url($edit) . '" aria-label="' . esc_attr(sprintf(
			/* translators: %s: document title */
			__('Open %s', 'agent-brain'),
			$title
		)) . '">' . esc_html__('Open', 'agent-brain') . '</a>' : '';

		$html = '<li class="ab-ui-doc' . ($attention ? ' ab-ui-doc--attn' : '') . '">'
			. ($kicker !== '' ? '<p class="ab-ui-kicker">' . esc_html($kicker) . '</p>' : '')
			. '<h2 class="ab-ui-doc-title">' . esc_html($title) . ($archived ? ' ' . Ui::tag(__('Archived', 'agent-brain')) : '') . '</h2>'
			. $bars
			. '<p class="ab-ui-doc-sum">' . $sum . '</p>'
			. ($actions !== '' ? '<div class="ab-ui-actions-row">' . $actions . '</div>' : '')
			. '</li>';

		return ['html' => $html, 'archived' => $archived, 'live' => $live, 'attention' => $attention, 'scope_id' => $scopeId, 'modified' => (string) $post->post_modified_gmt];
	}

	/**
	 * Each section's title and state, in outline order (sections outside
	 * the outline follow).
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function sectionStates(int $artifactId): array {
		$ids      = (new ArtifactService())->outlineSectionIds($artifactId);
		$children = get_posts([
			'post_type'      => Cpts::SECTION_POST_TYPE,
			'post_parent'    => $artifactId,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);
		$ids = array_values(array_unique(array_merge($ids, array_map('intval', $children))));

		$out = [];
		foreach ($ids as $i => $sectionId) {
			$section = get_post($sectionId);
			if (! $section instanceof \WP_Post || (int) $section->post_parent !== $artifactId) {
				continue;
			}
			$out[] = [
				$section->post_title !== '' ? $section->post_title : sprintf(
					/* translators: %d: section number */
					__('Section %d', 'agent-brain'),
					$i + 1
				),
				Stages::sectionState($sectionId),
			];
		}

		return $out;
	}

	/** @return array<string, string> */
	private static function stateLabels(): array {
		return [
			Cpts::SECTION_STATE_DRAFTING          => __('draft', 'agent-brain'),
			Cpts::SECTION_STATE_SUBMITTED         => __('waiting for review', 'agent-brain'),
			Cpts::SECTION_STATE_APPROVED          => __('approved', 'agent-brain'),
			Cpts::SECTION_STATE_CHANGES_REQUESTED => __('changes asked', 'agent-brain'),
		];
	}

	/**
	 * @param array<string, int> $tally state => sections
	 */
	private static function barsLabel(array $tally): string {
		$total = array_sum($tally);
		if ($total === 0) {
			return __('No sections yet', 'agent-brain');
		}

		$parts = [];
		foreach (self::stateLabels() as $state => $label) {
			if (! empty($tally[$state])) {
				$parts[] = $tally[$state] . ' ' . $label;
			}
		}

		/* translators: 1: number of sections, 2: list such as "2 approved, 1 draft" */
		return sprintf(_n('%1$d section: %2$s', '%1$d sections: %2$s', $total, 'agent-brain'), $total, implode(', ', $parts));
	}

	private static function openComments(int $artifactId): int {
		$query = new \WP_Query([
			'post_type'      => CommentCpt::POST_TYPE,
			'post_status'    => 'private',
			'post_parent'    => 0,
			'fields'         => 'ids',
			'posts_per_page' => 1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one admin page.
			'meta_query'     => [
				['key' => CommentCpt::META_ARTIFACT, 'value' => (string) $artifactId, 'type' => 'NUMERIC'],
				['key' => CommentCpt::META_RESOLVED, 'compare' => 'NOT EXISTS'],
			],
		]);

		return (int) $query->found_posts;
	}

	/**
	 * @param array<string, int> $counts view => documents
	 */
	private static function renderFilters(string $view, int $scope, array $counts): void {
		$labels = ['active' => __('Active', 'agent-brain'), 'live' => __('Live', 'agent-brain'), 'archived' => __('Archived', 'agent-brain')];
		$chips  = [];
		foreach (self::VIEWS as $v) {
			$chips[] = ['label' => $labels[$v], 'url' => self::url(array_filter(['view' => $v === 'active' ? '' : $v, 'scope_id' => $scope > 0 ? (string) $scope : ''])), 'count' => $counts[$v], 'current' => $v === $view];
		}

		echo '<form method="get" class="ab-ui-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />';
		if ($view !== 'active') {
			echo '<input type="hidden" name="view" value="' . esc_attr($view) . '" />';
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::chips($chips, __('Show', 'agent-brain'));
		echo '<label for="ab_doc_scope" class="ab-ui-sr">' . esc_html__('Scope', 'agent-brain') . '</label><select id="ab_doc_scope" name="scope_id"><option value="0">' . esc_html__('Every scope', 'agent-brain') . '</option>';
		foreach (ArtifactAdminUi::scopeChoices() as $id => $name) {
			echo '<option value="' . esc_attr((string) $id) . '"' . selected($scope, $id, false) . '>' . esc_html($name) . '</option>';
		}
		echo '</select><button type="submit" class="button">' . esc_html__('Show', 'agent-brain') . '</button>';
		echo '</form>';
	}

	/**
	 * Start a document: a title, its lead and its scope. The form posts to
	 * Artifacts\AdminUi::handleCreate, which opens the new document or
	 * comes back here with the reason it was refused.
	 */
	private static function newForm(bool $open): string {
		$out  = '<details id="ab-doc-new" class="ab-ui-card ab-ui-new"' . ($open ? ' open' : '') . '><summary>' . esc_html__('New document', 'agent-brain') . '</summary>';
		$out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		$out .= '<input type="hidden" name="action" value="' . esc_attr(ArtifactAdminUi::ACTION_CREATE) . '" />';
		$out .= '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(ArtifactAdminUi::NONCE_CREATE)) . '" />';
		$out .= '<p><label for="ab_doc_title">' . esc_html__('Title', 'agent-brain') . '</label><br /><input type="text" id="ab_doc_title" name="title" class="regular-text" required /></p>';
		$out .= '<p><label for="ab_doc_lead">' . esc_html__('Lead', 'agent-brain') . '</label><br /><select id="ab_doc_lead" name="lead_agent_id" required>';
		foreach (ArtifactAdminUi::leadChoices() as $id => $label) {
			$out .= '<option value="' . esc_attr((string) $id) . '">' . esc_html($label) . '</option>';
		}
		$out .= '</select></p>';
		$out .= '<p><label for="ab_doc_new_scope">' . esc_html__('Scope', 'agent-brain') . '</label><br /><select id="ab_doc_new_scope" name="scope_id" required>';
		foreach (ArtifactAdminUi::scopeChoices() as $id => $label) {
			$out .= '<option value="' . esc_attr((string) $id) . '">' . esc_html($label) . '</option>';
		}
		$out .= '</select></p>';
		$out .= '<p class="description">' . esc_html__('The lead plans the sections and must be able to write in the scope.', 'agent-brain') . '</p>';
		$out .= '<p><button type="submit" class="button button-primary">' . esc_html__('Start the document', 'agent-brain') . '</button></p>';

		return $out . '</form></details>';
	}
}
