<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

/**
 * #992: the dry-run plan. Compares Magic Backlog items with service rows and
 * says what would be created, changed, linked or unlinked. Nothing here
 * writes. A removed board item is unlinked, never deleted on the service.
 */
final class Plan {

	/** B232: the onboarding issues Linear adds to a new workspace. */
	private const SAMPLE_ISSUES = [
		'Welcome to Linear',
		'Get familiar with Linear',
		'Set up your teams',
		'Connect your tools',
		'Import your data',
		'Customize settings',
		'Use Cycles to focus work over n-weeks',
		'Use Projects to organize work for features or releases',
		'Download the desktop app',
		'Invite your teammates',
	];

	/**
	 * @param array<int, array<string, mixed>> $board
	 * @param array<int, array<string, mixed>> $remote
	 * @param array<string, string>            $links          Board id => remote id.
	 * @param array<int, string>               $unlinkedRemote Remote ids not to pull back.
	 * @param array<int, string>               $unlinkedBoard  Board ids not to push back.
	 * @return array<string, mixed>
	 */
	public static function build( array $board, array $remote, array $links, array $unlinkedRemote = [], array $unlinkedBoard = [], bool $truncated = false ): array {
		// Service ids are compared in one form (rid): a Notion page id comes
		// back from a query without dashes but from a create with them, so
		// a stored link must still find its row.
		$remoteById = [];
		foreach ( $remote as $row ) {
			$id = self::text( $row['id'] ?? '' );
			if ( $id !== '' ) {
				$remoteById[ self::rid( $id ) ] = $row;
			}
		}
		$boardById = [];
		foreach ( $board as $row ) {
			if ( self::text( $row['status'] ?? '' ) === 'idea' ) {
				continue;
			}
			$id = self::text( $row['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$boardById[ $id ] = $row;
			$remoteId         = self::text( $row['remote_id'] ?? '' );
			if ( $remoteId !== '' && ! isset( $links[ $id ] ) ) {
				$links[ $id ] = $remoteId;
			}
		}

		$actions = [];
		$notes   = [];
		$skipRemote = array_fill_keys( array_map( [ self::class, 'rid' ], $unlinkedRemote ), true );
		$skipBoard  = array_fill_keys( $unlinkedBoard, true );
		$usedRemote = [];

		foreach ( $links as $boardId => $remoteId ) {
			if ( $remoteId === '' ) {
				continue;
			}
			$rk                = self::rid( $remoteId );
			$usedRemote[ $rk ] = true;
			$hasBoard          = isset( $boardById[ $boardId ] );
			$hasRemote         = isset( $remoteById[ $rk ] );
			if ( ! $hasBoard ) {
				$actions[]         = self::action( 'unlink', $boardId, $remoteId, '' );
				$skipRemote[ $rk ] = true;
				continue;
			}
			if ( ! $hasRemote ) {
				if ( $truncated ) {
					$notes[] = 'A read stopped early, so ' . $boardId . ' was not unlinked.';
					continue;
				}
				$actions[]            = self::action( 'unlink', $boardId, $remoteId, self::text( $boardById[ $boardId ]['title'] ?? '' ) );
				$skipBoard[ $boardId ] = true;
				continue;
			}
			self::pair( $actions, $notes, $boardById[ $boardId ], $remoteById[ $rk ], false );
		}

		$leftRemote = [];
		foreach ( $remoteById as $id => $row ) {
			if ( ! isset( $usedRemote[ $id ] ) && ! isset( $skipRemote[ $id ] ) ) {
				$leftRemote[ $id ] = $row;
			}
		}
		$byTitle = [];
		foreach ( $leftRemote as $id => $row ) {
			$title = self::norm( self::text( $row['title'] ?? '' ) );
			if ( $title === '' ) {
				continue;
			}
			$byTitle[ $title ][] = $id;
		}

		$claimed = [];
		foreach ( $boardById as $boardId => $row ) {
			if ( isset( $links[ $boardId ] ) || isset( $skipBoard[ $boardId ] ) ) {
				continue;
			}
			$title = self::norm( self::text( $row['title'] ?? '' ) );
			$match = $title !== '' && isset( $byTitle[ $title ] ) && count( $byTitle[ $title ] ) === 1 ? $byTitle[ $title ][0] : '';
			if ( $match !== '' && ! isset( $claimed[ $match ] ) ) {
				$claimed[ $match ] = true;
				$actions[]         = self::action( 'link', $boardId, self::text( $leftRemote[ $match ]['id'] ?? '' ), self::text( $row['title'] ?? '' ), [], [], '', 0, self::text( $leftRemote[ $match ]['url'] ?? '' ), self::text( $leftRemote[ $match ]['alias'] ?? '' ) );
				self::pair( $actions, $notes, $row, $leftRemote[ $match ], true );
				continue;
			}
			$actions[] = self::action( 'create_service', $boardId, '', self::text( $row['title'] ?? '' ), self::pushFields( $row ), [], self::text( $row['key'] ?? '' ), (int) ( $row['version'] ?? 0 ) );
		}

		foreach ( $leftRemote as $id => $row ) {
			if ( isset( $claimed[ $id ] ) ) {
				continue;
			}
			$fields    = self::pullFields( $row );
			$actions[] = self::action( 'create_board', '', self::text( $row['id'] ?? '' ), self::text( $row['title'] ?? '' ), $fields, [], '', 0, self::text( $row['url'] ?? '' ), self::text( $row['alias'] ?? '' ) );
		}

		usort( $actions, static fn ( array $a, array $b ): int => [ $a['op'], $a['board_id'], $a['remote_id'] ] <=> [ $b['op'], $b['board_id'], $b['remote_id'] ] );

		$plan = [
			'actions'         => $actions,
			'notes'           => $notes,
			'links'           => self::nextLinks( $links, $actions ),
			'unlinked_remote' => array_keys( $skipRemote ),
			'unlinked_board'  => array_keys( $skipBoard ),
			'truncated'       => $truncated,
		];
		$plan['fingerprint'] = self::fingerprint( $plan );

		return $plan;
	}

	/**
	 * B232: a release that the service has no project (or cycle) for must
	 * never be dropped in silence. With Projects, the plan gains one
	 * create_release action per missing name, so the dry run lists it and
	 * the approved fingerprint covers it. Cycles cannot be made by name,
	 * so the plan says which releases go without one.
	 *
	 * @param array<string, mixed> $plan
	 * @param array<string, mixed> $target kind, release_via, projects, cycles
	 * @return array<string, mixed>
	 */
	public static function releases( array $plan, string $service, array $target ): array {
		if ( $service !== 'linear' ) {
			return $plan;
		}
		$actions = is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [];
		$wanted  = [];
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$op = (string) ( $action['op'] ?? '' );
			if ( $op !== 'create_service' && $op !== 'update_service' ) {
				continue;
			}
			$fields  = is_array( $action['fields'] ?? null ) ? $action['fields'] : [];
			$release = self::text( $fields['release'] ?? '' );
			if ( $release === '' ) {
				continue;
			}
			$key = self::norm( $release );
			if ( ! isset( $wanted[ $key ] ) ) {
				$wanted[ $key ] = [ 'name' => $release, 'issues' => 0 ];
			}
			++$wanted[ $key ]['issues'];
		}
		if ( $wanted === [] ) {
			return $plan;
		}
		$cycles = ( $target['release_via'] ?? '' ) === 'cycle';
		$known  = is_array( $target[ $cycles ? 'cycles' : 'projects' ] ?? null ) ? $target[ $cycles ? 'cycles' : 'projects' ] : [];
		$have   = [];
		foreach ( $known as $name => $id ) {
			if ( is_string( $name ) ) {
				$have[ self::norm( $name ) ] = true;
			}
		}
		$missing = array_values( array_filter( $wanted, static fn ( array $row ): bool => ! isset( $have[ self::norm( $row['name'] ) ] ) ) );
		if ( $missing === [] ) {
			return $plan;
		}
		usort( $missing, static fn ( array $a, array $b ): int => strnatcasecmp( $a['name'], $b['name'] ) );
		$notes = is_array( $plan['notes'] ?? null ) ? $plan['notes'] : [];
		$names = implode( ', ', array_map( static fn ( array $row ): string => $row['name'], $missing ) );
		$count = array_sum( array_map( static fn ( array $row ): int => $row['issues'], $missing ) );
		$plural = $count === 1 ? ' issue goes' : ' issues go';
		if ( (string) ( $target['kind'] ?? 'team' ) === 'project' ) {
			$notes[] = 'This sync reads one Linear project, so releases are not written as projects: ' . $names . '. ' . $count . $plural . ' without a release on Linear. Sync a team to carry releases.';
		} elseif ( $cycles ) {
			$notes[] = 'Linear has no cycle named ' . $names . ', so ' . $count . $plural . ' without a cycle. Name a cycle that way on Linear, or carry releases as Projects.';
		} else {
			foreach ( $missing as $row ) {
				$actions[] = self::action( 'create_release', '', '', $row['name'], [ 'issues' => (string) $row['issues'] ] );
			}
		}
		$plan['actions']     = $actions;
		$plan['notes']       = $notes;
		$plan['fingerprint'] = self::fingerprint( $plan );

		return $plan;
	}

	/**
	 * The release names a plan would create on the service, in order.
	 *
	 * @param array<string, mixed> $plan
	 * @return array<int, string>
	 */
	public static function newReleases( array $plan ): array {
		$out = [];
		foreach ( is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [] as $action ) {
			if ( is_array( $action ) && ( $action['op'] ?? '' ) === 'create_release' ) {
				$out[] = (string) ( $action['title'] ?? '' );
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $plan
	 */
	public static function fingerprint( array $plan ): string {
		$canon = [];
		foreach ( is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [] as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$fields = is_array( $action['fields'] ?? null ) ? $action['fields'] : [];
			ksort( $fields );
			$history = is_array( $action['history'] ?? null ) ? $action['history'] : [];
			$canon[] = [
				'op'        => (string) ( $action['op'] ?? '' ),
				'board_id'  => (string) ( $action['board_id'] ?? '' ),
				'remote_id' => (string) ( $action['remote_id'] ?? '' ),
				'title'     => (string) ( $action['title'] ?? '' ),
				'fields'    => $fields,
				'history'   => $history,
			];
		}
		$json = json_encode( $canon );

		return substr( hash( 'sha256', is_string( $json ) ? $json : '[]' ), 0, 16 );
	}

	public static function matches( string $fingerprint, string $yes ): bool {
		return $yes !== '' && hash_equals( $fingerprint, $yes );
	}

	/**
	 * After approval the sync runs on its own. Before that, only the
	 * fingerprint a person just approved may be written.
	 *
	 * @param array<string, mixed> $plan
	 */
	public static function gate( array $plan, bool $live, string $yes ): true|string {
		if ( $live ) {
			return true;
		}
		$print = (string) ( $plan['fingerprint'] ?? '' );
		if ( self::matches( $print, $yes ) ) {
			return true;
		}

		return 'The plan is not the one approved (its fingerprint is now ' . $print . '). Run the dry run again.';
	}

	public const EXAMPLES = 5;

	/**
	 * The dry run in plain words: each count with up to EXAMPLES items
	 * it names, so a person sees what would happen before approving.
	 *
	 * @param array<string, mixed> $plan
	 */
	public static function words( array $plan, string $service ): string {
		$name   = $service === 'linear' ? 'Linear' : 'Notion';
		$unit   = $service === 'linear' ? 'issue' : 'row';
		$groups = [];
		foreach ( is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [] as $action ) {
			if ( is_array( $action ) ) {
				$groups[ (string) ( $action['op'] ?? '' ) ][] = $action;
			}
		}
		$heads = [
			'create_service' => [ 'create 1 ' . $unit . ' on ' . $name, 'create ' . $unit . 's on ' . $name ],
			'create_board'   => [ 'create 1 item on Magic Backlog', 'create items on Magic Backlog' ],
			'update_service' => [ 'change 1 ' . $unit . ' on ' . $name, 'change ' . $unit . 's on ' . $name ],
			'update_board'   => [ 'change 1 item on Magic Backlog', 'change items on Magic Backlog' ],
			'link'           => [ 'link 1 item to its ' . $name . ' ' . $unit, 'link items to their ' . $name . ' ' . $unit . 's' ],
		];
		$lines = [ 'Dry run for ' . $name . '. Nothing was written.' ];
		foreach ( $heads as $op => $words ) {
			$list = $groups[ $op ] ?? [];
			if ( $list === [] ) {
				continue;
			}
			$lines[] = self::countLine( count( $list ), $words[0], $words[1] );
			foreach ( self::examples( $list, $op ) as $example ) {
				$lines[] = $example;
			}
		}
		$unlinks = $groups['unlink'] ?? [];
		if ( $unlinks !== [] ) {
			$lines[] = 'Would unlink ' . count( $unlinks ) . '. Nothing is deleted on ' . $name . '.';
			foreach ( self::examples( $unlinks, 'unlink' ) as $example ) {
				$lines[] = $example;
			}
		}
		if ( count( $lines ) === 1 ) {
			$lines[] = 'Nothing to do: both sides already match.';
		}
		$made = $service === 'linear' ? self::newReleases( $plan ) : [];
		if ( $made !== [] ) {
			$lines[] = 'Would create projects on Linear: ' . implode( ', ', $made ) . '.';
		}
		if ( ! empty( $plan['truncated'] ) ) {
			$lines[] = 'The read stopped early, so nothing unseen was unlinked.';
		}
		$samples = self::samples( $plan, $service );
		if ( $samples !== [] ) {
			$lines[] = count( $samples ) . ' of the items to create look like ' . $name . "'s own sample issues: " . implode( ', ', $samples ) . '. Delete them on ' . $name . ' first if you do not want them on the board.';
		}
		foreach ( is_array( $plan['notes'] ?? null ) ? $plan['notes'] : [] as $note ) {
			if ( is_string( $note ) && $note !== '' ) {
				$lines[] = $note;
			}
		}
		$lines[] = '';
		$lines[] = 'Approve to write exactly this plan. After that, sync runs on its own every hour. If either side changes before you approve, run the dry run again.';

		return implode( "\n", $lines );
	}

	/**
	 * Up to EXAMPLES lines naming the items, then how many more.
	 *
	 * @param array<int, array<string, mixed>> $actions
	 * @return array<int, string>
	 */
	private static function examples( array $actions, string $op ): array {
		$out = [];
		foreach ( array_slice( $actions, 0, self::EXAMPLES ) as $action ) {
			$title = self::text( $action['title'] ?? '' );
			$title = $title !== '' ? '“' . self::short( $title ) . '”' : '(untitled)';
			$alias = self::text( $action['alias'] ?? '' );
			$line  = '  · ' . $title . ( $alias !== '' ? ' (' . $alias . ')' : '' );
			if ( $op === 'update_service' || $op === 'update_board' ) {
				$fields = is_array( $action['fields'] ?? null ) ? $action['fields'] : [];
				$parts  = [];
				foreach ( $fields as $field => $value ) {
					$value   = self::text( $value );
					$parts[] = in_array( (string) $field, [ 'detail', 'title' ], true ) || $value === ''
						? (string) $field
						: $field . ' → ' . self::short( $value, 30 );
				}
				if ( $parts !== [] ) {
					$line .= ': ' . implode( ', ', $parts );
				}
			}
			$out[] = $line;
		}
		$more = count( $actions ) - self::EXAMPLES;
		if ( $more > 0 ) {
			$out[] = '  · and ' . $more . ' more';
		}

		return $out;
	}

	private static function short( string $text, int $max = 60 ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= $max ) {
			return $text;
		}

		return rtrim( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max - 1 ) : substr( $text, 0, $max - 1 ) ) . '…';
	}

	/**
	 * B235: what a run that wrote says afterwards, in the past tense, with
	 * its time. words() is only for a dry run: "Nothing was written" and
	 * "Would …" after a live run made the card read as if nothing happened.
	 *
	 * @param array<string, mixed> $plan
	 * @param array<int, string>   $failed
	 * @param array<string, int>|null $done Writes that really happened, by plan op.
	 */
	public static function ranWords( array $plan, string $service, string $at, int $wrote, array $failed, ?array $done = null ): string {
		$name   = $service === 'linear' ? 'Linear' : 'Notion';
		$unit   = $service === 'linear' ? 'issue' : 'row';
		$counts = [];
		foreach ( is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [] as $action ) {
			if ( is_array( $action ) ) {
				$op            = (string) ( $action['op'] ?? '' );
				$counts[ $op ] = ( $counts[ $op ] ?? 0 ) + 1;
			}
		}
		// What was really written, when the caller counted it: a failed write is not reported as done.
		if ( is_array( $done ) ) {
			foreach ( [ 'create_board', 'create_service', 'update_board', 'update_service', 'unlink' ] as $op ) {
				$counts[ $op ] = (int) ( $done[ $op ] ?? 0 );
			}
		}
		$when  = preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $at, $m ) === 1 ? $m[1] . ' ' . $m[2] . ' UTC' : $at;
		$lines = [ 'Last run ' . $when . ', ' . $name . ': wrote ' . $wrote . ' change' . ( $wrote === 1 ? '' : 's' ) . ' to ' . $name . '.' ];
		$done  = [
			self::pastLine( (int) ( $counts['create_board'] ?? 0 ), 'Created', 'item', 'on Magic Backlog' ),
			self::pastLine( (int) ( $counts['create_service'] ?? 0 ), 'Created', $unit, 'on ' . $name ),
			self::pastLine( (int) ( $counts['update_board'] ?? 0 ), 'Changed', 'item', 'on Magic Backlog' ),
			self::pastLine( (int) ( $counts['update_service'] ?? 0 ), 'Changed', $unit, 'on ' . $name ),
			self::pastLine( (int) ( $counts['link'] ?? 0 ), 'Linked', 'pair', '' ),
		];
		$made = $service === 'linear' ? self::newReleases( $plan ) : [];
		if ( $made !== [] ) {
			$done[] = 'Created projects on Linear: ' . implode( ', ', $made ) . '.';
		}
		$unlinks = (int) ( $counts['unlink'] ?? 0 );
		if ( $unlinks > 0 ) {
			$done[] = 'Unlinked ' . $unlinks . '. Nothing was deleted on ' . $name . '.';
		}
		$done = array_values( array_filter( $done ) );
		if ( $done === [] && $failed === [] ) {
			$done[] = 'Nothing to change: the board and ' . $name . ' were already in step.';
		}
		$lines = array_merge( $lines, $done );
		if ( ! empty( $plan['truncated'] ) ) {
			$lines[] = 'The read stopped early, so nothing unseen was unlinked.';
		}
		foreach ( is_array( $plan['notes'] ?? null ) ? $plan['notes'] : [] as $note ) {
			if ( is_string( $note ) && $note !== '' ) {
				$lines[] = $note;
			}
		}
		if ( $failed !== [] ) {
			$lines[] = count( $failed ) . ' write' . ( count( $failed ) === 1 ? '' : 's' ) . ' failed. First: ' . $failed[0];
		}
		$lines[] = 'Sync is on and runs every hour.';

		return implode( "\n", $lines );
	}

	private static function pastLine( int $n, string $verb, string $unit, string $where ): string {
		if ( $n === 0 ) {
			return '';
		}

		return $verb . ' ' . $n . ' ' . $unit . ( $n === 1 ? '' : 's' ) . ( $where === '' ? '' : ' ' . $where ) . '.';
	}

	/**
	 * @param array<int, array<string, mixed>> $actions
	 * @param array<int, string>               $notes
	 * @param array<string, mixed>             $board
	 * @param array<string, mixed>             $remote
	 */
	private static function pair( array &$actions, array &$notes, array $board, array $remote, bool $linked ): void {
		$snapshot = is_array( $board['snapshot'] ?? null ) ? $board['snapshot'] : null;
		$merged   = Rules::merge( $board, $remote, $linked ? null : $snapshot );
		$boardId  = self::text( $board['id'] ?? '' );
		$remoteId = self::text( $remote['id'] ?? '' );
		$title    = self::text( $board['title'] ?? '' );
		$key      = self::text( $board['key'] ?? '' );
		$version  = (int) ( $board['version'] ?? 0 );
		if ( $merged['board'] !== [] || $merged['history'] !== [] ) {
			$actions[] = self::action( 'update_board', $boardId, $remoteId, $title, $merged['board'], $merged['history'], $key, $version, self::text( $remote['url'] ?? '' ), self::text( $remote['alias'] ?? '' ) );
		}
		if ( $merged['service'] !== [] ) {
			$actions[] = self::action( 'update_service', $boardId, $remoteId, $title, $merged['service'], [], $key, $version );
		}
		if ( isset( $merged['held']['status'] ) ) {
			$notes[] = $boardId . ' is ' . $merged['held']['status'] . ' on the service but has no rank, so it stays in the Inbox until someone ranks it.';
		}
	}

	/**
	 * @param array<string, string>            $links
	 * @param array<int, array<string, mixed>> $actions
	 * @return array<string, string>
	 */
	private static function nextLinks( array $links, array $actions ): array {
		foreach ( $actions as $action ) {
			$op      = (string) ( $action['op'] ?? '' );
			$boardId = (string) ( $action['board_id'] ?? '' );
			$remote  = (string) ( $action['remote_id'] ?? '' );
			if ( $op === 'unlink' ) {
				unset( $links[ $boardId ] );
			} elseif ( $op === 'link' && $boardId !== '' && $remote !== '' ) {
				$links[ $boardId ] = $remote;
			}
		}

		return $links;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, string>
	 */
	private static function pushFields( array $row ): array {
		$out = [
			'title'  => self::text( $row['title'] ?? '' ),
			'detail' => self::text( $row['detail'] ?? '' ),
			'status' => self::text( $row['status'] ?? '' ),
			'owner'  => self::text( $row['owner'] ?? '' ),
		];
		$release = self::text( $row['release'] ?? '' );
		$rank    = self::text( $row['rank'] ?? '' );
		if ( $release !== '' ) {
			$out['release'] = $release;
		}
		if ( $rank !== '' ) {
			$out['rank'] = $rank;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, string>
	 */
	private static function pullFields( array $row ): array {
		return [
			'title'   => self::text( $row['title'] ?? '' ),
			'detail'  => self::text( $row['detail'] ?? '' ),
			'status'  => self::text( $row['status'] ?? '' ) !== '' ? self::text( $row['status'] ?? '' ) : 'inbox',
			'owner'   => self::text( $row['owner'] ?? '' ),
			'release' => self::text( $row['release'] ?? '' ),
		];
	}

	/**
	 * @param array<string, string>                              $fields
	 * @param array<int, array{f: string, from: string, to: string}> $history
	 * @return array<string, mixed>
	 */
	private static function action( string $op, string $boardId, string $remoteId, string $title, array $fields = [], array $history = [], string $key = '', int $version = 0, string $url = '', string $alias = '' ): array {
		return [
			'op'        => $op,
			'board_id'  => $boardId,
			'remote_id' => $remoteId,
			'title'     => $title,
			'fields'    => $fields,
			'history'   => $history,
			'key'       => $key,
			'version'   => $version,
			'url'       => $url,
			'alias'     => $alias,
		];
	}

	/**
	 * Titles of the issues Linear puts in every new workspace, as they
	 * would come onto the board.
	 *
	 * @param array<string, mixed> $plan
	 * @return array<int, string>
	 */
	private static function samples( array $plan, string $service ): array {
		if ( $service !== 'linear' ) {
			return [];
		}
		$known = [];
		foreach ( self::SAMPLE_ISSUES as $title ) {
			$known[ self::plain( $title ) ] = true;
		}
		$out = [];
		foreach ( is_array( $plan['actions'] ?? null ) ? $plan['actions'] : [] as $action ) {
			if ( ! is_array( $action ) || ( $action['op'] ?? '' ) !== 'create_board' ) {
				continue;
			}
			$title = self::text( $action['title'] ?? '' );
			if ( isset( $known[ self::plain( $title ) ] ) ) {
				$out[] = $title;
			}
		}

		return $out;
	}

	private static function countLine( int $n, string $one, string $many ): string {
		if ( $n === 0 ) {
			return 'No ' . $many . '.';
		}
		if ( $n === 1 ) {
			return 'Would ' . $one . '.';
		}

		return 'Would ' . $many . ': ' . $n . '.';
	}

	/** @param mixed $value */
	private static function text( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/** Lower case, letters, digits and single spaces only. */
	private static function plain( string $title ): string {
		$words = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $title );

		return self::norm( (string) preg_replace( '/\s+/', ' ', $words ) );
	}

	/**
	 * One form for a service id when ids are compared: a UUID with or
	 * without dashes, in any case, is the same id. Anything else is kept.
	 */
	public static function rid( string $id ): string {
		$plain = strtolower( str_replace( '-', '', trim( $id ) ) );

		return preg_match( '/^[0-9a-f]{32}$/', $plain ) === 1 ? $plain : trim( $id );
	}

	private static function norm( string $title ): string {
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title );

		return trim( $lower );
	}
}
