<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Artifacts\ArtifactIndex;
use AgentBrain\Modules\Artifacts\ArtifactService;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Backlog\Backlog;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\DataStore\TioDayDefault;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\DefaultScope;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Sharing\MembersAddress;
use AgentBrain\Modules\Voice\Enabled;
use WP_Error;

/**
 * #987: TíoDay and Tomagic (the team board) are extensions a brain turns on
 * or off on Settings > Extensions (linked from the Get started checklist's
 * "Extensions" step) or from a curator's AI (tomatio-extension-enable).
 * TíoDay is on by default. Tomagic is on for every brain and hidden until
 * TíoDay offers it (#1011, Dennis, 15 Nov). A brain that already turned it
 * on keeps the board visible. Settings can still turn it off entirely.
 *
 * - TíoDay (`binding.per: person`): while it is on, every person gets one
 *   the first time one is looked for (TioDayDefault); each person can still
 *   turn theirs off on their profile. Turning it off makes no new ones;
 *   existing TíoDays stay.
 * - Tomagic (`binding.per: brain`): turning it on makes the
 *   brain's board (in the default team scope, led by the curator's agent)
 *   or brings back the one archived when it was turned off. Turning it off
 *   archives it; nothing is deleted.
 *
 * OPTION holds {tioday, backlog, chosen, revealed}. `backlog` is the
 * switch (on by default: installed and enabled). `revealed` is the board
 * link. A brain with no OPTION yet gets the defaults (fromWhatExists).
 * A stored option with no `revealed` is migrated once: opted in (or a
 * board already there) stays visible; an explicit off stays off; a brain
 * that never chose gets it on and hidden.
 *
 * Voice (#1002, `binding.per: person`, off by default) has no page: it
 * lists the voice tools and the Voice section on profiles. Its switch is
 * its own option (Voice\Enabled), so OPTION keeps its shape.
 */
final class Switches {

	public const OPTION = 'agent_brain_extensions';

	public const TIODAY  = 'tioday';
	public const BACKLOG = 'backlog';
	public const VOICE   = 'voice';

	/** User-facing ids (as the abilities take them) => internal names. */
	public const IDS = [
		'tioday'         => self::TIODAY,
		'tomagic'        => self::BACKLOG,
		'planning-board' => self::BACKLOG,
		'voice'          => self::VOICE,
	];

	/** Tomagic's members address. */
	public const BOARD_SLUG = 'tomagic';

	/**
	 * The switches, read once from OPTION (written on the first read on a
	 * brain that has none).
	 *
	 * @return array{tioday: bool, backlog: bool, chosen: bool, revealed: bool}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			$stored = self::fromWhatExists();
			update_option( self::OPTION, $stored, true );
		} elseif ( ! array_key_exists( 'revealed', $stored ) ) {
			$stored = self::migrate( $stored );
			update_option( self::OPTION, $stored, true );
		}

		return [
			'tioday'   => ! empty( $stored['tioday'] ),
			'backlog'  => ! empty( $stored['backlog'] ),
			'chosen'   => ! empty( $stored['chosen'] ),
			'revealed' => ! empty( $stored['revealed'] ),
		];
	}

	/** The board link is shown (accepted, opted in under 1.9.18, or turned on in Settings). */
	public static function revealed(): bool {
		return self::all()['revealed'];
	}

	public static function enabled( string $name ): bool {
		$key = self::IDS[ $name ] ?? $name;
		if ( $key === self::VOICE ) {
			return Enabled::on();
		}

		return in_array( $key, [ self::TIODAY, self::BACKLOG ], true ) && self::all()[ $key ];
	}

	/** Whether someone chose the extensions (the checklist step is done). */
	public static function chosen(): bool {
		return self::all()['chosen'];
	}

	/**
	 * Turn one on or off. $actorId is the person choosing (a curator or a
	 * manager; the caller checks). Returns what the extension is now.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set( string $name, bool $on, int $actorId ) {
		$name = self::IDS[ $name ] ?? $name;
		if ( $name === self::VOICE ) {
			return self::setVoice( $on, $actorId );
		}
		if ( ! in_array( $name, [ self::TIODAY, self::BACKLOG ], true ) ) {
			return new WP_Error( 'ab_extension_unknown', __( 'extension is tioday, tomagic or voice.', 'tomatio' ), [ 'status' => 400 ] );
		}

		$all  = self::all();
		$page = 0;
		if ( $name === self::BACKLOG ) {
			$page = $on ? self::boardOn( $actorId ) : self::boardOff();
			if ( $page instanceof WP_Error ) {
				return $page;
			}
		}

		$was           = $all[ $name ];
		$all[ $name ]  = $on;
		if ( $name === self::BACKLOG ) {
			$all['revealed'] = $on;
		}
		$all['chosen'] = true;
		update_option( self::OPTION, $all, true );
		if ( $name === self::TIODAY ) {
			TioDayDefault::reset();
		}

		if ( $was !== $on ) {
			\AgentBrain\Core\Hooks::action(
				'audit',
				[
					'actor_id'    => $actorId,
					'event'       => $on ? 'extension.enabled' : 'extension.disabled',
					'status'      => 'ok',
					'object_type' => 'extension',
					'object_id'   => $name,
					'meta'        => $page > 0 ? [ 'artifact' => $page ] : [],
				]
			);
		}

		return self::describe( $name );
	}

	/**
	 * Voice on or off: the voice tools and the Voice section on profiles.
	 * Profiles already kept stay either way.
	 *
	 * @return array<string, mixed>
	 */
	private static function setVoice( bool $on, int $actorId ): array {
		$was = Enabled::on();
		Enabled::set( $on );
		$all           = self::all();
		$all['chosen'] = true;
		update_option( self::OPTION, $all, true );
		if ( $was !== $on ) {
			\AgentBrain\Core\Hooks::action(
				'audit',
				[
					'actor_id'    => $actorId,
					'event'       => $on ? 'extension.enabled' : 'extension.disabled',
					'status'      => 'ok',
					'object_type' => 'extension',
					'object_id'   => self::VOICE,
					'meta'        => [],
				]
			);
		}

		return self::describe( self::VOICE );
	}

	/**
	 * A board was made some other way (`wp tomatio backlog create`): Tomagic
	 * counts as on, so the switch says what the brain has.
	 */
	public static function boardMade(): void {
		$all = self::all();
		if ( ! $all['backlog'] || ! $all['revealed'] ) {
			$all['backlog']  = true;
			$all['revealed'] = true;
			update_option( self::OPTION, $all, true );
		}
	}

	/**
	 * Open the board and show its link. Used when TíoDay's offer is accepted.
	 * Turning the switch on in Settings does the same (set).
	 *
	 * @return int|WP_Error
	 */
	public static function reveal( int $actorId ) {
		$all = self::all();
		if ( ! $all['backlog'] ) {
			return new WP_Error( 'ab_extension_off', __( 'Tomagic is turned off. A manager can turn it on in Settings.', 'tomatio' ), [ 'status' => 409 ] );
		}
		$live = Backlog::all();
		if ( $all['revealed'] && $live !== [] ) {
			return $live[0];
		}
		$page = self::boardOn( $actorId );
		if ( $page instanceof WP_Error ) {
			return $page;
		}
		$all['revealed'] = true;
		update_option( self::OPTION, $all, true );
		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'extension.revealed',
				'status'      => 'ok',
				'object_type' => 'extension',
				'object_id'   => self::BACKLOG,
				'meta'        => [ 'artifact' => $page ],
			]
		);

		return $page;
	}

	/** Record that the extensions were chosen without changing either (Settings > Extensions saved as it was). */
	public static function markChosen(): void {
		$all           = self::all();
		$all['chosen'] = true;
		update_option( self::OPTION, $all, true );
	}

	/**
	 * The extensions as extension-list and Settings > Extensions show them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function describeAll(): array {
		return [ self::describe( self::TIODAY ), self::describe( self::BACKLOG ), self::describe( self::VOICE ) ];
	}

	/**
	 * The header links between TíoDay and Tomagic (Dennis, 15 Nov), as a
	 * member's page gets them at render time (MembersRoute, the
	 * `tomatio-extensions` meta tag): Tomagic's members address while the
	 * extension is on and the board exists, and $personId's own TíoDay.
	 * Share links never get them (the board is members-only).
	 *
	 * The TíoDay side also carries `now`, how many of the person's own tasks
	 * are open in Now: the number on the shared top bar's TíoDay | Tomagic
	 * switch (C306, 1.10.0), so both pages show the same count.
	 *
	 * @return array{tomagic: array{enabled: bool, url: string}, tioday: array{enabled: bool, url: string, now: int}, software: bool, offer: array{text: string, accept: string, later: string}|null}
	 */
	public static function links( int $personId ): array {
		$all    = self::all();
		$show   = $all['backlog'] && $all['revealed'];
		$boards = $show ? Backlog::all() : [];
		$tioday = $all['tioday'] && $personId > 0 ? TioDay::forPerson( $personId ) : 0;
		// #994: no header link to a page whose extension is turned off (it would 404).
		$boards = array_values( array_filter( $boards, static fn ( $id ): bool => ! TurnedOff::pageOff( (int) $id ) ) );
		$tioday = $tioday > 0 && TurnedOff::pageOff( $tioday ) ? 0 : $tioday;

		return [
			'tomagic'  => [
				'enabled' => $boards !== [],
				'url'     => $boards !== [] ? MembersAddress::url( (int) $boards[0] ) : '',
			],
			'tioday'   => [
				'enabled' => $tioday > 0,
				'url'     => $tioday > 0 ? MembersAddress::url( $tioday ) : '',
				'now'     => $tioday > 0 ? self::nowCount( $tioday ) : 0,
				// #1076: "Turn on your day" for a member without one who may have one.
				'turn_on' => $tioday <= 0 && self::mayTurnOnDay( $personId ) ? home_url( '/a/' . \AgentBrain\Modules\Sharing\MembersRoute::MY_DAY ) : '',
			],
			'software' => Offer::software(),
			'offer'    => Offer::payload( $personId ),
		];
	}

	/**
	 * #1076: whether $personId sees "Turn on your day": TíoDay is on, they
	 * are a person (not a pure agent) with no live TíoDay, and they either
	 * turned theirs off (the address sends them to the profile switch) or
	 * have an agent to lead a new one.
	 */
	public static function mayTurnOnDay( int $personId ): bool {
		if ( $personId <= 0 || ! self::all()['tioday'] || Capabilities::isPureAgent( $personId ) ) {
			return false;
		}

		return TioDayDefault::isOff( $personId ) || TioDayDefault::lead( $personId ) > 0;
	}

	/**
	 * #1071: the Tomagic items a TíoDay's tasks link to (PersonDay sets a
	 * task's `item` and `board`), for the chip on each task: the item's
	 * status and its address on the board. Only boards the viewer may read.
	 *
	 * @return array<string, array{status: string, url: string}>
	 */
	public static function boardChips( int $tioday, int $viewerId ): array {
		$out     = [];
		$canRead = [];
		foreach ( StoreData::list( $tioday, 'tasks' ) as $doc ) {
			$value = is_array( $doc['value'] ?? null ) ? $doc['value'] : [];
			$bid   = is_string( $value['item'] ?? null ) ? trim( $value['item'] ) : '';
			$board = (int) ( $value['board'] ?? 0 );
			if ( $bid === '' || $board <= 0 || isset( $out[ $bid ] ) ) {
				continue;
			}
			$canRead[ $board ] ??= Backlog::isBacklog( $board ) && Backlog::canRead( $viewerId, $board ) && ! TurnedOff::pageOff( $board );
			if ( ! $canRead[ $board ] ) {
				continue;
			}
			$item = \AgentBrain\Modules\Backlog\Project::find( $board, $bid );
			if ( $item === null ) {
				continue;
			}
			$out[ $bid ] = [
				'status' => (string) ( $item['value']['status'] ?? '' ),
				'url'    => MembersAddress::url( $board ) . '#item=' . rawurlencode( $bid ),
			];
		}

		return $out;
	}

	/** #1073: how many Done and Decided entries a board page gets at most, each. */
	public const RECORD_MAX = 200;

	/**
	 * #1073: the Done and Decided chips on Tomagic. The person's own TíoDay
	 * `done` and `decisions` (the live lists, about the last 30 days; older
	 * ones are in its archive, a TíoDay "Load older" away), newest first, at
	 * most RECORD_MAX each, as plain values. Null when the person has no
	 * TíoDay or it is off. This replaces the Nautilus bridge's mirror of
	 * TíoDay into page 856 (log, decided, archive).
	 *
	 * @return array{url: string, done: list<array<string, mixed>>, decisions: list<array<string, mixed>>, more: array{done: bool, decisions: bool}}|null
	 */
	public static function record( int $personId ): ?array {
		$all    = self::all();
		$tioday = $all['tioday'] && $personId > 0 ? TioDay::forPerson( $personId ) : 0;
		if ( $tioday <= 0 || TurnedOff::pageOff( $tioday ) ) {
			return null;
		}

		$out  = [
			'url'       => MembersAddress::url( $tioday ),
			'done'      => [],
			'decisions' => [],
			'more'      => [ 'done' => false, 'decisions' => false ],
		];
		$keep = [
			'done'      => [ 'id', 'title', 'detail', 'stream', 'by', 'date', 'kind', 'links' ],
			'decisions' => [ 'id', 'title', 'detail', 'by', 'date', 'kind' ],
		];
		foreach ( $keep as $collection => $fields ) {
			$rows = [];
			foreach ( StoreData::list( $tioday, $collection ) as $doc ) {
				$value = is_array( $doc['value'] ?? null ) ? $doc['value'] : [];
				if ( ! is_string( $value['title'] ?? null ) || trim( $value['title'] ) === '' ) {
					continue;
				}
				$row = [];
				foreach ( $fields as $field ) {
					if ( ! isset( $value[ $field ] ) ) {
						continue;
					}
					$row[ $field ] = $field === 'detail' && is_string( $value[ $field ] ) ? mb_substr( $value[ $field ], 0, 600 ) : $value[ $field ];
				}
				$row['at'] = (string) ( $doc['updated_at'] ?? '' );
				$rows[]    = $row;
			}
			usort(
				$rows,
				static fn ( array $a, array $b ): int => [ (string) ( $b['date'] ?? '' ), self::idNumber( $b ), (string) $b['at'] ] <=> [ (string) ( $a['date'] ?? '' ), self::idNumber( $a ), (string) $a['at'] ]
			);
			$out['more'][ $collection ] = count( $rows ) > self::RECORD_MAX;
			$out[ $collection ]         = array_slice( $rows, 0, self::RECORD_MAX );
		}

		return $out;
	}

	/** @param array<string, mixed> $row */
	private static function idNumber( array $row ): int {
		return is_string( $row['id'] ?? null ) && preg_match( '/(\d+)$/', $row['id'], $m ) === 1 ? (int) $m[1] : 0;
	}

	/**
	 * The open tasks in a TíoDay's Now that are its owner's: a task with no
	 * `who`, or one that names the owner (meta/page owner, owner_full,
	 * owner_login, owner_aliases), as the page itself counts them.
	 */
	public static function nowCount( int $tioday ): int {
		$page  = StoreData::get( $tioday, 'meta', 'page' );
		$meta  = is_array( $page ) && is_array( $page['value'] ?? null ) ? $page['value'] : [];
		$names = [ 'you' ];
		foreach ( [ 'owner', 'owner_full', 'owner_login' ] as $field ) {
			if ( is_string( $meta[ $field ] ?? null ) && trim( $meta[ $field ] ) !== '' ) {
				$names[] = strtolower( trim( $meta[ $field ] ) );
			}
		}
		foreach ( is_array( $meta['owner_aliases'] ?? null ) ? $meta['owner_aliases'] : [] as $alias ) {
			if ( is_string( $alias ) && trim( $alias ) !== '' ) {
				$names[] = strtolower( trim( $alias ) );
			}
		}

		$count = 0;
		foreach ( StoreData::list( $tioday, 'tasks' ) as $doc ) {
			$task = is_array( $doc['value'] ?? null ) ? $doc['value'] : [];
			if ( ( $task['group'] ?? '' ) !== 'now' || ( $task['status'] ?? '' ) === 'done' ) {
				continue;
			}
			$who = is_string( $task['who'] ?? null ) ? strtolower( trim( $task['who'] ) ) : '';
			if ( $who === '' || in_array( $who, $names, true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function describe( string $name ): array {
		if ( $name === self::VOICE ) {
			return [
				'id'      => 'voice',
				'name'    => 'Voice',
				'per'     => 'person',
				'enabled' => Enabled::on(),
				'what'    => __( 'A writing voice per person and agent, by context and language, so their AIs write for them (and as them) the way they write. The AIs measure texts they are given, without keeping them, and propose the readable part; each person approves it on their profile. Imports MYNAH profiles.', 'tomatio' ),
			];
		}
		$all = self::all();
		if ( $name === self::TIODAY ) {
			return [
				'id'      => 'tioday',
				'name'    => 'TíoDay',
				'per'     => 'person',
				'enabled' => $all['tioday'],
				'what'    => __( 'One private page per person that their AIs keep current: what needs them, what the AIs are working on, what got done and what they decided.', 'tomatio' ),
			];
		}

		$boards   = Backlog::all();
		$software = Offer::software();

		return [
			'id'       => 'tomagic',
			'name'     => 'Tomagic',
			'per'      => 'brain',
			'enabled'  => $all['backlog'],
			'revealed' => $all['revealed'],
			'tagline'  => $software
				? __( 'Where software magic happens.', 'tomatio' )
				: __( 'One board for the work.', 'tomatio' ),
			'what'     => $software
				? __( 'For software projects: plan, build, ship. One board for the whole brain: what the team works on, ranked now, next and later, with a release roadmap, ideas, and an assignee per item, a person or an agent.', 'tomatio' )
				: __( 'For any project: rank the work by impact and effort, assign it to a person or an agent, keep an ideas inbox, and see the road ahead as milestones. A real estate brain, a renovation or a move fits as well as a software project.', 'tomatio' ),
			'pages'    => count( $boards ),
			'url'      => $all['revealed'] && $boards !== [] ? (string) get_permalink( $boards[0] ) : '',
		];
	}

	/**
	 * Who may switch extensions on this call: the person behind it (the
	 * signed-in person, or the person an AI connection acts for) when they
	 * are a curator or a manager; 0 otherwise. Agents on their own never.
	 */
	public static function chooser(): int {
		$userId = (int) get_current_user_id();
		if ( $userId <= 0 || ! user_can( $userId, Capabilities::CAP_USE_MCP ) ) {
			return 0;
		}
		$person = TioDay::person();

		return $person > 0 && self::mayChoose( $person ) ? $person : 0;
	}

	public static function mayChoose( int $userId ): bool {
		return $userId > 0 && ! Capabilities::isPureAgent( $userId ) && ( user_can( $userId, Capabilities::CAP_APPROVE_KNOWLEDGE ) || user_can( $userId, Capabilities::CAP_MANAGE ) );
	}

	/**
	 * The first read on a brain (#1011): TíoDay is on, unless the brain had
	 * "TíoDay for everyone" turned off, which it keeps. Tomagic is on for
	 * every brain. The board link shows only when a board already exists
	 * (an upgraded brain that opted in keeps it). Not chosen yet.
	 *
	 * @return array{tioday: bool, backlog: bool, chosen: bool, revealed: bool}
	 */
	private static function fromWhatExists(): array {
		$board = Backlog::all() !== [];

		return [
			'tioday'   => (string) get_option( TioDayDefault::OPTION, '1' ) !== '0',
			'backlog'  => true,
			'chosen'   => false,
			'revealed' => $board,
		];
	}

	/**
	 * A 1.9.18 option has no revealed key. Opted in, or a board already
	 * there, stays visible. An explicit off stays off. Never chosen: on,
	 * and hidden until TíoDay offers it.
	 *
	 * @param array<string, mixed> $stored
	 * @return array{tioday: bool, backlog: bool, chosen: bool, revealed: bool}
	 */
	private static function migrate( array $stored ): array {
		$backlog = ! empty( $stored['backlog'] );
		$chosen  = ! empty( $stored['chosen'] );
		$board   = Backlog::all() !== [];
		if ( $backlog ) {
			$shown = true;
		} elseif ( $chosen ) {
			// An explicit off stays off, even if a board post is still there.
			$shown = false;
		} elseif ( $board ) {
			$backlog = true;
			$shown   = true;
		} else {
			$backlog = true;
			$shown   = false;
		}

		return [
			'tioday'   => ! empty( $stored['tioday'] ),
			'backlog'  => $backlog,
			'chosen'   => $chosen,
			'revealed' => $shown,
		];
	}

	/**
	 * Tomagic on: the brain's live one, else the newest archived
	 * one brought back, else a new one led by $actorId's agent in the
	 * default team scope.
	 *
	 * @return int|WP_Error
	 */
	private static function boardOn( int $actorId ) {
		$live = Backlog::all();
		if ( $live !== [] ) {
			return $live[0];
		}

		$archived = self::archivedBoard();
		if ( $archived > 0 ) {
			delete_post_meta( $archived, ArtifactService::META_ARCHIVED_AT );

			return $archived;
		}

		$lead = Capabilities::isPureAgent( $actorId ) ? $actorId : TioDayDefault::lead( $actorId );
		if ( $lead <= 0 ) {
			return new WP_Error( 'ab_extension_no_agent', __( 'Tomagic is led by an agent, and you have none yet. Create one (TomaTío > Agents), then turn it on.', 'tomatio' ), [ 'status' => 409 ] );
		}
		$scopes  = new Scopes();
		$default = (int) DefaultScope::id();
		$scopeId = $default > 0 && $scopes->canWrite( $lead, $default ) ? $default : (int) ( $scopes->writeScopeIds( $lead )[0] ?? 0 );
		if ( $scopeId <= 0 ) {
			return new WP_Error( 'ab_extension_no_scope', __( 'Your agent may write no scope yet, so Tomagic has nowhere to go. Grant it the team scope, then turn it on.', 'tomatio' ), [ 'status' => 409 ] );
		}

		return Backlog::make( 'Tomagic', $lead, $scopeId, self::BOARD_SLUG );
	}

	/** Tomagic off: every live board is archived (kept, with everything on it). */
	private static function boardOff(): int {
		$first = 0;
		foreach ( Backlog::all() as $id ) {
			update_post_meta( $id, ArtifactService::META_ARCHIVED_AT, gmdate( 'Y-m-d H:i:s' ) );
			$first = $first > 0 ? $first : $id;
		}

		return $first;
	}

	private static function archivedBoard(): int {
		$ids = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => [ 'publish', 'private', 'draft', 'pending' ],
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_key'       => PageMaker::META_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one exact lookup over a handful of pages.
				'meta_value'     => Backlog::EXTENSION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one exact lookup over a handful of pages.
			]
		);
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( ArtifactIndex::isArchived( $id ) ) {
				return $id;
			}
		}

		return 0;
	}
}
