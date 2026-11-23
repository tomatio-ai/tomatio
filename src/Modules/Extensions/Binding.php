<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Identity\Capabilities;

/**
 * #994 (RFC artifact-extensions, section 5, decision 5): a page bound to
 * an extension runs that extension's files, and nobody else's. People and
 * agents can no longer put, approve or restore its HTML versions; only the
 * plugin writes them, from the package it verified (PageMaker,
 * PageRefresh). The page keeps everything else that is its own: store,
 * share links, comments, lead, scope and home owner.
 *
 * The files still land as HTML versions put by the plugin (created_by 0),
 * so serving, history and the reload poll work as for any HTML page.
 *
 * Unbinding (a manager, never an agent) turns it back into a plain
 * HTML page on the files it serves now, which people can then change.
 */
final class Binding {

	/** The extension id the page runs, such as tomatio.tioday. */
	public const META = 'ab_extension';

	public static function isBound( int $artifactId ): bool {
		return self::extensionId( $artifactId ) !== '';
	}

	public static function extensionId( int $artifactId ): string {
		return $artifactId > 0 ? (string) get_post_meta( $artifactId, self::META, true ) : '';
	}

	/**
	 * Bind the page to $package. Done by the plugin (PageMaker for new
	 * pages, PageRefresh for pages still serving the plugin's files); an
	 * already bound page stays as it is.
	 */
	public static function bind( int $artifactId, Package $package ): void {
		if ( self::extensionId( $artifactId ) === $package->id() ) {
			return;
		}

		update_post_meta( $artifactId, self::META, $package->id() );

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => 0,
				'event'       => 'extension.page_bound',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'extension' => $package->id(), 'version' => $package->version() ],
			]
		);
	}

	/**
	 * Turn a bound page back into a plain HTML page. A manager may always
	 * do it, with no approval (RFC 5.2, step 5); an agent never may, not even
	 * for a person, or it could unbind a page to write to it. The plugin no longer refreshes it.
	 *
	 * @return true|\WP_Error
	 */
	public static function unbind( int $artifactId, int $actorId ) {
		$extension = self::extensionId( $artifactId );
		if ( $extension === '' ) {
			return new \WP_Error( 'ab_extension_not_bound', __( 'This page does not run an extension.', 'tomatio' ), [ 'status' => 409 ] );
		}

		$user = get_userdata( $actorId );
		if ( ! $user instanceof \WP_User || ! user_can( $user, Capabilities::CAP_MANAGE ) || in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'tomatio' ), [ 'status' => 403 ] );
		}

		delete_post_meta( $artifactId, self::META );
		delete_post_meta( $artifactId, PageMaker::META_FROM );
		delete_post_meta( $artifactId, Pin::META );

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'extension.page_unbound',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'extension' => $extension ],
			]
		);

		return true;
	}

	/**
	 * The plugin lets go of a page (its extension was removed): it becomes
	 * a plain HTML page on the files it serves, and is no longer refreshed.
	 * Installer::remove() checks who may do that.
	 */
	public static function release( int $artifactId ): void {
		$extension = self::extensionId( $artifactId );
		delete_post_meta( $artifactId, self::META );
		delete_post_meta( $artifactId, PageMaker::META_FROM );
		delete_post_meta( $artifactId, Pin::META );

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => 0,
				'event'       => 'extension.page_unbound',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'extension' => $extension, 'why' => 'extension removed' ],
			]
		);
	}

	/**
	 * The refusal for any person's or agent's write to a bound page's files.
	 */
	public static function refusal( int $artifactId ): \WP_Error {
		$name = self::extensionName( self::extensionId( $artifactId ) );

		return new \WP_Error(
			'ab_extension_files',
			sprintf(
				/* translators: %s: the extension's name, such as TíoDay. */
				__( 'This page runs the %s extension. Change the extension, not the page: its files come from the plugin.', 'tomatio' ),
				$name
			),
			[ 'status' => 409 ]
		);
	}

	private static function extensionName( string $id ): string {
		foreach ( [ 'tioday', 'backlog' ] as $folder ) {
			$package = Package::bundled( $folder );
			if ( $package instanceof Package && $package->id() === $id ) {
				return $package->name();
			}
		}

		$installed = Installed::record( $id );

		return $installed !== null ? (string) $installed['name'] : $id;
	}
}
