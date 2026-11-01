<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * A restore or import that was refused or could not finish, with a short
 * machine code (unsafe_archive, invalid_archive, rejected, not_empty,
 * forbidden, conflict, too_large, unsupported, internal) and a message
 * that names no content.
 */
final class ImportFailed extends \RuntimeException {

	public function __construct(private string $errorCode, string $message) {
		parent::__construct($message);
	}

	public function errorCode(): string {
		return $this->errorCode;
	}
}
