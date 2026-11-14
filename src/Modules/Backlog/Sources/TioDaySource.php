<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog\Sources;

use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;

/**
 * #892 (section 652, row 3): a person's open TíoDay tasks of kind feature,
 * linked to their backlog items. Not a tool source: it reads the TíoDay on
 * this brain, and a task is never closed or moved by it. In the real run
 * the task gets `item: B12` and the item `tioday: T151`, so either ID finds
 * the other.
 */
final class TioDaySource {

	public const SOURCE = 'tioday';

	public function __construct( private int $artifactId ) {
	}

	/**
	 * @return array{records: array<int, array<string, mixed>>, notes: array<int, string>}|string
	 */
	public function read() {
		if ( ! \AgentBrain\Modules\DataStore\TioDayIds::isTioDay( $this->artifactId ) ) {
			return 'Artifact ' . $this->artifactId . ' is not a TíoDay.';
		}

		$records = [];
		foreach ( StoreData::list( $this->artifactId, 'tasks' ) as $doc ) {
			$v = is_array( $doc['value'] ) ? $doc['value'] : [];
			if ( ( $v['kind'] ?? '' ) !== 'feature' || ( $v['status'] ?? 'open' ) === 'done' ) {
				continue;
			}
			$records[] = $this->record( (string) $doc['key'], $v );
		}

		return [ 'records' => $records, 'notes' => [ 'Read ' . count( $records ) . ' open feature tasks from TíoDay ' . $this->artifactId . '.' ] ];
	}

	/**
	 * @param array<string, mixed> $task
	 * @return array<string, mixed>
	 */
	public function record( string $key, array $task ): array {
		$links = [];
		foreach ( is_array( $task['links'] ?? null ) ? $task['links'] : [] as $link ) {
			if ( is_array( $link ) && is_string( $link['url'] ?? null ) ) {
				$links[] = ( $link['label'] ?? '' ) . ' ' . $link['url'];
			}
		}
		$group = (string) ( $task['group'] ?? 'now' );
		$id    = is_string( $task['id'] ?? null ) && $task['id'] !== '' ? $task['id'] : $key;

		return [
			'source'     => self::SOURCE,
			'key'        => self::SOURCE,
			'id'         => $id,
			'task_key'   => $key,
			'artifact'   => $this->artifactId,
			'url'        => TioDay::url( $this->artifactId ),
			'title'      => trim( (string) ( $task['title'] ?? $key ) ),
			'detail'     => mb_substr( trim( (string) ( $task['why'] ?? '' ) . ( isset( $task['how'] ) && $task['how'] !== '' ? "\n\n" . $task['how'] : '' ) ), 0, 2000 ),
			'kind'       => 'feature',
			'updated_at' => (string) ( $task['updated_at'] ?? '' ),
			'created_at' => '',
			'status'     => 'ready',
			'band'       => in_array( $group, [ 'now', 'week' ], true ) ? 'now' : 'later',
			'group'      => $group,
			'release'    => '',
			'area'       => '',
			'owner'      => '',
			'labels'     => [],
			'alias'      => $id,
			'item'       => is_string( $task['item'] ?? null ) ? $task['item'] : '',
			'refs'       => IssuesSource::refs( implode( ' ', $links ) . ' ' . ( $task['title'] ?? '' ) . ' ' . ( $task['why'] ?? '' ) ),
			'links'      => is_array( $task['links'] ?? null ) ? $task['links'] : [],
			'children'   => [],
			'snapshot'   => [],
		];
	}
}
