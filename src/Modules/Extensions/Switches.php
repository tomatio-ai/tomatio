<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Artifacts\ArtifactIndex;
use AgentBrain\Modules\Artifacts\ArtifactService;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Backlog\Backlog;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\DataStore\TioDayDefault;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\DefaultScope;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Sharing\MembersAddress;
use WP_Error;

/**
 * #987: TíoDay and Tomagic (the team board) are extensions a brain turns on
 * or off on Settings > Extensions (linked from the Get started checklist's
 * "Extensions" step) or from a curator's AI (tomatio-extension-enable).
 * TíoDay is on by default; Tomagic is for software projects and is off
 * until someone turns it on (Dennis, 14 Nov).
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
 * OPTION holds {tioday: bool, backlog: bool, chosen: bool}. A brain that
 * has no OPTION yet gets the defaults (fromWhatExists): TíoDay on, unless
 * "TíoDay for everyone" was turned off; Tomagic on only when a board
 * already exists. `chosen` is set once a person or an AI chose, which ticks
 * the checklist step.
 */
final class Switches {

	public const OPTION = 'agent_brain_extensions';

	public const TIODAY  = 'tioday';
	public const BACKLOG = 'backlog';

	/** User-facing ids (as the abilities take them) => internal names. */
	public const IDS = [
		'tioday'         => self::TIODAY,
		'tomagic'        => self::BACKLOG,
		'planning-board' => self::BACKLOG,
	];

	/** Tomagic's members address. */
	public const BOARD_SLUG = 'tomagic';

	/**
	 * The switches, read once from OPTION (written on the first read on a
	 * brain that has none).
	 *
	 * @return array{tioday: bool, backlog: bool, chosen: bool}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			$stored = self::fromWhatExists();
			update_option( self::OPTION, $stored, true );
		}

		return [
			'tioday'  => ! empty( $stored['tioday'] ),
			'backlog' => ! empty( $stored['backlog'] ),
			'chosen'  => ! empty( $stored['chosen'] ),
		];
	}

	public static function enabled( string $name ): bool {
		$key = self::IDS[ $name ] ?? $name;

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
		if ( ! in_array( $name, [ self::TIODAY, self::BACKLOG ], true ) ) {
			return new WP_Error( 'ab_extension_unknown', __( 'extension is tioday or tomagic.', 'tomatio' ), [ 'status' => 400 ] );
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
	 * A board was made some other way (`wp tomatio backlog create`): Tomagic
	 * counts as on, so the switch says what the brain has.
	 */
	public static function boardMade(): void {
		$all = self::all();
		if ( ! $all['backlog'] ) {
			$all['backlog'] = true;
			update_option( self::OPTION, $all, true );
		}
	}

	/** Record that the extensions were chosen without changing either (Settings > Extensions saved as it was). */
	public static function markChosen(): void {
		$all           = self::all();
		$all['chosen'] = true;
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Both extensions as extension-list and Settings > Extensions show them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function describeAll(): array {
		return [ self::describe( self::TIODAY ), self::describe( self::BACKLOG ) ];
	}

	/**
	 * The header links between TíoDay and Tomagic (Dennis, 15 Nov), as a
	 * member's page gets them at render time (MembersRoute, the
	 * `tomatio-extensions` meta tag): Tomagic's members address while the
	 * extension is on and the board exists, and $personId's own TíoDay.
	 * Share links never get them (the board is members-only).
	 *
	 * @return array{tomagic: array{enabled: bool, url: string}, tioday: array{enabled: bool, url: string}}
	 */
	public static function links( int $personId ): array {
		$all    = self::all();
		$boards = $all['backlog'] ? Backlog::all() : [];
		$tioday = $all['tioday'] && $personId > 0 ? TioDay::forPerson( $personId ) : 0;

		return [
			'tomagic' => [
				'enabled' => $boards !== [],
				'url'     => $boards !== [] ? MembersAddress::url( (int) $boards[0] ) : '',
			],
			'tioday'  => [
				'enabled' => $tioday > 0,
				'url'     => $tioday > 0 ? MembersAddress::url( $tioday ) : '',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function describe( string $name ): array {
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

		$boards = Backlog::all();

		return [
			'id'      => 'tomagic',
			'name'    => 'Tomagic',
			'per'     => 'brain',
			'enabled' => $all['backlog'],
			'tagline' => __( 'Where software magic happens.', 'tomatio' ),
			'what'    => __( 'For software projects: plan, build, ship. One board for the whole brain: what the team works on, ranked now, next and later, with a release roadmap, ideas, and an assignee per item, a person or an agent.', 'tomatio' ),
			'pages'   => count( $boards ),
			'url'     => $boards !== [] ? (string) get_permalink( $boards[0] ) : '',
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
	 * The first read on a brain (Dennis, 14 Nov): TíoDay is on, unless the
	 * brain had "TíoDay for everyone" turned off, which it keeps. Tomagic
	 * is for software projects, so it is off unless the brain already has a
	 * board (an upgraded brain keeps what it has; nothing is archived). Not
	 * chosen yet.
	 *
	 * @return array{tioday: bool, backlog: bool, chosen: bool}
	 */
	private static function fromWhatExists(): array {
		return [
			'tioday'  => (string) get_option( TioDayDefault::OPTION, '1' ) !== '0',
			'backlog' => Backlog::all() !== [],
			'chosen'  => false,
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
