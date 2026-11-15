<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;

/**
 * #818, #985: when the plugin brings a new page for a bundled package,
 * every page made from that package gets it, put and approved by the
 * plugin as PageMaker does, so an update reaches existing pages and not
 * only new ones (TioDayRefresh for TíoDay, BacklogRefresh for backlogs).
 *
 * Only where the plugin's own page is still the one served: the served
 * version was put by the plugin (created_by 0) and no newer version waits
 * (someone's draft). A page a person or an AI changed keeps what it has;
 * the audit log says which and why.
 */
final class PageRefresh {

	/**
	 * Give every page made from $package its page where the plugin's page
	 * is still served. $event names the audit events ($event.page_refreshed,
	 * $event.page_kept).
	 *
	 * @return array<int, string> Artifact id => what happened (refreshed, current, kept_edited, kept_draft).
	 */
	public static function run( Package $package, string $event ): array {
		$ids = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_key'       => PageMaker::META_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- once per plugin update, one row per page.
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
			$out[ (int) $id ] = self::refresh( (int) $id, $package, $want, $event );
		}

		return $out;
	}

	/** @param array<string, string> $want path => bytes */
	private static function refresh( int $id, Package $package, array $want, string $event ): string {
		$served = HtmlFiles::servedNumber( $id );
		$row    = $served > 0 ? HtmlFiles::version( $id, $served ) : null;
		if ( ! HtmlFiles::isHtml( $id ) || $row === null ) {
			return self::kept( $event, $id, 'kept_edited', 'no served version' );
		}
		if ( HtmlFiles::latestNumber( $id ) !== $served ) {
			return self::kept( $event, $id, 'kept_draft', 'a newer version is waiting for approval' );
		}
		if ( $row['created_by'] !== 0 ) {
			return self::kept( $event, $id, 'kept_edited', 'the served version was put by user ' . $row['created_by'] );
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
			return self::kept( $event, $id, 'kept_edited', $put->get_error_code() );
		}
		$approved = HtmlFiles::approveBySystem( $id, (int) $put['version'] );
		if ( $approved instanceof \WP_Error ) {
			return self::kept( $event, $id, 'kept_draft', $approved->get_error_code() );
		}
		update_post_meta( $id, PageMaker::META_VERSION, $package->version() );

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => 0,
				'event'       => $event . '.page_refreshed',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $id,
				'meta'        => [ 'version' => (int) $put['version'], 'package_version' => $package->version() ],
			]
		);

		return 'refreshed';
	}

	private static function kept( string $event, int $id, string $what, string $why ): string {
		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => 0,
				'event'       => $event . '.page_kept',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $id,
				'meta'        => [ 'result' => $what, 'why' => $why ],
			]
		);

		return $what;
	}

}
