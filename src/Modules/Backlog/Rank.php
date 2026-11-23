<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

/**
 * #891 (615, 648): an item's place in the ranked list is a fractional
 * index, a string of base-36 digits (0-9, a-z) read as a fraction, that
 * sorts between its neighbours with a plain string compare. Moving an item
 * writes only that item. Now / Next / Later are not a field: two rank
 * strings in meta/project (now_cut, next_cut) divide the one ranked list,
 * so the list and the roadmap can never disagree.
 *
 * A rank never ends in "0", so between() always finds room. Plain PHP.
 */
final class Rank {

	public const DIGITS = '0123456789abcdefghijklmnopqrstuvwxyz';

	public const BANDS = [ 'now', 'next', 'later' ];

	/** The dividers of a new backlog. */
	public const NOW_CUT  = 'c';
	public const NEXT_CUT = 'o';

	private const PATTERN = '/^[0-9a-z]{0,63}[1-9a-z]$/';

	/** @param mixed $rank */
	public static function isRank( $rank ): bool {
		return is_string( $rank ) && preg_match( self::PATTERN, $rank ) === 1;
	}

	/**
	 * A rank strictly between $a and $b (null: the start or the end of the
	 * list). Null when $a is not below $b.
	 */
	public static function between( ?string $a, ?string $b ): ?string {
		$a = $a ?? '';
		if ( ( $a !== '' && ! self::isRank( $a ) ) || ( $b !== null && ! self::isRank( $b ) ) || ( $b !== null && strcmp( $a, $b ) >= 0 ) ) {
			return null;
		}

		return self::midpoint( $a, $b );
	}

	/**
	 * $count ranks, in order, spread evenly between $a and $b, so a first
	 * ranking of many items keeps the strings short.
	 *
	 * @return array<int, string>
	 */
	public static function spread( ?string $a, ?string $b, int $count ): array {
		if ( $count <= 0 ) {
			return [];
		}
		$mid = self::between( $a, $b );
		if ( $mid === null ) {
			return [];
		}
		$left = intdiv( $count - 1, 2 );

		return array_merge( self::spread( $a, $mid, $left ), [ $mid ], self::spread( $mid, $b, $count - 1 - $left ) );
	}

	/**
	 * The band a rank falls in: now above now_cut, next above next_cut,
	 * later below. Null for an unranked item (the Inbox, or shipped).
	 *
	 * @param array<string, mixed> $project meta/project
	 */
	public static function band( ?string $rank, array $project ): ?string {
		if ( ! self::isRank( $rank ) ) {
			return null;
		}
		[ $now, $next ] = self::cuts( $project );
		if ( strcmp( (string) $rank, $now ) < 0 ) {
			return 'now';
		}

		return strcmp( (string) $rank, $next ) < 0 ? 'next' : 'later';
	}

	/**
	 * The two dividers of meta/project, the defaults when unset or broken.
	 *
	 * @param array<string, mixed> $project
	 * @return array{0:string, 1:string}
	 */
	public static function cuts( array $project ): array {
		$now  = self::isRank( $project['now_cut'] ?? null ) ? (string) $project['now_cut'] : self::NOW_CUT;
		$next = self::isRank( $project['next_cut'] ?? null ) ? (string) $project['next_cut'] : self::NEXT_CUT;

		return strcmp( $now, $next ) < 0 ? [ $now, $next ] : [ self::NOW_CUT, self::NEXT_CUT ];
	}

	/**
	 * The open interval a band covers: [after, before], null for the ends.
	 *
	 * @param array<string, mixed> $project
	 * @return array{0:string|null, 1:string|null}
	 */
	public static function bounds( string $band, array $project ): array {
		[ $now, $next ] = self::cuts( $project );

		switch ( $band ) {
			case 'now':
				return [ null, $now ];
			case 'next':
				return [ $now, $next ];
			default:
				return [ $next, null ];
		}
	}

	private static function midpoint( string $a, ?string $b ): string {
		if ( $b !== null ) {
			$n = 0;
			while ( $n < strlen( $b ) && ( $a[ $n ] ?? '0' ) === $b[ $n ] ) {
				++$n;
			}
			if ( $n > 0 ) {
				return substr( $b, 0, $n ) . self::midpoint( (string) substr( $a, $n ), (string) substr( $b, $n ) );
			}
		}

		$da = $a !== '' ? strpos( self::DIGITS, $a[0] ) : 0;
		$db = $b !== null && $b !== '' ? strpos( self::DIGITS, $b[0] ) : strlen( self::DIGITS );
		$da = $da === false ? 0 : $da;
		$db = $db === false ? strlen( self::DIGITS ) : $db;

		if ( $db - $da > 1 ) {
			return self::DIGITS[ intdiv( $da + $db + 1, 2 ) ];
		}
		if ( $b !== null && strlen( $b ) > 1 ) {
			return substr( $b, 0, 1 );
		}

		return self::DIGITS[ $da ] . self::midpoint( (string) substr( $a, 1 ), null );
	}
}
