<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Extensions\Package;
use AgentBrain\Modules\Extensions\PageRefresh;

/**
 * #818: when the plugin brings a new TíoDay page (the bundled package
 * changed), every TíoDay made from the package gets it, put and approved
 * by the plugin as PageMaker does, so an update reaches existing people
 * and not only new ones.
 *
 * Only where the plugin's own page is still the one served: the served
 * version was put by the plugin (created_by 0) and no newer version waits
 * (someone's draft). A page a person or an AI changed keeps what it has;
 * the audit log says which and why.
 *
 * #1088: collections follow the page, but only new ones: a collection the
 * package lists and the TíoDay lacks is added; none it has is changed.
 *
 * It runs once per package change: a fingerprint of the package manifest
 * (which holds every file's SHA-256) is kept in OPTION, and the first
 * request that finds a different one does the work, under a lock.
 */
final class TioDayRefresh {

	/** The fingerprint of the bundled package last rolled out. */
	public const OPTION = 'agent_brain_tioday_page';

	private const LOCK     = 'agent_brain_tioday_page_lock';
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
			$package = Package::bundled( TioDayDefault::PACKAGE );
			if ( $package instanceof Package ) {
				self::run( $package );
				update_option( self::OPTION, $print, true );
			}
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Give every TíoDay made from $package its page where the plugin's page
	 * is still served (PageRefresh).
	 *
	 * @return array<int, string> Artifact id => what happened (refreshed, current, kept_edited, kept_draft).
	 */
	public static function run( Package $package ): array {
		$out = PageRefresh::run( $package, 'tioday' );

		// #1088: a collection the new page uses and the TíoDay does not have
		// yet (answers) is added where the plugin's page is served. The ones
		// it has stay exactly as they are.
		$collections = StoreMode::normalizeCollections( $package->collections() );
		if ( is_string( $collections ) ) {
			return $out;
		}
		foreach ( $out as $id => $what ) {
			$have = StoreMode::collections( $id );
			if ( ( $what !== 'refreshed' && $what !== 'current' ) || $have === [] ) {
				continue;
			}
			$want = $have + $collections;
			if ( count( $want ) !== count( $have ) ) {
				StoreMode::configureBySystem( $id, $want );
			}
		}

		return $out;
	}

	/** The bundled package manifest's SHA-256, or '' when it cannot be read. */
	public static function fingerprint(): string {
		$path = Package::bundledDir( TioDayDefault::PACKAGE ) . '/' . Package::MANIFEST;

		// #994: the binding round rides on the fingerprint, so 1.10 runs it once on brains whose package did not change.
		return is_readable( $path ) ? (string) hash_file( 'sha256', $path ) . ':' . Package::BINDING_ROUND : '';
	}
}
