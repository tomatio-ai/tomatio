<?php

declare(strict_types=1);

namespace AgentBrain\Modules\MemoriesAdmin;

use AgentBrain\Modules\Admin\DateFormat;
use AgentBrain\Modules\Admin\Confirm;
use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\AgentView\Page as AgentViewPage;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Provenance\OutsideSources;
use AgentBrain\Modules\Review\InboxItems;

/**
 * The "Memories" admin screen.
 *
 * Capability: ab_manage (curators and administrators). Raw memories can
 * hold anything an agent wrote across every scope, so the screen is limited
 * to the same role that can already run Forget; edits and deletes
 * use the same capability and each writes an audit event.
 */
final class Page {

	public const CAPABILITY    = Capabilities::CAP_MANAGE;
	public const SLUG          = 'agent-brain-memories';
	public const UPDATE_ACTION = 'agent_brain_memory_update';
	public const DELETE_ACTION = 'agent_brain_memory_delete';

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addMenu']);
		add_action('admin_post_' . self::UPDATE_ACTION, [self::class, 'handleUpdate']);
		add_action('admin_post_' . self::DELETE_ACTION, [self::class, 'handleDelete']);
	}

	public static function addMenu(): void {
		add_submenu_page(
			Menu::PARENT,
			__('Memories', 'agent-brain'),
			__('Memories', 'agent-brain'),
			self::CAPABILITY,
			self::SLUG,
			[self::class, 'render']
		);
	}

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to view Memories.', 'agent-brain'), 403);
		}

		$visible = ScopeVisibility::visibleScopeIds(get_current_user_id());
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters, no state change.
		$filters = Filters::fromArray((array) wp_unslash($_GET));
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$page = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector, no state change.
		$memoryId = isset($_GET['memory']) ? absint(wp_unslash($_GET['memory'])) : 0;

		$all  = Store::query(Filters::fromArray([]), 1, 1, $visible)['total'];
		$week = Store::countSince(gmdate('Y-m-d H:i:s', time() - WEEK_IN_SECONDS), $visible);

		// #751: how much there is, then the search and the list with the
		// selected memory beside it; the explanation folds away.
		$head = Ui::head(
			__('Memories', 'agent-brain'),
			sprintf(
				/* translators: 1: memories in all, 2: memories written in the last 7 days */
				__('%1$s remembered · %2$s this week', 'agent-brain'),
				'<b>' . esc_html(number_format_i18n($all)) . '</b>',
				'<b>' . esc_html(number_format_i18n($week)) . '</b>'
			),
			'',
			'<p>' . esc_html__('What the agents remembered, newest first: working notes, episodes and facts, in the scopes you can see. A memory stays private to its scope; agents read it only with access to that scope.', 'agent-brain') . '</p>'
				. '<p>' . esc_html__('You can change how important a memory is, its search keys and where it came from, or delete it. Deleting keeps summaries already made from it; Forget removes those too.', 'agent-brain') . '</p>'
		);
		echo '<div class="wrap ab-admin ab-admin-memories">' . $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		self::renderNotice();
		self::renderFilterForm($filters, Store::typeCounts($filters, $visible));

		$result = Store::query($filters, $page, Store::PER_PAGE, $visible);
		$rows   = $result['rows'];

		if ($rows === [] && $memoryId === 0) {
			$hasFilter = $filters !== Filters::fromArray([]);
			echo '<p class="ab-empty">' . esc_html(
				$hasFilter
					? __('No memories match these filters. Clear a filter to widen the search.', 'agent-brain')
					: __('No memories have been stored yet. Agents write memories through the memory-write tool; they will appear here as soon as one does.', 'agent-brain')
			) . '</p></div>';

			return;
		}

		$selected = $memoryId > 0 ? $memoryId : (int) $rows[0]['id'];
		$listArgs = array_merge(self::activeQueryArgs($filters), $page > 1 ? ['paged' => (string) $page] : []);
		$scopes   = self::scopeNames();

		$list = '';
		foreach ($rows as $row) {
			$id   = (int) $row['id'];
			$who  = InboxItems::who((int) $row['agent_id']);
			$meta = Ui::tag(self::typeWord((string) $row['type']), (string) $row['type'] === 'episode' ? 'info' : ((string) $row['type'] === 'fact' ? 'ok' : ''))
				. ' ' . esc_html($who['name']) . ' · ' . esc_html($scopes[(int) $row['scope_id']] ?? ('#' . (int) $row['scope_id']))
				. ' · ' . self::ago((string) $row['created']);
			$list .= Ui::row(
				self::url(array_merge($listArgs, ['memory' => (string) $id])),
				wp_html_excerpt(wp_strip_all_tags((string) $row['content']), 160, '…'),
				$meta,
				$id === $selected
			);
		}

		$split = Ui::split(
			__('Memories', 'agent-brain'),
			$list,
			self::detailHtml($selected, self::url($listArgs)),
			$memoryId > 0,
			self::pagination($filters, $page, (int) $result['total'])
		);
		echo $split . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
	}

	// ---------------------------------------------------------------
	// List
	// ---------------------------------------------------------------

	/**
	 * @param array<string, mixed> $filters
	 */
	private static function pagination(array $filters, int $page, int $total): string {
		$pages = (int) ceil($total / Store::PER_PAGE);
		if ($pages <= 1) {
			return '';
		}

		$base = add_query_arg(array_merge(['page' => self::SLUG], self::activeQueryArgs($filters), ['paged' => '%#%']), admin_url('admin.php'));

		return '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
			'base'      => $base,
			'format'    => '',
			'current'   => $page,
			'total'     => $pages,
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
		]) . '</div></div>';
	}

	/**
	 * One search box, type chips and the scope and agent pickers; the rest
	 * of the filters fold away under "More filters".
	 *
	 * @param array<string, mixed> $f
	 * @param array<string, int>   $counts type => rows
	 */
	private static function renderFilterForm(array $f, array $counts): void {
		$args  = self::activeQueryArgs($f);
		unset($args['type']);
		$chips = [['label' => __('All', 'agent-brain'), 'url' => self::url($args), 'count' => array_sum($counts), 'current' => $f['type'] === '']];
		foreach (Filters::TYPES as $type) {
			$chips[] = ['label' => self::typeWord($type, true), 'url' => self::url($args + ['type' => $type]), 'count' => $counts[$type] ?? 0, 'current' => $f['type'] === $type];
		}
		$more = $f['type'] !== '' || $f['status'] !== '' || (int) $f['imp_min'] > 0 || (int) $f['imp_max'] < 100 || $f['date_from'] !== '' || $f['date_to'] !== '';

		echo '<form method="get" class="ab-ui-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />';
		echo '<label for="ab_f_q" class="ab-ui-sr">' . esc_html__('Search memories', 'agent-brain') . '</label><input type="search" id="ab_f_q" name="q" data-ab-search placeholder="' . esc_attr__('Search what the agents remember', 'agent-brain') . '" value="' . esc_attr((string) $f['q']) . '" /><kbd class="ab-ui-kbd">/</kbd>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::chips($chips, __('Type', 'agent-brain'));

		echo '<label for="ab_f_scope" class="ab-ui-sr">' . esc_html__('Scope', 'agent-brain') . '</label><select id="ab_f_scope" name="scope_id"><option value="0">' . esc_html__('Every scope', 'agent-brain') . '</option>';
		foreach (self::scopeNames() as $id => $name) {
			echo '<option value="' . esc_attr((string) $id) . '"' . selected((int) $f['scope_id'], $id, false) . '>' . esc_html($name) . '</option>';
		}
		echo '</select>';

		echo '<label for="ab_f_agent" class="ab-ui-sr">' . esc_html__('Agent', 'agent-brain') . '</label><select id="ab_f_agent" name="agent_id"><option value="0">' . esc_html__('Every agent', 'agent-brain') . '</option>';
		foreach (self::agentOptions() as $id => $label) {
			echo '<option value="' . esc_attr((string) $id) . '"' . selected((int) $f['agent_id'], $id, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select>';

		echo '<button type="submit" class="button">' . esc_html__('Search', 'agent-brain') . '</button>';
		if ($args !== [] || $f['type'] !== '') {
			echo '<a class="button-link" href="' . esc_url(self::url([])) . '">' . esc_html__('Clear', 'agent-brain') . '</a>';
		}

		echo '<details' . ($more ? ' open' : '') . '><summary>' . esc_html__('More filters', 'agent-brain') . '</summary><p>';
		echo '<label for="ab_f_type">' . esc_html__('Type', 'agent-brain') . '</label> <select id="ab_f_type" name="type"><option value="">' . esc_html__('Every type', 'agent-brain') . '</option>';
		foreach (Filters::TYPES as $type) {
			echo '<option value="' . esc_attr($type) . '"' . selected($f['type'], $type, false) . '>' . esc_html(self::typeWord($type, true)) . '</option>';
		}
		echo '</select> ';

		echo '<label for="ab_f_status">' . esc_html__('Status', 'agent-brain') . '</label> <select id="ab_f_status" name="status"><option value="">' . esc_html__('Every status', 'agent-brain') . '</option>';
		foreach (Filters::STATUSES as $status) {
			echo '<option value="' . esc_attr($status) . '"' . selected($f['status'], $status, false) . '>' . esc_html(self::statusWord($status)) . '</option>';
		}
		echo '</select></p><p>';

		echo '<label for="ab_f_imp_min">' . esc_html__('Importance', 'agent-brain') . '</label> <input type="number" min="0" max="100" id="ab_f_imp_min" name="imp_min" value="' . esc_attr((string) $f['imp_min']) . '" class="small-text" /> '
			. '<label for="ab_f_imp_max">' . esc_html__('to', 'agent-brain') . '</label> <input type="number" min="0" max="100" id="ab_f_imp_max" name="imp_max" value="' . esc_attr((string) $f['imp_max']) . '" class="small-text" /> ';
		echo '<label for="ab_f_date_from">' . esc_html__('From', 'agent-brain') . '</label> <input type="date" id="ab_f_date_from" name="date_from" value="' . esc_attr((string) $f['date_from']) . '" /> '
			. '<label for="ab_f_date_to">' . esc_html__('To', 'agent-brain') . '</label> <input type="date" id="ab_f_date_to" name="date_to" value="' . esc_attr((string) $f['date_to']) . '" />';
		echo '</p></details></form>';
	}

	/**
	 * @param array<string, mixed> $f
	 * @return array<string, string>
	 */
	private static function activeQueryArgs(array $f): array {
		$args = [];
		$default = Filters::fromArray([]);

		foreach ($f as $key => $value) {
			if ($value !== $default[$key]) {
				$args[$key] = (string) $value;
			}
		}

		return $args;
	}

	// ---------------------------------------------------------------
	// Detail
	// ---------------------------------------------------------------

	/**
	 * The selected memory: its text, who remembered it and when, the
	 * facts about it, then Edit (folded) and Delete.
	 */
	private static function detailHtml(int $id, string $back): string {
		$row = Store::findFor(get_current_user_id(), $id);

		if ($row === null) {
			return Ui::detail('', '<p class="ab-empty">' . esc_html__('That memory no longer exists.', 'agent-brain') . '</p>', '', $back);
		}

		$scopeId = (int) $row['scope_id'];
		$agentId = (int) $row['agent_id'];
		$scope   = self::scopeNames()[$scopeId] ?? ('#' . $scopeId);
		$who     = InboxItems::who($agentId);
		$client  = (string) ($row['client'] ?? '');
		$sources = (new OutsideSources())->sourcesOf('memory', $id);
		$fqdn    = $agentId > 0 ? (string) get_user_meta($agentId, AgentMeta::FQDN, true) : '';
		$account = $fqdn !== '' ? $fqdn : (string) $who['account'];

		$body  = '<div class="ab-ui-text">' . esc_html((string) $row['content']) . '</div>';
		$body .= '<div class="ab-ui-who"><span class="ab-ui-avatar" aria-hidden="true">' . esc_html(strtoupper(mb_substr($who['name'], 0, 1))) . '</span><div>'
			. sprintf(
				/* translators: 1: agent, 2: date and time */
				esc_html__('Remembered by %1$s, %2$s', 'agent-brain'),
				'<strong>' . esc_html($who['label']) . '</strong>',
				DateFormat::html((string) $row['created'])
			)
			. ($agentId > 0 && get_userdata($agentId) instanceof \WP_User ? ' · <a href="' . esc_url(AgentViewPage::urlFor($agentId)) . '">' . esc_html__('Agent View', 'agent-brain') . '</a>' : '')
			. ($account !== '' ? '<br><span class="ab-ui-account">' . esc_html($account) . '</span>' : '')
			. '</div></div>';

		$body .= Ui::facts([
			__('Scope', 'agent-brain')              => esc_html($scope),
			__('Importance', 'agent-brain')         => esc_html(self::importanceWords((int) $row['importance'])),
			__('Status', 'agent-brain')             => esc_html(self::statusWord((string) $row['status'])),
			__('Expires', 'agent-brain')            => DateFormat::html((string) ($row['expires'] ?? ''), __('Never', 'agent-brain')),
			__('Client', 'agent-brain')             => esc_html($client !== '' ? $client : __('(none recorded)', 'agent-brain')),
			__('Where it came from', 'agent-brain') => '<a href="' . esc_url(\AgentBrain\Modules\Provenance\Panel::url('memory', $id)) . '">' . esc_html__('Trace it in Provenance', 'agent-brain') . '</a>',
		]);

		// PROV-3 (#172): where this row came from outside the brain — small
		// reference records, never copies of the source content.
		$body .= '<p class="ab-ui-kicker" style="margin-top:16px">' . esc_html__('Outside sources', 'agent-brain') . '</p>';
		if ($sources === []) {
			$body .= '<p>' . esc_html__('(none recorded)', 'agent-brain') . '</p>';
		} else {
			$body .= '<ul style="margin:0">';
			foreach ($sources as $source) {
				$meta = [];
				if ((string) ($source['publisher'] ?? '') !== '') {
					$meta[] = (string) $source['publisher'];
				}
				if ((string) ($source['retrieved_at'] ?? '') !== '') {
					$meta[] = \AgentBrain\Modules\Mcp\Present::iso((string) $source['retrieved_at']);
				}
				if ((string) ($source['hash'] ?? '') !== '') {
					$meta[] = 'sha256:' . substr((string) $source['hash'], 0, 12) . '…';
				}
				$body .= '<li><strong>' . esc_html((string) $source['kind']) . '</strong> ' . esc_html((string) $source['title'])
					. ' — <code>' . esc_html((string) $source['locator']) . '</code>'
					. ($meta === [] ? '' : ' (' . esc_html(implode(', ', $meta)) . ')') . '</li>';
			}
			$body .= '</ul>';
		}

		$sourceLines = array_map([self::class, 'sourceLine'], $sources);
		$body .= '<details><summary>' . esc_html__('Edit importance, search keys and sources', 'agent-brain') . '</summary>'
			. '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::UPDATE_ACTION . '_' . $id)) . '" />'
			. '<input type="hidden" name="action" value="' . esc_attr(self::UPDATE_ACTION) . '" />'
			. '<input type="hidden" name="memory_id" value="' . esc_attr((string) $id) . '" />'
			. '<p><label for="ab_memory_importance">' . esc_html__('Importance (0-100)', 'agent-brain') . '</label><br><input type="number" min="0" max="100" id="ab_memory_importance" name="importance" value="' . esc_attr((string) (int) $row['importance']) . '" class="small-text" /></p>'
			. '<p><label for="ab_memory_keys">' . esc_html__('Search keys', 'agent-brain') . '</label><br><input type="text" id="ab_memory_keys" name="search_keys" value="' . esc_attr((string) ($row['search_keys'] ?? '')) . '" class="large-text" maxlength="500" /></p>'
			. '<p><label for="ab_memory_sources">' . esc_html__('Outside sources', 'agent-brain') . '</label><br><textarea id="ab_memory_sources" name="sources" rows="' . esc_attr((string) max(3, count($sourceLines) + 1)) . '" class="large-text">'
			. esc_textarea(implode("\n", $sourceLines))
			. '</textarea></p>'
			. '<p class="description">' . esc_html__('One per line: kind | title | locator | publisher | retrieved-at | hash. kind is url, document, meeting, conversation or other; publisher, retrieved-at and hash may be left empty. Records are shared: a line matching an existing source reuses it.', 'agent-brain') . '</p>'
			. '<p><button type="submit" class="button button-primary">' . esc_html__('Save changes', 'agent-brain') . '</button></p>'
			. '</form></details>';

		$actions = '<span class="ab-ui-spacer"></span>'
			. '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . Confirm::attr(Confirm::message(__('Delete this memory and its provenance links', 'agent-brain'))) . '>'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::DELETE_ACTION . '_' . $id)) . '" />'
			. '<input type="hidden" name="action" value="' . esc_attr(self::DELETE_ACTION) . '" />'
			. '<input type="hidden" name="memory_id" value="' . esc_attr((string) $id) . '" />'
			. '<button type="submit" class="button button-link-delete">' . esc_html__('Delete memory', 'agent-brain') . '</button></form>';

		return Ui::detail(
			/* translators: 1: memory type, 2: scope, 3: memory id */
			sprintf(__('%1$s · %2$s · #%3$d', 'agent-brain'), self::typeWord((string) $row['type']), $scope, $id),
			$body,
			$actions,
			$back
		);
	}

	private static function typeWord(string $type, bool $plural = false): string {
		switch ($type) {
			case 'episode':
				return $plural ? __('Episodes', 'agent-brain') : __('Episode', 'agent-brain');
			case 'fact':
				return $plural ? __('Facts', 'agent-brain') : __('Fact', 'agent-brain');
			case 'working':
				return $plural ? __('Working notes', 'agent-brain') : __('Working note', 'agent-brain');
		}

		return $type;
	}

	private static function statusWord(string $status): string {
		switch ($status) {
			case 'active':
				return __('Active', 'agent-brain');
			case 'rolled_up':
				return __('Summarised', 'agent-brain');
			case 'proposed':
				return __('Waiting in Review', 'agent-brain');
			case 'deleted':
				return __('Deleted', 'agent-brain');
		}

		return $status;
	}

	/**
	 * "Medium (60)": importance as people say it, with the number.
	 */
	public static function importanceWords(int $importance): string {
		$word = $importance >= 70 ? __('High', 'agent-brain') : ($importance >= 30 ? __('Medium', 'agent-brain') : __('Low', 'agent-brain'));

		/* translators: 1: Low, Medium or High, 2: importance from 0 to 100 */
		return sprintf(__('%1$s (%2$d)', 'agent-brain'), $word, $importance);
	}

	/**
	 * "35 min ago", with the exact time in ISO 8601 on hover.
	 */
	private static function ago(string $created): string {
		$ts = strtotime($created . ' UTC');
		if ($ts === false) {
			return '';
		}
		$iso = gmdate('Y-m-d\\TH:i:s\\Z', $ts);

		/* translators: %s: a time span such as "35 mins" */
		return '<time datetime="' . esc_attr($iso) . '" title="' . esc_attr($iso) . '">' . esc_html(sprintf(__('%s ago', 'agent-brain'), human_time_diff($ts, time()))) . '</time>';
	}

	// ---------------------------------------------------------------
	// Actions
	// ---------------------------------------------------------------

	public static function handleUpdate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside processUpdate().
		$url = self::processUpdate((array) wp_unslash($_POST));
		wp_safe_redirect($url);
		exit;
	}

	public static function handleDelete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside processDelete().
		$url = self::processDelete((array) wp_unslash($_POST));
		wp_safe_redirect($url);
		exit;
	}

	/**
	 * @param array<string, mixed> $post
	 * @return string Redirect URL.
	 */
	public static function processUpdate(array $post): string {
		$id = isset($post['memory_id']) ? absint($post['memory_id']) : 0;
		self::authorise($post, self::UPDATE_ACTION . '_' . $id);

		$importance = isset($post['importance']) && is_scalar($post['importance']) ? (string) $post['importance'] : '';
		$keys       = isset($post['search_keys']) && is_string($post['search_keys']) ? trim(sanitize_text_field($post['search_keys'])) : '';
		$keys       = substr($keys, 0, 500);

		if (! preg_match('/^\d{1,3}$/', $importance) || (int) $importance > 100) {
			return self::url(['memory' => $id, 'ab_notice' => 'invalid']);
		}

		$before = Store::findFor(get_current_user_id(), $id);
		if ($before === null) {
			self::audit('memory.admin_update', 'error', $id, []);

			return self::url(['memory' => $id, 'ab_notice' => 'error']);
		}

		// PROV-3 (#172): replace the row's outside-source links with exactly
		// the parsed lines (registering new records against the row's agent).
		$rawSources = isset($post['sources']) && is_string($post['sources']) ? trim($post['sources']) : '';
		$sourceIds  = null;
		if ($rawSources !== '') {
			try {
				$sourceIds = self::parseSources($rawSources, (int) $before['agent_id']);
			} catch (\InvalidArgumentException $e) {
				return self::url(['memory' => $id, 'ab_notice' => 'invalid_sources']);
			}
		}

		$ok = Store::updateMeta($id, (int) $importance, $keys);
		if ($ok && $sourceIds !== null) {
			try {
				(new OutsideSources())->replace('memory', $id, $sourceIds);
			} catch (\InvalidArgumentException $e) {
				$ok = false;
			}
		}

		self::audit('memory.admin_update', $ok ? 'ok' : 'error', $id, [
			'importance_from' => (int) $before['importance'],
			'importance_to'   => (int) $importance,
			'keys_changed'    => (string) ($before['search_keys'] ?? '') !== $keys,
			'sources_changed' => $sourceIds !== null,
		]);

		return self::url(['memory' => $id, 'ab_notice' => $ok ? 'updated' : 'error']);
	}

	/**
	 * @param array<string, mixed> $post
	 * @return string Redirect URL.
	 */
	public static function processDelete(array $post): string {
		$id = isset($post['memory_id']) ? absint($post['memory_id']) : 0;
		self::authorise($post, self::DELETE_ACTION . '_' . $id);

		$row = Store::findFor(get_current_user_id(), $id);
		$ok  = $row !== null && Store::delete($id);

		self::audit('memory.admin_delete', $ok ? 'ok' : 'error', $id, $row === null ? [] : [
			'agent_id' => (int) $row['agent_id'],
			'scope_id' => (int) $row['scope_id'],
			'type'     => (string) $row['type'],
		]);

		return self::url(['ab_notice' => $ok ? 'deleted' : 'error']);
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function authorise(array $post, string $nonceAction): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to modify memories.', 'agent-brain'), 403);
		}

		$nonce = isset($post['_wpnonce']) && is_string($post['_wpnonce']) ? $post['_wpnonce'] : '';
		if (wp_verify_nonce($nonce, $nonceAction) === false) {
			wp_die(esc_html__('The link you followed has expired. Please go back and try again.', 'agent-brain'), 403);
		}
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function audit(string $event, string $status, int $id, array $meta): void {
		do_action('agent_brain/audit', [
			'actor_id'    => (int) get_current_user_id(),
			'event'       => $event,
			'status'      => $status,
			'object_type' => 'ab_memory',
			'object_id'   => (string) $id,
			'meta'        => $meta,
		]);
	}

	/**
	 * @param array<string, scalar> $args
	 */
	private static function url(array $args): string {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	private static function renderNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash code.
		$code = isset($_GET['ab_notice']) ? sanitize_key(wp_unslash($_GET['ab_notice'])) : '';

		$messages = [
			'updated'         => ['success', __('Memory updated.', 'agent-brain')],
			'deleted'         => ['success', __('Memory deleted.', 'agent-brain')],
			'invalid'         => ['error', __('Importance must be a whole number from 0 to 100.', 'agent-brain')],
			'invalid_sources' => ['error', __('Outside sources could not be saved. Use one per line: kind | title | locator | publisher | retrieved-at | hash, with kind one of url, document, meeting, conversation, other.', 'agent-brain')],
			'error'           => ['error', __('That change could not be saved.', 'agent-brain')],
		];

		if (isset($messages[$code])) {
			echo '<div class="notice notice-' . esc_attr($messages[$code][0]) . ' is-dismissible" role="' . ('error' === $messages[$code][0] ? 'alert' : 'status') . '"><p>' . esc_html($messages[$code][1]) . '</p></div>';
		}
	}

	// ---------------------------------------------------------------
	// Outside sources (PROV-3, #172)
	// ---------------------------------------------------------------

	/**
	 * Render one source record as an editable `kind | title | locator |
	 * publisher | retrieved-at | hash` line (trailing empty fields dropped).
	 *
	 * @param array<string, mixed> $source
	 */
	private static function sourceLine(array $source): string {
		$fields = [
			(string) $source['kind'],
			(string) $source['title'],
			(string) $source['locator'],
			(string) ($source['publisher'] ?? ''),
			(string) ($source['retrieved_at'] ?? '') !== '' ? \AgentBrain\Modules\Mcp\Present::iso((string) $source['retrieved_at']) : '',
			(string) ($source['hash'] ?? ''),
		];

		while ($fields !== [] && end($fields) === '') {
			array_pop($fields);
		}

		return implode(' | ', $fields);
	}

	/**
	 * Parse the textarea's `kind | title | locator | publisher |
	 * retrieved-at | hash` lines and register each source (idempotently)
	 * against $agentId. Blank lines are skipped; anything unparseable is a
	 * fail-loud InvalidArgumentException, not a silent drop.
	 *
	 * @return int[] Source ids in line order.
	 */
	private static function parseSources(string $raw, int $agentId): array {
		$repo = new OutsideSources();
		$ids  = [];

		foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}

			$parts = array_map('trim', explode('|', $line, 6));
			while ($parts !== [] && end($parts) === '') {
				array_pop($parts);
			}
			if (count($parts) < 3) {
				throw new \InvalidArgumentException('source line needs at least kind | title | locator');
			}

			$ids[] = $repo->register([
				'kind'         => $parts[0],
				'title'        => $parts[1],
				'locator'      => $parts[2],
				'publisher'    => $parts[3] ?? null,
				'retrieved_at' => $parts[4] ?? null,
				'hash'         => $parts[5] ?? null,
				'agent_id'     => $agentId,
			]);
		}

		return $ids;
	}

	// ---------------------------------------------------------------
	// Lookups
	// ---------------------------------------------------------------

	/**
	 * @return array<int, string>
	 */
	private static function scopeNames(): array {
		$terms = get_terms(['taxonomy' => Scopes::TAXONOMY, 'hide_empty' => false]);
		$names = [];

		if (is_array($terms)) {
			foreach ($terms as $term) {
				if ($term instanceof \WP_Term) {
					$names[(int) $term->term_id] = $term->name;
				}
			}
		}

		return $names;
	}

	/**
	 * @return array<int, string>
	 */
	private static function agentOptions(): array {
		$out = [];

		foreach (get_users(['role' => Capabilities::ROLE_AGENT, 'orderby' => 'display_name']) as $user) {
			$out[(int) $user->ID] = $user->display_name . ' (' . $user->user_login . ')';
		}

		return $out;
	}
}
