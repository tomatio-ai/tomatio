<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Sharing\Interactive;
use WP_Error;

/**
 * #994 (RFC artifact-extensions, sections 3 and 5): the sandbox a page
 * runs in. A plain HTML page gets `allow-scripts` and nothing more, ever.
 * A page bound to an extension also gets the flags its running release
 * declares AND a manager approved, from a short list (contract 1: links
 * may open a new tab). Share links and the members address use it; the
 * wp-admin previews and editors never do.
 *
 * Approval: an extension installed from an archive is approved when the
 * manager confirms the install, which shows what it may do. A bundled one
 * (TíoDay) is approved on Settings > Extensions. "Disable interactive
 * mode" drops every extra flag at once.
 *
 * A manager who is a person can also run one extension with the base
 * sandbox only ("Base sandbox only" under Running extensions): its pages
 * then get `allow-scripts` and nothing more, whatever its manifest asks
 * and was approved. The approval is kept, so allowing the flags again
 * needs no new review.
 */
final class Sandbox {

	/** The flags contract 1 allows an extension to ask for. */
	public const ALLOWED = [ 'allow-popups', 'allow-popups-to-escape-sandbox' ];

	/** id => approved flags, for the bundled extensions. */
	public const OPTION = 'ab_extension_flags';

	/** The extension ids a manager set to run with the base sandbox only. */
	public const OPTION_BASE = 'ab_extensions_base_sandbox';

	/** @var array<string, Package|null> */
	private static array $packages = [];

	/**
	 * The extra flags the page runs with (none for a plain page).
	 *
	 * @return array<int, string>
	 */
	public static function flags( int $artifactId ): array {
		$id = Binding::extensionId( $artifactId );
		if ( $id === '' || Interactive::killSwitchOn() || self::baseOnly( $id ) ) {
			return [];
		}
		$package = self::package( $id );
		if ( $package === null ) {
			return [];
		}

		return array_values( array_intersect( self::ALLOWED, $package->sandbox(), self::approved( $id ) ) );
	}

	/** The sandbox attribute value for the page, `allow-scripts` first. */
	public static function attribute( int $artifactId ): string {
		return implode( ' ', array_merge( [ 'allow-scripts' ], self::flags( $artifactId ) ) );
	}

	/**
	 * The flags a manager approved for extension $id.
	 *
	 * @return array<int, string>
	 */
	public static function approved( string $id ): array {
		$record = Installed::record( $id );
		if ( $record !== null ) {
			return array_values( array_map( 'strval', (array) ( $record['flags'] ?? [] ) ) );
		}
		$all = get_option( self::OPTION, [] );

		return is_array( $all ) && is_array( $all[ $id ] ?? null ) ? array_values( array_map( 'strval', $all[ $id ] ) ) : [];
	}

	/** @return array<int, string> The extensions that run with the base sandbox only. */
	public static function baseOnlyIds(): array {
		$ids = get_option( self::OPTION_BASE, [] );

		return is_array( $ids ) ? array_values( array_map( 'strval', $ids ) ) : [];
	}

	/** Whether extension $id runs with the base sandbox only. */
	public static function baseOnly( string $id ): bool {
		return $id !== '' && in_array( $id, self::baseOnlyIds(), true );
	}

	/**
	 * Run extension $id with the base sandbox only ($on true), or with the
	 * flags it asks for and was approved again. A manager who is a person;
	 * never an agent.
	 *
	 * @return true|WP_Error
	 */
	public static function setBaseOnly( string $id, bool $on, int $actorId ) {
		$allowed = Installer::mayRun( $actorId );
		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}
		if ( self::package( $id ) === null && ! self::baseOnly( $id ) ) {
			return new WP_Error( 'ab_extension_unknown', __( 'That extension is neither bundled nor installed.', 'tomatio' ), [ 'status' => 404 ] );
		}
		if ( self::baseOnly( $id ) === $on ) {
			return true;
		}
		self::writeBaseOnly( $on ? array_merge( self::baseOnlyIds(), [ $id ] ) : array_diff( self::baseOnlyIds(), [ $id ] ) );

		$audit = [
			'actor_id'    => $actorId,
			'status'      => 'ok',
			'object_type' => 'extension',
			'object_id'   => $id,
			'meta'        => [ 'pages' => count( Installer::pages( $id ) ) ],
		];
		if ( $on ) {
			\AgentBrain\Core\Hooks::action( 'audit', [ 'event' => 'extension.base_sandbox_on' ] + $audit );
		} else {
			\AgentBrain\Core\Hooks::action( 'audit', [ 'event' => 'extension.base_sandbox_off' ] + $audit );
		}

		return true;
	}

	/** Removing an extension forgets its setting, so a later install starts with its flags. */
	public static function forgetBaseOnly( string $id ): void {
		if ( self::baseOnly( $id ) ) {
			self::writeBaseOnly( array_diff( self::baseOnlyIds(), [ $id ] ) );
		}
	}

	/** @param array<int, string> $ids */
	private static function writeBaseOnly( array $ids ): void {
		$ids = array_values( array_unique( $ids ) );
		if ( $ids === [] ) {
			delete_option( self::OPTION_BASE );
		} else {
			update_option( self::OPTION_BASE, $ids, true );
		}
	}

	/**
	 * A manager approves (or withdraws) the flags a bundled extension
	 * declares. Never an agent.
	 *
	 * @param array<int, string> $flags
	 * @return true|WP_Error
	 */
	public static function approveBundled( string $id, array $flags, int $actorId ) {
		$user = $actorId > 0 ? get_userdata( $actorId ) : false;
		if ( ! $user instanceof \WP_User || ! user_can( $user, Capabilities::CAP_MANAGE ) || in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			return new WP_Error( 'ab_forbidden', __( 'Only a manager can approve what an extension may do, and never through an AI.', 'tomatio' ), [ 'status' => 403 ] );
		}
		$flags = array_values( array_intersect( self::ALLOWED, $flags ) );
		$all   = get_option( self::OPTION, [] );
		$all   = is_array( $all ) ? $all : [];
		if ( ( $all[ $id ] ?? [] ) === $flags ) {
			return true;
		}
		$all[ $id ] = $flags;
		update_option( self::OPTION, $all, false );

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'extension.flags_approved',
				'status'      => 'ok',
				'object_type' => 'extension',
				'object_id'   => $id,
				'meta'        => [ 'flags' => $flags ],
			]
		);

		return true;
	}

	/**
	 * Plain words for a flag set, for the screens.
	 *
	 * @param array<int, string> $flags
	 */
	public static function describe( array $flags ): string {
		return array_intersect( self::ALLOWED, $flags ) !== []
			? __( 'Links can open in a new tab.', 'tomatio' )
			: __( 'Nothing beyond a plain HTML page.', 'tomatio' );
	}

	/** The running release of extension $id, bundled or installed. */
	public static function package( string $id ): ?Package {
		if ( ! array_key_exists( $id, self::$packages ) ) {
			$found = null;
			foreach ( Installer::BUNDLED as $folder ) {
				$bundled = Package::bundled( $folder );
				if ( $bundled instanceof Package && $bundled->id() === $id ) {
					$found = $bundled;
					break;
				}
			}
			self::$packages[ $id ] = $found ?? Installed::package( $id );
		}

		return self::$packages[ $id ];
	}

	/** For tests and after an install: read the packages again. */
	public static function reset(): void {
		self::$packages = [];
	}
}
