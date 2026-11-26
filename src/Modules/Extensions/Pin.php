<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use WP_Error;

/**
 * #994 (RFC artifact-extensions, sections 5 and 6): a bound page can be
 * pinned to the release it serves (`ab_extension_pin`), for example to try
 * a new release on one page first. Updates and rollbacks skip a pinned
 * page (PageRefresh). Unpinning brings it to the extension's running
 * release at once. A manager who is a person sets or clears it, on
 * Settings > Extensions; never an agent.
 */
final class Pin {

	public const META = 'ab_extension_pin';

	/** The version the page is pinned to, or ''. */
	public static function version( int $artifactId ): string {
		return $artifactId > 0 ? (string) get_post_meta( $artifactId, self::META, true ) : '';
	}

	/**
	 * Pin the page to the release it serves now.
	 *
	 * @return string|WP_Error The version it is pinned to.
	 */
	public static function set( int $artifactId, int $actorId ) {
		$allowed = Installer::mayRun( $actorId );
		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}
		$extension = Binding::extensionId( $artifactId );
		if ( $extension === '' ) {
			return new WP_Error( 'ab_extension_not_bound', __( 'This page does not run an extension.', 'tomatio' ), [ 'status' => 409 ] );
		}
		$version = (string) get_post_meta( $artifactId, PageMaker::META_VERSION, true );
		if ( $version === '' ) {
			$package = Sandbox::package( $extension );
			$version = $package !== null ? $package->version() : '';
		}
		if ( $version === '' ) {
			return new WP_Error( 'ab_extension_unknown', __( 'That extension is neither bundled nor installed.', 'tomatio' ), [ 'status' => 404 ] );
		}
		if ( self::version( $artifactId ) === $version ) {
			return $version;
		}
		update_post_meta( $artifactId, self::META, $version );

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'extension.page_pinned',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'extension' => $extension, 'version' => $version ],
			]
		);

		return $version;
	}

	/**
	 * Unpin the page; it gets the extension's running release at once.
	 *
	 * @return true|WP_Error
	 */
	public static function clear( int $artifactId, int $actorId ) {
		$allowed = Installer::mayRun( $actorId );
		if ( $allowed instanceof WP_Error ) {
			return $allowed;
		}
		$was = self::version( $artifactId );
		if ( $was === '' ) {
			return true;
		}
		delete_post_meta( $artifactId, self::META );

		$extension = Binding::extensionId( $artifactId );
		$package   = $extension !== '' ? Sandbox::package( $extension ) : null;
		$result    = $package !== null ? PageRefresh::one( $artifactId, $package, 'extension', $package->name() . ' ' . $package->version() . ', the page unpinned' ) : '';

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'extension.page_unpinned',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'extension' => $extension, 'was' => $was, 'now' => $package !== null ? $package->version() : '', 'result' => $result ],
			]
		);

		return true;
	}
}
