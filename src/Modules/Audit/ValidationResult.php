<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Audit;

/**
 * Result of EventValidator::validate(). Immutable value object so validation
 * errors travel with the (possibly garbage) input that produced them, for
 * building a "malformed event" audit row without ever re-deriving raw input.
 */
final class ValidationResult {

	/**
	 * @param array<int, string> $errors
	 * @param array<mixed>       $raw
	 */
	public function __construct(
		public readonly bool $valid,
		public readonly array $errors,
		public readonly array $raw
	) {
	}
}
