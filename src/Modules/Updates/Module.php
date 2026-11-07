<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Updates;

use AgentBrain\Core\ModuleInterface;

/**
 * Built-in updates from GitHub Releases for self-hosted copies (#353). See
 * Updater. Owns no tables; the release is cached in a site transient.
 */
class Module implements ModuleInterface {

	public function id(): string {
		return 'updates';
	}

	public function register(): void {
		if ( ! function_exists( 'add_filter' ) || ! Updater::enabledFor( Updater::headerUpdateUri() ) ) {
			return;
		}

		add_filter( 'update_plugins_github.com', [ Updater::class, 'offer' ], 10, 3 );
		add_filter( 'upgrader_pre_download', [ Updater::class, 'verifiedDownload' ], 10, 2 );
		add_filter( 'plugins_api', [ Updater::class, 'details' ], 10, 3 );
	}

	/**
	 * @return array<int, callable|string>
	 */
	public function migrations(): array {
		return [];
	}

	public function activate(): void {
		// Nothing to set up; the first update check fetches the release.
	}

	public function deactivate(): void {
		delete_site_transient( Updater::TRANSIENT );
	}
}
