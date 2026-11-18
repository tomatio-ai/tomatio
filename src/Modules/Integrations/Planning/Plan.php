<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

/**
 * #992: the dry-run plan. Compares Tomagic items with service rows and
 * says what would be created, changed, linked or unlinked. Nothing here
 * writes. A removed board item is unlinked, never deleted on the service.
 */
final class Plan {

	/**
	 * @param array<int, array<string, mixed>> $board
	 * @param array<int, array<string, mixed>> $remote
	 * @param array<string, string>            $links          Board id => remote id.
	 * @param array<int, string>               $unlinkedRemote Remote ids not to pull back.
	 * @param array<int, string>               $unlinkedBoard  Board ids not to push back.
	 * @return array<string, mixed>
	 */
	public static function build( array $board, array $remote, array $links, array $unlinkedRemote = [], array $unlinkedBoard = [], bool $truncated = false ): array {
		$remoteById = [];
		foreach ( $remote as $row ) {
			$id = self::text( $row['id'] ?? '' );
			if ( $id !== '' ) {
				$remoteById[ $id ] = $row;
			}
		}
		$boardById = [];
		foreach ( $board as $row ) {
			if ( self::text( $row['status'] ?? '' ) === 'idea' ) {
				continue;
			}
			$id = self::text( $row['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$boardById[ $id ] = $row;
			$remoteId         = self::text( $row['remote_id'] ?? '' );
			if ( $remoteId !== '' && ! isset( $links[ $id ] ) ) {
				$links[ $id ] = $remoteId;
			}
		}

		$actions = [];
		$notes   = [];
		$skipRemote = array_fill_keys( $unlinkedRemote, true );
		$skipBoard  = array_fill_keys( $unlinkedBoard, true );
		$usedRemote = [];

		foreach ( $links as $boardId => $remoteId ) {
			if ( $remoteId === '' ) {
				continue;
			}
			$usedRemote[ $remoteId ] = true;
			$hasBoard                = isset( $boardById[ $boardId ] );
			$hasRemote               = isset( $remoteById[ $remoteId ] );
			if ( ! $hasBoard ) {
				$actions[]              = self::action( 'unlink', $boardId, $remoteId, '' );
				$skipRemote[ $remoteId ] = true;
				continue;
			}
			if ( ! $hasRemote ) {
				if ( $truncated ) {
					$notes[] = 'A read stopped early, so ' . $boardId . ' was not unlinked.';
					continue;
				}
				$actions[]            = self::action( 'unlink', $boardId, $remoteId, self::text( $boardById[ $boardId ]['title'] ?? '' ) );
				$skipBoard[ $boardId ] = true;
				continue;
			}
			self::pair( $actions, $notes, $boardById[ $boardId ], $remoteById[ $remoteId ], false );
		}

		$leftRemote = [];
		foreach ( $remoteById as $id => $row ) {
			if ( ! isset( $usedRemote[ $id ] ) && ! isset( $skipRemote[ $id ] ) ) {
				$leftRemote[ $id ] = $row;
			}
		}
		$byTitle = [];
		foreach ( $leftRemote as $id => $row ) {
			$title = self::norm( self::text( $row['title'] ?? '' ) );
			if ( $title === '' ) {
				continue;
			}
			$byTitle[ $title ][] = $id;
		}

		$claimed = [];
		foreach ( $boardById as $boardId => $row ) {
			if ( isset( $links[ $boardId ] ) || isset( $skipBoard[ $boardId ] ) ) {
				continue;
			}
			$title = self::norm( self::text( $row['title'] ?? '' ) );
			$match = $title !== '' && isset( $byTitle[ $title ] ) && count( $byTitle[ $title ] ) === 1 ? $byTitle[ $title ][0] : '';
			if ( $match !== '' && ! isset( $claimed[ $match ] ) ) {
				$claimed[ $match ] = true;
				$actions[]         = self::action( 'link', $boardId, $match, self::text( $row['title'] ?? '' ), [], [], '', 0, self::text( $leftRemote[ $match ]['url'] ?? '' ), self::text( $leftRemote[ $match ]['alias'] ?? '' ) );
				self::pair( $actions, $notes, $row, $leftRemote[ $match ], true );
				continue;
			}
			$actions[] = self::action( 'create_service', $boardId, '', self::text( $row['title'] ?? '' ), self::pushFields( $row ), [], self::text( $row['key'] ?? '' ), (int) ( $row['version'] ?? 0 ) );
		}

		foreach ( $leftRemote as $id => $row ) {
			if ( isset( $claimed[ $id ] ) ) {
				continue;
			}
			$fields    = self::pullFields( $row );
			$actions[] = self::action( 'create_board', '', $id, self::text( $row['title'] ?? '' ), $fields, [], '', 0, self::text( $row['url'] ?? '' ), self::text( $row['alias'] ?? '' ) );
		}

		usort( $actions, static fn ( array $a, array $b ): int => [ $a['op'], $a['board_id'], $a['remote_id'] ] <=> [ $b['op'], $b['board_id'], $b['remote_id'] ] );

		$plan = [
			'actions'         => $actions,
			'notes'           => $notes,
			'links'           => self::nextLinks( $links, $actions ),
			'unlinked_remote' => array_keys( $skipRemote ),
			'unlinked_board'  => array_keys( $skipBoard ),
			'truncated'       => $truncated,
		];
		$plan['fingerprint'] = self::fingerprint( $plan );

		return $plan;
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	public static function fingerprint( array $plan ): string {
		$canon = [];
		foreach ( is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [] as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$fields = is_array( $action['fields'] ?? null ) ? $action['fields'] : [];
			ksort( $fields );
			$history = is_array( $action['history'] ?? null ) ? $action['history'] : [];
			$canon[] = [
				'op'        => (string) ( $action['op'] ?? '' ),
				'board_id'  => (string) ( $action['board_id'] ?? '' ),
				'remote_id' => (string) ( $action['remote_id'] ?? '' ),
				'title'     => (string) ( $action['title'] ?? '' ),
				'fields'    => $fields,
				'history'   => $history,
			];
		}
		$json = json_encode( $canon );

		return substr( hash( 'sha256', is_string( $json ) ? $json : '[]' ), 0, 16 );
	}

	public static function matches( string $fingerprint, string $yes ): bool {
		return $yes !== '' && hash_equals( $fingerprint, $yes );
	}

	/**
	 * After approval the sync runs on its own. Before that, only the
	 * fingerprint a person just approved may be written.
	 *
	 * @param array<string, mixed> $plan
	 */
	public static function gate( array $plan, bool $live, string $yes ): true|string {
		if ( $live ) {
			return true;
		}
		$print = (string) ( $plan['fingerprint'] ?? '' );
		if ( self::matches( $print, $yes ) ) {
			return true;
		}

		return 'The plan is not the one approved (its fingerprint is now ' . $print . '). Run the dry run again.';
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	public static function words( array $plan, string $service ): string {
		$name    = $service === 'linear' ? 'Linear' : 'Notion';
		$unit    = $service === 'linear' ? 'issue' : 'row';
		$counts  = [];
		foreach ( is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [] as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$op = (string) ( $action['op'] ?? '' );
			$counts[ $op ] = ( $counts[ $op ] ?? 0 ) + 1;
		}
		$lines   = [ 'Dry run for ' . $name . '. Nothing was written.' ];
		$lines[] = self::countLine( (int) ( $counts['create_board'] ?? 0 ), 'create 1 item on Tomagic', 'create items on Tomagic' );
		$lines[] = self::countLine( (int) ( $counts['create_service'] ?? 0 ), 'create 1 ' . $unit . ' on ' . $name, 'create ' . $unit . 's on ' . $name );
		$lines[] = self::countLine( (int) ( $counts['update_board'] ?? 0 ), 'change 1 item on Tomagic', 'change items on Tomagic' );
		$lines[] = self::countLine( (int) ( $counts['update_service'] ?? 0 ), 'change 1 ' . $unit . ' on ' . $name, 'change ' . $unit . 's on ' . $name );
		$lines[] = self::countLine( (int) ( $counts['link'] ?? 0 ), 'link 1 pair', 'link pairs' );
		$unlinks = (int) ( $counts['unlink'] ?? 0 );
		$lines[] = $unlinks === 0
			? 'Unlink none.'
			: 'Unlink ' . $unlinks . '. Nothing is deleted on ' . $name . '.';
		if ( ! empty( $plan['truncated'] ) ) {
			$lines[] = 'The read stopped early, so nothing unseen was unlinked.';
		}
		foreach ( is_array( $plan['notes'] ?? null ) ? $plan['notes'] : [] as $note ) {
			if ( is_string( $note ) && $note !== '' ) {
				$lines[] = $note;
			}
		}
		$lines[] = 'Fingerprint: ' . (string) ( $plan['fingerprint'] ?? '' );
		$lines[] = 'Approve that fingerprint to write this plan. After that, sync runs on its own.';

		return implode( "\n", $lines );
	}

	/**
	 * @param array<int, array<string, mixed>> $actions
	 * @param array<int, string>               $notes
	 * @param array<string, mixed>             $board
	 * @param array<string, mixed>             $remote
	 */
	private static function pair( array &$actions, array &$notes, array $board, array $remote, bool $linked ): void {
		$snapshot = is_array( $board['snapshot'] ?? null ) ? $board['snapshot'] : null;
		$merged   = Rules::merge( $board, $remote, $linked ? null : $snapshot );
		$boardId  = self::text( $board['id'] ?? '' );
		$remoteId = self::text( $remote['id'] ?? '' );
		$title    = self::text( $board['title'] ?? '' );
		$key      = self::text( $board['key'] ?? '' );
		$version  = (int) ( $board['version'] ?? 0 );
		if ( $merged['board'] !== [] || $merged['history'] !== [] ) {
			$actions[] = self::action( 'update_board', $boardId, $remoteId, $title, $merged['board'], $merged['history'], $key, $version, self::text( $remote['url'] ?? '' ), self::text( $remote['alias'] ?? '' ) );
		}
		if ( $merged['service'] !== [] ) {
			$actions[] = self::action( 'update_service', $boardId, $remoteId, $title, $merged['service'], [], $key, $version );
		}
		if ( isset( $merged['held']['status'] ) ) {
			$notes[] = $boardId . ' is ' . $merged['held']['status'] . ' on the service but has no rank, so it stays in the Inbox until someone ranks it.';
		}
	}

	/**
	 * @param array<string, string>            $links
	 * @param array<int, array<string, mixed>> $actions
	 * @return array<string, string>
	 */
	private static function nextLinks( array $links, array $actions ): array {
		foreach ( $actions as $action ) {
			$op      = (string) ( $action['op'] ?? '' );
			$boardId = (string) ( $action['board_id'] ?? '' );
			$remote  = (string) ( $action['remote_id'] ?? '' );
			if ( $op === 'unlink' ) {
				unset( $links[ $boardId ] );
			} elseif ( $op === 'link' && $boardId !== '' && $remote !== '' ) {
				$links[ $boardId ] = $remote;
			}
		}

		return $links;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, string>
	 */
	private static function pushFields( array $row ): array {
		$out = [
			'title'  => self::text( $row['title'] ?? '' ),
			'detail' => self::text( $row['detail'] ?? '' ),
			'status' => self::text( $row['status'] ?? '' ),
			'owner'  => self::text( $row['owner'] ?? '' ),
		];
		$release = self::text( $row['release'] ?? '' );
		$rank    = self::text( $row['rank'] ?? '' );
		if ( $release !== '' ) {
			$out['release'] = $release;
		}
		if ( $rank !== '' ) {
			$out['rank'] = $rank;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, string>
	 */
	private static function pullFields( array $row ): array {
		return [
			'title'   => self::text( $row['title'] ?? '' ),
			'detail'  => self::text( $row['detail'] ?? '' ),
			'status'  => self::text( $row['status'] ?? '' ) !== '' ? self::text( $row['status'] ?? '' ) : 'inbox',
			'owner'   => self::text( $row['owner'] ?? '' ),
			'release' => self::text( $row['release'] ?? '' ),
		];
	}

	/**
	 * @param array<string, string>                              $fields
	 * @param array<int, array{f: string, from: string, to: string}> $history
	 * @return array<string, mixed>
	 */
	private static function action( string $op, string $boardId, string $remoteId, string $title, array $fields = [], array $history = [], string $key = '', int $version = 0, string $url = '', string $alias = '' ): array {
		return [
			'op'        => $op,
			'board_id'  => $boardId,
			'remote_id' => $remoteId,
			'title'     => $title,
			'fields'    => $fields,
			'history'   => $history,
			'key'       => $key,
			'version'   => $version,
			'url'       => $url,
			'alias'     => $alias,
		];
	}

	private static function countLine( int $n, string $one, string $many ): string {
		if ( $n === 0 ) {
			return 'No ' . $many . '.';
		}
		if ( $n === 1 ) {
			return 'Would ' . $one . '.';
		}

		return 'Would ' . $many . ': ' . $n . '.';
	}

	/** @param mixed $value */
	private static function text( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	private static function norm( string $title ): string {
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title );

		return trim( $lower );
	}
}
