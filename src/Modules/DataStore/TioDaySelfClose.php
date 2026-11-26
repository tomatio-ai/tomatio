<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Backlog\Sources\Config;
use AgentBrain\Modules\Backlog\Sources\Tracker;
use WP_Error;

/**
 * #1088: TíoDay items close themselves when their issue or pull request
 * closes. Until now only the agent that made an in-flight item could clear
 * it, and only if it remembered: sessions ended, and finished work stayed
 * in flight for days.
 *
 * On every Progress pass (Backlog\Progress::syncAll, hourly by cron), for
 * every TíoDay, when the brain has an issue tracker (Settings >
 * Extensions, Issue tracker, #1001):
 *
 * - an in-flight item that links an issue or pull request of the tracker's
 *   repository becomes a done item once that issue is closed or that pull
 *   request merged: by the item's own who, with its links, on the day it
 *   closed, saying "Closed with #N". When a done item of that day or later
 *   already links it (a session or the Merge log recorded the landing),
 *   the in-flight item is only cleared;
 * - an open feature task that links one is marked done, with a comment
 *   saying why.
 *
 * The first linked issue or pull request to close closes the item. A pull
 * request closed without merging closes nothing. It writes through
 * TioDay's own helpers, as the TíoDay's lead agent (its person when it has
 * none), and reads the tracker with the stored read-only token only.
 * Without a tracker nothing here runs: done items still clear in-flight
 * ones (TioDay::done) and stale ones still ask (TioDayStale).
 */
final class TioDaySelfClose {

	/** Who the comment on a task that closed itself is by. */
	public const BY = 'TíoDay';

	/** TíoDays looked at in one pass. */
	private const MAX_TIODAYS = 500;

	/**
	 * One pass over every TíoDay.
	 *
	 * @param array<mixed>|null $json The API's JSON, read instead of the tracker (tests, the command line).
	 * @return array<int, array{inflight: string[], tasks: string[]}> What closed, by TíoDay.
	 */
	public static function run( ?array $json = null ): array {
		$out = [];
		if ( Tracker::prefix() === '' ) {
			return $out;
		}
		Tracker::forget();
		foreach ( TioDay::candidates( [ [ 'key' => HomeWorkspace::META_OWNER, 'compare' => 'EXISTS' ] ], self::MAX_TIODAYS ) as $post ) {
			$closed = self::sync( (int) $post->ID, $json );
			if ( $closed['inflight'] !== [] || $closed['tasks'] !== [] ) {
				$out[ (int) $post->ID ] = $closed;
			}
		}

		return $out;
	}

	/**
	 * Close what closed on the tracker on one TíoDay.
	 *
	 * @param array<mixed>|null $json
	 * @return array{inflight: string[], tasks: string[]} Keys of the items and tasks closed.
	 */
	public static function sync( int $artifactId, ?array $json = null ): array {
		$out   = [ 'inflight' => [], 'tasks' => [] ];
		$owner = (int) get_post_meta( $artifactId, HomeWorkspace::META_OWNER, true );
		if ( $owner <= 0 || Tracker::prefix() === '' ) {
			return $out;
		}

		$found = [];
		foreach ( [ 'inflight', 'tasks' ] as $collection ) {
			foreach ( StoreData::list( $artifactId, $collection ) as $doc ) {
				$value = is_array( $doc['value'] ) && ! array_is_list( $doc['value'] ) ? $doc['value'] : [];
				if ( $collection === 'tasks' && ( ( $value['kind'] ?? '' ) !== 'feature' || in_array( (string) ( $value['status'] ?? 'open' ), [ 'done', 'dropped', 'deleted' ], true ) ) ) {
					continue;
				}
				$closed = self::closedLink( $value['links'] ?? null, $json );
				if ( $closed !== null ) {
					$found[] = [ $collection, (string) $doc['key'], $value, $closed ];
				}
			}
		}
		if ( $found === [] ) {
			return $out;
		}

		$lead     = (int) get_post_meta( $artifactId, Cpts::META_LEAD_AGENT, true );
		$previous = get_current_user_id();
		wp_set_current_user( $lead > 0 && get_userdata( $lead ) instanceof \WP_User ? $lead : $owner );
		try {
			$made = [];
			foreach ( $found as [ $collection, $key, $value, $closed ] ) {
				$did = $collection === 'inflight' ? self::closeFlight( $artifactId, $key, $value, $closed, $made ) : self::closeTask( $artifactId, $owner, $key, $closed );
				if ( ! $did ) {
					continue;
				}
				$out[ $collection ][] = $key;
				\AgentBrain\Core\Hooks::action(
					'audit',
					[
						'actor_id'    => 0,
						'event'       => 'tioday.self_closed',
						'status'      => 'ok',
						'object_type' => 'artifact',
						'object_id'   => (string) $artifactId,
						'meta'        => [ 'collection' => $collection, 'item' => $key, 'number' => $closed['number'], 'state' => $closed['state'] ],
					]
				);
			}
		} finally {
			wp_set_current_user( $previous );
		}

		return $out;
	}

	/**
	 * The first of an item's links whose issue is closed or whose pull
	 * request merged on the tracker: {number, state, pull, at}; null when
	 * none is, or the item links nothing on the tracker.
	 *
	 * @param mixed             $links
	 * @param array<mixed>|null $json
	 * @return array{number: int, state: string, pull: bool, at: string}|null
	 */
	public static function closedLink( $links, ?array $json = null ): ?array {
		$seen = [];
		foreach ( is_array( $links ) ? $links : [] as $link ) {
			$number = is_array( $link ) && is_string( $link['url'] ?? null ) ? Tracker::number( $link['url'] ) : 0;
			if ( $number <= 0 || isset( $seen[ $number ] ) ) {
				continue;
			}
			$seen[ $number ] = true;
			$state           = Tracker::state( $number, $json );
			if ( $state !== null && Tracker::closes( $state ) ) {
				return [ 'number' => $number ] + $state;
			}
		}

		return null;
	}

	/**
	 * An in-flight item becomes a done item, by its own who.
	 *
	 * @param array<string, mixed>                                      $value
	 * @param array{number: int, state: string, pull: bool, at: string} $closed
	 * @param string[]                                                  $made   Keys of the done items this pass made: each item gets its own.
	 */
	private static function closeFlight( int $artifactId, string $key, array $value, array $closed, array &$made ): bool {
		$day = self::day( $closed['at'] );
		foreach ( TioDayLinks::docs( $artifactId, 'done', [ Tracker::prefix() . '#' . $closed['number'] ] ) as $done ) {
			$date = is_array( $done['value'] ) && is_string( $done['value']['date'] ?? null ) ? $done['value']['date'] : substr( (string) $done['key'], 0, 10 );
			if ( strcmp( $date, $day ) >= 0 && ! in_array( (string) $done['key'], $made, true ) ) {
				// The landing is recorded already: only clear the item.
				$gone = TioDay::inflight( $artifactId, [ 'slug' => $key, 'clear' => true ] );

				return is_array( $gone ) && ! empty( $gone['cleared'] );
			}
		}

		$repo = explode( '/', Config::all()['issues']['repo'] );
		$done = TioDay::done(
			$artifactId,
			[
				'date'           => $day,
				'stream'         => (string) end( $repo ),
				'title'          => is_string( $value['title'] ?? null ) && trim( $value['title'] ) !== '' ? $value['title'] : $key,
				'detail'         => self::why( $closed ),
				'links'          => is_array( $value['links'] ?? null ) ? array_values( $value['links'] ) : [],
				'by'             => is_string( $value['who'] ?? null ) ? $value['who'] : '',
				'kind'           => ( $value['kind'] ?? '' ) === 'feature' ? 'feature' : null,
				// This item only: another one on the same issue gets its own done item.
				'clear_inflight' => $key,
				'keep_inflight'  => true,
			]
		);
		if ( $done instanceof WP_Error ) {
			return false;
		}
		$made[] = (string) $done['key'];

		return true;
	}

	/**
	 * A feature task is marked done, with a comment saying why.
	 *
	 * @param array{number: int, state: string, pull: bool, at: string} $closed
	 */
	private static function closeTask( int $artifactId, int $owner, string $key, array $closed ): bool {
		$done = TioDay::task( $artifactId, $owner, [ 'slug' => $key, 'close' => true, 'done_by' => __( 'the issue tracker', 'tomatio' ) ] );
		if ( $done instanceof WP_Error ) {
			return false;
		}
		TioDay::reply( $artifactId, [ 'on' => 't:' . $key, 'text' => self::why( $closed ), 'by' => self::BY ] );

		return true;
	}

	/**
	 * "Closed with #12: …", the detail of the done item and the comment on the task.
	 *
	 * @param array{number: int, state: string, pull: bool, at: string} $closed
	 */
	public static function why( array $closed ): string {
		return $closed['pull']
			/* translators: %d: a pull request's number. */
			? sprintf( __( 'Closed with #%d: its pull request merged, so this closed by itself.', 'tomatio' ), $closed['number'] )
			/* translators: %d: an issue's number. */
			: sprintf( __( 'Closed with #%d: its issue closed on the tracker, so this closed by itself.', 'tomatio' ), $closed['number'] );
	}

	/** The day it closed, in the brain's time zone; today when unknown. */
	private static function day( string $closedAt ): string {
		$at = $closedAt !== '' ? strtotime( $closedAt ) : false;

		return $at !== false ? (string) wp_date( 'Y-m-d', $at ) : TioDay::today();
	}
}
