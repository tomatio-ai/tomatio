<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\DataStore\StoreData;

/**
 * Dennis, 14 Nov: every change to an item's release, status or assignee is
 * kept on the item, with when and who, so its panel shows one Activity
 * feed: `hist: [{at, by, f, from, to}]`, oldest first, the newest MAX kept.
 *
 * - f is `release` (a version, "next", "later" or '' for the Inbox),
 *   `status` (the item's status, such as shipped, or the board's mark:
 *   '' Open, keep, done, drop), `owner` (the assignee) or `urgent`
 *   (#1004: a project admin's flag, 'true' or 'false').
 * - Item writes (Change::write, a promote, a restore, a release rolling
 *   over, an agent closing its item) get their entries as they are stored
 *   (the store_value filter). A write that leaves `hist` out keeps the
 *   history the item had.
 * - The board's marks and releases live in verdicts/<item key>; a change
 *   there is added to the item right after it is stored.
 * - `by` is a display name: the person (or agent) behind the change.
 */
final class History {

	public const MAX    = 60;
	public const FIELDS = [ 'release', 'status', 'owner', 'urgent' ];

	/** Verdict requests, not marks: never history. */
	private const REQUESTS = [ 'promote', 'reject', 'restore' ];

	/** Who is behind the item write in progress (a login or a name), set by Change::write. */
	private static string $actor = '';

	/** @var array<string, array<int, array<string, string>>> "artifact|key" => entries waiting for the verdict write */
	private static array $pending = [];

	public static function register(): void {
		add_filter( 'tomatio/store_value', [ self::class, 'filter' ], 20, 6 );
		add_action( 'tomatio/store_written', [ self::class, 'written' ], 5, 5 );
	}

	/** Change::write names who is behind the write (the person an agent acts for, else the writer). */
	public static function actor( string $who ): void {
		self::$actor = $who;
	}

	/**
	 * @param mixed                     $value
	 * @param mixed                     $before
	 * @param array<string, mixed>|null $by
	 * @return mixed
	 */
	public static function filter( $value, int $artifactId, string $collection, string $key, $before, $by = null ) {
		if ( ! is_array( $value ) || ! in_array( $collection, [ 'items', 'verdicts' ], true ) || ! Backlog::isBacklog( $artifactId ) ) {
			return $value;
		}
		$before = is_array( $before ) ? $before : null;
		$name   = is_array( $by ) && is_string( $by['name'] ?? null ) ? $by['name'] : '';

		if ( $collection === 'items' ) {
			if ( $before === null ) {
				return $value;
			}
			$hist = is_array( $value['hist'] ?? null ) ? $value['hist'] : ( is_array( $before['hist'] ?? null ) ? $before['hist'] : [] );
			$who  = self::display( self::$actor !== '' ? self::$actor : $name );
			foreach ( [ 'status', 'owner' ] as $field ) {
				$from = self::str( $before[ $field ] ?? ( $field === 'status' ? 'inbox' : '' ) );
				$to   = self::str( $value[ $field ] ?? ( $field === 'status' ? 'inbox' : '' ) );
				if ( $from !== $to ) {
					$hist[] = self::entry( $who, $field, $from, $to );
				}
			}
			// #1004: the Urgent flag, 'true' or 'false'.
			$wasUrgent = ( $before['urgent'] ?? false ) === true;
			$isUrgent  = ( $value['urgent'] ?? false ) === true;
			if ( $wasUrgent !== $isUrgent ) {
				$hist[] = self::entry( $who, 'urgent', $wasUrgent ? 'true' : 'false', $isUrgent ? 'true' : 'false' );
			}
			if ( $hist !== [] ) {
				$value['hist'] = array_values( array_slice( $hist, -self::MAX ) );
			}

			return $value;
		}

		// verdicts: what the board marked, or the release it chose.
		$item = StoreData::get( $artifactId, 'items', $key );
		if ( $item === null || ( is_array( $item['value'] ) && ( $item['value']['status'] ?? '' ) === 'idea' ) ) {
			return $value;
		}
		$who     = self::display( is_string( $value['by'] ?? null ) && $value['by'] !== '' ? $value['by'] : $name );
		$entries = [];
		$from    = self::str( $before['status'] ?? '' );
		$to      = self::str( $value['status'] ?? '' );
		if ( $from !== $to && ! in_array( $from, self::REQUESTS, true ) && ! in_array( $to, self::REQUESTS, true ) ) {
			$entries[] = self::entry( $who, 'status', $from, $to );
		}
		$had = $before !== null && array_key_exists( 'release', $before ) ? (string) Releases::normalize( $before['release'] ) : Releases::setOf( $item, [] );
		if ( array_key_exists( 'release', $value ) && (string) Releases::normalize( $value['release'] ) !== $had ) {
			$entries[] = self::entry( $who, 'release', $had, (string) Releases::normalize( $value['release'] ) );
		}
		if ( $entries !== [] ) {
			self::$pending[ $artifactId . '|' . $key ] = $entries;
		}

		return $value;
	}

	/**
	 * After a verdict is stored: its entries go on the item.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $by
	 */
	public static function written( int $artifactId, string $collection, string $key, $value, array $by ): void {
		$slot = $artifactId . '|' . $key;
		if ( $collection !== 'verdicts' || ! isset( self::$pending[ $slot ] ) ) {
			return;
		}
		$entries = self::$pending[ $slot ];
		unset( self::$pending[ $slot ] );
		self::add( $artifactId, $key, $entries );
	}

	/**
	 * Append entries to an item's history (a compare-and-set write, tried
	 * twice).
	 *
	 * @param array<int, array<string, string>> $entries
	 */
	public static function add( int $artifactId, string $key, array $entries ): void {
		for ( $try = 0; $try < 2; $try++ ) {
			$doc = StoreData::get( $artifactId, 'items', $key );
			if ( $doc === null || ! is_array( $doc['value'] ) ) {
				return;
			}
			$value         = $doc['value'];
			$hist          = is_array( $value['hist'] ?? null ) ? $value['hist'] : [];
			$value['hist'] = array_values( array_slice( array_merge( $hist, $entries ), -self::MAX ) );
			$done          = StoreData::write( $artifactId, 'items', $key, $value, StoreData::anonymousAttribution(), 'replace', null, (int) $doc['version'] );
			if ( ! $done instanceof \WP_Error ) {
				return;
			}
		}
	}

	/** @return array{at: string, by: string, f: string, from: string, to: string} */
	private static function entry( string $by, string $field, string $from, string $to ): array {
		return [ 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'by' => $by, 'f' => $field, 'from' => $from, 'to' => $to ];
	}

	/** A login as the person's display name; anything else as it is. */
	private static function display( string $who ): string {
		$who = trim( $who );
		if ( $who === '' ) {
			return '';
		}
		$user = get_user_by( 'login', $who );

		return mb_substr( $user instanceof \WP_User ? (string) $user->display_name : $who, 0, 80 );
	}

	/** @param mixed $value */
	private static function str( $value ): string {
		return is_string( $value ) ? $value : '';
	}
}
