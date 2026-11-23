<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

use AgentBrain\Core\Hooks;
use AgentBrain\Modules\Backlog\Backlog;
use AgentBrain\Modules\Backlog\Change;
use AgentBrain\Modules\Backlog\Item;
use AgentBrain\Modules\Backlog\Project;
use AgentBrain\Modules\Backlog\Releases;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\Integrations\Store;
use WP_Error;

/**
 * #992: dry run, approve, and the run that follows. The first run
 * writes nothing until a person approves its fingerprint. After that
 * it runs on its own. A removed item is unlinked, never deleted on
 * the service. Every write to the service is audited.
 */
final class Apply {

	/**
	 * @return array{ok: bool, text: string}
	 */
	public static function preview( string $service, int $actorId ): array {
		$work = self::working( $service, $actorId );
		if ( is_string( $work ) ) {
			self::emit( 'integration.planning_preview', $service, $actorId, false );

			return self::no( $work );
		}
		$row                = $work['row'];
		$row['report']      = Plan::words( $work['plan'], $service );
		$row['fingerprint'] = (string) $work['plan']['fingerprint'];
		$row['backlog_id']  = $work['board_id'];
		$row['actor_id']    = $actorId > 0 ? $actorId : (int) $row['actor_id'];
		Library::save( $service, $row );
		self::emit( 'integration.planning_preview', $service, $actorId, true );

		return self::yes( $row['report'] );
	}

	/**
	 * @return array{ok: bool, text: string}
	 */
	public static function approve( string $service, int $actorId, string $yes ): array {
		$work = self::working( $service, $actorId );
		if ( is_string( $work ) ) {
			self::emit( 'integration.planning_approve', $service, $actorId, false );

			return self::no( $work );
		}
		$gate = Plan::gate( $work['plan'], false, $yes );
		if ( $gate !== true ) {
			self::emit( 'integration.planning_approve', $service, $actorId, false );

			return self::no( $gate );
		}
		self::emit( 'integration.planning_approve', $service, $actorId, true );

		return self::commit( $work, $actorId, true );
	}

	/**
	 * B236: make a Notion backlog database under the page a person picked,
	 * save it as the sync target, and fill it at once. No dry run: the
	 * database is new and empty, so nothing in it can be overwritten.
	 * Every open item is written once and linked; the card gets the run's
	 * report. A second click while the first is still working is refused,
	 * so it cannot make two databases.
	 *
	 * @return array{ok: bool, text: string}
	 */
	public static function create( string $service, int $actorId, string $parentPage, int $boardId ): array {
		if ( $service !== 'notion' ) {
			return self::no( 'Only a Notion backlog can be made here.' );
		}
		$row = Library::row( $service );
		if ( ! empty( $row['live'] ) ) {
			return self::no( 'Sync is on. Stop it before making a new Notion database.' );
		}
		$boardId = $boardId > 0 ? $boardId : (int) $row['backlog_id'];
		if ( $boardId <= 0 ) {
			$resolved = Backlog::resolve( 0, $actorId > 0 ? $actorId : null );
			if ( $resolved instanceof WP_Error ) {
				return self::no( $resolved->get_error_message() );
			}
			$boardId = $resolved;
		}
		if ( ! Backlog::isBacklog( $boardId ) ) {
			return self::no( 'That artifact is not a Magic Backlog board.' );
		}
		if ( $actorId > 0 && ! Backlog::canWrite( $actorId, $boardId ) ) {
			return self::no( 'You cannot write that Magic Backlog board, so nothing was made.' );
		}
		$title   = get_post_field( 'post_title', $boardId );
		$title   = is_string( $title ) && trim( $title ) !== '' ? trim( $title ) : 'TomaTío backlog';
		$request = Write::notionDatabase( $parentPage, $title, self::releaseNames( $boardId, $service ) );
		if ( $request === null || Write::forbidden( $request ) ) {
			return self::no( 'Pick the Notion page to put the backlog under.' );
		}
		$lock = 'ab_plan_creating_' . $service;
		if ( get_transient( $lock ) !== false ) {
			return self::no( 'The Notion database is being made already. Reload this page in a moment.' );
		}
		set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

		$sent = Pull::dispatch( $service, $request['method'], $request['url'], $request['body'] );
		$made = is_array( $sent ) && (int) $sent['status'] >= 200 && (int) $sent['status'] < 300 ? Write::createdDatabase( $sent['body'] ) : [ 'id' => '', 'url' => '' ];
		if ( $made['id'] === '' ) {
			delete_transient( $lock );
			self::emit( 'integration.planning_create', $service, $actorId, false );
			if ( is_string( $sent ) ) {
				return self::no( 'Notion did not make the database: ' . $sent );
			}
			$status = (int) $sent['status'];

			return self::no( $status === 404 || $status === 403
				? 'Notion cannot write under that page. In Notion, open the page, ••• > Connections, add this connection, then try again.'
				: 'Notion did not make the database (it answered ' . $status . '). Nothing was saved.' );
		}
		Store::noteWrite( $service, $actorId );
		self::emit( 'integration.planning_create', $service, $actorId, true );

		$row                    = Library::blank( $service );
		$row['target']          = $made['id'];
		$row['target_kind']     = 'database';
		$row['target_url']      = $made['url'];
		$row['backlog_id']      = $boardId;
		$row['actor_id']        = $actorId;
		$row['setup']           = 'create';
		$row['open_only']       = true;
		Library::save( $service, $row );
		Choices::forget( $service );

		$work = self::working( $service, $actorId );
		if ( is_string( $work ) ) {
			delete_transient( $lock );
			$row['report'] = 'Created the Notion database “' . $title . '”, but its first fill did not run: ' . $work . ' Run a dry run below.';
			Library::save( $service, $row );

			return self::no( $row['report'] );
		}
		$result = self::commit( $work, $actorId, true );
		delete_transient( $lock );
		$after           = Library::row( $service );
		$after['report'] = 'Created the Notion database “' . $title . '”.' . "\n" . (string) $after['report'];
		Library::save( $service, $after );

		return [ 'ok' => $result['ok'], 'text' => 'Created the Notion database “' . $title . '”. ' . $result['text'] ];
	}

	/**
	 * B236: a status that is still open on the board: in the Inbox, ready
	 * (Now, Next, a planned release) or being built.
	 */
	public static function isOpen( string $status ): bool {
		return in_array( $status, [ 'inbox', 'ready', 'building' ], true );
	}

	/**
	 * B236: the items a database TomaTío made takes: every open item, and
	 * any item already linked to one of its rows, so a shipped item keeps
	 * its row up to date. Only the links this database recorded count: a
	 * source from another Notion database (an earlier sync, a backlog
	 * import) is not one of its rows, so it is neither unlinked nor kept
	 * from being written.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, string>            $links board id => row id
	 * @return array<int, array<string, mixed>>
	 */
	public static function openOnly( array $items, array $links, string $service ): array {
		$out = [];
		foreach ( $items as $item ) {
			$link = (string) ( $links[ (string) ( $item['id'] ?? '' ) ] ?? '' );
			if ( $link === '' && ! self::isOpen( (string) ( $item['status'] ?? '' ) ) ) {
				continue;
			}
			$value   = is_array( $item['value'] ?? null ) ? $item['value'] : [];
			$sources = is_array( $value['sources'] ?? null ) ? $value['sources'] : [];
			$source  = $link !== '' && is_array( $sources[ $service . ':' . $link ] ?? null ) ? $sources[ $service . ':' . $link ] : null;
			$item['remote_id'] = $link;
			$item['snapshot']  = $source !== null && is_array( $source['snapshot'] ?? null ) ? $source['snapshot'] : null;
			$item['url']       = $source !== null ? (string) ( $source['url'] ?? '' ) : '';
			$out[]             = $item;
		}

		return $out;
	}

	/**
	 * The board's releases and those its open items carry, for the new
	 * database's Release options.
	 *
	 * @return array<int, string>
	 */
	private static function releaseNames( int $boardId, string $service ): array {
		$names = [];
		$doc   = StoreData::get( $boardId, 'meta', 'project' );
		if ( $doc !== null && is_array( $doc['value'] ) ) {
			foreach ( array_keys( Releases::listed( $doc['value'] ) ) as $version ) {
				$names[ (string) $version ] = true;
			}
		}
		foreach ( self::boardItems( $boardId, $service ) as $item ) {
			$release = (string) $item['release'];
			if ( $release !== '' && self::isOpen( (string) $item['status'] ) ) {
				$names[ $release ] = true;
			}
		}
		$list = array_map( 'strval', array_keys( $names ) );
		usort( $list, static fn ( string $a, string $b ): int => version_compare( $a, $b ) );

		return $list;
	}

	/**
	 * @return array{ok: bool, text: string}
	 */
	public static function cron( string $service ): array {
		$row = Library::row( $service );
		if ( empty( $row['live'] ) || (string) $row['target'] === '' ) {
			return self::yes( 'Planning sync is not on.' );
		}
		$actor = (int) $row['actor_id'];
		$work  = self::working( $service, $actor );
		if ( is_string( $work ) ) {
			self::emit( 'integration.planning_sync', $service, $actor, false );

			return self::no( $work );
		}

		return self::commit( $work, $actor, false );
	}

	/**
	 * @return array{ok: bool, text: string}
	 */
	public static function stop( string $service, int $actorId ): array {
		$row         = Library::row( $service );
		$row['live'] = false;
		Library::save( $service, $row );
		self::emit( 'integration.planning_stop', $service, $actorId, true );

		return self::yes( 'Planning sync is off. Nothing was deleted on the service.' );
	}

	/**
	 * @return array{ok: bool, text: string, row: array<string, mixed>, plan: array<string, mixed>, board_id: int, pulled: array<string, mixed>, board: array<string, array<string, mixed>>}|string
	 */
	private static function working( string $service, int $actorId ): array|string {
		$row = Library::row( $service );
		if ( (string) $row['target'] === '' ) {
			return 'Pick a Linear team or a Notion database first.';
		}
		$pulled = Pull::live( $service, $row );
		if ( is_string( $pulled ) ) {
			return $pulled;
		}
		$boardId = (int) $row['backlog_id'];
		if ( $boardId <= 0 ) {
			$resolved = Backlog::resolve( 0, $actorId > 0 ? $actorId : null );
			if ( $resolved instanceof WP_Error ) {
				return $resolved->get_error_message();
			}
			$boardId = $resolved;
		}
		if ( ! Backlog::isBacklog( $boardId ) ) {
			return 'That artifact is not a Magic Backlog board.';
		}
		if ( $actorId > 0 && ! Backlog::canWrite( $actorId, $boardId ) ) {
			return 'You cannot write that Magic Backlog board, so nothing was changed.';
		}

		$items = self::boardItems( $boardId, $service );
		if ( ! empty( $row['open_only'] ) ) {
			$items = self::openOnly( $items, self::links( $row ), $service );
		}
		$plan  = Plan::build( $items, $pulled['records'], self::links( $row ), self::list( $row, 'unlinked_remote' ), self::list( $row, 'unlinked_board' ), ! empty( $pulled['truncated'] ) );
		$plan  = Plan::releases( $plan, $service, self::target( [ 'row' => $row, 'pulled' => $pulled ] ) );

		return [
			'ok'       => true,
			'text'     => '',
			'row'      => $row,
			'plan'     => $plan,
			'board_id' => $boardId,
			'pulled'   => $pulled,
			'board'    => self::index( $items ),
		];
	}

	/**
	 * @param array{row: array<string, mixed>, plan: array<string, mixed>, board_id: int, pulled: array<string, mixed>, board: array<string, array<string, mixed>>} $work
	 * @return array{ok: bool, text: string}
	 */
	private static function commit( array $work, int $actorId, bool $turnOn ): array {
		$service = (string) $work['row']['service'];
		$plan    = $work['plan'];
		$boardId = $work['board_id'];
		$by      = StoreData::attributionFromUser( $actorId, $boardId );
		$user    = get_userdata( $actorId );
		$login   = $user instanceof \WP_User ? (string) $user->user_login : 'planning-sync';
		$now     = time();
		$at      = gmdate( 'Y-m-d\\TH:i:s\\Z', $now );
		$target  = self::target( $work );
		$failed  = [];
		$wrote   = 0;
		$links   = is_array( $plan['links'] ?? null ) ? $plan['links'] : [];
		$made    = [];

		// B232: projects for new releases first, so the issues can join them.
		foreach ( Plan::newReleases( $plan ) as $release ) {
			$request = Write::project( $release, $target );
			if ( $request === null || Write::forbidden( $request ) ) {
				$failed[] = 'Could not make the Linear project ' . $release . ', so its issues go without one.';
				continue;
			}
			$sent = Pull::dispatch( $service, $request['method'], $request['url'], $request['body'] );
			$id   = is_array( $sent ) && (int) $sent['status'] >= 200 && (int) $sent['status'] < 300 ? Write::createdProject( $sent['body'] ) : '';
			if ( $id === '' ) {
				$failed[] = 'Linear did not make the project ' . $release . ', so its issues go without one.';
				continue;
			}
			Store::noteWrite( $service, $actorId );
			++$wrote;
			$target['projects'][ $release ] = $id;
		}

		// What really happened, by plan op, for the run report: a failed write is not "Created".
		$doneOps = [];
		// B237: board items whose push reached the service, so their snapshot can move on.
		$pushed = [];
		foreach ( Write::requests( $service, is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [], $target ) as $request ) {
			$index  = (int) $request['action'];
			$action = is_array( $plan['actions'][ $index ] ?? null ) ? $plan['actions'][ $index ] : [];
			if ( Write::forbidden( $request ) ) {
				$failed[] = 'A write was refused because it would delete.';
				continue;
			}
			$sent = Pull::dispatch( $service, $request['method'], $request['url'], $request['body'] );
			if ( is_string( $sent ) || (int) $sent['status'] < 200 || (int) $sent['status'] >= 300 ) {
				$failed[] = is_string( $sent ) ? $sent : Write::failure( $service, $sent, (string) ( $action['title'] ?? ( $action['fields']['title'] ?? '' ) ) );
				continue;
			}
			Store::noteWrite( $service, $actorId );
			++$wrote;
			$op             = (string) ( $action['op'] ?? '' );
			$doneOps[ $op ] = ( $doneOps[ $op ] ?? 0 ) + 1;
			if ( $op === 'update_service' ) {
				$pushed[ (string) ( $action['board_id'] ?? '' ) ] = true;
			}
			$created = Write::createdId( $service, $sent['body'] );
			$boardItem = (string) ( $action['board_id'] ?? '' );
			if ( $created !== '' && $boardItem !== '' ) {
				$made[ $boardItem ] = $created;
				$links[ $boardItem ] = $created;
			}
		}

		// B237: a push that did not reach the service keeps the old snapshot
		// for its fields, so the next run pushes again instead of reading
		// the service's old value as a change. A push that did, with no
		// board change beside it, still moves the snapshot on.
		$boardChanged = [];
		$unpushed     = [];
		foreach ( is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [] as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$item = (string) ( $action['board_id'] ?? '' );
			$op   = (string) ( $action['op'] ?? '' );
			if ( $op === 'update_board' || $op === 'unlink' ) {
				$boardChanged[ $item ] = true;
			} elseif ( $op === 'update_service' && ! isset( $pushed[ $item ] ) ) {
				$unpushed[ $item ] = array_keys( is_array( $action['fields'] ?? null ) ? $action['fields'] : [] );
			}
		}

		$releases = [];
		foreach ( is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [] as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$op = (string) ( $action['op'] ?? '' );
			if ( $op === 'update_service' ) {
				$item = (string) ( $action['board_id'] ?? '' );
				if ( isset( $pushed[ $item ] ) && ! isset( $boardChanged[ $item ] ) ) {
					$done = self::boardWrite( $boardId, $service, $action, $work['board'], $made, $login, $at, $now, $by );
					if ( $done['error'] !== '' ) {
						$failed[] = $done['error'];
					}
				}
				continue;
			}
			if ( $op !== 'create_board' && $op !== 'update_board' && $op !== 'unlink' && $op !== 'create_service' ) {
				continue;
			}
			$keep = $unpushed[ (string) ( $action['board_id'] ?? '' ) ] ?? [];
			$done = self::boardWrite( $boardId, $service, $action, $work['board'], $made, $login, $at, $now, $by, $keep );
			if ( $done['error'] !== '' ) {
				$failed[] = $done['error'];
				continue;
			}
			if ( $op !== 'create_service' ) {
				$doneOps[ $op ] = ( $doneOps[ $op ] ?? 0 ) + 1;
			}
			if ( $done['release'] !== '' ) {
				$releases[] = $done['release'];
			}
		}
		self::ensureReleases( $boardId, $releases, $by );

		$row                     = $work['row'];
		$row['links']            = $links;
		$row['unlinked_remote']  = is_array( $plan['unlinked_remote'] ?? null ) ? $plan['unlinked_remote'] : [];
		$row['unlinked_board']   = is_array( $plan['unlinked_board'] ?? null ) ? $plan['unlinked_board'] : [];
		$row['backlog_id']       = $boardId;
		$row['actor_id']         = $actorId > 0 ? $actorId : (int) $row['actor_id'];
		$row['ran_at']           = $at;
		$row['fingerprint']      = (string) ( $plan['fingerprint'] ?? '' );
		$row['report']           = Plan::ranWords( $plan, $service, $at, $wrote, $failed, $doneOps );
		if ( $turnOn ) {
			$row['live'] = true;
		}
		Library::save( $service, $row );
		self::emit( 'integration.planning_sync', $service, $actorId, $failed === [] );

		$text = 'Wrote ' . $wrote . ' change' . ( $wrote === 1 ? '' : 's' ) . ' to the service.';
		if ( $turnOn ) {
			$text .= ' Planning sync is on and will run on its own.';
		}
		if ( $failed !== [] ) {
			$text .= ' ' . $failed[0];
		}

		return $failed === [] ? self::yes( $text ) : self::no( $text );
	}

	/**
	 * @param array<string, mixed>             $action
	 * @param array<string, array<string, mixed>> $board
	 * @param array<string, string>            $made
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null} $by
	 * @param array<int, string>               $keep Fields whose push failed: their snapshot stays.
	 * @return array{error: string, release: string}
	 */
	private static function boardWrite( int $boardId, string $service, array $action, array $board, array $made, string $login, string $at, int $now, array $by, array $keep = [] ): array {
		$op      = (string) ( $action['op'] ?? '' );
		$boardItem = (string) ( $action['board_id'] ?? '' );
		$remote  = (string) ( $action['remote_id'] ?? '' );
		if ( isset( $made[ $boardItem ] ) ) {
			$remote = $made[ $boardItem ];
		}
		$fields = is_array( $action['fields'] ?? null ) ? $action['fields'] : [];
		if ( $op === 'create_board' ) {
			$value = self::newItem( $action, $service, $login, $at );
			$key   = Project::freeKey( $boardId, (string) ( $value['title'] ?? 'item' ) );
			$wrote = Change::write( $boardId, $key, $value, 0, [ 'what' => 'read', 'by' => $login, 'via' => 'planning sync' ], $by, $now );

			return $wrote instanceof WP_Error
				? [ 'error' => (string) ( $action['title'] ?? 'item' ) . ': ' . $wrote->get_error_message(), 'release' => '' ]
				: [ 'error' => '', 'release' => (string) ( $fields['release'] ?? '' ) ];
		}
		$current = $board[ $boardItem ] ?? null;
		if ( $current === null ) {
			return [ 'error' => '', 'release' => '' ];
		}
		$value = is_array( $current['value'] ?? null ) ? $current['value'] : [];
		if ( $op === 'create_service' ) {
			if ( $remote === '' ) {
				return [ 'error' => '', 'release' => '' ];
			}
			$value = self::withSource( $value, $service, $remote, (string) ( $action['url'] ?? '' ), $at, $fields, (string) ( $action['alias'] ?? '' ) );
			$wrote = Change::write( $boardId, (string) $current['key'], $value, (int) $current['version'], [ 'what' => 'linked', 'by' => $login, 'via' => 'planning sync' ], $by, $now );

			return $wrote instanceof WP_Error
				? [ 'error' => $boardItem . ': ' . $wrote->get_error_message(), 'release' => '' ]
				: [ 'error' => '', 'release' => '' ];
		}
		if ( $op === 'update_service' ) {
			// B237: the push landed; only the snapshot moves on.
			if ( $remote === '' ) {
				return [ 'error' => '', 'release' => '' ];
			}
			$value = self::withSource( $value, $service, $remote, (string) ( $current['url'] ?? '' ), $at, [] );
			$wrote = Change::write( $boardId, (string) $current['key'], $value, (int) $current['version'], [ 'what' => 'snapshot', 'by' => $login, 'via' => 'planning sync' ], $by, $now );

			return $wrote instanceof WP_Error
				? [ 'error' => $boardItem . ': ' . $wrote->get_error_message(), 'release' => '' ]
				: [ 'error' => '', 'release' => '' ];
		}
		if ( $op === 'unlink' ) {
			$sources = is_array( $value['sources'] ?? null ) ? $value['sources'] : [];
			unset( $sources[ $service . ':' . $remote ] );
			$value['sources'] = $sources === [] ? new \stdClass() : $sources;
			$flags            = is_array( $value['flags'] ?? null ) ? $value['flags'] : [];
			$flags[]          = Item::flag( 'deleted_in_tool', 'Unlinked. Nothing was deleted on the service.', $at );
			$value['flags']   = array_slice( $flags, -Item::MAX_FLAGS );
		} else {
			foreach ( [ 'title', 'detail', 'status', 'owner', 'release' ] as $field ) {
				if ( isset( $fields[ $field ] ) && is_string( $fields[ $field ] ) ) {
					$value[ $field ] = $fields[ $field ];
				}
			}
			$value = self::withSource( $value, $service, $remote, (string) ( $action['url'] ?? '' ), $at, $fields, (string) ( $action['alias'] ?? '' ), $keep );
			$value = self::withHistory( $value, $action, $login, $at );
			if ( (string) ( $action['alias'] ?? '' ) !== '' ) {
				$value = Item::withAlias( $value, (string) $action['alias'] );
			}
		}
		$wrote = Change::write( $boardId, (string) $current['key'], $value, (int) $current['version'], [ 'what' => $op === 'unlink' ? 'linked' : 'snapshot', 'by' => $login, 'via' => 'planning sync' ], $by, $now );

		return $wrote instanceof WP_Error
			? [ 'error' => $boardItem . ': ' . $wrote->get_error_message(), 'release' => '' ]
			: [ 'error' => '', 'release' => (string) ( $fields['release'] ?? '' ) ];
	}

	/**
	 * @param array<string, mixed> $action
	 * @return array<string, mixed>
	 */
	private static function newItem( array $action, string $service, string $login, string $at ): array {
		$fields = is_array( $action['fields'] ?? null ) ? $action['fields'] : [];
		$status = is_string( $fields['status'] ?? null ) ? $fields['status'] : 'inbox';
		if ( ! in_array( $status, Item::STATUSES, true ) || in_array( $status, Item::RANKED, true ) ) {
			$status = 'inbox';
		}
		$remote = (string) ( $action['remote_id'] ?? '' );
		$value  = [
			'kind'     => 'feature',
			'title'    => TioDay::text( $fields['title'] ?? $action['title'] ?? '', Item::MAX_TITLE ),
			'detail'   => TioDay::text( $fields['detail'] ?? '', Item::MAX_DETAIL, true ),
			'urgency'  => 'later',
			'rank'     => null,
			'status'   => $status,
			'links'    => [],
			'added_by' => $login . ' (planning sync)',
			'added_at' => $at,
			'sources'  => [],
			'tags'     => [],
			'aliases'  => [],
			'flags'    => [],
		];
		$owner = is_string( $fields['owner'] ?? null ) ? $fields['owner'] : '';
		if ( $owner !== '' ) {
			$value['owner'] = TioDay::text( $owner, TioDay::MAX_NAME );
		}
		$release = is_string( $fields['release'] ?? null ) ? $fields['release'] : '';
		if ( $release !== '' ) {
			$value['release'] = TioDay::text( $release, TioDay::MAX_NAME );
		}
		$value = self::withSource( $value, $service, $remote, (string) ( $action['url'] ?? '' ), $at, $fields, (string) ( $action['alias'] ?? '' ) );
		$alias = (string) ( $action['alias'] ?? '' );
		if ( $alias !== '' ) {
			$value = Item::withAlias( $value, $alias );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $value
	 * @param array<string, mixed> $fields
	 * @param array<int, string>   $keep Fields whose snapshot stays as it was (B237).
	 * @return array<string, mixed>
	 */
	private static function withSource( array $value, string $service, string $remote, string $url, string $at, array $fields, string $alias = '', array $keep = [] ): array {
		if ( $remote === '' ) {
			return $value;
		}
		$sources = is_array( $value['sources'] ?? null ) ? $value['sources'] : [];
		$prior   = is_array( $sources[ $service . ':' . $remote ] ?? null ) ? $sources[ $service . ':' . $remote ] : [];
		$priorSnap = is_array( $prior['snapshot'] ?? null ) ? $prior['snapshot'] : [];
		$sources[ $service . ':' . $remote ] = [
			'id'         => $remote,
			'url'        => $url !== '' ? $url : (string) ( $prior['url'] ?? '' ),
			'alias'      => $alias !== '' ? $alias : (string) ( $prior['alias'] ?? '' ),
			'main'       => true,
			'first_read' => (string) ( $prior['first_read'] ?? $at ),
			'synced_at'  => $at,
			'updated_at' => $at,
			'snapshot'   => [
				'title'   => (string) ( $value['title'] ?? '' ),
				'detail'  => (string) ( $value['detail'] ?? '' ),
				'status'  => (string) ( $value['status'] ?? '' ),
				'owner'   => (string) ( $value['owner'] ?? '' ),
				'rank'    => is_string( $value['rank'] ?? null ) ? $value['rank'] : '',
				'release' => (string) ( $value['release'] ?? $fields['release'] ?? '' ),
			],
		];
		foreach ( $keep as $field ) {
			if ( is_string( $field ) && array_key_exists( $field, $priorSnap ) ) {
				$sources[ $service . ':' . $remote ]['snapshot'][ $field ] = (string) $priorSnap[ $field ];
			}
		}
		$value['sources'] = $sources;

		return $value;
	}

	/**
	 * @param array<string, mixed> $value
	 * @param array<string, mixed> $action
	 * @return array<string, mixed>
	 */
	private static function withHistory( array $value, array $action, string $login, string $at ): array {
		$hist = is_array( $value['hist'] ?? null ) ? $value['hist'] : [];
		foreach ( is_array( $action['history'] ?? null ) ? $action['history'] : [] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$hist[] = [
				'at'   => $at,
				'by'   => $login,
				'f'    => (string) ( $entry['f'] ?? '' ),
				'from' => (string) ( $entry['from'] ?? '' ),
				'to'   => (string) ( $entry['to'] ?? '' ),
			];
		}
		if ( $hist !== [] ) {
			$value['hist'] = array_values( array_slice( $hist, -60 ) );
		}

		return $value;
	}

	/**
	 * @param array<int, string> $versions
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null} $by
	 */
	private static function ensureReleases( int $boardId, array $versions, array $by ): void {
		$want = [];
		foreach ( $versions as $version ) {
			$normal = Releases::normalize( $version );
			if ( is_string( $normal ) && $normal !== '' && $normal !== Releases::NEXT && $normal !== Releases::LATER ) {
				$want[ $normal ] = true;
			}
		}
		if ( $want === [] ) {
			return;
		}
		$doc = StoreData::get( $boardId, 'meta', 'project' );
		if ( $doc === null || ! is_array( $doc['value'] ) ) {
			return;
		}
		$project = $doc['value'];
		$listed  = Releases::listed( $project );
		$added   = false;
		foreach ( array_keys( $want ) as $version ) {
			if ( isset( $listed[ $version ] ) || count( $listed ) >= Releases::MAX ) {
				continue;
			}
			$listed[ $version ] = [ 'version' => $version ];
			$added              = true;
		}
		if ( ! $added ) {
			return;
		}
		uksort( $listed, 'version_compare' );
		$project['releases'] = array_values( $listed );
		StoreData::write( $boardId, 'meta', 'project', $project, $by, 'replace', null, (int) $doc['version'] );
	}

	/**
	 * @param array{row: array<string, mixed>, pulled: array<string, mixed>} $work
	 * @return array<string, mixed>
	 */
	private static function target( array $work ): array {
		$row = $work['row'];
		$pulled = $work['pulled'];

		return [
			'id'          => (string) $row['target'],
			'kind'        => (string) $row['target_kind'],
			'team_id'     => (string) ( $pulled['team_id'] ?? '' ),
			'release_via' => (string) $row['release_via'],
			'projects'    => is_array( $pulled['projects'] ?? null ) ? $pulled['projects'] : [],
			'cycles'      => is_array( $pulled['cycles'] ?? null ) ? $pulled['cycles'] : [],
			'states'      => is_array( $pulled['states'] ?? null ) ? $pulled['states'] : [],
			'mapping'     => Rules::mapping( (string) $row['service'], is_array( $row['mapping'] ?? null ) ? $row['mapping'] : [] ),
			'types'       => is_array( $pulled['types'] ?? null ) ? $pulled['types'] : [],
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function boardItems( int $artifactId, string $service ): array {
		$choices = Releases::choices( $artifactId );
		$out     = [];
		foreach ( StoreData::list( $artifactId, 'items' ) as $doc ) {
			$value = is_array( $doc['value'] ?? null ) ? $doc['value'] : null;
			if ( $value === null || ! is_string( $value['id'] ?? null ) ) {
				continue;
			}
			$remote   = '';
			$snapshot = null;
			$url      = '';
			$sources  = is_array( $value['sources'] ?? null ) ? $value['sources'] : [];
			foreach ( $sources as $key => $source ) {
				if ( ! is_string( $key ) || ! is_array( $source ) || ! str_starts_with( $key, $service . ':' ) ) {
					continue;
				}
				$remote   = (string) ( $source['id'] ?? '' );
				$snapshot = is_array( $source['snapshot'] ?? null ) ? $source['snapshot'] : null;
				$url      = (string) ( $source['url'] ?? '' );
			}
			$choice = $choices[ (string) $doc['key'] ] ?? null;
			$release = '';
			if ( is_array( $choice ) && is_array( $choice['value'] ?? null ) && array_key_exists( 'release', $choice['value'] ) ) {
				$release = is_string( $choice['value']['release'] ) ? $choice['value']['release'] : '';
			} elseif ( is_string( $value['release'] ?? null ) ) {
				$release = $value['release'];
			}
			$out[] = [
				'id'         => $value['id'],
				'key'        => (string) $doc['key'],
				'version'    => (int) $doc['version'],
				'title'      => (string) ( $value['title'] ?? '' ),
				'detail'     => (string) ( $value['detail'] ?? '' ),
				'status'     => (string) ( $value['status'] ?? 'inbox' ),
				'owner'      => (string) ( $value['owner'] ?? '' ),
				'rank'       => is_string( $value['rank'] ?? null ) ? $value['rank'] : '',
				'release'    => $release,
				'updated_at' => (string) ( $doc['updated_at'] ?? '' ),
				'remote_id'  => $remote,
				'url'        => $url,
				'snapshot'   => $snapshot,
				'value'      => $value,
			];
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<string, array<string, mixed>>
	 */
	private static function index( array $items ): array {
		$out = [];
		foreach ( $items as $item ) {
			$out[ (string) $item['id'] ] = $item;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, string>
	 */
	private static function links( array $row ): array {
		$out = [];
		foreach ( is_array( $row['links'] ?? null ) ? $row['links'] : [] as $board => $remote ) {
			if ( is_string( $board ) && is_string( $remote ) && $remote !== '' ) {
				$out[ $board ] = $remote;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<int, string>
	 */
	private static function list( array $row, string $key ): array {
		$out = [];
		foreach ( is_array( $row[ $key ] ?? null ) ? $row[ $key ] : [] as $id ) {
			if ( is_string( $id ) && $id !== '' ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	private static function emit( string $event, string $service, int $actorId, bool $ok ): void {
		$base = [
			'actor_id'    => $actorId,
			'status'      => $ok ? 'ok' : 'error',
			'object_type' => 'integration',
			'object_id'   => $service,
		];
		if ( $event === 'integration.planning_preview' ) {
			Hooks::action( 'audit', [ 'event' => 'integration.planning_preview' ] + $base );

			return;
		}
		if ( $event === 'integration.planning_approve' ) {
			Hooks::action( 'audit', [ 'event' => 'integration.planning_approve' ] + $base );

			return;
		}
		if ( $event === 'integration.planning_create' ) {
			Hooks::action( 'audit', [ 'event' => 'integration.planning_create' ] + $base );

			return;
		}
		if ( $event === 'integration.planning_stop' ) {
			Hooks::action( 'audit', [ 'event' => 'integration.planning_stop' ] + $base );

			return;
		}
		Hooks::action( 'audit', [ 'event' => 'integration.planning_sync' ] + $base );
	}

	/** @return array{ok: bool, text: string} */
	private static function yes( string $text ): array {
		return [ 'ok' => true, 'text' => $text ];
	}

	/** @return array{ok: bool, text: string} */
	private static function no( string $text ): array {
		return [ 'ok' => false, 'text' => $text ];
	}
}
