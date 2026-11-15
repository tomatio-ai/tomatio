<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\DataStore\StoreData;

/**
 * #995: ideas on Tomagic, from the page.
 *
 * The page writes only its store (the extension contract, #722); items are
 * read-only to it. So:
 *
 * - A member logs an idea by adding a document to `ideas` ({title,
 *   note?}; an idea has no release, Dennis 14 Nov). If the account the store recorded may write the backlog,
 *   it becomes an item with status `idea` (Project::log, idea: true), logged
 *   by that account, and the `ideas` document is removed. Otherwise the
 *   document stays, marked `refused`, so the page can say why.
 * - A member asks to promote or reject an idea by setting its verdict
 *   (verdicts/<item key>, status `promote` or `reject`). If the account may
 *   decide on ideas (Project::decidesIdeas: a curator, manager or the
 *   backlog's manager, never an agent on its own), Project::idea() does it.
 *   Either way the verdict's status goes back to "", so the request never
 *   lingers.
 * - Anyone who may write the backlog restores an item from the Archive
 *   (verdict status `restore`): a rejected idea is an idea again, a
 *   declined, parked or archived item goes back to the Inbox.
 *
 * Over MCP the same happens with tomatio-project-log (idea: true) and
 * tomatio-project-idea.
 */
final class Ideas {

	private static bool $busy = false;

	public static function register(): void {
		add_action( 'tomatio/store_written', [ self::class, 'written' ], 20, 5 );
	}

	/**
	 * @param mixed                $value
	 * @param array<string, mixed> $by
	 */
	public static function written( int $artifactId, string $collection, string $key, $value, array $by ): void {
		if ( self::$busy || ! in_array( $collection, [ 'ideas', 'verdicts' ], true ) || ! is_array( $value ) || ! Backlog::isBacklog( $artifactId ) ) {
			return;
		}
		$userId = empty( $by['anonymous'] ) ? (int) ( $by['id'] ?? 0 ) : 0;

		self::$busy = true;
		try {
			if ( $collection === 'ideas' ) {
				self::ingest( $artifactId, $key, $value, $userId );
			} elseif ( in_array( $value['status'] ?? '', [ 'promote', 'reject', 'drop', 'restore' ], true ) ) {
				self::decide( $artifactId, $key, $value, $userId );
			}
		} finally {
			self::$busy = false;
		}
	}

	/** @param array<string, mixed> $value */
	private static function ingest( int $artifactId, string $key, array $value, int $userId ): void {
		if ( ! empty( $value['refused'] ) ) {
			return;
		}
		if ( $userId <= 0 || ! Backlog::canWrite( $userId, $artifactId ) ) {
			StoreData::set( $artifactId, 'ideas', $key, [ 'refused' => 'write' ] + $value, StoreData::anonymousAttribution() );

			return;
		}

		$input = [
			'idea'   => true,
			'title'  => is_string( $value['title'] ?? null ) ? $value['title'] : '',
			'detail' => is_string( $value['note'] ?? null ) ? $value['note'] : '',
		];
		$done = Project::log( $artifactId, $input, Project::callerFor( $userId, $artifactId ) );
		if ( $done instanceof \WP_Error ) {
			StoreData::set( $artifactId, 'ideas', $key, [ 'refused' => $done->get_error_message() ] + $value, StoreData::anonymousAttribution() );

			return;
		}
		StoreData::delete( $artifactId, 'ideas', $key, null, StoreData::anonymousAttribution() );
	}

	/** @param array<string, mixed> $value */
	private static function decide( int $artifactId, string $key, array $value, int $userId ): void {
		$status = (string) $value['status'];
		$doc    = StoreData::get( $artifactId, 'items', $key );
		$caller = $userId > 0 ? Project::callerFor( $userId, $artifactId ) : null;
		if ( $status === 'restore' ) {
			// Restore: anyone who may write the backlog (Project::idea checks it is in the Archive).
			if ( $doc !== null && $caller !== null && Backlog::canWrite( $userId, $artifactId ) ) {
				Project::idea( $artifactId, [ 'item' => $key, 'decision' => 'restore' ], $caller );
			}
			$now = StoreData::get( $artifactId, 'verdicts', $key );
			if ( $now !== null && is_array( $now['value'] ) && ( $now['value']['status'] ?? '' ) === 'restore' ) {
				StoreData::set( $artifactId, 'verdicts', $key, [ 'status' => '' ] + $now['value'], StoreData::anonymousAttribution() );
			}

			return;
		}
		if ( $doc === null || ! is_array( $doc['value'] ) || ( $doc['value']['status'] ?? '' ) !== 'idea' ) {
			// Not an idea: "drop" is Not needed on an item; leave it. "promote" and "reject" mean nothing there.
			if ( $status !== 'drop' ) {
				StoreData::set( $artifactId, 'verdicts', $key, [ 'status' => '' ] + $value, StoreData::anonymousAttribution() );
			}

			return;
		}

		if ( $caller !== null && Project::decidesIdeas( $artifactId, $caller ) ) {
			Project::idea( $artifactId, [ 'item' => $key, 'decision' => $status ], $caller );
		}
		StoreData::set( $artifactId, 'verdicts', $key, [ 'status' => '' ] + $value, StoreData::anonymousAttribution() );
	}
}
