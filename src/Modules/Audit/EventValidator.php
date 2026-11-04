<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Audit;

/**
 * Validates the shape of an `agent_brain/audit` event array before it is
 * persisted (contracts: actor_id:int, event:string, status:'ok'|'denied'|'error',
 * object_type?:string, object_id?:string, meta?:array, plus this module's own
 * optional args_hash/duration_ms columns).
 *
 * Never throws: callers always get a result object back, even for
 * completely malformed input, so a malformed event can be recorded as an
 * error row instead of being silently dropped (L26 fail-loud).
 */
final class EventValidator {

	public const STATUSES = ['ok', 'denied', 'error'];

	/**
	 * @param mixed $rawEvent Whatever was passed to do_action('agent_brain/audit', ...).
	 */
	public static function validate($rawEvent): ValidationResult {
		$errors = [];

		if (! is_array($rawEvent)) {
			return new ValidationResult(false, ['event payload is not an array'], []);
		}

		$actorId = $rawEvent['actor_id'] ?? null;
		if (! is_int($actorId) || $actorId < 0) {
			$errors[] = 'actor_id must be a non-negative int';
		}

		$event = $rawEvent['event'] ?? null;
		if (! is_string($event) || $event === '') {
			$errors[] = 'event must be a non-empty string';
		}

		$status = $rawEvent['status'] ?? null;
		if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
			$errors[] = 'status must be one of: ' . implode('|', self::STATUSES);
		}

		foreach (['object_type', 'object_id', 'args_hash'] as $optionalString) {
			if (array_key_exists($optionalString, $rawEvent) && $rawEvent[$optionalString] !== null && ! is_string($rawEvent[$optionalString])) {
				$errors[] = "{$optionalString} must be a string when present";
			}
		}

		if (array_key_exists('args_hash', $rawEvent) && is_string($rawEvent['args_hash']) && ! preg_match('/^[0-9a-f]{64}$/', $rawEvent['args_hash'])) {
			$errors[] = 'args_hash must be a 64-char lowercase hex sha256 digest';
		}

		if (array_key_exists('meta', $rawEvent) && $rawEvent['meta'] !== null && ! is_array($rawEvent['meta'])) {
			$errors[] = 'meta must be an array when present';
		}

		if (array_key_exists('duration_ms', $rawEvent) && $rawEvent['duration_ms'] !== null
			&& ! (is_int($rawEvent['duration_ms']) || is_float($rawEvent['duration_ms']))) {
			$errors[] = 'duration_ms must be numeric when present';
		}

		if ($errors !== []) {
			return new ValidationResult(false, $errors, $rawEvent);
		}

		return new ValidationResult(true, [], $rawEvent);
	}
}
