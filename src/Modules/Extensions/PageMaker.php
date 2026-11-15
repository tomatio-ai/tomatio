<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\Artifacts\ArtifactService;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\DataStore\StoreMode;
use WP_Error;

/**
 * #606: make a page from a bundled extension package (Package), as the
 * plugin itself: the artifact (ArtifactService::createBySystem), its files
 * as version 1, approved (HtmlFiles::putBySystem, approveBySystem), and the
 * page store with the manifest's collections (StoreMode::configureBySystem).
 * Every step is in the audit log with actor 0.
 *
 * This is RFC #722's "making a new bound page" before the install flow
 * exists: the package is part of the plugin, so installing the plugin is
 * the install, and the brain's own setting for the feature that calls this
 * is the approval. The page is a plain HTML page (`ab_artifact_kind`
 * html, sandbox allow-scripts), and it records what it was made from
 * (META_FROM, META_VERSION), so the bind flow can recognise it later and
 * compare its files with the release.
 */
final class PageMaker {

	public const META_FROM    = 'ab_made_from_extension';
	public const META_VERSION = 'ab_made_from_version';

	/**
	 * @param int $homeOwner For a `binding.per: person` package with `home: true`, the person the page is pinned to (HomeWorkspace::META_OWNER).
	 * @return int|WP_Error The new artifact's id.
	 */
	public static function make( Package $package, string $title, int $leadAgentId, int $scopeId, int $homeOwner = 0 ) {
		$collections = StoreMode::normalizeCollections( $package->collections() );
		if ( is_string( $collections ) ) {
			return new WP_Error( 'ab_extension_invalid', 'The package store collections are invalid: ' . $collections, [ 'status' => 500 ] );
		}

		$id = ( new ArtifactService() )->createBySystem( $title, $leadAgentId, $scopeId, [ 'extension' => $package->id(), 'extension_version' => $package->version() ] );
		if ( $id instanceof WP_Error ) {
			return $id;
		}
		$id = (int) $id;

		$done = self::fill( $package, $id, $collections );
		if ( $done instanceof WP_Error ) {
			wp_delete_post( $id, true );

			return $done;
		}

		update_post_meta( $id, self::META_FROM, $package->id() );
		update_post_meta( $id, self::META_VERSION, $package->version() );

		$binding = $package->binding();
		if ( $homeOwner > 0 && ( $binding['per'] ?? '' ) === 'person' && ! empty( $binding['home'] ) ) {
			update_post_meta( $id, HomeWorkspace::META_OWNER, (string) $homeOwner );
		}

		return $id;
	}

	/**
	 * @param array<string, array<string, mixed>> $collections
	 * @return true|WP_Error
	 */
	private static function fill( Package $package, int $id, array $collections ) {
		$put = HtmlFiles::putBySystem( $id, $package->htmlFiles(), $package->name() . ' ' . $package->version() . ', from the package bundled with the plugin' );
		if ( $put instanceof WP_Error ) {
			return $put;
		}

		$approved = HtmlFiles::approveBySystem( $id, (int) $put['version'] );
		if ( $approved instanceof WP_Error ) {
			return $approved;
		}

		return $collections === [] ? true : StoreMode::configureBySystem( $id, $collections );
	}
}
