<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Core\Db;
use AgentBrain\Modules\AgentId\AgentIssuer;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\AgentRegistration\AgentActions;
use AgentBrain\Modules\AgentView\Page as AgentViewPage;
use AgentBrain\Modules\Audit\Repository;
use AgentBrain\Modules\Identity\AppPasswords;
use AgentBrain\Modules\Identity\ApprovalGrants;
use AgentBrain\Modules\Identity\AgentHumans;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\OAuth\ProfileUi;
use AgentBrain\Modules\OAuth\TokenStore;
use AgentBrain\Modules\Review\InboxItems;
use AgentBrain\Modules\ScopesAdmin\Page as ScopesPage;

/**
 * The Agents page, redesigned in #751 as the Review inbox's list and
 * detail: every ab_agent user on the left (who it works for, its scopes,
 * when it was last seen), the picked agent on the right with what it reads
 * and writes, its ID and its actions. Suspend sits in the detail; the
 * actions that are hard to take back sit under More. "Connected apps" is
 * ProfileUi's OAuth clients list, one chip away. "Add an agent" opens the
 * registration form (AgentRegistration\AdminUi) through the
 * agent_brain/agents_page_registrations hook.
 */
final class AgentsPage {

	public const CAPABILITY = Capabilities::CAP_MANAGE;
	public const SLUG       = 'agent-brain-agents';

	public const TAB_AGENTS = 'agents';
	public const TAB_OAUTH  = 'oauth';

	/** The list's views; the default hides suspended and revoked agents. */
	private const VIEWS = ['', AgentMeta::STATUS_SUSPENDED, AgentMeta::STATUS_REVOKED];

	/** Cap on ab_memory rows scanned to resolve each agent's last-seen client. */
	private const CLIENT_SCAN_LIMIT = 500;

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addMenu']);
	}

	public static function addMenu(): void {
		if (! function_exists('add_submenu_page')) {
			return;
		}

		add_submenu_page(
			Menu::PARENT,
			__('Agents', 'agent-brain'),
			__('Agents', 'agent-brain'),
			self::CAPABILITY,
			self::SLUG,
			[self::class, 'render']
		);
	}

	/**
	 * @param array<string, string> $args
	 */
	public static function url(array $args = []): string {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to view Agents.', 'agent-brain'), 403);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tab, view and selection, no state change.
		$tab    = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : self::TAB_AGENTS;
		$tab    = in_array($tab, [self::TAB_AGENTS, self::TAB_OAUTH], true) ? $tab : self::TAB_AGENTS;
		$view   = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : '';
		$view   = in_array($view, self::VIEWS, true) ? $view : '';
		$picked = isset($_GET['item']) ? absint(wp_unslash($_GET['item'])) : 0;
		// phpcs:enable

		$rows    = self::agentRows();
		$counts  = array_fill_keys(self::VIEWS, 0);
		$seenDay = 0;
		foreach ($rows as $row) {
			++$counts[self::viewOf($row)];
			$ts       = DateFormat::timestamp((string) $row['last_activity']);
			$seenDay += $ts !== null && $ts > time() - DAY_IN_SECONDS ? 1 : 0;
		}
		$clients = TokenStore::activeClients();

		$working = $counts[''];
		if ($working === 0) {
			$seen = '';
		} elseif ($seenDay >= $working) {
			$seen = __('all seen today', 'agent-brain');
		} else {
			/* translators: %s: number of agents */
			$seen = sprintf(__('%s seen today', 'agent-brain'), number_format_i18n($seenDay));
		}
		/* translators: %s: number of agents */
		$status = '<b>' . esc_html(sprintf(_n('%s agent', '%s agents', $working, 'agent-brain'), number_format_i18n($working))) . '</b>' . ($seen !== '' ? ' · ' . esc_html($seen) : '');

		$about = '<p>' . esc_html__('An agent is a WordPress account with the Agent role that an AI signs in as. It reads and writes only the scopes it was given, and everything it does is in Activity.', 'agent-brain') . '</p>'
			. '<p>' . esc_html__('Add an agent creates a registration key: paste the instructions into the AI and it registers itself, with the scopes you picked as its limit. Connected apps are the AI clients a person signed in with OAuth.', 'agent-brain') . '</p>';
		$actions = current_user_can(Capabilities::CAP_MANAGE)
			? '<a class="button button-primary" href="#ab-agent-new">' . esc_html__('Add an agent', 'agent-brain') . '</a>'
			: '';

		echo '<div class="wrap ab-admin ab-admin-agents">' . Ui::head(__('Agents', 'agent-brain'), $status, $actions, $about); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		$chips = [
			['label' => __('Agents', 'agent-brain'), 'url' => self::url(), 'count' => $working, 'current' => $tab === self::TAB_AGENTS && $view === ''],
			['label' => __('Connected apps', 'agent-brain'), 'url' => self::url(['tab' => self::TAB_OAUTH]), 'count' => count($clients), 'current' => $tab === self::TAB_OAUTH],
			['label' => __('Suspended', 'agent-brain'), 'url' => self::url(['view' => AgentMeta::STATUS_SUSPENDED]), 'count' => $counts[AgentMeta::STATUS_SUSPENDED], 'current' => $tab === self::TAB_AGENTS && $view === AgentMeta::STATUS_SUSPENDED],
		];
		if ($counts[AgentMeta::STATUS_REVOKED] > 0) {
			$chips[] = ['label' => __('Revoked', 'agent-brain'), 'url' => self::url(['view' => AgentMeta::STATUS_REVOKED]), 'count' => $counts[AgentMeta::STATUS_REVOKED], 'current' => $tab === self::TAB_AGENTS && $view === AgentMeta::STATUS_REVOKED];
		}
		echo '<div class="ab-ui-filters">' . Ui::chips($chips, __('Show', 'agent-brain')) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		do_action('agent_brain/agents_page_registrations');

		if ($tab === self::TAB_OAUTH) {
			(new ProfileUi())->renderClientsPage(true);
			echo '</div>';

			return;
		}

		$shown = array_values(array_filter($rows, static fn (array $row): bool => self::viewOf($row) === $view));
		if ($shown === []) {
			if ($view !== '') {
				$empty = EmptyState::html(
					$view === AgentMeta::STATUS_SUSPENDED ? __('No suspended agents', 'agent-brain') : __('No revoked agents', 'agent-brain'),
					__('Every agent here can sign in and work in its scopes.', 'agent-brain'),
					['label' => __('Show the agents', 'agent-brain'), 'url' => self::url()]
				);
			} else {
				$empty = EmptyState::html(
					__('No agents yet', 'agent-brain'),
					__('An agent is a WordPress account with the Agent role that an AI signs in as. None has been created, so nothing can read or write memory yet.', 'agent-brain'),
					['label' => __('Add an agent', 'agent-brain'), 'url' => '#ab-agent-new']
				);
			}
			echo $empty . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmptyState escapes.

			return;
		}

		$args     = array_filter(['view' => $view]);
		$ids      = array_map(static fn (array $row): int => (int) $row['user_id'], $shown);
		$selected = in_array($picked, $ids, true) ? $picked : $ids[0];
		$list     = '';
		$detail   = '';
		foreach ($shown as $row) {
			$list .= Ui::row(self::url($args + ['item' => (string) $row['user_id']]), (string) $row['name'], self::rowMeta($row), (int) $row['user_id'] === $selected);
			if ((int) $row['user_id'] === $selected) {
				$detail = self::detailHtml($row, self::url($args));
			}
		}

		echo Ui::split(__('Agents', 'agent-brain'), $list, $detail, $picked > 0) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
	}

	/**
	 * Which view lists this agent: suspended and revoked agents have their
	 * own; every other agent (active, pending, without an ID) is in the
	 * default one.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function viewOf(array $row): string {
		$status = (string) $row['agent_status'];

		return in_array($status, [AgentMeta::STATUS_SUSPENDED, AgentMeta::STATUS_REVOKED], true) ? $status : '';
	}

	/**
	 * "Roger's Tío · Marketing, reads Engineering · 12 minutes ago".
	 *
	 * @param array<string, mixed> $row
	 */
	private static function rowMeta(array $row): string {
		$parts = [];
		if ((string) $row['human'] !== '') {
			$first = explode(' ', trim((string) $row['human']))[0];
			/* translators: %s: first name of the person the agent works for. Tío is TomaTío's word for a person's AI agent. */
			$parts[] = esc_html(sprintf(__('%s\'s Tío', 'agent-brain'), $first));
		}
		$scopes = self::scopeWords($row);
		if ($scopes !== '') {
			$parts[] = esc_html($scopes);
		}
		$parts[] = (string) $row['last_activity'] !== ''
			? DateFormat::ago((string) $row['last_activity'])
			: esc_html__('never seen', 'agent-brain');

		$tag = self::statusTag($row);

		return ($tag !== '' ? $tag . ' ' : '') . implode(' · ', $parts);
	}

	/**
	 * The scopes in a few words: where it writes, then what it only reads.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function scopeWords(array $row): string {
		$write    = (array) $row['write_scopes'];
		$readOnly = array_values(array_diff((array) $row['read_scopes'], $write));
		$words    = implode(', ', $write);
		if ($readOnly !== []) {
			/* translators: %s: comma-separated scope names */
			$reads = sprintf(__('reads %s', 'agent-brain'), implode(', ', $readOnly));
			$words = $words !== '' ? $words . ', ' . $reads : $reads;
		}

		return $words;
	}

	/**
	 * A tag for anything other than a working agent with an ID.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function statusTag(array $row): string {
		$status = (string) $row['agent_status'];
		if ($status === AgentMeta::STATUS_REVOKED) {
			return Ui::tag(__('Revoked', 'agent-brain'), 'bad');
		}
		if ($status === AgentMeta::STATUS_SUSPENDED) {
			return Ui::tag(__('Suspended', 'agent-brain'), 'warn');
		}
		if ((string) $row['fqdn'] === '') {
			return Ui::tag(__('No ID yet', 'agent-brain'), 'warn');
		}

		return $status === AgentMeta::STATUS_PENDING ? Ui::tag(__('Pending', 'agent-brain'), 'info') : '';
	}

	/**
	 * The picked agent: what it is for, what it reads and writes, when it
	 * was last seen, its ID, and its actions.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function detailHtml(array $row, string $back): string {
		$userId = (int) $row['user_id'];
		$status = (string) $row['agent_status'];
		$issued = (string) $row['fqdn'] !== '';
		$dash   = '—';

		$words = [
			AgentMeta::STATUS_ACTIVE    => __('active', 'agent-brain'),
			AgentMeta::STATUS_PENDING   => __('pending', 'agent-brain'),
			AgentMeta::STATUS_SUSPENDED => __('suspended', 'agent-brain'),
			AgentMeta::STATUS_REVOKED   => __('revoked', 'agent-brain'),
		];
		/* translators: %s: the agent's state, such as "active" */
		$kicker = sprintf(__('Agent · %s', 'agent-brain'), $issued || $status === AgentMeta::STATUS_REVOKED ? ($words[$status] ?? $status) : __('no ID yet', 'agent-brain'));

		$body = '<h2>' . esc_html((string) $row['name']) . '</h2>';
		if ((string) $row['description'] !== '') {
			$body .= '<p>' . esc_html((string) $row['description']) . '</p>';
		} else {
			$body .= '<p class="ab-muted"><a href="' . esc_url((string) $row['edit_link'] . '#description') . '">' . esc_html__('Add a description', 'agent-brain') . '</a> '
				. esc_html__('(Biographical Info): people see it when they connect an AI as this agent.', 'agent-brain') . '</p>';
		}
		if (! $issued && $status !== AgentMeta::STATUS_REVOKED) {
			$body .= Ui::callout('warn', __('It cannot sign in yet.', 'agent-brain'), esc_html__('It needs an agent ID first: Issue ID gives it one.', 'agent-brain'));
		}

		$seen = (string) $row['last_activity'] !== '' ? DateFormat::ago((string) $row['last_activity']) : esc_html__('Never', 'agent-brain');
		if ((string) $row['connected_client'] !== '') {
			/* translators: %s: name of the AI client, such as "claude-ai" */
			$seen .= esc_html(sprintf(__(', from %s', 'agent-brain'), (string) $row['connected_client']));
		}
		$change = current_user_can(Capabilities::CAP_GRANT_SCOPES) || current_user_can(Capabilities::CAP_MANAGE)
			? ' · <a href="' . esc_url(add_query_arg(['page' => ScopesPage::SLUG], admin_url('admin.php'))) . '">' . esc_html__('Change scopes', 'agent-brain') . '</a>'
			: '';
		$memories = (int) $row['memories_week'];

		$facts = [
			__('Works for', 'agent-brain')  => (string) $row['human'] !== '' ? esc_html((string) $row['human']) : esc_html__('Nobody yet', 'agent-brain'),
			__('Reads', 'agent-brain')      => esc_html($row['read_scopes'] === [] ? $dash : implode(', ', (array) $row['read_scopes'])),
			__('Writes', 'agent-brain')     => esc_html($row['write_scopes'] === [] ? $dash : implode(', ', (array) $row['write_scopes'])) . $change,
			__('Last seen', 'agent-brain')  => $seen,
			/* translators: %s: number of memories */
			__('This week', 'agent-brain')  => esc_html(sprintf(_n('%s memory written', '%s memories written', $memories, 'agent-brain'), number_format_i18n($memories))),
		];
		if ((bool) $row['can_approve']) {
			$facts[__('Approval', 'agent-brain')] = esc_html__('May review document sections and assemble documents', 'agent-brain');
		}
		$facts[__('App passwords', 'agent-brain')] = esc_html(number_format_i18n((int) $row['app_password_count']));
		$facts[__('ID', 'agent-brain')]            = $issued ? '<code>' . esc_html((string) $row['fqdn']) . '</code>' : esc_html__('None yet', 'agent-brain');
		if ($issued) {
			/* translators: 1: who issued the ID, 2: when */
			$facts[__('Issued', 'agent-brain')] = sprintf(esc_html__('by %1$s, %2$s', 'agent-brain'), esc_html((string) $row['issued_by'] !== '' ? (string) $row['issued_by'] : $dash), DateFormat::html((string) $row['issued_at'], $dash));
		}
		$facts[__('Login', 'agent-brain')] = esc_html((string) $row['login']) . ' · <a href="' . esc_url((string) $row['edit_link']) . '">' . esc_html__('Edit profile', 'agent-brain') . '</a>';
		$body .= Ui::facts($facts);

		$actions = '';
		if ($status !== AgentMeta::STATUS_REVOKED && class_exists(AgentViewPage::class)) {
			$actions .= '<a class="button button-primary" href="' . esc_url(AgentViewPage::urlFor($userId)) . '">' . esc_html__('See what it sees', 'agent-brain') . '</a>';
		}
		if (current_user_can(Capabilities::CAP_MANAGE) && $status !== AgentMeta::STATUS_REVOKED) {
			if (! $issued) {
				$actions .= AgentActions::buttonForm($userId, AgentActions::OP_ISSUE, 'button button-primary');
			}
			$ops   = AgentActions::availableOps($status);
			$more  = AgentActions::buttonForm($userId, AgentActions::approvalOp($userId), 'button');
			$more .= in_array(AgentActions::OP_PASSWORDS, $ops, true) ? AgentActions::buttonForm($userId, AgentActions::OP_PASSWORDS, 'button') : '';
			$more .= in_array(AgentActions::OP_REVOKE, $ops, true) ? AgentActions::buttonForm($userId, AgentActions::OP_REVOKE, 'button button-link-delete') : '';
			$actions .= '<span class="ab-ui-spacer"></span>'
				. '<details class="ab-ui-menu"><summary class="button">' . esc_html__('More', 'agent-brain') . '</summary><div class="ab-ui-menu-list">' . $more . '</div></details>';
			foreach ([AgentActions::OP_SUSPEND, AgentActions::OP_REACTIVATE] as $op) {
				$actions .= in_array($op, $ops, true) ? AgentActions::buttonForm($userId, $op, 'button') : '';
			}
		}

		return Ui::detail($kicker, $body, $actions, $back);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function agentRows(): array {
		if (! function_exists('get_users')) {
			return [];
		}

		$users = get_users(['role' => Capabilities::ROLE_AGENT, 'orderby' => 'display_name', 'order' => 'ASC']);
		if ($users === []) {
			return [];
		}

		$scopes       = new Scopes();
		$appPasswords = new AppPasswords();
		$grants       = new ApprovalGrants();
		$userIds      = array_map(static fn (\WP_User $user): int => (int) $user->ID, $users);
		$lastActivity = self::lastActivityByActor($userIds);
		$lastClients  = self::lastClientByAgent($userIds);
		$week         = self::memoriesSince($userIds, gmdate('Y-m-d H:i:s', time() - WEEK_IN_SECONDS));

		$rows = [];
		foreach ($users as $user) {
			$rows[] = [
				'user_id'            => (int) $user->ID,
				'name'               => InboxItems::who((int) $user->ID)['name'],
				'description'        => trim((string) $user->description),
				'login'              => $user->user_login,
				'edit_link'          => get_edit_user_link($user->ID),
				'fqdn'               => (string) AgentIssuer::fqdnFor((int) $user->ID),
				'agent_status'       => AgentIssuer::statusOf((int) $user->ID) ?? AgentMeta::STATUS_ACTIVE,
				'issued_by'          => AgentIssuer::issuedInfo((int) $user->ID)['by'],
				'issued_at'          => AgentIssuer::issuedInfo((int) $user->ID)['at'],
				'human'              => AgentHumans::nameFor((int) $user->ID) ?? '',
				'read_scopes'        => self::scopeTermNames($scopes->readScopeIds((int) $user->ID)),
				'write_scopes'       => self::scopeTermNames($scopes->writeScopeIds((int) $user->ID)),
				'app_password_count' => count($appPasswords->listForUser((int) $user->ID)),
				'can_approve'        => $grants->hasGrant((int) $user->ID),
				'last_activity'      => $lastActivity[(int) $user->ID] ?? '',
				'connected_client'   => $lastClients[(int) $user->ID] ?? '',
				'memories_week'      => $week[(int) $user->ID] ?? 0,
			];
		}

		return $rows;
	}

	/**
	 * @param int[] $scopeIds
	 * @return array<int, string>
	 */
	private static function scopeTermNames(array $scopeIds): array {
		if ($scopeIds === [] || ! function_exists('get_term')) {
			return [];
		}

		$names = [];
		foreach ($scopeIds as $scopeId) {
			$term = get_term($scopeId, Scopes::TAXONOMY);
			if ($term instanceof \WP_Term) {
				$names[] = $term->name;
			}
		}

		return $names;
	}

	/**
	 * Most recent audit ts per actor id, bounded to the given user id list
	 * via the indexed actor_event key and a hard row cap.
	 *
	 * @param int[] $userIds
	 * @return array<int, string>
	 */
	private static function lastActivityByActor(array $userIds): array {
		if ($userIds === []) {
			return [];
		}

		$placeholders = implode(',', array_fill(0, count($userIds), '%d'));
		$sql          = 'SELECT actor_id, MAX(ts) AS last_ts FROM ' . Repository::tableName()
			. " WHERE actor_id IN ({$placeholders}) GROUP BY actor_id";

		$rows = Db::getResults($sql, $userIds);

		$map = [];
		foreach ($rows as $row) {
			$map[(int) $row['actor_id']] = (string) $row['last_ts'];
		}

		return $map;
	}

	/**
	 * Last non-null `client` value written to ab_memory per agent id,
	 * bounded by an explicit row cap on the underlying scan.
	 *
	 * @param int[] $userIds
	 * @return array<int, string>
	 */
	private static function lastClientByAgent(array $userIds): array {
		if ($userIds === []) {
			return [];
		}

		$table        = Db::table('memory');
		$placeholders = implode(',', array_fill(0, count($userIds), '%d'));
		$sql          = "SELECT agent_id, client, created FROM {$table} "
			. "WHERE agent_id IN ({$placeholders}) AND client IS NOT NULL "
			. 'ORDER BY created DESC LIMIT %d';

		$params   = $userIds;
		$params[] = self::CLIENT_SCAN_LIMIT;

		$rows = Db::getResults($sql, $params);

		$map = [];
		foreach ($rows as $row) {
			$agentId = (int) ($row['agent_id'] ?? 0);
			if ($agentId > 0 && ! isset($map[$agentId])) {
				$map[$agentId] = (string) ($row['client'] ?? '');
			}
		}

		return $map;
	}

	/**
	 * Memories each agent wrote since a UTC time.
	 *
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	private static function memoriesSince(array $userIds, string $since): array {
		if ($userIds === []) {
			return [];
		}

		$table        = Db::table('memory');
		$placeholders = implode(',', array_fill(0, count($userIds), '%d'));
		$sql          = "SELECT agent_id, COUNT(*) AS n FROM {$table} WHERE agent_id IN ({$placeholders}) AND created >= %s GROUP BY agent_id";

		$params   = $userIds;
		$params[] = $since;

		$map = [];
		foreach (Db::getResults($sql, $params) as $row) {
			$map[(int) $row['agent_id']] = (int) $row['n'];
		}

		return $map;
	}
}
