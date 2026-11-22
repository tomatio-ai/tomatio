<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

/**
 * An export that could not be completed. The code is a PCP error code
 * (SPEC section 6.3): too_large when the build ran past its ceiling,
 * forbidden for a user who may not export, unsupported when archives cannot
 * be held (stream-only mode), internal for everything else. Messages never
 * contain memory content.
 */
final class ExportFailed extends \RuntimeException {

	public function __construct(private string $errorCode, string $message) {
		parent::__construct($message);
	}

	public function errorCode(): string {
		return $this->errorCode;
	}
}
