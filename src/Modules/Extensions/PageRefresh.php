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
 *
 * #994: a page bound to the package (Binding) always gets it, since
 * nobody else can write to it; a page still serving the plugin's own
 * files becomes bound here, which is how pages made before 1.10 get
 * bound. A page someone changed stays unbound and keeps what it has.
 */
final class PageRefresh {

	/**
	 * Give every page made from $package its page where the plugin's page
	 * is still served. $event names the audit events ($event.page_refreshed,
	 * $event.page_kept). $note says why in the new version's note (by
	 * default, a new page bundled with the plugin).
	 *
	 * #994: a page pinned to another release (Pin) is skipped (kept_pinned).
	 *
	 * @return array<int, string> Artifact id => what happened (refreshed, current, kept_edited, kept_draft, kept_pinned).
	 */
	public static function run( Package $package, string $event, string $note = '' ): array {
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

		$want = self::want( $package );
		$out  = [];
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = self::refresh( (int) $id, $package, $want, $event, $note );
		}

		return $out;
	}

	/**
	 * #994: give one page $package (a page just unpinned). Same rules as run().
	 */
	public static function one( int $id, Package $package, string $event, string $note = '' ): string {
		return self::refresh( $id, $package, self::want( $package ), $event, $note );
	}

	/** @return array<string, string> path => bytes */
	private static function want( Package $package ): array {
		$want = [];
		foreach ( $package->htmlFiles() as $file ) {
			$want[ $file['path'] ] = (string) base64_decode( $file['content'], true );
		}
		ksort( $want );

		return $want;
	}

	/** @param array<string, string> $want path => bytes */
	private static function refresh( int $id, Package $package, array $want, string $event, string $note ): string {
		$pin = Pin::version( $id );
		if ( $pin !== '' && $pin !== $package->version() && Binding::extensionId( $id ) === $package->id() ) {
			return self::kept( $event, $id, 'kept_pinned', 'the page is pinned to ' . $pin );
		}
		$served = HtmlFiles::servedNumber( $id );
		$row    = $served > 0 ? HtmlFiles::version( $id, $served ) : null;
		if ( ! HtmlFiles::isHtml( $id ) || $row === null ) {
			return self::kept( $event, $id, 'kept_edited', 'no served version' );
		}
		$bound = Binding::extensionId( $id ) === $package->id();
		if ( ! $bound && HtmlFiles::latestNumber( $id ) !== $served ) {
			return self::kept( $event, $id, 'kept_draft', 'a newer version is waiting for approval' );
		}
		if ( ! $bound && $row['created_by'] !== 0 ) {
			return self::kept( $event, $id, 'kept_edited', 'the served version was put by user ' . $row['created_by'] );
		}

		$have = [];
		foreach ( $row['files'] as $path => $file ) {
			$have[ (string) $path ] = HtmlFiles::bytes( $file );
		}
		ksort( $have );
		if ( $have === $want && HtmlFiles::latestNumber( $id ) === $served ) {
			update_post_meta( $id, PageMaker::META_VERSION, $package->version() );
			Binding::bind( $id, $package );

			return 'current';
		}

		$put = HtmlFiles::putBySystem( $id, $package->htmlFiles(), $note !== '' ? $note : $package->name() . ' ' . $package->version() . ', the new page bundled with the plugin' );
		if ( $put instanceof \WP_Error ) {
			return self::kept( $event, $id, 'kept_edited', $put->get_error_code() );
		}
		$approved = HtmlFiles::approveBySystem( $id, (int) $put['version'] );
		if ( $approved instanceof \WP_Error ) {
			return self::kept( $event, $id, 'kept_draft', $approved->get_error_code() );
		}
		update_post_meta( $id, PageMaker::META_VERSION, $package->version() );
		Binding::bind( $id, $package );

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
