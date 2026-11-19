<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\StoreRules;
use AgentBrain\Modules\DataStore\TioDayDefault;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\DefaultScope;
use AgentBrain\Modules\Identity\Scopes;
use WP_Error;

/**
 * #994 (RFC artifact-extensions, sections 4 and 6; decisions 2, 3 and 4):
 * a manager installs an extension from a zip on Settings > Extensions.
 *
 * - Only a person with ab_manage installs, updates or removes: never an
 *   agent, not even for a person (decision 2).
 * - The archive holds tomatio-extension.json at its top and exactly the
 *   files it lists, each matching its SHA-256 (decision 4). The first
 *   install pins the extension's source; a later archive with the same
 *   id from another source is refused. The bundled ids (TíoDay, Tomagic)
 *   are pinned to the plugin.
 * - A newer release replaces the installed one and reaches every page
 *   bound to it at once (decision 3); the earlier releases are kept.
 * - Removing keeps every page: they become plain HTML pages on the files
 *   they serve.
 */
final class Installer {

	/** The bundled packages: their ids are pinned to the plugin. */
	public const BUNDLED = [ TioDayDefault::PACKAGE, 'backlog' ];

	/**
	 * Read a zip into path => bytes, within the HTML page limits.
	 *
	 * @return array<string, string>|WP_Error
	 */
	public static function readArchive( string $zipPath ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return self::error( 'ab_extension_no_zip', __( 'This server cannot open zip files (the PHP zip extension is missing).', 'tomatio' ), 500 );
		}
		$zip = new \ZipArchive();
		if ( $zip->open( $zipPath ) !== true ) {
			return self::error( 'ab_extension_invalid', __( 'That file is not a zip archive.', 'tomatio' ) );
		}

		$limits = HtmlFiles::limits();
		$bytes  = [];
		$total  = 0;
		try {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				$name = is_array( $stat ) ? (string) $stat['name'] : '';
				if ( $name === '' || str_ends_with( $name, '/' ) ) {
					continue;
				}
				if ( $name !== Package::MANIFEST && ! HtmlFiles::isValidPath( $name ) ) {
					return self::error( 'ab_extension_invalid', sprintf( /* translators: %s: a path in the archive. */ __( 'The archive holds a path that is not allowed: %s', 'tomatio' ), $name ) );
				}
				$size = (int) $stat['size'];
				if ( $size > $limits['max_file_bytes'] || count( $bytes ) >= $limits['max_files'] + 1 ) {
					return self::error( 'ab_extension_too_big', __( 'The archive has too many files or a file that is too big for a page.', 'tomatio' ) );
				}
				$total += $size;
				if ( $total > $limits['max_total_bytes'] ) {
					return self::error( 'ab_extension_too_big', __( 'The archive is too big for a page.', 'tomatio' ) );
				}
				$read = $zip->getFromIndex( $i );
				if ( ! is_string( $read ) ) {
					return self::error( 'ab_extension_invalid', __( 'A file in the archive could not be read.', 'tomatio' ) );
				}
				$bytes[ $name ] = $read;
			}
		} finally {
			$zip->close();
		}

		return $bytes;
	}

	/**
	 * Check an archive's files without installing: the package, and what
	 * installing it would do (install, update, or why not).
	 *
	 * @param array<string, string> $bytes
	 * @return array{package:Package, action:string, from:string}|WP_Error
	 */
	public static function check( array $bytes, int $actorId ) {
		$allowed = self::mayInstall( $actorId );
		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}

		$package = Package::fromArchive( $bytes );
		if ( is_string( $package ) ) {
			return self::error( 'ab_extension_invalid', $package );
		}

		foreach ( self::BUNDLED as $folder ) {
			$bundled = Package::bundled( $folder );
			if ( $bundled instanceof Package && $bundled->id() === $package->id() ) {
				return self::error( 'ab_extension_pinned', sprintf( /* translators: %s: an extension's name. */ __( '%s comes with the plugin; it is updated with the plugin, not from an archive.', 'tomatio' ), $bundled->name() ), 409 );
			}
		}

		$record = Installed::record( $package->id() );
		if ( $record === null ) {
			return [ 'package' => $package, 'action' => 'install', 'from' => '' ];
		}
		if ( (string) $record['source'] !== $package->sourceRepo() ) {
			return self::error( 'ab_extension_pinned', sprintf( /* translators: 1: the extension's id, 2: the source it was installed from. */ __( '%1$s was installed from %2$s; an archive from another source cannot replace it. Remove it first if you mean to switch.', 'tomatio' ), $package->id(), (string) $record['source'] !== '' ? (string) $record['source'] : __( 'an archive with no source', 'tomatio' ) ), 409 );
		}
		if ( version_compare( $package->version(), (string) $record['version'], '<=' ) ) {
			return self::error( 'ab_extension_not_newer', sprintf( /* translators: 1: the archive's version, 2: the installed version. */ __( 'This is version %1$s; version %2$s is already installed.', 'tomatio' ), $package->version(), (string) $record['version'] ), 409 );
		}

		return [ 'package' => $package, 'action' => 'update', 'from' => (string) $record['version'] ];
	}

	/**
	 * Install or update from an archive's files.
	 *
	 * @param array<string, string> $bytes
	 * @return array{id:string, version:string, action:string, pages:array<int, string>}|WP_Error
	 */
	public static function install( array $bytes, int $actorId ) {
		$checked = self::check( $bytes, $actorId );
		if ( $checked instanceof WP_Error ) {
			return $checked;
		}
		$package = $checked['package'];

		Installed::put( $package, $actorId );
		Sandbox::reset();
		$pages = $checked['action'] === 'update' ? PageRefresh::run( $package, 'extension' ) : [];

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => $checked['action'] === 'update' ? 'extension.updated' : 'extension.installed',
				'status'      => 'ok',
				'object_type' => 'extension',
				'object_id'   => $package->id(),
				'meta'        => [ 'version' => $package->version(), 'from' => $checked['from'], 'source' => $package->sourceRepo(), 'pages' => count( $pages ) ],
			]
		);

		return [ 'id' => $package->id(), 'version' => $package->version(), 'action' => $checked['action'], 'pages' => $pages ];
	}

	/**
	 * Make a new page bound to an installed extension, led by the
	 * manager's agent in the team scope.
	 *
	 * @return int|WP_Error The new artifact's id.
	 */
	public static function makePage( string $id, int $actorId ) {
		$allowed = self::mayInstall( $actorId );
		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}
		$package = Installed::package( $id );
		if ( $package === null ) {
			return self::error( 'ab_extension_unknown', __( 'That extension is not installed.', 'tomatio' ), 404 );
		}
		if ( ( $package->binding()['per'] ?? '' ) === 'brain' && self::pages( $id ) !== [] ) {
			return self::error( 'ab_extension_one_page', __( 'This extension has one page per brain, and it has it already.', 'tomatio' ), 409 );
		}

		$lead = TioDayDefault::lead( $actorId );
		if ( $lead <= 0 ) {
			return self::error( 'ab_extension_no_agent', __( 'Pages are led by an agent, and you have none yet. Create one (TomaTío > Agents) first.', 'tomatio' ), 409 );
		}
		$scopes  = new Scopes();
		$default = (int) DefaultScope::id();
		$scopeId = $default > 0 && $scopes->canWrite( $lead, $default ) ? $default : (int) ( $scopes->writeScopeIds( $lead )[0] ?? 0 );
		if ( $scopeId <= 0 ) {
			return self::error( 'ab_extension_no_scope', __( 'Your agent may write no scope yet. Grant it the team scope first.', 'tomatio' ), 409 );
		}

		$homeOwner = ( $package->binding()['per'] ?? '' ) === 'person' ? $actorId : 0;

		return PageMaker::make( $package, $package->name(), $lead, $scopeId, $homeOwner );
	}

	/**
	 * Remove an installed extension. Its pages stay, as plain HTML pages
	 * on the files they serve now.
	 *
	 * @return array{id:string, pages:int}|WP_Error
	 */
	public static function remove( string $id, int $actorId ) {
		$allowed = self::mayInstall( $actorId );
		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}
		if ( ! Installed::has( $id ) ) {
			return self::error( 'ab_extension_unknown', __( 'That extension is not installed.', 'tomatio' ), 404 );
		}

		$pages = self::pages( $id );
		foreach ( $pages as $page ) {
			Binding::release( $page );
		}
		Installed::forget( $id );
		Sandbox::forgetBaseOnly( $id );
		Sandbox::reset();
		// #994: a later install of the same id starts on, as a new one.
		$off = array_values( array_diff( TurnedOff::ids(), [ $id ] ) );
		if ( $off === [] ) {
			delete_option( TurnedOff::OPTION );
		} else {
			update_option( TurnedOff::OPTION, $off, true );
		}

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'extension.removed',
				'status'      => 'ok',
				'object_type' => 'extension',
				'object_id'   => $id,
				'meta'        => [ 'pages_kept' => count( $pages ) ],
			]
		);

		return [ 'id' => $id, 'pages' => count( $pages ) ];
	}

	/**
	 * The pages made from (or bound to) extension $id.
	 *
	 * @return array<int, int>
	 */
	public static function pages( string $id ): array {
		$ids = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => PageMaker::META_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a manager's screen, one row per page.
				'meta_value'     => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
			]
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * #994 (RFC section 6): serve kept release $version of an installed
	 * extension again, at once, to every bound page that is not pinned.
	 * No approval: older releases were approved when they ran. The store
	 * is never rolled back. Bundled extensions keep no releases, so they
	 * have no rollback.
	 *
	 * The result's store_breaking lists what in the older release's store
	 * rules may break the data the pages have now (storeBreaking()); it
	 * warns, it never blocks.
	 *
	 * @return array{id:string, version:string, from:string, pages:array<int, string>, store_breaking:array<int, array{collection:string, kind:string, fields:array<int, string>, docs:int}>}|WP_Error
	 */
	public static function rollBack( string $id, string $version, int $actorId ) {
		$allowed = self::mayRun( $actorId );
		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}
		$record = Installed::record( $id );
		if ( $record === null ) {
			return self::error( 'ab_extension_no_releases', __( 'Only an extension installed from an archive keeps releases to roll back to.', 'tomatio' ), 409 );
		}
		$from = (string) $record['version'];
		if ( $version === $from ) {
			return self::error( 'ab_extension_running', sprintf( /* translators: %s: a version. */ __( 'Version %s is already the one running.', 'tomatio' ), $version ), 409 );
		}
		$package = Installed::package( $id, $version );
		$running = Installed::package( $id );
		$warns   = $package !== null && $running !== null ? self::storeBreaking( $running, $package ) : [];
		if ( $package === null || ! Installed::makeRunning( $id, $version, $actorId ) ) {
			return self::error( 'ab_extension_unknown', sprintf( /* translators: %s: a version. */ __( 'Version %s is not kept on this brain.', 'tomatio' ), $version ), 404 );
		}
		Sandbox::reset();
		$pages = PageRefresh::run( $package, 'extension', $package->name() . ' ' . $package->version() . ', rolled back from ' . $from );

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'extension.rolled_back',
				'status'      => 'ok',
				'object_type' => 'extension',
				'object_id'   => $id,
				'meta'        => [
					'version'        => $version,
					'from'           => $from,
					'pages'          => count( $pages ),
					'pinned'         => count( array_filter( $pages, static fn ( string $what ): bool => $what === 'kept_pinned' ) ),
					'store_breaking' => array_map( static fn ( array $warn ): string => $warn['collection'] . ':' . $warn['kind'], $warns ),
				],
			]
		);

		return [ 'id' => $id, 'version' => $version, 'from' => $from, 'pages' => $pages, 'store_breaking' => $warns ];
	}

	/**
	 * #994: what rolling extension $id back to kept release $version may
	 * break, for the Rollback confirm and the rollback tool's dry run.
	 *
	 * @return array<int, array{collection:string, kind:string, fields:array<int, string>, docs:int}>
	 */
	public static function rollbackWarnings( string $id, string $version ): array {
		$older   = Installed::package( $id, $version );
		$running = Installed::package( $id );

		return $older !== null && $running !== null && $older->version() !== $running->version() ? self::storeBreaking( $running, $older ) : [];
	}

	/**
	 * #994: where release $to's store rules are stricter than, or differ
	 * from, release $from's in a way the data the pages hold now may break:
	 *
	 * - missing: $from has the collection and $to does not list it, so its
	 *   documents can no longer be read or written through the page;
	 * - required_added: $to's schema requires fields $from's did not, so a
	 *   document written without them fails its next update;
	 * - stricter: $to allows less (fewer operations, stronger writers, a
	 *   tighter owner or narrower visibility), so writes the page made
	 *   before are refused.
	 *
	 * docs counts the documents the bound pages hold in that collection.
	 *
	 * @return array<int, array{collection:string, kind:string, fields:array<int, string>, docs:int}>
	 */
	public static function storeBreaking( Package $from, Package $to ): array {
		$now   = $from->collections();
		$then  = $to->collections();
		$docs  = self::docsByCollection( $from->id() );
		$warns = [];
		$names = array_map( 'strval', array_keys( $now ) );
		sort( $names );
		foreach ( $names as $name ) {
			$count = (int) ( $docs[ $name ] ?? 0 );
			if ( ! array_key_exists( $name, $then ) ) {
				$warns[] = [ 'collection' => $name, 'kind' => 'missing', 'fields' => [], 'docs' => $count ];
				continue;
			}
			$a = StoreRules::normalize( $now[ $name ] );
			$b = StoreRules::normalize( $then[ $name ] );
			if ( ! is_array( $a ) || ! is_array( $b ) ) {
				continue;
			}
			$added = array_values( array_diff( self::required( $b ), self::required( $a ) ) );
			sort( $added );
			if ( $added !== [] ) {
				$warns[] = [ 'collection' => $name, 'kind' => 'required_added', 'fields' => $added, 'docs' => $count ];
			}
			if ( StoreRules::looserThan( $a, $b ) ) {
				$warns[] = [ 'collection' => $name, 'kind' => 'stricter', 'fields' => [], 'docs' => $count ];
			}
		}

		return $warns;
	}

	/**
	 * Plain words for storeBreaking()'s warnings, for the screens.
	 *
	 * @param array<int, array{collection:string, kind:string, fields:array<int, string>, docs:int}> $warns
	 */
	public static function describeBreaking( array $warns ): string {
		$lines = [];
		foreach ( $warns as $warn ) {
			$lines[] = match ( $warn['kind'] ) {
				/* translators: 1: a store collection, 2: number of documents. */
				'missing'        => sprintf( __( '%1$s is not in that release, so its %2$d documents can no longer be read or written through the pages.', 'tomatio' ), $warn['collection'], $warn['docs'] ),
				/* translators: 1: a store collection, 2: comma-separated field names, 3: number of documents. */
				'required_added' => sprintf( __( '%1$s requires %2$s in that release, so its %3$d documents fail their next update when they lack them.', 'tomatio' ), $warn['collection'], implode( ', ', $warn['fields'] ), $warn['docs'] ),
				/* translators: 1: a store collection, 2: number of documents. */
				default          => sprintf( __( '%1$s allows less in that release, so some writes the pages make now are refused (%2$d documents).', 'tomatio' ), $warn['collection'], $warn['docs'] ),
			};
		}

		return implode( ' ', $lines );
	}

	/**
	 * @param array<string, mixed> $rules
	 * @return array<int, string>
	 */
	private static function required( array $rules ): array {
		$schema = is_array( $rules['schema'] ?? null ) ? $rules['schema'] : [];

		return is_array( $schema['required'] ?? null ) ? array_values( array_map( 'strval', $schema['required'] ) ) : [];
	}

	/**
	 * Documents per collection across the pages bound to extension $id.
	 *
	 * @return array<string, int>
	 */
	private static function docsByCollection( string $id ): array {
		$docs = [];
		foreach ( self::pages( $id ) as $page ) {
			foreach ( StoreData::collections( $page ) as $stats ) {
				$docs[ $stats['collection'] ] = ( $docs[ $stats['collection'] ] ?? 0 ) + $stats['docs'];
			}
		}

		return $docs;
	}

	/**
	 * #994: who may turn an extension off, roll it back or pin a page: a
	 * manager who is a person (curators hold ab_manage too), never an agent
	 * (not even with ab_manage). The MCP tools for it run as the person
	 * behind the connection (ExtensionAbilities::runner()).
	 *
	 * @return true|WP_Error
	 */
	public static function mayRun( int $actorId ) {
		$user = $actorId > 0 ? get_userdata( $actorId ) : false;
		if ( ! $user instanceof \WP_User || ! user_can( $user, Capabilities::CAP_MANAGE ) || in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			return self::error( 'ab_forbidden', __( 'Only a manager who is a person can turn an extension off, roll it back or pin a page; an agent never can.', 'tomatio' ), 403 );
		}

		return true;
	}

	/** @return true|WP_Error */
	public static function mayInstall( int $actorId ) {
		$user = $actorId > 0 ? get_userdata( $actorId ) : false;
		if ( ! $user instanceof \WP_User || ! user_can( $user, Capabilities::CAP_MANAGE ) || in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			return self::error( 'ab_forbidden', __( 'Only a manager can install, update or remove extensions, and never through an AI.', 'tomatio' ), 403 );
		}

		return true;
	}

	private static function error( string $code, string $message, int $status = 422 ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
