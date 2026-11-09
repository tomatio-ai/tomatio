<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * Auto-discovers modules from src/Modules/<Name>/Module.php.
 *
 * A module directory is picked up when it contains a Module.php declaring
 * class AgentBrain\Modules\<Name>\Module implementing ModuleInterface.
 * Directories that don't resolve to a valid module are NEVER silently
 * skipped (L26.2, fail-loud-over-default): every rejection is recorded as a
 * typed ModuleFailure via failures(), because a module that fails to load
 * quietly (e.g. Identity or Memory) would mean scope enforcement vanishing
 * without anyone noticing. Callers decide how loud to be (Health/Plugin);
 * this class's job is only to never lose the fact that something failed.
 */
class ModuleRegistry {

	/** @var array<string, ModuleInterface> id => module instance */
	private array $modules = [];

	/** @var array<int, ModuleFailure> */
	private array $failures = [];

	private string $modulesDir;

	public function __construct(?string $modulesDir = null) {
		$this->modulesDir = $modulesDir ?? __DIR__ . '/../Modules';
	}

	/**
	 * Scan the modules directory and instantiate every valid module.
	 * Never throws: a broken module directory is recorded as a failure and
	 * discovery continues for the rest, but nothing is dropped silently.
	 *
	 * @return array<string, ModuleInterface>
	 */
	public function discover(): array {
		$this->modules  = [];
		$this->failures = [];

		if (! is_dir($this->modulesDir)) {
			return $this->modules;
		}

		$entries = scandir($this->modulesDir);
		if ($entries === false) {
			return $this->modules;
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$moduleFile = $this->modulesDir . '/' . $entry . '/Module.php';
			if (! is_file($moduleFile)) {
				continue;
			}

			$this->discoverOne($entry, $moduleFile);
		}

		return $this->modules;
	}

	private function discoverOne(string $entry, string $moduleFile): void {
		try {
			require_once $moduleFile;

			$class = 'AgentBrain\\Modules\\' . $entry . '\\Module';
			if (! class_exists($class)) {
				$this->recordFailure(
					$entry,
					ModuleFailure::REASON_MISSING_CLASS,
					"Expected class {$class} was not declared after requiring {$moduleFile}."
				);

				return;
			}

			$instance = new $class();
			if (! $instance instanceof ModuleInterface) {
				$this->recordFailure(
					$entry,
					ModuleFailure::REASON_NOT_MODULE,
					"{$class} does not implement " . ModuleInterface::class . '.'
				);

				return;
			}

			$id = $instance->id();
			if (array_key_exists($id, $this->modules)) {
				$this->recordFailure(
					$entry,
					ModuleFailure::REASON_DUPLICATE_ID,
					"Module id '{$id}' is already registered by another module directory."
				);

				return;
			}

			$this->modules[$id] = $instance;
		} catch (\Throwable $e) {
			$this->recordFailure(
				$entry,
				ModuleFailure::REASON_EXCEPTION,
				sprintf('%s: %s', get_class($e), $e->getMessage())
			);
		}
	}

	private function recordFailure(string $dir, string $reason, string $message): void {
		$this->failures[] = new ModuleFailure($dir, $reason, $message);
	}

	/**
	 * @return array<string, ModuleInterface>
	 */
	public function all(): array {
		return $this->modules;
	}

	public function get(string $id): ?ModuleInterface {
		return $this->modules[$id] ?? null;
	}

	/**
	 * Typed failures collected by the most recent discover() call. Empty
	 * means every module directory found resolved cleanly.
	 *
	 * @return array<int, ModuleFailure>
	 */
	public function failures(): array {
		return $this->failures;
	}

	public function registerAll(): void {
		foreach ($this->modules as $module) {
			$module->register();
		}
	}
}
