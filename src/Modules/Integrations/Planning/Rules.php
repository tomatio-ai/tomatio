<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

/**
 * #992: conflict rules for a planning sync. Plain PHP, no HTTP.
 *
 * The board wins rank and release. The service wins status and
 * assignee. Other fields: the later write wins, and when both sides
 * changed, both versions go in the item's history.
 *
 * A blank release is not a decision, so a service project can fill it
 * once. After the board has a release, the board wins.
 */
final class Rules {

	public const BOARD_WINS = [ 'rank', 'release' ];

	public const SERVICE_WINS = [ 'status', 'owner' ];

	public const LAST_WRITE = [ 'title', 'detail' ];

	/** Service status words, lower-case, to a Tomagic status. */
	public const TO_BOARD = [
		'idea'        => 'inbox',
		'inbox'       => 'inbox',
		'backlog'     => 'inbox',
		'not started' => 'inbox',
		'todo'        => 'ready',
		'to do'       => 'ready',
		'planned'     => 'ready',
		'next'        => 'ready',
		'ready'       => 'ready',
		'blocked'     => 'ready',
		'in progress' => 'building',
		'building'    => 'building',
		'doing'       => 'building',
		'in review'   => 'building',
		'done'        => 'shipped',
		'shipped'     => 'shipped',
		'released'    => 'shipped',
		"won't do"    => 'declined',
		'wont do'     => 'declined',
		'declined'    => 'declined',
		'dropped'     => 'declined',
		'cancelled'   => 'declined',
		'canceled'    => 'declined',
		'parked'      => 'parked',
		'on hold'     => 'parked',
		'archived'    => 'archived',
	];

	/** A Tomagic status to the name we write on a new service row. */
	public const TO_SERVICE = [
		'idea'     => 'Backlog',
		'inbox'    => 'Backlog',
		'ready'    => 'Todo',
		'building' => 'In Progress',
		'shipped'  => 'Done',
		'declined' => 'Canceled',
		'parked'   => 'Canceled',
		'archived' => 'Canceled',
	];

	/**
	 * Sensible defaults. A saved mapping replaces names, not these rules.
	 *
	 * @return array<string, string>
	 */
	public static function defaults( string $service ): array {
		if ( $service === 'linear' ) {
			return [
				'title'   => 'title',
				'detail'  => 'description',
				'status'  => 'state',
				'owner'   => 'assignee',
				'release' => 'project',
				'rank'    => '',
			];
		}

		return [
			'title'   => 'Name',
			'detail'  => 'Detail',
			'status'  => 'Status',
			'owner'   => 'Owner',
			'release' => 'Release',
			'rank'    => 'Rank',
		];
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, string>
	 */
	public static function mapping( string $service, array $overrides ): array {
		$base = self::defaults( $service );
		foreach ( $overrides as $field => $name ) {
			if ( ! is_string( $field ) || ! isset( $base[ $field ] ) || ! is_string( $name ) ) {
				continue;
			}
			$base[ $field ] = trim( $name );
		}

		return $base;
	}

	public static function toBoard( string $status ): string {
		$key = strtolower( trim( $status ) );

		return self::TO_BOARD[ $key ] ?? '';
	}

	public static function toService( string $status ): string {
		return self::TO_SERVICE[ $status ] ?? '';
	}

	/**
	 * What each side should become. Empty strings are "no opinion", not a
	 * clear: a blank service assignee does not wipe the board.
	 *
	 * A ready or building status is not applied while the item has no
	 * rank. The board owns rank, and those statuses cannot sit on the
	 * list without one. `held` names that status so the report can say so.
	 *
	 * @param array<string, mixed>      $board
	 * @param array<string, mixed>      $service
	 * @param array<string, mixed>|null $snapshot Values at the last sync.
	 * @return array{board: array<string, string>, service: array<string, string>, history: array<int, array{f: string, from: string, to: string}>, held: array<string, string>}
	 */
	public static function merge( array $board, array $service, ?array $snapshot ): array {
		$boardOut   = [];
		$serviceOut = [];
		$history    = [];
		$held       = [];

		$boardRank   = self::text( $board['rank'] ?? '' );
		$serviceRank = self::text( $service['rank'] ?? '' );
		if ( array_key_exists( 'rank', $service ) && $boardRank !== '' && $boardRank !== $serviceRank ) {
			$serviceOut['rank'] = $boardRank;
		}

		$boardRelease   = self::text( $board['release'] ?? '' );
		$serviceRelease = self::text( $service['release'] ?? '' );
		if ( $boardRelease !== '' && $boardRelease !== $serviceRelease ) {
			$serviceOut['release'] = $boardRelease;
		} elseif ( $boardRelease === '' && $serviceRelease !== '' ) {
			$boardOut['release'] = $serviceRelease;
		}

		$serviceStatus = self::text( $service['status'] ?? '' );
		$boardStatus   = self::text( $board['status'] ?? '' );
		if ( $serviceStatus !== '' && $serviceStatus !== $boardStatus ) {
			if ( in_array( $serviceStatus, [ 'ready', 'building' ], true ) && $boardRank === '' ) {
				$held['status'] = $serviceStatus;
			} else {
				$boardOut['status'] = $serviceStatus;
			}
		}

		$serviceOwner = self::text( $service['owner'] ?? '' );
		$boardOwner   = self::text( $board['owner'] ?? '' );
		if ( $serviceOwner !== '' && $serviceOwner !== $boardOwner ) {
			$boardOut['owner'] = $serviceOwner;
		}

		$boardAt   = self::text( $board['updated_at'] ?? '' );
		$serviceAt = self::text( $service['updated_at'] ?? '' );
		foreach ( self::LAST_WRITE as $field ) {
			$picked = self::later(
				$field,
				self::text( $board[ $field ] ?? '' ),
				self::text( $service[ $field ] ?? '' ),
				$boardAt,
				$serviceAt,
				$snapshot === null ? null : self::text( $snapshot[ $field ] ?? '' )
			);
			if ( $picked === null ) {
				continue;
			}
			if ( $picked['board'] !== null ) {
				$boardOut[ $field ] = $picked['board'];
			}
			if ( $picked['service'] !== null ) {
				$serviceOut[ $field ] = $picked['service'];
			}
			if ( $picked['history'] !== null ) {
				$history[] = $picked['history'];
			}
		}

		return [
			'board'   => $boardOut,
			'service' => $serviceOut,
			'history' => $history,
			'held'    => $held,
		];
	}

	/**
	 * @return array{board: ?string, service: ?string, history: ?array{f: string, from: string, to: string}}|null
	 */
	private static function later( string $field, string $board, string $service, string $boardAt, string $serviceAt, ?string $snapshot ): ?array {
		if ( $board === $service ) {
			return null;
		}
		$boardChanged   = $snapshot === null || $board !== $snapshot;
		$serviceChanged = $snapshot === null || $service !== $snapshot;
		if ( $snapshot !== null && ! $boardChanged && ! $serviceChanged ) {
			return null;
		}

		$serviceWins = $serviceChanged && ( ! $boardChanged || strcmp( $serviceAt, $boardAt ) > 0 );
		if ( $snapshot !== null && $boardChanged && $serviceChanged && strcmp( $serviceAt, $boardAt ) === 0 ) {
			$serviceWins = false;
		}

		if ( $serviceWins ) {
			return [
				'board'   => $service,
				'service' => null,
				'history' => [ 'f' => $field, 'from' => self::clip( $board ), 'to' => self::clip( $service ) ],
			];
		}

		$history = null;
		if ( $snapshot !== null && $boardChanged && $serviceChanged ) {
			$history = [ 'f' => $field, 'from' => self::clip( $service ), 'to' => self::clip( $board ) ];
		}

		return [
			'board'   => null,
			'service' => $board,
			'history' => $history,
		];
	}

	/** @param mixed $value */
	private static function text( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( $value );
	}

	private static function clip( string $value ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, 200 );
		}

		return substr( $value, 0, 200 );
	}
}
