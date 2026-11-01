<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Audit;

/**
 * AUD-2: persistent admin notice naming any plugin(s) hooked on
 * `wp_pre_execute_ability` that aren't us, shown to manage_options users.
 */
final class AdminNotice {

	public static function register(): void {
		add_action('admin_notices', [self::class, 'render']);
	}

	public static function render(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		$foreign = Diagnostics::foreignPreExecuteHooks();
		if ($foreign === []) {
			return;
		}

		$names = [];
		foreach ($foreign as $entry) {
			$names[$entry['plugin']] = true;
		}

		printf(
			'<div class="notice notice-warning" role="status"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated plugin names. */
					__('TomaTío: the following plugin(s) hook wp_pre_execute_ability and can bypass ability permission checks: %s.', 'agent-brain'),
					implode(', ', array_keys($names))
				)
			)
		);
	}
}
