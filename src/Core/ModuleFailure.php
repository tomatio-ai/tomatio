<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * A single module directory that failed discovery. Immutable value object
 * so ModuleRegistry::failures() and Health can both surface the same facts
 * without either mutating them.
 */
final class ModuleFailure {

	public const REASON_MISSING_CLASS = 'missing_class';
	public const REASON_NOT_MODULE    = 'not_module';
	public const REASON_EXCEPTION     = 'exception';
	public const REASON_DUPLICATE_ID  = 'duplicate_id';

	private string $dir;

	private string $reason;

	private string $message;

	public function __construct(string $dir, string $reason, string $message) {
		$this->dir     = $dir;
		$this->reason  = $reason;
		$this->message = $message;
	}

	public function dir(): string {
		return $this->dir;
	}

	public function reason(): string {
		return $this->reason;
	}

	public function message(): string {
		return $this->message;
	}

	/**
	 * @return array{dir: string, reason: string, message: string}
	 */
	public function toArray(): array {
		return [
			'dir'     => $this->dir,
			'reason'  => $this->reason,
			'message' => $this->message,
		];
	}
}
