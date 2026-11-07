<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\Artifacts\ArtifactService;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Extensions\Package;
use AgentBrain\Modules\Extensions\PageMaker;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\DefaultScope;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\OAuth\RoleSwitching;
use WP_Error;

/**
 * #606: every person gets a TíoDay by default. It is made the first time
 * one is looked for: when they sign in, open the signed-in home page, or an
 * AI connected as them reads its instructions or uses a TíoDay tool. That
 * covers new and upgraded brains alike, with nothing to run.
 *
 * What gets made: the page from the bundled TíoDay package
 * (extensions/tioday, Extensions\PageMaker), led by the person's default
 * agent (or their first agent), pinned to them, with `meta/page` naming
 * them. It goes in a scope the person and that agent already share (see
 * scopeFor()): no scope is made and nothing is granted, because a grant to
 * the agent would change which people may switch into it
 * (RoleSwitching::agentsFor()). The page itself is private whatever the
 * scope: only its lead, curators and managers open it or its store, and
 * the home page lists it only for its person. A person with no agent (or
 * no scope in common with it) gets theirs once they have one.
 *
 * Off switches:
 *  - for the whole brain, Settings > Briefing ("TíoDay for everyone",
 *    OPTION, on unless '0'): no new TíoDay is made; existing ones stay.
 *  - for one person, their profile (META_OFF): theirs is archived and none
 *    is made; turning it back on restores the archived one.
 *
 * A TíoDay is made at most once per person on its own (META_MADE): one
 * that a curator archived or deleted does not come back unless the person
 * turns it on again.
 *
 * Kept apart from DataStore\TioDay (the tools) so it can become the
 * TíoDay extension's `binding.per: person` behaviour (#722) without
 * touching them.
 */
final class TioDayDefault {

	public const OPTION     = 'ab_tioday_default';
	public const META_OFF   = 'ab_tioday_off';
	public const META_MADE  = 'ab_tioday_made';
	public const SCOPE_META = 'ab_personal_scope';
	public const PACKAGE    = 'tioday';

	/** @var array<int, int> Who was looked at in this request, and the answer. */
	private static array $seen = [];

	public static function register(): void {
		add_action( 'wp_login', [ self::class, 'onLogin' ], 10, 2 );
		add_action( 'tomatio/home_view', [ self::class, 'onHomeView' ] );
	}

	/** @param mixed $userId */
	public static function onHomeView( $userId ): void {
		self::ensure( (int) $userId );
	}

	/** Whether the brain makes TíoDays (Settings > Briefing; on by default). */
	public static function enabled(): bool {
		return (string) get_option( self::OPTION, '1' ) !== '0';
	}

	/** Whether $userId turned their TíoDay off on their profile. */
	public static function isOff( int $userId ): bool {
		return get_user_meta( $userId, self::META_OFF, true ) === '1';
	}

	/** @param mixed $user */
	public static function onLogin( string $login, $user ): void {
		unset( $login );
		if ( $user instanceof \WP_User ) {
			self::ensure( (int) $user->ID );
		}
	}

	/**
	 * $personId's TíoDay, made now when they should have one and do not:
	 * its id, or 0 (switched off, a pure agent, no agent yet, or one was
	 * already made for them once).
	 */
	public static function ensure( int $personId ): int {
		if ( $personId <= 0 ) {
			return 0;
		}
		if ( isset( self::$seen[ $personId ] ) ) {
			return self::$seen[ $personId ];
		}

		self::$seen[ $personId ] = 0;
		$existing                = TioDay::forPerson( $personId );
		if ( $existing > 0 ) {
			return self::$seen[ $personId ] = $existing;
		}
		if ( ! self::enabled() || ! self::isPerson( $personId ) || self::isOff( $personId ) || get_user_meta( $personId, self::META_MADE, true ) !== '' ) {
			return 0;
		}

		$lead = self::lead( $personId );
		if ( $lead <= 0 ) {
			return 0;
		}

		// One request makes it: a second one at the same moment finds the mark and stops.
		if ( ! add_user_meta( $personId, self::META_MADE, 'pending', true ) ) {
			return 0;
		}

		$id = self::make( $personId, $lead );
		if ( $id instanceof WP_Error ) {
			delete_user_meta( $personId, self::META_MADE );
			self::audit( 'tioday.make_failed', $personId, 0, [ 'error' => $id->get_error_code() ] );

			return 0;
		}

		update_user_meta( $personId, self::META_MADE, (string) $id );
		self::audit( 'tioday.made', $personId, $id, [ 'lead_agent' => $lead ] );

		return self::$seen[ $personId ] = $id;
	}

	/**
	 * Make $personId's TíoDay now, led by $leadId.
	 *
	 * @return int|WP_Error
	 */
	public static function make( int $personId, int $leadId ) {
		$package = Package::bundled( self::PACKAGE );
		if ( is_string( $package ) ) {
			return new WP_Error( 'ab_tioday_package', 'The bundled TíoDay package cannot be used: ' . $package, [ 'status' => 500 ] );
		}

		$scopeId = self::scopeFor( $personId, $leadId );
		if ( $scopeId <= 0 ) {
			return new WP_Error( 'ab_tioday_no_scope', 'The person and their agent share no scope the agent may write.', [ 'status' => 409 ] );
		}

		$id = PageMaker::make( $package, $package->name(), $leadId, $scopeId, $personId );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$name = TioDay::name( $personId );
		$user = get_userdata( $personId );
		StoreData::set(
			$id,
			'meta',
			'page',
			[
				'owner'       => $name,
				'owner_full'  => $user instanceof \WP_User ? (string) $user->display_name : $name,
				// #809: the page's Now list shows the tasks whose `who` is one of the owner's names.
				'owner_login' => $user instanceof \WP_User ? (string) $user->user_login : '',
				'shared_with' => '',
				'accounts'    => [ (string) $personId => $name ],
				'updated_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'updated_by'  => 'TomaTío',
				'note'        => __( 'Your AIs keep this page: what needs you, what they are working on, what got done and what you decided.', 'tomatio' ),
			],
			StoreData::attributionFromUser( $leadId, $id )
		);

		return $id;
	}

	/** Profile: turn $personId's TíoDay off. Theirs is archived, not deleted. */
	public static function turnOff( int $personId, int $actorId ): void {
		update_user_meta( $personId, self::META_OFF, '1' );
		$id = TioDay::forPerson( $personId );
		if ( $id > 0 ) {
			update_post_meta( $id, ArtifactService::META_ARCHIVED_AT, gmdate( 'Y-m-d H:i:s' ) );
		}
		unset( self::$seen[ $personId ] );
		self::audit( 'tioday.off', $personId, $id, [ 'by' => $actorId ] );
	}

	/**
	 * Profile: turn $personId's TíoDay back on. Their newest archived one
	 * comes back; without one, a new one is made (when they have an agent).
	 */
	public static function turnOn( int $personId, int $actorId ): int {
		delete_user_meta( $personId, self::META_OFF );
		unset( self::$seen[ $personId ] );

		$archived = self::archivedFor( $personId );
		if ( $archived > 0 && TioDay::forPerson( $personId ) === 0 ) {
			delete_post_meta( $archived, ArtifactService::META_ARCHIVED_AT );
			self::audit( 'tioday.on', $personId, $archived, [ 'by' => $actorId, 'restored' => true ] );

			return $archived;
		}

		delete_user_meta( $personId, self::META_MADE );
		$id = self::ensure( $personId );
		self::audit( 'tioday.on', $personId, $id, [ 'by' => $actorId ] );

		return $id;
	}

	/** The agent that leads a new TíoDay: the default agent, else the first one they may use. */
	public static function lead( int $personId ): int {
		$lead = RoleSwitching::defaultAgentFor( $personId );
		if ( $lead > 0 ) {
			return $lead;
		}
		$agents = RoleSwitching::agentsFor( $personId );

		return isset( $agents[0] ) ? (int) $agents[0]->ID : 0;
	}

	/**
	 * Where $personId's TíoDay goes: a scope marked as theirs (term meta
	 * SCOPE_META), else the default team scope, else the first scope $leadId
	 * may write and the person may read; always one the lead may write. 0
	 * when there is none.
	 */
	public static function scopeFor( int $personId, int $leadId ): int {
		$scopes = new Scopes();
		$write  = array_values( array_filter( array_map( 'intval', $scopes->writeScopeIds( $leadId ) ), static fn ( int $id ): bool => $id > 0 && $scopes->canRead( $personId, $id ) ) );
		if ( $write === [] ) {
			return 0;
		}

		foreach ( $write as $scopeId ) {
			if ( get_term_meta( $scopeId, self::SCOPE_META, true ) === (string) $personId ) {
				return $scopeId;
			}
		}

		$default = (int) DefaultScope::id();

		return in_array( $default, $write, true ) ? $default : $write[0];
	}

	/** A person, not an agent (only people get a TíoDay of their own). */
	private static function isPerson( int $userId ): bool {
		return get_userdata( $userId ) instanceof \WP_User && ! Capabilities::isPureAgent( $userId );
	}

	/** $personId's newest archived TíoDay, or 0. */
	private static function archivedFor( int $personId ): int {
		$posts = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => [ 'publish', 'private', 'draft', 'pending' ],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a person's pinned pages: a handful of rows.
					'relation' => 'AND',
					[ 'key' => HomeWorkspace::META_OWNER, 'value' => (string) $personId ],
					[ 'key' => ArtifactService::META_ARCHIVED_AT, 'compare' => 'EXISTS' ],
				],
			]
		);

		return isset( $posts[0] ) ? (int) $posts[0] : 0;
	}

	/** @param array<string, mixed> $meta */
	private static function audit( string $event, int $personId, int $artifactId, array $meta ): void {
		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => 0,
				'event'       => $event,
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'person' => $personId ] + $meta,
			]
		);
	}

	/** Tests: forget what this request already looked at. */
	public static function reset(): void {
		self::$seen = [];
	}
}
