<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Core\ModuleInterface;

/**
 * Admin IA wave (ledger ecc6ce42), Unit U: owns the single "TomaTío"
 * top-level admin menu (Menu), its Overview and Agents pages, and
 * assets/admin.css. Every other TomaTío admin page re-parents itself
 * to Menu::PARENT from its own module; this module only wires the shared
 * menu shell plus the two pages it owns, and — once Unit S's file exists —
 * the Settings hub, so the one call site for it lives in exactly one
 * module regardless of dispatch order between Unit U and Unit S.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'admin';
	}

	public function register(): void {
		Menu::registerTopLevel();
		MenuGroups::register();
		Notices::register();
		ListScreens::register();
		LibraryPage::register();
		ScopeVisibility::register();
		GrantsScreen::register();
		GrantsPage::register();
		HandoffsPage::register();
		OverviewPage::register();
		OverviewRest::register();
		AgentsPage::register();
		ConnectPage::register();
		HomePage::register();
		BrandChrome::register();
		OnboardingSettings::register();
		DedicatedSite::register();
		DedicatedPrompt::register();
		AppLink::register();
		add_action('wp_abilities_api_init', [AppLinkAbilities::class, 'register']);
		SharedInstall::register();

		if (class_exists(SettingsHub::class)) {
			SettingsHub::register();
		}
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [];
	}

	public function activate(): void {
		// Nothing beyond menu/page registration, which register() already
		// handles on every request.
	}

	public function deactivate(): void {
	}
}
