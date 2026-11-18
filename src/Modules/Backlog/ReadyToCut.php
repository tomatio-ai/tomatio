<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\Extensions\Offer;
use AgentBrain\Modules\Extensions\Switches;
use AgentBrain\Modules\Review\AutoApprove;

/**
 * #1077: when every item in a board's next release is done (the board's
 * "ready to cut" banner), the person who manages the board gets one TíoDay
 * task: "1.10.0 is ready to cut: all 12 items are done", with the items and
 * a link to the board grouped by release. Once per version. The manager is
 * the person whose AI leads the board (AutoApprove::ownerFor). Runs with
 * Progress::sync (after a board change, and hourly). This replaces the
 * Nautilus bridge's release-ready task.
 */
final class ReadyToCut {

	/** Post meta on the board: the last version a task was made for. */
	public const META = 'tomatio_ready_cut_told';

	/** Statuses that leave a release, as on the page (archived, not done). */
	private const OUT = [ 'idea', 'declined', 'parked', 'archived' ];

	/**
	 * The next release and its items when every one is done; null otherwise.
	 *
	 * @return array{version: string, items: list<array{id: string, title: string}>}|null
	 */
	public static function ready( int $artifactId ): ?array {
		$items       = StoreData::list( $artifactId, 'items' );
		[ $project ] = Project::project( $artifactId );
		$choices     = Releases::choices( $artifactId );
		$plan        = Releases::plan( $project, $items, $choices );
		if ( $plan['next'] === '' ) {
			return null;
		}
		$verdicts = [];
		foreach ( StoreData::list( $artifactId, 'verdicts' ) as $doc ) {
			$verdicts[ (string) $doc['key'] ] = is_array( $doc['value'] ) ? (string) ( $doc['value']['status'] ?? '' ) : '';
		}

		$in = [];
		foreach ( $items as $doc ) {
			$value   = is_array( $doc['value'] ) ? $doc['value'] : [];
			$status  = (string) ( $value['status'] ?? 'inbox' );
			$verdict = $verdicts[ (string) $doc['key'] ] ?? '';
			if ( in_array( $status, self::OUT, true ) || $verdict === 'drop' || Releases::resolve( Releases::setOf( $doc, $choices ), $plan ) !== $plan['next'] ) {
				continue;
			}
			if ( $status !== 'shipped' && $verdict !== 'done' ) {
				return null;
			}
			$in[] = [
				'id'    => is_string( $value['id'] ?? null ) ? $value['id'] : (string) $doc['key'],
				'title' => (string) ( $value['title'] ?? '' ),
			];
		}

		usort( $in, static fn ( array $a, array $b ): int => strnatcmp( $a['id'], $b['id'] ) );

		return $in === [] ? null : [ 'version' => $plan['next'], 'items' => $in ];
	}

	/** Tell the board's manager once when its next release is ready to cut. True when a task was made. */
	public static function check( int $artifactId ): bool {
		if ( ! Offer::software() || ! Switches::enabled( Switches::TIODAY ) ) {
			return false;
		}
		$ready = self::ready( $artifactId );
		if ( $ready === null || (string) get_post_meta( $artifactId, self::META, true ) === $ready['version'] ) {
			return false;
		}
		$manager = AutoApprove::ownerFor( (int) get_post_meta( $artifactId, Cpts::META_LEAD_AGENT, true ) );
		$tioday  = $manager > 0 ? TioDay::forPerson( $manager ) : 0;
		if ( $tioday <= 0 ) {
			return false;
		}

		$n     = count( $ready['items'] );
		$url   = Assignees::url( $artifactId );
		$lines = array_map( static fn ( array $i ): string => $i['id'] . ' ' . $i['title'], array_slice( $ready['items'], 0, 30 ) );
		$task  = [
			/* translators: 1: version, 2: how many items. */
			'title'   => TioDay::text( sprintf( _n( '%1$s is ready to cut: its %2$d item is done', '%1$s is ready to cut: all %2$d items are done', $n, 'tomatio' ), $ready['version'], $n ), TioDay::MAX_TITLE ),
			'why'     => TioDay::text( implode( "\n", $lines ) . ( $n > 30 ? "\n…" : '' ), TioDay::MAX_TEXT, true ),
			'how'     => TioDay::text( __( 'Cut and tag the release, then ship it. Tomagic shows the items under Group by Release.', 'tomatio' ), TioDay::MAX_TEXT, true ),
			'links'   => str_starts_with( $url, 'https://' ) ? [ [ 'label' => 'Tomagic', 'url' => $url . '#group=release' ] ] : [],
			'group'   => 'now',
			'who'     => TioDay::name( $manager ),
			'status'  => 'open',
			'added'   => gmdate( 'Y-m-d' ),
			'done_on' => null,
			'order'   => 0,
			'board'   => $artifactId,
		];
		$slug   = 'ready-to-cut-' . trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $ready['version'] ) ), '-' );
		$stored = StoreData::set( $tioday, 'tasks', $slug, $task, StoreData::anonymousAttribution(), null, 0 );
		if ( $stored instanceof \WP_Error ) {
			// Already there (a second request at the same moment): told.
			update_post_meta( $artifactId, self::META, $ready['version'] );

			return false;
		}
		update_post_meta( $artifactId, self::META, $ready['version'] );

		return true;
	}
}
