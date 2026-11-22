<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\DataStore\StoreData;
use WP_Error;

/**
 * #1099: Mark urgent and the estimate, from Magic Backlog's item panel.
 *
 * The page writes only its store (#722), so it asks: a member writes
 * edits/<item key> with `urgent` (true or false) and/or `estimate`
 * ({h, tok, dec}; null clears it) and/or `who_can` (human, go, ai, or ''
 * to clear it). The brain makes the change with
 * Project::edit as the account the store recorded, so the rules are the
 * MCP ones: anyone who may write the backlog sets the estimate, only a
 * project admin (Project::decidesIdeas) marks an item urgent, an idea is
 * neither. The change goes in the item's history and the log. On success
 * the request is removed; otherwise it stays, marked `refused` with the
 * reason, so the page can say why.
 */
final class PageEdits {

	public const COLLECTION = 'edits';

	private static bool $busy = false;

	public static function register(): void {
		add_action( 'tomatio/store_written', [ self::class, 'written' ], 20, 5 );
	}

	/**
	 * @param mixed                $value
	 * @param array<string, mixed> $by
	 */
	public static function written( int $artifactId, string $collection, string $key, $value, array $by ): void {
		if ( self::$busy || $collection !== self::COLLECTION || ! is_array( $value ) || ! empty( $value['refused'] ) || ! Backlog::isBacklog( $artifactId ) ) {
			return;
		}
		$userId = empty( $by['anonymous'] ) ? (int) ( $by['id'] ?? 0 ) : 0;

		self::$busy = true;
		try {
			$done = self::apply( $artifactId, $key, $value, $userId );
			if ( $done instanceof WP_Error ) {
				StoreData::set( $artifactId, self::COLLECTION, $key, [ 'refused' => self::reason( $done ) ] + $value, StoreData::anonymousAttribution() );

				return;
			}
			StoreData::delete( $artifactId, self::COLLECTION, $key, null, StoreData::anonymousAttribution() );
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * @param array<string, mixed> $value
	 * @return true|WP_Error
	 */
	private static function apply( int $artifactId, string $key, array $value, int $userId ) {
		if ( StoreData::get( $artifactId, 'items', $key ) === null ) {
			return new WP_Error( 'ab_not_found', 'item' );
		}
		if ( $userId <= 0 || ! Backlog::canWrite( $userId, $artifactId ) ) {
			return new WP_Error( 'ab_forbidden', 'write' );
		}
		$input = [ 'item' => $key ];
		if ( array_key_exists( 'urgent', $value ) ) {
			$input['urgent'] = $value['urgent'];
		}
		if ( array_key_exists( 'estimate', $value ) ) {
			$input['estimate'] = $value['estimate'];
		}
		if ( array_key_exists( 'who_can', $value ) ) {
			$input['who_can'] = $value['who_can'];
		}
		if ( count( $input ) === 1 ) {
			return new WP_Error( 'ab_invalid_input', __( 'Say what to change: urgent, estimate or who_can.', 'tomatio' ) );
		}
		$done = Project::edit( $artifactId, $input, Project::callerFor( $userId, $artifactId ) );

		return $done instanceof WP_Error ? $done : true;
	}

	/** The refusal the page shows: 'item' and 'write' as for assign, else the message. */
	private static function reason( WP_Error $error ): string {
		$code = (string) $error->get_error_code();
		if ( $code === 'ab_not_found' || $code === 'ab_forbidden' ) {
			return (string) $error->get_error_message();
		}
		if ( $code === 'ab_backlog_admins_only' ) {
			return 'admin';
		}

		return mb_substr( (string) $error->get_error_message(), 0, 300 );
	}
}
