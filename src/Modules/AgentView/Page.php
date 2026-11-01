<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AgentView;

use AgentBrain\Modules\Admin\AgentsPage;
use AgentBrain\Modules\Admin\DateFormat;
use AgentBrain\Modules\Admin\EmptyState;
use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\AdapterBridge;
use AgentBrain\Modules\Recall\Briefing;
use AgentBrain\Modules\Recall\Recall;
use AgentBrain\Modules\Review\InboxItems;
use AgentBrain\Modules\ScopesAdmin\Page as ScopesPage;

/**
 * Read-only "Agent View" screen ("see what this agent sees"), laid out in
 * #751 as the Review inbox's list and detail: the agents on the left, the
 * picked one (the first by default, never an empty page) on the right with
 * its briefing, scopes, a test search and its tools, one at a time.
 *
 * Capability: ab_manage. The screen runs the same builders the MCP tools
 * use (Briefing::build() for context-briefing, Recall::search() for
 * memory-search) with the TARGET agent's user id, so the output is exactly
 * what that agent would receive, scope rules included. It never writes
 * memory. Two side effects are inherent to the shared services and are
 * deliberate: Recall::search() records a `recall_search` audit row for the
 * agent, and the curator's own test search is audited separately as
 * `agent_view.search` so the two can be told apart.
 */
final class Page {

	public const CAPABILITY = Capabilities::CAP_MANAGE;
	public const SLUG       = 'agent-brain-agent-view';

	private const PANELS = ['briefing', 'scopes', 'search', 'tools'];

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addMenu']);
	}

	public static function addMenu(): void {
		add_submenu_page(
			Menu::PARENT,
			__('Agent View', 'agent-brain'),
			__('Agent View', 'agent-brain'),
			self::CAPABILITY,
			self::SLUG,
			[self::class, 'render']
		);
	}

	/**
	 * Link used by the Agents page row action.
	 */
	public static function urlFor(int $agentId): string {
		return add_query_arg(['page' => self::SLUG, 'agent' => $agentId], admin_url('admin.php'));
	}

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to view Agent View.', 'agent-brain'), 403);
		}

		$agents = self::agents();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only selection, panel and query, no state change.
		$picked = isset($_GET['agent']) ? absint(wp_unslash($_GET['agent'])) : 0;
		$q      = isset($_GET['q']) ? trim(sanitize_text_field(wp_unslash($_GET['q']))) : '';
		$show   = isset($_GET['show']) ? sanitize_key(wp_unslash((string) $_GET['show'])) : '';
		// phpcs:enable
		$show = in_array($show, self::PANELS, true) ? $show : ($q !== '' ? 'search' : 'briefing');

		$agentId = $picked > 0 ? $picked : (int) array_key_first($agents);
		/* translators: %s: agent name */
		$status = isset($agents[$agentId]) ? sprintf(esc_html__('What %s sees when it connects', 'agent-brain'), '<b>' . esc_html($agents[$agentId]) . '</b>') : '';
		$about  = '<p>' . esc_html__('See exactly what one agent sees: its briefing, the scopes it can reach, what a search returns for it, and the tools it can call. Nothing here changes the brain.', 'agent-brain') . '</p>'
			. '<p>' . esc_html__('Only agents whose read scopes you can read yourself are listed. A test search is recorded in Activity under your name and the agent\'s.', 'agent-brain') . '</p>';

		echo '<div class="wrap ab-admin ab-admin-agent-view">' . Ui::head(__('Agent View', 'agent-brain'), $status, '', $about); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		if ($agents === []) {
			$empty = EmptyState::html(
				__('No agents yet', 'agent-brain'),
				__('An agent is a WordPress account with the Agent role that an AI signs in as. Add one and you can see here what it sees.', 'agent-brain'),
				['label' => __('Add an agent', 'agent-brain'), 'url' => admin_url('admin.php?page=' . AgentsPage::SLUG . '#ab-agent-new')]
			);
			echo $empty . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmptyState escapes.

			return;
		}

		$scopes = new Scopes();
		$rows   = '';
		foreach ($agents as $id => $label) {
			$rows .= Ui::row(self::urlFor($id), $label, esc_html(self::scopeList($scopes->readScopeIds($id))), $id === $agentId);
		}

		echo Ui::split(__('Agents', 'agent-brain'), $rows, self::detailHtml($agentId, $agents, $show, $q), $picked > 0) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
	}

	/**
	 * The picked agent: its ID, a callout when it reads nothing, and one
	 * panel at a time (briefing, scopes, test search, tools).
	 *
	 * @param array<int, string> $agents
	 */
	private static function detailHtml(int $agentId, array $agents, string $show, string $q): string {
		$back = add_query_arg(['page' => self::SLUG], admin_url('admin.php'));
		$user = get_userdata($agentId);
		if (! $user instanceof \WP_User || ! in_array(Capabilities::ROLE_AGENT, (array) $user->roles, true)) {
			return Ui::detail('', '<p class="ab-empty">' . esc_html__('That user is not an agent.', 'agent-brain') . '</p>', '', $back);
		}
		if (! isset($agents[$agentId])) {
			return Ui::detail('', '<p class="ab-empty">' . esc_html__('You can view an agent only when you can read every scope it reads.', 'agent-brain') . '</p>', '', $back);
		}

		$fqdn  = (string) get_user_meta($agentId, AgentMeta::FQDN, true);
		$tools = self::tools($agentId);
		$body  = '<h2>' . esc_html($agents[$agentId]) . '</h2><p><code>' . esc_html($fqdn !== '' ? $fqdn : $user->user_login) . '</code></p>';

		if ((new Scopes())->readScopeIds($agentId) === []) {
			$body .= Ui::callout('warn', __('It reads nothing yet.', 'agent-brain'), esc_html__('This agent has no read scopes, so its briefing and searches are empty by design. Grant access on the Scopes page.', 'agent-brain'));
		}

		$labels = [
			'briefing' => __('Briefing', 'agent-brain'),
			'scopes'   => __('Scopes', 'agent-brain'),
			'search'   => __('Try a search', 'agent-brain'),
			/* translators: %s: number of tools */
			'tools'    => sprintf(__('Tools (%s)', 'agent-brain'), number_format_i18n(count($tools))),
		];
		$chips = [];
		foreach ($labels as $panel => $label) {
			$chips[] = ['label' => $label, 'url' => add_query_arg(['show' => $panel], self::urlFor($agentId)), 'current' => $panel === $show];
		}
		$body .= Ui::chips($chips, __('What to show', 'agent-brain'));

		switch ($show) {
			case 'scopes':
				$body .= self::scopesHtml($agentId);
				break;
			case 'search':
				$body .= self::searchHtml($agentId, $q);
				break;
			case 'tools':
				$body .= self::toolsHtml($tools);
				break;
			default:
				$body .= self::briefingHtml($agentId);
		}

		return Ui::detail(__('Agent View · read-only', 'agent-brain'), $body, '', $back);
	}

	private static function scopesHtml(int $agentId): string {
		$scopes = new Scopes();

		return Ui::facts([
			__('Can read', 'agent-brain')  => esc_html(self::scopeList($scopes->readScopeIds($agentId))),
			__('Can write', 'agent-brain') => esc_html(self::scopeList($scopes->writeScopeIds($agentId))),
		]) . '<p><a href="' . esc_url(add_query_arg(['page' => ScopesPage::SLUG], admin_url('admin.php'))) . '">' . esc_html__('Change scopes', 'agent-brain') . '</a></p>';
	}

	private static function briefingHtml(int $agentId): string {
		$briefing = (new Briefing())->build($agentId);

		$out = '<p class="ab-muted">' . esc_html(sprintf(
			/* translators: 1: token count, 2: item count */
			__('What context-briefing returns at the start of each chat: about %1$d tokens, %2$d items.', 'agent-brain'),
			(int) $briefing['tokens'],
			count($briefing['items'])
		)) . '</p>';

		if (trim($briefing['text']) === '') {
			return $out . '<p class="ab-empty">' . esc_html__('The briefing is empty: this agent has no readable working memory, episodes, knowledge or procedures yet.', 'agent-brain') . '</p>';
		}

		return $out . '<pre class="ab-ui-text ab-av-briefing">' . esc_html($briefing['text']) . '</pre>';
	}

	private static function searchHtml(int $agentId, string $q): string {
		$out = '<form method="get" class="ab-ui-filters"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />'
			. '<input type="hidden" name="agent" value="' . esc_attr((string) $agentId) . '" />'
			. '<input type="hidden" name="show" value="search" />'
			. '<label for="ab_agent_view_q" class="ab-ui-sr">' . esc_html__('Test search', 'agent-brain') . '</label>'
			. '<input type="search" id="ab_agent_view_q" name="q" value="' . esc_attr($q) . '" placeholder="' . esc_attr__('Search as this agent', 'agent-brain') . '" /> '
			. '<button type="submit" class="button">' . esc_html__('Search as this agent', 'agent-brain') . '</button></form>';

		if ($q === '') {
			return $out . '<p class="ab-muted">' . esc_html__('Test search: enter a query to see exactly what memory-search would return for this agent.', 'agent-brain') . '</p>';
		}

		do_action('agent_brain/audit', [
			'actor_id'    => (int) get_current_user_id(),
			'event'       => 'agent_view.search',
			'status'      => 'ok',
			'object_type' => 'user',
			'object_id'   => (string) $agentId,
		]);

		$results = (new Recall())->search($agentId, $q);
		if ($results === []) {
			return $out . '<p class="ab-empty">' . esc_html__('No results. Either nothing readable matches, or this agent has no read scopes.', 'agent-brain') . '</p>';
		}

		$out .= '<ol class="ab-ui-thread ab-av-results">';
		foreach ($results as $row) {
			$out .= '<li><div class="ab-ui-text">' . esc_html((string) $row['excerpt']) . '</div><p class="ab-muted">'
				. esc_html(sprintf(
					/* translators: 1: kind of item, 2: its id, 3: blended score, 4: importance, 5: when it was created */
					__('%1$s #%2$s · score %3$s · importance %4$s · %5$s', 'agent-brain'),
					(string) $row['kind'],
					(string) $row['id'],
					number_format((float) $row['score'], 4),
					(string) $row['importance'],
					DateFormat::text((string) $row['created'])
				)) . '</p></li>';
		}

		return $out . '</ol><p class="ab-muted">' . esc_html__('Score is the blended rank (relevance, recency and importance); importance is shown separately.', 'agent-brain') . '</p>';
	}

	/**
	 * @param array<string, string> $tools
	 */
	private static function toolsHtml(array $tools): string {
		if ($tools === []) {
			return '<p class="ab-empty">' . esc_html__('This agent cannot call any MCP tools: its user lacks the ab_use_mcp capability.', 'agent-brain') . '</p>';
		}

		$tones = [
			__('allowed', 'agent-brain')                        => 'ok',
			__('object-level (leads/owns only)', 'agent-brain') => 'info',
			__('visible but curator-only', 'agent-brain')       => 'warn',
		];
		$out = '<ul class="ab-av-tools">';
		foreach ($tools as $name => $access) {
			$out .= '<li><code>' . esc_html($name) . '</code> ' . Ui::tag($access, $tones[$access] ?? '') . '</li>';
		}

		return $out . '</ul>';
	}

	/**
	 * Tool name => "allowed" | "object-level (leads/owns only)" | "visible but
	 * curator-only".
	 *
	 * Derived from the same source as MCP tools/list: every ability the
	 * server registers (AbilityNames::ALL plus REGISTRATION), kept when
	 * AdapterBridge::isListed() accepts its permission check evaluated as the
	 * target agent (current user is swapped for the check and always
	 * restored). Tools that tools/list would not show this agent are omitted.
	 *
	 * @return array<string, string>
	 */
	public static function tools(int $agentId): array {
		if (! user_can($agentId, Capabilities::CAP_USE_MCP)) {
			return [];
		}

		$previous = get_current_user_id();
		$out      = [];

		wp_set_current_user($agentId);

		try {
			foreach (array_merge(AbilityNames::ALL, AbilityNames::REGISTRATION) as $name) {
				$ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;

				if ($ability === null) {
					continue;
				}

				$result = $ability->check_permissions([]);

				if (! AdapterBridge::isListed($result)) {
					continue;
				}

				if (AdapterBridge::isCuratorsOnlyDenial($result)) {
					$out[$name] = __('visible but curator-only', 'agent-brain');
				} elseif (self::objectLevel($name)) {
					$out[$name] = __('object-level (leads/owns only)', 'agent-brain');
				} else {
					$out[$name] = __('allowed', 'agent-brain');
				}
			}
		} finally {
			wp_set_current_user($previous);
		}

		return $out;
	}

	/**
	 * Abilities whose permission depends on the target artifact/section, so
	 * a permission check without a target says "no" even when the agent can
	 * use the tool on its own objects.
	 */
	private static function objectLevel(string $name): bool {
		return in_array($name, [
			AbilityNames::ARTIFACT_REORDER,
			AbilityNames::SECTION_ASSIGN,
			AbilityNames::SECTION_CREATE,
			AbilityNames::ARTIFACT_SET_OUTLINE,
			AbilityNames::ARTIFACT_STATUS,
			AbilityNames::SECTION_REVIEW,
			AbilityNames::ARTIFACT_ASSEMBLE,
		], true);
	}

	/**
	 * @param array<int, int> $ids
	 */
	private static function scopeList(array $ids): string {
		$names = [];

		foreach ($ids as $id) {
			$term = get_term($id, Scopes::TAXONOMY);
			if ($term instanceof \WP_Term) {
				$names[] = $term->name;
			}
		}

		return $names === [] ? __('(none)', 'agent-brain') : implode(', ', $names);
	}

	/**
	 * @return array<int, string>
	 */
	private static function agents(): array {
		$out = [];

		// #552: an agent's briefing and search show everything it reads, so
		// only agents whose read scopes are all within the viewer's are listed.
		$visible = ScopeVisibility::visibleScopeIds(get_current_user_id());
		$scopes  = new Scopes();

		foreach (get_users(['role' => Capabilities::ROLE_AGENT, 'orderby' => 'display_name']) as $user) {
			if ($visible !== null && array_diff($scopes->readScopeIds((int) $user->ID), $visible) !== []) {
				continue;
			}

			$out[(int) $user->ID] = InboxItems::who((int) $user->ID)['name'];
		}

		return $out;
	}
}
