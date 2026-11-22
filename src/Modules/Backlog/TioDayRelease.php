<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\DataStore\TioDayLinks;
use WP_Error;

/**
 * The release list on a TíoDay follows the Magic Backlog release plan.
 *
 * On 22 Nov merged work was on TíoDay's hand-kept release list and missing
 * from the board's release (or the other way round): two lists, kept by
 * different sessions, drifted. Now the board is the one source:
 *
 * - every item the board puts in a release (Releases::setOf, "next"
 *   resolved by Releases::plan) is an item of that version's `release`
 *   document on the TíoDay, with its pull request link (Progress::PR_FILTER)
 *   or its board link, and its state: in dev-wip once its pull request
 *   merged (or it is shipped or marked done), in progress while a pull
 *   request is open or it is being built, held when parked, else proposed;
 * - a release the board ships (shipped_at on meta/project.releases) flips
 *   the TíoDay's planning release to released, stamping its gate once;
 * - board items carry `board` and `item`, so the next pass rebuilds them:
 *   an item moved to another release on the board leaves this one.
 *
 * Items written by hand (tomatio-tioday-release, the Merge log) stay, as do
 * the note and the gates: a brain without a board, a merge with no board
 * item, a gate. When a hand-written item names the same work as a board
 * item (the same issue or pull request, or in the same release the same
 * title) the board's wins and the hand-written one goes; its `for` is kept,
 * and a merge it saw counts as landed until the board's next read of the
 * pull request catches up.
 *
 * A TíoDay follows a board when it keeps a release list (it has a release
 * document) and its person can read exactly one board with a release plan;
 * of several, the one that follows the plugin version. The filter
 * `tomatio/tioday/release_board` (board id, TíoDay id) can choose another,
 * or 0 for none. Runs on the hourly Progress pass, at the end of a request
 * that changed a board's items, verdicts or plan, and after every
 * tomatio-tioday-release write. Needs Magic Backlog and TíoDay both on.
 */
final class TioDayRelease {

	public const FILTER = 'tomatio/tioday/release_board';

	/** Who a gate the board stamped is by. */
	public const BY = 'Magic Backlog';

	/** Item statuses that are never release work. */
	private const SKIP = [ 'idea', 'declined', 'archived' ];

	private const MAX_TIODAYS = 500;

	/** @var array<int, bool> Boards changed during this request. */
	private static array $dirty = [];

	public static function register(): void {
		add_action( 'tomatio/store_written', [ self::class, 'written' ], 31, 5 );
		add_action( 'shutdown', [ self::class, 'flush' ], 31 );
	}

	/**
	 * tomatio/store_written: a change to a board's items, verdicts or plan
	 * brings its TíoDays' release lists up to date at the end of the request.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $by
	 */
	public static function written( int $artifactId, string $collection, string $key, $value, array $by ): void {
		unset( $value, $by );
		if ( ( in_array( $collection, [ 'items', 'verdicts' ], true ) || ( $collection === 'meta' && $key === 'project' ) ) && Backlog::isBacklog( $artifactId ) ) {
			self::$dirty[ $artifactId ] = true;
		}
	}

	/** At the end of a request: sync the TíoDays of the boards that changed. */
	public static function flush(): void {
		$boards      = array_keys( self::$dirty );
		self::$dirty = [];
		if ( $boards !== [] ) {
			self::run( array_map( 'intval', $boards ) );
		}
	}

	/**
	 * One pass: every TíoDay that follows a board (one of $boards when given).
	 *
	 * @param array<int, int>|null $boards
	 * @return array<int, array<int, string>> TíoDay id => the versions written.
	 */
	public static function run( ?array $boards = null ): array {
		$out = [];
		if ( ! PersonDay::both() ) {
			return $out;
		}
		foreach ( TioDay::candidates( [ [ 'key' => HomeWorkspace::META_OWNER, 'compare' => 'EXISTS' ] ], self::MAX_TIODAYS ) as $post ) {
			$tioday = (int) $post->ID;
			$board  = self::follows( $tioday );
			if ( $board <= 0 || ( $boards !== null && ! in_array( $board, $boards, true ) ) ) {
				continue;
			}
			$written = self::sync( $tioday, $board );
			if ( $written !== [] ) {
				$out[ $tioday ] = $written;
			}
		}

		return $out;
	}

	/**
	 * The board whose release plan TíoDay $tioday follows; 0 for none.
	 */
	public static function follows( int $tioday ): int {
		if ( $tioday <= 0 || ! PersonDay::both() ) {
			return 0;
		}
		$owner = (int) get_post_meta( $tioday, HomeWorkspace::META_OWNER, true );
		if ( $owner <= 0 || StoreData::list( $tioday, 'release' ) === [] ) {
			return 0;
		}
		$planned = [];
		$plugin  = [];
		foreach ( Backlog::all() as $board ) {
			if ( ! Backlog::canRead( $owner, $board ) ) {
				continue;
			}
			[ $project ] = Project::project( $board );
			if ( Releases::running( $project ) === '' && Releases::listed( $project ) === [] ) {
				continue;
			}
			$planned[] = $board;
			if ( ( $project['running'] ?? '' ) === Releases::PLUGIN ) {
				$plugin[] = $board;
			}
		}
		$chosen = count( $planned ) === 1 ? $planned[0] : ( count( $plugin ) === 1 ? $plugin[0] : 0 );
		$chosen = (int) apply_filters( self::FILTER, $chosen, $tioday );

		return $chosen > 0 && Backlog::isBacklog( $chosen ) ? $chosen : 0;
	}

	/**
	 * The version merged work goes into on the board TíoDay $tioday follows
	 * (the patch after its running version, as Releases::fillNextPatch puts
	 * it); null when it follows none, or that board has no such patch.
	 */
	public static function nextFor( int $tioday ): ?string {
		$board = self::follows( $tioday );
		if ( $board <= 0 ) {
			return null;
		}
		[ $project ] = Project::project( $board );
		$patch       = Releases::nextPatch( Releases::running( $project ) );

		return $patch !== '' ? $patch : null;
	}

	/**
	 * The board's release items, by version: [version => [item]], each item
	 * {title, url?, label, state, board, item} plus `refs` (the issues and
	 * pull requests it names) for matching, taken off before it is stored.
	 *
	 * @return array{0: array<string, array<int, array<string, mixed>>>, 1: array<string, string>, 2: array<int, string>} [items by version, shipped_at by version, the releases still to come]
	 */
	public static function fromBoard( int $board ): array {
		[ $project ] = Project::project( $board );
		$items       = StoreData::list( $board, 'items' );
		$choices     = Releases::choices( $board );
		$plan        = Releases::plan( $project, $items, $choices );
		$listed      = Releases::listed( $project );
		$url         = Assignees::url( $board );

		$out = [];
		foreach ( $items as $doc ) {
			$value   = is_array( $doc['value'] ) ? $doc['value'] : [];
			$status  = (string) ( $value['status'] ?? 'inbox' );
			$verdict = (string) ( $choices[ (string) $doc['key'] ]['value']['status'] ?? '' );
			if ( in_array( $status, self::SKIP, true ) || $verdict === 'drop' ) {
				continue;
			}
			$version = Releases::resolve( Releases::setOf( $doc, $choices ), $plan );
			if ( $version === '' || $version === Releases::NEXT || $version === Releases::LATER ) {
				continue;
			}
			$id = is_string( $value['id'] ?? null ) && $value['id'] !== '' ? $value['id'] : (string) $doc['key'];
			$pr = apply_filters( Progress::PR_FILTER, null, $board, $doc );
			$pr = is_array( $pr ) && in_array( $pr['state'] ?? '', [ 'open', 'merged' ], true ) ? $pr : null;

			$prUrl = $pr !== null && is_string( $pr['url'] ?? null ) && str_starts_with( $pr['url'], 'https://' ) ? $pr['url'] : '';
			$num   = $pr !== null && is_int( $pr['number'] ?? null ) ? (int) $pr['number'] : 0;
			$refs  = TioDayLinks::refs( $value['links'] ?? null );
			if ( $prUrl !== '' ) {
				$ref = TioDayLinks::ref( $prUrl );
				if ( $ref !== null && ! in_array( $ref, $refs, true ) ) {
					$refs[] = $ref;
				}
			}

			if ( $status === 'shipped' || $verdict === 'done' || ( $pr !== null && $pr['state'] === 'merged' ) ) {
				$state = 'in dev-wip';
			} elseif ( $pr !== null || $status === 'building' ) {
				$state = 'in progress';
			} elseif ( $status === 'parked' ) {
				$state = 'held';
			} else {
				$state = 'proposed';
			}

			$link = $prUrl !== '' ? $prUrl : ( str_starts_with( $url, 'https://' ) ? $url . '#view=board&item=' . rawurlencode( $id ) : '' );
			$out[ $version ][] = array_filter(
				[
					'title' => TioDay::text( (string) ( $value['title'] ?? $id ), TioDay::MAX_TITLE ),
					'url'   => $link,
					'label' => $num > 0 ? $id . ', #' . $num : $id,
					'state' => $state,
					'board' => $board,
					'item'  => $id,
					'refs'  => $refs,
				],
				static fn ( $v ): bool => $v !== '' && $v !== []
			);
		}
		foreach ( $out as &$list ) {
			usort( $list, static fn ( array $a, array $b ): int => strnatcmp( (string) $a['item'], (string) $b['item'] ) );
		}
		unset( $list );

		$shipped = [];
		foreach ( $listed as $version => $entry ) {
			if ( ! empty( $entry['shipped_at'] ) ) {
				$shipped[ (string) $version ] = (string) $entry['shipped_at'];
			}
		}

		return [ $out, $shipped, $plan['upcoming'] ];
	}

	/**
	 * Bring TíoDay $tioday's release documents in line with board $board.
	 *
	 * @return array<int, string> The versions written.
	 */
	public static function sync( int $tioday, int $board ): array {
		[ $byVersion, $shipped, $upcoming ] = self::fromBoard( $board );
		$key                                = static fn ( string $version ): string => (string) StoreData::normalizeKey( $version );

		// Every issue or pull request a board item names, in any release:
		// a hand-written item naming one of them goes wherever it is.
		$boardRefs = [];
		foreach ( $byVersion as $list ) {
			foreach ( $list as $item ) {
				foreach ( $item['refs'] ?? [] as $ref ) {
					$boardRefs[ $ref ] = true;
				}
				if ( isset( $item['url'] ) ) {
					$boardRefs[ 'url:' . $item['url'] ] = true;
				}
			}
		}

		$docs = [];
		foreach ( StoreData::list( $tioday, 'release' ) as $doc ) {
			if ( is_array( $doc['value'] ) && ! array_is_list( $doc['value'] ) ) {
				$docs[ (string) $doc['key'] ] = $doc;
			}
		}
		// Existing documents, plus a new one for each release still to come
		// that has board items (never one for a release long gone).
		$versions = array_map( 'strval', array_keys( $docs ) );
		foreach ( array_keys( $byVersion ) as $version ) {
			$version = (string) $version;
			if ( in_array( $version, $upcoming, true ) && $key( $version ) !== '' ) {
				$versions[] = $key( $version );
			}
		}
		$keyed = [];
		foreach ( $byVersion as $version => $list ) {
			$keyed[ $key( (string) $version ) ] = $list;
		}
		$shippedKeyed = [];
		foreach ( $shipped as $version => $at ) {
			$shippedKeyed[ $key( (string) $version ) ] = $at;
		}

		$out = [];
		foreach ( array_unique( $versions ) as $version ) {
			$version = (string) $version;
			$have    = $docs[ $version ] ?? null;
			$value   = $have !== null ? $have['value'] : [
				'version' => $version,
				'status'  => 'planning',
				'note'    => '',
				'gates'   => new \stdClass(),
				'items'   => [],
			];
			if ( ( $value['status'] ?? 'planning' ) === 'deployed' ) {
				continue;
			}
			$next = self::merged( $value, $keyed[ $version ] ?? [], $boardRefs, $board, $shippedKeyed[ $version ] ?? '' );
			if ( $have !== null && self::same( $have['value'], $next ) ) {
				continue;
			}
			$stored = StoreData::set( $tioday, 'release', $version, $next, StoreData::anonymousAttribution(), null, $have !== null ? (int) $have['version'] : 0 );
			if ( ! $stored instanceof WP_Error ) {
				$out[] = $version;
			}
		}
		usort( $out, 'version_compare' );

		return $out;
	}

	/**
	 * A release document with its board items rebuilt from $boardItems and
	 * its status flipped to released when the board shipped it.
	 *
	 * @param array<string, mixed>              $value
	 * @param array<int, array<string, mixed>>  $boardItems
	 * @param array<string, bool>               $boardRefs
	 * @return array<string, mixed>
	 */
	private static function merged( array $value, array $boardItems, array $boardRefs, int $board, string $shippedAt ): array {
		$titles = [];
		foreach ( $boardItems as $i => $item ) {
			$titles[ strtolower( (string) $item['title'] ) ] = $i;
		}

		$old  = is_array( $value['items'] ?? null ) ? array_values( $value['items'] ) : [];
		$byId = [];
		foreach ( $boardItems as $i => $item ) {
			$byId[ (string) $item['item'] ] = $i;
		}
		// This board's own items are rebuilt; what a hand-written item
		// carried over to one before (who it is for, a merge seen first)
		// stays with it.
		foreach ( $old as $was ) {
			if ( ! is_array( $was ) || (int) ( $was['board'] ?? 0 ) !== $board || ! isset( $byId[ (string) ( $was['item'] ?? '' ) ] ) ) {
				continue;
			}
			$i = $byId[ (string) $was['item'] ];
			if ( ! isset( $boardItems[ $i ]['for'] ) && is_string( $was['for'] ?? null ) && $was['for'] !== '' ) {
				$boardItems[ $i ]['for'] = $was['for'];
			}
			if ( ! empty( $was['merge_seen'] ) ) {
				$boardItems[ $i ] = self::landed( $boardItems[ $i ] );
			}
		}

		$kept = [];
		foreach ( $old as $hand ) {
			if ( ! is_array( $hand ) || ( (int) ( $hand['board'] ?? 0 ) === $board && isset( $hand['item'] ) ) ) {
				continue;
			}
			$match = null;
			$url   = is_string( $hand['url'] ?? null ) ? $hand['url'] : '';
			$ref   = $url !== '' ? TioDayLinks::ref( $url ) : null;
			foreach ( $boardItems as $i => $item ) {
				if ( ( $url !== '' && ( $item['url'] ?? '' ) === $url ) || ( $ref !== null && in_array( $ref, $item['refs'] ?? [], true ) ) ) {
					$match = $i;
					break;
				}
			}
			if ( $match === null && is_string( $hand['title'] ?? null ) && isset( $titles[ strtolower( $hand['title'] ) ] ) ) {
				$match = $titles[ strtolower( $hand['title'] ) ];
			}
			if ( $match !== null ) {
				// The board wins; who it is for, and a merge seen first, carry over.
				if ( ! isset( $boardItems[ $match ]['for'] ) && is_string( $hand['for'] ?? null ) && $hand['for'] !== '' ) {
					$boardItems[ $match ]['for'] = $hand['for'];
				}
				if ( in_array( $hand['state'] ?? '', [ 'merged', 'in dev-wip' ], true ) ) {
					$boardItems[ $match ] = self::landed( $boardItems[ $match ] );
				}
				continue;
			}
			if ( ( $ref !== null && isset( $boardRefs[ $ref ] ) ) || ( $url !== '' && isset( $boardRefs[ 'url:' . $url ] ) ) ) {
				// The board has this work in another release.
				continue;
			}
			$kept[] = $hand;
		}

		$items = [];
		foreach ( $boardItems as $item ) {
			unset( $item['refs'] );
			$items[] = $item;
		}
		$value['items'] = array_merge( $items, $kept );

		if ( $shippedAt !== '' && ( $value['status'] ?? 'planning' ) === 'planning' ) {
			$value['status'] = 'released';
			$gates           = is_array( $value['gates'] ?? null ) && ! array_is_list( $value['gates'] ) ? $value['gates'] : [];
			if ( ! isset( $gates['released'] ) ) {
				$at                = strtotime( $shippedAt );
				$gates['released'] = [ 'on' => $at !== false ? (string) wp_date( 'Y-m-d', $at ) : TioDay::today(), 'by' => self::BY ];
			}
			$value['gates'] = $gates;
		}
		if ( ! is_array( $value['gates'] ?? null ) || $value['gates'] === [] ) {
			$value['gates'] = new \stdClass();
		}

		return $value;
	}

	/**
	 * A board item a hand-written item (the Merge log's, say) saw merge
	 * before the board read its pull request: in dev-wip, marked so the
	 * next pass keeps it there.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function landed( array $item ): array {
		if ( in_array( $item['state'] ?? '', [ 'proposed', 'in progress' ], true ) ) {
			$item['state']      = 'in dev-wip';
			$item['merge_seen'] = true;
		}

		return $item;
	}

	/**
	 * @param mixed $a
	 * @param mixed $b
	 */
	private static function same( $a, $b ): bool {
		// Through JSON both ways, so an empty gates object and an empty array compare alike.
		return json_decode( (string) wp_json_encode( $a ), true ) === json_decode( (string) wp_json_encode( $b ), true );
	}
}
