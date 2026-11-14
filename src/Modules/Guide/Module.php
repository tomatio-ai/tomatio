<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Guide;

use AgentBrain\Core\ModuleInterface;

/**
 * #861: the TomaTío manual in every brain: the daily fetch from tomatio.ai,
 * the tomatio-help ability and TomaTío > Help.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'guide';
	}

	public function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action(Manual::CRON_HOOK, [Manual::class, 'runScheduled']);
		add_action('wp_abilities_api_init', [HelpAbility::class, 'register']);
		HelpPage::register();
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [
			// Brains that update to this version fetch the manual too.
			1 => static function (): void {
				Manual::schedule();
			},
		];
	}

	public function activate(): void {
		Manual::schedule();
	}

	public function deactivate(): void {
		wp_clear_scheduled_hook(Manual::CRON_HOOK);
	}
}
