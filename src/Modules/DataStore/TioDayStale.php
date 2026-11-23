<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\Sharing\ShareLinks;

/**
 * #1088: an in-flight item nobody has updated for TioDay::STALE_DAYS asks
 * "Still going?".
 *
 * On the page, with Clear and Keep. In-flight items are read-only to the
 * page (the extension contract, #722), so the page writes the person's
 * answer to `answers/<in-flight key>` ({status: clear|keep}) and the brain
 * acts on it: Clear removes the item, Keep stamps it (kept_at, kept_by) so
 * it is current again for STALE_DAYS. Either way the answer is removed, so
 * nothing lingers. Only the TíoDay's person, or someone who keeps it (its
 * lead, a curator or a manager), is listened to.
 *
 * In the briefing: the agent that keeps a TíoDay hears about the stale
 * items it wrote (or that name it as who) first, before the rest of the
 * TíoDay block, so the next session clears or refreshes them.
 */
final class TioDayStale {

	/** The collection the page writes its answers to. */
	public const COLLECTION = 'answers';

	/** Stale items named in the briefing; the rest are counted. */
	public const LISTED = 8;

	private static bool $busy = false;

	public static function register(): void {
		add_action( 'tomatio/store_written', [ self::class, 'written' ], 20, 5 );
	}

	/**
	 * tomatio/store_written: an answer from the page.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $by
	 */
	public static function written( int $artifactId, string $collection, string $key, $value, array $by ): void {
		if ( self::$busy || $collection !== self::COLLECTION || ! is_array( $value ) || ! TioDayIds::isTioDay( $artifactId ) ) {
			return;
		}
		$status = is_string( $value['status'] ?? null ) ? $value['status'] : '';
		if ( ! in_array( $status, [ 'clear', 'keep' ], true ) ) {
			return;
		}
		$userId = empty( $by['anonymous'] ) ? (int) ( $by['id'] ?? 0 ) : 0;

		self::$busy = true;
		try {
			if ( self::listensTo( $userId, $artifactId ) ) {
				self::answer( $artifactId, $key, $status, $userId );
			}
			// Acted on or not, the answer never lingers.
			StoreData::delete( $artifactId, self::COLLECTION, $key, null, StoreData::anonymousAttribution() );
		} finally {
			self::$busy = false;
		}
	}

	/** Whether $userId may answer for this TíoDay: its person, or someone who keeps it. */
	public static function listensTo( int $userId, int $artifactId ): bool {
		if ( $userId <= 0 ) {
			return false;
		}

		return (int) get_post_meta( $artifactId, HomeWorkspace::META_OWNER, true ) === $userId || ShareLinks::canManage( $userId, $artifactId );
	}

	/**
	 * Clear the in-flight item $key, or keep it: stamp it so it is current
	 * again. False when there is no such item.
	 */
	public static function answer( int $artifactId, string $key, string $status, int $userId ): bool {
		$doc = StoreData::get( $artifactId, 'inflight', $key );
		if ( $doc === null ) {
			return false;
		}
		$by = StoreData::attributionFromUser( $userId, $artifactId );
		if ( $status === 'clear' ) {
			$gone = StoreData::delete( $artifactId, 'inflight', $key, null, $by );
		} else {
			$value = is_array( $doc['value'] ) && ! array_is_list( $doc['value'] ) ? $doc['value'] : [];
			$kept  = StoreData::set( $artifactId, 'inflight', $key, [ 'kept_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'kept_by' => TioDay::name( $userId ) ] + $value, $by, null, (int) $doc['version'] );
			$gone  = ! $kept instanceof \WP_Error;
		}
		if ( $gone ) {
			\AgentBrain\Core\Hooks::action(
				'audit',
				[
					'actor_id'    => $userId,
					'event'       => $status === 'clear' ? 'tioday.stale_cleared' : 'tioday.stale_kept',
					'status'      => 'ok',
					'object_type' => 'artifact',
					'object_id'   => (string) $artifactId,
					'meta'        => [ 'item' => $key ],
				]
			);
		}

		return $gone;
	}

	/**
	 * The stale in-flight items $agentId keeps on this TíoDay: the ones it
	 * wrote, or that name it as who. Rows as TioDay::stale() gives them.
	 *
	 * @return array<int, array{ref:string, id:string|null, title:string, updated_at:string, days:int}>
	 */
	public static function mine( int $artifactId, int $agentId, ?int $now = null ): array {
		$now   = $now ?? time();
		$names = self::names( $agentId );
		$out   = [];
		foreach ( StoreData::list( $artifactId, 'inflight' ) as $doc ) {
			$value = is_array( $doc['value'] ) ? $doc['value'] : [];
			$wrote = false;
			foreach ( [ 'created_by', 'updated_by' ] as $field ) {
				$wrote = $wrote || ( is_array( $doc[ $field ] ?? null ) && (int) ( $doc[ $field ]['id'] ?? 0 ) === $agentId );
			}
			$who = is_string( $value['who'] ?? null ) ? TioDayAssignee::normalize( $value['who'] ) : '';
			if ( ! $wrote && ( $who === '' || ! in_array( $who, $names, true ) ) ) {
				continue;
			}
			$row = TioDay::staleRow( 'f:' . $doc['key'], is_string( $value[ TioDayIds::FIELD ] ?? null ) ? $value[ TioDayIds::FIELD ] : null, (string) ( $value['title'] ?? $doc['key'] ), (string) $doc['updated_at'], $now );
			if ( $row !== null ) {
				$out[] = $row;
			}
		}
		usort( $out, static fn ( array $a, array $b ): int => $b['days'] <=> $a['days'] );

		return $out;
	}

	/**
	 * The line that opens the TíoDay block of the briefing and the MCP
	 * instructions when $agentId keeps stale in-flight items there; ''
	 * when it keeps none.
	 */
	public static function briefing( int $artifactId, int $owner, int $agentId, ?int $now = null ): string {
		$stale = $agentId > 0 ? self::mine( $artifactId, $agentId, $now ) : [];
		if ( $stale === [] ) {
			return '';
		}

		$listed = [];
		foreach ( array_slice( $stale, 0, self::LISTED ) as $row ) {
			$listed[] = sprintf(
				/* translators: 1: a short ID, such as F12; 2: the item's title; 3: days. */
				_n( '%1$s “%2$s” (%3$d day)', '%1$s “%2$s” (%3$d days)', $row['days'], 'tomatio' ),
				$row['id'] ?? $row['ref'],
				mb_substr( $row['title'], 0, 80 ),
				$row['days']
			);
		}
		if ( count( $stale ) > self::LISTED ) {
			/* translators: %d: how many more items. */
			$listed[] = sprintf( __( 'and %d more', 'tomatio' ), count( $stale ) - self::LISTED );
		}

		return sprintf(
			/* translators: 1: the person's name, 2: days, 3: the items, such as F12 “Ship the brief” (5 days). */
			__( 'TíoDay, first: these in-flight items of yours on %1$s\'s TíoDay have had no update for %2$d days or more: %3$s. Before anything else, check each one: clear it when the work landed or stopped (tomatio-tioday-inflight with its ID and clear: true, or tomatio-tioday-done with clear_inflight), and update it when it is still going.', 'tomatio' ),
			TioDay::name( $owner ),
			TioDay::STALE_DAYS,
			implode( ', ', $listed )
		);
	}

	/**
	 * The names an account goes by on an in-flight item's who, normalized.
	 *
	 * @return array<int, string>
	 */
	private static function names( int $userId ): array {
		$user = $userId > 0 ? get_userdata( $userId ) : false;
		if ( ! $user instanceof \WP_User ) {
			return [];
		}

		return array_values( array_filter( array_unique( array_map( [ TioDayAssignee::class, 'normalize' ], [ (string) $user->display_name, (string) $user->user_login, TioDay::name( $userId ) ] ) ) ) );
	}
}
