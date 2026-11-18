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
			return 'That artifact is not a Tomagic board.';
		}
		if ( $actorId > 0 && ! Backlog::canWrite( $actorId, $boardId ) ) {
			return 'You cannot write that Tomagic board, so nothing was changed.';
		}

		$items = self::boardItems( $boardId, $service );

		return [
			'ok'       => true,
			'text'     => '',
			'row'      => $row,
			'plan'     => Plan::build( $items, $pulled['records'], self::links( $row ), self::list( $row, 'unlinked_remote' ), self::list( $row, 'unlinked_board' ), ! empty( $pulled['truncated'] ) ),
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

		foreach ( Write::requests( $service, is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [], $target ) as $request ) {
			if ( Write::forbidden( $request ) ) {
				$failed[] = 'A write was refused because it would delete.';
				continue;
			}
			$sent = Pull::dispatch( $service, $request['method'], $request['url'], $request['body'] );
			if ( is_string( $sent ) || (int) $sent['status'] < 200 || (int) $sent['status'] >= 300 ) {
				$failed[] = is_string( $sent ) ? $sent : 'The service answered ' . (int) $sent['status'] . '.';
				continue;
			}
			Store::noteWrite( $service, $actorId );
			++$wrote;
			$created = Write::createdId( $service, $sent['body'] );
			$index   = (int) $request['action'];
			$action  = is_array( $plan['actions'][ $index ] ?? null ) ? $plan['actions'][ $index ] : [];
			$boardItem = (string) ( $action['board_id'] ?? '' );
			if ( $created !== '' && $boardItem !== '' ) {
				$made[ $boardItem ] = $created;
				$links[ $boardItem ] = $created;
			}
		}

		$releases = [];
		foreach ( is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [] as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$op = (string) ( $action['op'] ?? '' );
			if ( $op !== 'create_board' && $op !== 'update_board' && $op !== 'unlink' && $op !== 'create_service' ) {
				continue;
			}
			$done = self::boardWrite( $boardId, $service, $action, $work['board'], $made, $login, $at, $now, $by );
			if ( $done['error'] !== '' ) {
				$failed[] = $done['error'];
				continue;
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
		$row['report']           = Plan::words( $plan, $service );
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
	 * @return array{error: string, release: string}
	 */
	private static function boardWrite( int $boardId, string $service, array $action, array $board, array $made, string $login, string $at, int $now, array $by ): array {
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
			$value = self::withSource( $value, $service, $remote, (string) ( $action['url'] ?? '' ), $at, $fields, (string) ( $action['alias'] ?? '' ) );
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
	 * @return array<string, mixed>
	 */
	private static function withSource( array $value, string $service, string $remote, string $url, string $at, array $fields, string $alias = '' ): array {
		if ( $remote === '' ) {
			return $value;
		}
		$sources = is_array( $value['sources'] ?? null ) ? $value['sources'] : [];
		$prior   = is_array( $sources[ $service . ':' . $remote ] ?? null ) ? $sources[ $service . ':' . $remote ] : [];
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
