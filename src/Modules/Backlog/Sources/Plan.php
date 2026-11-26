<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

use AgentBrain\Modules\Backlog\Item;
use AgentBrain\Modules\DataStore\StoreData;

/**
 * #892 (section 652): what a read would do, worked out without writing
 * anything. The dry run prints it as the report; the real run works it out
 * again and applies it only when its fingerprint is the one the person
 * said yes to.
 *
 * Merging: the same external ID is the same item. When a Notion row or a
 * TíoDay task names an issue, the issue is the anchor and the row or task
 * adds what the item lacks; the item ends with every source entry. A row or
 * task naming several issues anchors on the first, the rest become links.
 * Two rows (or tasks) on one issue, or look-alike titles that share no ID,
 * are never merged: they are listed as "maybe the same".
 *
 * Placing (sources never rank): new items get a band and an order inside
 * it, marked placed: import, and people rank from there. Now: open TíoDay
 * feature tasks in Now and This week. Next: Notion Planned and In
 * progress (or the row's Band). Later: everything else accepted somewhere.
 * The Inbox: issues nothing points at. Shipped and declined rows come in
 * off the list. Existing items keep their rank and status: a re-run only
 * refreshes snapshots and flags what clashes.
 */
final class Plan {

	/** Above this similarity (0-100), two titles with no shared ID are "maybe the same". */
	public const SIMILAR = 88;

	/** @var array<string, array<string, mixed>> anchor => draft */
	private array $drafts = [];

	/** @var array<string, array<string, mixed>> "key|id" => existing items document */
	private array $existing = [];

	/** @var array<string, array<string, mixed>> B12 => existing items document */
	private array $existingById = [];

	/** @var array<string, mixed> */
	private array $report = [
		'sources'     => [],
		'maybe_same'  => [],
		'unmapped'    => [],
		'unanchored'  => [],
		'missing'     => [],
		'notes'       => [],
	];

	private string $now;

	/**
	 * @param array<int, array<string, mixed>> $items The backlog's items documents (none before a first read).
	 */
	public function __construct( array $items, ?string $now = null ) {
		$this->now = $now ?? gmdate( 'Y-m-d\TH:i:s\Z' );
		foreach ( $items as $doc ) {
			$v = is_array( $doc['value'] ?? null ) ? $doc['value'] : [];
			if ( is_string( $v['id'] ?? null ) ) {
				$this->existingById[ strtoupper( $v['id'] ) ] = $doc;
			}
			foreach ( is_array( $v['sources'] ?? null ) ? $v['sources'] : [] as $key => $entry ) {
				if ( is_array( $entry ) && isset( $entry['id'] ) ) {
					$this->existing[ $key . '|' . $entry['id'] ] = $doc;
				}
			}
			if ( is_string( $v['tioday'] ?? null ) && $v['tioday'] !== '' ) {
				$this->existing[ 'tioday|' . $v['tioday'] ] = $doc;
			}
		}
	}

	/**
	 * The issues, first: each open issue is a draft (an existing item when
	 * its ID is known).
	 *
	 * @param array<int, array<string, mixed>> $records
	 */
	public function addIssues( array $records ): void {
		$this->count( 'issues', 'read', count( $records ) );
		foreach ( $records as $record ) {
			$this->count( 'issues', isset( $this->existing[ $record['key'] . '|' . $record['id'] ] ) ? 'known' : 'new', 1 );
			$this->issueDraft( $record );
		}
	}

	/**
	 * Notion rows: merged onto the issue they name, else items of their own.
	 *
	 * @param array<int, array<string, mixed>>  $records
	 * @param callable(int): ?array<string, mixed> $issue Fetches a closed issue a row names (null: unknown).
	 */
	public function addNotion( array $records, callable $issue ): void {
		$this->count( 'notion', 'read', count( $records ) );
		foreach ( $records as $record ) {
			if ( isset( $record['unmapped_status'] ) ) {
				$this->report['unmapped'][ 'Notion status "' . $record['unmapped_status'] . '"' ] = ( $this->report['unmapped'][ 'Notion status "' . $record['unmapped_status'] . '"' ] ?? 0 ) + 1;
			}
			$this->attach( $record, $issue );
		}
	}

	/**
	 * TíoDay feature tasks: merged onto the issue they name (or the item
	 * they already point at), else items of their own.
	 *
	 * @param array<int, array<string, mixed>>  $records
	 * @param callable(int): ?array<string, mixed> $issue
	 */
	public function addTioDay( array $records, callable $issue ): void {
		$this->count( 'tioday', 'read', count( $records ) );
		foreach ( $records as $record ) {
			$this->attach( $record, $issue );
		}
	}

	public function note( string $note ): void {
		$this->report['notes'][] = $note;
	}

	/**
	 * The plan: every draft with its operation, ordered as it would be
	 * placed, plus the report's lists and the fingerprint of what would be
	 * written.
	 *
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$this->parents();
		$this->maybeSame();

		$items = [];
		foreach ( $this->drafts as $anchor => $draft ) {
			$items[] = $this->finish( $anchor, $draft );
		}
		usort( $items, [ self::class, 'order' ] );

		$counts = [ 'create' => 0, 'update' => 0, 'unchanged' => 0 ];
		$bands  = [ 'now' => [], 'next' => [], 'later' => [], 'inbox' => [], 'off' => [] ];
		foreach ( $items as $item ) {
			++$counts[ $item['op'] ];
			if ( $item['op'] === 'create' ) {
				$bands[ $item['band'] ?? ( $item['status'] === 'inbox' ? 'inbox' : 'off' ) ][] = $item['title'];
			}
		}
		foreach ( [ 'issues', 'notion', 'tioday' ] as $source ) {
			$this->report['sources'][ $source ] = ( $this->report['sources'][ $source ] ?? [] ) + [ 'read' => 0, 'new' => 0, 'merged' => 0, 'known' => 0, 'closed' => 0 ];
		}
		$this->report['issues_alone'] = array_values(
			array_map(
				static fn ( array $i ): string => (string) ( $i['from'][0] ?? $i['title'] ),
				array_filter( $items, static fn ( array $i ): bool => $i['op'] === 'create' && count( $i['from'] ) === 1 && str_starts_with( (string) $i['from'][0], '#' ) )
			)
		);

		$plan = [
			'at'       => $this->now,
			'counts'   => $counts,
			'items'    => $items,
			'proposed' => $bands,
			'up_next'  => array_slice( $bands['now'], 0, 5 ),
		] + $this->report;

		$plan['fingerprint'] = self::fingerprint( $plan );

		return $plan;
	}

	/**
	 * What the person says yes to: a hash of every write the real run would
	 * make (items, their sources and places, the TíoDay links and the Notion
	 * writes), not of when the plan was made.
	 *
	 * @param array<string, mixed> $plan
	 */
	public static function fingerprint( array $plan ): string {
		$items = array_map(
			static function ( array $i ): array {
				$sources = [];
				foreach ( $i['sources'] as $key => $entry ) {
					$sources[ $key ] = [ $entry['id'] ?? '', $entry['updated_at'] ?? '' ];
				}
				ksort( $sources );

				$flags = array_map( static fn ( array $f ): string => $f['what'] . ' ' . $f['detail'], $i['flags'] );

				return [ $i['op'], $i['key'] ?? '', $i['title'], $i['status'], $i['band'] ?? '', $i['parent'] ?? '', $i['tioday'] ?? '', $sources, $flags ];
			},
			$plan['items']
		);

		return substr( hash( 'sha256', (string) wp_json_encode( [ $items, $plan['notion_writes'] ?? [] ] ) ), 0, 12 );
	}

	// -----------------------------------------------------------------

	/** @param array<string, mixed> $record */
	private function issueDraft( array $record ): string {
		$anchor = 'issue:' . $record['id'];
		if ( isset( $this->drafts[ $anchor ] ) ) {
			return $anchor;
		}
		$draft           = $this->blank( $record );
		$draft['issue']  = $record;
		$draft['status'] = 'inbox';
		$draft['band']   = null;
		$draft['rankBy'] = [ 9, $record['created_at'] ];
		$draft['tags']   = $record['labels'];
		$draft['parentOf'] = $record['children'];
		$this->drafts[ $anchor ] = $draft;
		// Its own source entry and #N alias, as for any other record: they
		// are how a re-run knows the item and how "#12" finds it.
		$this->merge( $anchor, $record );

		return $anchor;
	}

	/**
	 * A row or task: onto its known item, the issue it names, or its own draft.
	 *
	 * @param array<string, mixed> $record
	 * @param callable(int): ?array<string, mixed> $issue
	 */
	private function attach( array $record, callable $issue ): void {
		$source = (string) $record['source'];
		$anchor = '';

		// Known already (a re-run): stay on the same item.
		$known = $this->existing[ $record['key'] . '|' . $record['id'] ] ?? null;
		if ( $known === null && is_string( $record['item'] ?? null ) && $record['item'] !== '' ) {
			$known = $this->existingById[ strtoupper( $record['item'] ) ] ?? null;
		}
		if ( $known !== null ) {
			foreach ( $this->drafts as $a => $d ) {
				if ( $d['existing'] !== null && $d['existing']['key'] === $known['key'] ) {
					$anchor = $a;
					break;
				}
			}
		}

		$refs = array_values( $record['refs'] );
		if ( $anchor === '' && $known === null && $refs !== [] ) {
			$first = (int) $refs[0];
			$a     = 'issue:' . $first;
			if ( ! isset( $this->drafts[ $a ] ) ) {
				$closed = $issue( $first );
				if ( $closed !== null ) {
					$this->count( 'issues', 'closed', 1 );
					$a = $this->issueDraft( $closed );
				} else {
					$this->report['missing'][] = $record['alias'] . ' names #' . $first . ', which was not read';
					$a = '';
				}
			}
			if ( $a !== '' ) {
				$taken = $source === 'tioday' ? ( $this->drafts[ $a ]['tioday'] ?? '' ) !== '' : isset( $this->drafts[ $a ]['sources'][ $record['key'] ] );
				if ( $taken ) {
					$this->report['maybe_same'][] = [ $record['alias'], $this->label( $this->drafts[ $a ] ), 'both name #' . $first ];
				} else {
					$anchor = $a;
				}
			}
			foreach ( array_slice( $refs, 1 ) as $other ) {
				$record['more_links'][] = (int) $other;
			}
		}

		if ( $anchor === '' && $known !== null ) {
			$anchor                              = $source . ':' . $record['id'];
			$this->drafts[ $anchor ]             = $this->blank( $record );
			$this->drafts[ $anchor ]['existing'] = $known;
			$this->count( $source, 'known', 1 );
			$this->merge( $anchor, $record );

			return;
		}

		if ( $anchor === '' ) {
			$anchor = $source . ':' . $record['id'];
			$this->drafts[ $anchor ] = $this->blank( $record );
			if ( $refs === [] ) {
				$this->report['unanchored'][] = $record['alias'] . ' ' . $record['title'];
			}
			$this->count( $source, 'new', 1 );
		} else {
			$this->count( $source, $this->drafts[ $anchor ]['existing'] !== null ? 'known' : 'merged', 1 );
		}

		$this->merge( $anchor, $record );
	}

	/**
	 * Add $record's fields to the draft at $anchor where it has none, and
	 * its source entry (TíoDay: the task link instead).
	 *
	 * @param array<string, mixed> $record
	 */
	private function merge( string $anchor, array $record ): void {
		$d =& $this->drafts[ $anchor ];
		$source = (string) $record['source'];
		foreach ( [ 'area', 'owner', 'release' ] as $field ) {
			if ( ( $d[ $field ] ?? '' ) === '' && ( $record[ $field ] ?? '' ) !== '' ) {
				$d[ $field ] = $record[ $field ];
			}
		}
		if ( ( $d['detail'] ?? '' ) === '' && $record['detail'] !== '' ) {
			$d['detail'] = $record['detail'];
		}
		if ( ! in_array( $record['alias'], $d['from'], true ) ) {
			$d['from'][] = $record['alias'];
		}
		foreach ( $record['more_links'] ?? [] as $n ) {
			$d['also'][] = $n;
		}

		if ( $source === 'tioday' ) {
			$d['tioday']      = $record['id'];
			$d['tiodayTask']  = [ (int) $record['artifact'], (string) $record['task_key'], (string) ( $record['item'] ?? '' ) ];
			$d['links']       = array_merge( $d['links'], is_array( $record['links'] ?? null ) ? $record['links'] : [] );
			if ( $record['band'] === 'now' ) {
				$d['status'] = 'ready';
				$d['band']   = 'now';
				$d['rankBy'] = [ $record['group'] === 'now' ? 0 : 1, $d['rankBy'][1] ];
			} elseif ( $d['band'] === null && ! in_array( $d['status'], [ 'shipped', 'declined', 'parked', 'archived' ], true ) ) {
				$d['status'] = 'ready';
				$d['band']   = 'later';
				$d['rankBy'] = [ 6, $d['rankBy'][1] ];
			}

			return;
		}

		$d['sources'][ $record['key'] ] = $this->entry( $record, $d['sources'] === [] );
		if ( $source === 'notion' ) {
			$d['notion'] = $record;
			$this->placeFromNotion( $d, $record );
		}
	}

	/**
	 * Notion's status and Band decide a new item's place, unless TíoDay put
	 * it in Now.
	 *
	 * @param array<string, mixed> $d
	 * @param array<string, mixed> $record
	 */
	private function placeFromNotion( array &$d, array $record ): void {
		if ( $d['band'] === 'now' && $d['tioday'] !== '' ) {
			return;
		}
		$status = $record['status'];
		if ( in_array( $status, [ 'shipped', 'declined', 'parked', 'archived' ], true ) ) {
			$d['status'] = $status;
			$d['band']   = null;

			return;
		}
		$d['status'] = $status === 'building' ? 'building' : 'ready';
		$band        = $record['band'] ?? null;
		if ( $band === null ) {
			$band = in_array( $status, [ 'ready', 'building' ], true ) ? 'next' : 'later';
		}
		$d['band']   = $band;
		$d['rankBy'] = [ $band === 'now' ? 2 : ( $status === 'building' ? 3 : ( $band === 'next' ? 4 : 7 ) ), $d['rankBy'][1] ];
	}

	/** Epics' task lists give their issues a parent. */
	private function parents(): void {
		foreach ( $this->drafts as $anchor => $draft ) {
			foreach ( $draft['parentOf'] ?? [] as $n ) {
				$child = 'issue:' . (int) $n;
				if ( isset( $this->drafts[ $child ] ) && $child !== $anchor && ( $this->drafts[ $child ]['parent'] ?? '' ) === '' ) {
					$this->drafts[ $child ]['parent'] = $anchor;
				}
			}
		}
	}

	/** Look-alike titles on new drafts that share no ID. */
	private function maybeSame(): void {
		$titles = [];
		foreach ( $this->drafts as $anchor => $draft ) {
			if ( $draft['existing'] === null ) {
				$titles[ $anchor ] = self::normal( (string) $draft['title'] );
			}
		}
		$anchors = array_keys( $titles );
		$n       = count( $anchors );
		for ( $i = 0; $i < $n; ++$i ) {
			for ( $j = $i + 1; $j < $n; ++$j ) {
				$a = $titles[ $anchors[ $i ] ];
				$b = $titles[ $anchors[ $j ] ];
				if ( $a === '' || $b === '' || abs( strlen( $a ) - strlen( $b ) ) > 20 ) {
					continue;
				}
				similar_text( $a, $b, $pct );
				if ( $a === $b || $pct >= self::SIMILAR ) {
					$this->report['maybe_same'][] = [ $this->label( $this->drafts[ $anchors[ $i ] ] ), $this->label( $this->drafts[ $anchors[ $j ] ] ), 'look-alike titles' ];
				}
			}
		}
	}

	/**
	 * The draft as a plan item: create (new), update (a known item whose
	 * source changed) or unchanged.
	 *
	 * @param array<string, mixed> $d
	 * @return array<string, mixed>
	 */
	private function finish( string $anchor, array $d ): array {
		$parent = isset( $d['parent'] ) && $d['parent'] !== '' ? $d['parent'] : '';
		$links  = $d['links'];
		$issues = $d['issue'] !== null ? $d['issue'] : null;
		$base   = $issues !== null ? preg_replace( '~/issues/\d+$~', '', (string) $issues['url'] ) : '';
		foreach ( array_unique( $d['also'] ?? [] ) as $n ) {
			if ( $base !== '' ) {
				$links[] = [ 'label' => '#' . $n, 'url' => $base . '/issues/' . $n ];
			}
		}

		$item = [
			'anchor'  => $anchor,
			'op'      => 'create',
			'key'     => null,
			'id'      => null,
			'title'   => (string) $d['title'],
			'kind'    => (string) $d['kind'],
			'status'  => (string) $d['status'],
			'band'    => $d['band'],
			'rankBy'  => $d['rankBy'],
			'detail'  => (string) $d['detail'],
			'release' => (string) ( $d['release'] ?? '' ),
			'area'    => (string) ( $d['area'] ?? '' ),
			'owner'   => (string) ( $d['owner'] ?? '' ),
			'tags'    => $d['tags'],
			'links'   => $links,
			'sources' => $d['sources'],
			'aliases' => array_values( array_filter( $d['from'], static fn ( $a ): bool => ! preg_match( '/^T\d+$/', (string) $a ) ) ),
			'tioday'  => (string) $d['tioday'],
			'tiodayTask' => $d['tiodayTask'],
			'parent'  => $parent,
			'from'    => $d['from'],
			'flags'   => [],
			'notion'  => $d['notion'] !== null ? [ 'id' => $d['notion']['id'], 'page_id' => $d['notion']['page_id'], 'band' => $d['notion']['snapshot']['band'] ?? '', 'written' => $d['notion']['written'] ] : null,
		];

		// A tool fact against what a person set: flag it, never overwrite.
		$state = (string) ( $issues['snapshot']['state'] ?? '' );
		if ( $state === 'closed' && ! in_array( $item['status'], [ 'shipped', 'declined', 'archived' ], true ) ) {
			$item['flags'][] = Item::flag( 'closed_unshipped', $issues['alias'] . ' is closed, the item is not shipped', $this->now );
		}

		$existing = $d['existing'];
		if ( $existing === null ) {
			return $item;
		}

		$v                = is_array( $existing['value'] ) ? $existing['value'] : [];
		$item['key']      = (string) $existing['key'];
		$item['id']       = $v['id'] ?? null;
		$item['version']  = (int) $existing['version'];
		$item['status']   = (string) ( $v['status'] ?? 'inbox' );
		$item['band']     = null;
		$item['title']    = (string) ( $v['title'] ?? $item['title'] );
		$item['flags']    = [];
		$changed          = false;
		$oldSources       = is_array( $v['sources'] ?? null ) ? $v['sources'] : [];
		foreach ( $item['sources'] as $key => $entry ) {
			$old = $oldSources[ $key ] ?? null;
			if ( ! is_array( $old ) || ( $old['updated_at'] ?? '' ) !== ( $entry['updated_at'] ?? '' ) || ( $old['snapshot'] ?? [] ) != ( $entry['snapshot'] ?? [] ) ) {
				$changed = true;
			}
			if ( is_array( $old ) && isset( $old['first_read'] ) ) {
				$item['sources'][ $key ]['first_read'] = $old['first_read'];
			}
		}
		$milestone = (string) ( $issues['snapshot']['milestone'] ?? '' );
		if ( $milestone !== '' && is_string( $v['release'] ?? null ) && $v['release'] !== '' && $v['release'] !== $milestone ) {
			$item['flags'][] = Item::flag( 'release_mismatch', 'Milestone ' . $milestone . ', release plan ' . $v['release'], $this->now );
		}
		if ( $state === 'closed' && ! in_array( $item['status'], [ 'shipped', 'declined', 'archived' ], true ) ) {
			$item['flags'][] = Item::flag( 'closed_unshipped', ( $issues['alias'] ?? '' ) . ' is closed, the item is not shipped', $this->now );
		}
		foreach ( $item['flags'] as $flag ) {
			if ( Item::withFlag( $v, $flag ) !== $v ) {
				$changed = true;
			}
		}
		if ( $item['tioday'] !== '' && ( $v['tioday'] ?? '' ) !== $item['tioday'] ) {
			$changed = true;
		}
		$item['op'] = $changed ? 'update' : 'unchanged';

		return $item;
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private function blank( array $record ): array {
		return [
			'existing'   => $this->existing[ $record['key'] . '|' . $record['id'] ] ?? null,
			'title'      => $record['title'],
			'kind'       => $record['kind'],
			'detail'     => $record['detail'],
			'status'     => 'inbox',
			'band'       => null,
			'rankBy'     => [ 8, (string) $record['created_at'] ],
			'release'    => '',
			'area'       => '',
			'owner'      => '',
			'tags'       => [],
			'links'      => [],
			'sources'    => [],
			'tioday'     => '',
			'tiodayTask' => null,
			'issue'      => null,
			'notion'     => null,
			'from'       => [],
			'also'       => [],
			'parentOf'   => [],
		];
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private function entry( array $record, bool $main ): array {
		return array_filter(
			[
				'id'         => $record['id'],
				'url'        => $record['url'],
				'main'       => $main ? true : null,
				'first_read' => $this->now,
				'synced_at'  => $this->now,
				'updated_at' => $record['updated_at'],
				'snapshot'   => $record['snapshot'],
			],
			static fn ( $v ): bool => $v !== null && $v !== ''
		);
	}

	/** @param array<string, mixed> $d */
	private function label( array $d ): string {
		return ( $d['from'][0] ?? '' ) . ' ' . $d['title'];
	}

	private function count( string $source, string $what, int $n ): void {
		$this->report['sources'][ $source ][ $what ] = ( $this->report['sources'][ $source ][ $what ] ?? 0 ) + $n;
	}

	/** Lower case, letters and digits only, for comparing titles. */
	public static function normal( string $title ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( $title ) ) ) );
	}

	/**
	 * Band order (Now, Next, Later, the Inbox, then off the list), then the
	 * placing hint, then newest first.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	public static function order( array $a, array $b ): int {
		$band = [ 'now' => 0, 'next' => 1, 'later' => 2 ];
		$ba   = $band[ $a['band'] ?? '' ] ?? ( $a['status'] === 'inbox' ? 3 : 4 );
		$bb   = $band[ $b['band'] ?? '' ] ?? ( $b['status'] === 'inbox' ? 3 : 4 );

		return [ $ba, $a['rankBy'][0], $b['rankBy'][1], $a['title'] ] <=> [ $bb, $b['rankBy'][0], $a['rankBy'][1], $b['title'] ];
	}

	/**
	 * The items documents of backlog $artifactId (none for 0: a dry run
	 * before the backlog exists).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function itemsOf( int $artifactId ): array {
		return $artifactId > 0 ? StoreData::list( $artifactId, 'items' ) : [];
	}
}
