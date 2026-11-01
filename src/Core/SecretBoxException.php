<?php

declare(strict_types=1);

namespace AgentBrain\Core;

/**
 * Raised by SecretBox. The message never contains key material or plaintext;
 * getReason() is one of the SecretBox::REASON_* codes.
 */
final class SecretBoxException extends \RuntimeException {

	private string $reason;

	public function __construct(string $reason, string $message) {
		parent::__construct($message);

		$this->reason = $reason;
	}

	public function getReason(): string {
		return $this->reason;
	}
}
