<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\Backlog\Assignees;
use AgentBrain\Modules\Backlog\Backlog;
use AgentBrain\Modules\Backlog\Change;
use AgentBrain\Modules\Backlog\Item;
use AgentBrain\Modules\Backlog\Progress;
use AgentBrain\Modules\Backlog\Project;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\DataStore\TioDayArchive;
use AgentBrain\Modules\DataStore\TioDayIds;
use WP_Error;

/**
 * #1001: every source feeds the Tomagic Inbox, and nothing reaches a
 * release without triage.
 *
 * Sources: the brain's issue tracker (Forgejo or GitHub, read-only, set in
 * Settings > Extensions, Issue tracker) and the open TíoDay tasks marked as
 * features, of people who may write the board. Notion and Linear join once
 * they ship (#992, #993).
 *
 * - Into the Inbox only: a new item lands untriaged (status inbox, no rank,
 *   no release), with where it came from ("From: Issue #998", "From: TíoDay
 *   T380") and a link back. Never into a release, Later or a rank.
 * - Once each: an item already on the board (by the source's own ID, the
 *   issue's link or the TíoDay task ID, in any state, the Archive included)
 *   is skipped, and so is anything brought in before, even when its item
 *   was removed since: rejecting it never brings it back.
 * - Triage stays a person's (or a master agent's) call.
 * - Each source is switched on or off per board (off until someone turns
 *   it on), and every import is audited (backlog.inbox_import), as is every
 *   switch (backlog.inbox_source).
 *
 * #1088: the intake also tidies the Ideas list: an idea whose source issue
 * closed, or whose TíoDay task is done, moves to the Archive with that
 * reason (archiveIdeas), whether or not a source is switched on.
 *
 * The state lives in integrations/inbox on the board: {on: {issues, tioday},
 * seen: {<source key>: [ids]}, last: {at, <source>: {imported, error?}}}.
 * The issue tracker's token never goes there: it stays in Config, sealed.
 * Nothing is ever written back to a source.
 */
final class Inbox {

	/** integrations/<DOC> on each board. */
	public const DOC = 'inbox';

	/** The sources the intake reads. */
	public const SOURCES = [ 'issues', 'tioday' ];

	/** The cron hook. */
	public const HOOK = 'tomatio_backlog_inbox';

	/** At most this many new items per source per run; the rest come next run. */
	public const MAX_PER_RUN = 100;

	/** IDs remembered per source key, newest kept. */
	private const MAX_SEEN = 800;

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'syncAll' ] );
		add_action( 'init', [ self::class, 'schedule' ], 40 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	public static function syncAll(): void {
		foreach ( Backlog::all() as $board ) {
			self::sync( $board );
		}
	}

	/**
	 * Which sources feed this board's Inbox.
	 *
	 * @return array{issues: bool, tioday: bool}
	 */
	public static function switches( int $board ): array {
		$on = self::state( $board )['value']['on'] ?? [];

		return [
			'issues' => is_array( $on ) && ! empty( $on['issues'] ),
			'tioday' => is_array( $on ) && ! empty( $on['tioday'] ),
		];
	}

	/**
	 * The last run on this board: {at, issues: {imported, error?}, tioday: {...}}.
	 *
	 * @return array<string, mixed>
	 */
	public static function last( int $board ): array {
		$last = self::state( $board )['value']['last'] ?? [];

		return is_array( $last ) ? $last : [];
	}

	/** Switch one source on or off for a board; audited. */
	public static function setSwitch( int $board, string $source, bool $on, int $actorId ): bool {
		if ( ! in_array( $source, self::SOURCES, true ) || ! Backlog::isBacklog( $board ) ) {
			return false;
		}
		$state = self::state( $board );
		$value = $state['value'];
		$was   = ! empty( $value['on'][ $source ] );
		if ( $was === $on ) {
			return true;
		}
		$value['on']            = is_array( $value['on'] ?? null ) ? $value['on'] : [];
		$value['on'][ $source ] = $on;
		if ( ! self::save( $board, $value, $state['version'] ) ) {
			return false;
		}
		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'backlog.inbox_source',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $board,
				'meta'        => [ 'source' => $source, 'on' => $on ],
			]
		);

		return true;
	}

	/**
	 * Bring what is new from every switched-on source into the board's
	 * Inbox. $options is for tests and the command line: issues_json (the
	 * API's JSON, read instead of the tracker), now (an ISO time).
	 *
	 * @param array{issues_json?: array<mixed>|null, now?: string} $options
	 * @return array{issues: int, tioday: int, archived: int, notes: array<int, string>}
	 */
	public static function sync( int $board, array $options = [] ): array {
		$out = [ 'issues' => 0, 'tioday' => 0, 'archived' => 0, 'notes' => [] ];
		if ( ! Backlog::isBacklog( $board ) ) {
			$out['notes'][] = 'Artifact ' . $board . ' is not a board.';

			return $out;
		}
		$at = (string) ( $options['now'] ?? gmdate( 'Y-m-d\TH:i:s\Z' ) );

		// #1088: an idea whose source issue closed, or whose TíoDay task is done, goes to the Archive.
		$out['archived'] = self::archiveIdeas( $board, $options['issues_json'] ?? null, $at );

		$on = self::switches( $board );
		if ( ! $on['issues'] && ! $on['tioday'] ) {
			return $out;
		}

		$state = self::state( $board );
		$value = $state['value'];
		$seen  = is_array( $value['seen'] ?? null ) ? $value['seen'] : [];
		$known = self::known( $board );
		$last  = [ 'at' => $at ];

		foreach ( self::SOURCES as $source ) {
			if ( ! $on[ $source ] ) {
				continue;
			}
			$records = $source === 'issues' ? self::issues( $options['issues_json'] ?? null ) : self::tasks( $board );
			if ( is_string( $records ) ) {
				$last[ $source ] = [ 'imported' => 0, 'error' => $records ];
				$out['notes'][]  = ( $source === 'issues' ? 'Issues' : 'TíoDay' ) . ': ' . $records;
				continue;
			}
			$imported = 0;
			foreach ( $records as $record ) {
				if ( $imported >= self::MAX_PER_RUN ) {
					$out['notes'][] = ( $source === 'issues' ? 'Issues' : 'TíoDay' ) . ': more than ' . self::MAX_PER_RUN . ' new; the rest come next run.';
					break;
				}
				$id     = (string) $record['id'];
				$key    = (string) $record['key'];
				$before = is_array( $seen[ $key ] ?? null ) ? array_map( 'strval', $seen[ $key ] ) : [];
				if ( in_array( $id, $before, true ) || self::isKnown( $known, $record ) ) {
					continue;
				}
				$item = self::import( $board, $record, $at );
				if ( $item instanceof WP_Error ) {
					$out['notes'][] = $record['from'] . ': ' . $item->get_error_message();
					continue;
				}
				$before[]       = $id;
				$seen[ $key ]   = array_slice( $before, -self::MAX_SEEN );
				$known[ $key . '|' . $id ] = true;
				++$imported;
			}
			$last[ $source ] = [ 'imported' => $imported ];
			$out[ $source ]  = $imported;
		}

		// Read again: a switch may have moved while the sources were read.
		$fresh                = self::state( $board );
		$fresh['value']['seen'] = $seen;
		$fresh['value']['last'] = $last;
		self::save( $board, $fresh['value'], $fresh['version'] );

		return $out;
	}

	/**
	 * The open issues of the tracker as intake records, or why not.
	 *
	 * @param array<mixed>|null $json
	 * @return array<int, array<string, mixed>>|string
	 */
	private static function issues( ?array $json ) {
		$source = IssuesSource::configured( $json );
		if ( is_string( $source ) ) {
			return 'no issue tracker is set up (Settings > Extensions, Issue tracker).';
		}
		$manifest = Manifest::bundled( 'issues' );
		if ( is_string( $manifest ) ) {
			return $manifest;
		}
		$records = $source->open( $manifest );
		if ( $records instanceof WP_Error ) {
			$data = $records->get_error_data();

			return IssuesSource::why( is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0, $records->get_error_message(), Config::token( IssuesSource::SOURCE ) !== '' );
		}

		$out = [];
		foreach ( $records as $record ) {
			$kind  = (string) $record['kind'];
			$out[] = [
				'source'   => 'issues',
				'key'      => (string) $record['key'],
				'id'       => (string) $record['id'],
				'url'      => (string) $record['url'],
				'title'    => (string) $record['title'],
				'detail'   => (string) $record['detail'],
				'kind'     => in_array( $kind, Item::KINDS, true ) && $kind !== 'idea' ? $kind : 'feature',
				'from'     => 'Issue #' . $record['id'],
				'alias'    => '#' . $record['id'],
				'tioday'   => '',
				'updated'  => (string) $record['updated_at'],
				'snapshot' => is_array( $record['snapshot'] ) ? $record['snapshot'] : [],
			];
		}

		return $out;
	}

	/**
	 * The open TíoDay tasks marked as features, of people who may write the
	 * board, that are not already about an item on it. Only the title and
	 * the why come along, never the task's other fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function tasks( int $board ): array {
		$out   = [];
		$url   = Assignees::url( $board );
		$posts = TioDay::candidates( [ [ 'key' => HomeWorkspace::META_OWNER, 'compare' => 'EXISTS' ] ] );
		foreach ( $posts as $post ) {
			$tioday = (int) $post->ID;
			$owner  = (int) get_post_meta( $tioday, HomeWorkspace::META_OWNER, true );
			if ( $owner <= 0 || ! Backlog::canWrite( $owner, $board ) ) {
				continue;
			}
			foreach ( StoreData::list( $tioday, 'tasks' ) as $doc ) {
				$task = is_array( $doc['value'] ) ? $doc['value'] : [];
				if ( ( $task['kind'] ?? '' ) !== 'feature' || in_array( (string) ( $task['status'] ?? 'open' ), [ 'done', 'dropped', 'deleted' ], true ) ) {
					continue;
				}
				$links = is_array( $task['links'] ?? null ) ? $task['links'] : [];
				if ( $url !== '' && Progress::linkedItem( $url, $links ) !== '' ) {
					// Already about an item on this board (a task made from it).
					continue;
				}
				$id    = is_string( $task['id'] ?? null ) && $task['id'] !== '' ? $task['id'] : (string) $doc['key'];
				$title = trim( (string) ( $task['title'] ?? '' ) );
				if ( $title === '' ) {
					continue;
				}
				$out[] = [
					'source'   => 'tioday',
					'key'      => 'tioday:' . $tioday,
					'id'       => $id,
					'url'      => TioDay::url( $tioday ),
					'title'    => $title,
					'detail'   => trim( (string) ( $task['why'] ?? '' ) ),
					'kind'     => 'feature',
					'from'     => 'TíoDay ' . $id,
					'alias'    => '',
					'tioday'   => $id,
					'updated'  => (string) ( $task['updated_at'] ?? '' ),
					'snapshot' => [],
				];
			}
		}

		return $out;
	}

	/**
	 * Write one record as a new Inbox item, with its log line and audit row.
	 *
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>|WP_Error
	 */
	private static function import( int $board, array $record, string $at ) {
		$from  = (string) $record['from'];
		$links = (string) $record['url'] !== '' ? TioDay::links( [ [ 'label' => $from, 'url' => (string) $record['url'] ] ] ) : [];
		$entry = array_filter(
			[
				'id'         => (string) $record['id'],
				'url'        => (string) $record['url'],
				'main'       => true,
				'first_read' => $at,
				'synced_at'  => $at,
				'updated_at' => (string) $record['updated'],
				'snapshot'   => $record['snapshot'],
			],
			static fn ( $v ): bool => $v !== '' && $v !== [] && $v !== null
		);
		$by    = $record['source'] === 'issues' ? 'issue tracker' : 'TíoDay';
		$value = [
			'kind'     => (string) $record['kind'],
			'title'    => TioDay::text( $record['title'], Item::MAX_TITLE ),
			'detail'   => TioDay::text( $record['detail'], Item::MAX_DETAIL, true ),
			'urgency'  => 'later',
			'rank'     => null,
			'status'   => 'inbox',
			'links'    => $links instanceof WP_Error ? [] : $links,
			'added_by' => $by . ' (Inbox intake)',
			'added_at' => $at,
			'from'     => $from,
			'sources'  => [ (string) $record['key'] => $entry ],
			'tags'     => [],
			'aliases'  => [],
			'flags'    => [],
		];
		if ( $record['alias'] !== '' ) {
			$value = Item::withAlias( $value, (string) $record['alias'] );
		}
		if ( $record['tioday'] !== '' ) {
			$value['tioday'] = (string) $record['tioday'];
		}

		$line  = [ 'what' => 'read', 'by' => $by, 'via' => 'Inbox intake: ' . $from, 'from' => null, 'to' => 'inbox' ];
		$wrote = Change::write( $board, Project::freeKey( $board, (string) $value['title'] ), $value, 0, $line, StoreData::anonymousAttribution() );
		if ( $wrote instanceof WP_Error ) {
			return $wrote;
		}
		$item = is_array( $wrote['item']['value'] ) ? $wrote['item']['value'] : [];
		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => 0,
				'event'       => 'backlog.inbox_import',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $board,
				'meta'        => [ 'source' => (string) $record['source'], 'from' => $from, 'item' => (string) ( $item['id'] ?? $wrote['item']['key'] ) ],
			]
		);

		return $item;
	}

	/**
	 * #1088: ideas whose work is over leave the Ideas list by themselves.
	 * An idea whose source issue is closed on the tracker (or whose pull
	 * request merged), or whose TíoDay task is done, moves to the Archive
	 * with that as its reason, by the intake, with a log line and an audit
	 * row. A person restores it from the Archive like any other; it comes
	 * back as an idea and is not archived again for the same reason
	 * (intake_archived keeps it). Returns how many moved.
	 *
	 * The issue rule needs the issue tracker; the TíoDay rule needs nothing.
	 * Neither depends on a source being switched on for new work.
	 *
	 * @param array<mixed>|null $json
	 */
	private static function archiveIdeas( int $board, ?array $json, string $at ): int {
		$count = 0;
		$fresh = false;
		foreach ( StoreData::list( $board, 'items' ) as $doc ) {
			$value = is_array( $doc['value'] ) ? $doc['value'] : [];
			if ( ( $value['status'] ?? '' ) !== 'idea' ) {
				continue;
			}
			if ( ! $fresh ) {
				// One read per issue per pass.
				Tracker::forget();
				$fresh = true;
			}
			$why = self::ideaOver( $value, $json );
			if ( $why === null ) {
				continue;
			}
			[ $by, $reason ] = $why;
			if ( ( $value['intake_archived'] ?? '' ) === $reason ) {
				// A person restored it since: it is theirs to keep.
				continue;
			}
			$value['status']          = 'archived';
			$value['intake_archived'] = $reason;
			// As a rejected idea is marked: restoring it makes it an idea again.
			$value['idea']    = 'dropped';
			$value['rank']    = null;
			$value['decided'] = [ 'by' => $by . ' (Inbox intake)', 'at' => $at, 'reason' => $reason ];
			$line             = [ 'what' => 'status', 'by' => $by, 'via' => 'Inbox intake', 'from' => 'idea', 'to' => 'archived', 'reason' => $reason ];
			$wrote            = Change::write( $board, (string) $doc['key'], $value, (int) $doc['version'], $line, StoreData::anonymousAttribution() );
			if ( $wrote instanceof WP_Error ) {
				continue;
			}
			++$count;
			\AgentBrain\Core\Hooks::action(
				'audit',
				[
					'actor_id'    => 0,
					'event'       => 'backlog.idea_archived',
					'status'      => 'ok',
					'object_type' => 'artifact',
					'object_id'   => (string) $board,
					'meta'        => [ 'item' => (string) ( $value['id'] ?? $doc['key'] ), 'reason' => $reason ],
				]
			);
		}

		return $count;
	}

	/**
	 * Why an idea's work is over: [who says so, the reason], or null when
	 * it is not (or nothing says).
	 *
	 * @param array<string, mixed> $idea
	 * @param array<mixed>|null    $json
	 * @return array{0: string, 1: string}|null
	 */
	private static function ideaOver( array $idea, ?array $json ): ?array {
		$sources = is_array( $idea['sources'] ?? null ) ? $idea['sources'] : [];

		// Its TíoDay task (the intake's source key is "tioday:<TíoDay>").
		$taskId = is_string( $idea['tioday'] ?? null ) ? $idea['tioday'] : '';
		foreach ( array_keys( $sources ) as $key ) {
			if ( $taskId === '' || preg_match( '/^tioday:(\d+)$/', (string) $key, $m ) !== 1 ) {
				continue;
			}
			$task = TioDayIds::find( (int) $m[1], $taskId ) ?? TioDayArchive::find( (int) $m[1], $taskId );
			if ( $task !== null && $task['collection'] === 'tasks' && is_array( $task['doc']['value'] ) && ( $task['doc']['value']['status'] ?? '' ) === 'done' ) {
				/* translators: %s: a TíoDay task's short ID, such as T12. */
				return [ 'TíoDay', sprintf( __( 'TíoDay task %s is done', 'tomatio' ), strtoupper( $taskId ) ) ];
			}
		}

		// Its source issue: the one the intake read it from, else the first link into the tracker's repository.
		$number = 0;
		$key    = Config::key( IssuesSource::SOURCE );
		if ( $key !== '' && is_array( $sources[ $key ] ?? null ) && is_numeric( $sources[ $key ]['id'] ?? null ) ) {
			$number = (int) $sources[ $key ]['id'];
		}
		foreach ( is_array( $idea['links'] ?? null ) ? $idea['links'] : [] as $link ) {
			if ( $number > 0 ) {
				break;
			}
			$number = is_array( $link ) && is_string( $link['url'] ?? null ) ? Tracker::number( $link['url'] ) : 0;
		}
		$state = $number > 0 ? Tracker::state( $number, $json ) : null;
		if ( $state === null || ! Tracker::closes( $state ) ) {
			return null;
		}

		return [
			'issue tracker',
			$state['pull']
				/* translators: %d: a pull request's number. */
				? sprintf( __( 'Pull request #%d merged', 'tomatio' ), $number )
				/* translators: %d: an issue's number. */
				: sprintf( __( 'Issue #%d closed', 'tomatio' ), $number ),
		];
	}

	/**
	 * What is on the board already, in any state: "<source key>|<id>", "url|<link>"
	 * and "tioday|<task ID>".
	 *
	 * @return array<string, bool>
	 */
	private static function known( int $board ): array {
		$known = [];
		foreach ( StoreData::list( $board, 'items' ) as $doc ) {
			$v = is_array( $doc['value'] ) ? $doc['value'] : [];
			foreach ( is_array( $v['sources'] ?? null ) ? $v['sources'] : [] as $key => $entry ) {
				if ( is_array( $entry ) && isset( $entry['id'] ) && is_scalar( $entry['id'] ) ) {
					$known[ $key . '|' . $entry['id'] ] = true;
					if ( is_string( $entry['url'] ?? null ) && $entry['url'] !== '' ) {
						$known[ 'url|' . $entry['url'] ] = true;
					}
				}
			}
			foreach ( is_array( $v['links'] ?? null ) ? $v['links'] : [] as $link ) {
				if ( is_array( $link ) && is_string( $link['url'] ?? null ) && str_contains( $link['url'], '/issues/' ) ) {
					$known[ 'url|' . $link['url'] ] = true;
				}
			}
			if ( is_string( $v['tioday'] ?? null ) && $v['tioday'] !== '' ) {
				$known[ 'tioday|' . strtoupper( $v['tioday'] ) ] = true;
			}
		}

		return $known;
	}

	/**
	 * @param array<string, bool>  $known
	 * @param array<string, mixed> $record
	 */
	private static function isKnown( array $known, array $record ): bool {
		if ( isset( $known[ $record['key'] . '|' . $record['id'] ] ) ) {
			return true;
		}
		if ( $record['source'] === 'issues' && (string) $record['url'] !== '' && isset( $known[ 'url|' . $record['url'] ] ) ) {
			return true;
		}

		return $record['source'] === 'tioday' && isset( $known[ 'tioday|' . strtoupper( (string) $record['id'] ) ] );
	}

	/**
	 * integrations/inbox on the board, or an empty one.
	 *
	 * @return array{value: array<string, mixed>, version: int}
	 */
	private static function state( int $board ): array {
		$doc = StoreData::get( $board, 'integrations', self::DOC );
		if ( $doc === null || ! is_array( $doc['value'] ) ) {
			return [ 'value' => [ 'on' => [], 'seen' => [], 'last' => [] ], 'version' => 0 ];
		}

		return [ 'value' => $doc['value'], 'version' => (int) $doc['version'] ];
	}

	/** @param array<string, mixed> $value */
	private static function save( int $board, array $value, int $version ): bool {
		$value['source'] = self::DOC;
		foreach ( [ 'on', 'seen', 'last' ] as $field ) {
			if ( ! is_array( $value[ $field ] ?? null ) || $value[ $field ] === [] ) {
				$value[ $field ] = new \stdClass();
			}
		}
		$wrote = StoreData::set( $board, 'integrations', self::DOC, $value, StoreData::anonymousAttribution(), null, $version );

		return ! ( $wrote instanceof WP_Error );
	}
}
