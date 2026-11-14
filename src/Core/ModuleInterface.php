<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * Contract every self-registering module under src/Modules/<Name>/Module.php must implement.
 *
 * Rationale: parallel workers add new modules by dropping a directory under src/Modules/
 * and never need to touch shared bootstrap files (Plugin.php, ModuleRegistry.php).
 */
interface ModuleInterface {
	/**
	 * Stable, unique, lowercase-with-hyphens identifier for this module.
	 */
	public function id(): string;

	/**
	 * Register WordPress hooks (actions/filters). Called on every request once the
	 * module is discovered, regardless of activation state.
	 */
	public function register(): void;

	/**
	 * Return an ordered map of version => callable|string (SQL) migrations.
	 * Keys must be monotonically increasing integers starting at 1.
	 *
	 * @return array<int, callable|string>
	 */
	public function migrations(): array;

	/**
	 * Called once on plugin activation (or first discovery after activation).
	 */
	public function activate(): void;

	/**
	 * Called once on plugin deactivation.
	 */
	public function deactivate(): void;
}
