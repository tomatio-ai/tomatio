<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

/**
 * #994 (RFC artifact-extensions, section 4): the extensions a manager
 * installed on this brain from an archive, each with its last releases.
 *
 * One option per extension (not autoloaded, ab_ so uninstall removes it with the
 * plugin's other options) holds the record: who installed it and when,
 * the source pinned at first install (decision 4), and the files of the
 * last KEEP releases as base64, so pages can be served and rolled back
 * without the archive. Nothing is written to disk: files under uploads/
 * would be served from the brain's own origin, outside the sandbox.
 *
 * The bundled packages (TíoDay, Tomagic) are not recorded here: their
 * source is the plugin (Package::bundled).
 */
final class Installed {

	/** id => installed version, for listing without loading every record. */
	public const INDEX = 'ab_extensions_installed';

	private const PREFIX = 'ab_extension_';

	/** Releases kept per extension (RFC section 6). */
	public const KEEP = 5;

	/** @return array<string, string> id => version */
	public static function index(): array {
		$index = get_option( self::INDEX, [] );

		return is_array( $index ) ? array_map( 'strval', $index ) : [];
	}

	public static function has( string $id ): bool {
		return isset( self::index()[ $id ] );
	}

	/**
	 * @return array{id:string, name:string, version:string, publisher:string, source:string, installed_by:int, installed_at:string, updated_at:string, flags?:array<int, string>, releases:array<string, array{manifest:array<string, mixed>, files:array<string, string>}>}|null
	 */
	public static function record( string $id ): ?array {
		$record = self::has( $id ) ? get_option( self::option( $id ) ) : null;

		return is_array( $record ) && isset( $record['releases'] ) ? $record : null;
	}

	/** The installed release of $id, verified again, or null. */
	public static function package( string $id, string $version = '' ): ?Package {
		$record = self::record( $id );
		if ( $record === null ) {
			return null;
		}
		$release = $record['releases'][ $version !== '' ? $version : $record['version'] ] ?? null;
		if ( ! is_array( $release ) ) {
			return null;
		}

		$bytes = [ Package::MANIFEST => (string) wp_json_encode( $release['manifest'] ) ];
		foreach ( $release['files'] as $path => $data ) {
			$bytes[ (string) $path ] = (string) base64_decode( (string) $data, true );
		}
		$package = Package::fromArchive( $bytes );

		return $package instanceof Package ? $package : null;
	}

	/**
	 * Record $package as the installed release of its extension, keeping
	 * the earlier ones (at most KEEP in all).
	 */
	public static function put( Package $package, int $actorId ): void {
		$now    = gmdate( 'c' );
		$record = self::record( $package->id() ) ?? [
			'id'           => $package->id(),
			'source'       => $package->sourceRepo(),
			'installed_by' => $actorId,
			'installed_at' => $now,
			'releases'     => [],
		];

		$files = [];
		foreach ( $package->files() as $path => $bytes ) {
			$files[ $path ] = base64_encode( $bytes );
		}
		$record['releases'][ $package->version() ] = [ 'manifest' => $package->manifest(), 'files' => $files ];
		uksort( $record['releases'], static fn ( $a, $b ): int => version_compare( (string) $b, (string) $a ) );
		$record['releases'] = array_slice( $record['releases'], 0, self::KEEP, true );

		$record['name']       = $package->name();
		$record['version']    = $package->version();
		$record['publisher']  = $package->publisher();
		$record['updated_at'] = $now;
		$record['updated_by'] = $actorId;
		// #994: the manager who confirms the install approves what it asks for (the review shows it).
		$record['flags'] = $package->sandbox();

		update_option( self::option( $package->id() ), $record, false );
		$index                   = self::index();
		$index[ $package->id() ] = $package->version();
		update_option( self::INDEX, $index, false );
	}

	/**
	 * #994: make kept release $version the running one (a rollback). The
	 * releases themselves stay as they are.
	 */
	public static function makeRunning( string $id, string $version, int $actorId ): bool {
		$record = self::record( $id );
		if ( $record === null || ! isset( $record['releases'][ $version ] ) ) {
			return false;
		}
		$record['version']    = $version;
		$record['updated_at'] = gmdate( 'c' );
		$record['updated_by'] = $actorId;
		update_option( self::option( $id ), $record, false );
		$index        = self::index();
		$index[ $id ] = $version;
		update_option( self::INDEX, $index, false );

		return true;
	}

	/**
	 * The kept releases of $id, newest first.
	 *
	 * @return array<int, string>
	 */
	public static function releases( string $id ): array {
		$record = self::record( $id );

		return $record !== null ? array_map( 'strval', array_keys( $record['releases'] ) ) : [];
	}

	public static function forget( string $id ): void {
		delete_option( self::option( $id ) );
		$index = self::index();
		unset( $index[ $id ] );
		update_option( self::INDEX, $index, false );
	}

	private static function option( string $id ): string {
		return self::PREFIX . md5( $id );
	}
}
