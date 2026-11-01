<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Review;

use AgentBrain\Modules\Admin\SettingsHub;

/**
 * #701: the Review inbox's markup. A list of everything waiting on the left
 * and the selected item in full on the right (stacked on narrow screens),
 * one form per item that posts to the type's existing admin-post handler.
 * Without JavaScript it is a working page: a link per item selects it
 * (?item=), its buttons post as before, Edit and the reason for Not needed
 * open as <details>, and the checkboxes post the bulk form.
 * assets/review-inbox.js adds keys, Undo and in-place updates on top.
 *
 * Every id carries the item's key, so the page has no duplicate ids
 * (A11yScreensTest).
 */
final class InboxView {

	public const ROOT_ID = 'ab-review';

	/** Days before closing from which the list flags an item. */
	private const SOON_DAYS = 3;

	/**
	 * Reasons a curator can pick for Not needed; the value is stored in
	 * words in the review note and the outcome memory.
	 *
	 * @return array<string, string>
	 */
	public static function reasons(): array {
		return [
			'duplicate'    => __('Duplicate', 'agent-brain'),
			'wrong'        => __('Wrong', 'agent-brain'),
			'out_of_date'  => __('Out of date', 'agent-brain'),
			'too_specific' => __('Too specific', 'agent-brain'),
			'private'      => __('Private', 'agent-brain'),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $all      Everything waiting.
	 * @param array{type:string, scope:int, agent:int}  $filters
	 */
	public static function render(array $all, array $filters, string $selectedKey, string $intro, string $howItRuns): void {
		$items = array_values(array_filter($all, static fn (array $item): bool => self::matches($item, $filters)));

		echo '<div class="wrap ab-admin ab-admin-review ab-ri" id="' . esc_attr(self::ROOT_ID) . '" data-total="' . esc_attr((string) count($all)) . '">';
		echo '<h1>' . esc_html__('Review Queue', 'agent-brain') . '</h1>';
		echo '<p class="ab-review-intro">' . esc_html($intro) . '</p>';
		echo '<p class="description">' . esc_html($howItRuns);
		if (current_user_can(SettingsHub::CAPABILITY)) {
			echo ' <a href="' . esc_url(SettingsHub::url(SettingsHub::TAB_REVIEW)) . '">' . esc_html__('Review settings', 'agent-brain') . '</a>';
		}
		echo '</p>';

		if ($all === []) {
			self::renderEmpty();
			echo '</div>';

			return;
		}

		self::renderHead(count($all));
		self::renderFilters($all, $filters);

		if ($items === []) {
			echo '<div class="ab-ri-inbox ab-ri-inbox--none"><p class="ab-ri-none">' . esc_html__('Nothing here matches these filters.', 'agent-brain')
				. ' <a href="' . esc_url(self::url()) . '">' . esc_html__('Show everything', 'agent-brain') . '</a></p></div></div>';

			return;
		}

		$keys = array_column($items, 'key');
		if (! in_array($selectedKey, $keys, true)) {
			$selectedKey = (string) $keys[0];
		}

		echo '<div class="ab-ri-inbox">';
		self::renderList($items, $filters, $selectedKey);
		echo '<div class="ab-ri-details">';
		foreach ($items as $item) {
			self::renderDetail($item, $item['key'] === $selectedKey);
		}
		echo '</div></div>';

		echo '<div class="ab-ri-toast" role="status" aria-live="polite" hidden></div>';
		self::renderHelp();
		echo '</div>';
	}

	/**
	 * @param array<string, mixed>                     $item
	 * @param array{type:string, scope:int, agent:int} $filters
	 */
	private static function matches(array $item, array $filters): bool {
		if ($filters['type'] !== '' && $item['type'] !== $filters['type']) {
			return false;
		}
		if ($filters['scope'] > 0 && (int) $item['scope_id'] !== $filters['scope']) {
			return false;
		}
		if ($filters['agent'] > 0 && (int) ($item['by']['id'] ?? 0) !== $filters['agent']) {
			return false;
		}

		return true;
	}

	/** @param array<string, int|string> $args */
	public static function url(array $args = []): string {
		return add_query_arg(array_filter(['page' => ReviewQueuePage::SLUG] + $args, static fn ($v): bool => $v !== '' && $v !== 0), admin_url('admin.php'));
	}

	private static function renderHead(int $total): void {
		echo '<div class="ab-ri-head">';
		echo '<p class="ab-ri-progress" aria-live="polite"><span class="ab-ri-bar" aria-hidden="true"><i></i></span><span class="ab-ri-count">'
			. esc_html(sprintf(
				/* translators: %d: number of items waiting */
				_n('%d waiting for you', '%d waiting for you', $total, 'agent-brain'),
				$total
			)) . '</span></p>';
		echo '<button type="button" class="button-link ab-ri-js ab-ri-help-open" hidden>' . esc_html__('Keyboard shortcuts', 'agent-brain') . ' <kbd>?</kbd></button>';
		echo '</div>';
	}

	/**
	 * @param array<int, array<string, mixed>> $all
	 * @param array{type:string, scope:int, agent:int} $filters
	 */
	private static function renderFilters(array $all, array $filters): void {
		$labels = [
			''                          => __('All', 'agent-brain'),
			InboxItems::TYPE_FACT      => __('Facts', 'agent-brain'),
			InboxItems::TYPE_PROCEDURE => __('Procedures', 'agent-brain'),
			InboxItems::TYPE_HANDOFF   => __('Handoffs', 'agent-brain'),
			InboxItems::TYPE_MEMORY    => __('Memories', 'agent-brain'),
			InboxItems::TYPE_AGENT     => __('New agents', 'agent-brain'),
		];
		$counts = array_count_values(array_column($all, 'type'));
		$counts[''] = count($all);

		echo '<div class="ab-ri-filters">';
		echo '<ul class="ab-ri-types" aria-label="' . esc_attr__('Show', 'agent-brain') . '">';
		foreach ($labels as $type => $label) {
			if ($type !== '' && empty($counts[$type])) {
				continue;
			}
			$current = $filters['type'] === $type;
			echo '<li><a class="ab-ri-chip" href="' . esc_url(self::url(['ab_type' => $type, 'ab_scope' => $filters['scope'], 'ab_agent' => $filters['agent']])) . '"'
				. ($current ? ' aria-current="true"' : '') . '>' . esc_html($label) . ' <span class="ab-ri-n">' . esc_html((string) $counts[$type]) . '</span></a></li>';
		}
		echo '</ul>';

		$scopes = [];
		$agents = [];
		foreach ($all as $item) {
			if ((int) $item['scope_id'] > 0) {
				$scopes[(int) $item['scope_id']] = (string) $item['scope'];
			}
			if (is_array($item['by']) && (int) $item['by']['id'] > 0) {
				$agents[(int) $item['by']['id']] = (string) $item['by']['label'];
			}
		}
		asort($scopes);
		asort($agents);

		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="ab-ri-selects">';
		echo '<input type="hidden" name="page" value="' . esc_attr(ReviewQueuePage::SLUG) . '" />';
		if ($filters['type'] !== '') {
			echo '<input type="hidden" name="ab_type" value="' . esc_attr($filters['type']) . '" />';
		}
		if (count($scopes) > 1) {
			echo '<label class="screen-reader-text" for="ab-ri-scope">' . esc_html__('Scope', 'agent-brain') . '</label><select name="ab_scope" id="ab-ri-scope"><option value="0">' . esc_html__('Every scope', 'agent-brain') . '</option>';
			foreach ($scopes as $id => $name) {
				echo '<option value="' . esc_attr((string) $id) . '"' . selected($filters['scope'], $id, false) . '>' . esc_html($name) . '</option>';
			}
			echo '</select>';
		}
		if (count($agents) > 1) {
			echo '<label class="screen-reader-text" for="ab-ri-agent">' . esc_html__('Suggested by', 'agent-brain') . '</label><select name="ab_agent" id="ab-ri-agent"><option value="0">' . esc_html__('Every agent', 'agent-brain') . '</option>';
			foreach ($agents as $id => $label) {
				echo '<option value="' . esc_attr((string) $id) . '"' . selected($filters['agent'], $id, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select>';
		}
		if (count($scopes) > 1 || count($agents) > 1) {
			echo '<button type="submit" class="button ab-ri-nojs">' . esc_html__('Filter', 'agent-brain') . '</button>';
		}
		echo '</form></div>';
	}

	/**
	 * The list, inside the bulk form: checking facts and procedures and
	 * pressing one of the two buttons decides them all (ab_review_bulk).
	 * With JavaScript every type can be checked, and the buttons decide
	 * each checked item through its own form.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param array{type:string, scope:int, agent:int} $filters
	 */
	private static function renderList(array $items, array $filters, string $selectedKey): void {
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ab-ri-listform">';
		wp_nonce_field(ReviewQueuePage::NONCE_ACTION, ReviewQueuePage::NONCE_FIELD);
		echo '<input type="hidden" name="action" value="ab_review_bulk" />';
		echo '<div class="ab-ri-bulk"><span class="ab-ri-bulk-count ab-ri-js" hidden></span>'
			. '<button type="submit" name="bulk_outcome" value="approved" class="button button-small">' . esc_html__('Make selected official', 'agent-brain') . '</button> '
			. '<button type="submit" name="bulk_outcome" value="rejected" class="button button-small">' . esc_html__('Mark selected not needed', 'agent-brain') . '</button></div>';

		echo '<ul class="ab-ri-list" aria-label="' . esc_attr__('Waiting for you', 'agent-brain') . '">';
		foreach ($items as $item) {
			$key     = (string) $item['key'];
			$current = $key === $selectedKey;
			$isPost  = in_array($item['type'], [InboxItems::TYPE_FACT, InboxItems::TYPE_PROCEDURE], true);

			echo '<li class="ab-ri-row" data-key="' . esc_attr($key) . '">';
			echo '<input type="checkbox" class="ab-ri-check' . ($isPost ? '' : ' ab-ri-js') . '" id="ab-ri-c-' . esc_attr($key) . '"'
				. ($isPost ? ' name="post_ids[]" value="' . esc_attr((string) $item['id']) . '"' : ' hidden')
				. ' aria-label="' . esc_attr(sprintf(
					/* translators: %s: item title */
					__('Select %s', 'agent-brain'),
					(string) $item['title']
				)) . '" />';
			echo '<a class="ab-ri-link" href="' . esc_url(self::url(['ab_type' => $filters['type'], 'ab_scope' => $filters['scope'], 'ab_agent' => $filters['agent'], 'item' => $key])) . '"'
				. ' aria-controls="ab-ri-d-' . esc_attr($key) . '"' . ($current ? ' aria-current="true"' : '') . '>';
			echo '<span class="ab-ri-title">' . esc_html((string) $item['title']) . '</span>';
			echo '<span class="ab-ri-meta">' . self::tag($item) . self::metaBits($item) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			echo '</a></li>';
		}
		echo '</ul></form>';
	}

	/**
	 * The coloured label: what kind of item, and for a fact, how it relates to official knowledge.
	 *
	 * @param array<string, mixed> $item
	 */
	private static function tag(array $item): string {
		$type = (string) $item['type'];
		if ($type === InboxItems::TYPE_FACT || $type === InboxItems::TYPE_PROCEDURE) {
			$kind   = (string) ($item['match']['kind'] ?? 'new');
			$labels = [
				'update' => __('Changes official', 'agent-brain'),
				'same'   => __('Already official', 'agent-brain'),
				'new'    => __('New fact', 'agent-brain'),
			];
			$html = '<span class="ab-ri-tag ab-ri-tag--' . esc_attr($kind) . '">' . esc_html($labels[$kind] ?? $labels['new']) . '</span>';
			if ($type === InboxItems::TYPE_PROCEDURE) {
				$html .= '<span class="ab-ri-tag ab-ri-tag--type">' . esc_html__('Procedure', 'agent-brain') . '</span>';
			}

			return $html;
		}

		$labels = [
			InboxItems::TYPE_HANDOFF => __('Handoff', 'agent-brain'),
			InboxItems::TYPE_MEMORY  => __('Memory', 'agent-brain'),
			InboxItems::TYPE_AGENT   => __('New agent', 'agent-brain'),
		];

		return '<span class="ab-ri-tag ab-ri-tag--type">' . esc_html($labels[$type] ?? $type) . '</span>';
	}

	/** @param array<string, mixed> $item */
	private static function metaBits(array $item): string {
		$bits = [];
		if (is_array($item['by'])) {
			$bits[] = esc_html((string) $item['by']['label']);
		}
		if ((string) $item['scope'] !== '') {
			$bits[] = esc_html((string) $item['scope']);
		}
		$bits[] = esc_html(self::ago((string) $item['since']));

		$html  = '<span class="ab-ri-bits">' . implode(' · ', $bits) . '</span>';
		$left  = self::secondsLeft($item['closes'] ?? null);
		if ($left !== null && $left < self::SOON_DAYS * DAY_IN_SECONDS) {
			$html .= '<span class="ab-ri-tag ab-ri-tag--soon">' . esc_html(self::closesIn($left, true)) . '</span>';
		}

		return $html;
	}

	/** @param array<string, mixed> $item */
	private static function renderDetail(array $item, bool $current): void {
		$key  = (string) $item['key'];
		$type = (string) $item['type'];

		echo '<section class="ab-ri-detail" id="ab-ri-d-' . esc_attr($key) . '" data-key="' . esc_attr($key) . '" data-type="' . esc_attr($type) . '"'
			. ' aria-labelledby="ab-ri-t-' . esc_attr($key) . '"' . ($current ? '' : ' hidden') . '>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ab-ri-form">';
		self::hiddenFields($item);

		echo '<div class="ab-ri-body">';
		echo '<a class="ab-ri-back ab-ri-js" href="' . esc_url(self::url()) . '" hidden>' . esc_html__('All items', 'agent-brain') . '</a>';
		echo '<p class="ab-ri-kicker">' . esc_html(self::kicker($item)) . '</p>';
		echo '<h2 id="ab-ri-t-' . esc_attr($key) . '" tabindex="-1">' . esc_html((string) $item['title']) . '</h2>';
		self::renderWho($item);

		switch ($type) {
			case InboxItems::TYPE_HANDOFF:
				self::renderHandoff($item);
				break;
			case InboxItems::TYPE_MEMORY:
				self::renderMemory($item);
				break;
			case InboxItems::TYPE_AGENT:
				self::renderAgent($item);
				break;
			default:
				self::renderProposal($item);
		}
		echo '</div>';

		self::renderActions($item);
		echo '</form></section>';
	}

	/** @param array<string, mixed> $item */
	private static function hiddenFields(array $item): void {
		$id = (string) $item['id'];
		switch ($item['type']) {
			case InboxItems::TYPE_HANDOFF:
				echo '<input type="hidden" name="action" value="ab_handoff_action" /><input type="hidden" name="handoff_id" value="' . esc_attr($id) . '" />'
					. '<input type="hidden" name="' . esc_attr(HandoffQueue::NONCE_FIELD) . '" value="' . esc_attr(wp_create_nonce(HandoffQueue::NONCE_ACTION . '_' . $id)) . '" />';
				break;
			case InboxItems::TYPE_MEMORY:
				echo '<input type="hidden" name="action" value="' . esc_attr(MemoryProposalQueue::ACTION) . '" /><input type="hidden" name="memory_id" value="' . esc_attr($id) . '" />'
					. '<input type="hidden" name="' . esc_attr(MemoryProposalQueue::NONCE_FIELD) . '" value="' . esc_attr(wp_create_nonce(MemoryProposalQueue::NONCE_ACTION . $id)) . '" />';
				break;
			case InboxItems::TYPE_AGENT:
				echo '<input type="hidden" name="action" value="' . esc_attr(AgentProposalQueue::ACTION) . '" /><input type="hidden" name="proposal" value="' . esc_attr($id) . '" />'
					. '<input type="hidden" name="' . esc_attr(AgentProposalQueue::NONCE_FIELD) . '" value="' . esc_attr(wp_create_nonce(AgentProposalQueue::NONCE_ACTION . $id)) . '" />';
				break;
			default:
				echo '<input type="hidden" name="action" value="ab_review_action" /><input type="hidden" name="post_id" value="' . esc_attr($id) . '" />'
					. '<input type="hidden" name="' . esc_attr(ReviewQueuePage::NONCE_FIELD) . '" value="' . esc_attr(wp_create_nonce(ReviewQueuePage::NONCE_ACTION . '_' . $id)) . '" />';
		}
	}

	/** @param array<string, mixed> $item */
	private static function kicker(array $item): string {
		$scope = (string) $item['scope'];
		switch ($item['type']) {
			case InboxItems::TYPE_HANDOFF:
				return __('Handoff', 'agent-brain');
			case InboxItems::TYPE_MEMORY:
				/* translators: %s: scope name */
				return $scope !== '' ? sprintf(__('Memory for %s', 'agent-brain'), $scope) : __('Memory', 'agent-brain');
			case InboxItems::TYPE_AGENT:
				return __('Proposed agent', 'agent-brain');
			case InboxItems::TYPE_PROCEDURE:
				/* translators: %s: scope name */
				return $scope !== '' ? sprintf(__('Procedure for %s', 'agent-brain'), $scope) : __('Procedure', 'agent-brain');
			default:
				/* translators: %s: scope name */
				return $scope !== '' ? sprintf(__('Fact for %s', 'agent-brain'), $scope) : __('Fact', 'agent-brain');
		}
	}

	/** @param array<string, mixed> $item */
	private static function renderWho(array $item): void {
		$ago = self::ago((string) $item['since']);
		echo '<div class="ab-ri-who">';
		if (! is_array($item['by'])) {
			echo '<span>' . esc_html(sprintf(
				/* translators: %s: how long ago, e.g. "2 days" */
				__('From an import, waiting %s', 'agent-brain'),
				$ago
			)) . '</span></div>';

			return;
		}

		$by = $item['by'];
		echo '<span class="ab-ri-avatar" aria-hidden="true">' . esc_html(self::initials((string) ($by['person'] !== '' ? $by['person'] : $by['name']))) . '</span><span>';
		$name = '<strong>' . esc_html((string) $by['name']) . '</strong>';
		if ((string) $by['person'] !== '') {
			echo wp_kses(sprintf(
				/* translators: 1: agent name, 2: person the agent works for, 3: how long ago, e.g. "2 days". Tío is TomaTío's word for a person's AI agent. */
				__('Suggested by %1$s, %2$s\'s Tío, %3$s ago', 'agent-brain'),
				$name,
				esc_html((string) $by['person']),
				esc_html($ago)
			), ['strong' => []]);
		} else {
			echo wp_kses(sprintf(
				/* translators: 1: who suggested it, 2: how long ago, e.g. "2 days" */
				__('Suggested by %1$s, %2$s ago', 'agent-brain'),
				$name,
				esc_html($ago)
			), ['strong' => []]);
		}
		if ((string) $by['account'] !== '') {
			echo '<br /><span class="ab-ri-account">' . esc_html((string) $by['account']) . '</span>';
		}
		echo '</span></div>';
	}

	/** @param array<string, mixed> $item */
	private static function renderProposal(array $item): void {
		$key   = (string) $item['key'];
		$match = (array) $item['match'];
		$kind  = (string) ($match['kind'] ?? 'new');
		$scope = (string) $item['scope'];

		if ($kind === 'update') {
			self::callout('update', __('Changes official knowledge.', 'agent-brain'), sprintf(
				/* translators: %s: title of the official item */
				__('Official "%s" says something different. Making this official adds it next to that one, so agents would read both.', 'agent-brain'),
				(string) $match['title']
			));
		} elseif ($kind === 'same') {
			self::callout('same', __('Already official, almost word for word.', 'agent-brain'), sprintf(
				/* translators: %s: title of the official item */
				__('"%s" says the same. Usually: Not needed, reason Duplicate.', 'agent-brain'),
				(string) $match['title']
			));
		} else {
			self::callout('new', $scope !== ''
				? sprintf(
					/* translators: %s: scope name */
					__('New to %s.', 'agent-brain'),
					$scope
				)
				: __('New.', 'agent-brain'), (string) $match['title'] !== ''
				? sprintf(
					/* translators: %s: title of the nearest official item */
					__('Nothing official says this yet. The closest is "%s", and it is not close.', 'agent-brain'),
					(string) $match['title']
				)
				: __('Nothing official says this yet.', 'agent-brain'));
		}

		$hasDiff = $kind !== 'new' && (string) $match['html'] !== '';
		echo '<div class="ab-ri-panes">';
		if ($hasDiff) {
			echo '<div class="ab-ri-seg ab-ri-js" role="group" aria-label="' . esc_attr__('Show', 'agent-brain') . '" hidden>'
				. '<button type="button" data-pane="changes" aria-pressed="true">' . esc_html__('Changes', 'agent-brain') . '</button>'
				. '<button type="button" data-pane="proposed" aria-pressed="false">' . esc_html__('Suggested text', 'agent-brain') . '</button>'
				. '<button type="button" data-pane="official" aria-pressed="false">' . esc_html__('Official now', 'agent-brain') . '</button></div>';
			// Diff::renderWords() escapes every word itself.
			echo '<div class="ab-ri-text ab-ri-diff" data-pane="changes">' . wp_kses((string) $match['html'], ['ins' => [], 'del' => []]) . '</div>';
			echo '<p class="ab-ri-hint ab-ri-diff-key">' . wp_kses(__('<del>Struck</del> is in the official text now, <ins>green</ins> is what the agent suggests.', 'agent-brain'), ['ins' => [], 'del' => []]) . '</p>';
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- paragraphsHtml() escapes each paragraph.
			echo '<div class="ab-ri-text" data-pane="proposed" hidden>' . self::paragraphsHtml((array) $item['paragraphs']) . '</div>';
			echo '<div class="ab-ri-text" data-pane="official" hidden>' . self::paragraphsHtml(InboxItems::paragraphs((string) $match['official'])) . '</div>';
		} else {
			echo '<div class="ab-ri-text" data-pane="proposed">' . self::paragraphsHtml((array) $item['paragraphs']) . '</div>';
			// phpcs:enable
		}
		echo '</div>';

		// Not needed, with an optional reason the agent reads. It comes
		// before Edit so that Enter in its field submits Not needed: implicit
		// submission uses the form's first submit button.
		echo '<details class="ab-ri-reason"><summary>' . esc_html__('Not needed, with a reason', 'agent-brain') . '</summary>';
		echo '<fieldset><legend>' . wp_kses(__('Why is it not needed? <span>Optional. The agent that suggested it learns from your answer.</span>', 'agent-brain'), ['span' => []]) . '</legend><span class="ab-ri-reasons">';
		foreach (self::reasons() as $value => $label) {
			echo '<label class="ab-ri-chip"><input type="radio" name="review_reason" value="' . esc_attr($value) . '" /> ' . esc_html($label) . '</label>';
		}
		echo '</span>';
		echo '<label class="screen-reader-text" for="ab-ri-n-' . esc_attr($key) . '">' . esc_html__('Reason in your words', 'agent-brain') . '</label>';
		echo '<input type="text" name="review_note" id="ab-ri-n-' . esc_attr($key) . '" maxlength="300" class="ab-ri-note" placeholder="' . esc_attr__('Or say it in a few words (optional)', 'agent-brain') . '" />';
		echo '<p class="ab-ri-edit-actions"><button type="submit" name="outcome" value="rejected" class="button" data-act="confirm-reject" data-done="' . esc_attr__('marked not needed', 'agent-brain') . '">' . esc_html__('Not needed', 'agent-brain') . '</button>'
			. ' <button type="button" class="button-link ab-ri-js" data-act="cancel" hidden>' . esc_html__('Cancel', 'agent-brain') . ' <kbd>Esc</kbd></button></p>';
		echo '</fieldset></details>';

		// Edit in place: with JavaScript the text above turns into this
		// field; without, it opens as a <details>.
		echo '<details class="ab-ri-edit"><summary>' . esc_html__('Edit the text first', 'agent-brain') . '</summary>';
		echo '<label class="screen-reader-text" for="ab-ri-e-' . esc_attr($key) . '">' . esc_html__('Edited text', 'agent-brain') . '</label>';
		echo '<textarea name="edited_content" id="ab-ri-e-' . esc_attr($key) . '" rows="6" class="ab-ri-text ab-ri-textarea">' . esc_textarea(implode("\n\n", (array) $item['paragraphs'])) . '</textarea>';
		echo '<p class="ab-ri-hint ab-ri-live" aria-live="polite"></p>';
		echo '<p class="ab-ri-edit-actions"><button type="submit" name="outcome" value="edited" class="button button-primary" data-act="save" data-done="' . esc_attr__('made official, with your edits', 'agent-brain') . '">' . esc_html__('Make official as edited', 'agent-brain') . '</button>'
			. ' <button type="button" class="button-link ab-ri-js" data-act="cancel" hidden>' . esc_html__('Cancel', 'agent-brain') . ' <kbd>Esc</kbd></button></p>';
		echo '</details>';

		echo '<dl class="ab-ri-facts">';
		if ($item['source'] !== null) {
			echo '<dt>' . esc_html__('From', 'agent-brain') . '</dt><dd>' . esc_html(sprintf(
				/* translators: %s: date the memory was written */
				__('A memory written %s', 'agent-brain'),
				self::date((string) $item['source']['created'])
			)) . '<blockquote class="ab-ri-quote">' . esc_html((string) $item['source']['content']) . '</blockquote></dd>';
		}
		if ((int) $item['sources'] > 1) {
			echo '<dt>' . esc_html__('Why', 'agent-brain') . '</dt><dd>' . esc_html(sprintf(
				/* translators: %d: number of memories */
				_n('Suggested from %d memory', 'Suggested from %d memories', (int) $item['sources'], 'agent-brain'),
				(int) $item['sources']
			)) . '</dd>';
		}
		if ($scope !== '') {
			echo '<dt>' . esc_html__('Who will rely on it', 'agent-brain') . '</dt><dd>' . esc_html(sprintf(
				/* translators: %s: scope name */
				__('Every agent with access to %s', 'agent-brain'),
				$scope
			)) . '</dd>';
		}
		echo '<dt>' . esc_html__('Length', 'agent-brain') . '</dt><dd>' . wp_kses(sprintf(
			/* translators: %s: number of words */
			__('%s words', 'agent-brain'),
			'<span class="ab-ri-words">' . (int) $item['words'] . '</span>'
		), ['span' => ['class' => []]]);
		if ((string) $item['edit_link'] !== '') {
			echo ' · <a href="' . esc_url((string) $item['edit_link']) . '">' . esc_html__('Open in the editor', 'agent-brain') . '</a>';
		}
		echo '</dd>';
		self::closesRow($item);
		echo '</dl>';
	}

	/** @param array<string, mixed> $item */
	private static function renderHandoff(array $item): void {
		$to = is_array($item['to'] ?? null) ? (string) $item['to']['label'] : __('Any curator', 'agent-brain');
		self::callout('handoff', sprintf(
			/* translators: 1: who sent it, 2: who it is for */
			__('From %1$s to %2$s.', 'agent-brain'),
			is_array($item['by']) ? (string) $item['by']['label'] : '',
			$to
		), sprintf(
			/* translators: %d: number of memories */
			_n('Approving lets the recipient read everything in the scope its %d memory is in, not only that memory. Nothing becomes official.', 'Approving lets the recipient read everything in the scopes its %d memories are in, not only those memories. Nothing becomes official.', (int) $item['memories'], 'agent-brain'),
			(int) $item['memories']
		));
		if ((array) $item['paragraphs'] !== []) {
			echo '<div class="ab-ri-text">' . self::paragraphsHtml((array) $item['paragraphs']) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		}
		echo '<dl class="ab-ri-facts"><dt>' . esc_html__('Read access to', 'agent-brain') . '</dt><dd>' . esc_html((string) $item['grants']) . '</dd>';
		self::closesRow($item);
		echo '</dl>';
	}

	/** @param array<string, mixed> $item */
	private static function renderMemory(array $item): void {
		self::callout('handoff', __('A memory waiting before agents can read it.', 'agent-brain'), __('Keeping it makes it readable by agents with access to its scope. Deleting it removes it.', 'agent-brain'));
		echo '<div class="ab-ri-text">' . self::paragraphsHtml((array) $item['paragraphs']) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		echo '<dl class="ab-ri-facts"><dt>' . esc_html__('Kind', 'agent-brain') . '</dt><dd>' . esc_html((string) $item['kind']) . '</dd></dl>';
	}

	/** @param array<string, mixed> $item */
	private static function renderAgent(array $item): void {
		self::callout('handoff', __('An agent asks for a new agent for this work.', 'agent-brain'), __('Adding it creates an agent account with this role and these scopes, with no password: it is reachable only through Connect-as and role switching.', 'agent-brain'));
		echo '<dl class="ab-ri-facts">'
			. '<dt>' . esc_html__('Role', 'agent-brain') . '</dt><dd>' . esc_html((string) $item['role']) . '</dd>'
			. '<dt>' . esc_html__('Reads', 'agent-brain') . '</dt><dd>' . esc_html((string) $item['reads']) . '</dd>'
			. '<dt>' . esc_html__('Writes', 'agent-brain') . '</dt><dd>' . esc_html((string) $item['writes']) . '</dd>';
		self::closesRow($item);
		echo '</dl>';
	}

	/**
	 * One action bar per item. data-act names what a key does: a is the
	 * first button, x the reject one, e the edit one.
	 *
	 * @param array<string, mixed> $item
	 */
	private static function renderActions(array $item): void {
		$type = (string) $item['type'];
		echo '<div class="ab-ri-actions">';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- button() escapes its label, key and values.
		if ($type === InboxItems::TYPE_HANDOFF) {
			echo self::button('handoff_outcome', 'approve', __('Approve and share access', 'agent-brain'), 'a', 'approve', __('approved: access shared', 'agent-brain'), true)
				. self::button('handoff_outcome', 'dismiss', __('Dismiss', 'agent-brain'), 'x', 'reject', __('dismissed', 'agent-brain'));
		} elseif ($type === InboxItems::TYPE_MEMORY) {
			echo self::button('decision', 'approve', __('Keep it', 'agent-brain'), 'a', 'approve', __('kept', 'agent-brain'), true)
				. self::button('decision', 'reject', __('Delete', 'agent-brain'), 'x', 'reject', __('deleted', 'agent-brain'));
		} elseif ($type === InboxItems::TYPE_AGENT) {
			echo self::button('decision', 'approve', __('Add agent', 'agent-brain'), 'a', 'approve', __('added', 'agent-brain'), true)
				. self::button('decision', 'decline', __('Decline', 'agent-brain'), 'x', 'reject', __('declined', 'agent-brain'));
		} else {
			echo self::button('outcome', 'approved', __('Make official', 'agent-brain'), 'a', 'approve', __('made official', 'agent-brain'), true)
				. '<button type="button" class="button ab-ri-js" data-act="edit" hidden><span>' . esc_html__('Edit', 'agent-brain') . '</span> <kbd>e</kbd></button>'
				. self::button('outcome', 'rejected', __('Not needed', 'agent-brain'), 'x', 'reject', __('marked not needed', 'agent-brain'));
		}
		// phpcs:enable

		echo '<span class="ab-ri-spacer"></span><button type="button" class="button-link ab-ri-js" data-act="next" hidden><span>' . esc_html__('Skip', 'agent-brain') . '</span> <kbd>j</kbd></button>';
		echo '</div>';
	}

	/** $done is what the Undo message says happened ("made official"). */
	private static function button(string $name, string $value, string $label, string $key, string $act, string $done, bool $primary = false): string {
		return '<button type="submit" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="button' . ($primary ? ' button-primary' : '') . '" data-act="' . esc_attr($act) . '" data-done="' . esc_attr($done) . '">'
			. '<span>' . esc_html($label) . '</span> <kbd>' . esc_html($key) . '</kbd></button> ';
	}

	private static function callout(string $kind, string $strong, string $rest): void {
		echo '<p class="ab-ri-callout ab-ri-callout--' . esc_attr($kind) . '"><strong>' . esc_html($strong) . '</strong> ' . esc_html($rest) . '</p>';
	}

	/** @param array<string, mixed> $item */
	private static function closesRow(array $item): void {
		$left = self::secondsLeft($item['closes'] ?? null);
		if ($left === null) {
			return;
		}

		echo '<dt>' . esc_html__('If nobody decides', 'agent-brain') . '</dt><dd>' . esc_html(self::closesIn($left, false)) . '</dd>';
	}

	/** @param string[] $paragraphs */
	private static function paragraphsHtml(array $paragraphs): string {
		return implode('', array_map(static fn (string $p): string => '<p>' . esc_html($p) . '</p>', $paragraphs));
	}

	private static function secondsLeft(?string $closesGmt): ?int {
		if ($closesGmt === null || $closesGmt === '') {
			return null;
		}
		$time = strtotime($closesGmt . ' UTC');

		return $time === false ? null : max(0, $time - time());
	}

	private static function closesIn(int $seconds, bool $short): string {
		if ($seconds < DAY_IN_SECONDS) {
			$hours = max(1, (int) ceil($seconds / HOUR_IN_SECONDS));

			return $short
				/* translators: %d: hours */
				? sprintf(_n('closes in %d hour', 'closes in %d hours', $hours, 'agent-brain'), $hours)
				/* translators: %d: hours */
				: sprintf(_n('Closes as not needed in %d hour', 'Closes as not needed in %d hours', $hours, 'agent-brain'), $hours);
		}

		$days = (int) ceil($seconds / DAY_IN_SECONDS);

		return $short
			/* translators: %d: days */
			? sprintf(_n('closes in %d day', 'closes in %d days', $days, 'agent-brain'), $days)
			/* translators: %d: days */
			: sprintf(_n('Closes as not needed in %d day', 'Closes as not needed in %d days', $days, 'agent-brain'), $days);
	}

	private static function ago(string $gmt): string {
		$time = strtotime($gmt . ' UTC');

		return $time === false ? '' : human_time_diff($time, time());
	}

	private static function date(string $gmt): string {
		$time = strtotime($gmt . ' UTC');

		return $time === false ? '' : wp_date((string) get_option('date_format'), $time);
	}

	private static function initials(string $name): string {
		$words = preg_split('/[\s\-_.]+/', trim($name)) ?: [];
		$out   = '';
		foreach (array_slice(array_filter($words), 0, 2) as $word) {
			$out .= mb_strtoupper(mb_substr($word, 0, 1));
		}

		return $out !== '' ? $out : '?';
	}

	/**
	 * "All caught up": nothing waits, what happens next, and the decisions
	 * so far instead of the old approval rate.
	 */
	private static function renderEmpty(): void {
		$counts = Stats::counts();
		echo '<div class="ab-ri-empty">';
		echo '<p class="ab-ri-empty-mark" aria-hidden="true">✓</p>';
		echo '<h2>' . esc_html__('All caught up', 'agent-brain') . '</h2>';
		echo '<p>' . esc_html__('Nothing is waiting for you. Agents keep working with what is official now.', 'agent-brain') . '</p>';
		if (array_sum($counts) > 0) {
			echo '<ul class="ab-ri-tally" aria-label="' . esc_attr__('Decided so far', 'agent-brain') . '">'
				. '<li><b>' . esc_html(number_format_i18n($counts['approved'])) . '</b> ' . esc_html__('made official', 'agent-brain') . '</li>'
				. '<li><b>' . esc_html(number_format_i18n($counts['edited'])) . '</b> ' . esc_html__('edited first', 'agent-brain') . '</li>'
				. '<li><b>' . esc_html(number_format_i18n($counts['rejected'])) . '</b> ' . esc_html__('not needed', 'agent-brain') . '</li></ul>';
		}
		echo '<ul class="ab-ri-next">'
			. '<li>' . esc_html__('When an agent suggests something, it shows up here, with a count next to Review in the TomaTío menu.', 'agent-brain') . '</li>'
			. '<li>' . esc_html(AutoApprove::enabled()
				? __('Facts from curators\' own Tíos are made official without waiting here.', 'agent-brain')
				: __('Facts from curators\' own Tíos wait here too; you can have them made official automatically in the review settings.', 'agent-brain')) . '</li>';
		$days = Expiry::days();
		if ($days > 0) {
			echo '<li>' . esc_html(sprintf(
				/* translators: %d: number of days */
				_n('Anything nobody decides within %d day closes as not needed. The memory it came from is kept.', 'Anything nobody decides within %d days closes as not needed. The memory it came from is kept.', $days, 'agent-brain'),
				$days
			)) . '</li>';
		}
		echo '</ul>';
		echo '<p><a class="button" href="' . esc_url(admin_url('edit.php?post_type=ab_knowledge')) . '">' . esc_html__('See what is official', 'agent-brain') . '</a> '
			. '<a class="button-link" href="' . esc_url(admin_url('admin.php?page=agent-brain-connect')) . '">' . esc_html__('Connect an AI', 'agent-brain') . '</a></p>';
		echo '</div>';
	}

	private static function renderHelp(): void {
		$keys = [
			'j / k'   => __('Next / previous item', 'agent-brain'),
			'a'       => __('Make official (or the first button)', 'agent-brain'),
			'e'       => __('Edit the text', 'agent-brain'),
			'⌘ / Ctrl + Enter' => __('Make official as edited', 'agent-brain'),
			'x'       => __('Not needed (or the second button)', 'agent-brain'),
			'u'       => __('Undo the last decision', 'agent-brain'),
			'space'   => __('Select, to decide several at once', 'agent-brain'),
			'Esc'     => __('Cancel editing, or close this', 'agent-brain'),
		];
		echo '<div class="ab-ri-help" role="dialog" aria-modal="true" aria-labelledby="ab-ri-help-title" hidden><div class="ab-ri-help-card">';
		echo '<h2 id="ab-ri-help-title">' . esc_html__('Keyboard shortcuts', 'agent-brain') . '</h2><dl>';
		foreach ($keys as $key => $what) {
			echo '<dt><kbd>' . esc_html($key) . '</kbd></dt><dd>' . esc_html($what) . '</dd>';
		}
		echo '</dl><p><button type="button" class="button ab-ri-help-close">' . esc_html__('Close', 'agent-brain') . '</button></p></div></div>';
	}
}
