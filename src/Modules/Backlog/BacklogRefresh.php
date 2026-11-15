<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\DataStore\StoreMode;
use AgentBrain\Modules\Extensions\Package;
use AgentBrain\Modules\Extensions\PageRefresh;

/**
 * #985: when the plugin brings a new backlog page (the bundled package
 * changed), every backlog gets it, as TíoDays do (#818, PageRefresh), and
 * the collections the new page uses: an upgraded brain shows the Ranked
 * view, with its verdicts and comments, with nothing to set up.
 *
 * Collections follow the page: only where the plugin's page is served
 * (refreshed or current), the manifest's collections replace the ones of
 * the same name and the others are kept. A page someone changed keeps its
 * collections too.
 *
 * Once per package change: the manifest's SHA-256 is kept in OPTION and
 * the first request that finds a different one does the work, under a lock.
 */
final class BacklogRefresh {

	/** The fingerprint of the bundled package last rolled out. */
	public const OPTION = 'agent_brain_backlog_page';

	private const LOCK     = 'agent_brain_backlog_page_lock';
	private const LOCK_TTL = 300;

	public static function register(): void {
		add_action( 'init', [ self::class, 'maybeRun' ], 30 );
	}

	public static function maybeRun(): void {
		$print = self::fingerprint();
		if ( $print === '' || get_option( self::OPTION ) === $print ) {
			return;
		}

		if ( ! add_option( self::LOCK, (string) time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK ) < self::LOCK_TTL ) {
				return;
			}
			update_option( self::LOCK, (string) time(), false );
		}

		try {
			$package = Package::bundled( Backlog::PACKAGE );
			if ( $package instanceof Package ) {
				self::run( $package );
				update_option( self::OPTION, $print, true );
			}
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Give every backlog made from $package its page and collections where
	 * the plugin's page is still served.
	 *
	 * @return array<int, string> Artifact id => what happened (refreshed, current, kept_edited, kept_draft).
	 */
	public static function run( Package $package ): array {
		$out         = PageRefresh::run( $package, 'backlog' );
		$collections = StoreMode::normalizeCollections( $package->collections() );
		if ( is_string( $collections ) ) {
			return $out;
		}

		foreach ( $out as $id => $what ) {
			if ( $what !== 'refreshed' && $what !== 'current' ) {
				continue;
			}
			$have = StoreMode::collections( $id );
			$want = $collections + $have;
			ksort( $want );
			if ( $want !== $have ) {
				StoreMode::configureBySystem( $id, $want );
			}
		}

		return $out;
	}

	/** The bundled package manifest's SHA-256, or '' when it cannot be read. */
	public static function fingerprint(): string {
		$path = Package::bundledDir( Backlog::PACKAGE ) . '/' . Package::MANIFEST;

		return is_readable( $path ) ? (string) hash_file( 'sha256', $path ) : '';
	}
}
