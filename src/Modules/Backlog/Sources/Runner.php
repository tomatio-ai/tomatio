<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

use AgentBrain\Modules\Backlog\Backlog;
use AgentBrain\Modules\Backlog\Change;
use AgentBrain\Modules\Backlog\Item;
use AgentBrain\Modules\Backlog\Project;
use AgentBrain\Modules\Backlog\Rank;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use WP_Error;

/**
 * #892 (sections 652 and 665): the source runner. A source's first run is
 * a full read, in two steps:
 *
 *  1. the dry run (plan()) reads the sources and works out the Plan, and
 *     writes nothing anywhere;
 *  2. the real run (apply()) works the plan out again and, only when its
 *     fingerprint is the one the person said yes to, writes it into the
 *     backlog: new items (placed, marked placed: import, one "read" log
 *     line each), refreshed snapshots and flags on known items, the links
 *     between TíoDay feature tasks and their items, and one integrations/
 *     <source> document per source.
 *
 * Nothing is written to a tool: the issues source is read-only, and the
 * Notion writes the report lists are step 5. Running again is safe, since
 * every item carries its source IDs: a second run updates, never
 * duplicates.
 */
final class Runner {

	/**
	 * Read the sources and work out the plan.
	 *
	 * @param array{sources?: array<int, string>, issues_json?: array<string, mixed>|null, notion_json?: array<string, mixed>|null, repo?: string, host?: string, tioday?: int, now?: string} $options
	 * @return array<string, mixed>|WP_Error
	 */
	public static function plan( int $backlogId, array $options ) {
		$sources = $options['sources'] ?? [ 'issues', 'notion', 'tioday' ];
		$plan    = new Plan( Plan::itemsOf( $backlogId ), $options['now'] ?? null );
		$fetch   = static fn ( int $n ): ?array => null;

		$issuesManifest = Manifest::bundled( 'issues' );
		$notionManifest = Manifest::bundled( 'notion' );
		if ( is_string( $issuesManifest ) || is_string( $notionManifest ) ) {
			return new WP_Error( 'ab_backlog_source', is_string( $issuesManifest ) ? $issuesManifest : (string) $notionManifest );
		}

		$read = [];
		if ( in_array( 'issues', $sources, true ) ) {
			$source = IssuesSource::configured( $options['issues_json'] ?? null, (string) ( $options['repo'] ?? '' ), (string) ( $options['host'] ?? '' ) );
			if ( is_string( $source ) ) {
				$plan->note( 'Issues: skipped. ' . $source );
			} else {
				$got = $source->read( $issuesManifest );
				if ( $got instanceof WP_Error ) {
					return $got;
				}
				foreach ( $got['notes'] as $note ) {
					$plan->note( 'Issues: ' . $note );
				}
				$plan->addIssues( $got['records'] );
				$closes = $got['closes'];
				$fetch  = static fn ( int $n ): ?array => $source->one( $n, $issuesManifest, $closes );
				$read[] = 'issues';
			}
		}

		$columns = null;
		if ( in_array( 'notion', $sources, true ) ) {
			$source = NotionSource::configured( $options['notion_json'] ?? null );
			if ( is_string( $source ) ) {
				$plan->note( 'Notion: skipped. ' . $source );
			} else {
				$got = $source->read( $notionManifest );
				if ( $got instanceof WP_Error ) {
					return $got;
				}
				foreach ( $got['notes'] as $note ) {
					$plan->note( 'Notion: ' . $note );
				}
				$plan->addNotion( $got['records'], $fetch );
				$columns = $got['columns'];
				$read[]  = 'notion';
			}
		}

		$tiodayId = (int) ( $options['tioday'] ?? 0 );
		if ( in_array( 'tioday', $sources, true ) && $tiodayId > 0 ) {
			$got = ( new TioDaySource( $tiodayId ) )->read();
			if ( is_string( $got ) ) {
				$plan->note( 'TíoDay: skipped. ' . $got );
			} else {
				foreach ( $got['notes'] as $note ) {
					$plan->note( 'TíoDay: ' . $note );
				}
				$plan->addTioDay( $got['records'], $fetch );
				$read[] = 'tioday';
			}
		} elseif ( in_array( 'tioday', $sources, true ) ) {
			$plan->note( 'TíoDay: skipped. Pass --tioday=<artifact id> to link its feature tasks.' );
		}

		$out                  = $plan->build();
		$out['backlog']       = $backlogId;
		$out['read']          = $read;
		$out['notion_writes'] = $columns !== null ? self::notionWrites( $out['items'], $notionManifest, $columns ) : null;
		$out['fingerprint']   = Plan::fingerprint( $out );
		$out['tioday']        = $tiodayId;

		return $out;
	}

	/**
	 * What a sync would write to Notion (step 5): the columns it adds, the
	 * rows it changes, the rows it creates. Listed in the report so the
	 * person's yes covers them later; never sent by this version.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, string>            $columns
	 * @return array<string, mixed>
	 */
	public static function notionWrites( array $items, Manifest $manifest, array $columns ): array {
		$changed = [];
		$created = [];
		foreach ( $items as $item ) {
			$accepted = in_array( $item['status'], [ 'ready', 'building', 'shipped', 'declined', 'parked', 'archived' ], true );
			if ( $item['notion'] === null ) {
				if ( $item['op'] === 'create' && in_array( $item['status'], [ 'ready', 'building' ], true ) ) {
					$created[] = $item['title'];
				}
				continue;
			}
			if ( ! $accepted || $item['op'] !== 'create' ) {
				continue;
			}
			$fields = [];
			$band   = (string) ( $item['band'] ?? '' );
			if ( $band !== '' && mb_strtolower( (string) $item['notion']['band'] ) !== $band ) {
				$fields['Band'] = [ (string) $item['notion']['band'], ucfirst( $band ) ];
			}
			if ( $band !== '' ) {
				$fields['Rank'] = [ (string) ( $item['notion']['written']['rank'] ?? '' ), 'its place in ' . ucfirst( $band ) ];
			}
			$fields['B ID']    = [ (string) ( $item['notion']['written']['b_id'] ?? '' ), 'its new B ID' ];
			$fields['TomaTío'] = [ (string) ( $item['notion']['written']['link'] ?? '' ), 'the item\'s link' ];
			if ( $item['status'] === 'archived' ) {
				$fields['Status'] = [ '', 'Archived' ];
			}
			$changed[] = [ 'row' => $item['notion']['id'], 'title' => $item['title'], 'fields' => $fields ];
		}

		return [
			'step'    => 5,
			'columns' => NotionSource::missingColumns( $manifest, $columns ),
			'statuses' => array_values( (array) $manifest->get( 'adds_statuses' ) ),
			'changed' => $changed,
			'created' => $created,
		];
	}

	/**
	 * The real run: work the plan out again and write it, when its
	 * fingerprint is $yes. $runAs is the account the changes are made as
	 * (an agent that can write the backlog), $for the person it acts for.
	 *
	 * @param array<string, mixed> $options As plan().
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply( int $backlogId, array $options, string $yes, int $runAs, string $for = '' ) {
		if ( ! Backlog::isBacklog( $backlogId ) ) {
			return new WP_Error( 'ab_backlog_not_found', 'Artifact ' . $backlogId . ' is not a backlog. Make one first: wp tomatio backlog create.' );
		}
		if ( ! Backlog::canWrite( $runAs, $backlogId ) ) {
			return new WP_Error( 'ab_forbidden', 'The account the read runs as cannot write this backlog.' );
		}
		$plan = self::plan( $backlogId, $options );
		if ( $plan instanceof WP_Error ) {
			return $plan;
		}
		if ( $yes === '' || ! hash_equals( (string) $plan['fingerprint'], $yes ) ) {
			return new WP_Error( 'ab_backlog_plan_changed', 'The plan is not the one approved (its fingerprint is now ' . $plan['fingerprint'] . '). Run the dry run again, read the report, and approve that one.' );
		}

		$user  = get_userdata( $runAs );
		$login = $user instanceof \WP_User ? (string) $user->user_login : 'source';
		$by    = StoreData::attributionFromUser( $runAs, $backlogId );
		$now   = time();
		$at    = gmdate( 'Y-m-d\TH:i:s\Z', $now );
		[ $project ] = Project::project( $backlogId );

		$ranks = self::ranks( $backlogId, $plan['items'], $project );
		$ids   = [];
		$done  = [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'tioday_linked' => 0, 'failed' => [] ];

		// Epics first, so their issues can name them as parent.
		$items = $plan['items'];
		usort( $items, static fn ( array $a, array $b ): int => ( $a['kind'] === 'epic' ? 0 : 1 ) <=> ( $b['kind'] === 'epic' ? 0 : 1 ) );

		foreach ( $items as $item ) {
			if ( $item['op'] === 'unchanged' ) {
				++$done['unchanged'];
				$ids[ $item['anchor'] ] = (string) $item['id'];
				self::linkTask( $item, (string) $item['id'], $runAs, $done );
				continue;
			}
			$line = [
				'what' => $item['op'] === 'create' ? 'read' : 'snapshot',
				'by'   => $login,
				'for'  => $for,
				'via'  => 'first read: ' . implode( ', ', $item['from'] ),
				'from' => null,
				'to'   => $item['op'] === 'create' ? ( $item['band'] ?? $item['status'] ) : null,
			];

			if ( $item['op'] === 'create' ) {
				$value = self::newItem( $item, $ranks[ $item['anchor'] ] ?? null, $item['parent'] !== '' ? ( $ids[ $item['parent'] ] ?? '' ) : '', $login, $at );
				$wrote = Change::write( $backlogId, Project::freeKey( $backlogId, $item['title'] ), $value, 0, $line, $by, $now );
			} else {
				$current = StoreData::get( $backlogId, 'items', (string) $item['key'] );
				if ( $current === null ) {
					$done['failed'][] = $item['title'] . ': gone';
					continue;
				}
				$value = self::refreshed( is_array( $current['value'] ) ? $current['value'] : [], $item, $at );
				$wrote = Change::write( $backlogId, (string) $item['key'], $value, (int) $current['version'], $line, $by, $now );
			}
			if ( $wrote instanceof WP_Error ) {
				$done['failed'][] = $item['title'] . ': ' . $wrote->get_error_message();
				continue;
			}
			++$done[ $item['op'] === 'create' ? 'created' : 'updated' ];
			$id                     = is_array( $wrote['item']['value'] ) ? (string) ( $wrote['item']['value']['id'] ?? '' ) : '';
			$ids[ $item['anchor'] ] = $id;
			self::linkTask( $item, $id, $runAs, $done );
		}

		foreach ( $plan['read'] as $source ) {
			StoreData::set(
				$backlogId,
				'integrations',
				$source,
				[
					'source'      => $source,
					'key'         => $source === 'tioday' ? 'tioday:' . $plan['tioday'] : ( $source === 'notion' ? NotionSource::KEY : Config::key( 'issues' ) ),
					'on'          => true,
					'last_read'   => $at,
					'fingerprint' => $plan['fingerprint'],
					'counts'      => $plan['sources'][ $source ] ?? [],
					'by'          => $login . ( $for !== '' ? ' for ' . $for : '' ),
				],
				$by
			);
		}
		Change::note( $backlogId, 'project', [ 'what' => 'read', 'by' => $login, 'for' => $for, 'via' => 'first read', 'to' => $done['created'] . ' new, ' . $done['updated'] . ' updated' ], $by, $now );

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $runAs,
				'event'       => 'backlog.read',
				'status'      => $done['failed'] === [] ? 'ok' : 'partial',
				'object_type' => 'artifact',
				'object_id'   => (string) $backlogId,
				'meta'        => [ 'fingerprint' => $plan['fingerprint'], 'created' => $done['created'], 'updated' => $done['updated'], 'failed' => count( $done['failed'] ) ],
			]
		);

		return $done + [ 'fingerprint' => $plan['fingerprint'] ];
	}

	/**
	 * Ranks for the new items of each band, in plan order, below the items
	 * already in that band.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, mixed>             $project
	 * @return array<string, string> anchor => rank
	 */
	private static function ranks( int $backlogId, array $items, array $project ): array {
		$out = [];
		foreach ( Rank::BANDS as $band ) {
			$new = array_values( array_filter( $items, static fn ( array $i ): bool => $i['op'] === 'create' && ( $i['band'] ?? null ) === $band ) );
			if ( $new === [] ) {
				continue;
			}
			[ $lo, $hi ] = Rank::bounds( $band, $project );
			foreach ( StoreData::list( $backlogId, 'items' ) as $doc ) {
				$r = is_array( $doc['value'] ) ? ( $doc['value']['rank'] ?? null ) : null;
				if ( Rank::isRank( $r ) && Rank::band( $r, $project ) === $band && ( $lo === null || strcmp( $r, $lo ) > 0 ) ) {
					$lo = $r;
				}
			}
			$spread = Rank::spread( $lo, $hi, count( $new ) );
			foreach ( $new as $i => $item ) {
				if ( isset( $spread[ $i ] ) ) {
					$out[ $item['anchor'] ] = $spread[ $i ];
				}
			}
		}

		return $out;
	}

	/**
	 * A new item from a plan item.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function newItem( array $item, ?string $rank, string $parent, string $login, string $at ): array {
		$links = TioDay::links( array_slice( $item['links'], 0, TioDay::MAX_LINKS ) );
		$kind  = in_array( $item['kind'], Item::KINDS, true ) ? $item['kind'] : 'feature';
		$value = [
			'kind'     => $kind,
			'title'    => TioDay::text( $item['title'], Item::MAX_TITLE ),
			'detail'   => TioDay::text( $item['detail'], Item::MAX_DETAIL, true ),
			'urgency'  => 'later',
			'rank'     => in_array( $item['status'], Item::RANKED, true ) ? $rank : null,
			'status'   => $item['status'],
			'links'    => $links instanceof WP_Error ? [] : $links,
			'added_by' => $login . ' (first read)',
			'added_at' => $at,
			'sources'  => $item['sources'] !== [] ? $item['sources'] : new \stdClass(),
			'tags'     => Item::words( $item['tags'], Item::MAX_TAGS ),
			'aliases'  => [],
			'flags'    => $item['flags'],
		];
		if ( in_array( $item['status'], Item::RANKED, true ) && $rank === null ) {
			$value['status'] = 'inbox';
		}
		if ( $value['rank'] !== null ) {
			$value['placed'] = 'import';
		}
		foreach ( [ 'release', 'area', 'owner' ] as $field ) {
			if ( $item[ $field ] !== '' ) {
				$value[ $field ] = TioDay::text( $item[ $field ], TioDay::MAX_NAME );
			}
		}
		foreach ( $item['aliases'] as $alias ) {
			$value = Item::withAlias( $value, (string) $alias );
		}
		if ( $item['tioday'] !== '' ) {
			$value['tioday'] = $item['tioday'];
		}
		if ( $parent !== '' ) {
			$value['parent'] = $parent;
		}

		return $value;
	}

	/**
	 * A known item with fresh source entries, its aliases, flags and TíoDay
	 * link; rank, status and the rest stay as people set them.
	 *
	 * @param array<string, mixed> $value
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function refreshed( array $value, array $item, string $at ): array {
		$sources = is_array( $value['sources'] ?? null ) ? $value['sources'] : [];
		foreach ( $item['sources'] as $key => $entry ) {
			$old             = is_array( $sources[ $key ] ?? null ) ? $sources[ $key ] : [];
			$entry['synced_at'] = $at;
			if ( isset( $old['first_read'] ) ) {
				$entry['first_read'] = $old['first_read'];
			}
			if ( isset( $old['main'] ) ) {
				$entry['main'] = $old['main'];
			}
			$sources[ $key ] = $entry;
		}
		$value['sources'] = $sources;
		foreach ( $item['aliases'] as $alias ) {
			$value = Item::withAlias( $value, (string) $alias );
		}
		foreach ( $item['flags'] as $flag ) {
			$value = Item::withFlag( $value, $flag );
		}
		if ( $item['tioday'] !== '' ) {
			$value['tioday'] = $item['tioday'];
		}

		return $value;
	}

	/**
	 * The TíoDay task names its item (item: B12); it is never closed or moved.
	 *
	 * @param array<string, mixed> $item
	 * @param array<string, mixed> $done
	 */
	private static function linkTask( array $item, string $id, int $runAs, array &$done ): void {
		$task = $item['tiodayTask'];
		if ( ! is_array( $task ) || $id === '' || $task[2] === $id ) {
			return;
		}
		$wrote = StoreData::update( (int) $task[0], 'tasks', (string) $task[1], [ 'item' => $id ], StoreData::attributionFromUser( $runAs, (int) $task[0] ) );
		if ( $wrote instanceof WP_Error ) {
			$done['failed'][] = 'TíoDay ' . $task[1] . ': ' . $wrote->get_error_message();

			return;
		}
		++$done['tioday_linked'];
	}
}
