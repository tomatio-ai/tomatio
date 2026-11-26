<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

/**
 * #992: conflict rules for a planning sync. Plain PHP, no HTTP.
 *
 * The board wins rank and release. The service wins status and
 * assignee when it changed them. Status pulls only on a real change on
 * the service since the last sync, and a board status change is pushed
 * (B237). Other fields: the later write wins, and when both sides
 * changed, both versions go in the item's history.
 *
 * A blank release is not a decision, so a service project can fill it
 * once. After the board has a release, the board wins.
 */
final class Rules {

	public const BOARD_WINS = [ 'rank', 'release' ];

	public const SERVICE_WINS = [ 'status', 'owner' ];

	public const LAST_WRITE = [ 'title', 'detail' ];

	/** Service status words, lower-case, to a Magic Backlog status. */
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

	/** A Magic Backlog status to the name we write on the service. */
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
	 * B237: a board status and a status read back from the service are in
	 * step when they are the same, or when the board status, written to
	 * the service, reads back as that one. Mapping is lossy (parked and
	 * archived both go to Canceled, which reads back as declined), so
	 * parked on the board against declined on the service is no change.
	 */
	public static function inStep( string $board, string $service ): bool {
		if ( $board === $service ) {
			return true;
		}
		$written = self::toService( $board );

		return $written !== '' && self::toBoard( $written ) === $service;
	}

	/**
	 * B237: the service's own name for a board status. Prefer a name that
	 * reads back as exactly that status (Notion's "Not started" for inbox,
	 * "On hold" for parked), then the usual name (Rules::TO_SERVICE). With
	 * no names known, the usual name. '' when the service has neither.
	 *
	 * @param array<int|string, mixed> $names The service's names, as keys (name => id) or values.
	 */
	public static function serviceName( string $status, array $names ): string {
		$usual = self::toService( $status );
		if ( $usual === '' ) {
			return '';
		}
		$known = [];
		foreach ( $names as $key => $value ) {
			$name = is_string( $key ) ? $key : ( is_string( $value ) ? $value : '' );
			if ( $name !== '' ) {
				$known[] = $name;
			}
		}
		if ( $known === [] ) {
			return $usual;
		}
		$exact = [];
		foreach ( $known as $name ) {
			if ( self::toBoard( $name ) === $status ) {
				$exact[] = $name;
			}
		}
		foreach ( $exact as $name ) {
			if ( strtolower( $name ) === strtolower( $usual ) ) {
				return $name;
			}
		}
		if ( $exact !== [] ) {
			return $exact[0];
		}
		foreach ( $known as $name ) {
			if ( strtolower( $name ) === strtolower( $usual ) ) {
				return $name;
			}
		}

		return '';
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

		// B237: status pulls only when the service really changed it since
		// the last sync. An unchanged service value never overwrites the
		// board, and a board change is pushed. The service still wins
		// when both sides changed.
		$serviceStatus = self::text( $service['status'] ?? '' );
		$boardStatus   = self::text( $board['status'] ?? '' );
		$snapStatus    = $snapshot === null ? null : self::text( $snapshot['status'] ?? '' );
		if ( $snapStatus === '' ) {
			$snapStatus = null;
		}
		$serviceMoved = $serviceStatus !== '' && (
			$snapStatus === null
				? ! self::inStep( $boardStatus, $serviceStatus )
				: ! self::inStep( $snapStatus, $serviceStatus )
		);
		$boardMoved = $snapStatus !== null && $boardStatus !== '' && $boardStatus !== $snapStatus;
		if ( $serviceMoved && ! self::inStep( $boardStatus, $serviceStatus ) ) {
			if ( in_array( $serviceStatus, [ 'ready', 'building' ], true ) && $boardRank === '' ) {
				$held['status'] = $serviceStatus;
			} else {
				$boardOut['status'] = $serviceStatus;
			}
		} elseif ( ! $serviceMoved && $boardMoved && $boardStatus !== $serviceStatus && self::toService( $boardStatus ) !== '' ) {
			$serviceOut['status'] = $boardStatus;
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
