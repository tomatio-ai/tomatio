<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\DataStore\StoreData;
use WP_Error;

/**
 * #891 (615 sections 624 and 628): a change and its log line in one write.
 * Every change to an item (added, rank, urgency, status, assignee,
 * declined, merged, a source's read or snapshot) writes the item and one
 * line in `log`, so there is never a change without its line or a line
 * without its change:
 *
 *  1. both values are checked first (encoded, within the caps);
 *  2. the item is written with compare-and-set on the version it was read
 *     at (a stale version is the usual 409 with the current document);
 *  3. the line is appended under log/<YYYYMMDDTHHMMSS>-<microseconds>-<random>;
 *  4. if the line can't be written, the item is put back as it was (again
 *     compare-and-set) and the call fails.
 *
 * A line: {at, by, for?, on: B12, what, from?, to?, reason?, via?}. `by`
 * is who made the change (a person, an agent, or a source such as
 * "forgejo-source"), `for` the person an agent acted for. The log is
 * append only: nobody edits or deletes a line.
 */
final class Change {

	public const WHATS = [ 'added', 'rank', 'urgency', 'status', 'assignee', 'owner', 'release', 'declined', 'merged', 'read', 'snapshot', 'linked', 'flagged', 'cut', 'edited', 'comment' ];

	/**
	 * Write $value as items/$key (a new item when $expectedVersion is 0)
	 * and its log line. The line's `on` is filled with the item's B ID.
	 *
	 * @param array<string, mixed>                                                      $value
	 * @param array<string, mixed>                                                      $line  {what, from?, to?, reason?, by?, for?, via?}
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null,link?:int} $by    The store attribution.
	 * @return array{item: array<string, mixed>, log: array<string, mixed>}|WP_Error
	 */
	public static function write( int $artifactId, string $key, array $value, int $expectedVersion, array $line, array $by, ?int $now = null ) {
		$now    = $now ?? time();
		$before = $expectedVersion > 0 ? StoreData::get( $artifactId, 'items', $key ) : null;
		if ( $expectedVersion > 0 && ( $before === null || (int) $before['version'] !== $expectedVersion ) ) {
			return new WP_Error( 'ab_store_version_conflict', __( 'The item was changed by someone else. Read it again and retry.', 'tomatio' ), [ 'status' => 409, 'doc' => $before ] );
		}

		$entry = self::line( $line, $by, $now );
		if ( is_string( $entry ) ) {
			return new WP_Error( 'ab_backlog_invalid', $entry, [ 'status' => 400 ] );
		}
		foreach ( [ $value, $entry ] as $check ) {
			$json = StoreData::encodeValue( $check );
			if ( $json instanceof WP_Error ) {
				return $json;
			}
		}

		$item = StoreData::write( $artifactId, 'items', $key, $value, $by, $expectedVersion > 0 ? 'replace' : 'create', null, $expectedVersion, null, $now );
		if ( $item instanceof WP_Error ) {
			return $item;
		}

		$entry['on'] = is_array( $item['value'] ) && is_string( $item['value']['id'] ?? null ) ? $item['value']['id'] : $key;
		$logged      = StoreData::write( $artifactId, 'log', self::key( $now ), $entry, $by, 'create', null, 0, null, $now );
		if ( $logged instanceof WP_Error ) {
			// Put the item back: no change without its line.
			if ( $before === null ) {
				StoreData::delete( $artifactId, 'items', $key, (int) $item['version'], $by, 'delete', $now );
			} else {
				StoreData::write( $artifactId, 'items', $key, $before['value'], $by, 'replace', null, (int) $item['version'], null, $now );
			}

			return $logged;
		}

		return [ 'item' => $item, 'log' => $logged ];
	}

	/**
	 * A log line about the backlog itself (a divider moved, a source run),
	 * with no item behind it.
	 *
	 * @param array<string, mixed>                                                      $line
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null,link?:int} $by
	 * @return array<string, mixed>|WP_Error
	 */
	public static function note( int $artifactId, string $on, array $line, array $by, ?int $now = null ) {
		$now   = $now ?? time();
		$entry = self::line( $line, $by, $now );
		if ( is_string( $entry ) ) {
			return new WP_Error( 'ab_backlog_invalid', $entry, [ 'status' => 400 ] );
		}
		$entry['on'] = $on;

		return StoreData::write( $artifactId, 'log', self::key( $now ), $entry, $by, 'create', null, 0, null, $now );
	}

	/** The last log key this request made, so the next one sorts after it. */
	private static string $last = '';

	/**
	 * A log key that sorts in write order: 20261109T134501-482113-k3j9.
	 * The microseconds keep two lines of one second in order (history, and
	 * the seen mark's "lines after this one"); within one request each key
	 * also sorts after the last.
	 */
	public static function key( int $now ): string {
		$second = gmdate( 'Ymd\THis', $now );
		$micro  = (int) substr( sprintf( '%.6F', microtime( true ) ), -6 );
		if ( strncmp( self::$last, $second, 15 ) === 0 ) {
			$micro = max( $micro, (int) substr( self::$last, 16, 6 ) + 1 );
		}
		self::$last = $second . '-' . sprintf( '%06d', min( $micro, 999999 ) );

		return self::$last . '-' . strtolower( wp_generate_password( 4, false ) );
	}

	/**
	 * @param array<string, mixed>                                                      $line
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null,link?:int} $by
	 * @return array<string, mixed>|string
	 */
	private static function line( array $line, array $by, int $now ) {
		$what = (string) ( $line['what'] ?? '' );
		if ( ! in_array( $what, self::WHATS, true ) ) {
			return 'Unknown change: ' . $what;
		}

		$out = [
			'at'   => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
			'by'   => self::short( $line['by'] ?? ( $by['name'] ?? '' ), 120 ),
			'what' => $what,
		];
		foreach ( [ 'for', 'reason', 'via' ] as $field ) {
			$text = self::short( $line[ $field ] ?? '', $field === 'reason' ? 500 : 120 );
			if ( $text !== '' ) {
				$out[ $field ] = $text;
			}
		}
		foreach ( [ 'from', 'to' ] as $field ) {
			if ( array_key_exists( $field, $line ) && $line[ $field ] !== null ) {
				$out[ $field ] = is_scalar( $line[ $field ] ) ? self::short( (string) $line[ $field ], 300 ) : $line[ $field ];
			}
		}

		return $out;
	}

	/** @param mixed $text */
	private static function short( $text, int $max ): string {
		return is_scalar( $text ) ? mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) ), 0, $max ) : '';
	}
}
