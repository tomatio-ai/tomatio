<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\AgentId\TaskTakers;
use AgentBrain\Modules\Audit\Repository as AuditRepository;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\DataStore\TioDayAssignee;
use AgentBrain\Modules\DataStore\TioDaySelfClose;
use AgentBrain\Modules\Sharing\MembersAddress;

/**
 * #1005: how far each item's coding has got, and what each agent is doing
 * now. Written to meta/progress (server-written, read-only for the page),
 * refreshed by cron and after a change on the board.
 *
 * Six steps per planned item an agent that takes tasks works on (its
 * assignee, or the agent of an open TíoDay task that links to the item):
 * 1 queued; 2 started (the agent commented on the item or on its task);
 * 3 PR open; 4 checks green; 5 merged; 6 shipped. Steps 3 to 5 need the
 * pull request, which the issue tracker (#1001, Settings > Issue tracker)
 * gives through the PR_FILTER filter (Sources\ItemPull, #1092); without a
 * tracker only queued, started and shipped show. A failing check stays on step 3 ("a test to fix"): never
 * red, since only a TíoDay task means a person must act. An item that got
 * somewhere and is no longer current is waiting: the page greys the steps
 * it reached.
 *
 * One line per agent: what it is on now, how many items wait in its queue,
 * its last check-in (the newest audit log row it caused), and when a pull
 * request of its items was last pushed. The page picks the dot from those
 * times, so it stays right between refreshes.
 *
 * #1088: the cron pass also closes the TíoDay in-flight items and feature
 * tasks whose issue closed or whose pull request merged on the tracker
 * (DataStore\TioDaySelfClose), so nobody has to remember to clear them.
 */
final class Progress {

	/** meta/<KEY> on every board. */
	public const KEY = 'progress';

	/** The cron hook. */
	public const HOOK = 'tomatio_backlog_progress';

	/**
	 * Filter: the pull request of an item, or null. Gets (null, artifact
	 * id, items document); answers {number, url, state: open|merged|closed,
	 * checks: green|failing|running|'', pushed_at, author}.
	 */
	public const PR_FILTER = 'tomatio/backlog/item_pr';

	/** The six steps, in order. */
	public const STEPS = [ 'queued', 'started', 'pr', 'green', 'merged', 'shipped' ];

	/** Statuses that are never planned work. */
	private const NOT_PLANNED = [ 'idea', 'declined', 'parked', 'archived' ];

	/** @var array<int, bool> Boards changed during this request. */
	private static array $dirty = [];

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'syncAll' ] );
		add_action( 'init', [ self::class, 'schedule' ], 40 );
		add_action( 'tomatio/store_written', [ self::class, 'written' ], 30, 5 );
		add_action( 'shutdown', [ self::class, 'flush' ], 30 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * tomatio/store_written: a change to a board's items, comments or
	 * verdicts refreshes its progress at the end of the request.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $by
	 */
	public static function written( int $artifactId, string $collection, string $key, $value, array $by ): void {
		unset( $key, $value, $by );
		if ( in_array( $collection, [ 'items', 'comments', 'verdicts' ], true ) && Backlog::isBacklog( $artifactId ) ) {
			self::$dirty[ $artifactId ] = true;
		}
	}

	public static function flush(): void {
		$ids         = array_keys( self::$dirty );
		self::$dirty = [];
		foreach ( $ids as $artifactId ) {
			self::sync( (int) $artifactId );
		}
	}

	public static function syncAll(): void {
		// #1088: on the same pass, TíoDay items whose issue or pull request closed close themselves.
		TioDaySelfClose::run();
		// #1092: and each item's pull request is read from the tracker, for steps 3 to 5.
		Sources\ItemPull::refresh();
		foreach ( Backlog::all() as $artifactId ) {
			// #1103: a merged item goes in the next patch, planned then if need be.
			Releases::fillNextPatch( $artifactId );
			// B227: on a continuous board it ships instead (and Tomagic suggests a mode once).
			Delivery::hourly( $artifactId );
			// #1105: and the TíoDay task of an item whose pull request merged is done.
			PersonDay::closeMerged( $artifactId );
			self::sync( $artifactId );
		}
		// And each TíoDay's release list follows its board, pull requests as just read.
		TioDayRelease::run();
	}

	/** Write meta/progress on a board when it changed. */
	public static function sync( int $artifactId ): void {
		// #1077: the manager hears once when the next release is ready to cut.
		ReadyToCut::check( $artifactId );
		// #1136: and once a day about comments and verdicts nobody handled.
		Waiting::nudge( $artifactId );
		$have = StoreData::get( $artifactId, 'meta', self::KEY );
		$prev = $have !== null && is_array( $have['value'] ) ? $have['value'] : [];
		$want = self::compute( $artifactId, $prev );
		$old  = $prev;
		unset( $old['at'] );
		$new = $want;
		unset( $new['at'] );
		if ( $have !== null && $old === $new ) {
			return;
		}
		StoreData::set( $artifactId, 'meta', self::KEY, $want, StoreData::anonymousAttribution(), null, $have !== null ? (int) $have['version'] : 0 );
	}

	/**
	 * The progress of a board: {at, items: {<item ID>: entry}, agents: [line]}.
	 *
	 * @param array<string, mixed> $prev The last meta/progress value, for the steps an item reached before.
	 * @return array<string, mixed>
	 */
	public static function compute( int $artifactId, array $prev = [] ): array {
		$items       = StoreData::list( $artifactId, 'items' );
		[ $project ] = Project::project( $artifactId );
		$choices     = Releases::choices( $artifactId );
		$plan        = Releases::plan( $project, $items, $choices );
		$was         = is_array( $prev['items'] ?? null ) ? $prev['items'] : [];

		$agents = [];
		foreach ( TaskTakers::all() as $agent ) {
			$agentId = (int) $agent->ID;
			if ( Backlog::canRead( $agentId, $artifactId ) ) {
				$agents[ $agentId ] = TaskTakers::nameOf( $agentId );
			}
		}

		$board    = Assignees::url( $artifactId );
		$tasks    = self::linkedTasks( $board, array_keys( $agents ) );
		$comments = self::agentComments( $artifactId );

		$out = [];
		foreach ( $items as $doc ) {
			$value  = is_array( $doc['value'] ) ? $doc['value'] : [];
			$status = (string) ( $value['status'] ?? 'inbox' );
			if ( in_array( $status, self::NOT_PLANNED, true ) ) {
				continue;
			}
			$id   = is_string( $value['id'] ?? null ) ? $value['id'] : (string) $doc['key'];
			$task = $tasks[ strtolower( $id ) ] ?? $tasks[ strtolower( (string) $doc['key'] ) ] ?? null;

			$agentId = 0;
			$owner   = is_string( $value['owner'] ?? null ) ? $value['owner'] : '';
			foreach ( array_keys( $agents ) as $candidate ) {
				if ( $owner !== '' && TioDayAssignee::isNamed( $candidate, $owner ) ) {
					$agentId = $candidate;
					break;
				}
			}
			if ( $agentId === 0 && $task !== null ) {
				$agentId = $task['agent'];
			}
			$before = is_array( $was[ $id ] ?? null ) ? $was[ $id ] : null;
			if ( $status === 'shipped' && $before === null ) {
				continue;
			}
			if ( $agentId === 0 ) {
				// Nobody's queue, but the page still shows "Awaiting release"
				// once the item's pull request merged (Dennis, 22 Nov).
				$merged = self::mergedEntry( $artifactId, $doc, $value, $id, $plan, $choices );
				if ( $merged !== null ) {
					$out[ $id ] = $merged;
				}
				continue;
			}

			$started = self::earliest(
				self::startedOnItem( $comments[ $id ] ?? [], $agentId, (string) ( $value['assigned_at'] ?? '' ) ),
				$task !== null ? $task['commented_at'] : ''
			);
			$pr      = self::pr( $artifactId, $doc );
			$step    = self::step( $status, $started, $pr );
			$reached = max( $step, (int) ( $before['reached'] ?? 0 ) );
			$current = Assignees::isCurrent( $doc, $project, $plan, $choices );
			$waiting = $step < 5 && ( ! $current || ( $task !== null && $task['status'] === 'waiting' ) );
			if ( $waiting && $reached < 2 ) {
				// Never started and not current: not in anyone's queue.
				continue;
			}

			$release = Releases::resolve( Releases::setOf( $doc, $choices ), $plan );
			$entry   = array_filter(
				[
					'agent'      => $agents[ $agentId ],
					'title'      => (string) ( $value['title'] ?? $id ),
					'step'       => $step,
					'reached'    => $reached,
					'waiting'    => $waiting,
					'started_at' => $started,
					'release'    => $release,
					'task'       => $task !== null ? $task['id'] : '',
					'pr'         => $pr,
				],
				static fn ( $v ): bool => $v !== '' && $v !== null && $v !== false
			);

			$out[ $id ] = $entry;
		}
		ksort( $out, SORT_NATURAL );

		$lines = [];
		foreach ( $agents as $agentId => $name ) {
			$lines[] = self::line( $agentId, $name, $out );
		}

		return [
			'at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'items'  => $out,
			'agents' => $lines,
		];
	}

	/**
	 * The entry of an item no agent works on whose pull request merged:
	 * step 5, with its release, or null. Never counted in an agent's line.
	 *
	 * @param array<string, mixed>                                            $doc
	 * @param array<string, mixed>                                            $value
	 * @param array{running: string, next: string, upcoming: array<int, string>} $plan
	 * @param array<string, array<string, mixed>>                             $choices
	 * @return array<string, mixed>|null
	 */
	private static function mergedEntry( int $artifactId, array $doc, array $value, string $id, array $plan, array $choices ): ?array {
		if ( ( $value['status'] ?? '' ) === 'shipped' ) {
			return null;
		}
		$pr = self::pr( $artifactId, $doc );
		if ( $pr === null || $pr['state'] !== 'merged' ) {
			return null;
		}

		return array_filter(
			[
				'title'   => (string) ( $value['title'] ?? $id ),
				'step'    => 5,
				'reached' => 5,
				'release' => Releases::resolve( Releases::setOf( $doc, $choices ), $plan ),
				'pr'      => $pr,
			],
			static fn ( $v ): bool => $v !== '' && $v !== null
		);
	}

	/**
	 * The step an item is on: 6 shipped, 5 merged, 4 checks green, 3 PR
	 * open, 2 started, else 1 queued.
	 *
	 * @param array<string, mixed>|null $pr
	 */
	public static function step( string $status, string $started, ?array $pr ): int {
		if ( $status === 'shipped' ) {
			return 6;
		}
		$state = $pr !== null ? (string) ( $pr['state'] ?? '' ) : '';
		if ( $state === 'merged' ) {
			return 5;
		}
		if ( $state === 'open' ) {
			return ( $pr['checks'] ?? '' ) === 'green' ? 4 : 3;
		}

		return $started !== '' ? 2 : 1;
	}

	/**
	 * One agent's line: what it is on now, its queue, its last check-in,
	 * and the newest push on its items.
	 *
	 * @param array<string, array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	private static function line( int $agentId, string $name, array $items ): array {
		$on     = '';
		$onAt   = '';
		$queue  = 0;
		$pushed = '';
		foreach ( $items as $id => $entry ) {
			if ( ( $entry['agent'] ?? '' ) !== $name ) {
				continue;
			}
			$push = is_array( $entry['pr'] ?? null ) ? (string) ( $entry['pr']['pushed_at'] ?? '' ) : '';
			if ( strcmp( $push, $pushed ) > 0 ) {
				$pushed = $push;
			}
			if ( ! empty( $entry['waiting'] ) ) {
				continue;
			}
			$step = (int) $entry['step'];
			if ( $step === 1 ) {
				++$queue;
			} elseif ( $step <= 4 ) {
				$at = self::newest( (string) ( $entry['started_at'] ?? '' ), $push );
				if ( $on === '' || strcmp( $at, $onAt ) > 0 ) {
					$on   = (string) $id;
					$onAt = $at;
				}
			}
		}

		return array_filter(
			[
				'name'      => $name,
				'on'        => $on,
				'queue'     => $queue,
				'checkin'   => self::checkin( $agentId ),
				'pushed_at' => $pushed,
			],
			static fn ( $v ): bool => $v !== ''
		);
	}

	/** The agent's newest audit log row, as UTC ISO 8601, or ''. */
	public static function checkin( int $agentId ): string {
		$rows = Db::getResults( 'SELECT MAX(ts) AS ts FROM ' . AuditRepository::tableName() . ' WHERE actor_id = %d', [ $agentId ] );
		$ts   = isset( $rows[0]['ts'] ) && is_string( $rows[0]['ts'] ) ? $rows[0]['ts'] : '';
		$time = $ts !== '' ? strtotime( $ts . ' UTC' ) : false;

		return $time !== false ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : '';
	}

	/**
	 * The item's pull request from the issue tracker, cleaned, or null.
	 *
	 * @param array<string, mixed> $doc
	 * @return array<string, mixed>|null
	 */
	private static function pr( int $artifactId, array $doc ): ?array {
		$raw = apply_filters( self::PR_FILTER, null, $artifactId, $doc );
		if ( ! is_array( $raw ) || ! in_array( $raw['state'] ?? null, [ 'open', 'merged', 'closed' ], true ) ) {
			return null;
		}
		if ( $raw['state'] === 'closed' ) {
			// Closed without merging: back to started or queued.
			return null;
		}
		$url = is_string( $raw['url'] ?? null ) && str_starts_with( $raw['url'], 'https://' ) ? $raw['url'] : '';

		return array_filter(
			[
				'number'    => is_int( $raw['number'] ?? null ) ? $raw['number'] : 0,
				'url'       => $url,
				'state'     => $raw['state'],
				'checks'    => in_array( $raw['checks'] ?? '', [ 'green', 'failing', 'running' ], true ) ? $raw['checks'] : '',
				'pushed_at' => is_string( $raw['pushed_at'] ?? null ) ? substr( $raw['pushed_at'], 0, 40 ) : '',
				'author'    => is_string( $raw['author'] ?? null ) ? substr( $raw['author'], 0, 80 ) : '',
			],
			static fn ( $v ): bool => $v !== '' && $v !== 0
		);
	}

	/**
	 * When the agent first commented on its item (after it was assigned),
	 * or ''.
	 *
	 * @param array<int, array<string, string>> $comments [{by, at}] on the item.
	 */
	private static function startedOnItem( array $comments, int $agentId, string $assignedAt ): string {
		$login = '';
		$user  = get_userdata( $agentId );
		if ( $user instanceof \WP_User ) {
			$login = (string) $user->user_login;
		}
		$first = '';
		foreach ( $comments as $comment ) {
			$by = explode( ' for ', $comment['by'] )[0];
			if ( $by === '' || ( strcasecmp( $by, $login ) !== 0 && ! TioDayAssignee::isNamed( $agentId, $by ) ) ) {
				continue;
			}
			if ( $assignedAt !== '' && strcmp( $comment['at'], $assignedAt ) < 0 ) {
				continue;
			}
			if ( $first === '' || strcmp( $comment['at'], $first ) < 0 ) {
				$first = $comment['at'];
			}
		}

		return $first;
	}

	/**
	 * The board's comments by item ID: [{by, at}].
	 *
	 * @return array<string, array<int, array<string, string>>>
	 */
	private static function agentComments( int $artifactId ): array {
		$out = [];
		foreach ( StoreData::list( $artifactId, 'comments' ) as $doc ) {
			$value = is_array( $doc['value'] ) ? $doc['value'] : [];
			$on    = is_string( $value['on'] ?? null ) ? $value['on'] : '';
			if ( $on === '' || $on === 'project' ) {
				continue;
			}
			$out[ $on ][] = [
				'by' => is_string( $value['by'] ?? null ) ? $value['by'] : '',
				'at' => is_string( $value['at'] ?? null ) ? $value['at'] : '',
			];
		}

		return $out;
	}

	/**
	 * Open TíoDay tasks of these agents that link to an item on the board
	 * (a link to the board's address with item=<ID or key> in it), by
	 * lower-case item ID or key: {agent, id, status, commented_at}.
	 *
	 * @param array<int, int> $agentIds
	 * @return array<string, array{agent: int, id: string, status: string, commented_at: string}>
	 */
	private static function linkedTasks( string $board, array $agentIds ): array {
		$out = [];
		if ( $board === '' || $agentIds === [] ) {
			return $out;
		}
		foreach ( TioDay::candidates( [ [ 'key' => HomeWorkspace::META_OWNER, 'compare' => 'EXISTS' ] ] ) as $post ) {
			$tioday = (int) $post->ID;
			$found  = [];
			foreach ( $agentIds as $agentId ) {
				foreach ( TioDayAssignee::tasks( $tioday, $agentId, true ) as $key => $task ) {
					$item = self::linkedItem( $board, is_array( $task['links'] ?? null ) ? $task['links'] : [] );
					if ( $item !== '' && ! isset( $out[ $item ] ) ) {
						$found[ (string) $key ] = [ $item, $agentId, $task ];
					}
				}
			}
			if ( $found === [] ) {
				continue;
			}
			$said = [];
			foreach ( StoreData::list( $tioday, 'comments' ) as $doc ) {
				$value = is_array( $doc['value'] ) ? $doc['value'] : [];
				$on    = is_string( $value['on'] ?? null ) ? $value['on'] : '';
				$key   = str_starts_with( $on, 't:' ) ? substr( $on, 2 ) : '';
				if ( ! isset( $found[ $key ] ) || ! is_string( $value['by'] ?? null ) || ! TioDayAssignee::isNamed( $found[ $key ][1], $value['by'] ) ) {
					continue;
				}
				$at = is_string( $value['at'] ?? null ) ? $value['at'] : '';
				if ( ! isset( $said[ $key ] ) || strcmp( $at, $said[ $key ] ) < 0 ) {
					$said[ $key ] = $at;
				}
			}
			foreach ( $found as $key => [ $item, $agentId, $task ] ) {
				$out[ $item ] = [
					'agent'        => $agentId,
					'id'           => is_string( $task['id'] ?? null ) ? $task['id'] : '',
					'status'       => (string) ( $task['status'] ?? 'open' ),
					'commented_at' => $said[ $key ] ?? '',
				];
			}
		}

		return $out;
	}

	/**
	 * The item a task's links point to on this board (lower case), or ''.
	 *
	 * @param array<int, mixed> $links
	 */
	public static function linkedItem( string $board, array $links ): string {
		$base  = strtok( $board, '#?' );
		$bases = $base === false ? [] : self::boardBases( $base );
		foreach ( $links as $link ) {
			$url = is_array( $link ) && is_string( $link['url'] ?? null ) ? $link['url'] : '';
			if ( $url === '' || ! in_array( strtok( $url, '#?' ), $bases, true ) ) {
				continue;
			}
			if ( preg_match( '/[#&?]item=([^&#]+)/', $url, $m ) === 1 ) {
				return strtolower( rawurldecode( $m[1] ) );
			}
		}

		return '';
	}

	/**
	 * B199: every address of the board at $base: the one given, its number
	 * and the slugs it had before (a link to /a/tomagic still means the
	 * board now at /a/magic-backlog).
	 *
	 * @return array<int, string>
	 */
	private static function boardBases( string $base ): array {
		$out    = [ $base ];
		$prefix = home_url( MembersAddress::PATH_PREFIX );
		if ( str_starts_with( $base, $prefix ) ) {
			$id = MembersAddress::resolve( trim( substr( $base, strlen( $prefix ) ), '/' ) )['artifact_id'];
			if ( $id > 0 ) {
				$out[] = $prefix . $id;
				$out[] = MembersAddress::url( $id );
				foreach ( get_post_meta( $id, MembersAddress::META_OLD_SLUGS ) as $old ) {
					if ( is_string( $old ) && $old !== '' ) {
						$out[] = $prefix . $old;
					}
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/** The earlier of two times when both are set, else the one set. */
	private static function earliest( string $a, string $b ): string {
		if ( $a === '' || $b === '' ) {
			return $a . $b;
		}

		return strcmp( $a, $b ) <= 0 ? $a : $b;
	}

	/** The later of two times when both are set, else the one set. */
	private static function newest( string $a, string $b ): string {
		if ( $a === '' ) {
			return $b;
		}
		if ( $b === '' ) {
			return $a;
		}

		return strcmp( $a, $b ) >= 0 ? $a : $b;
	}
}
