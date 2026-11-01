<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Ops;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;

/**
 * OPS-2 admin page: curator-facing (`ab_manage`) self-check listing every
 * contract check with pass/warn/fail + remediation text.
 *
 * Admin IA wave (Unit S): this used to be its own admin_management page.
 * It is now the Self-Check tab of SettingsHub (agent-brain-settings); the
 * old slug (agent-brain-ops) stays registered as a hidden page under the
 * core-hidden `options.php` parent purely so old bookmarks/links redirect
 * to the hub instead of 404ing.
 *
 * #751: shown to people as "Health". Only what needs fixing is open, each
 * as a callout with its fix; the checks that pass fold into one line.
 */
final class SelfCheckPage {

	public const CAPABILITY = 'ab_manage';
	public const SLUG       = 'agent-brain-ops';

	public static function register(): void {
		add_action('admin_menu', [self::class, 'addMenu']);
	}

	public static function addMenu(): void {
		$hook = add_submenu_page(
			'options.php',
			__('TomaTío Self-Check', 'agent-brain'),
			__('TomaTío Self-Check', 'agent-brain'),
			self::CAPABILITY,
			self::SLUG,
			[self::class, 'render']
		);

		if (is_string($hook)) {
			add_action('load-' . $hook, [self::class, 'redirectToHub']);
		}
	}

	/**
	 * Fired on `load-{hook}` for the old agent-brain-ops slug, before any
	 * output — forwards straight to the Self-Check tab of the settings
	 * hub, preserving whatever query args the old link carried (minus
	 * `page`).
	 */
	public static function redirectToHub(): void {
		wp_safe_redirect(self::hubRedirectUrl());
		exit;
	}

	public static function hubRedirectUrl(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect, no state change.
		$args = wp_unslash($_GET);
		unset($args['page']);

		/** @var array<string, string> $args */
		return SettingsHub::url(SettingsHub::TAB_SELF_CHECK, $args);
	}

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to view this page.', 'agent-brain'), 403);
		}

		echo '<div class="wrap"><h1>' . esc_html__('TomaTío Self-Check', 'agent-brain') . '</h1>';
		self::renderBody();
		echo '</div>';
	}

	/**
	 * The tab body, without the outer `<div class="wrap"><h1>` — called by
	 * SettingsHub for the Health tab (capability already checked there),
	 * which passes the checks it already ran.
	 *
	 * @param array<int, array{id:string, label:string, status:string, message:string, remediation:string}>|null $checks
	 */
	public static function renderBody(?array $checks = null): void {
		$checks  = $checks ?? SelfCheck::run();
		$passing = [];
		$out     = '';

		// Failures first, then warnings: each says what is wrong and how to fix it.
		foreach ([SelfCheck::STATUS_FAIL, SelfCheck::STATUS_WARN] as $status) {
			foreach ($checks as $check) {
				if ($check['status'] !== $status) {
					continue;
				}
				$fix  = trim($check['remediation']) !== ''
					? '<details class="ab-ui-set-more"><summary>' . esc_html__('How to fix', 'agent-brain') . '</summary><pre class="ab-ui-fix">' . esc_html($check['remediation']) . '</pre></details>'
					: '';
				$out .= Ui::callout($status === SelfCheck::STATUS_FAIL ? 'bad' : 'warn', $check['label'] . ':', esc_html($check['message']), $fix);
			}
		}

		foreach ($checks as $check) {
			if ($check['status'] === SelfCheck::STATUS_PASS) {
				$passing[] = $check;
			}
		}

		if ($out === '') {
			$out = Ui::callout('ok', __('Every check passes.', 'agent-brain'), esc_html__('This site is set up the way TomaTío needs.', 'agent-brain'));
		}

		if ($passing !== []) {
			$out .= '<details class="ab-ui-card ab-ui-fold"><summary><span class="ab-ui-dot ab-ui-dot--ok"></span>'
				/* translators: %d: number of checks that pass */
				. esc_html(sprintf(_n('%d check passes', '%d checks pass', count($passing), 'agent-brain'), count($passing)))
				. '</summary><ul class="ab-ui-checks">';
			foreach ($passing as $check) {
				$out .= '<li><span>' . esc_html($check['label']) . '</span><small>' . esc_html($check['message']) . '</small></li>';
			}
			$out .= '</ul></details>';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts and Ui.
		echo $out;
	}

	/**
	 * The Health tab's status line: "1 warning · 9 checks pass".
	 *
	 * @param array<int, array{id:string, label:string, status:string, message:string, remediation:string}> $checks
	 */
	public static function statusHtml(array $checks): string {
		$count = [SelfCheck::STATUS_FAIL => 0, SelfCheck::STATUS_WARN => 0, SelfCheck::STATUS_PASS => 0];
		foreach ($checks as $check) {
			$count[$check['status']] = ($count[$check['status']] ?? 0) + 1;
		}

		$parts = [];
		if ($count[SelfCheck::STATUS_FAIL] > 0) {
			/* translators: %s: number of failing checks, in bold */
			$parts[] = sprintf(esc_html(_n('%s problem', '%s problems', $count[SelfCheck::STATUS_FAIL], 'agent-brain')), '<b>' . (int) $count[SelfCheck::STATUS_FAIL] . '</b>');
		}
		if ($count[SelfCheck::STATUS_WARN] > 0) {
			/* translators: %s: number of checks with a warning, in bold */
			$parts[] = sprintf(esc_html(_n('%s warning', '%s warnings', $count[SelfCheck::STATUS_WARN], 'agent-brain')), '<b>' . (int) $count[SelfCheck::STATUS_WARN] . '</b>');
		}
		/* translators: %d: number of checks that pass */
		$parts[] = esc_html(sprintf(_n('%d check passes', '%d checks pass', $count[SelfCheck::STATUS_PASS], 'agent-brain'), $count[SelfCheck::STATUS_PASS]));

		return '<p class="ab-ui-status">' . implode(' · ', $parts) . '</p>';
	}
}
