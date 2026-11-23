<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\DataStore\StoreData;
use WP_Error;

/**
 * Dennis, 14 Nov: every board item, idea and release carries a `project`
 * field from now on, so each project can get its own board when projects
 * (2.0 pillar 1) land, with no migration. Until then a brain has one
 * project, its default: the brain's slug (the first label of its address,
 * nautilus.tomatio.ai: "nautilus"). It is set on create (Change::write,
 * Project::release), backfilled once on upgrade (maybeBackfill), and the
 * project tools take an optional `project` that defaults to it. No project
 * screens or switching yet.
 */
final class Projects {

	/** Set once every board's items and releases carry their project. */
	public const BACKFILL_OPTION  = 'agent_brain_backlog_project_backfill';
	public const BACKFILL_VERSION = '1';

	public static function register(): void {
		add_action( 'init', [ self::class, 'maybeBackfill' ], 31 );
	}

	/** The brain's one project for now: its slug. */
	public static function slug(): string {
		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$parts = array_values( array_filter( explode( '.', $host ), static fn ( string $p ): bool => $p !== '' && $p !== 'www' ) );
		$slug  = sanitize_title( (string) ( $parts[0] ?? '' ) );

		return $slug !== '' ? $slug : 'default';
	}

	/**
	 * A project tool's optional `project`: left out, empty or the brain's
	 * own is fine; any other is not a project on this brain.
	 *
	 * @param array<string, mixed> $input
	 * @return true|WP_Error
	 */
	public static function check( array $input ) {
		$asked = isset( $input['project'] ) && is_string( $input['project'] ) ? sanitize_title( trim( $input['project'] ) ) : '';
		if ( $asked === '' || $asked === self::slug() ) {
			return true;
		}

		return new WP_Error(
			'ab_backlog_project_unknown',
			/* translators: %s: the brain's project. */
			sprintf( __( 'This brain has one project for now, %s. Leave project out.', 'tomatio' ), self::slug() ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * $value with its project: the one it has, else the brain's.
	 *
	 * @param array<string, mixed> $value
	 * @return array<string, mixed>
	 */
	public static function stamp( array $value ): array {
		if ( ! is_string( $value['project'] ?? null ) || $value['project'] === '' ) {
			$value['project'] = self::slug();
		}

		return $value;
	}

	/** Once per brain (and per BACKFILL_VERSION): every board gets its project on what it already has. */
	public static function maybeBackfill(): void {
		if ( get_option( self::BACKFILL_OPTION ) === self::BACKFILL_VERSION ) {
			return;
		}
		foreach ( Backlog::all() as $artifactId ) {
			self::backfill( (int) $artifactId );
		}
		update_option( self::BACKFILL_OPTION, self::BACKFILL_VERSION, false );
	}

	/**
	 * Give $artifactId's items (ideas included) and planned releases the
	 * brain's project where they have none. Returns how many items changed.
	 */
	public static function backfill( int $artifactId ): int {
		$by      = StoreData::anonymousAttribution();
		$changed = 0;
		foreach ( StoreData::list( $artifactId, 'items' ) as $doc ) {
			$value = is_array( $doc['value'] ?? null ) ? $doc['value'] : null;
			if ( $value === null || ( is_string( $value['project'] ?? null ) && $value['project'] !== '' ) ) {
				continue;
			}
			$done = StoreData::write( $artifactId, 'items', (string) $doc['key'], self::stamp( $value ), $by, 'replace', null, (int) $doc['version'] );
			if ( ! $done instanceof WP_Error ) {
				++$changed;
			}
		}

		$meta = StoreData::get( $artifactId, 'meta', 'project' );
		if ( $meta !== null && is_array( $meta['value'] ) && is_array( $meta['value']['releases'] ?? null ) ) {
			$project  = $meta['value'];
			$releases = array_map( static fn ( $r ) => is_array( $r ) ? self::stamp( $r ) : $r, $project['releases'] );
			if ( $releases !== $project['releases'] ) {
				$project['releases'] = $releases;
				StoreData::write( $artifactId, 'meta', 'project', $project, $by, 'replace', null, (int) $meta['version'] );
			}
		}

		return $changed;
	}
}
