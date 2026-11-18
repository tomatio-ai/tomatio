<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\AgentId\TaskTakers;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\DataStore\TioDayAgents;
use AgentBrain\Modules\DataStore\TioDayAssignee;
use AgentBrain\Modules\Extensions\Switches;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Sharing\MembersAddress;
use WP_Error;

/**
 * #987: an assignee per Tomagic item, and agents pick up current work.
 *
 * - An item's `owner` is its assignee: a person (login or name) or an agent
 *   that takes tasks (#953), by name or alias. It is set with
 *   tomatio-project-edit (owner) or from the page: a member writes
 *   assign/<item key> {owner}; the brain sets it when that account may
 *   write the backlog, records who assigned it (`assigned_by`, a login)
 *   and removes the request, or marks it `refused`.
 * - meta/people (server-written, read-only for the page) lists who can be
 *   picked: the people who can read the backlog, and the agents that take
 *   tasks and can read it.
 * - An item is *current* when it is open and in the Now band, or in the
 *   next release (#986). A current item assigned to an agent is in that
 *   agent's assigned work (TioDayAssigned: tomatio-tioday-assigned and
 *   GET /tioday/assigned), with a link back. The agent may comment on it
 *   and mark it shipped, and nothing else (Backlog::check), like an
 *   assigned TíoDay task (#949).
 * - The agent's questions are comments on the item. When TíoDay is on,
 *   the person who assigned it also gets each one in their TíoDay comments.
 */
final class Assignees {

	/** At most this many people in meta/people. */
	public const MAX_PEOPLE = 200;

	/** Statuses that are not open work. */
	private const CLOSED = [ 'idea', 'shipped', 'declined', 'parked', 'archived' ];

	private static bool $busy = false;

	public static function register(): void {
		add_action( 'tomatio/store_written', [ self::class, 'written' ], 20, 5 );
		foreach ( [ 'profile_update', 'set_user_role', 'deleted_user', 'user_register' ] as $hook ) {
			add_action( $hook, [ self::class, 'dirty' ] );
		}
		// After TioDayAgents::flush (priority 10), which marks this dirty too.
		add_action( 'shutdown', [ self::class, 'flush' ], 20 );
		add_action( 'init', [ self::class, 'maybeSync' ], 33 );
	}

	/** @var bool Whether someone changed during this request. */
	private static bool $dirty = false;

	public static function dirty(): void {
		self::$dirty = true;
	}

	public static function flush(): void {
		if ( self::$dirty ) {
			self::$dirty = false;
			self::syncAll();
		}
	}

	/** Once per plugin version: every board gets meta/people. */
	public static function maybeSync(): void {
		$version = defined( 'AGENT_BRAIN_VERSION' ) ? (string) AGENT_BRAIN_VERSION : '';
		if ( get_option( 'agent_brain_backlog_people' ) === $version ) {
			return;
		}
		update_option( 'agent_brain_backlog_people', $version, false );
		self::syncAll();
	}

	public static function syncAll(): void {
		foreach ( Backlog::all() as $artifactId ) {
			self::sync( $artifactId );
		}
	}

	/**
	 * Write meta/people on a board when it changed.
	 */
	public static function sync( int $artifactId ): void {
		$want = [ 'people' => self::people( $artifactId ), 'agents' => self::agents( $artifactId ) ];
		$have = StoreData::get( $artifactId, 'meta', 'people' );
		if ( $have !== null && is_array( $have['value'] ) && $have['value'] === $want ) {
			return;
		}
		StoreData::set( $artifactId, 'meta', 'people', $want, StoreData::anonymousAttribution(), null, $have !== null ? (int) $have['version'] : 0 );
	}

	/**
	 * The people who can read the board: [{name, login}], by name. name is
	 * the display name, as the page's viewer pass carries it ("Mine").
	 *
	 * @return array<int, array{name: string, login: string}>
	 */
	public static function people( int $artifactId ): array {
		$out = [];
		foreach ( get_users( [ 'number' => 500, 'orderby' => 'display_name', 'fields' => [ 'ID' ] ] ) as $row ) {
			$id = (int) $row->ID;
			if ( Capabilities::isPureAgent( $id ) || ! Backlog::canRead( $id, $artifactId ) ) {
				continue;
			}
			$user  = get_userdata( $id );
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$out[] = [ 'name' => (string) $user->display_name, 'login' => (string) $user->user_login ];
			if ( count( $out ) >= self::MAX_PEOPLE ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The agents that take tasks and can read the board, as TíoDay lists
	 * them (#970): [{name, aliases, names}].
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function agents( int $artifactId ): array {
		$out = [];
		foreach ( TaskTakers::all() as $agent ) {
			if ( ! Backlog::canRead( (int) $agent->ID, $artifactId ) ) {
				continue;
			}
			foreach ( TioDayAgents::list() as $row ) {
				if ( $row['name'] === TaskTakers::nameOf( (int) $agent->ID ) ) {
					$out[] = $row;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Set an item's assignee ('' clears it), with a log line.
	 *
	 * @param array<string, mixed> $doc    An items document.
	 * @param array<string, mixed> $caller Project::caller()
	 * @return array{item: array<string, mixed>, log: array<string, mixed>}|WP_Error As Change::write.
	 */
	public static function set( int $artifactId, array $doc, string $owner, array $caller, string $reason = '' ) {
		if ( is_array( $doc['value'] ) && ( $doc['value']['status'] ?? '' ) === 'idea' ) {
			return new WP_Error( 'ab_invalid_input', __( 'An idea has no assignee until a curator promotes it to an item.', 'tomatio' ), [ 'status' => 400 ] );
		}
		$owner = self::canonical( $artifactId, $owner );
		if ( $owner instanceof WP_Error ) {
			return $owner;
		}
		$value = is_array( $doc['value'] ) ? $doc['value'] : [];
		$was   = (string) ( $value['owner'] ?? '' );
		if ( $was === $owner ) {
			return [ 'item' => $doc, 'log' => [ 'key' => '' ] ];
		}
		if ( $owner === '' ) {
			unset( $value['owner'], $value['assigned_by'], $value['assigned_at'] );
		} else {
			$value['owner']       = $owner;
			$value['assigned_by'] = $caller['for'] !== '' ? $caller['for'] : $caller['by'];
			$value['assigned_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		}
		$line = array_filter(
			[
				'by'     => $caller['by'],
				'for'    => $caller['for'],
				'what'   => 'assignee',
				'from'   => $was !== '' ? $was : null,
				'to'     => $owner !== '' ? $owner : null,
				'reason' => $reason,
			],
			static fn ( $v ): bool => $v !== null && $v !== ''
		);

		return Change::write( $artifactId, (string) $doc['key'], $value, (int) $doc['version'], $line, $caller['store'] );
	}

	/**
	 * The assignee as stored: '' (none), an agent that takes tasks and can
	 * read the board (its name), or a person who can read it (display
	 * name), found by name, alias or login. Anyone else is refused.
	 *
	 * @return string|WP_Error
	 */
	public static function canonical( int $artifactId, string $who ) {
		$who = TioDay::text( $who, TioDay::MAX_NAME );
		if ( $who === '' ) {
			return '';
		}
		$agent = TaskTakers::named( $who );
		if ( $agent > 0 && Backlog::canRead( $agent, $artifactId ) ) {
			return TaskTakers::nameOf( $agent );
		}
		foreach ( self::people( $artifactId ) as $person ) {
			if ( strcasecmp( $person['name'], $who ) === 0 || strcasecmp( $person['login'], $who ) === 0 ) {
				return $person['name'];
			}
		}

		return new WP_Error(
			'ab_invalid_input',
			/* translators: %s: the name given. */
			sprintf( __( '%s is not someone who can be assigned here: name a person who can read this backlog, or an agent that takes tasks and can read it.', 'tomatio' ), $who ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * tomatio/store_written: an assign request from the page.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $by
	 */
	public static function written( int $artifactId, string $collection, string $key, $value, array $by ): void {
		if ( self::$busy || $collection !== 'assign' || ! is_array( $value ) || ! empty( $value['refused'] ) || ! Backlog::isBacklog( $artifactId ) ) {
			return;
		}
		$userId = empty( $by['anonymous'] ) ? (int) ( $by['id'] ?? 0 ) : 0;

		self::$busy = true;
		try {
			$doc = StoreData::get( $artifactId, 'items', $key );
			if ( $doc === null ) {
				StoreData::set( $artifactId, 'assign', $key, [ 'refused' => 'item' ] + $value, StoreData::anonymousAttribution() );

				return;
			}
			if ( $userId <= 0 || ! Backlog::canWrite( $userId, $artifactId ) ) {
				StoreData::set( $artifactId, 'assign', $key, [ 'refused' => 'write' ] + $value, StoreData::anonymousAttribution() );

				return;
			}
			$done = self::set( $artifactId, $doc, is_string( $value['owner'] ?? null ) ? $value['owner'] : '', Project::callerFor( $userId, $artifactId ) );
			if ( $done instanceof WP_Error ) {
				StoreData::set( $artifactId, 'assign', $key, [ 'refused' => $done->get_error_message() ] + $value, StoreData::anonymousAttribution() );

				return;
			}
			StoreData::delete( $artifactId, 'assign', $key, null, StoreData::anonymousAttribution() );
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Whether an item is current: open, and in the Now band or in the next
	 * release.
	 *
	 * @param array<string, mixed>                                               $doc
	 * @param array<string, mixed>                                               $project
	 * @param array{running: string, next: string, upcoming: array<int, string>} $plan
	 * @param array<string, array<string, mixed>>                                $choices
	 */
	public static function isCurrent( array $doc, array $project, array $plan, array $choices ): bool {
		$value = is_array( $doc['value'] ) ? $doc['value'] : [];
		if ( in_array( (string) ( $value['status'] ?? 'inbox' ), self::CLOSED, true ) ) {
			return false;
		}
		$verdict = $choices[ (string) $doc['key'] ]['value']['status'] ?? null;
		if ( in_array( $verdict, [ 'done', 'drop' ], true ) ) {
			return false;
		}
		if ( Rank::band( is_string( $value['rank'] ?? null ) ? $value['rank'] : null, $project ) === 'now' ) {
			return true;
		}
		$release = Releases::resolve( Releases::setOf( $doc, $choices ), $plan );

		return $release !== '' && $release === $plan['next'];
	}

	/**
	 * Whether $agentId is the assignee of $doc (by name or alias, and only
	 * while it takes tasks).
	 *
	 * @param array<string, mixed> $doc
	 */
	public static function isAssignee( int $agentId, array $doc ): bool {
		$owner = is_array( $doc['value'] ) && is_string( $doc['value']['owner'] ?? null ) ? $doc['value']['owner'] : '';

		return $owner !== '' && TioDayAssignee::isNamed( $agentId, $owner );
	}

	/**
	 * Whether $agentId may act on $ref as its assignee: the item is current
	 * and assigned to it.
	 */
	public static function mayActOn( int $artifactId, int $agentId, string $ref ): bool {
		if ( ! TaskTakers::takes( $agentId ) || ! Backlog::canRead( $agentId, $artifactId ) ) {
			return false;
		}
		$doc = Project::find( $artifactId, $ref );
		if ( $doc === null || ! self::isAssignee( $agentId, $doc ) ) {
			return false;
		}
		[ $project ] = Project::project( $artifactId );
		$choices     = Releases::choices( $artifactId );
		$plan        = Releases::plan( $project, StoreData::list( $artifactId, 'items' ), $choices );

		return self::isCurrent( $doc, $project, $plan, $choices );
	}

	/**
	 * The current items assigned to $agentId on every board it can read, as
	 * the assigned feed lists them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function forAgent( int $agentId ): array {
		$out = [];
		if ( ! TaskTakers::takes( $agentId ) ) {
			return $out;
		}
		foreach ( Backlog::all() as $artifactId ) {
			if ( ! Backlog::canRead( $agentId, $artifactId ) ) {
				continue;
			}
			$items = StoreData::list( $artifactId, 'items' );
			$mine  = array_values( array_filter( $items, static fn ( array $doc ): bool => self::isAssignee( $agentId, $doc ) ) );
			if ( $mine === [] ) {
				continue;
			}
			[ $project ] = Project::project( $artifactId );
			$choices     = Releases::choices( $artifactId );
			$plan        = Releases::plan( $project, $items, $choices );
			$comments    = self::commentCounts( $artifactId );
			$url         = self::url( $artifactId );
			$scores      = self::impactScores( $items );
			foreach ( $mine as $doc ) {
				if ( ! self::isCurrent( $doc, $project, $plan, $choices ) ) {
					continue;
				}
				$value   = (array) $doc['value'];
				$id      = is_string( $value['id'] ?? null ) ? $value['id'] : (string) $doc['key'];
				$release = Releases::resolve( Releases::setOf( $doc, $choices ), $plan );
				$out[]   = [
					'artifact_id'     => $artifactId,
					'board'           => 'Tomagic',
					'id'              => $id,
					'key'             => (string) $doc['key'],
					'title'           => (string) ( $value['title'] ?? $doc['key'] ),
					'detail'          => (string) ( $value['detail'] ?? '' ),
					'driver'          => (string) ( $value['driver'] ?? '' ),
					'impact'          => (string) ( $value['impact'] ?? '' ),
					'status'          => (string) ( $value['status'] ?? 'inbox' ),
					'band'            => Rank::band( is_string( $value['rank'] ?? null ) ? $value['rank'] : null, $project ),
					'release'         => $release !== '' ? $release : null,
					'assigned_by'     => (string) ( $value['assigned_by'] ?? '' ),
					'links'           => is_array( $value['links'] ?? null ) ? $value['links'] : [],
					'url'             => $url !== '' ? $url . '#view=board&item=' . rawurlencode( $id ) : '',
					'comments'        => $comments[ $id ][0] ?? 0,
					'last_comment_at' => $comments[ $id ][1] ?? null,
					// #1078: what pickup order is made of, as the board shows it.
					'urgent'          => Item::urgent( $value ),
					'impact_score'    => $scores[ (string) $doc['key'] ] ?? null,
					'effort'          => Item::effort( $value['estimate'] ?? null ),
					'rank'            => is_string( $value['rank'] ?? null ) ? $value['rank'] : null,
				];
			}
		}

		return self::pickupOrder( $out );
	}

	/**
	 * #1078: an agent's items in the order to pick them up: urgent first,
	 * then priority (impact score ÷ effort, highest first; none last), then
	 * rank. Each gets `order` (1, 2, …) and `priority`, and `how` says it in
	 * one line: "Pickup 2 of 5 in 1.10.0 · priority 3.1×".
	 *
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public static function pickupOrder( array $items ): array {
		foreach ( $items as $i => $item ) {
			$score                   = $item['impact_score'] ?? null;
			$effort                  = $item['effort'] ?? null;
			$items[ $i ]['priority'] = is_int( $score ) && is_int( $effort ) && $effort > 0 ? round( $score / $effort, 1 ) : null;
		}
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$pa = $a['priority'];
				$pb = $b['priority'];

				// Not urgent (true) sorts after urgent (false); no priority after a priority.
				return [ empty( $a['urgent'] ), $pa === null, -( $pa ?? 0 ), $a['rank'] === null, (string) $a['rank'], $a['artifact_id'], $a['id'] ]
					<=> [ empty( $b['urgent'] ), $pb === null, -( $pb ?? 0 ), $b['rank'] === null, (string) $b['rank'], $b['artifact_id'], $b['id'] ];
			}
		);
		$n = count( $items );
		foreach ( $items as $i => $item ) {
			$items[ $i ]['order'] = $i + 1;
			$parts                = [ sprintf( /* translators: 1: place, 2: how many. */ __( 'Pickup %1$d of %2$d', 'tomatio' ), $i + 1, $n ) . ( is_string( $item['release'] ?? null ) && $item['release'] !== '' ? ' ' . sprintf( /* translators: %s: release. */ __( 'in %s', 'tomatio' ), $item['release'] ) : '' ) ];
			if ( ! empty( $item['urgent'] ) ) {
				$parts[] = __( 'urgent', 'tomatio' );
			}
			if ( $item['priority'] !== null ) {
				$parts[] = sprintf( /* translators: %s: priority, such as 3.1. */ __( 'priority %s×', 'tomatio' ), number_format( (float) $item['priority'], 1, '.', '' ) );
			}
			$items[ $i ]['how'] = implode( ' · ', $parts );
		}

		return $items;
	}

	/**
	 * #1004/#1078: each ranked item's impact score, its place on the ranked
	 * list on a 100-point scale (100 at the top, 12 at the bottom), as the
	 * board shows it. Keyed by document key.
	 *
	 * @param list<array<string, mixed>> $items The board's item documents.
	 * @return array<string, int>
	 */
	public static function impactScores( array $items ): array {
		$ranked = array_values(
			array_filter(
				$items,
				static fn ( array $doc ): bool => is_array( $doc['value'] ?? null ) && in_array( (string) ( $doc['value']['status'] ?? '' ), Item::RANKED, true ) && is_string( $doc['value']['rank'] ?? null ) && $doc['value']['rank'] !== ''
			)
		);
		usort( $ranked, static fn ( array $a, array $b ): int => strcmp( (string) $a['value']['rank'], (string) $b['value']['rank'] ) );
		$n   = count( $ranked );
		$out = [];
		foreach ( $ranked as $i => $doc ) {
			$out[ (string) $doc['key'] ] = $n > 1 ? (int) round( 100 - $i * ( 88 / ( $n - 1 ) ) ) : 100;
		}

		return $out;
	}

	/**
	 * After an agent's comment on an item it was assigned: the person who
	 * assigned it gets it in their TíoDay comments (when TíoDay is on and
	 * they have one), so a question never sits unseen.
	 *
	 * @param array<string, mixed> $doc     The items document.
	 * @param array<string, mixed> $comment The stored comment value.
	 */
	public static function forward( int $artifactId, array $doc, int $agentId, array $comment ): int {
		if ( ! Capabilities::isPureAgent( $agentId ) || ! self::isAssignee( $agentId, $doc ) || ! Switches::enabled( Switches::TIODAY ) ) {
			return 0;
		}
		$by     = is_array( $doc['value'] ) && is_string( $doc['value']['assigned_by'] ?? null ) ? $doc['value']['assigned_by'] : '';
		$person = $by !== '' ? get_user_by( 'login', $by ) : false;
		if ( ! $person instanceof \WP_User || Capabilities::isPureAgent( (int) $person->ID ) ) {
			return 0;
		}
		$tioday = TioDay::forPerson( (int) $person->ID );
		if ( $tioday <= 0 ) {
			return 0;
		}
		$value = (array) $doc['value'];
		$id    = is_string( $value['id'] ?? null ) ? $value['id'] : (string) $doc['key'];
		$url   = self::url( $artifactId );
		$text  = sprintf(
			/* translators: 1: item ID, 2: item title, 3: the comment, 4: link to the item. */
			__( 'On Tomagic %1$s (%2$s): %3$s %4$s', 'tomatio' ),
			$id,
			(string) ( $value['title'] ?? '' ),
			(string) ( $comment['text'] ?? '' ),
			$url !== '' ? $url . '#view=board&item=' . rawurlencode( $id ) : ''
		);
		$stored = StoreData::write(
			$tioday,
			'comments',
			StoreData::generateKey(),
			[
				'on'   => 'page',
				'text' => TioDay::text( trim( $text ), 4000, true ),
				'by'   => TaskTakers::nameOf( $agentId ),
				'at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			],
			StoreData::attributionFromUser( $agentId, $tioday ),
			'create',
			null,
			0
		);

		return $stored instanceof WP_Error ? 0 : $tioday;
	}

	/** The board's members address, or ''. */
	public static function url( int $artifactId ): string {
		return HtmlFiles::isHtml( $artifactId ) ? MembersAddress::url( $artifactId ) : '';
	}

	/**
	 * Comment count and newest comment time per item ID.
	 *
	 * @return array<string, array{0: int, 1: ?string}>
	 */
	private static function commentCounts( int $artifactId ): array {
		$out = [];
		foreach ( StoreData::list( $artifactId, 'comments' ) as $doc ) {
			$on = is_array( $doc['value'] ) ? (string) ( $doc['value']['on'] ?? '' ) : '';
			if ( $on === '' || $on === 'project' ) {
				continue;
			}
			$at         = is_string( $doc['value']['at'] ?? null ) ? $doc['value']['at'] : null;
			$have       = $out[ $on ] ?? [ 0, null ];
			$out[ $on ] = [ $have[0] + 1, $at !== null && ( $have[1] === null || strcmp( $at, $have[1] ) > 0 ) ? $at : $have[1] ];
		}

		return $out;
	}
}
