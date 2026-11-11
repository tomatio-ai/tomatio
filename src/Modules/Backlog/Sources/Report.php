<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

/**
 * #892 (section 652, "What the dry-run report shows"): the plan as a
 * report a person can say yes to. Counts per source, what didn't map, what
 * has no issue and which issues nothing points at, the "maybe the same"
 * pairs, every write a sync would make to Notion (step 5), and the
 * proposed Now and Next with what TíoDay's Up next would show. It ends
 * with the command that runs exactly this plan.
 */
final class Report {

	private const MAX_LIST = 60;

	/** @param array<string, mixed> $plan */
	public static function markdown( array $plan ): string {
		$out   = [];
		$out[] = '# First read: dry run';
		$out[] = '';
		$out[] = $plan['backlog'] > 0
			? 'Backlog: artifact ' . $plan['backlog'] . '. Made ' . $plan['at'] . '. Nothing has been written.'
			: 'No backlog yet: this is what a first read into a new, empty backlog would do. Made ' . $plan['at'] . '. Nothing has been written.';
		$out[] = '';
		$out[] = 'Fingerprint: **' . $plan['fingerprint'] . '**';
		$out[] = '';

		$out[] = '## Counts';
		$out[] = '';
		$out[] = '| Source | Read | New items | Merged onto another | Already known | Closed issues read |';
		$out[] = '|---|---|---|---|---|---|';
		foreach ( [ 'issues' => 'Issues', 'notion' => 'Notion', 'tioday' => 'TíoDay feature tasks' ] as $key => $label ) {
			$c     = $plan['sources'][ $key ] ?? [];
			$out[] = '| ' . $label . ' | ' . (int) ( $c['read'] ?? 0 ) . ' | ' . (int) ( $c['new'] ?? 0 ) . ' | ' . (int) ( $c['merged'] ?? 0 ) . ' | ' . (int) ( $c['known'] ?? 0 ) . ' | ' . ( $key === 'issues' ? (int) ( $c['closed'] ?? 0 ) : '' ) . ' |';
		}
		$out[] = '';
		$out[] = sprintf( 'Items: %d new, %d updated, %d unchanged.', $plan['counts']['create'], $plan['counts']['update'], $plan['counts']['unchanged'] );
		$out[] = '';

		$out[] = '## Proposed places';
		$out[] = '';
		$out[] = 'Sources never rank: these are first places, marked "rank from import" until a person moves or confirms them.';
		$out[] = '';
		foreach ( [ 'now' => 'Now', 'next' => 'Next' ] as $band => $label ) {
			$out[] = '**' . $label . '** (' . count( $plan['proposed'][ $band ] ) . ')';
			$out[] = '';
			$out   = array_merge( $out, self::list( $plan['proposed'][ $band ], true ) );
			$out[] = '';
		}
		$out[] = sprintf( '**Later**: %d. **Inbox**: %d. **Off the list** (shipped, declined, parked): %d.', count( $plan['proposed']['later'] ), count( $plan['proposed']['inbox'] ), count( $plan['proposed']['off'] ) );
		$out[] = '';
		$out[] = '**TíoDay Up next** would show: ' . ( $plan['up_next'] !== [] ? implode( '; ', $plan['up_next'] ) : 'nothing new' ) . '.';
		$out[] = '';

		$out[] = '## Needs a look';
		$out[] = '';
		$out[] = '**Maybe the same** (not merged; a person or the product manager agent merges them): ' . count( $plan['maybe_same'] );
		foreach ( array_slice( $plan['maybe_same'], 0, self::MAX_LIST ) as $pair ) {
			$out[] = '- ' . $pair[0] . ' / ' . $pair[1] . ' (' . $pair[2] . ')';
		}
		$out[] = '';
		$out[] = '**Values that didn\'t map**: ' . ( $plan['unmapped'] === [] ? 'none' : '' );
		foreach ( $plan['unmapped'] as $what => $n ) {
			$out[] = '- ' . $what . ': ' . $n . ' rows (they come in as Later; add the status to the Notion manifest\'s map to place them)';
		}
		$out[] = '';
		$out[] = '**Rows and tasks with no issue**: ' . count( $plan['unanchored'] );
		$out   = array_merge( $out, self::list( $plan['unanchored'], false ) );
		$out[] = '';
		$out[] = '**Issues nothing points at** (they land in the Inbox): ' . count( $plan['issues_alone'] ) . ( $plan['issues_alone'] !== [] ? ': ' . implode( ', ', array_slice( $plan['issues_alone'], 0, 200 ) ) : '' );
		$out[] = '';
		if ( $plan['missing'] !== [] ) {
			$out[] = '**Issues named but not read**: ' . count( $plan['missing'] );
			$out   = array_merge( $out, self::list( $plan['missing'], false ) );
			$out[] = '';
		}
		$flags = array_filter( $plan['items'], static fn ( array $i ): bool => $i['flags'] !== [] );
		if ( $flags !== [] ) {
			$out[] = '**Flagged for a person**: ' . count( $flags );
			foreach ( array_slice( $flags, 0, self::MAX_LIST ) as $i ) {
				$out[] = '- ' . $i['title'] . ': ' . implode( '; ', array_map( static fn ( array $f ): string => $f['detail'], $i['flags'] ) );
			}
			$out[] = '';
		}

		$out[] = '## Writes to Notion (step 5)';
		$out[] = '';
		$writes = $plan['notion_writes'];
		if ( ! is_array( $writes ) ) {
			$out[] = 'Notion was not read, so none are listed.';
		} else {
			$out[] = 'This version writes nothing to Notion. When the write side lands (step 5), a sync makes these writes; your yes to this report is the go-ahead for them then.';
			$out[] = '';
			$out[] = '- Columns it adds: ' . ( $writes['columns'] !== [] ? implode( ', ', $writes['columns'] ) : 'none' );
			$out[] = '- Status options it adds: ' . ( $writes['statuses'] !== [] ? implode( ', ', $writes['statuses'] ) : 'none' );
			$out[] = '- Rows it changes: ' . count( $writes['changed'] );
			foreach ( array_slice( $writes['changed'], 0, self::MAX_LIST ) as $row ) {
				$fields = [];
				foreach ( $row['fields'] as $name => [ $old, $new ] ) {
					$fields[] = $name . ' ' . ( $old !== '' ? $old : '(empty)' ) . ' → ' . $new;
				}
				$out[] = '  - ' . $row['row'] . ' ' . $row['title'] . ': ' . implode( ', ', $fields );
			}
			if ( count( $writes['changed'] ) > self::MAX_LIST ) {
				$out[] = '  - and ' . ( count( $writes['changed'] ) - self::MAX_LIST ) . ' more (see --format=json)';
			}
			$out[] = '- Rows it creates (accepted items with no row): ' . count( $writes['created'] );
			$out   = array_merge( $out, array_map( static fn ( string $l ): string => '  ' . $l, self::list( $writes['created'], false ) ) );
		}
		$out[] = '';

		if ( $plan['notes'] !== [] ) {
			$out[] = '## Notes';
			$out[] = '';
			foreach ( $plan['notes'] as $note ) {
				$out[] = '- ' . $note;
			}
			$out[] = '';
		}

		$out[] = '## To run it';
		$out[] = '';
		$out[] = 'After a yes to this report, the same command with `--yes=' . $plan['fingerprint'] . '` instead of `--dry-run` writes exactly this plan. If anything changed in the meantime, the fingerprint differs and nothing is written.';

		return implode( "\n", $out ) . "\n";
	}

	/**
	 * The plan without the bulky parts, for --format=json.
	 *
	 * @param array<string, mixed> $plan
	 * @return array<string, mixed>
	 */
	public static function json( array $plan ): array {
		$plan['items'] = array_map(
			static fn ( array $i ): array => array_intersect_key( $i, array_flip( [ 'op', 'key', 'id', 'title', 'kind', 'status', 'band', 'release', 'area', 'owner', 'from', 'parent', 'tioday', 'flags' ] ) ),
			$plan['items']
		);

		return $plan;
	}

	/**
	 * @param array<int, string> $lines
	 * @return array<int, string>
	 */
	private static function list( array $lines, bool $numbered ): array {
		$out = [];
		foreach ( array_slice( array_values( $lines ), 0, self::MAX_LIST ) as $n => $line ) {
			$out[] = ( $numbered ? ( $n + 1 ) . '. ' : '- ' ) . $line;
		}
		if ( count( $lines ) > self::MAX_LIST ) {
			$out[] = '- and ' . ( count( $lines ) - self::MAX_LIST ) . ' more';
		}

		return $out;
	}
}
