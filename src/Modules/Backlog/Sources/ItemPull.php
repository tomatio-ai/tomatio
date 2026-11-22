<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

use AgentBrain\Modules\Backlog\Backlog;
use AgentBrain\Modules\Backlog\Progress;
use AgentBrain\Modules\DataStore\StoreData;
use WP_Error;

/**
 * #1092: the pull request of a board item, for Progress (#1005) steps 3 to
 * 5: PR open, checks green, merged. It answers Progress::PR_FILTER, which
 * nothing did before, so those steps never showed on a real brain.
 *
 * Which pull request: the item's issue is its sources entry for the
 * tracker; without one, every link of the item into the tracker's
 * repository counts. The pull request is the one that closes that issue
 * ("Closes #N", IssuesSource::closes()), or the link itself when it is a
 * pull request. With several: an open one, else the newest merged one, else
 * one closed without merging.
 *
 * The filter only ever reads what the last pass stored (OPTION), so a page
 * request never waits for the tracker. refresh() runs on the hourly
 * Progress pass, with the same read-only token and repository as the Inbox
 * intake (IssuesSource, Config); nothing is ever written to the tracker.
 *
 * At most MAX_READS tracker reads in one pass, every one counted (#1095):
 * the open pull requests, the closed ones newest change first (only back to
 * the last pass; all of them, up to LIST_READS pages, once a day and when an
 * item names an issue it did not before), a few numbers no list showed read
 * one by one (LOOKUPS), a pull request that left the open list asked for by
 * number, then the checks of each open pull request and, when its newest
 * commit changed, that commit. What does not fit keeps its last answer and
 * comes on a later pass.
 *
 * A tracker that does not answer the first read changes nothing. One that
 * stops answering later in the lists (a slow one: a page of 50 can take
 * longer than a read may) keeps what it gave: those items get their step,
 * the rest keep their last answer, and the next pass reads as far back
 * again (#1095).
 */
final class ItemPull {

	/** {key, at, deep_at, numbers: [n], issues: [n], pulls: {n: pull}}; removed with the plugin's data. */
	public const OPTION = 'ab_backlog_item_pulls';

	/** Most tracker reads in one pass. */
	public const MAX_READS = 40;

	/** Most of them for the lists of pull requests, so the checks always get some. */
	public const LIST_READS = 24;

	/**
	 * Most numbers read one by one in a pass: ones an item names that no list
	 * showed (an old pull request, or an issue in a repository too large to
	 * list), read once each (#1095).
	 */
	public const LOOKUPS = 6;

	/** Every closed pull request is read again at least this often, in seconds. */
	private const DEEP_EVERY = 86400;

	/** Statuses whose items no pull request is looked up for. */
	private const SKIP = [ 'idea', 'declined', 'parked', 'archived', 'shipped' ];

	/** What the filter gives Progress. */
	private const FIELDS = [ 'number', 'url', 'state', 'checks', 'pushed_at', 'author' ];

	public static function register(): void {
		add_filter( Progress::PR_FILTER, [ self::class, 'answer' ], 10, 3 );
	}

	/**
	 * Progress::PR_FILTER: the item's pull request as the last pass found
	 * it, or null.
	 *
	 * @param mixed                $pull
	 * @param array<string, mixed> $doc
	 * @return mixed
	 */
	public static function answer( $pull, int $artifactId, array $doc ) {
		unset( $artifactId );
		if ( $pull !== null ) {
			return $pull;
		}
		$pulls = self::stored()['pulls'];
		$best  = null;
		foreach ( self::numbers( is_array( $doc['value'] ?? null ) ? $doc['value'] : [] ) as $number ) {
			$best = self::better( $best, $pulls[ $number ] ?? null );
		}

		if ( $best === null ) {
			return null;
		}
		$out = [];
		foreach ( self::FIELDS as $field ) {
			$out[ $field ] = $best[ $field ];
		}

		return $out;
	}

	/**
	 * The issue and pull request numbers an item names on the tracker: its
	 * sources entry, else its links into the tracker's repository.
	 *
	 * @param array<string, mixed> $value The item.
	 * @return array<int, int>
	 */
	public static function numbers( array $value ): array {
		$key   = Config::key( IssuesSource::SOURCE );
		$entry = $key !== '' && is_array( $value['sources'] ?? null ) ? ( $value['sources'][ $key ] ?? null ) : null;
		if ( is_array( $entry ) && is_scalar( $entry['id'] ?? null ) && (int) $entry['id'] > 0 ) {
			return [ (int) $entry['id'] ];
		}
		$out = [];
		foreach ( is_array( $value['links'] ?? null ) ? $value['links'] : [] as $link ) {
			$number = is_array( $link ) && is_string( $link['url'] ?? null ) ? Tracker::number( $link['url'] ) : 0;
			if ( $number > 0 ) {
				$out[ $number ] = $number;
			}
		}

		return array_values( $out );
	}

	/**
	 * The hourly pass: read the tracker and store each item's pull request.
	 * Returns how many tracker reads it made, the ones by number included.
	 */
	public static function refresh(): int {
		$key    = Config::key( IssuesSource::SOURCE );
		$source = $key !== '' ? IssuesSource::configured() : '';
		if ( is_string( $source ) ) {
			return 0;
		}
		$stored = self::stored();
		$needed = self::needed();
		if ( $needed === [] ) {
			if ( $stored['pulls'] !== [] || $stored['issues'] !== [] ) {
				self::save( [ 'key' => $key, 'at' => '', 'deep_at' => '', 'numbers' => [], 'issues' => [], 'pulls' => [] ] );
			}

			return 0;
		}

		$now   = time();
		$last  = $stored['at'] !== '' ? (int) strtotime( $stored['at'] ) : 0;
		$deep  = $last === 0 || $now - (int) strtotime( $stored['deep_at'] ) >= self::DEEP_EVERY || array_diff( $needed, $stored['numbers'] ) !== [];
		$since = $deep ? 0 : $last - 10 * MINUTE_IN_SECONDS;

		// The open pull requests, then the closed ones, newest change first.
		$reads     = 0;
		$seen      = [];
		$closing   = [];
		$allOpen   = false;
		$allClosed = false;
		$failed    = false;
		foreach ( [ 'open', 'closed' ] as $state ) {
			for ( $page = 1; $reads < self::LIST_READS; ++$page ) {
				$got = $source->pulls( $state, $page );
				++$reads;
				if ( $got instanceof WP_Error ) {
					if ( $reads === 1 ) {
						// The tracker did not answer: every item keeps its last answer.
						return $reads;
					}
					// #1095: it stopped answering (a slow tracker): keep what it gave.
					$failed = true;
					break 2;
				}
				$oldest = 0;
				foreach ( $got as $raw ) {
					$one = is_array( $raw ) ? self::summary( $raw ) : null;
					if ( $one === null ) {
						continue;
					}
					$seen[ $one['number'] ] = $one;
					foreach ( array_keys( IssuesSource::closes( [ $raw ] ) ) as $issue ) {
						$closing[ (int) $issue ][] = $one['number'];
					}
					$oldest = (int) strtotime( (string) ( $raw['updated_at'] ?? '' ) );
				}
				if ( count( $got ) < IssuesSource::PER_PAGE ) {
					if ( $state === 'open' ) {
						$allOpen = true;
					} else {
						$allClosed = true;
					}
					break;
				}
				if ( $state === 'closed' && $since > 0 && $oldest > 0 && $oldest < $since ) {
					// The rest changed before the last pass, which read them.
					break;
				}
			}
		}

		// #1095: numbers no list showed. When both lists were read to the end,
		// they are issues. Otherwise a few are read one by one, once each: an
		// old pull request gets its state, an issue is remembered as one.
		$issues = array_values( array_intersect( $stored['issues'], $needed ) );
		foreach ( $needed as $number ) {
			if ( isset( $seen[ $number ] ) ) {
				continue;
			}
			if ( ( $allOpen && $allClosed ) || isset( $closing[ $number ] ) ) {
				$issues[] = $number;
			}
		}
		$looked = 0;
		foreach ( $needed as $number ) {
			if ( $failed || $looked >= self::LOOKUPS || $reads >= self::MAX_READS ) {
				break;
			}
			if ( isset( $seen[ $number ] ) || isset( $stored['pulls'][ $number ] ) || in_array( $number, $issues, true ) ) {
				continue;
			}
			++$looked;
			++$reads;
			$raw = $source->raw( $number );
			if ( $raw === null ) {
				continue;
			}
			$pr = $raw['pull_request'] ?? null;
			if ( ! is_array( $pr ) ) {
				$issues[] = $number;
				continue;
			}
			// An issue read of a pull request says merged under pull_request.
			$one = self::summary( [ 'merged' => ! empty( $pr['merged'] ), 'merged_at' => $pr['merged_at'] ?? null ] + $raw );
			if ( $one !== null && $one['number'] === $number ) {
				$seen[ $number ] = $one;
			}
		}
		$issues = array_values( array_diff( array_unique( $issues ), array_keys( $seen ) ) );
		sort( $issues );

		$pulls = [];
		foreach ( $needed as $number ) {
			$best = $seen[ $number ] ?? null;
			foreach ( $closing[ $number ] ?? [] as $by ) {
				$best = self::better( $best, $seen[ $by ] );
			}
			$prev = $stored['pulls'][ $number ] ?? null;
			if ( $prev !== null && ! isset( $seen[ $prev['number'] ] ) ) {
				if ( $prev['state'] === 'open' && $allOpen && $reads < self::MAX_READS ) {
					// It was open and no longer is, and the closed ones read did not reach it: ask how it closed.
					// #1095: the read counts against this pass's cap (none when the self-close read it already).
					$before = Tracker::reads();
					$state  = Tracker::state( $prev['number'] );
					$reads += Tracker::reads() - $before;
					if ( $state !== null && $state['pull'] && $state['state'] !== 'open' ) {
						$prev['state'] = $state['state'] === 'merged' ? 'merged' : 'closed';
					}
				}
				$best = self::better( $best, $prev );
			}
			if ( $best === null ) {
				continue;
			}
			$old  = $prev !== null && $prev['number'] === $best['number'] ? $prev : null;
			$same = $old !== null && $best['sha'] !== '' && $old['sha'] === $best['sha'] && $best['state'] === 'open';

			$pulls[ $number ] = [
				'checks'     => $same ? $old['checks'] : '',
				'checked_at' => $same ? $old['checked_at'] : '',
				'pushed_at'  => $old !== null ? $old['pushed_at'] : '',
				'pushed_sha' => $old !== null ? $old['pushed_sha'] : '',
			] + $best;
		}

		// The checks of each open pull request, the longest unread first; its newest commit when that changed.
		$open = [];
		foreach ( $pulls as $number => $pull ) {
			if ( $pull['state'] === 'open' && $pull['sha'] !== '' ) {
				$open[ $pull['number'] ][] = $number;
			}
		}
		$order = array_keys( $open );
		usort(
			$order,
			static fn ( int $a, int $b ): int => [ $pulls[ $open[ $a ][0] ]['checked_at'], $a ] <=> [ $pulls[ $open[ $b ][0] ]['checked_at'], $b ]
		);
		$stamp = gmdate( 'Y-m-d\TH:i:s\Z', $now );
		foreach ( $order as $by ) {
			$first  = $pulls[ $open[ $by ][0] ];
			$commit = $first['pushed_sha'] !== $first['sha'];
			$cost   = $source->checksCost() + ( $commit ? 1 : 0 );
			if ( $reads + $cost > self::MAX_READS ) {
				continue;
			}
			$reads += $source->checksCost();
			$checks = $source->checks( $first['sha'] );
			if ( $checks === null ) {
				// The tracker stopped answering: the rest keep their last answer.
				break;
			}
			$pushed = null;
			if ( $commit ) {
				++$reads;
				$pushed = $source->committed( $first['sha'] );
			}
			foreach ( $open[ $by ] as $number ) {
				$pulls[ $number ]['checks']     = $checks;
				$pulls[ $number ]['checked_at'] = $stamp;
				if ( $pushed !== null ) {
					$pulls[ $number ]['pushed_sha'] = $first['sha'];
					if ( $pushed !== '' ) {
						$pulls[ $number ]['pushed_at'] = $pushed;
					}
				}
			}
		}

		// #1095: a pass whose lists broke off does not count as having read
		// them, so the next one reads as far back again.
		self::save(
			[
				'key'     => $key,
				'at'      => $failed ? $stored['at'] : $stamp,
				'deep_at' => $deep && ! $failed ? $stamp : $stored['deep_at'],
				'numbers' => $failed ? array_values( array_intersect( $stored['numbers'], $needed ) ) : $needed,
				'issues'  => $issues,
				'pulls'   => $pulls,
			]
		);

		return $reads;
	}

	/**
	 * A pull request as the API lists it, cut down to what is kept; null
	 * without a number.
	 *
	 * @param array<mixed> $raw
	 * @return array{number: int, url: string, state: string, sha: string, author: string}|null
	 */
	public static function summary( array $raw ): ?array {
		$number = is_scalar( $raw['number'] ?? null ) ? (int) $raw['number'] : 0;
		if ( $number <= 0 ) {
			return null;
		}
		$merged = ! empty( $raw['merged'] ) || ! empty( $raw['merged_at'] );
		$user   = is_array( $raw['user'] ?? null ) ? $raw['user'] : [];
		$login  = $user['login'] ?? $user['username'] ?? '';
		$head   = is_array( $raw['head'] ?? null ) ? $raw['head'] : [];
		$sha    = is_string( $head['sha'] ?? null ) && preg_match( '/^[0-9a-f]{7,64}$/', $head['sha'] ) === 1 ? $head['sha'] : '';
		$url    = $raw['html_url'] ?? '';

		return [
			'number' => $number,
			'url'    => is_string( $url ) && str_starts_with( $url, 'https://' ) ? $url : '',
			'state'  => $merged ? 'merged' : ( ( $raw['state'] ?? '' ) === 'open' ? 'open' : 'closed' ),
			'sha'    => $sha,
			'author' => is_string( $login ) ? substr( $login, 0, 80 ) : '',
		];
	}

	/**
	 * The pull request that says more about an item: an open one, else a
	 * merged one, else one closed without merging; of two alike, the newer.
	 *
	 * @template A of array{number: int, state: string}
	 * @template B of array{number: int, state: string}
	 * @param A|null $a
	 * @param B|null $b
	 * @return A|B|null
	 */
	public static function better( ?array $a, ?array $b ): ?array {
		if ( $a === null || $b === null ) {
			return $a ?? $b;
		}
		$rank = [ 'open' => 3, 'merged' => 2, 'closed' => 1 ];

		return [ $rank[ $b['state'] ] ?? 0, $b['number'] ] > [ $rank[ $a['state'] ] ?? 0, $a['number'] ] ? $b : $a;
	}

	/**
	 * The numbers every board's planned, unshipped items name, in order.
	 *
	 * @return array<int, int>
	 */
	private static function needed(): array {
		$out = [];
		foreach ( Backlog::all() as $artifactId ) {
			foreach ( StoreData::list( $artifactId, 'items' ) as $doc ) {
				$value = is_array( $doc['value'] ) ? $doc['value'] : [];
				if ( in_array( (string) ( $value['status'] ?? 'inbox' ), self::SKIP, true ) ) {
					continue;
				}
				foreach ( self::numbers( $value ) as $number ) {
					$out[ $number ] = $number;
				}
			}
		}
		sort( $out );

		return $out;
	}

	/**
	 * What the last pass stored, when it was for the tracker set now.
	 *
	 * @return array{at: string, deep_at: string, numbers: array<int, int>, issues: array<int, int>, pulls: array<int, array{number: int, url: string, state: string, sha: string, author: string, checks: string, checked_at: string, pushed_at: string, pushed_sha: string}>}
	 */
	private static function stored(): array {
		$out = [ 'at' => '', 'deep_at' => '', 'numbers' => [], 'issues' => [], 'pulls' => [] ];
		$raw = get_option( self::OPTION, [] );
		$key = Config::key( IssuesSource::SOURCE );
		if ( ! is_array( $raw ) || $key === '' || ( $raw['key'] ?? '' ) !== $key ) {
			return $out;
		}
		foreach ( [ 'at', 'deep_at' ] as $field ) {
			$out[ $field ] = is_string( $raw[ $field ] ?? null ) ? $raw[ $field ] : '';
		}
		$out['numbers'] = array_values( array_map( 'intval', is_array( $raw['numbers'] ?? null ) ? $raw['numbers'] : [] ) );
		$out['issues']  = array_values( array_map( 'intval', is_array( $raw['issues'] ?? null ) ? $raw['issues'] : [] ) );
		foreach ( is_array( $raw['pulls'] ?? null ) ? $raw['pulls'] : [] as $number => $pull ) {
			if ( ! is_array( $pull ) || ! is_int( $pull['number'] ?? null ) || ! in_array( $pull['state'] ?? null, [ 'open', 'merged', 'closed' ], true ) ) {
				continue;
			}
			$one = [ 'number' => $pull['number'], 'state' => $pull['state'] ];
			foreach ( [ 'url', 'sha', 'author', 'checks', 'checked_at', 'pushed_at', 'pushed_sha' ] as $field ) {
				$one[ $field ] = is_string( $pull[ $field ] ?? null ) ? $pull[ $field ] : '';
			}
			$out['pulls'][ (int) $number ] = $one;
		}

		return $out;
	}

	/** @param array<string, mixed> $data */
	private static function save( array $data ): void {
		update_option( self::OPTION, $data, false );
	}
}
