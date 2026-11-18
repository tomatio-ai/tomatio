<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\AgentId\TaskTakers;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\DataStore\TioDayDefault;
use AgentBrain\Modules\DataStore\TioDayIds;
use AgentBrain\Modules\Extensions\Switches;
use AgentBrain\Modules\Identity\Capabilities;
use WP_Error;

/**
 * #1000: with Tomagic and TíoDay both on, they split the work. Tomagic is
 * the team's board. TíoDay is one person's day.
 *
 * - A current item (open, in Now or the next release, the same line the
 *   agent loop uses) assigned to a person becomes a linked task on that
 *   person's TíoDay. Until then it stays on Tomagic only.
 * - Closing that task marks the item shipped. Reassigning it, or moving
 *   it out of the current release, closes the task.
 * - An item of kind decision becomes a Decide entry instead of a task.
 *   The board has no Decision kind yet; the path is ready when one is stored.
 * - Agents take work from Tomagic. TíoDay tools refuse who = an agent, and
 *   an open agent task already on a TíoDay moves to a Tomagic item and closes
 *   with a link, once, when both extensions are on.
 * - A comment on the linked task shows on the item, and the other way round.
 *
 * A brain with TíoDay only is unchanged, agent tasks included.
 */
final class PersonDay {

	/** Set once both extensions are on, so the move runs again if Tomagic is turned off and on. */
	public const FLAG = 'agent_brain_person_day_both';

	private static bool $busy = false;

	public static function register(): void {
		add_action( 'tomatio/store_written', [ self::class, 'written' ], 30, 5 );
		add_action( 'init', [ self::class, 'maybeMigrate' ], 34 );
	}

	public static function both(): bool {
		return Switches::enabled( Switches::TIODAY ) && Switches::revealed();
	}

	/** The briefing line when both are on; '' otherwise (TíoDay-only keeps today's agent line). */
	public static function splitLine(): string {
		if ( ! self::both() ) {
			return '';
		}

		return __( 'Team work goes to Tomagic; TíoDay is for a person\'s own tasks and decisions. Agents take their work from Tomagic, so do not set who to an agent on a TíoDay task.', 'tomatio' );
	}

	public static function refusesAgent( string $who ): bool {
		return self::both() && TaskTakers::named( $who ) > 0;
	}

	public static function agentRefusal(): string {
		return __( 'When Tomagic is on, agents take their work from Tomagic, not from a TíoDay task. Assign the item on the board.', 'tomatio' );
	}

	/**
	 * tomatio/store_written.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $by
	 */
	public static function written( int $artifactId, string $collection, string $key, $value, array $by ): void {
		unset( $by );
		if ( self::$busy || ! self::both() || ! is_array( $value ) ) {
			return;
		}

		self::$busy = true;
		try {
			if ( $collection === 'items' && Backlog::isBacklog( $artifactId ) ) {
				self::onItem( $artifactId, $key );
			} elseif ( $collection === 'verdicts' && Backlog::isBacklog( $artifactId ) ) {
				self::onItem( $artifactId, $key );
			} elseif ( $collection === 'meta' && $key === 'project' && Backlog::isBacklog( $artifactId ) ) {
				self::syncBoard( $artifactId );
			} elseif ( $collection === 'tasks' && TioDayIds::isTioDay( $artifactId ) ) {
				self::onTask( $artifactId, $key, $value );
			} elseif ( $collection === 'comments' ) {
				self::onComment( $artifactId, $value );
			}
		} finally {
			self::$busy = false;
		}
	}

	/** Once both are on: move open agent tasks onto Tomagic, then sync current assignments. */
	public static function maybeMigrate(): void {
		$on  = self::both();
		$was = get_option( self::FLAG ) === '1';
		if ( ! $on ) {
			if ( $was ) {
				update_option( self::FLAG, '0', false );
			}

			return;
		}
		if ( $was || self::$busy ) {
			return;
		}

		update_option( self::FLAG, '1', false );
		self::$busy = true;
		try {
			self::moveAgentTasks();
			foreach ( Backlog::all() as $board ) {
				self::syncBoard( $board );
			}
		} finally {
			self::$busy = false;
		}
	}

	private static function syncBoard( int $board ): void {
		foreach ( StoreData::list( $board, 'items' ) as $doc ) {
			self::onItem( $board, (string) $doc['key'] );
		}
	}

	private static function onItem( int $board, string $key ): void {
		$doc = StoreData::get( $board, 'items', $key );
		if ( $doc === null || ! is_array( $doc['value'] ) ) {
			return;
		}
		$value = $doc['value'];
		if ( ( $value['status'] ?? '' ) === 'idea' ) {
			return;
		}
		$owner = is_string( $value['owner'] ?? null ) ? $value['owner'] : '';
		$agent = $owner !== '' ? TaskTakers::named( $owner ) : 0;
		if ( $agent > 0 ) {
			self::closeLinked( $board, $value, 'reassigned' );

			return;
		}
		$person = self::personId( $board, $owner );
		$bid    = is_string( $value['id'] ?? null ) && $value['id'] !== '' ? $value['id'] : '';
		if ( $person <= 0 || $bid === '' || ! self::current( $board, $doc ) ) {
			self::closeLinked( $board, $value, $person <= 0 ? 'reassigned' : 'release' );

			return;
		}
		if ( ( $value['kind'] ?? '' ) === 'decision' ) {
			self::ensureDecision( $board, $person, $bid, $value );

			return;
		}
		self::ensureTask( $board, $key, $person, $bid, $value, (int) $doc['version'] );
	}

	/**
	 * @param array<string, mixed> $doc
	 */
	private static function current( int $board, array $doc ): bool {
		$items       = StoreData::list( $board, 'items' );
		[ $project ] = Project::project( $board );
		$choices     = Releases::choices( $board );

		return Assignees::isCurrent( $doc, $project, Releases::plan( $project, $items, $choices ), $choices );
	}

	private static function personId( int $board, string $owner ): int {
		if ( $owner === '' ) {
			return 0;
		}
		foreach ( Assignees::people( $board ) as $person ) {
			if ( strcasecmp( $person['name'], $owner ) !== 0 && strcasecmp( $person['login'], $owner ) !== 0 ) {
				continue;
			}
			$user = get_user_by( 'login', $person['login'] );

			return $user instanceof \WP_User && ! Capabilities::isPureAgent( (int) $user->ID ) ? (int) $user->ID : 0;
		}

		return 0;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function ensureTask( int $board, string $itemKey, int $person, string $bid, array $item, int $version ): void {
		$tioday = self::dayOf( $person );
		if ( $tioday <= 0 ) {
			return;
		}
		$have = self::openTask( $tioday, $bid );
		if ( $have !== null ) {
			self::remember( $board, $itemKey, $item, $version, (string) ( $have['value']['id'] ?? '' ) );

			return;
		}
		$closed = self::linkedTask( $tioday, $bid );
		if ( $closed !== null && ( $closed['value']['status'] ?? '' ) === 'done' && empty( $closed['value']['flow'] ) ) {
			self::ship( $board, $itemKey, $item, $version );

			return;
		}

		$slug  = self::freeSlug( $tioday, $itemKey );
		$url   = Assignees::url( $board );
		$links = str_starts_with( $url, 'https://' ) ? [ [ 'label' => 'Tomagic', 'url' => $url . '#view=board&item=' . rawurlencode( $bid ) ] ] : [];
		$kind  = in_array( (string) ( $item['kind'] ?? '' ), [ 'feature', 'bug', 'chore', 'epic' ], true ) ? 'feature' : '';
		$task  = [
			'title'   => TioDay::text( (string) ( $item['title'] ?? $bid ), TioDay::MAX_TITLE ),
			'why'     => TioDay::text( (string) ( $item['detail'] ?? '' ), TioDay::MAX_TEXT, true ),
			'how'     => TioDay::text( sprintf( __( 'From Tomagic %s. Closing this marks it done there.', 'tomatio' ), $bid ), TioDay::MAX_TEXT, true ),
			'links'   => $links,
			'group'   => 'now',
			'who'     => TioDay::name( $person ),
			'status'  => 'open',
			'added'   => TioDay::text( gmdate( 'Y-m-d' ), 10 ),
			'done_on' => null,
			'order'   => 1,
			'item'    => $bid,
			'board'   => $board,
		];
		if ( $kind !== '' ) {
			$task['kind'] = $kind;
		}
		$stored = StoreData::set( $tioday, 'tasks', $slug, $task, StoreData::anonymousAttribution(), null, 0 );
		if ( $stored instanceof WP_Error ) {
			return;
		}
		$saved = StoreData::get( $tioday, 'tasks', $slug );
		$id    = is_array( $saved['value'] ?? null ) ? (string) ( $saved['value']['id'] ?? '' ) : '';
		self::remember( $board, $itemKey, $item, $version, $id );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function remember( int $board, string $key, array $item, int $version, string $taskId ): void {
		if ( $taskId === '' || ( $item['tioday'] ?? '' ) === $taskId ) {
			return;
		}
		$item['tioday'] = $taskId;
		$fresh          = StoreData::get( $board, 'items', $key );
		$version        = $fresh !== null ? (int) $fresh['version'] : $version;
		if ( $fresh !== null && is_array( $fresh['value'] ) ) {
			$item = $fresh['value'];
			$item['tioday'] = $taskId;
		}
		Change::write(
			$board,
			$key,
			$item,
			$version,
			[ 'what' => 'linked', 'by' => 'Tomagic', 'to' => $taskId, 'reason' => 'On their TíoDay' ],
			StoreData::anonymousAttribution()
		);
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function ensureDecision( int $board, int $person, string $bid, array $item ): void {
		$tioday = self::dayOf( $person );
		if ( $tioday <= 0 || self::linkedDecision( $tioday, $bid ) !== null ) {
			return;
		}
		$url   = Assignees::url( $board );
		$value = [
			'title'  => TioDay::text( (string) ( $item['title'] ?? $bid ), TioDay::MAX_TITLE ),
			'detail' => TioDay::text( (string) ( $item['detail'] ?? '' ), TioDay::MAX_TEXT, true ),
			'by'     => TioDay::name( $person ),
			'date'   => gmdate( 'Y-m-d' ),
			'item'   => $bid,
			'board'  => $board,
		];
		if ( str_starts_with( $url, 'https://' ) ) {
			$value['links'] = [ [ 'label' => 'Tomagic', 'url' => $url . '#view=board&item=' . rawurlencode( $bid ) ] ];
		}
		StoreData::set( $tioday, 'decisions', gmdate( 'Y-m-d' ) . '-tm-' . strtolower( $bid ), $value, StoreData::anonymousAttribution(), null, 0 );
	}

	private static function dayOf( int $person ): int {
		$id = TioDay::forPerson( $person );

		return $id > 0 ? $id : TioDayDefault::ensure( $person );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function closeLinked( int $board, array $item, string $why ): void {
		$bid = is_string( $item['tioday'] ?? null ) ? $item['tioday'] : '';
		if ( $bid === '' ) {
			return;
		}
		$owner = is_string( $item['owner'] ?? null ) ? $item['owner'] : '';
		$person = self::personId( $board, $owner );
		// The task lives on the person's day it was made for. A reassignment
		// has a new owner, so also search every TíoDay that names this item.
		$days = $person > 0 ? [ self::dayOf( $person ) ] : [];
		foreach ( self::daysWith( $bid ) as $day ) {
			$days[] = $day;
		}
		foreach ( array_unique( array_filter( $days ) ) as $tioday ) {
			$task = self::openTask( $tioday, is_string( $item['id'] ?? null ) ? $item['id'] : '' );
			if ( $task === null ) {
				continue;
			}
			self::closeTask( $tioday, $task, $why );
		}
	}

	/**
	 * @return array<int, int>
	 */
	private static function daysWith( string $taskId ): array {
		$out = [];
		if ( $taskId === '' ) {
			return $out;
		}
		foreach ( \get_posts( [ 'post_type' => Cpts::ARTIFACT_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 50, 'meta_key' => HomeWorkspace::META_OWNER ] ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery -- one scan when an item leaves a person's day.
			$id = (int) $id;
			if ( ! TioDayIds::isTioDay( $id ) ) {
				continue;
			}
			foreach ( StoreData::list( $id, 'tasks' ) as $doc ) {
				$v = is_array( $doc['value'] ) ? $doc['value'] : [];
				if ( ( $v['id'] ?? '' ) === $taskId && ( $v['status'] ?? '' ) !== 'done' ) {
					$out[] = $id;
				}
			}
		}

		return $out;
	}

	/**
	 * @param array{key: string, value: array<string, mixed>, version: int} $task
	 */
	private static function closeTask( int $tioday, array $task, string $why ): void {
		$value             = $task['value'];
		$value['status']   = 'done';
		$value['done_on']  = gmdate( 'Y-m-d' );
		$value['done_at']  = gmdate( 'Y-m-d\TH:i:s\Z' );
		$value['done_by']  = 'Tomagic';
		$value['flow']     = $why;
		$note              = $why === 'release'
			? __( 'Closed: the item left the current release, so it stays on Tomagic only.', 'tomatio' )
			: __( 'Closed: the item is no longer assigned to this person.', 'tomatio' );
		StoreData::set( $tioday, 'tasks', $task['key'], $value, StoreData::anonymousAttribution(), null, $task['version'] );
		self::note( $tioday, 't:' . $task['key'], $note, 'Tomagic' );
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private static function onTask( int $tioday, string $key, array $value ): void {
		if ( ( $value['status'] ?? '' ) !== 'done' ) {
			if ( is_string( $value['who'] ?? null ) && self::refusesAgent( $value['who'] ) && empty( $value['flow'] ) ) {
				$doc = StoreData::get( $tioday, 'tasks', $key );
				if ( $doc !== null ) {
					self::moveAgentTask( $tioday, $doc );
				}
			}

			return;
		}
		if ( ! empty( $value['flow'] ) || ( ! is_int( $value['board'] ?? null ) && ! is_numeric( $value['board'] ?? null ) ) ) {
			return;
		}
		$board = (int) $value['board'];
		$bid   = is_string( $value['item'] ?? null ) ? $value['item'] : '';
		if ( $board <= 0 || $bid === '' ) {
			return;
		}
		$doc = Project::find( $board, $bid );
		if ( $doc === null || ! is_array( $doc['value'] ) ) {
			return;
		}
		self::ship( $board, (string) $doc['key'], $doc['value'], (int) $doc['version'] );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function ship( int $board, string $key, array $item, int $version ): void {
		if ( in_array( (string) ( $item['status'] ?? 'inbox' ), [ 'idea', 'shipped', 'declined', 'parked', 'archived' ], true ) ) {
			return;
		}
		$before          = (string) ( $item['status'] ?? 'inbox' );
		$item['status']  = 'shipped';
		$item['rank']    = null;
		$fresh           = StoreData::get( $board, 'items', $key );
		if ( $fresh !== null && is_array( $fresh['value'] ) ) {
			$version = (int) $fresh['version'];
			$item    = $fresh['value'];
			if ( in_array( (string) ( $item['status'] ?? '' ), [ 'shipped', 'declined', 'parked', 'archived', 'idea' ], true ) ) {
				return;
			}
			$before         = (string) ( $item['status'] ?? 'inbox' );
			$item['status'] = 'shipped';
			$item['rank']   = null;
		}
		Change::write(
			$board,
			$key,
			$item,
			$version,
			[ 'what' => 'status', 'by' => 'Tomagic', 'from' => $before, 'to' => 'shipped', 'reason' => 'Closed on their TíoDay' ],
			StoreData::anonymousAttribution()
		);
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private static function onComment( int $artifactId, array $value ): void {
		if ( ! empty( $value['mirror'] ) || ! is_string( $value['text'] ?? null ) || $value['text'] === '' ) {
			return;
		}
		$on = is_string( $value['on'] ?? null ) ? $value['on'] : '';
		if ( TioDayIds::isTioDay( $artifactId ) ) {
			self::mirrorToItem( $artifactId, $on, $value );

			return;
		}
		if ( Backlog::isBacklog( $artifactId ) && $on !== '' && $on !== 'project' ) {
			self::mirrorToTask( $artifactId, $on, $value );
		}
	}

	/**
	 * @param array<string, mixed> $comment
	 */
	private static function mirrorToItem( int $tioday, string $on, array $comment ): void {
		$key = str_starts_with( $on, 't:' ) ? substr( $on, 2 ) : '';
		if ( $key === '' ) {
			return;
		}
		$task = StoreData::get( $tioday, 'tasks', $key );
		if ( $task === null || ! is_array( $task['value'] ) ) {
			return;
		}
		$board = (int) ( $task['value']['board'] ?? 0 );
		$bid   = is_string( $task['value']['item'] ?? null ) ? $task['value']['item'] : '';
		if ( $board <= 0 || $bid === '' ) {
			return;
		}
		self::note( $board, $bid, (string) $comment['text'], (string) ( $comment['by'] ?? '' ), 'tioday' );
	}

	/**
	 * @param array<string, mixed> $comment
	 */
	private static function mirrorToTask( int $board, string $bid, array $comment ): void {
		$doc = Project::find( $board, $bid );
		if ( $doc === null || ! is_array( $doc['value'] ) ) {
			return;
		}
		$owner  = is_string( $doc['value']['owner'] ?? null ) ? $doc['value']['owner'] : '';
		$person = self::personId( $board, $owner );
		if ( $person <= 0 ) {
			return;
		}
		$task = self::openTask( self::dayOf( $person ), is_string( $doc['value']['id'] ?? null ) ? $doc['value']['id'] : $bid );
		if ( $task === null ) {
			return;
		}
		self::note( self::dayOf( $person ), 't:' . $task['key'], (string) $comment['text'], (string) ( $comment['by'] ?? '' ), 'tomagic' );
	}

	private static function note( int $artifactId, string $on, string $text, string $by, string $mirror = '' ): void {
		$text = TioDay::text( trim( $text ), 4000, true );
		if ( $text === '' ) {
			return;
		}
		$value = [
			'on'   => $on,
			'text' => $text,
			'by'   => $by !== '' ? $by : 'Tomagic',
			'at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
		];
		if ( $mirror !== '' ) {
			$value['mirror'] = $mirror;
		}
		StoreData::write( $artifactId, 'comments', StoreData::generateKey(), $value, StoreData::anonymousAttribution(), 'create', null, 0 );
	}

	private static function moveAgentTasks(): void {
		foreach ( \get_posts( [ 'post_type' => Cpts::ARTIFACT_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 50, 'meta_key' => HomeWorkspace::META_OWNER ] ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery -- once, when both extensions are on.
			$id = (int) $id;
			if ( ! TioDayIds::isTioDay( $id ) ) {
				continue;
			}
			foreach ( StoreData::list( $id, 'tasks' ) as $doc ) {
				self::moveAgentTask( $id, $doc );
			}
		}
	}

	/**
	 * @param array<string, mixed> $doc
	 */
	private static function moveAgentTask( int $tioday, array $doc ): void {
		$value = is_array( $doc['value'] ) ? $doc['value'] : [];
		if ( ( $value['status'] ?? 'open' ) === 'done' || ! is_string( $value['who'] ?? null ) || TaskTakers::named( $value['who'] ) <= 0 ) {
			return;
		}
		$agent = TaskTakers::named( $value['who'] );
		$board = self::boardFor( $agent );
		if ( $board <= 0 ) {
			return;
		}
		$name = TaskTakers::nameOf( $agent );
		$have = is_string( $value['item'] ?? null ) && $value['item'] !== '' ? Project::find( $board, $value['item'] ) : null;
		if ( $have === null ) {
			$caller = [
				'writer' => 0,
				'person' => 0,
				'by'     => 'Tomagic',
				'for'    => '',
				'people' => true,
				'store'  => StoreData::anonymousAttribution(),
			];
			$made   = Project::log(
				$board,
				[
					'title'  => (string) ( $value['title'] ?? __( 'Agent task', 'tomatio' ) ),
					'detail' => (string) ( $value['why'] ?? '' ),
					'kind'   => 'feature',
					'owner'  => $name,
					'links'  => is_array( $value['links'] ?? null ) ? $value['links'] : [],
				],
				$caller
			);
			if ( $made instanceof WP_Error ) {
				return;
			}
			$have = [ 'key' => (string) $made['key'], 'value' => is_array( $made['value'] ) ? $made['value'] : [], 'version' => (int) $made['version'] ];
		}
		$bid = is_string( $have['value']['id'] ?? null ) ? $have['value']['id'] : '';
		if ( $bid === '' ) {
			$again = StoreData::get( $board, 'items', (string) $have['key'] );
			$bid   = is_array( $again['value'] ?? null ) ? (string) ( $again['value']['id'] ?? '' ) : '';
		}
		$tid = is_string( $value['id'] ?? null ) ? $value['id'] : '';
		if ( $bid !== '' && $tid !== '' && ( $have['value']['tioday'] ?? '' ) !== $tid ) {
			$linked           = $have['value'];
			$linked['tioday'] = $tid;
			Change::write(
				$board,
				(string) $have['key'],
				$linked,
				(int) $have['version'],
				[ 'what' => 'linked', 'by' => 'Tomagic', 'to' => $tid, 'reason' => 'Agent task moved off TíoDay' ],
				StoreData::anonymousAttribution()
			);
		}
		$value['status']  = 'done';
		$value['done_on'] = gmdate( 'Y-m-d' );
		$value['done_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$value['done_by'] = 'Tomagic';
		$value['flow']    = 'moved';
		$value['item']    = $bid;
		$value['board']   = $board;
		StoreData::set( $tioday, 'tasks', (string) $doc['key'], $value, StoreData::anonymousAttribution(), null, (int) $doc['version'] );
		if ( $bid !== '' ) {
			self::note( $tioday, 't:' . $doc['key'], sprintf( __( 'Moved to Tomagic %s. Agents take their work from the board.', 'tomatio' ), $bid ), 'Tomagic' );
		}
	}

	private static function boardFor( int $agentId ): int {
		$fallback = 0;
		foreach ( Backlog::all() as $board ) {
			$fallback = $fallback > 0 ? $fallback : $board;
			if ( Backlog::canRead( $agentId, $board ) ) {
				return $board;
			}
		}

		return $fallback;
	}

	/**
	 * @return array{key: string, value: array<string, mixed>, version: int}|null
	 */
	private static function openTask( int $tioday, string $bid ): ?array {
		$task = self::linkedTask( $tioday, $bid );

		return $task !== null && ( $task['value']['status'] ?? '' ) !== 'done' ? $task : null;
	}

	/**
	 * @return array{key: string, value: array<string, mixed>, version: int}|null
	 */
	private static function linkedTask( int $tioday, string $bid ): ?array {
		if ( $tioday <= 0 || $bid === '' ) {
			return null;
		}
		$found = null;
		foreach ( StoreData::list( $tioday, 'tasks' ) as $doc ) {
			$v = is_array( $doc['value'] ) ? $doc['value'] : [];
			if ( ( $v['item'] ?? '' ) !== $bid ) {
				continue;
			}
			$row = [ 'key' => (string) $doc['key'], 'value' => $v, 'version' => (int) $doc['version'] ];
			if ( ( $v['status'] ?? '' ) !== 'done' ) {
				return $row;
			}
			$found = $row;
		}

		return $found;
	}

	/**
	 * @return array{key: string, value: array<string, mixed>}|null
	 */
	private static function linkedDecision( int $tioday, string $bid ): ?array {
		foreach ( StoreData::list( $tioday, 'decisions' ) as $doc ) {
			$v = is_array( $doc['value'] ) ? $doc['value'] : [];
			if ( ( $v['item'] ?? '' ) === $bid ) {
				return [ 'key' => (string) $doc['key'], 'value' => $v ];
			}
		}

		return null;
	}

	private static function freeSlug( int $tioday, string $itemKey ): string {
		$base = strtolower( (string) preg_replace( '/[^a-z0-9-]+/i', '-', 'tm-' . $itemKey ) );
		$base = trim( $base, '-' );
		$base = $base !== '' ? substr( $base, 0, 60 ) : 'tm-item';
		$slug = $base;
		$n    = 2;
		while ( StoreData::get( $tioday, 'tasks', $slug ) !== null ) {
			$slug = substr( $base, 0, 57 ) . '-' . $n;
			++$n;
		}

		return $slug;
	}
}
