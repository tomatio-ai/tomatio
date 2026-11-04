<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Extensions\Package;
use AgentBrain\Modules\Extensions\PageMaker;

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
	 * is still served.
	 *
	 * @return array<int, string> Artifact id => what happened (refreshed, current, kept_edited, kept_draft).
	 */
	public static function run( Package $package ): array {
		$ids = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => PageMaker::META_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- once per plugin update, one row per person.
				'meta_value'     => $package->id(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			]
		);

		$want = [];
		foreach ( $package->htmlFiles() as $file ) {
			$want[ $file['path'] ] = (string) base64_decode( $file['content'], true );
		}
		ksort( $want );

		$out = [];
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = self::refresh( (int) $id, $package, $want );
		}

		return $out;
	}

	/** @param array<string, string> $want path => bytes */
	private static function refresh( int $id, Package $package, array $want ): string {
		$served = HtmlFiles::servedNumber( $id );
		$row    = $served > 0 ? HtmlFiles::version( $id, $served ) : null;
		if ( ! HtmlFiles::isHtml( $id ) || $row === null ) {
			return self::kept( $id, 'kept_edited', 'no served version' );
		}
		if ( HtmlFiles::latestNumber( $id ) !== $served ) {
			return self::kept( $id, 'kept_draft', 'a newer version is waiting for approval' );
		}
		if ( $row['created_by'] !== 0 ) {
			return self::kept( $id, 'kept_edited', 'the served version was put by user ' . $row['created_by'] );
		}

		$have = [];
		foreach ( $row['files'] as $path => $file ) {
			$have[ (string) $path ] = HtmlFiles::bytes( $file );
		}
		ksort( $have );
		if ( $have === $want ) {
			update_post_meta( $id, PageMaker::META_VERSION, $package->version() );

			return 'current';
		}

		$put = HtmlFiles::putBySystem( $id, $package->htmlFiles(), $package->name() . ' ' . $package->version() . ', the new page bundled with the plugin' );
		if ( $put instanceof \WP_Error ) {
			return self::kept( $id, 'kept_edited', $put->get_error_code() );
		}
		$approved = HtmlFiles::approveBySystem( $id, (int) $put['version'] );
		if ( $approved instanceof \WP_Error ) {
			return self::kept( $id, 'kept_draft', $approved->get_error_code() );
		}
		update_post_meta( $id, PageMaker::META_VERSION, $package->version() );

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => 0,
				'event'       => 'tioday.page_refreshed',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $id,
				'meta'        => [ 'version' => (int) $put['version'], 'package_version' => $package->version() ],
			]
		);

		return 'refreshed';
	}

	private static function kept( int $id, string $what, string $why ): string {
		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => 0,
				'event'       => 'tioday.page_kept',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $id,
				'meta'        => [ 'result' => $what, 'why' => $why ],
			]
		);

		return $what;
	}

	/** The bundled package manifest's SHA-256, or '' when it cannot be read. */
	public static function fingerprint(): string {
		$path = Package::bundledDir( TioDayDefault::PACKAGE ) . '/' . Package::MANIFEST;

		return is_readable( $path ) ? (string) hash_file( 'sha256', $path ) : '';
	}
}
