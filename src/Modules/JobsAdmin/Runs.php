<?php

declare(strict_types=1);

namespace AgentBrain\Modules\JobsAdmin;

/**
 * Pure normaliser for compression audit rows (no WordPress calls so it is
 * unit-testable). Turns a raw ab_audit row (with its JSON `meta` string)
 * into the flat shape the Jobs screen renders.
 */
final class Runs {

	public const EVENT_COMPLETED = 'compression.job_completed';
	public const EVENT_FAILED    = 'compression.job_failed';
	public const EVENT_DISABLED  = 'compression.disabled';
	public const EVENT_BLOCKED   = 'compression.spend_blocked';

	/** @var array<int, string> */
	public const EVENTS = [
		self::EVENT_COMPLETED,
		self::EVENT_FAILED,
		self::EVENT_DISABLED,
		self::EVENT_BLOCKED,
	];

	/**
	 * @param array<string, mixed> $row
	 * @return array{ts:string, outcome:string, agent_id:int, scope_id:int, reason:string, message:string}
	 */
	public static function normalise(array $row): array {
		$event = (string) ($row['event'] ?? '');
		$meta  = [];

		if (isset($row['meta']) && is_string($row['meta']) && $row['meta'] !== '') {
			$decoded = json_decode($row['meta'], true);
			$meta    = is_array($decoded) ? $decoded : [];
		}

		return [
			'ts'       => (string) ($row['ts'] ?? ''),
			'outcome'  => self::outcome($event),
			'agent_id' => (int) ($row['actor_id'] ?? 0),
			'scope_id' => (int) ($meta['scope_id'] ?? 0),
			'reason'   => is_scalar($meta['reason'] ?? null) ? (string) $meta['reason'] : '',
			'message'  => is_scalar($meta['message'] ?? null) ? (string) $meta['message'] : '',
		];
	}

	public static function outcome(string $event): string {
		return match ($event) {
			self::EVENT_COMPLETED => 'completed',
			self::EVENT_FAILED    => 'failed',
			self::EVENT_DISABLED  => 'disabled',
			self::EVENT_BLOCKED   => 'blocked',
			default               => 'other',
		};
	}

	/**
	 * @param array<int, array{outcome:string}> $runs
	 */
	public static function failureCount(array $runs): int {
		return count(array_filter($runs, static fn (array $r): bool => $r['outcome'] === 'failed'));
	}

	/**
	 * Plain-language explanations for known machine error codes. Matched
	 * against the audit reason and message; the raw code stays visible next
	 * to the explanation.
	 *
	 * @return array<string, string>
	 */
	public static function explanations(): array {
		return [
			'prompt_invalid_argument'  => 'The AI provider rejected the request; check the provider key and model in Settings.',
			'prompt_no_provider'       => 'No AI provider is configured; add a provider key in Settings.',
			'ab_compression_provider_no_output' => 'The provider returned nothing; check the provider key and model in Settings.',
			'cloud_job_error'          => 'The cloud compression job failed; check the Cloud connection in Settings.',
		];
	}

	/**
	 * "Explanation (code)" for a known code, else the raw "reason: message".
	 */
	public static function describe(string $reason, string $message): string {
		$raw = $reason . ($message !== '' ? ': ' . $message : '');

		foreach (self::explanations() as $code => $text) {
			if ($reason === $code || str_contains($message, $code)) {
				return $text . ' (' . $code . ')';
			}
		}

		return $raw;
	}

	/**
	 * "Oct 21, 07:56 UTC" from a UTC "Y-m-d H:i:s[.fff]" stamp; the input is
	 * returned unchanged when it cannot be parsed.
	 */
	public static function formatTime(string $ts): string {
		$time = strtotime($ts . ' UTC');

		return $time === false ? $ts : gmdate('M j, H:i', $time) . ' UTC';
	}

	/**
	 * True when there were runs and every one that actually attempted work
	 * (completed or failed) failed.
	 *
	 * @param array<int, array{outcome:string}> $runs
	 */
	public static function allFailing(array $runs): bool {
		$attempted = array_filter($runs, static fn (array $r): bool => in_array($r['outcome'], ['completed', 'failed'], true));

		return $attempted !== [] && self::failureCount($attempted) === count($attempted);
	}
}
