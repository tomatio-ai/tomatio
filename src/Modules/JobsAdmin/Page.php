<?php

declare(strict_types=1);

namespace AgentBrain\Modules\JobsAdmin;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Audit\Repository;
use AgentBrain\Modules\Cloud\Connector;
use AgentBrain\Modules\Cloud\Settings;
use AgentBrain\Modules\Compression\Endpoint;
use AgentBrain\Modules\Compression\ProviderSelector;
use AgentBrain\Modules\Compression\Runner;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Review\InboxItems;

/**
 * "Jobs" admin screen. Capability: ab_manage (same as Settings and the Cloud
 * tab it summarises).
 *
 * Failure data comes from the audit log (`compression.*` events written by
 * Compression\Runner / CloudProvider). The provider state is computed with
 * ProviderSelector::decide() from live options, never ProviderSelector::
 * select(), so merely viewing the page does not change stored state.
 *
 * Retry: the Runner has no per-job queue. Groups that failed are simply left
 * unprocessed, and every run re-selects all pending rows, so "Retry" means
 * "run the compression pass now" (Runner::run()), exactly what the hourly
 * cron does.
 */
final class Page {

	public const CAPABILITY   = Capabilities::CAP_MANAGE;
	public const SLUG         = 'agent-brain-jobs';
	public const RETRY_ACTION = 'agent_brain_jobs_retry';
	public const RUN_LIMIT    = 50;

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addMenu']);
		add_action('admin_post_' . self::RETRY_ACTION, [self::class, 'handleRetry']);
	}

	public static function addMenu(): void {
		add_submenu_page(
			Menu::PARENT,
			__('Jobs', 'agent-brain'),
			__('Jobs', 'agent-brain'),
			self::CAPABILITY,
			self::SLUG,
			[self::class, 'render']
		);
	}

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to view Jobs.', 'agent-brain'), 403);
		}

		$provider = self::providerState();
		$runs     = self::recentRuns();
		$canRun   = $provider['provider'] !== null;

		// #751: the state and the one thing to do come first; how it runs and
		// the run log follow, and the explanation folds away.
		$head = Ui::head(
			__('Jobs', 'agent-brain'),
			self::statusLine($provider, $runs),
			$canRun && Runs::failureCount($runs) === 0 ? self::retryForm(__('Run now', 'agent-brain')) : '',
			self::about($provider)
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo '<div class="wrap ab-admin ab-admin-jobs">' . $head;

		self::renderNotice();
		self::renderState($provider, $runs);

		self::renderProvider($provider);
		self::renderRuns($runs);
		echo '</div>';
	}

	/**
	 * @param array{provider: ?string, label: string, connected: bool, cloud_enabled: bool, wp_ai: bool, degraded: string, next_run: string, failing: bool} $p
	 * @param array<int, array{ts:string, outcome:string, agent_id:int, scope_id:int, reason:string, message:string}> $runs
	 */
	private static function statusLine(array $p, array $runs): string {
		if ($p['provider'] === null) {
			$state = __('off', 'agent-brain');
		} elseif ($p['failing']) {
			$state = __('failing', 'agent-brain');
		} elseif ($runs === []) {
			$state = __('waiting for the first run', 'agent-brain');
		} else {
			$state = __('working', 'agent-brain');
		}

		/* translators: %s: off, failing, waiting for the first run or working */
		return sprintf(esc_html__('Summarising old memories: %s', 'agent-brain'), '<b>' . esc_html($state) . '</b>');
	}

	/**
	 * @param array{provider: ?string, label: string, connected: bool, cloud_enabled: bool, wp_ai: bool, degraded: string, next_run: string, failing: bool} $p
	 */
	private static function about(array $p): string {
		$out = '<p>' . esc_html__('Compression turns raw working memory into short episode summaries and durable facts. This page shows the provider doing that work and how recent runs went.', 'agent-brain') . '</p>';

		if (! $p['connected']) {
			$out .= '<p>' . esc_html__('Not connected means this site is on the free tier, which is complete without the cloud: compression uses your OpenAI-compatible endpoint or the WordPress AI client with your own provider key if one is configured. Without either, compression stays off and memories roll over with the built-in digest, which is lossless but not summarised.', 'agent-brain') . '</p>';
		}

		return $out . '<p>' . esc_html__('Failed groups are left unprocessed, so running the pass again retries them.', 'agent-brain') . '</p>';
	}

	/**
	 * One callout: what is wrong and the one thing to do, or one quiet line.
	 *
	 * @param array{provider: ?string, label: string, connected: bool, cloud_enabled: bool, wp_ai: bool, degraded: string, next_run: string, failing: bool} $p
	 * @param array<int, array{ts:string, outcome:string, agent_id:int, scope_id:int, reason:string, message:string}> $runs
	 */
	private static function renderState(array $p, array $runs): void {
		$failures = Runs::failureCount($runs);

		if ($p['provider'] === null) {
			$html = Ui::callout(
				'warn',
				__('Summarising is off.', 'agent-brain'),
				esc_html__('No compression provider is available, so no jobs will run until you add an OpenAI-compatible endpoint, connect the cloud or configure the WordPress AI client. Nothing is lost: memories roll over with the built-in digest.', 'agent-brain'),
				'<a class="button button-primary" href="' . esc_url(self::settingsUrl()) . '">' . esc_html__('Choose a summarising option in Settings', 'agent-brain') . '</a>'
			);
		} elseif ($failures > 0) {
			$latest = '';
			foreach ($runs as $run) {
				if ($run['outcome'] === 'failed') {
					$latest = Runs::describe($run['reason'], $run['message']);
					break;
				}
			}
			$title = $p['failing']
				/* translators: %d: number of failed runs */
				? sprintf(_n('The last run failed.', 'The last %d runs failed.', $failures, 'agent-brain'), $failures)
				/* translators: 1: failed runs, 2: runs listed */
				: sprintf(__('%1$d of the last %2$d runs failed.', 'agent-brain'), $failures, count($runs));
			$html  = Ui::callout(
				$p['failing'] ? 'bad' : 'warn',
				$title,
				esc_html(trim($latest . ' ' . __('Nothing is lost: memories wait and are summarised on the next good run.', 'agent-brain'))),
				'<a class="button button-primary" href="' . esc_url(self::settingsUrl()) . '">' . esc_html__('Fix in Settings', 'agent-brain') . '</a> '
					. self::retryForm(__('Try again now', 'agent-brain'))
			);
		} elseif ($runs === []) {
			$html = Ui::callout('info', __('No runs yet.', 'agent-brain'), esc_html__('The pass runs hourly once there is memory to compress, and every outcome appears here.', 'agent-brain'));
		} else {
			$html = Ui::callout(
				'ok',
				__('Summarising works.', 'agent-brain'),
				$p['next_run'] !== ''
					/* translators: %s: date and time of the next run */
					? esc_html(sprintf(__('Next run: %s.', 'agent-brain'), $p['next_run']))
					: ''
			);
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::callout() escapes what it emits.
		echo $html;
	}

	private static function retryForm(string $label): string {
		return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
			. wp_nonce_field(self::RETRY_ACTION, '_wpnonce', false, false)
			. '<input type="hidden" name="action" value="' . esc_attr(self::RETRY_ACTION) . '" />'
			. '<button type="submit" class="button">' . esc_html($label) . '</button></form>';
	}

	private static function settingsUrl(): string {
		return class_exists(Settings::class) ? Settings::hubRedirectUrl() : admin_url('admin.php?page=agent-brain-settings');
	}

	/**
	 * @return array{provider: ?string, label: string, connected: bool, cloud_enabled: bool, wp_ai: bool, degraded: string, next_run: string, failing: bool}
	 */
	public static function providerState(): array {
		$connected = class_exists(Connector::class) && Connector::isConnected();
		$enabled   = (bool) get_option('ab_cloud_enabled', false);
		$wpAi      = function_exists('wp_supports_ai') && \wp_supports_ai();
		$decision  = ProviderSelector::decide($connected, $enabled, $wpAi, Endpoint::isConfigured());

		$labels = [
			'openai_compatible' => __('Your OpenAI-compatible endpoint', 'agent-brain'),
			'cloud'        => __('Cloud', 'agent-brain'),
			'wp_ai_client' => __('WordPress AI client (free tier, your own key)', 'agent-brain'),
		];

		$next = wp_next_scheduled(Runner::CRON_HOOK);

		return [
			'provider'      => $decision,
			'label'         => $decision !== null ? $labels[$decision] : __('None (compression is disabled)', 'agent-brain'),
			'connected'     => $connected,
			'cloud_enabled' => $enabled,
			'wp_ai'         => $wpAi,
			'degraded'      => (string) get_option(ProviderSelector::OPTION_DEGRADED_REASON, ''),
			'next_run'      => $next ? Runs::formatTime(gmdate('Y-m-d H:i:s', (int) $next)) : '',
			'failing'       => Runs::allFailing(self::recentRuns()),
		];
	}

	/**
	 * @param array{provider: ?string, label: string, connected: bool, cloud_enabled: bool, wp_ai: bool, degraded: string, next_run: string, failing: bool} $p
	 */
	private static function renderProvider(array $p): void {
		$facts = Ui::facts([
			__('Current provider', 'agent-brain')    => esc_html($p['label']),
			__('Next scheduled run', 'agent-brain')  => esc_html($p['next_run'] !== '' ? $p['next_run'] : __('Not scheduled', 'agent-brain')),
			__('Cloud', 'agent-brain')               => esc_html($p['connected'] ? __('Connected', 'agent-brain') : __('Not connected', 'agent-brain'))
				. ($p['connected'] && ! $p['cloud_enabled'] ? ' ' . esc_html__('(connected but not enabled for compression)', 'agent-brain') : ''),
			__('WordPress AI client', 'agent-brain') => esc_html($p['wp_ai'] ? ($p['failing'] ? __('Available but failing', 'agent-brain') : __('Available', 'agent-brain')) : __('Not available', 'agent-brain')),
		]);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::card(__('How it runs', 'agent-brain'), $facts . '<p><a class="ab-ui-more" href="' . esc_url(self::settingsUrl()) . '">' . esc_html__('Summarising settings', 'agent-brain') . '</a></p>', 'ab-ui-card--narrow');
	}

	/**
	 * @param array<int, array{ts:string, outcome:string, agent_id:int, scope_id:int, reason:string, message:string}> $runs
	 */
	private static function renderRuns(array $runs): void {
		echo '<section class="ab-ui-card"><h2 class="ab-ui-kicker">' . esc_html(sprintf(
			/* translators: %d: maximum number of runs listed */
			__('Recent runs (last %d)', 'agent-brain'),
			self::RUN_LIMIT
		)) . '</h2>';

		if ($runs === []) {
			echo '<p class="ab-empty">' . esc_html__('No compression runs have been recorded yet. The pass runs hourly once there is memory to compress, and every outcome appears here.', 'agent-brain') . '</p></section>';

			return;
		}

		echo '<div class="ab-table-wrap"><table class="widefat striped"><thead><tr>'
			. '<th scope="col">' . esc_html__('When (UTC)', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Outcome', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Agent', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Scope', 'agent-brain') . '</th>'
			. '<th scope="col">' . esc_html__('Reason', 'agent-brain') . '</th>'
			. '</tr></thead><tbody>';

		foreach ($runs as $run) {
			$reason = Runs::describe($run['reason'], $run['message']);
			$scope  = $run['scope_id'] > 0 ? get_term($run['scope_id'], Scopes::TAXONOMY) : null;

			echo '<tr>';
			echo '<td>' . esc_html(Runs::formatTime($run['ts'])) . '</td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::tag() escapes.
			echo '<td>' . Ui::tag($run['outcome'], $run['outcome'] === 'failed' ? 'bad' : ($run['outcome'] === 'completed' ? 'ok' : '')) . '</td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- agentLabel() escapes.
			echo '<td>' . self::agentLabel($run['agent_id']) . '</td>';
			echo '<td>' . esc_html($scope instanceof \WP_Term ? $scope->name : ($run['scope_id'] > 0 ? '#' . $run['scope_id'] : '-')) . '</td>';
			echo '<td>' . esc_html($reason !== '' ? $reason : '-') . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div></section>';
	}

	/**
	 * The agent as people say it ("growth · Dana's Tío"), with its ID after.
	 */
	private static function agentLabel(int $agentId): string {
		$user = $agentId > 0 ? get_userdata($agentId) : false;
		if (! $user instanceof \WP_User) {
			return esc_html($agentId > 0 ? '#' . $agentId : '-');
		}

		$who  = InboxItems::who($agentId);
		$fqdn = (string) get_user_meta($agentId, AgentMeta::FQDN, true);

		return esc_html($who['label'])
			. ($fqdn !== '' && $fqdn !== $who['label'] ? '<br><span class="ab-ri-account">' . esc_html($fqdn) . '</span>' : '');
	}

	/**
	 * @return array<int, array{ts:string, outcome:string, agent_id:int, scope_id:int, reason:string, message:string}>
	 */
	public static function recentRuns(): array {
		$placeholders = implode(', ', array_fill(0, count(Runs::EVENTS), '%s'));
		$rows         = Db::getResults(
			'SELECT ts, actor_id, event, status, meta FROM ' . Repository::tableName()
			. " WHERE event IN ({$placeholders}) ORDER BY ts DESC, id DESC LIMIT %d",
			array_merge(Runs::EVENTS, [self::RUN_LIMIT])
		);

		return array_map([Runs::class, 'normalise'], $rows);
	}

	public static function handleRetry(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside processRetry().
		wp_safe_redirect(self::processRetry((array) wp_unslash($_POST)));
		exit;
	}

	/**
	 * @param array<string, mixed> $post
	 * @return string Redirect URL.
	 */
	public static function processRetry(array $post): string {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to retry jobs.', 'agent-brain'), 403);
		}

		$nonce = isset($post['_wpnonce']) && is_string($post['_wpnonce']) ? $post['_wpnonce'] : '';
		if (wp_verify_nonce($nonce, self::RETRY_ACTION) === false) {
			wp_die(esc_html__('The link you followed has expired. Please go back and try again.', 'agent-brain'), 403);
		}

		if (self::providerState()['provider'] === null) {
			return self::url(['ab_notice' => 'no_provider']);
		}

		do_action('agent_brain/audit', [
			'actor_id' => (int) get_current_user_id(),
			'event'    => 'compression.retry_requested',
			'status'   => 'ok',
		]);

		Runner::run();

		return self::url(['ab_notice' => 'retried']);
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
			'retried'     => ['success', __('A compression pass was run. New results appear below.', 'agent-brain')],
			'no_provider' => ['error', __('No compression provider is available, so nothing was run.', 'agent-brain')],
		];

		if (isset($messages[$code])) {
			echo '<div class="notice notice-' . esc_attr($messages[$code][0]) . ' is-dismissible" role="' . ('error' === $messages[$code][0] ? 'alert' : 'status') . '"><p>' . esc_html($messages[$code][1]) . '</p></div>';
		}
	}
}
