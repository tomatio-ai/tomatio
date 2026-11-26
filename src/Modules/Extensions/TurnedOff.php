<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use WP_Error;

/**
 * #994 (RFC artifact-extensions, section 6, "Turning off"): a manager turns
 * one extension off, or every extension at once. A page bound to an
 * extension that is off gets the uniform 404 on share links and the
 * members address (ShareLinks::isServable, the gate both already use for
 * missing pages) and leaves the home page. Nothing is deleted: the pages,
 * their store, share links and versions stay, and turning it on again
 * brings everything back.
 *
 * This is not Switches (TíoDay, Magic Backlog): those decide whether the plugin
 * makes new pages. This one stops serving the pages that exist.
 * "Disable interactive mode" (Interactive::killSwitchOn) still only drops
 * the extra sandbox flags (Sandbox::flags), as before.
 */
final class TurnedOff {

	/** The extension ids a manager turned off. */
	public const OPTION = 'ab_extensions_off';

	/** '1' while "Turn off all extensions" is on. */
	public const OPTION_ALL = 'ab_extensions_all_off';

	/** @return array<int, string> */
	public static function ids(): array {
		$ids = get_option( self::OPTION, [] );

		return is_array( $ids ) ? array_values( array_map( 'strval', $ids ) ) : [];
	}

	public static function allOff(): bool {
		return (string) get_option( self::OPTION_ALL, '' ) === '1';
	}

	/** Whether pages of extension $id are off, by its own switch or the global one. */
	public static function isOff( string $id ): bool {
		return $id !== '' && ( self::allOff() || in_array( $id, self::ids(), true ) );
	}

	/** Whether the page runs an extension that is off. */
	public static function pageOff( int $artifactId ): bool {
		$id = Binding::extensionId( $artifactId );

		return $id !== '' && self::isOff( $id );
	}

	/**
	 * Turn extension $id off ($off true) or on again. A manager who is a
	 * person; never an agent.
	 *
	 * @return true|WP_Error
	 */
	public static function set( string $id, bool $off, int $actorId ) {
		$allowed = Installer::mayRun( $actorId );
		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}
		if ( Sandbox::package( $id ) === null && ! in_array( $id, self::ids(), true ) ) {
			return new WP_Error( 'ab_extension_unknown', __( 'That extension is neither bundled nor installed.', 'tomatio' ), [ 'status' => 404 ] );
		}

		$ids = self::ids();
		if ( in_array( $id, $ids, true ) === $off ) {
			return true;
		}
		$ids = $off ? array_merge( $ids, [ $id ] ) : array_values( array_diff( $ids, [ $id ] ) );
		if ( $ids === [] ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $ids, true );
		}

		$audit = [
			'actor_id'    => $actorId,
			'status'      => 'ok',
			'object_type' => 'extension',
			'object_id'   => $id,
			'meta'        => [ 'pages' => count( Installer::pages( $id ) ) ],
		];
		if ( $off ) {
			\AgentBrain\Core\Hooks::action( 'audit', [ 'event' => 'extension.turned_off' ] + $audit );
		} else {
			\AgentBrain\Core\Hooks::action( 'audit', [ 'event' => 'extension.turned_on' ] + $audit );
		}

		return true;
	}

	/**
	 * "Turn off all extensions" (next to "Disable interactive mode" on
	 * Settings > Sharing). Turning it on again leaves the extensions turned
	 * off one by one as they were.
	 *
	 * @return true|WP_Error
	 */
	public static function setAll( bool $off, int $actorId ) {
		$allowed = Installer::mayRun( $actorId );
		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}
		if ( self::allOff() === $off ) {
			return true;
		}
		if ( $off ) {
			update_option( self::OPTION_ALL, '1', true );
			\AgentBrain\Core\Hooks::action( 'audit', [ 'event' => 'extension.all_off' ] + self::globalAudit( $actorId ) );
		} else {
			delete_option( self::OPTION_ALL );
			\AgentBrain\Core\Hooks::action( 'audit', [ 'event' => 'extension.all_on' ] + self::globalAudit( $actorId ) );
		}

		return true;
	}

	/** @return array{actor_id:int, status:string, object_type:string, object_id:string, meta:array<string, mixed>} */
	private static function globalAudit( int $actorId ): array {
		return [
			'actor_id'    => $actorId,
			'status'      => 'ok',
			'object_type' => 'setting',
			'object_id'   => self::OPTION_ALL,
			'meta'        => [],
		];
	}
}
