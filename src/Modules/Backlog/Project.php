<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\DataStore\TioDayIds;
use AgentBrain\Modules\Identity\Capabilities;
use WP_Error;

/**
 * #891: what the project tools do to a backlog (615 sections 622 to 628,
 * note 643). People rank, agents propose:
 *
 *  - anyone who may write the backlog logs an item (into the Inbox),
 *    comments, and sets building or shipped (an agent with a link);
 *  - ranking, accepting (ready), declining, parking and archiving need a
 *    person: the caller is a person, or an agent with a person behind the
 *    connection (delegated consent or act-as), logged "agent for person";
 *  - a pure agent with nobody behind it proposes those in a comment.
 *
 * Every change writes its log line in the same call (Change::write).
 */
final class Project {

	/** Log lines tioday-get style reads return at most. */
	private const MAX_LINES = 100;

	/**
	 * meta/project of a new backlog.
	 *
	 * @return array<string, mixed>
	 */
	public static function newProject( string $name, int $scopeId ): array {
		return [
			'name'       => TioDay::text( $name, Item::MAX_TITLE ),
			'scope_id'   => $scopeId,
			'now_cut'    => Rank::NOW_CUT,
			'next_cut'   => Rank::NEXT_CUT,
			'members'    => [],
			'agents'     => [],
			'people'     => new \stdClass(),
			'updated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'updated_by' => 'TomaTío',
		];
	}

	/**
	 * meta/project, with its version (0 when missing).
	 *
	 * @return array{0: array<string, mixed>, 1: int}
	 */
	public static function project( int $artifactId ): array {
		$doc = StoreData::get( $artifactId, 'meta', 'project' );

		return [ $doc !== null && is_array( $doc['value'] ) ? $doc['value'] : self::newProject( '', Backlog::scopeOf( $artifactId ) ), $doc !== null ? (int) $doc['version'] : 0 ];
	}

	// -----------------------------------------------------------------
	// Who is calling
	// -----------------------------------------------------------------

	/**
	 * The writer, the person behind it, and how the log names them.
	 *
	 * @return array{writer:int, person:int, by:string, for:string, people:bool, store:array<string, mixed>}
	 */
	public static function caller( int $artifactId ): array {
		$writer = TioDay::writerId();
		$person = TioDay::person();
		$isAgent = $writer > 0 && Capabilities::isPureAgent( $writer );

		return [
			'writer' => $writer,
			'person' => $person,
			'by'     => self::login( $writer ),
			'for'    => $isAgent && $person > 0 && $person !== $writer ? self::login( $person ) : '',
			'people' => ! $isAgent || $person > 0,
			'store'  => StoreData::attributionFromUser( $writer, $artifactId ),
		];
	}

	private static function login( int $userId ): string {
		$user = $userId > 0 ? get_userdata( $userId ) : false;

		return $user instanceof \WP_User ? (string) $user->user_login : '';
	}

	// -----------------------------------------------------------------
	// Reading
	// -----------------------------------------------------------------

	/**
	 * The item $ref names: a B ID (B12), its key, or an alias (#807, AB-84,
	 * T151). Null when none does.
	 *
	 * @return array<string, mixed>|null The stored document.
	 */
	public static function find( int $artifactId, string $ref ): ?array {
		$ref = trim( $ref );
		if ( $ref === '' ) {
			return null;
		}
		if ( TioDayIds::isId( $ref ) && TioDayIds::collectionOf( $ref ) === 'items' ) {
			$found = TioDayIds::find( $artifactId, $ref );

			return $found !== null ? $found['doc'] : null;
		}
		$key = StoreData::normalizeKey( strtolower( $ref ) );
		$doc = $key !== null ? StoreData::get( $artifactId, 'items', $key ) : null;
		if ( $doc !== null ) {
			return $doc;
		}
		foreach ( StoreData::list( $artifactId, 'items' ) as $doc ) {
			if ( Item::matches( $doc, $ref ) ) {
				return $doc;
			}
		}

		return null;
	}

	/**
	 * tomatio-project-get: one item with its comments and history, or the
	 * list (Inbox, then Now / Next / Later in rank order) and what changed
	 * since the caller last looked.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( int $artifactId, array $input ) {
		[ $project ] = self::project( $artifactId );
		$caller      = self::caller( $artifactId );

		if ( isset( $input['item'] ) && is_string( $input['item'] ) && trim( $input['item'] ) !== '' ) {
			$doc = self::find( $artifactId, $input['item'] );
			if ( $doc === null ) {
				return self::notFound( $input['item'] );
			}

			return self::one( $artifactId, $doc, $project );
		}

		$wanted = isset( $input['status'] ) && is_string( $input['status'] ) && $input['status'] !== '' ? $input['status'] : 'open';
		$band   = isset( $input['band'] ) && is_string( $input['band'] ) ? $input['band'] : '';
		$query  = isset( $input['query'] ) && is_string( $input['query'] ) ? mb_strtolower( trim( $input['query'] ) ) : '';
		$limit  = isset( $input['limit'] ) && is_numeric( $input['limit'] ) ? max( 1, min( 500, (int) $input['limit'] ) ) : 200;

		$inbox    = [];
		$ideas    = [];
		$ranked   = [];
		$other    = [];
		$verdicts = self::verdicts( $artifactId );
		$threads  = self::threads( $artifactId );
		$marked   = [ 'done' => 0, 'drop' => 0, 'keep' => 0 ];
		$all      = StoreData::list( $artifactId, 'items' );
		$choices  = Releases::choices( $artifactId );
		$plan     = Releases::plan( $project, $all, $choices );
		$blocks   = [];
		foreach ( $plan['upcoming'] as $version ) {
			$blocks[ $version ] = [];
		}
		$unplanned = 0;
		$later     = 0;
		foreach ( $all as $doc ) {
			$row = self::withRelease( Item::summary( $doc, $project ) + self::feedback( $doc, $verdicts, $threads ), $doc, $choices, $plan );
			if ( $row['status'] === 'idea' ) {
				// #995: ideas sit apart: not in the plan, not counted as work.
				if ( ( $query === '' || str_contains( mb_strtolower( (string) $row['title'] ), $query ) ) && ( $wanted === 'open' || $wanted === 'all' || $wanted === 'idea' ) ) {
					$ideas[] = $row;
				}
				continue;
			}
			if ( ! in_array( $row['status'], [ 'shipped', 'declined', 'archived' ], true ) ) {
				if ( isset( $row['release'], $blocks[ $row['release'] ] ) ) {
					$blocks[ $row['release'] ][] = $row;
				} elseif ( ! isset( $row['release'] ) ) {
					++$unplanned;
				} elseif ( $row['release'] === Releases::LATER ) {
					++$later;
				}
			}
			if ( isset( $input['release'] ) && is_string( $input['release'] ) && $input['release'] !== '' && ( $row['release'] ?? '' ) !== Releases::resolve( (string) Releases::normalize( $input['release'] ), $plan ) ) {
				continue;
			}
			if ( $query !== '' && ! str_contains( mb_strtolower( (string) $row['title'] ), $query ) && ! Item::matches( $doc, $query ) ) {
				continue;
			}
			$status = (string) $row['status'];
			if ( $wanted === 'open' ? in_array( $status, [ 'shipped', 'declined', 'archived', 'parked' ], true ) : ( $wanted !== 'all' && $status !== $wanted ) ) {
				continue;
			}
			if ( $band !== '' && ( $row['band'] ?? ( $status === 'inbox' ? 'inbox' : '' ) ) !== $band ) {
				continue;
			}
			if ( isset( $row['verdict'] ) ) {
				++$marked[ $row['verdict']['status'] ];
			}
			if ( $status === 'inbox' ) {
				$inbox[] = $row;
			} elseif ( isset( $row['band'] ) ) {
				$ranked[] = $row;
			} else {
				$other[] = $row;
			}
		}
		usort( $ranked, static fn ( array $a, array $b ): int => strcmp( (string) $a['rank'], (string) $b['rank'] ) );
		// Newest first: B IDs grow as items are added.
		usort( $inbox, static fn ( array $a, array $b ): int => (int) substr( (string) ( $b['id'] ?? 'B0' ), 1 ) <=> (int) substr( (string) ( $a['id'] ?? 'B0' ), 1 ) );

		$counts = [ 'inbox' => count( $inbox ), 'now' => 0, 'next' => 0, 'later' => 0 ];
		foreach ( $ranked as $row ) {
			++$counts[ $row['band'] ];
		}

		[ $since, $mark ] = self::sinceSeen( $artifactId, $caller );
		$out              = [
			'artifact_id' => $artifactId,
			'url'         => TioDay::url( $artifactId ),
			'project'     => [
				'name'     => (string) ( $project['name'] ?? '' ),
				'now_cut'  => Rank::cuts( $project )[0],
				'next_cut' => Rank::cuts( $project )[1],
			],
			'counts'      => $counts,
			'verdicts'    => $marked,
			'releases'    => self::planOut( $project, $plan, $blocks, $unplanned, $later ),
			'ideas'       => array_slice( $ideas, 0, $limit ),
			'inbox'       => array_slice( $inbox, 0, $limit ),
			'ranked'      => array_slice( $ranked, 0, $limit ),
			'since_seen'  => $since,
			'seen_mark'   => $mark,
		];
		if ( $other !== [] ) {
			$out['other'] = array_slice( $other, 0, $limit );
		}
		if ( ! empty( $input['mark_seen'] ) ) {
			$out['seen_mark'] = self::markSeen( $artifactId, $caller );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $doc
	 * @param array<string, mixed> $project
	 * @return array<string, mixed>
	 */
	private static function one( int $artifactId, array $doc, array $project ): array {
		$v        = is_array( $doc['value'] ) ? $doc['value'] : [];
		$id       = (string) ( $v['id'] ?? $doc['key'] );
		$comments = array_map( static fn ( array $c ): array => [ 'key' => (string) $c['key'] ] + ( is_array( $c['value'] ) ? $c['value'] : [] ), StoreData::query( $artifactId, 'comments', [ 'on' => $id ], StoreData::QUERY_MAX_LIMIT, 0 ) );
		usort( $comments, static fn ( array $a, array $b ): int => strcmp( (string) ( $a['at'] ?? '' ), (string) ( $b['at'] ?? '' ) ) );
		$history = array_map( static fn ( array $l ): array => [ 'key' => (string) $l['key'] ] + ( is_array( $l['value'] ) ? $l['value'] : [] ), StoreData::query( $artifactId, 'log', [ 'on' => $id ], self::MAX_LINES, 0 ) );
		$verdict = self::verdictOf( StoreData::get( $artifactId, 'verdicts', (string) $doc['key'] ) );
		$choices = Releases::choices( $artifactId );
		$plan    = Releases::plan( $project, StoreData::list( $artifactId, 'items' ), $choices );
		$release = self::withRelease( [], $doc, $choices, $plan );

		return [
			'artifact_id' => $artifactId,
			'url'         => TioDay::url( $artifactId ) . '#' . rawurlencode( $id ),
			'item'        => [ 'key' => (string) $doc['key'], 'version' => (int) $doc['version'], 'band' => in_array( $v['status'] ?? '', Item::RANKED, true ) ? Rank::band( is_string( $v['rank'] ?? null ) ? $v['rank'] : null, $project ) : null ] + $v,
			'verdict'     => $verdict,
			'release'     => $release['release'] ?? null,
			'next'        => ! empty( $release['next_release'] ),
			'comments'    => $comments,
			'history'     => $history,
		];
	}

	// -----------------------------------------------------------------
	// #985: verdicts and comments people leave on the page
	// -----------------------------------------------------------------

	/**
	 * Every verdict on the backlog, by item key (verdicts/<item key>).
	 *
	 * @return array<string, array{status:string, by:string, at:string}>
	 */
	private static function verdicts( int $artifactId ): array {
		$out = [];
		foreach ( StoreData::list( $artifactId, 'verdicts' ) as $doc ) {
			$verdict = self::verdictOf( $doc );
			if ( $verdict !== null ) {
				$out[ (string) $doc['key'] ] = $verdict;
			}
		}

		return $out;
	}

	/**
	 * A verdicts document as project-get shows it, or null when it is open.
	 * `by` is who the store saw write it (the signed-in viewer), not what the
	 * page claimed.
	 *
	 * @param array<string, mixed>|null $doc
	 * @return array{status:string, by:string, at:string}|null
	 */
	private static function verdictOf( ?array $doc ): ?array {
		$v      = $doc !== null && is_array( $doc['value'] ) ? $doc['value'] : [];
		$status = is_string( $v['status'] ?? null ) ? $v['status'] : '';
		if ( ! in_array( $status, Item::VERDICTS, true ) ) {
			return null;
		}
		$writer = is_array( $doc['updated_by'] ?? null ) && empty( $doc['updated_by']['anonymous'] ) ? (string) ( $doc['updated_by']['name'] ?? '' ) : '';

		return [
			'status' => $status,
			'by'     => $writer !== '' ? $writer : TioDay::text( $v['by'] ?? '', TioDay::MAX_NAME ),
			'at'     => is_string( $v['at'] ?? null ) ? $v['at'] : (string) ( $doc['updated_at'] ?? '' ),
		];
	}

	/**
	 * Comments per item (`on`, the B ID): how many, and the last one.
	 *
	 * @return array<string, array{count:int, last:array<string, string>}>
	 */
	private static function threads( int $artifactId ): array {
		$out = [];
		foreach ( StoreData::list( $artifactId, 'comments' ) as $doc ) {
			$v  = is_array( $doc['value'] ) ? $doc['value'] : [];
			$on = is_string( $v['on'] ?? null ) ? $v['on'] : '';
			if ( $on === '' || ! is_string( $v['text'] ?? null ) || trim( $v['text'] ) === '' ) {
				continue;
			}
			$at   = is_string( $v['at'] ?? null ) ? $v['at'] : (string) ( $doc['created_at'] ?? '' );
			$last = $out[ $on ]['last'] ?? null;
			$out[ $on ] = [
				'count' => ( $out[ $on ]['count'] ?? 0 ) + 1,
				'last'  => $last !== null && strcmp( $last['at'], $at ) > 0 ? $last : [ 'by' => TioDay::text( $v['by'] ?? '', TioDay::MAX_NAME ), 'at' => $at, 'text' => TioDay::text( $v['text'], 300, true ) ],
			];
		}

		return $out;
	}

	/**
	 * #986: a row with the item's release resolved ("next" becomes the
	 * version), whether that is the next release, and what was set.
	 *
	 * @param array<string, mixed>                                      $row
	 * @param array<string, mixed>                                      $doc
	 * @param array<string, array<string, mixed>>                       $choices
	 * @param array{running:string, next:string, upcoming:array<int, string>} $plan
	 * @return array<string, mixed>
	 */
	private static function withRelease( array $row, array $doc, array $choices, array $plan ): array {
		unset( $row['release'] );
		$set      = Releases::setOf( $doc, $choices );
		$resolved = Releases::resolve( $set, $plan );
		if ( $resolved !== '' ) {
			$row['release'] = $resolved;
			if ( $resolved === $plan['next'] ) {
				$row['next_release'] = true;
			}
		}
		if ( $set === Releases::NEXT ) {
			$row['release_set'] = Releases::NEXT;
		}

		return $row;
	}

	/**
	 * The plan by release for project-get: what is running, which release
	 * is next, each upcoming release with its open items in rank order.
	 *
	 * @param array<string, mixed>                                      $project
	 * @param array{running:string, next:string, upcoming:array<int, string>} $plan
	 * @param array<string, array<int, array<string, mixed>>>           $blocks
	 * @return array<string, mixed>
	 */
	private static function planOut( array $project, array $plan, array $blocks, int $unplanned, int $later = 0 ): array {
		$listed = Releases::listed( $project );
		$out    = [];
		foreach ( $blocks as $version => $rows ) {
			usort( $rows, static fn ( array $a, array $b ): int => strcmp( (string) ( $a['rank'] ?? '~' ), (string) ( $b['rank'] ?? '~' ) ) );
			$out[] = array_filter(
				[
					'version' => (string) $version,
					'title'   => $listed[ $version ]['title'] ?? '',
					'date'    => $listed[ $version ]['date'] ?? '',
					'next'    => (string) $version === $plan['next'],
					'items'   => array_values( array_map( static fn ( array $r ): string => (string) ( $r['id'] ?? $r['key'] ), $rows ) ),
				],
				static fn ( $x ): bool => $x !== ''
			);
		}

		return [
			'running'   => (string) ( $project['running'] ?? '' ) === Releases::PLUGIN ? $plan['running'] . ' (plugin)' : $plan['running'],
			'next'      => $plan['next'],
			'plan'      => $out,
			'unplanned' => $unplanned,
			'later'     => $later,
			'shipped'   => array_values( array_map( static fn ( array $e ): string => (string) $e['version'], array_filter( $listed, static fn ( array $e ): bool => ! empty( $e['shipped_at'] ) ) ) ),
		];
	}

	/**
	 * An item's verdict and comment count for a list row.
	 *
	 * @param array<string, mixed>                                           $doc
	 * @param array<string, array{status:string, by:string, at:string}>      $verdicts
	 * @param array<string, array{count:int, last:array<string, string>}>    $threads
	 * @return array<string, mixed>
	 */
	private static function feedback( array $doc, array $verdicts, array $threads ): array {
		$v   = is_array( $doc['value'] ) ? $doc['value'] : [];
		$on  = is_string( $v['id'] ?? null ) ? $v['id'] : (string) $doc['key'];
		$out = [];
		if ( isset( $verdicts[ (string) $doc['key'] ] ) ) {
			$out['verdict'] = $verdicts[ (string) $doc['key'] ];
		}
		if ( isset( $threads[ $on ] ) ) {
			$out['comments']     = $threads[ $on ]['count'];
			$out['last_comment'] = $threads[ $on ]['last'];
		}

		return $out;
	}

	/**
	 * Log lines after the caller's seen mark (the last 50 lines on a first
	 * look), and the mark.
	 *
	 * @param array{writer:int, person:int, by:string, for:string, people:bool, store:array<string, mixed>} $caller
	 * @return array{0: array<int, array<string, mixed>>, 1: string}
	 */
	private static function sinceSeen( int $artifactId, array $caller ): array {
		$login = $caller['for'] !== '' ? $caller['for'] : $caller['by'];
		$key   = StoreData::normalizeKey( $login );
		$seen  = $key !== null ? StoreData::get( $artifactId, 'seen', $key ) : null;
		$mark  = $seen !== null && is_array( $seen['value'] ) && is_string( $seen['value']['log'] ?? null ) ? $seen['value']['log'] : '';
		$lines = $mark !== '' ? StoreData::listAfter( $artifactId, 'log', $mark, 50 ) : array_reverse( StoreData::listLast( $artifactId, 'log', 50 ) );

		return [ array_map( static fn ( array $l ): array => [ 'key' => (string) $l['key'] ] + ( is_array( $l['value'] ) ? $l['value'] : [] ), $lines ), $mark ];
	}

	/**
	 * Move the caller's seen mark to the newest log line.
	 *
	 * @param array{writer:int, person:int, by:string, for:string, people:bool, store:array<string, mixed>} $caller
	 */
	private static function markSeen( int $artifactId, array $caller ): string {
		$login = $caller['for'] !== '' ? $caller['for'] : $caller['by'];
		$key   = StoreData::normalizeKey( $login );
		$last  = StoreData::listLast( $artifactId, 'log', 1 );
		$mark  = isset( $last[0] ) ? (string) $last[0]['key'] : '';
		if ( $key !== null && $mark !== '' ) {
			StoreData::set( $artifactId, 'seen', $key, [ 'log' => $mark, 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ], $caller['store'] );
		}

		return $mark;
	}

	// -----------------------------------------------------------------
	// Writing
	// -----------------------------------------------------------------

	/**
	 * tomatio-project-log: a new idea, need, bug, feature or chore, into the
	 * Inbox; with idea: true, an idea on Tomagic (#995).
	 *
	 * @param array<string, mixed>      $input
	 * @param array<string, mixed>|null $caller Who logs it; the connection's caller by default.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function log( int $artifactId, array $input, ?array $caller = null ) {
		$caller = $caller ?? self::caller( $artifactId );
		$now    = time();
		$idea   = ! empty( $input['idea'] );
		if ( $idea ) {
			// #995: an idea is a title and a line or two; no rank, urgency,
			// assignee or release (Dennis, 14 Nov: every idea lands in the
			// Inbox; it gets a release once someone makes it an item).
			if ( is_string( $input['release'] ?? null ) && trim( $input['release'] ) !== '' ) {
				return self::invalid( __( 'An idea has no release: it lands in the Inbox. Once a curator promotes it to an item, put it in a release with tomatio-project-edit.', 'tomatio' ) );
			}
			$input = array_intersect_key( $input, array_flip( [ 'title', 'detail', 'reason', 'links' ] ) ) + [ 'kind' => 'idea' ];
		}
		$item   = Item::fromInput( $input, $caller['for'] !== '' ? $caller['by'] . ' for ' . $caller['for'] : $caller['by'], gmdate( 'Y-m-d\TH:i:s\Z', $now ) );
		if ( is_string( $item ) ) {
			return self::invalid( $item );
		}
		if ( isset( $input['prompt'] ) ) {
			$item['prompt'] = TioDay::text( $input['prompt'], Item::MAX_PROMPT, true );
		}

		if ( $idea ) {
			$item['status'] = 'idea';
			unset( $item['urgency'] );
		}

		$done = Change::write( $artifactId, self::freeKey( $artifactId, (string) $item['title'] ), $item, 0, self::line( $caller, 'added', null, $idea ? 'idea' : 'inbox', $input['reason'] ?? '' ), $caller['store'], $now );

		return $done instanceof WP_Error ? $done : self::answer( $done );
	}

	/**
	 * #995 tomatio-project-idea: a curator promotes an idea to an item (it
	 * goes to the Inbox, to be ranked, keeping its ID, comments and who
	 * logged it) or rejects it (declined, with the reason: it goes to
	 * Tomagic's Archive). Agents log ideas; only a person who is a curator
	 * or manager (or who manages the backlog) decides on them. "drop" is
	 * the old word for reject. restore (Dennis, 14 Nov: nothing disappears)
	 * brings back what is in the Archive: a rejected idea is an idea again,
	 * a declined, parked or archived item goes back to the Inbox. Anyone who
	 * may write the backlog restores, as long as a person asked.
	 *
	 * @param array<string, mixed>      $input  {item, decision: promote|reject|restore, reason?}
	 * @param array<string, mixed>|null $caller Who decides; the connection's caller by default.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function idea( int $artifactId, array $input, ?array $caller = null ) {
		$caller   = $caller ?? self::caller( $artifactId );
		$decision = isset( $input['decision'] ) && is_string( $input['decision'] ) ? $input['decision'] : '';
		$decision = $decision === 'drop' ? 'reject' : $decision;
		if ( ! in_array( $decision, [ 'promote', 'reject', 'restore' ], true ) ) {
			return self::invalid( __( 'decision is promote, reject or restore.', 'tomatio' ) );
		}
		$doc = isset( $input['item'] ) && is_string( $input['item'] ) ? self::find( $artifactId, $input['item'] ) : null;
		if ( $decision === 'restore' ) {
			return self::restore( $artifactId, $doc, (string) ( $input['item'] ?? '' ), TioDay::text( $input['reason'] ?? '', 500 ), $caller );
		}
		if ( ! self::decidesIdeas( $artifactId, $caller ) ) {
			return new WP_Error( 'ab_backlog_curators_only', __( 'Only a curator promotes or rejects an idea. Agents log ideas; comment on the idea to make the case for it.', 'tomatio' ), [ 'status' => 403 ] );
		}
		if ( $doc === null ) {
			return self::notFound( (string) ( $input['item'] ?? '' ) );
		}
		$value = is_array( $doc['value'] ) ? $doc['value'] : [];
		if ( ( $value['status'] ?? '' ) !== 'idea' ) {
			return self::invalid( __( 'That item is not an idea (any more).', 'tomatio' ) );
		}
		$reason = TioDay::text( $input['reason'] ?? '', 500 );
		$who    = $caller['for'] !== '' ? $caller['for'] : $caller['by'];
		if ( $decision === 'promote' ) {
			$value['status'] = 'inbox';
			$value['idea']   = 'promoted';
			$value['rank']   = null;
			$line            = self::line( $caller, 'promoted', 'idea', 'inbox', $reason );
		} else {
			$value['status']  = 'declined';
			$value['idea']    = 'rejected';
			$value['rank']    = null;
			$value['decided'] = [ 'by' => $who, 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'reason' => $reason ];
			$line             = self::line( $caller, 'declined', 'idea', 'declined', $reason !== '' ? $reason : __( 'idea rejected', 'tomatio' ) );
		}

		$done = Change::write( $artifactId, (string) $doc['key'], $value, (int) $doc['version'], $line, $caller['store'] );

		return $done instanceof WP_Error ? $done : self::answer( $done );
	}

	/** Statuses that put an item in Tomagic's Archive (with a Not needed verdict). */
	public const ARCHIVED = [ 'declined', 'parked', 'archived' ];

	/**
	 * Bring back an item from the Archive: a rejected idea becomes an idea
	 * again; a declined, parked or archived item goes back to the Inbox. A
	 * Not needed verdict is cleared too.
	 *
	 * @param array<string, mixed>|null $doc
	 * @param array<string, mixed>      $caller
	 * @return array<string, mixed>|WP_Error
	 */
	private static function restore( int $artifactId, ?array $doc, string $ref, string $reason, array $caller ) {
		if ( $doc === null ) {
			return self::notFound( $ref );
		}
		if ( ! $caller['people'] ) {
			return self::peopleOnly( __( 'restore', 'tomatio' ) );
		}
		$value   = is_array( $doc['value'] ) ? $doc['value'] : [];
		$before  = (string) ( $value['status'] ?? 'inbox' );
		$verdict = StoreData::get( $artifactId, 'verdicts', (string) $doc['key'] );
		$dropped = $verdict !== null && is_array( $verdict['value'] ) && ( $verdict['value']['status'] ?? '' ) === 'drop';
		if ( ! in_array( $before, self::ARCHIVED, true ) && ! $dropped ) {
			return self::invalid( __( 'That item is not in the Archive.', 'tomatio' ) );
		}
		if ( $dropped ) {
			StoreData::set( $artifactId, 'verdicts', (string) $doc['key'], [ 'status' => '' ] + $verdict['value'], $caller['store'], null, (int) $verdict['version'] );
		}
		if ( ! in_array( $before, self::ARCHIVED, true ) ) {
			return [ 'key' => (string) $doc['key'], 'id' => $value['id'] ?? null, 'value' => $value, 'version' => (int) $doc['version'], 'log' => '' ];
		}

		$wasIdea         = in_array( $value['idea'] ?? '', [ 'rejected', 'dropped' ], true );
		$value['status'] = $wasIdea ? 'idea' : 'inbox';
		$value['rank']   = null;
		unset( $value['decided'] );
		if ( $wasIdea ) {
			unset( $value['idea'] );
		}
		$done = Change::write( $artifactId, (string) $doc['key'], $value, (int) $doc['version'], self::line( $caller, 'restored', $before, $value['status'], $reason ), $caller['store'] );

		return $done instanceof WP_Error ? $done : self::answer( $done );
	}

	/**
	 * Whether $caller may promote or drop ideas: a person (an agent only
	 * when it acts for one) who is a curator or manager, or manages this backlog.
	 *
	 * @param array<string, mixed> $caller
	 */
	public static function decidesIdeas( int $artifactId, array $caller ): bool {
		$writer = (int) $caller['writer'];
		$person = Capabilities::isPureAgent( $writer ) ? (int) $caller['person'] : $writer;
		if ( $person <= 0 || Capabilities::isPureAgent( $person ) ) {
			return false;
		}

		return user_can( $person, Capabilities::CAP_APPROVE_KNOWLEDGE ) || user_can( $person, Capabilities::CAP_MANAGE ) || \AgentBrain\Modules\Sharing\ShareLinks::canManage( $person, $artifactId );
	}

	/**
	 * The caller array for a page write: the account the store recorded.
	 *
	 * @return array<string, mixed>
	 */
	public static function callerFor( int $userId, int $artifactId ): array {
		return [
			'writer' => $userId,
			'person' => Capabilities::isPureAgent( $userId ) ? 0 : $userId,
			'by'     => self::login( $userId ),
			'for'    => '',
			'people' => ! Capabilities::isPureAgent( $userId ),
			'store'  => StoreData::attributionFromUser( $userId, $artifactId ),
		];
	}

	/**
	 * #985 tomatio-project-edit: an item's driver and impact lines (what is
	 * driving it, and what it does for the project), as Tomagic
	 * shows them. Agents may set them; '' clears one.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function edit( int $artifactId, array $input ) {
		$caller = self::caller( $artifactId );
		$notes  = Item::notes( $input );
		if ( is_string( $notes ) ) {
			return self::invalid( $notes );
		}
		$release = array_key_exists( 'release', $input ) ? Releases::normalize( $input['release'] ) : false;
		if ( $release === null ) {
			return self::invalid( __( 'release is a version such as 1.9.18, "next" (the next planned release), "later" (we will do it, not scheduled yet), or "" for the Inbox (not decided yet).', 'tomatio' ) );
		}
		$owner = array_key_exists( 'owner', $input ) ? ( is_string( $input['owner'] ) ? $input['owner'] : null ) : false;
		if ( $owner === null ) {
			return self::invalid( __( 'owner is a name: a person or an agent that takes tasks, or "" for nobody.', 'tomatio' ) );
		}
		if ( is_string( $owner ) && ! $caller['people'] ) {
			return self::peopleOnly( __( 'assign an item', 'tomatio' ) );
		}
		// #1004: the effort estimate (anyone who may edit), and the Urgent
		// flag (a project admin only). Priority is computed from them.
		$estimate = array_key_exists( 'estimate', $input ) ? Item::estimate( $input['estimate'] ) : false;
		if ( is_string( $estimate ) ) {
			return self::invalid( $estimate );
		}
		$urgent = array_key_exists( 'urgent', $input ) ? $input['urgent'] : null;
		if ( $urgent !== null && ! is_bool( $urgent ) ) {
			return self::invalid( __( 'urgent is true or false.', 'tomatio' ) );
		}
		if ( $urgent !== null && ! self::decidesIdeas( $artifactId, $caller ) ) {
			return new WP_Error( 'ab_backlog_admins_only', __( 'Only a project admin (a curator or manager, or whoever manages this backlog) marks an item urgent. Comment on the item to ask for it.', 'tomatio' ), [ 'status' => 403 ] );
		}
		$rest = $notes !== [] || $estimate !== false || $urgent !== null;
		if ( ! $rest && $release === false && $owner === false ) {
			return self::invalid( __( 'Say what to change: driver, impact, estimate, urgent, release, owner, or more than one.', 'tomatio' ) );
		}
		$doc = isset( $input['item'] ) && is_string( $input['item'] ) ? self::find( $artifactId, $input['item'] ) : null;
		if ( $doc === null ) {
			return self::notFound( (string) ( $input['item'] ?? '' ) );
		}
		if ( is_string( $release ) && $release !== '' && ( $doc['value']['status'] ?? '' ) === 'idea' ) {
			return self::invalid( __( 'An idea has no release until a curator promotes it to an item (tomatio-project-idea).', 'tomatio' ) );
		}
		if ( $urgent === true && ( $doc['value']['status'] ?? '' ) === 'idea' ) {
			return self::invalid( __( 'An idea is not urgent: a curator promotes it to an item first (tomatio-project-idea).', 'tomatio' ) );
		}
		if ( is_string( $release ) ) {
			$placed = Releases::place( $artifactId, $doc, $release, $caller['store'], array_filter( [ 'by' => $caller['by'], 'for' => $caller['for'], 'reason' => is_string( $input['reason'] ?? null ) ? $input['reason'] : '' ] ) );
			if ( $placed instanceof WP_Error ) {
				return $placed;
			}
			$doc = (array) StoreData::get( $artifactId, 'items', (string) $doc['key'] );
			if ( ! $rest && $owner === false ) {
				return [ 'key' => (string) $doc['key'], 'id' => $doc['value']['id'] ?? null, 'value' => $doc['value'], 'version' => (int) $doc['version'], 'log' => '', 'release' => $release ];
			}
		}
		if ( is_string( $owner ) ) {
			// #987: the assignee, with who assigned it.
			$assigned = Assignees::set( $artifactId, $doc, $owner, $caller, is_string( $input['reason'] ?? null ) ? $input['reason'] : '' );
			if ( $assigned instanceof WP_Error ) {
				return $assigned;
			}
			$doc = (array) StoreData::get( $artifactId, 'items', (string) $doc['key'] );
			if ( ! $rest ) {
				return [ 'key' => (string) $doc['key'], 'id' => $doc['value']['id'] ?? null, 'value' => $doc['value'], 'version' => (int) $doc['version'], 'log' => (string) ( $assigned['log']['key'] ?? '' ) ];
			}
		}

		$value   = is_array( $doc['value'] ) ? $doc['value'] : [];
		$changed = [];
		foreach ( $notes as $field => $text ) {
			if ( (string) ( $value[ $field ] ?? '' ) === $text ) {
				continue;
			}
			$changed[] = $field;
			if ( $text === '' ) {
				unset( $value[ $field ] );
			} else {
				$value[ $field ] = $text;
			}
		}
		if ( $estimate !== false && ( $value['estimate'] ?? null ) !== $estimate ) {
			$changed[] = 'estimate';
			if ( $estimate === null ) {
				unset( $value['estimate'] );
			} else {
				$value['estimate'] = $estimate;
			}
		}
		// The flag's changes go in the item's history with who (History).
		if ( $urgent !== null && ( ( $value['urgent'] ?? false ) === true ) !== $urgent ) {
			$changed[] = 'urgent';
			if ( $urgent ) {
				$value['urgent'] = true;
			} else {
				unset( $value['urgent'] );
			}
		}
		if ( $changed === [] ) {
			return [ 'key' => (string) $doc['key'], 'id' => $value['id'] ?? null, 'value' => $value, 'version' => (int) $doc['version'], 'log' => '' ];
		}

		$done = Change::write( $artifactId, (string) $doc['key'], $value, (int) $doc['version'], self::line( $caller, 'edited', null, implode( ', ', $changed ), $input['reason'] ?? '' ), $caller['store'] );

		return $done instanceof WP_Error ? $done : self::answer( $done );
	}

	/**
	 * #986 tomatio-project-release: plan a release (version, title, date),
	 * take one off the plan, or say what is running ("plugin" follows this
	 * plugin's version). Reaching a release ships it (Releases::roll).
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function release( int $artifactId, array $input ) {
		$caller                       = self::caller( $artifactId );
		[ $project, $projectVersion ] = self::project( $artifactId );
		$listed                       = Releases::listed( $project );
		$changed                      = [];

		if ( array_key_exists( 'running', $input ) ) {
			$running = is_string( $input['running'] ) && strtolower( trim( $input['running'] ) ) === Releases::PLUGIN ? Releases::PLUGIN : Releases::normalize( $input['running'] );
			if ( $running === null || $running === Releases::NEXT ) {
				return self::invalid( __( 'running is a version, "plugin" (follow this plugin\'s version) or "" (none).', 'tomatio' ) );
			}
			$project['running'] = $running;
			$changed[]          = 'running ' . ( $running !== '' ? $running : 'none' );
		}

		if ( isset( $input['version'] ) ) {
			$version = Releases::normalize( $input['version'] );
			if ( $version === null || $version === '' || $version === Releases::NEXT ) {
				return self::invalid( __( 'version is a release such as 1.9.18.', 'tomatio' ) );
			}
			if ( ! empty( $input['remove'] ) ) {
				unset( $listed[ $version ] );
				$changed[] = 'removed ' . $version;
			} else {
				if ( ! isset( $listed[ $version ] ) && count( $listed ) >= Releases::MAX ) {
					return self::invalid( __( 'A plan holds at most 30 releases. Remove a shipped one first.', 'tomatio' ) );
				}
				$entry = Projects::stamp( $listed[ $version ] ?? [ 'version' => $version ] );
				foreach ( [ 'title' => 120, 'date' => 40 ] as $field => $max ) {
					if ( array_key_exists( $field, $input ) ) {
						$entry[ $field ] = TioDay::text( $input[ $field ], $max );
					}
				}
				$listed[ $version ] = array_filter( $entry, static fn ( $v ): bool => $v !== '' );
				$changed[]          = 'planned ' . $version;
			}
		}
		if ( $changed === [] ) {
			return self::invalid( __( 'Give a version to plan (or remove), or running.', 'tomatio' ) );
		}

		uksort( $listed, 'version_compare' );
		$project['releases']   = array_values( $listed );
		$project['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$project['updated_by'] = $caller['by'];
		$stored                = StoreData::write( $artifactId, 'meta', 'project', $project, $caller['store'], 'replace', null, $projectVersion > 0 ? $projectVersion : 0 );
		if ( $stored instanceof WP_Error ) {
			return $stored;
		}
		Change::note( $artifactId, 'project', self::line( $caller, 'release', null, implode( '; ', $changed ), $input['reason'] ?? '' ), $caller['store'] );
		$rolled = Releases::roll( $artifactId );

		[ $project ] = self::project( $artifactId );
		$plan        = Releases::plan( $project, StoreData::list( $artifactId, 'items' ), Releases::choices( $artifactId ) );

		return [
			'running'  => $plan['running'],
			'next'     => $plan['next'],
			'upcoming' => $plan['upcoming'],
			'releases' => $project['releases'],
			'rolled'   => $rolled,
		];
	}

	/**
	 * tomatio-project-rank: move an item above or below another, to the top
	 * or bottom of a band, or move a divider. People only (see the class).
	 * An Inbox item that is ranked is accepted (status ready).
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function rank( int $artifactId, array $input ) {
		$caller = self::caller( $artifactId );
		if ( ! $caller['people'] ) {
			return self::peopleOnly( __( 'rank', 'tomatio' ) );
		}
		[ $project, $projectVersion ] = self::project( $artifactId );
		$reason                       = $input['reason'] ?? '';

		if ( isset( $input['divider'] ) && is_string( $input['divider'] ) && $input['divider'] !== '' ) {
			return self::moveDivider( $artifactId, $input, $project, $projectVersion, $caller );
		}

		$doc = isset( $input['item'] ) && is_string( $input['item'] ) ? self::find( $artifactId, $input['item'] ) : null;
		if ( $doc === null ) {
			return self::notFound( (string) ( $input['item'] ?? '' ) );
		}
		$value  = is_array( $doc['value'] ) ? $doc['value'] : [];
		$status = (string) ( $value['status'] ?? 'inbox' );
		if ( ! in_array( $status, array_merge( Item::RANKED, [ 'inbox' ] ), true ) ) {
			/* translators: %s: the item's status. */
			return self::invalid( sprintf( __( 'A %s item is not on the ranked list. Set its status first (tomatio-project-status).', 'tomatio' ), $status ) );
		}

		$rank = self::placement( $artifactId, (string) $doc['key'], $input, $project );
		if ( $rank instanceof WP_Error ) {
			return $rank;
		}

		$old             = is_string( $value['rank'] ?? null ) ? $value['rank'] : null;
		$from            = $status === 'inbox' ? 'inbox' : Rank::band( $old, $project );
		$value['rank']   = $rank;
		$value['status'] = $status === 'inbox' ? 'ready' : $status;
		unset( $value['placed'] );

		$done = Change::write( $artifactId, (string) $doc['key'], $value, (int) $doc['version'], self::line( $caller, 'rank', $from, Rank::band( $rank, $project ), $reason ), $caller['store'] );

		return $done instanceof WP_Error ? $done : self::answer( $done ) + [ 'band' => Rank::band( $rank, $project ) ];
	}

	/**
	 * tomatio-project-comment: a comment on an item ("project" for the whole
	 * backlog), optionally to a person or agent, or a reply.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function comment( int $artifactId, array $input ) {
		$caller = self::caller( $artifactId );
		$text   = TioDay::text( $input['text'] ?? '', 4000, true );
		if ( $text === '' ) {
			return self::invalid( __( 'A comment needs text.', 'tomatio' ) );
		}

		$ref = isset( $input['item'] ) && is_string( $input['item'] ) ? trim( $input['item'] ) : '';
		$on  = 'project';
		if ( $ref !== '' && strtolower( $ref ) !== 'project' ) {
			$doc = self::find( $artifactId, $ref );
			if ( $doc === null ) {
				return self::notFound( $ref );
			}
			$on = is_array( $doc['value'] ) && is_string( $doc['value']['id'] ?? null ) ? $doc['value']['id'] : (string) $doc['key'];
		}

		$value = [
			'on'   => $on,
			'text' => $text,
			'by'   => $caller['for'] !== '' ? $caller['by'] . ' for ' . $caller['for'] : $caller['by'],
			'at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
		];
		$to = TioDay::text( $input['to'] ?? '', 120 );
		if ( $to !== '' ) {
			$value['to'] = $to;
		}
		$replyTo = isset( $input['reply_to'] ) && is_string( $input['reply_to'] ) ? StoreData::normalizeKey( $input['reply_to'] ) : null;
		if ( $replyTo !== null ) {
			if ( StoreData::get( $artifactId, 'comments', $replyTo ) === null ) {
				return self::invalid( __( 'reply_to is the key of a comment on this backlog.', 'tomatio' ) );
			}
			$value['reply_to'] = $replyTo;
		}

		$stored = StoreData::write( $artifactId, 'comments', StoreData::generateKey(), $value, $caller['store'], 'create', null, 0 );
		if ( $stored instanceof WP_Error ) {
			return $stored;
		}
		Change::note( $artifactId, $on, self::line( $caller, 'comment', null, (string) $stored['key'], '' ), $caller['store'] );
		if ( isset( $doc ) && ! $caller['people'] ) {
			// #987: an agent's question on its item reaches whoever assigned it.
			Assignees::forward( $artifactId, $doc, (int) $caller['writer'], (array) $stored['value'] );
		}

		return [ 'key' => (string) $stored['key'], 'value' => $stored['value'], 'version' => (int) $stored['version'] ];
	}

	/**
	 * tomatio-project-status: set an item's status. building and shipped
	 * are open to agents with a link (a branch, PR or release); ready,
	 * declined (with a reason), parked, archived and back to inbox need a
	 * person.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function status( int $artifactId, array $input ) {
		$caller = self::caller( $artifactId );
		$status = isset( $input['status'] ) && is_string( $input['status'] ) ? $input['status'] : '';
		if ( ! in_array( $status, Item::STATUSES, true ) ) {
			/* translators: %s: the allowed statuses. */
			return self::invalid( sprintf( __( 'status is one of: %s.', 'tomatio' ), implode( ', ', Item::STATUSES ) ) );
		}
		if ( in_array( $status, Item::PEOPLE_ONLY, true ) && ! $caller['people'] ) {
			return self::peopleOnly( $status );
		}

		$doc = isset( $input['item'] ) && is_string( $input['item'] ) ? self::find( $artifactId, $input['item'] ) : null;
		if ( $doc === null ) {
			return self::notFound( (string) ( $input['item'] ?? '' ) );
		}
		$value  = is_array( $doc['value'] ) ? $doc['value'] : [];
		$before = (string) ( $value['status'] ?? 'inbox' );
		$reason = TioDay::text( $input['reason'] ?? '', 500 );
		if ( $before === 'idea' || $status === 'idea' ) {
			return self::invalid( __( 'An idea is logged with project-log (idea: true) and promoted or dropped by a curator with project-idea, not by its status.', 'tomatio' ) );
		}

		$link = $input['link'] ?? null;
		if ( is_string( $link ) && str_starts_with( $link, 'https://' ) ) {
			$link = [ [ 'url' => $link ] ];
		} elseif ( is_array( $link ) && ! array_is_list( $link ) ) {
			$link = [ $link ];
		}
		$links = TioDay::links( $link ?? ( $input['links'] ?? [] ) );
		if ( $links instanceof WP_Error ) {
			return $links;
		}
		// #987: the agent assigned a current item closes it, link or not.
		$assignee = ! $caller['people'] && $status === 'shipped' && Assignees::mayActOn( $artifactId, (int) $caller['writer'], (string) $input['item'] );
		if ( in_array( $status, [ 'building', 'shipped' ], true ) && ! $caller['people'] && $links === [] && ! $assignee ) {
			return self::invalid( __( 'An agent sets building or shipped with a link: the branch, pull request or release.', 'tomatio' ) );
		}
		if ( $status === 'declined' && $reason === '' ) {
			return self::invalid( __( 'Declining needs a reason, so it stays findable.', 'tomatio' ) );
		}

		$value['status'] = $status;
		$have            = array_column( is_array( $value['links'] ?? null ) ? $value['links'] : [], 'url' );
		foreach ( $links as $link ) {
			if ( ! in_array( $link['url'], $have, true ) && count( $value['links'] ?? [] ) < TioDay::MAX_LINKS ) {
				$value['links'][] = $link;
			}
		}
		if ( in_array( $status, [ 'declined', 'parked', 'archived', 'shipped', 'inbox' ], true ) ) {
			// Off the ranked list; the rank comes back when someone ranks it again.
			$value['rank'] = null;
		} elseif ( ! Rank::isRank( $value['rank'] ?? null ) ) {
			[ $project ] = self::project( $artifactId );
			// Accepted or started without a place: the top of Later.
			$value['rank'] = self::placement( $artifactId, (string) $doc['key'], [ 'band' => 'later', 'position' => 'top' ], $project );
			$value['rank'] = is_string( $value['rank'] ) ? $value['rank'] : null;
		}
		if ( in_array( $status, [ 'declined', 'parked', 'ready' ], true ) && $caller['people'] ) {
			$value['decided'] = [ 'by' => $caller['for'] !== '' ? $caller['for'] : $caller['by'], 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'reason' => $reason ];
		}

		$done = Change::write( $artifactId, (string) $doc['key'], $value, (int) $doc['version'], self::line( $caller, $status === 'declined' ? 'declined' : 'status', $before, $status, $reason ), $caller['store'] );

		return $done instanceof WP_Error ? $done : self::answer( $done );
	}

	// -----------------------------------------------------------------
	// Ranks
	// -----------------------------------------------------------------

	/**
	 * Where an item goes: above or below another item, or the top or bottom
	 * of a band. The neighbours include the dividers, so "above B7" never
	 * jumps a band.
	 *
	 * @param array<string, mixed> $input   {above?, below?, band?, position?}
	 * @param array<string, mixed> $project
	 * @return string|WP_Error
	 */
	public static function placement( int $artifactId, string $movingKey, array $input, array $project ) {
		$points = self::points( $artifactId, $movingKey, $project );

		foreach ( [ 'above', 'below' ] as $side ) {
			if ( ! isset( $input[ $side ] ) || ! is_string( $input[ $side ] ) || trim( $input[ $side ] ) === '' ) {
				continue;
			}
			$other = self::find( $artifactId, $input[ $side ] );
			$at    = $other !== null && is_array( $other['value'] ) && Rank::isRank( $other['value']['rank'] ?? null ) && (string) $other['key'] !== $movingKey ? (string) $other['value']['rank'] : null;
			if ( $at === null ) {
				/* translators: %s: the item reference. */
				return self::invalid( sprintf( __( '%s is not a ranked item to move next to.', 'tomatio' ), $input[ $side ] ) );
			}
			$i = array_search( $at, $points, true );
			$i = $i === false ? 0 : (int) $i;
			$rank = $side === 'above' ? Rank::between( $points[ $i - 1 ] ?? null, $at ) : Rank::between( $at, $points[ $i + 1 ] ?? null );

			return $rank ?? self::invalid( __( 'No room there. Try again.', 'tomatio' ) );
		}

		$band = isset( $input['band'] ) && is_string( $input['band'] ) ? $input['band'] : 'later';
		if ( ! in_array( $band, Rank::BANDS, true ) ) {
			return self::invalid( __( 'band is now, next or later.', 'tomatio' ) );
		}
		[ $lo, $hi ] = Rank::bounds( $band, $project );
		$inside      = array_values( array_filter( $points, static fn ( string $p ): bool => ( $lo === null || strcmp( $p, $lo ) > 0 ) && ( $hi === null || strcmp( $p, $hi ) < 0 ) ) );
		$rank        = ( $input['position'] ?? 'bottom' ) === 'top'
			? Rank::between( $lo, $inside[0] ?? $hi )
			: Rank::between( $inside !== [] ? $inside[ count( $inside ) - 1 ] : $lo, $hi );

		return $rank ?? self::invalid( __( 'No room there. Try again.', 'tomatio' ) );
	}

	/**
	 * Every rank on the list (both dividers included), sorted, without the
	 * moving item's.
	 *
	 * @param array<string, mixed> $project
	 * @return array<int, string>
	 */
	private static function points( int $artifactId, string $movingKey, array $project ): array {
		$points = Rank::cuts( $project );
		foreach ( StoreData::list( $artifactId, 'items' ) as $doc ) {
			$v = is_array( $doc['value'] ) ? $doc['value'] : [];
			if ( (string) $doc['key'] !== $movingKey && in_array( $v['status'] ?? '', Item::RANKED, true ) && Rank::isRank( $v['rank'] ?? null ) ) {
				$points[] = (string) $v['rank'];
			}
		}
		$points = array_values( array_unique( $points ) );
		sort( $points, SORT_STRING );

		return $points;
	}

	/**
	 * Move the Now or Next divider above or below an item. Moving a
	 * divider is a rank change, logged like any other.
	 *
	 * @param array<string, mixed>                                                                         $input
	 * @param array<string, mixed>                                                                         $project
	 * @param array{writer:int, person:int, by:string, for:string, people:bool, store:array<string, mixed>} $caller
	 * @return array<string, mixed>|WP_Error
	 */
	private static function moveDivider( int $artifactId, array $input, array $project, int $version, array $caller ) {
		$which = (string) $input['divider'];
		if ( ! in_array( $which, [ 'now', 'next' ], true ) ) {
			return self::invalid( __( 'divider is now (between Now and Next) or next (between Next and Later).', 'tomatio' ) );
		}
		if ( ! isset( $input['above'] ) && ! isset( $input['below'] ) ) {
			return self::invalid( __( 'Say where the divider goes: above or below an item.', 'tomatio' ) );
		}
		$field = $which . '_cut';
		$old   = Rank::cuts( $project )[ $which === 'now' ? 0 : 1 ];
		$cut   = self::placement( $artifactId, '', array_diff_key( $input, [ 'band' => 1 ] ), $project );
		if ( $cut instanceof WP_Error ) {
			return $cut;
		}

		$project[ $field ] = $cut;
		[ $now, $next ]    = [ (string) ( $project['now_cut'] ?? Rank::NOW_CUT ), (string) ( $project['next_cut'] ?? Rank::NEXT_CUT ) ];
		if ( strcmp( $now, $next ) >= 0 ) {
			return self::invalid( __( 'The Now divider must stay above the Next divider.', 'tomatio' ) );
		}
		$project['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$project['updated_by'] = $caller['by'];

		$stored = StoreData::write( $artifactId, 'meta', 'project', $project, $caller['store'], 'replace', null, $version > 0 ? $version : 0 );
		if ( $stored instanceof WP_Error ) {
			return $stored;
		}
		Change::note( $artifactId, 'project', self::line( $caller, 'cut', $which . ':' . $old, $which . ':' . $cut, $input['reason'] ?? '' ), $caller['store'] );

		return [ 'project' => [ 'now_cut' => $project['now_cut'], 'next_cut' => $project['next_cut'] ], 'version' => (int) $stored['version'] ];
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * @param array{writer:int, person:int, by:string, for:string, people:bool, store:array<string, mixed>} $caller
	 * @param mixed                                                                                          $reason
	 * @return array<string, mixed>
	 */
	private static function line( array $caller, string $what, ?string $from, ?string $to, $reason ): array {
		return array_filter(
			[
				'what'   => $what,
				'by'     => $caller['by'],
				'for'    => $caller['for'],
				'from'   => $from,
				'to'     => $to,
				'reason' => is_string( $reason ) ? $reason : '',
			],
			static fn ( $x ): bool => $x !== null && $x !== ''
		);
	}

	/** A free item key made from a title: "dark-mode", then "dark-mode-2". */
	public static function freeKey( int $artifactId, string $title ): string {
		$base = trim( substr( trim( (string) preg_replace( '/[^a-z0-9-]+/', '-', sanitize_title( $title ) ), '-' ), 0, 48 ), '-' );
		$base = preg_match( '/^[a-z0-9][a-z0-9-]*$/', $base ) === 1 ? $base : 'item';
		$key  = $base;
		for ( $n = 2; StoreData::get( $artifactId, 'items', $key ) !== null && $n < 1000; $n++ ) {
			$key = $base . '-' . $n;
		}

		return $key;
	}

	/**
	 * @param array{item: array<string, mixed>, log: array<string, mixed>} $done
	 * @return array<string, mixed>
	 */
	private static function answer( array $done ): array {
		$value = is_array( $done['item']['value'] ) ? $done['item']['value'] : [];

		return [
			'key'     => (string) $done['item']['key'],
			'id'      => $value['id'] ?? null,
			'value'   => $value,
			'version' => (int) $done['item']['version'],
			'log'     => (string) $done['log']['key'],
		];
	}

	private static function notFound( string $ref ): WP_Error {
		return new WP_Error(
			'ab_backlog_item_not_found',
			/* translators: %s: what was asked for. */
			sprintf( __( 'No item on this backlog is %s. Use its B ID (B12), its key, or a tool\'s ID it carries (#807, AB-84, T151); tomatio-project-get lists them.', 'tomatio' ), $ref ),
			[ 'status' => 404 ]
		);
	}

	private static function peopleOnly( string $what ): WP_Error {
		return new WP_Error(
			'ab_backlog_people_only',
			/* translators: %s: the change, such as "rank" or "declined". */
			sprintf( __( 'Only a person can %s (agents propose, people rank). Propose it with tomatio-project-comment, or act for the person who asked.', 'tomatio' ), $what ),
			[ 'status' => 403 ]
		);
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'ab_backlog_invalid', $message, [ 'status' => 400 ] );
	}
}
