<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

use AgentBrain\Modules\DataStore\TioDayLinks;

/**
 * #1088: whether an issue or pull request on the brain's issue tracker
 * (Settings > Extensions, Issue tracker, #1001) is closed. The same
 * read-only token and repository the Inbox intake uses (IssuesSource,
 * Config); nothing is ever written to the tracker.
 *
 * One read per number per pass, kept for the pass, and at most MAX_READS
 * reads in one pass: what is left comes on the next.
 */
final class Tracker {

	/** Most tracker reads in one pass. */
	public const MAX_READS = 80;

	/** @var array<int, array{state: string, pull: bool, at: string}|null> */
	private static array $states = [];

	private static int $reads = 0;

	/**
	 * What links to the tracker's repository start with, as TioDayLinks::ref()
	 * spells them ("git.example.com/team/project"); '' when no tracker is set.
	 */
	public static function prefix(): string {
		$config = Config::all()['issues'];
		if ( $config['repo'] === '' || $config['api'] === '' ) {
			return '';
		}
		$host = strtolower( (string) wp_parse_url( $config['api'], PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $config['api'], PHP_URL_PATH );
		if ( $host === 'api.github.com' ) {
			$host = 'github.com';
			$path = '';
		}
		// Forgejo's API is the site's address and /api/v1; GitHub Enterprise's, /api/v3.
		$path = strtolower( trim( (string) preg_replace( '#/api/v\d+/?$#', '', $path ), '/' ) );

		return $host === '' ? '' : $host . '/' . ( $path !== '' ? $path . '/' : '' ) . strtolower( $config['repo'] );
	}

	/** The number of the issue or pull request $url points at in the tracker's repository; 0 for any other address. */
	public static function number( string $url ): int {
		$prefix = self::prefix();
		$ref    = $prefix !== '' ? TioDayLinks::ref( $url ) : null;

		return $ref !== null && str_starts_with( $ref, $prefix . '#' ) ? (int) substr( $ref, strlen( $prefix ) + 1 ) : 0;
	}

	/**
	 * Where issue or pull request $number stands: state is open, closed (an
	 * issue), merged (a pull request) or unmerged (a pull request closed
	 * without merging); at is when it closed. Null when it can't be read
	 * (no tracker, no such number, the tracker did not answer, or this
	 * pass has read enough).
	 *
	 * @param array<mixed>|null $json The API's JSON, read instead of the tracker (tests, the command line).
	 * @return array{state: string, pull: bool, at: string}|null
	 */
	public static function state( int $number, ?array $json = null ): ?array {
		if ( $number <= 0 ) {
			return null;
		}
		if ( $json === null && array_key_exists( $number, self::$states ) ) {
			return self::$states[ $number ];
		}
		if ( $json === null && self::$reads >= self::MAX_READS ) {
			return null;
		}
		$source = IssuesSource::configured( $json );
		if ( is_string( $source ) ) {
			return null;
		}
		$raw   = $source->raw( $number );
		$state = $raw !== null ? self::of( $raw ) : null;
		if ( $json === null ) {
			++self::$reads;
			self::$states[ $number ] = $state;
		}

		return $state;
	}

	/**
	 * Whether a state closes what links to it: a closed issue or a merged
	 * pull request. A pull request closed without merging does not.
	 *
	 * @param array{state: string, pull: bool, at: string}|null $state
	 */
	public static function closes( ?array $state ): bool {
		return $state !== null && in_array( $state['state'], [ 'closed', 'merged' ], true );
	}

	/** How many tracker reads this pass has made, so a caller can count the ones it caused (#1095). */
	public static function reads(): int {
		return self::$reads;
	}

	/** A new pass: read everything again. */
	public static function forget(): void {
		self::$states = [];
		self::$reads  = 0;
	}

	/**
	 * @param array<string, mixed> $raw An issue as the API gives it; a pull request carries pull_request.
	 * @return array{state: string, pull: bool, at: string}
	 */
	private static function of( array $raw ): array {
		$pull   = is_array( $raw['pull_request'] ?? null );
		$merged = $pull && ( ! empty( $raw['pull_request']['merged'] ) || ! empty( $raw['pull_request']['merged_at'] ) );
		$closed = ( $raw['state'] ?? '' ) === 'closed';
		$at     = is_string( $raw['closed_at'] ?? null ) ? $raw['closed_at'] : '';
		if ( $at === '' && $pull && is_string( $raw['pull_request']['merged_at'] ?? null ) ) {
			$at = $raw['pull_request']['merged_at'];
		}

		return [
			'state' => ! $closed ? 'open' : ( $pull ? ( $merged ? 'merged' : 'unmerged' ) : 'closed' ),
			'pull'  => $pull,
			'at'    => $closed ? $at : '',
		];
	}
}
