<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Audit\Repository;
use AgentBrain\Modules\Audit\ViewerPage;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\DefaultScope;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\JobsAdmin\Page as JobsPage;
use AgentBrain\Modules\OAuth\TokenStore;

/**
 * TomaTío admin IA wave (ledger ecc6ce42), Unit U: the top-level
 * "Overview" landing page. Since the admin redesign (#751) it leads with
 * what needs you (the work waiting in Review, and problems as callouts
 * with the one thing to do), then live activity, how much the brain holds
 * with a health line linking to Settings -> Self-Check, the latest
 * memories and, until it is done, the Get started checklist.
 *
 * Every query here is bounded (LIMIT + indexed columns only, per contract):
 * badge-style counts reuse Menu::badgeCounts(), the artifact-by-stage count
 * is a single GROUP BY over an indexed meta key (at most 5 rows back), the
 * active-agent count is capped at ACTIVE_AGENTS_SCAN_LIMIT distinct actor
 * rows within an indexed ts range, and recent activity reuses
 * Audit\Repository::recent() which is already LIMIT/OFFSET-bound.
 */
final class OverviewPage {

	public const SLUG = Menu::PARENT;

	/** Cap on distinct actor rows scanned to compute the 7-day active-agent count. */
	private const ACTIVE_AGENTS_SCAN_LIMIT = 500;


	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addMenu']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueueLive']);
	}

	public static function addMenu(): void {
		if (! function_exists('add_submenu_page')) {
			return;
		}

		add_submenu_page(
			Menu::PARENT,
			__('Overview', 'agent-brain'),
			__('Overview', 'agent-brain'),
			'read',
			self::SLUG,
			[self::class, 'render']
		);
	}

	/**
	 * Same number as the Review Queue menu pill: proposals plus open handoffs (#80).
	 *
	 * @param array{review: int, handoffs: int} $counts
	 */
	public static function waitingForReview(array $counts): int {
		return (int) $counts['review'] + (int) $counts['handoffs'];
	}

	public static function render(): void {
		if (! self::currentUserCanView()) {
			wp_die(esc_html__('You do not have permission to view TomaTío.', 'agent-brain'), 403);
		}

		$snapshot = OverviewData::snapshot(get_current_user_id());
		$counts   = $snapshot['counts'];
		$waiting  = self::waitingForReview($counts);
		$problems = self::problems((string) $snapshot['health']);
		$needs    = $waiting + count($problems);

		// #51: a brand-new brain has nothing to count. It leads with the
		// setup checklist and shows no bare zeros, but never hides work that
		// is actually waiting (pending reviews, open handoffs).
		$empty = $counts['memories'] === 0;

		// #751: the head says what needs you; problems follow as callouts
		// that name the one thing to do; the work waiting is a link straight
		// into it; the rest of the page is how the brain is doing.
		$live = '<span class="ab-live ab-live-off" id="ab-live"><span class="ab-live-dot" aria-hidden="true"></span>' . esc_html__('Live', 'agent-brain') . '</span>';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui and the render helpers escape what they emit.
		echo '<div class="wrap ab-admin ab-admin-overview" id="ab-overview" data-ab-problems="' . esc_attr((string) count($problems)) . '">';
		echo Ui::head(__('Overview', 'agent-brain'), self::statusLine($needs), $live, self::about());
		echo implode('', $problems);
		self::renderNeeds($counts, $waiting);

		$setup = self::setupChecklist(! $empty);
		if ($empty) {
			echo $setup;
		}

		echo '<div class="ab-ui-grid">';
		self::renderActivity($snapshot['activity']);
		self::renderGlance($counts, $snapshot['stages'], (string) $snapshot['health'], $empty, ! empty($snapshot['scopeless']));
		echo '</div>';

		self::renderMemories($snapshot['memories'], ! empty($snapshot['scopeless']));

		if (! $empty) {
			echo $setup;
		}

		// #471: on a fresh install, ask once whether it is just for TomaTío,
		// in one line at the bottom.
		echo DedicatedPrompt::html(true);
		// phpcs:enable

		echo '</div>';
	}

	/**
	 * "3 things need you", or that nothing does. overview-live.js rewrites
	 * it with the same words as the counts change.
	 */
	private static function statusLine(int $needs): string {
		if ($needs === 0) {
			return esc_html__('Nothing needs you right now.', 'agent-brain');
		}

		return sprintf(
			/* translators: %s: number of things that need the person, in bold */
			esc_html(_n('%s thing needs you', '%s things need you', $needs, 'agent-brain')),
			'<b>' . esc_html(number_format_i18n($needs)) . '</b>'
		);
	}

	private static function about(): string {
		return '<p>' . esc_html__('The Overview leads with what needs you: proposals and handoffs waiting in Review, and anything that has stopped working. Below that is what the agents are doing, how much the brain holds and the latest memories in scopes you can read.', 'agent-brain') . '</p>'
			. '<p>' . esc_html__('While this tab is open, the counts, the activity and the memories refresh by themselves every few seconds; "Live" next to the title shows it is on.', 'agent-brain') . '</p>';
	}

	/**
	 * One callout per problem, each with the one thing to do: TomaTío
	 * running degraded, and (for managers) summarising failing on every
	 * recent run.
	 *
	 * @return list<string>
	 */
	public static function problems(string $health): array {
		$out = [];

		if ($health === 'degraded') {
			$out[] = Ui::callout(
				'bad',
				__('Part of TomaTío failed to load.', 'agent-brain'),
				esc_html__('The brain is running degraded and may refuse agents until it is fixed. Self-Check says which part and why.', 'agent-brain'),
				current_user_can(SettingsHub::CAPABILITY)
					? '<a class="button button-primary" href="' . esc_url(SettingsHub::url(SettingsHub::TAB_SELF_CHECK)) . '">' . esc_html__('Open Self-Check', 'agent-brain') . '</a>'
					: ''
			);
		}

		if (current_user_can(JobsPage::CAPABILITY) && JobsPage::providerState()['failing']) {
			$out[] = Ui::callout(
				'bad',
				__('Summarising is failing.', 'agent-brain'),
				esc_html__('Every recent run failed, so old memories are not being summarised. Nothing is lost: they wait for the next good run.', 'agent-brain'),
				'<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=' . JobsPage::SLUG)) . '">' . esc_html__('See why in Jobs', 'agent-brain') . '</a>'
			);
		}

		return $out;
	}

	/**
	 * The work waiting, as a link straight into it. Rendered even at zero
	 * (hidden) so the live refresh can show it the moment something arrives.
	 *
	 * @param array{review: int, handoffs: int, active_agents: int, memories: int} $counts
	 */
	private static function renderNeeds(array $counts, int $waiting): void {
		echo '<ul class="ab-ov-needs" aria-label="' . esc_attr__('Needs you', 'agent-brain') . '">';
		echo '<li data-ab-need="waiting"' . ($waiting === 0 ? ' hidden' : '') . '><a class="ab-ov-need" href="' . esc_url(admin_url('admin.php?page=agent-brain-review')) . '">'
			. '<b class="ab-card-count" data-ab-count="waiting">' . esc_html((string) $waiting) . '</b>'
			. '<span class="ab-ov-need-label">' . esc_html__('Waiting for review', 'agent-brain') . '</span>'
			. '<small>' . sprintf(
				/* translators: 1: number of proposed facts and procedures, 2: number of open handoffs */
				esc_html__('%1$s facts and procedures, %2$s open handoffs', 'agent-brain'),
				'<span data-ab-count="review">' . esc_html((string) $counts['review']) . '</span>',
				'<span data-ab-count="handoffs">' . esc_html((string) $counts['handoffs']) . '</span>'
			) . '</small></a></li>';
		echo '</ul>';
	}

	/**
	 * How much the brain holds, its health and the running version. On an
	 * empty brain only the health line shows: no bare zeros (#51).
	 *
	 * @param array{review: int, handoffs: int, active_agents: int, memories: int} $counts
	 * @param array<string, int>                                                   $stages
	 */
	private static function renderGlance(array $counts, array $stages, string $health, bool $empty, bool $scopeless): void {
		$body = '';

		if (! $empty) {
			$stats = [
				[$scopeless ? __('Memories on this brain', 'agent-brain') : __('Memories you can read', 'agent-brain'), 'memories', $counts['memories'], ''],
				[__('Active agents (7 days)', 'agent-brain'), 'active_agents', $counts['active_agents'], ''],
				[__('Documents', 'agent-brain'), 'documents', array_sum($stages), self::stageList($stages)],
			];

			$body .= '<dl class="ab-ov-stats">';
			foreach ($stats as [$label, $key, $count, $extra]) {
				$body .= '<div><dt>' . esc_html($label) . '</dt><dd><span class="ab-card-count" data-ab-count="' . esc_attr($key) . '">' . esc_html((string) $count) . '</span>' . $extra . '</dd></div>';
			}
			$body .= '</dl>';
		}

		$body .= self::healthLine($health);

		echo Ui::card($empty ? '' : __('At a glance', 'agent-brain'), $body, 'ab-ov-glance'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::card() escapes.
	}

	/**
	 * @param array<string, int> $stageCounts
	 */
	private static function stageList(array $stageCounts): string {
		$out = '<ul class="ab-stage-list" aria-label="' . esc_attr__('Documents by stage', 'agent-brain') . '">';
		foreach ($stageCounts as $stage => $count) {
			$out .= '<li><span class="ab-stage-name">' . esc_html((string) $stage) . '</span> '
				. '<span class="ab-stage-count" data-ab-stage="' . esc_attr((string) $stage) . '">' . esc_html((string) $count) . '</span></li>';
		}

		return $out . '</ul>';
	}

	private static function healthLine(string $status): string {
		$out = '<p class="ab-health-line">'
			. esc_html__('System health:', 'agent-brain') . ' '
			. '<span class="ab-health-status ab-health-' . esc_attr($status) . '">' . esc_html($status) . '</span>'
			. ' · <a href="' . esc_url(SettingsHub::url(SettingsHub::TAB_SELF_CHECK)) . '">' . esc_html__('Self-Check', 'agent-brain') . '</a>'
			. ' · ' . self::versionBadge();

		if (current_user_can(ConnectPage::CAPABILITY)) {
			$out .= ' · <a href="' . esc_url(admin_url('admin.php?page=' . ConnectPage::SLUG)) . '">' . esc_html__('Connect an AI', 'agent-brain') . '</a>';
		}

		return $out . '</p>';
	}

	public static function enqueueLive(string $hook): void {
		if (! function_exists('wp_enqueue_script') || ! defined('AGENT_BRAIN_PLUGIN_FILE')) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		$page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
		if ($page !== self::SLUG) {
			return;
		}

		wp_enqueue_script(
			'agent-brain-overview-live',
			plugins_url('assets/overview-live.js', AGENT_BRAIN_PLUGIN_FILE),
			[],
			\AgentBrain\Core\AssetVersion::of('assets/overview-live.js'),
			true
		);

		wp_add_inline_script(
			'agent-brain-overview-live',
			'window.agentBrainOverview = ' . wp_json_encode([
				'endpoint' => rest_url(OverviewRest::NAMESPACE . OverviewRest::ROUTE),
				'nonce'    => wp_create_nonce('wp_rest'),
				'interval' => 4000,
				'i18n'     => [
					'ago'        => __('%s ago', 'agent-brain'),
					'justNow'    => __('just now', 'agent-brain'),
					'noActivity' => __('No activity yet. This feed shows what agents do as they work; connect an AI client to get started.', 'agent-brain'),
					'noScopes'   => __('You hold no scopes, so memories are hidden here; see Memories.', 'agent-brain'),
					'noMemories' => __('No memories in your scopes yet. Memories are what agents save while they work.', 'agent-brain'),
					'failed'     => __('failed', 'agent-brain'),
					'needsNone'  => __('Nothing needs you right now.', 'agent-brain'),
					/* translators: %s: number of things that need the person (one) */
					'needsOne'   => _n('%s thing needs you', '%s things need you', 1, 'agent-brain'),
					/* translators: %s: number of things that need the person (more than one) */
					'needsMany'  => _n('%s thing needs you', '%s things need you', 2, 'agent-brain'),
				],
			]) . ';',
			'before'
		);
	}

	/**
	 * Version pill shown next to the page title, so support requests can
	 * quote which TomaTío release a site runs. Same pattern as
	 * Core\AssetVersion::of(): fall back to '0' when the constant is missing.
	 */
	private static function versionBadge(): string {
		$version = defined('AGENT_BRAIN_VERSION') ? (string) AGENT_BRAIN_VERSION : '0';

		return '<span class="ab-version-badge">'
			. esc_html(sprintf(
				/* translators: %s: TomaTío plugin version number, e.g. "1.8.2". */
				__('Version %s', 'agent-brain'),
				$version
			))
			. '</span>';
	}

	private static function currentUserCanView(): bool {
		if (! function_exists('current_user_can')) {
			return false;
		}

		foreach (Menu::surfaceCapabilities() as $cap) {
			if (current_user_can($cap)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Setup state for the first-run checklist: has an agent been created,
	 * does one hold a read scope, has any AI client connected, and does the
	 * site look like a dedicated install (#357).
	 *
	 * @return array{agents: bool, scopes: bool, client: bool, dedicated: bool}
	 */
	public static function setupState(): array {
		$agents = function_exists('get_users')
			? get_users(['role' => Capabilities::ROLE_AGENT, 'fields' => 'ID', 'number' => 50])
			: [];

		// The default team scope (#182) is granted on creation, so its
		// existence completes the step.
		$hasScopes = DefaultScope::id() !== null;
		$scopes    = new Scopes();
		foreach ($hasScopes ? [] : $agents as $agentId) {
			if ($scopes->readScopeIds((int) $agentId) !== []) {
				$hasScopes = true;
				break;
			}
		}

		try {
			$hasClient = TokenStore::activeClients() !== [] || self::activeAgentsLast7Days() > 0;
		} catch (\Throwable $e) {
			$hasClient = false;
		}

		return [
			'agents'    => $agents !== [],
			'scopes'    => $hasScopes,
			'client'    => $hasClient,
			'dedicated' => ! SharedInstall::detect()['shared'],
		];
	}

	/**
	 * The first-run checklist, or '' once every step is done. It stays
	 * until then (a brain can hold memories before an agent has scopes or a
	 * client is connected), open while the brain is new and folded once
	 * most of it is done.
	 */
	private static function setupChecklist(bool $hasMemories): string {
		$state = self::setupState();

		$steps = [
			[$state['agents'], __('Create an agent', 'agent-brain'), __('An agent is the account an AI signs in as.', 'agent-brain'), admin_url('admin.php?page=' . AgentsPage::SLUG), Capabilities::CAP_MANAGE],
			[$state['scopes'], __('Grant it scopes', 'agent-brain'), __('Scopes decide which memories the agent can read and write.', 'agent-brain'), admin_url('admin.php?page=' . AgentsPage::SLUG), Capabilities::CAP_MANAGE],
			[$state['client'], __('Connect an AI client', 'agent-brain'), __('Point Claude, ChatGPT or another MCP client at this site.', 'agent-brain'), admin_url('admin.php?page=' . ConnectPage::SLUG), ConnectPage::CAPABILITY],
			[$hasMemories, __('Save the first memory', 'agent-brain'), __('Once a connected agent saves something, it appears under Latest memories.', 'agent-brain'), admin_url('admin.php?page=' . ConnectPage::SLUG), ConnectPage::CAPABILITY],
			[$state['dedicated'], __('Use a dedicated WordPress install', 'agent-brain'), __('A brain holds private context, agent tokens and the audit log; on an existing website every other plugin and every existing admin shares the process and database that reach them. TomaTío recommends its own, new install (for example brain.yourcompany.com).', 'agent-brain'), SettingsHub::url(SettingsHub::TAB_SITE), SettingsHub::CAPABILITY],
		];

		$done = count(array_filter($steps, static fn (array $step): bool => (bool) $step[0]));
		if ($done === count($steps)) {
			return '';
		}

		$open = ! $hasMemories || $done < 3;
		$out  = '<details class="ab-ui-card ab-ov-setup ab-setup" id="ab-setup"' . ($open ? ' open' : '') . '><summary>'
			. '<h2>' . esc_html__('Get started', 'agent-brain') . '</h2> '
			/* translators: 1: steps done, 2: all steps */
			. '<span class="ab-ov-progress">' . esc_html(sprintf(__('%1$d of %2$d done', 'agent-brain'), $done, count($steps))) . '</span></summary>';
		$out .= '<p>' . esc_html(
			$hasMemories
				? __('This brain already holds memories, but setup is not finished. Complete the remaining steps below.', 'agent-brain')
				: __('Nothing has been remembered yet. Four steps and your first memory will show up here.', 'agent-brain')
		) . '</p>';
		$out .= '<ol class="ab-checklist">';

		foreach ($steps as [$isDone, $label, $why, $url, $cap]) {
			$text = esc_html($label);
			if (! $isDone && current_user_can($cap)) {
				$text = '<a href="' . esc_url($url) . '">' . $text . '</a>';
			}

			$out .= '<li class="' . ($isDone ? 'ab-step-done' : 'ab-step-todo') . '"><span class="ab-step-mark" aria-hidden="true">' . ($isDone ? '&#10003;' : '&#9675;') . '</span> '
				. '<strong>' . $text . '</strong>'
				. ($isDone ? ' <span class="screen-reader-text">' . esc_html__('(done)', 'agent-brain') . '</span>' : '')
				. '<br /><span class="description">' . esc_html($why) . '</span></li>';
		}

		return $out . '</ol></details>';
	}

	/**
	 * @param array<int, array{id:int, agent:string, agent_id:int, action:string, status:string, ts:string}> $rows
	 */
	private static function renderActivity(array $rows): void {
		echo '<section class="ab-ui-card ab-ov-activity"><h2 class="ab-ui-kicker">' . esc_html__('Live activity', 'agent-brain') . '</h2>';
		echo '<ol class="ab-feed" id="ab-activity">';

		if ($rows === []) {
			echo '<li class="ab-empty">';
			EmptyState::render(
				__('No activity yet', 'agent-brain'),
				__('This feed shows what agents do as they work. No agent has used the brain yet.', 'agent-brain'),
				['label' => __('Connect an AI', 'agent-brain'), 'url' => admin_url('admin.php?page=' . ConnectPage::SLUG)]
			);
			echo '</li>';
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- avatar()/timeTag() escape internally; every other value goes through esc_*().
		foreach ($rows as $row) {
			echo '<li class="ab-feed-item" data-id="' . esc_attr((string) $row['id']) . '">'
				. self::avatar($row['agent'], $row['agent_id'])
				. '<div class="ab-feed-body"><span class="ab-feed-text"><strong>' . esc_html($row['agent']) . '</strong> ' . esc_html($row['action']) . '</span>'
				. ($row['status'] !== 'ok' && $row['status'] !== '' ? ' <span class="ab-badge ab-badge-' . esc_attr($row['status']) . '">' . esc_html($row['status']) . '</span>' : '')
				. self::timeTag($row['ts']) . '</div></li>';
		}

		// phpcs:enable

		echo '</ol>';
		if (current_user_can(ViewerPage::CAPABILITY)) {
			echo '<a class="ab-ui-more" href="' . esc_url(admin_url('admin.php?page=' . ViewerPage::SLUG)) . '">' . esc_html__('All activity', 'agent-brain') . '</a>';
		}
		echo '</section>';
	}

	/**
	 * @param array<int, array{id:int, agent:string, agent_id:int, scope:string, type:string, excerpt:string, ts:string}> $rows
	 */
	private static function renderMemories(array $rows, bool $scopeless = false): void {
		echo '<section class="ab-ui-card ab-ov-memories"><h2 class="ab-ui-kicker">' . esc_html__('Latest memories', 'agent-brain') . '</h2>';
		echo '<ol class="ab-feed" id="ab-memories">';

		if ($rows === [] && $scopeless) {
			echo '<li class="ab-empty"><p class="description">'
				. esc_html__('You hold no scopes, so memories are hidden here; see Memories.', 'agent-brain')
				. ' <a href="' . esc_url(admin_url('admin.php?page=' . \AgentBrain\Modules\MemoriesAdmin\Page::SLUG)) . '">' . esc_html__('Open Memories', 'agent-brain') . '</a></p></li>';
		} elseif ($rows === []) {
			echo '<li class="ab-empty">';
			EmptyState::render(
				__('No memories in your scopes yet', 'agent-brain'),
				__('Memories are what agents save while they work. None have been saved in a scope you can read.', 'agent-brain'),
				['label' => __('Connect an AI', 'agent-brain'), 'url' => admin_url('admin.php?page=' . ConnectPage::SLUG)]
			);
			echo '</li>';
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- avatar()/timeTag() escape internally; every other value goes through esc_*().
		foreach ($rows as $row) {
			echo '<li class="ab-feed-item" data-id="' . esc_attr((string) $row['id']) . '">'
				. self::avatar($row['agent'], $row['agent_id'])
				. '<div class="ab-feed-body"><p class="ab-memory">' . esc_html($row['excerpt']) . '</p>'
				. '<span class="ab-meta"><strong>' . esc_html($row['agent']) . '</strong>'
				. ($row['scope'] !== '' ? ' <span class="ab-chip">' . esc_html($row['scope']) . '</span>' : '')
				. ' <span class="ab-type">' . esc_html($row['type']) . '</span>'
				. self::timeTag($row['ts']) . '</span></div></li>';
		}

		// phpcs:enable

		echo '</ol></section>';
	}

	private static function avatar(string $name, int $id): string {
		$initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1));

		return '<span class="ab-avatar ab-hue-' . esc_attr((string) (abs($id) % 6)) . '" aria-hidden="true">' . esc_html($initial) . '</span>';
	}

	private static function timeTag(string $iso): string {
		if ($iso === '') {
			return '';
		}

		$timestamp = strtotime($iso);
		$relative  = $timestamp !== false && function_exists('human_time_diff')
			? sprintf(
				/* translators: %s: human-readable time difference, e.g. "5 minutes" */
				__('%s ago', 'agent-brain'),
				human_time_diff($timestamp, time())
			)
			: $iso;

		return ' <time class="ab-time" datetime="' . esc_attr($iso) . '">' . esc_html($relative) . '</time>';
	}

	/**
	 * Counted for the current user: artifacts outside their scopes are left
	 * out (#552).
	 *
	 * @return array<string, int>
	 */
	public static function artifactCountsByStage(): array {
		$counts = array_fill_keys(Cpts::stages(), 0);

		if (! class_exists(Cpts::class)) {
			return $counts;
		}

		global $wpdb;

		$sql = 'SELECT pm.meta_value AS stage, COUNT(*) AS c FROM ' . $wpdb->posts . ' p '
			. 'INNER JOIN ' . $wpdb->postmeta . ' pm ON pm.post_id = p.ID AND pm.meta_key = %s '
			. "WHERE p.post_type = %s AND p.post_status != 'trash'";
		$params = [Cpts::META_STAGE, Cpts::ARTIFACT_POST_TYPE];

		$hidden = ScopeVisibility::hiddenPostIds(get_current_user_id(), Cpts::ARTIFACT_POST_TYPE);
		if ($hidden !== []) {
			$sql   .= ' AND p.ID NOT IN (' . implode(', ', array_fill(0, count($hidden), '%d')) . ')';
			$params = array_merge($params, $hidden);
		}

		$rows = Db::getResults($sql . ' GROUP BY pm.meta_value', $params);

		foreach ($rows as $row) {
			$stage = (string) ($row['stage'] ?? '');
			if (array_key_exists($stage, $counts)) {
				$counts[$stage] = (int) ($row['c'] ?? 0);
			}
		}

		return $counts;
	}

	/**
	 * Count of distinct ab_agent-role actors with at least one audit row in
	 * the last 7 days. Bounded: the WHERE clause uses the indexed `ts`
	 * column and the DISTINCT actor scan itself is capped at
	 * ACTIVE_AGENTS_SCAN_LIMIT rows.
	 */
	public static function activeAgentsLast7Days(): int {
		if (! function_exists('current_time')) {
			return 0;
		}

		$since = gmdate('Y-m-d H:i:s', current_time('timestamp', true) - 7 * DAY_IN_SECONDS);

		$sql = 'SELECT DISTINCT actor_id FROM ' . Repository::tableName()
			. ' WHERE ts >= %s AND actor_id > 0 LIMIT %d';

		$rows = Db::getResults($sql, [$since, self::ACTIVE_AGENTS_SCAN_LIMIT]);

		$count = 0;
		foreach ($rows as $row) {
			$actorId = isset($row['actor_id']) ? (int) $row['actor_id'] : 0;
			if ($actorId <= 0) {
				continue;
			}

			$user = function_exists('get_userdata') ? get_userdata($actorId) : false;
			if ($user instanceof \WP_User && in_array(Capabilities::ROLE_AGENT, (array) $user->roles, true)) {
				$count++;
			}
		}

		return $count;
	}
}
