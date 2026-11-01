<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Audit;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Admin\DateFormat;
use AgentBrain\Modules\Admin\EventLabels;
use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Identity\AgentHumans;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\JobsAdmin\Runs;
use AgentBrain\Modules\MemoriesAdmin\Store as MemoriesStore;
use AgentBrain\Modules\Review\InboxItems;

/**
 * Curator-facing audit log viewer: `manage_options`-style admin page gated
 * on the `ab_view_audit` capability, listing recent `ab_audit` rows with
 * paging and whitelisted, prepared-SQL filters. Since #751 the rows read as
 * sentences grouped by day, chips pick problems, approvals or access
 * changes, and the selected event shows its reason and raw row beside them. Read-only GET listing — no
 * state change, so no nonce is required for the pagination/filter
 * parameters (WordPress core list tables follow the same pattern for
 * read-only screens).
 */
final class ViewerPage {

	public const CAPABILITY = 'ab_view_audit';
	public const SLUG       = 'agent-brain-audit';
	public const PER_PAGE   = 20;

	public const STATUS_ALL = 'all';

	public static function register(): void {
		add_action('admin_menu', [self::class, 'addMenu']);
	}

	public static function addMenu(): void {
		add_submenu_page(
			Menu::PARENT,
			__('Audit Log', 'agent-brain'),
			__('Audit Log', 'agent-brain'),
			self::CAPABILITY,
			self::SLUG,
			[self::class, 'render']
		);
	}

	/** Chips above the list (#751): everything, or one kind of event. */
	public const VIEWS = ['', 'problems', 'approvals', 'access'];

	/** Time windows for the When filter. */
	public const WHEN = ['', 'today', 'week', 'month'];

	/** Events the Approvals chip shows. */
	private const APPROVAL_EVENTS = [
		'knowledge_review', 'artifact_section_review', 'artifact_publish', 'artifact_unpublish', 'artifact_html_approved',
		'review.memory_approved', 'review.memory_rejected', 'share_link_approved', 'handoff_expired', 'agent.decline',
	];

	/** Events the Access changes chip shows. */
	private const ACCESS_EVENTS = [
		'scope_grant', 'scope_revoke', 'approval_grant', 'approval_revoke', 'grant.removed', 'agent.status', 'agent.issue',
		'agent.create', 'agent.register', 'agent.revoke_app_passwords', 'agent.registration_create', 'agent.registration_revoke',
		'app_password_create', 'app_password_delete', 'oauth.authorize', 'oauth.client_revoke', 'oauth.revoke',
		'share_link_minted', 'share_link_revoked', 'principal.bind', 'agent.switch', 'agent.forbidden_caps_removed', 'oauth.grant',
		'scope.created', 'scope.deleted',
	];

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to view the audit log.', 'agent-brain'));
		}

		$filters = self::readFilters();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no state change.
		$paged  = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector, no state change.
		$itemId = isset($_GET['item']) ? absint(wp_unslash($_GET['item'])) : 0;
		$offset = ($paged - 1) * self::PER_PAGE;

		$rows  = self::filteredRows($filters, self::PER_PAGE, $offset);
		$total = self::filteredCount($filters);
		$pages = (int) max(1, ceil($total / self::PER_PAGE));

		$today    = self::filteredCount(self::blankFilters(['when' => 'today']));
		$problems = self::filteredCount(self::blankFilters(['when' => 'today', 'view' => 'problems']));

		// #751: how today went, the kinds of events as chips, then the
		// events as sentences by day with the selected one beside them.
		$head = Ui::head(
			__('Audit Log', 'agent-brain'),
			sprintf(
				/* translators: 1: events today, 2: problems today */
				__('%1$s events today · %2$s problems', 'agent-brain'),
				'<b>' . esc_html(number_format_i18n($today)) . '</b>',
				'<b>' . esc_html(number_format_i18n($problems)) . '</b>'
			),
			'',
			'<p>' . esc_html__('Everything that happened in the brain, newest first: every tool an AI used, plus reviews, access changes, sharing, settings and erasures. Each event says who did it and whether it worked. It never holds the content or the arguments, only a keyed hash of them.', 'agent-brain') . '</p>'
				. '<p>' . esc_html__('Problems are events that were denied or failed; their reason is in the detail. Events are kept for 90 days.', 'agent-brain') . '</p>'
		);
		echo '<div class="wrap ab-admin ab-admin-audit">' . $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		self::renderFilterForm($filters);

		if ($rows === [] && $itemId === 0) {
			echo '<p class="ab-empty">' . esc_html__('No audit events match these filters.', 'agent-brain') . '</p></div>';

			return;
		}

		$listArgs = self::queryArgs($filters) + ($paged > 1 ? ['paged' => (string) $paged] : []);
		$selected = $itemId > 0 ? $itemId : (int) $rows[0]['id'];

		$list = '';
		$day  = '';
		foreach ($rows as $row) {
			$ts      = DateFormat::timestamp((string) ($row['ts'] ?? ''));
			$thisDay = $ts !== null ? wp_date('Y-m-d', $ts) : '';
			if ($thisDay !== $day) {
				$day   = (string) $thisDay;
				$list .= '<li class="ab-ui-list-group">' . esc_html(self::dayLabel($ts)) . '</li>';
			}

			$id      = (int) $row['id'];
			$status  = (string) ($row['status'] ?? '');
			$problem = $status !== Repository::STATUS_OK;
			// The sentence already names the ability, so only other objects go in the meta.
			$on      = (string) ($row['object_type'] ?? '') === 'ability' ? "\u{2014}" : self::rowObjectLabel($row);
			$meta    = ($problem ? Ui::tag($status === Repository::STATUS_DENIED ? __('Denied', 'agent-brain') : __('Failed', 'agent-brain'), 'bad') . ' ' : '')
				. ($on !== "\u{2014}" ? esc_html($on) . ' · ' : '')
				. esc_html($ts !== null ? (string) wp_date((string) get_option('time_format'), $ts) : (string) ($row['ts'] ?? ''));
			$list .= Ui::row(self::url($listArgs + ['item' => (string) $id]), self::sentence($row), $meta, $id === $selected);
		}

		$split = Ui::split(
			__('Audit events', 'agent-brain'),
			$list,
			self::detailHtml($selected, self::url($listArgs)),
			$itemId > 0,
			self::pagination($filters, $paged, $pages)
		);
		echo $split . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
	}

	/**
	 * "Who did what", the list's line for one event.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function sentence(array $row): string {
		$label = EventLabels::friendly((string) ($row['event'] ?? ''), (string) ($row['object_id'] ?? ''));
		$actor = (int) ($row['actor_id'] ?? 0);
		$who   = $actor === 0 ? __('The brain', 'agent-brain') : InboxItems::who($actor)['label'];

		// friendly() capitalises the label; an acronym ("MCP …") keeps its case.
		$verb = preg_match('/^[A-Z][A-Z]/', $label) === 1 ? $label : lcfirst($label);

		return $who . ' ' . $verb;
	}

	private static function dayLabel(?int $ts): string {
		if ($ts === null) {
			return __('Unknown date', 'agent-brain');
		}

		$day = wp_date('Y-m-d', $ts);
		if ($day === wp_date('Y-m-d')) {
			return __('Today', 'agent-brain');
		}
		if ($day === wp_date('Y-m-d', time() - DAY_IN_SECONDS)) {
			return __('Yesterday', 'agent-brain');
		}

		return (string) wp_date((string) get_option('date_format'), $ts);
	}

	private static function detailHtml(int $id, string $back): string {
		$rows = Db::getResults(
			'SELECT id, ts, actor_id, event, status, object_type, object_id, args_hash, meta, duration_ms FROM ' . Repository::tableName() . ' WHERE id = %d',
			[$id]
		);
		if ($rows === []) {
			return Ui::detail('', '<p class="ab-empty">' . esc_html__('That event is no longer in the log.', 'agent-brain') . '</p>', '', $back);
		}

		$row     = $rows[0];
		$event   = (string) ($row['event'] ?? '');
		$status  = (string) ($row['status'] ?? '');
		$problem = $status !== Repository::STATUS_OK;
		$label   = EventLabels::friendly($event, (string) ($row['object_id'] ?? ''));
		$body    = '<h2>' . esc_html(self::sentence($row)) . '</h2>';

		if ($problem) {
			$word  = $status === Repository::STATUS_DENIED ? __('It was denied.', 'agent-brain') : __('It failed.', 'agent-brain');
			$body .= Ui::callout('bad', $word, self::reasonText($row));
		}
		if ($event === 'compression.job_failed') {
			$body .= Ui::callout(
				'warn',
				__('Summarising could not run.', 'agent-brain'),
				__('Raw memories stay uncompressed until it succeeds. Usually the AI provider or the cloud connection needs attention.', 'agent-brain'),
				'<a class="button button-small" href="' . esc_url(SettingsHub::url(SettingsHub::TAB_CLOUD)) . '">' . esc_html__('Open Settings → Summarising', 'agent-brain') . '</a>'
			);
		}

		$ts    = (string) ($row['ts'] ?? '');
		$facts = [
			__('Who', 'agent-brain')  => self::renderActor($row['actor_id'] ?? null),
			__('When', 'agent-brain') => self::renderTime($ts),
			__('On', 'agent-brain')   => esc_html(self::rowObjectLabel($row)),
			__('What', 'agent-brain') => '<strong>' . esc_html($label) . '</strong> <code>' . esc_html($event) . '</code>',
		];
		if ((string) ($row['duration_ms'] ?? '') !== '') {
			$facts[__('Took', 'agent-brain')] = esc_html(sprintf(
				/* translators: %s: milliseconds */
				__('%s ms', 'agent-brain'),
				number_format_i18n((int) $row['duration_ms'])
			));
		}
		$body .= Ui::facts($facts);
		$body .= self::renderDetails($row);

		return Ui::detail(
			($problem ? __('Problem', 'agent-brain') : __('Event', 'agent-brain')) . ' · #' . (int) $row['id'],
			$body,
			'',
			$back
		);
	}

	/**
	 * The recorded reason in plain words, or a general line.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function reasonText(array $row): string {
		$meta = isset($row['meta']) && is_string($row['meta']) ? json_decode($row['meta'], true) : null;
		if (is_array($meta)) {
			foreach (['reason', 'note', 'warning'] as $key) {
				if (isset($meta[$key]) && is_scalar($meta[$key]) && (string) $meta[$key] !== '') {
					return self::statusReason((string) $meta[$key]);
				}
			}
		}

		return __('No reason was recorded. Nothing was changed.', 'agent-brain');
	}

	/**
	 * @param array{actor: int|null, event: string, status: string, from: string, to: string, view: string, when: string} $filters
	 */
	private static function renderChips(array $filters): void {
		$base     = array_merge($filters, ['view' => '']);
		$problems = self::filteredCount(array_merge($filters, ['view' => 'problems']));
		$chips    = [];
		foreach ([
			''          => __('Everything', 'agent-brain'),
			'problems'  => __('Problems', 'agent-brain'),
			'approvals' => __('Approvals', 'agent-brain'),
			'access'    => __('Access changes', 'agent-brain'),
		] as $view => $label) {
			$chip = [
				'label'   => $label,
				'url'     => self::url(self::queryArgs(array_merge($base, ['view' => $view]))),
				'current' => $filters['view'] === $view,
			];
			if ($view === 'problems') {
				$chip['count'] = $problems;
			}
			$chips[] = $chip;
		}

		echo Ui::chips($chips, __('Kind of event', 'agent-brain')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array{actor: int|null, event: string, status: string, from: string, to: string, view: string, when: string}
	 */
	private static function blankFilters(array $overrides = []): array {
		/** @var array{actor: int|null, event: string, status: string, from: string, to: string, view: string, when: string} */
		return array_merge(['actor' => null, 'event' => '', 'status' => self::STATUS_ALL, 'from' => '', 'to' => '', 'view' => '', 'when' => ''], $overrides);
	}

	/**
	 * @param array{actor: int|null, event: string, status: string, from: string, to: string, view: string, when: string} $filters
	 * @return array<string, string>
	 */
	private static function queryArgs(array $filters): array {
		return array_filter([
			'actor'  => $filters['actor'] !== null ? (string) $filters['actor'] : '',
			'event'  => $filters['event'],
			'status' => $filters['status'] !== self::STATUS_ALL ? $filters['status'] : '',
			'from'   => $filters['from'],
			'to'     => $filters['to'],
			'view'   => $filters['view'],
			'when'   => $filters['when'],
		], static fn (string $value): bool => $value !== '');
	}

	/**
	 * @param array<string, string> $args
	 */
	private static function url(array $args): string {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	/**
	 * @return array{actor: int|null, event: string, status: string, from: string, to: string, view: string, when: string}
	 */
	private static function readFilters(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filtering, no state change.
		$get = wp_unslash($_GET);

		$actor = isset($get['actor']) && $get['actor'] !== '' ? absint($get['actor']) : null;

		$event = isset($get['event']) ? sanitize_text_field((string) $get['event']) : '';

		$status = isset($get['status']) ? sanitize_key((string) $get['status']) : self::STATUS_ALL;
		if (! in_array($status, [self::STATUS_ALL, Repository::STATUS_OK, Repository::STATUS_DENIED, Repository::STATUS_ERROR], true)) {
			$status = self::STATUS_ALL;
		}

		$from = isset($get['from']) ? sanitize_text_field((string) $get['from']) : '';
		$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? $from : '';

		$to = isset($get['to']) ? sanitize_text_field((string) $get['to']) : '';
		$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 ? $to : '';

		$view = isset($get['view']) ? sanitize_key((string) $get['view']) : '';
		$when = isset($get['when']) ? sanitize_key((string) $get['when']) : '';

		return [
			'actor'  => $actor,
			'event'  => $event,
			'status' => $status,
			'from'   => $from,
			'to'     => $to,
			'view'   => in_array($view, self::VIEWS, true) ? $view : '',
			'when'   => in_array($when, self::WHEN, true) ? $when : '',
		];
	}

	/**
	 * @param array{actor: int|null, event: string, status: string, from: string, to: string, view: string, when: string} $filters
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function whereClause(array $filters): array {
		$where  = [];
		$params = [];

		// Every value below is bound through Db::prepare() via %d/%s
		// placeholders — never concatenated into the SQL string, including
		// values that don't match the whitelist dropdowns rendered in the
		// filter form (an unlisted/injection-shaped event value is simply a
		// literal string parameter that matches zero rows, never SQL).
		if ($filters['actor'] !== null) {
			$where[]  = 'actor_id = %d';
			$params[] = $filters['actor'];
		}

		if ($filters['event'] !== '') {
			$where[]  = 'event = %s';
			$params[] = $filters['event'];
		}

		if ($filters['status'] !== self::STATUS_ALL) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}

		if ($filters['from'] !== '') {
			$where[]  = 'ts >= %s';
			$params[] = $filters['from'] . ' 00:00:00';
		}

		if ($filters['to'] !== '') {
			$where[]  = 'ts <= %s';
			$params[] = $filters['to'] . ' 23:59:59';
		}

		if ($filters['view'] === 'problems') {
			$where[]  = 'status <> %s';
			$params[] = Repository::STATUS_OK;
		} elseif ($filters['view'] === 'approvals' || $filters['view'] === 'access') {
			$events  = $filters['view'] === 'approvals' ? self::APPROVAL_EVENTS : self::ACCESS_EVENTS;
			$where[] = 'event IN (' . implode(', ', array_fill(0, count($events), '%s')) . ')';
			$params  = array_merge($params, $events);
		}

		$since = self::since($filters['when']);
		if ($since !== '') {
			$where[]  = 'ts >= %s';
			$params[] = $since;
		}

		$sql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

		return [$sql, $params];
	}

	/** The UTC start of a When window, or '' for all time. */
	private static function since(string $when): string {
		switch ($when) {
			case 'today':
				return get_gmt_from_date((string) wp_date('Y-m-d 00:00:00'));
			case 'week':
				return gmdate('Y-m-d H:i:s', time() - WEEK_IN_SECONDS);
			case 'month':
				return gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
			default:
				return '';
		}
	}

	/**
	 * @param array{actor: int|null, event: string, status: string, from: string, to: string, view: string, when: string} $filters
	 * @return array<int, array<string, mixed>>
	 */
	private static function filteredRows(array $filters, int $limit, int $offset): array {
		[$whereSql, $params] = self::whereClause($filters);

		$sql = 'SELECT id, ts, actor_id, event, status, object_type, object_id, args_hash, meta, duration_ms FROM '
			. Repository::tableName() . $whereSql . ' ORDER BY ts DESC, id DESC LIMIT %d OFFSET %d';

		$params[] = max(1, min(200, $limit));
		$params[] = max(0, $offset);

		return Db::getResults($sql, $params);
	}

	/**
	 * @param array{actor: int|null, event: string, status: string, from: string, to: string, view: string, when: string} $filters
	 */
	private static function filteredCount(array $filters): int {
		[$whereSql, $params] = self::whereClause($filters);

		$sql  = 'SELECT COUNT(*) AS c FROM ' . Repository::tableName() . $whereSql;
		$rows = $params !== [] ? Db::getResults($sql, $params) : Db::getResults($sql);

		return isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
	}

	/**
	 * @return array<int, int>
	 */
	private static function distinctActors(): array {
		$rows = Db::getResults('SELECT DISTINCT actor_id FROM ' . Repository::tableName() . ' ORDER BY actor_id ASC');

		return array_map(static fn (array $row): int => (int) $row['actor_id'], $rows);
	}

	/**
	 * @return array<int, string>
	 */
	private static function distinctEvents(): array {
		$rows = Db::getResults('SELECT DISTINCT event FROM ' . Repository::tableName() . ' ORDER BY event ASC');

		return array_map(static fn (array $row): string => (string) $row['event'], $rows);
	}

	/**
	 * @param array{actor: int|null, event: string, status: string, from: string, to: string, view: string, when: string} $filters
	 */
	private static function renderFilterForm(array $filters): void {
		echo '<form method="get" class="ab-admin-audit-filters ab-ui-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />';
		if ($filters['view'] !== '') {
			echo '<input type="hidden" name="view" value="' . esc_attr($filters['view']) . '" />';
		}
		self::renderChips($filters);

		echo '<label class="screen-reader-text" for="ab-audit-actor">' . esc_html__('Who', 'agent-brain') . '</label>';
		echo '<select name="actor" id="ab-audit-actor"><option value="">' . esc_html__('Everyone', 'agent-brain') . '</option>';
		foreach (self::distinctActors() as $actorId) {
			$label = $actorId === 0 ? __('System', 'agent-brain') : self::actorLabel($actorId);
			echo '<option value="' . esc_attr((string) $actorId) . '"'
				. selected($filters['actor'], $actorId, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select> ';

		$windows = [
			''      => __('Any time', 'agent-brain'),
			'today' => __('Today', 'agent-brain'),
			'week'  => __('Last 7 days', 'agent-brain'),
			'month' => __('Last 30 days', 'agent-brain'),
		];
		echo '<label class="screen-reader-text" for="ab-audit-when">' . esc_html__('When', 'agent-brain') . '</label>';
		echo '<select name="when" id="ab-audit-when">';
		foreach ($windows as $value => $label) {
			echo '<option value="' . esc_attr($value) . '"' . selected($filters['when'], $value, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select> ';
		submit_button(__('Filter', 'agent-brain'), 'secondary', '', false);

		$open = $filters['event'] !== '' || $filters['status'] !== self::STATUS_ALL || $filters['from'] !== '' || $filters['to'] !== '';
		echo '<details class="ab-audit-more"' . ($open ? ' open' : '') . '><summary>' . esc_html__('More filters', 'agent-brain') . '</summary><p>';

		echo '<label class="screen-reader-text" for="ab-audit-event">' . esc_html__('Event', 'agent-brain') . '</label>';
		echo '<select name="event" id="ab-audit-event"><option value="">' . esc_html__('All events', 'agent-brain') . '</option>';
		foreach (self::distinctEvents() as $event) {
			echo '<option value="' . esc_attr($event) . '"'
				. selected($filters['event'], $event, false) . '>' . esc_html(EventLabels::friendly($event, '') . ' (' . $event . ')') . '</option>';
		}
		echo '</select> ';

		$statuses = [
			self::STATUS_ALL          => __('All statuses', 'agent-brain'),
			Repository::STATUS_OK     => __('OK', 'agent-brain'),
			Repository::STATUS_DENIED => __('Denied', 'agent-brain'),
			Repository::STATUS_ERROR  => __('Error', 'agent-brain'),
		];
		echo '<label class="screen-reader-text" for="ab-audit-status">' . esc_html__('Status', 'agent-brain') . '</label>';
		echo '<select name="status" id="ab-audit-status">';
		foreach ($statuses as $value => $label) {
			echo '<option value="' . esc_attr($value) . '"' . selected($filters['status'], $value, false) . '>' . esc_html($label) . '</option>';
		}
		echo '</select> ';

		echo '<label>' . esc_html__('From', 'agent-brain')
			. ' <input type="date" name="from" value="' . esc_attr($filters['from']) . '" /></label> ';
		echo '<label>' . esc_html__('To', 'agent-brain')
			. ' <input type="date" name="to" value="' . esc_attr($filters['to']) . '" /></label></p></details>';
		echo '</form>';
	}

	private static function actorLabel(int $actorId): string {
		$user = get_userdata($actorId);
		if ($user !== false) {
			$fqdn = (string) get_user_meta($actorId, AgentMeta::FQDN, true);

			return $fqdn !== '' ? $fqdn : ($user->display_name !== '' ? $user->display_name : $user->user_login);
		}

		return sprintf(
			/* translators: %d: numeric user id of an account that no longer exists */
			__('Removed user (#%d)', 'agent-brain'),
			$actorId
		);
	}

	/**
	 * @param mixed $actorId
	 */
	private static function renderActor($actorId): string {
		$id = (int) $actorId;

		if ($id === 0) {
			return esc_html__('system', 'agent-brain');
		}

		$user = get_userdata($id);
		if ($user === false) {
			return '<span title="' . esc_attr((string) $id) . '">' . esc_html(sprintf(
				/* translators: %d: numeric user id */
				__('Removed user (#%d)', 'agent-brain'),
				$id
			)) . '</span>';
		}

		// Agents are shown by FQDN (when one has been issued) or login and
		// link to the Agents page; people link to their profile.
		$isAgent = in_array(Capabilities::ROLE_AGENT, (array) $user->roles, true);
		$fqdn    = (string) get_user_meta($id, AgentMeta::FQDN, true);
		$name    = $fqdn !== '' ? $fqdn : $user->user_login;
		$link    = $isAgent ? admin_url('admin.php?page=agent-brain-agents') : get_edit_user_link($id);

		$human = $isAgent ? AgentHumans::nameFor($id) : null;
		if ($human !== null) {
			/* translators: %s: person the agent works for. Tío is TomaTío's word for a person's AI agent. */
			$name .= ' · ' . sprintf(__('%s\'s Tío', 'agent-brain'), $human);
		}

		if ($link === '') {
			return '<span title="' . esc_attr($user->display_name) . '">' . esc_html($name) . '</span>';
		}

		return '<a href="' . esc_url($link) . '" title="' . esc_attr($user->display_name) . '">' . esc_html($name) . '</a>';
	}

	/**
	 * Object column text for a row. Never empty and never a raw uuid or
	 * event key: rows with no object show a dash.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function rowObjectLabel(array $row): string {
		$type = (string) ($row['object_type'] ?? '');
		$id   = (string) ($row['object_id'] ?? '');
		$meta = isset($row['meta']) && is_string($row['meta']) ? json_decode($row['meta'], true) : null;

		if ($type === 'ability' && $id !== '') {
			return ucfirst(trim(str_replace(['agent-brain/', '-', '_', '/'], ['', ' ', ' ', ' '], $id)));
		}

		if ((string) ($row['event'] ?? '') === 'compression.job_failed') {
			$short = preg_match('/^[0-9a-f]{8}-/i', $id) === 1 ? substr($id, 0, 8) : $id;

			return $short !== '' ? sprintf(
				/* translators: %s: compression job id */
				__('Compression job #%s', 'agent-brain'),
				$short
			) : __('Compression job', 'agent-brain');
		}

		$label = self::objectLabel($type, $id, is_array($meta) ? $meta : []);

		return $label !== '' ? $label : "\u{2014}";
	}

	/**
	 * Readable object label: the title for posts, the friendly name for
	 * abilities, the FQDN for agents, the name for scopes, a humanised type
	 * + id for anything else.
	 *
	 * @param array<string, mixed> $meta Decoded audit meta (optional context).
	 */
	public static function objectLabel(string $type, string $id, array $meta = []): string {
		if ($type === '' && $id === '') {
			return '';
		}

		if ($type === 'ability') {
			if ($id === '') {
				return '';
			}

			return EventLabels::friendly('ability.invoked', $id);
		}

		$typeLabels = [
			'ab_knowledge'        => __('Knowledge', 'agent-brain'),
			'ab_procedure'        => __('Procedure', 'agent-brain'),
			'ab_artifact'         => __('Artifact', 'agent-brain'),
			'ab_artifact_section' => __('Section', 'agent-brain'),
			'ab_handoff'          => __('Handoff', 'agent-brain'),
			'ab_memory'           => __('Memory', 'agent-brain'),
			'ab_forget_receipt'   => __('Forget receipt', 'agent-brain'),
			'oauth_client'        => __('AI client', 'agent-brain'),
			'cloud_job'           => __('Cloud job', 'agent-brain'),
			'cloud_connection'    => __('Cloud connection', 'agent-brain'),
			'backup_key'          => __('Backup key', 'agent-brain'),
			'backup'              => __('Backup', 'agent-brain'),
			'user'                => __('User', 'agent-brain'),
			'post'                => __('Post', 'agent-brain'),
			'application_password' => __('App password', 'agent-brain'),
			'registration'        => __('Agent registration', 'agent-brain'),
			'share_link'          => __('Share link', 'agent-brain'),
			'ab_scope'            => __('Scope', 'agent-brain'),
			'cloud_rest'          => __('Cloud request', 'agent-brain'),
		];
		$typeLabel = $typeLabels[$type] ?? ucfirst(trim(str_replace(['_', '-'], ' ', $type)));

		if ($id !== '' && ctype_digit($id)) {
			if ($type === 'user') {
				$fqdn = (string) get_user_meta((int) $id, AgentMeta::FQDN, true);

				if ($fqdn !== '') {
					return __('Agent', 'agent-brain') . ': ' . $fqdn;
				}

				return get_userdata((int) $id) !== false ? $typeLabel . ': ' . self::actorLabel((int) $id) : self::actorLabel((int) $id);
			}

			if ($type === 'ab_scope') {
				$term = get_term((int) $id, 'ab_scope');

				return $typeLabel . ': ' . ($term instanceof \WP_Term ? $term->name : '#' . $id);
			}

			if ($type === 'ab_memory') {
				$row     = MemoriesStore::findFor(get_current_user_id(), (int) $id);
				$excerpt = $row !== null ? trim((string) preg_replace('/\s+/', ' ', (string) ($row['content'] ?? ''))) : '';
				if ($excerpt !== '') {
					return $typeLabel . ': "' . (strlen($excerpt) > 60 ? substr($excerpt, 0, 57) . '...' : $excerpt) . '"';
				}

				return $typeLabel . ' #' . $id;
			}

			if ($type === 'share_link') {
				$out        = $typeLabel . ' #' . $id;
				$artifactId = isset($meta['artifact_id']) && is_numeric($meta['artifact_id']) ? (int) $meta['artifact_id'] : 0;
				$title      = $artifactId > 0 && ScopeVisibility::canSeePost(get_current_user_id(), $artifactId) ? (string) get_post_field('post_title', $artifactId) : '';

				return $title !== '' ? $out . ' of "' . $title . '"' : $out;
			}

			// #552: an item outside the viewer's scopes keeps its type and id,
			// never its title or excerpt.
			if (post_type_exists($type) && ScopeVisibility::canSeePost(get_current_user_id(), (int) $id)) {
				$title = (string) get_post_field('post_title', (int) $id);
				if ($title !== '') {
					return $typeLabel . ': ' . $title;
				}
			}
		}

		// Raw UUIDs / hashes mean nothing to a curator: show the type only.
		if ($id !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f-]{4,}$/i', $id) === 1) {
			$id = '';
		}

		return trim($typeLabel . ' ' . ($id !== '' ? ($type === '' ? $id : '#' . $id) : ''));
	}

	/**
	 * Technical fields (raw event key, args hash, duration) tucked into a
	 * row-level disclosure so the table stays readable.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function renderDetails(array $row): string {
		$html  = '<details class="ab-audit-details"><summary>' . esc_html__('Raw event', 'agent-brain') . '</summary><dl>';
		$html .= '<dt>' . esc_html__('Event key', 'agent-brain') . '</dt><dd><code>' . esc_html((string) ($row['event'] ?? '')) . '</code></dd>';
		$meta = isset($row['meta']) && is_string($row['meta']) ? json_decode($row['meta'], true) : null;
		if (is_array($meta)) {
			$reasons = ['lead_not_ab_agent' => __('The lead is not an agent account (for example a person or curator).', 'agent-brain')];
			foreach (['warning' => __('Reason', 'agent-brain'), 'reason' => __('Reason', 'agent-brain'), 'lead_agent' => __('Lead user ID', 'agent-brain')] as $key => $label) {
				if (isset($meta[$key]) && is_scalar($meta[$key])) {
					$value = (string) $meta[$key];
					$html .= '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($reasons[$value] ?? $value) . '</dd>';
				}
			}
		}
		$html .= '<dt>' . esc_html__('Args hash', 'agent-brain') . '</dt><dd>' . self::renderArgsHash((string) ($row['args_hash'] ?? '')) . '</dd>';
		$html .= '<dt>' . esc_html__('Duration (ms)', 'agent-brain') . '</dt><dd>' . esc_html((string) ($row['duration_ms'] ?? '')) . '</dd>';

		$raw = (string) wp_json_encode(
			[
				'id'          => (int) ($row['id'] ?? 0),
				'ts'          => (string) ($row['ts'] ?? ''),
				'actor_id'    => (int) ($row['actor_id'] ?? 0),
				'event'       => (string) ($row['event'] ?? ''),
				'status'      => (string) ($row['status'] ?? ''),
				'object_type' => (string) ($row['object_type'] ?? ''),
				'object_id'   => (string) ($row['object_id'] ?? ''),
				'meta'        => is_array($meta) ? $meta : null,
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return $html . '</dl><pre class="ab-audit-raw">' . esc_html($raw) . '</pre></details>';
	}

	/**
	 * Plain explanation for a recorded reason, reusing the Jobs page mapper;
	 * unknown reasons fall back to the words of the code.
	 */
	public static function statusReason(string $reason): string {
		$described = Runs::describe($reason, '');

		return $described !== $reason ? $described : str_replace('_', ' ', $reason);
	}

	private static function renderTime(string $ts): string {
		if ($ts === '') {
			return '';
		}

		if (DateFormat::timestamp($ts) === null) {
			return esc_html($ts);
		}

		return DateFormat::html($ts);
	}

	private static function renderArgsHash(string $hash): string {
		if ($hash === '') {
			return '';
		}

		$short = substr($hash, 0, 10);

		return '<code title="' . esc_attr($hash) . '">' . esc_html($short) . '</code>';
	}

	/**
	 * @param array{actor: int|null, event: string, status: string, from: string, to: string, view: string, when: string} $filters
	 */
	private static function pagination(array $filters, int $paged, int $pages): string {
		if ($pages <= 1) {
			return '';
		}

		$links = paginate_links([
			'base'      => add_query_arg(array_merge(['page' => self::SLUG], self::queryArgs($filters), ['paged' => '%#%']), admin_url('admin.php')),
			'format'    => '',
			'current'   => $paged,
			'total'     => $pages,
			'prev_next' => true,
			'mid_size'  => 1,
		]);

		return $links ? '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>' : '';
	}
}
