<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Core\ModuleInterface;

/**
 * #987: the extension switches (TíoDay, Tomagic): Settings > Extensions and
 * the extension-list / extension-enable tools. The packages themselves are
 * bundled (Package, PageMaker, PageRefresh); this module only turns them
 * on and off for the brain.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'extensions';
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', [ ExtensionAbilities::class, 'register' ] );
		SettingsTab::register();
		Offer::register();
		// #994: bind requests expire on the Review queue's daily job, and Forget and the personal data tools reach them.
		add_action( \AgentBrain\Modules\Review\Expiry::HOOK, [ BindRequests::class, 'expireDaily' ] );
		add_filter( 'tomatio/forget_collect', [ BindRequests::class, 'forgetCollect' ], 10, 2 );
		add_action( 'tomatio/forget_delete', [ BindRequests::class, 'forgetDelete' ], 10, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', [ BindRequests::class, 'privacyExporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ BindRequests::class, 'privacyErasers' ] );
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [];
	}

	public function activate(): void {
	}

	public function deactivate(): void {
	}
}
