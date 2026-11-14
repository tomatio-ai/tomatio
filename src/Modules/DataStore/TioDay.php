<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\AuthorizedBy;
use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\AgentId\TaskTakers;
use AgentBrain\Modules\Artifacts\ArtifactIndex;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\OAuth\Delegation;
use AgentBrain\Modules\OAuth\RoleSwitching;
use AgentBrain\Modules\Sharing\MembersAddress;
use AgentBrain\Modules\Sharing\ShareLinks;
use WP_Error;

/**
 * #654: a person's TíoDay, for every connected AI (#606). A TíoDay is an
 * artifact pinned to its person with post meta `ab_home_owner`
 * (HomeWorkspace::META_OWNER); its page reads only its page store, in these
 * collections and shapes, which this class writes exactly:
 *
 *  - tasks/<slug>:            {title, why, how, links, group, who, status, added, done_on, done_at?, done_by?, order}
 *  - inflight/<slug>:         {title, detail, who, links, order}, deleted when the work lands
 *  - done/<YYYY-MM-DD-NN>:    {stream, title, detail, links, by, date}
 *  - decisions/<YYYY-MM-DD-NN>: {title, detail, by, date}
 *  - comments/<random>:       {on, text, by, at}
 *  - meta/page:               {owner, owner_full, ..., updated_at, updated_by}, merged, never trimmed
 *
 * #809: every task, done item, decision and in-flight item also carries a
 * short ID (`id`: T12, D3, C2, F1) that the store stamps (TioDayIds); the
 * tools take it wherever they take a key.
 *
 * Whose TíoDay: the person behind the connection (a delegated or
 * role-switching connection's person, or the signed-in person), or, for a
 * pure agent with no person behind it, the TíoDay it leads. Who may write
 * it: whoever artifact-store-set allows (ShareLinks::canManage: the lead,
 * a curator or a manager), checked for the account the write runs as; a
 * person's own plain connection writes as their default agent
 * (RoleSwitching::ROUTED_WRITES), the same as memory-write. The owner may
 * also read their own.
 *
 * #949: an agent a task is assigned to (its `who` names the agent) may
 * also read the tasks assigned to it, close them and comment on them, and
 * nothing else (TioDayAssignee).
 */
final class TioDay {

	public const GROUPS   = [ 'now', 'week', 'later', 'waiting' ];
	public const STATUSES = [ 'open', 'waiting', 'done' ];
	/** #821: what an item is, shown as a label on the page. Only feature work for now: building or changing the product. */
	public const KINDS = [ 'feature' ];
	/** #828: a release document's status: planning, released (tagged), deployed (live on the brains). */
	public const RELEASE_STATUSES = [ 'planning', 'released', 'deployed' ];
	/** #828: where a release item stands. #885: merged is what the Merge log sets when the pull request lands. */
	public const RELEASE_STATES = [ 'merged', 'in dev-wip', 'in progress', 'proposed', 'held' ];
	/** #828: in-flight work or a release nobody updated for this many days is listed as stale. */
	public const STALE_DAYS = 3;

	public const MAX_TITLE  = 200;
	public const MAX_TEXT   = 2000;
	public const MAX_NAME   = 100;
	public const MAX_LINKS  = 10;
	public const MAX_PER_DAY = 99;

	/** Comments tioday-get returns without `since`: the last week. */
	private const COMMENT_DAYS = 7;

	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

	// -----------------------------------------------------------------
	// Whose TíoDay
	// -----------------------------------------------------------------

	/**
	 * The person behind this connection: the person an agent acts for
	 * (delegated consent or act-as), the person of a role-switching or
	 * routed connection, or the signed-in user when they are a person.
	 * 0 for a pure agent with nobody behind it.
	 */
	public static function person(): int {
		foreach ( [ AuthorizedBy::id(), RoleSwitching::person(), RoleSwitching::writer() ] as $id ) {
			if ( $id > 0 ) {
				return $id;
			}
		}

		$userId = (int) get_current_user_id();
		$user   = $userId > 0 ? get_userdata( $userId ) : false;

		return $user instanceof \WP_User && ! Delegation::isPureAgent( $user ) ? $userId : 0;
	}

	/**
	 * The account a TíoDay write runs as. During the ability that is the
	 * current user (RoleSwitching has already switched it); at discovery
	 * (tools/list runs as the person) it is the agent the write would be
	 * switched to: the act-as role, else the person's default agent.
	 */
	public static function writerId(): int {
		$userId = (int) get_current_user_id();
		if ( $userId > 0 && $userId === RoleSwitching::person() && RoleSwitching::current() > 0 ) {
			return RoleSwitching::current();
		}
		if ( $userId > 0 && $userId === RoleSwitching::writer() && RoleSwitching::current() === 0 && RoleSwitching::writeAgent() > 0 ) {
			return RoleSwitching::writeAgent();
		}

		return $userId;
	}

	/**
	 * The TíoDay of a person: their newest live artifact pinned to them,
	 * an HTML page first. 0 when they have none.
	 */
	public static function forPerson( int $personId ): int {
		if ( $personId <= 0 ) {
			return 0;
		}

		return self::pick( self::candidates( [ [ 'key' => HomeWorkspace::META_OWNER, 'value' => (string) $personId ] ] ) );
	}

	/**
	 * Which TíoDay this call is about: $artifactId when given (it must be a
	 * TíoDay), else the person's, else (a pure agent) the one it leads,
	 * else, with $orAssigned (#949, the tioday tools), the one where a task
	 * is assigned to it.
	 *
	 * @return array{id:int, owner:int}|WP_Error
	 */
	public static function resolve( int $artifactId = 0, bool $orAssigned = false ) {
		if ( $artifactId > 0 ) {
			$owner = (int) get_post_meta( $artifactId, HomeWorkspace::META_OWNER, true );
			if ( $owner <= 0 || ! self::isLive( $artifactId ) ) {
				return new WP_Error( 'ab_tioday_not_found', __( 'That artifact is not a TíoDay (a page pinned to a person).', 'tomatio' ), [ 'status' => 404 ] );
			}

			return [ 'id' => $artifactId, 'owner' => $owner ];
		}

		$person = self::person();
		if ( $person > 0 ) {
			// #606: made now when the person should have one (TioDayDefault).
			$id = self::forPerson( $person );
			$id = $id > 0 ? $id : TioDayDefault::ensure( $person );
			if ( $id <= 0 ) {
				return new WP_Error(
					'ab_tioday_none',
					/* translators: %s: the person's name. */
					sprintf( __( '%s has no TíoDay on this brain. Nothing to record.', 'tomatio' ), self::name( $person ) ),
					[ 'status' => 404 ]
				);
			}

			return [ 'id' => $id, 'owner' => $person ];
		}

		// A pure agent with nobody behind it: the TíoDay it leads.
		$agentId = (int) get_current_user_id();
		$led     = $agentId > 0 ? self::candidates( [ [ 'key' => Cpts::META_LEAD_AGENT, 'value' => (string) $agentId ], [ 'key' => HomeWorkspace::META_OWNER, 'compare' => 'EXISTS' ] ] ) : [];
		if ( $led === [] && $orAssigned ) {
			$led = self::assigned( $agentId );
		}
		if ( $led === [] ) {
			return new WP_Error( 'ab_tioday_none', __( 'No person is behind this connection and this agent leads no TíoDay and has no task on one. Connect as the person, or pass the artifact_id of a TíoDay you lead.', 'tomatio' ), [ 'status' => 404 ] );
		}

		if ( count( $led ) > 1 ) {
			$issuer = (int) get_user_meta( $agentId, AgentMeta::ISSUED_BY, true );
			$mine   = array_values( array_filter( $led, static fn ( \WP_Post $post ): bool => $issuer > 0 && (int) get_post_meta( (int) $post->ID, HomeWorkspace::META_OWNER, true ) === $issuer ) );
			if ( $mine === [] ) {
				return new WP_Error(
					'ab_tioday_ambiguous',
					/* translators: %s: comma-separated artifact ids. */
					sprintf( __( 'This agent leads several TíoDays (artifacts %s). Pass artifact_id to choose one.', 'tomatio' ), implode( ', ', array_map( static fn ( \WP_Post $post ): string => (string) $post->ID, $led ) ) ),
					[ 'status' => 409 ]
				);
			}
			$led = $mine;
		}

		$id = self::pick( $led );

		return [ 'id' => $id, 'owner' => (int) get_post_meta( $id, HomeWorkspace::META_OWNER, true ) ];
	}

	/**
	 * #949: the TíoDays with an open task assigned to $agentId, as posts.
	 *
	 * @return array<int, \WP_Post>
	 */
	private static function assigned( int $agentId ): array {
		$posts = [];
		foreach ( TioDayAssignee::find( $agentId ) as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Whether the caller keeps this TíoDay: the account the call runs as may
	 * manage it, or, for a read, it is the caller's own. An assignee (#949)
	 * does not keep it.
	 */
	public static function keeps( int $artifactId, int $owner, bool $write ): bool {
		$actor = $write ? self::writerId() : (int) get_current_user_id();

		return ShareLinks::canManage( $actor, $artifactId ) || ( ! $write && $owner === self::person() );
	}

	/**
	 * Whether the current caller may use this TíoDay tool: the TíoDay is
	 * found, and the account the call runs as may manage the artifact (the
	 * artifact-store-set rule). A read is also open to the TíoDay's owner.
	 * #949: an agent a task is assigned to may use $ability as far as
	 * TioDayAssignee allows (every field is checked once there is input).
	 * The refusal says why (over MCP, tools/call shows it), so a tool never
	 * just fails; a caller who may not use MCP at all gets a bare false,
	 * like every other tool.
	 *
	 * @param mixed $input
	 * @return bool|WP_Error
	 */
	public static function check( $input, bool $write, string $ability = '' ) {
		$userId = (int) get_current_user_id();
		if ( $userId <= 0 || ! user_can( $userId, \AgentBrain\Modules\Identity\Capabilities::CAP_USE_MCP ) ) {
			return false;
		}

		$input  = is_array( $input ) ? $input : [];
		$target = self::resolve( (int) ( $input['artifact_id'] ?? 0 ), true );
		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$actor = $write ? self::writerId() : $userId;
		if ( self::keeps( $target['id'], $target['owner'], $write ) ) {
			return true;
		}
		if ( $ability !== '' ) {
			$asked    = array_diff_key( $input, [ 'artifact_id' => true ] );
			$assignee = TioDayAssignee::allows( $ability, $input, $target['id'], $actor, $asked !== [] );
			if ( $assignee !== null ) {
				return $assignee;
			}
		}

		$lead = (int) get_post_meta( $target['id'], Cpts::META_LEAD_AGENT, true );

		return new WP_Error(
			'ab_forbidden',
			sprintf(
				/* translators: 1: the owner's name, 2: the lead agent's name, 3: the caller's name. */
				__( 'Only %2$s (the lead of %1$s\'s TíoDay), a curator or a manager can change it, and you are %3$s. Act as %2$s to keep it.', 'tomatio' ),
				self::name( $target['owner'] ),
				$lead > 0 ? self::name( $lead ) : __( 'its lead', 'tomatio' ),
				self::name( $actor )
			),
			[ 'status' => 403 ]
		);
	}

	/**
	 * The lines the MCP initialize instructions and context-briefing add
	 * when the person behind this connection has a TíoDay this connection
	 * may keep; '' otherwise.
	 *
	 * #926: worded as a rule for every turn, not a suggestion. Clients that
	 * have no hook to enforce it (ChatGPT) recorded nothing unless asked,
	 * so the block says when to write, gives everyday examples beyond code
	 * work, and allows skipping only a turn with no outcome.
	 */
	public static function instructions(): string {
		$target = self::resolve( 0, true );
		if ( $target instanceof WP_Error ) {
			return '';
		}
		if ( self::check( [], true ) !== true ) {
			// #949: an agent with tasks assigned to it hears how to report on them.
			return TioDayAssignee::instructions( $target['id'], $target['owner'], self::writerId() );
		}

		$name = self::name( $target['owner'] );

		// #953: and how to give work to the agents that take tasks.
		$takers = TaskTakers::instructions( self::writerId() );

		return sprintf(
			/* translators: 1: the person's name, 2: the TíoDay's link, 3: days. */
			__( 'TíoDay: %1$s has a TíoDay, a private page of what is theirs to do and what got done: %2$s. Keeping it current is part of every turn, not an extra: before you end a turn in which you did something for %1$s, record it there in that same turn, without being asked and without asking first. Finished work (a draft written, a message or listing prepared, a question answered with a result, a file made, a fix merged) goes in with tomatio-tioday-done. Work that will outlast the turn goes in with tomatio-tioday-inflight, cleared when it lands; when it is stuck on %1$s, set waiting_on to their name so it shows in their Now, and clear it once they act. Anything that now needs %1$s (a decision, an approval, a reply or message to send, a call to make, a step only they can take) becomes a task with tomatio-tioday-task, and so does anything %1$s asks to save to their tasks, to-do or work list. A reminder at a set time stays in the app\'s own reminders, not on TíoDay; a task asking for a review links the document\'s review page (the review_url of section-submit or artifact-review-set), never a wp-admin address. A decision %1$s makes in the conversation goes in with tomatio-tioday-decided. Early in a conversation, read it with tomatio-tioday-get and answer the new comments meant for you with tomatio-tioday-reply. Keep the release list current with tomatio-tioday-release (planning, released when tagged, deployed when live). tomatio-tioday-get lists under stale what nobody has updated for %3$d days: check its links again before repeating its status. Every item has a short ID (T12 a task, D3 done, C2 a decision, F1 in flight): when %1$s quotes one, read it with tomatio-tioday-get and item, and pass it as the slug to change it. Mark feature work (building or changing the product, such as a pull request for an issue) with kind "feature" on its in-flight item, done item, task or decision, so the page labels it. Only a turn with no outcome (small talk, a lookup that led nothing further) may skip it. Every TíoDay write answers with a say line: end your reply with it, so %1$s sees what was recorded.', 'tomatio' ),
			$name,
			self::url( $target['id'] ),
			self::STALE_DAYS
		) . ( $takers !== '' ? ' ' . $takers : '' );
	}

	/**
	 * #932: one line for the person on what a TíoDay write changed, ending
	 * with the page's link, such as `TíoDay: T2 added, “Call the notary” —
	 * https://…`. Every write tool answers with it and asks the AI to end
	 * its reply with it, so a client with no hook of its own (ChatGPT)
	 * still shows what was recorded.
	 *
	 * @param array<string, mixed> $out   The write's answer.
	 * @param array<string, mixed> $input The write's input.
	 */
	public static function say( string $ability, array $out, array $input, int $artifactId ): string {
		$value = is_array( $out['value'] ?? null ) ? $out['value'] : [];
		$id    = is_string( $out['id'] ?? null ) && $out['id'] !== '' ? $out['id'] : (string) ( $out['key'] ?? '' );
		$title = is_string( $value['title'] ?? null ) ? trim( $value['title'] ) : '';
		$first = (int) ( $out['version'] ?? 0 ) === 1;

		switch ( $ability ) {
			case AbilityNames::TIODAY_TASK:
				$what = ! empty( $input['close'] ) || ( $value['status'] ?? '' ) === 'done'
					? __( 'done', 'tomatio' )
					: ( $first ? __( 'added', 'tomatio' ) : __( 'updated', 'tomatio' ) );
				break;
			case AbilityNames::TIODAY_INFLIGHT:
				if ( array_key_exists( 'cleared', $out ) && ! array_key_exists( 'value', $out ) ) {
					/* translators: %s: the in-flight item's key. */
					return self::sayLine( sprintf( __( '%s cleared', 'tomatio' ), $id ), $artifactId );
				}
				$what = $first ? __( 'in flight', 'tomatio' ) : __( 'updated', 'tomatio' );
				break;
			case AbilityNames::TIODAY_REPLY:
				$on = is_string( $value['on'] ?? null ) ? $value['on'] : 'page';
				/* translators: %s: what the comment is on, such as T12 or page. */
				return self::sayLine( sprintf( __( 'comment posted on %s', 'tomatio' ), $on ), $artifactId );
			case AbilityNames::TIODAY_RELEASE:
				/* translators: %s: a version, such as 1.9.16. */
				return self::sayLine( sprintf( __( 'release %s updated', 'tomatio' ), (string) ( $out['key'] ?? '' ) ), $artifactId );
			default:
				$what = __( 'recorded', 'tomatio' );
		}

		$line = $id . ' ' . $what;
		if ( $title !== '' ) {
			/* translators: 1: a short ID and what happened, such as "T2 added"; 2: the item's title. */
			$line = sprintf( __( '%1$s, “%2$s”', 'tomatio' ), $line, mb_substr( $title, 0, 80 ) );
		}

		return self::sayLine( $line, $artifactId );
	}

	private static function sayLine( string $what, int $artifactId ): string {
		/* translators: 1: what changed, such as "T2 added"; 2: the TíoDay's link. */
		return sprintf( __( 'TíoDay: %1$s — %2$s', 'tomatio' ), $what, self::url( $artifactId ) );
	}

	/** The TíoDay's link: its https home link when set, else its members address. */
	public static function url( int $artifactId ): string {
		$link = (string) get_post_meta( $artifactId, HomeWorkspace::META_LINK, true );

		return str_starts_with( $link, 'https://' ) ? $link : MembersAddress::url( $artifactId );
	}

	/** A person's or agent's short name: first name, else display name. */
	public static function name( int $userId ): string {
		$user = $userId > 0 ? get_userdata( $userId ) : false;
		if ( ! $user instanceof \WP_User ) {
			return __( 'someone', 'tomatio' );
		}

		$first = trim( (string) $user->first_name );

		return $first !== '' ? $first : (string) $user->display_name;
	}

	/**
	 * @param array<int, array<string, string>> $meta
	 * @return array<int, \WP_Post>
	 */
	public static function candidates( array $meta ): array {
		$posts = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => [ 'publish', 'private', 'draft', 'pending' ],
				'posts_per_page' => 20,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array_merge( [ 'relation' => 'AND' ], $meta ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a person's pinned pages: a handful of rows.
			]
		);

		return array_values( array_filter( $posts, static fn ( $post ): bool => $post instanceof \WP_Post && ! ArtifactIndex::isArchived( (int) $post->ID ) ) );
	}

	/**
	 * The first HTML page among $posts (newest first), else the first one.
	 *
	 * @param array<int, \WP_Post> $posts
	 */
	private static function pick( array $posts ): int {
		foreach ( $posts as $post ) {
			if ( HtmlFiles::isHtml( (int) $post->ID ) ) {
				return (int) $post->ID;
			}
		}

		return isset( $posts[0] ) ? (int) $posts[0]->ID : 0;
	}

	private static function isLive( int $artifactId ): bool {
		$post = get_post( $artifactId );

		return $post instanceof \WP_Post && $post->post_type === Cpts::ARTIFACT_POST_TYPE && $post->post_status !== 'trash' && ! ArtifactIndex::isArchived( $artifactId );
	}

	// -----------------------------------------------------------------
	// Reading
	// -----------------------------------------------------------------

	/**
	 * Open tasks, everything in flight, the day's done and decisions, and
	 * the comments since $since (default: the last week).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function read( int $artifactId, int $owner, ?string $date, ?string $since ) {
		$date = $date ?? self::today();
		if ( ! self::isDate( $date ) ) {
			return self::invalid( __( 'date is a day as YYYY-MM-DD.', 'tomatio' ) );
		}

		$sinceTs = $since !== null && trim( $since ) !== '' ? strtotime( $since ) : time() - self::COMMENT_DAYS * DAY_IN_SECONDS;
		if ( $sinceTs === false ) {
			return self::invalid( __( 'since is a time, such as 2026-10-29T09:00:00Z.', 'tomatio' ) );
		}

		$tasks = array_values( array_filter( self::items( $artifactId, 'tasks' ), static fn ( array $task ): bool => ( $task['status'] ?? 'open' ) !== 'done' ) );
		usort(
			$tasks,
			static function ( array $a, array $b ): int {
				$group = array_flip( self::GROUPS );

				return [ $group[ $a['group'] ?? 'later' ] ?? 9, (int) ( $a['order'] ?? 0 ) ] <=> [ $group[ $b['group'] ?? 'later' ] ?? 9, (int) ( $b['order'] ?? 0 ) ];
			}
		);

		$inflight = self::items( $artifactId, 'inflight' );
		usort( $inflight, static fn ( array $a, array $b ): int => (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 ) );

		$onDay    = static fn ( array $item ): bool => ( $item['date'] ?? '' ) === $date;
		$comments = array_values(
			array_filter(
				self::items( $artifactId, 'comments' ),
				static function ( array $comment ) use ( $sinceTs ): bool {
					$at = strtotime( (string) ( $comment['at'] ?? '' ) );

					return $at !== false && $at >= $sinceTs;
				}
			)
		);
		usort( $comments, static fn ( array $a, array $b ): int => strcmp( (string) ( $a['at'] ?? '' ), (string) ( $b['at'] ?? '' ) ) );

		$meta  = StoreData::get( $artifactId, 'meta', 'page' );
		$stale = self::stale( $artifactId );

		return [
			'artifact_id' => $artifactId,
			'url'         => self::url( $artifactId ),
			'owner'       => self::name( $owner ),
			'date'        => $date,
			'tasks'       => $tasks,
			'inflight'    => $inflight,
			'done'        => array_values( array_filter( self::items( $artifactId, 'done' ), $onDay ) ),
			'decisions'   => array_values( array_filter( self::items( $artifactId, 'decisions' ), $onDay ) ),
			'comments'    => $comments,
			'meta'        => $meta !== null && is_array( $meta['value'] ) ? (object) $meta['value'] : new \stdClass(),
			'release'     => self::releases( $artifactId ),
			'stale'       => $stale,
		] + ( $stale !== [] ? [ 'stale_hint' => sprintf( /* translators: %d: days. */ __( 'Nobody has updated these for %d days or more. Before you repeat their status, check their links again, then update them (tomatio-tioday-inflight, tomatio-tioday-release) or clear them.', 'tomatio' ), self::STALE_DAYS ) ] : [] );
	}

	/**
	 * #828: the release this TíoDay works towards (the lowest version not
	 * deployed yet) and the latest deployed one, each with the store's
	 * updated_at.
	 *
	 * @return array{current: array<string, mixed>|null, shipped: array<string, mixed>|null}
	 */
	public static function releases( int $artifactId ): array {
		$docs = [];
		foreach ( StoreData::list( $artifactId, 'release' ) as $doc ) {
			if ( is_array( $doc['value'] ) && ! array_is_list( $doc['value'] ) ) {
				$docs[] = [ 'key' => (string) $doc['key'] ] + $doc['value'] + [ 'updated_at' => self::iso( (string) $doc['updated_at'] ) ];
			}
		}
		$number = static fn ( array $d ): string => is_string( $d['version'] ?? null ) && $d['version'] !== '' ? $d['version'] : (string) $d['key'];
		usort( $docs, static fn ( array $a, array $b ): int => version_compare( $number( $a ), $number( $b ) ) );

		$open    = array_values( array_filter( $docs, static fn ( array $d ): bool => ( $d['status'] ?? 'planning' ) !== 'deployed' ) );
		$shipped = array_values( array_filter( $docs, static fn ( array $d ): bool => ( $d['status'] ?? '' ) === 'deployed' ) );

		return [
			'current' => $open[0] ?? null,
			'shipped' => $shipped !== [] ? $shipped[ count( $shipped ) - 1 ] : null,
		];
	}

	/**
	 * #828: in-flight work, and the current release, that nobody has updated
	 * for STALE_DAYS or more, by the store's own updated_at (so nobody has
	 * to remember to stamp it).
	 *
	 * @return array<int, array{ref:string, id:string|null, title:string, updated_at:string, days:int}>
	 */
	public static function stale( int $artifactId, ?int $now = null ): array {
		$now = $now ?? time();
		$out = [];
		foreach ( StoreData::list( $artifactId, 'inflight' ) as $doc ) {
			$v   = is_array( $doc['value'] ) ? $doc['value'] : [];
			$row = self::staleRow( 'f:' . $doc['key'], is_string( $v['id'] ?? null ) ? $v['id'] : null, (string) ( $v['title'] ?? $doc['key'] ), (string) $doc['updated_at'], $now );
			if ( $row !== null ) {
				$out[] = $row;
			}
		}
		$current = self::releases( $artifactId )['current'];
		if ( $current !== null ) {
			$row = self::staleRow( 'r:' . $current['key'], null, sprintf( /* translators: %s: version. */ __( 'Release %s', 'tomatio' ), (string) ( $current['version'] ?? $current['key'] ) ), (string) $current['updated_at'], $now );
			if ( $row !== null ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/** @return array{ref:string, id:string|null, title:string, updated_at:string, days:int}|null */
	private static function staleRow( string $ref, ?string $id, string $title, string $updatedAt, int $now ): ?array {
		$at = strtotime( str_ends_with( $updatedAt, 'Z' ) ? $updatedAt : $updatedAt . ' UTC' );
		if ( $at === false ) {
			return null;
		}
		$days = (int) floor( ( $now - $at ) / DAY_IN_SECONDS );

		return $days >= self::STALE_DAYS ? [ 'ref' => $ref, 'id' => $id, 'title' => $title, 'updated_at' => self::iso( $updatedAt ), 'days' => $days ] : null;
	}

	/** A store time (Y-m-d H:i:s, UTC) as ISO 8601. */
	private static function iso( string $time ): string {
		$at = strtotime( str_ends_with( $time, 'Z' ) ? $time : $time . ' UTC' );

		return $at === false ? $time : gmdate( 'Y-m-d\TH:i:s\Z', $at );
	}

	/**
	 * #809: one item by its short ID (T12) or reference (t:<key>), whatever
	 * its day or status, with every comment on it.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function item( int $artifactId, int $owner, string $ref ) {
		$found = TioDayIds::find( $artifactId, $ref );
		if ( $found === null ) {
			return new WP_Error(
				'ab_tioday_item_not_found',
				/* translators: %s: the short ID or reference that was asked for. */
				sprintf( __( 'Nothing on this TíoDay is %s. Short IDs are T (task), D (done), C (decision) and F (in flight) with a number, such as T12; references are t:<key>, d:<key>, c:<key> or f:<key>.', 'tomatio' ), $ref ),
				[ 'status' => 404 ]
			);
		}

		$comments = array_values( array_filter( self::items( $artifactId, 'comments' ), static fn ( array $c ): bool => ( $c['on'] ?? '' ) === $found['ref'] ) );
		usort( $comments, static fn ( array $a, array $b ): int => strcmp( (string) ( $a['at'] ?? '' ), (string) ( $b['at'] ?? '' ) ) );

		return [
			'artifact_id' => $artifactId,
			'url'         => self::url( $artifactId ),
			'owner'       => self::name( $owner ),
			'item'        => [
				'kind' => [ 'tasks' => 'task', 'done' => 'done', 'decisions' => 'decision', 'inflight' => 'in flight' ][ $found['collection'] ],
				'id'   => $found['id'],
				'ref'  => $found['ref'],
				'key'  => $found['key'],
			] + ( is_array( $found['doc']['value'] ) ? $found['doc']['value'] : [] ),
			'comments'    => $comments,
		];
	}

	/**
	 * A tool's `slug` as the key it names in $collection: a short ID (T12)
	 * is looked up, a slug stays as it is. The error when no item has the ID.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	private static function withKey( int $artifactId, string $collection, array $input, string $field ) {
		if ( ! isset( $input[ $field ] ) || ! is_string( $input[ $field ] ) || ! TioDayIds::isId( $input[ $field ] ) ) {
			return $input;
		}

		$key = TioDayIds::keyFor( $artifactId, $collection, $input[ $field ] );
		if ( $key === null ) {
			return new WP_Error(
				'ab_tioday_item_not_found',
				/* translators: 1: the short ID, 2: what kind of item it should be. */
				sprintf( __( 'No %2$s on this TíoDay has the ID %1$s (tomatio-tioday-get lists them).', 'tomatio' ), (string) TioDayIds::normalize( $input[ $field ] ), [ 'tasks' => __( 'task', 'tomatio' ), 'inflight' => __( 'in-flight item', 'tomatio' ) ][ $collection ] ?? $collection ),
				[ 'status' => 404 ]
			);
		}
		$input[ $field ] = $key;

		return $input;
	}

	/**
	 * Every document of a collection as its value with its `key`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function items( int $artifactId, string $collection ): array {
		$out = [];
		foreach ( StoreData::list( $artifactId, $collection ) as $doc ) {
			if ( is_array( $doc['value'] ) && ! array_is_list( $doc['value'] ) ) {
				$out[] = [ 'key' => (string) $doc['key'] ] + $doc['value'];
			}
		}

		return $out;
	}

	// -----------------------------------------------------------------
	// Writing
	// -----------------------------------------------------------------

	/**
	 * Add a task, or change one by its slug; `close` marks it done.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function task( int $artifactId, int $owner, array $input ) {
		$input = self::withKey( $artifactId, 'tasks', $input, 'slug' );
		if ( $input instanceof WP_Error ) {
			return $input;
		}
		$by      = self::by( $artifactId );
		$slug    = self::given( $input, 'slug' ) && is_string( $input['slug'] ) ? self::slug( $input['slug'] ) : null;
		$patch   = [];
		$strings = [ 'title' => self::MAX_TITLE, 'why' => self::MAX_TEXT, 'how' => self::MAX_TEXT, 'who' => self::MAX_NAME ];
		foreach ( $strings as $field => $max ) {
			if ( array_key_exists( $field, $input ) ) {
				$patch[ $field ] = self::text( $input[ $field ], $max, $field !== 'title' && $field !== 'who' );
			}
		}
		if ( array_key_exists( 'links', $input ) ) {
			$links = self::links( $input['links'] );
			if ( $links instanceof WP_Error ) {
				return $links;
			}
			$patch['links'] = $links;
		}
		if ( array_key_exists( 'group', $input ) ) {
			if ( ! in_array( $input['group'], self::GROUPS, true ) ) {
				return self::invalid( __( 'group is one of now, week, later or waiting.', 'tomatio' ) );
			}
			$patch['group'] = $input['group'];
		}
		if ( array_key_exists( 'status', $input ) ) {
			if ( ! in_array( $input['status'], self::STATUSES, true ) ) {
				return self::invalid( __( 'status is one of open, waiting or done.', 'tomatio' ) );
			}
			$patch['status'] = $input['status'];
		}
		if ( array_key_exists( 'order', $input ) ) {
			if ( ! is_numeric( $input['order'] ) ) {
				return self::invalid( __( 'order is a number.', 'tomatio' ) );
			}
			$patch['order'] = (int) $input['order'];
		}
		if ( ! empty( $input['close'] ) ) {
			$patch['status'] = 'done';
		}
		$kind = self::kindOf( $input );
		if ( $kind instanceof WP_Error ) {
			return $kind;
		}
		if ( $kind !== null ) {
			$patch['kind'] = $kind;
		}
		if ( ( $patch['title'] ?? null ) === '' ) {
			unset( $patch['title'] ); // A title is never blanked.
		}
		if ( self::given( $input, 'slug' ) && $slug === null ) {
			return self::invalid( __( 'slug is lower-case letters, digits and hyphens, up to 64.', 'tomatio' ) );
		}

		$doneBy = isset( $input['done_by'] ) ? self::text( $input['done_by'], self::MAX_NAME ) : '';
		$stored = self::busy();

		for ( $try = 0; $try < 3; $try++ ) {
			$existing = $slug !== null ? StoreData::get( $artifactId, 'tasks', $slug ) : null;

			if ( $existing === null ) {
				if ( ( $patch['title'] ?? '' ) === '' ) {
					return self::invalid( __( 'A new task needs a title.', 'tomatio' ) );
				}
				$key   = $slug ?? self::freeSlug( $artifactId, 'tasks', (string) $patch['title'] );
				$value = [
					'title'   => $patch['title'],
					'why'     => $patch['why'] ?? '',
					'how'     => $patch['how'] ?? '',
					'links'   => $patch['links'] ?? [],
					'group'   => $patch['group'] ?? 'now',
					'who'     => ( $patch['who'] ?? '' ) !== '' ? $patch['who'] : self::name( $owner ),
					'status'  => $patch['status'] ?? 'open',
					'added'   => self::today(),
					'done_on' => null,
					'order'   => $patch['order'] ?? self::nextOrder( $artifactId, 'tasks' ),
				];
				$version = 0;
			} else {
				$key     = (string) $slug;
				$value   = is_array( $existing['value'] ) && ! array_is_list( $existing['value'] ) ? array_merge( $existing['value'], $patch ) : $patch;
				$version = (int) $existing['version'];
			}

			$value = self::taskDoneFields( $value, $existing !== null && is_array( $existing['value'] ) ? $existing['value'] : null, $doneBy, self::name( (int) get_current_user_id() ) );
			$value = self::withKind( $value, $patch );

			$stored = StoreData::set( $artifactId, 'tasks', $key, $value, $by, null, $version );
			if ( ! self::isConflict( $stored ) ) {
				return self::landed( $artifactId, $stored, $by );
			}
			// A task created by a parallel session under a derived slug:
			// derive again; an existing slug: merge into the newer version.
		}

		return $stored;
	}

	/**
	 * #821: the `kind` an input gives: one of KINDS, '' to clear it, null
	 * when the input leaves it out.
	 *
	 * @param array<string, mixed> $input
	 * @return string|null|WP_Error
	 */
	private static function kindOf( array $input ) {
		if ( ! array_key_exists( 'kind', $input ) || $input['kind'] === null ) {
			return null;
		}
		$kind = is_string( $input['kind'] ) ? strtolower( trim( $input['kind'] ) ) : '';
		if ( $kind !== '' && ! in_array( $kind, self::KINDS, true ) ) {
			return self::invalid( __( 'kind is "feature" for feature work (building or changing the product), or empty to clear it.', 'tomatio' ) );
		}

		return $kind;
	}

	/**
	 * $value with the kind $patch sets, or without one when it clears it.
	 *
	 * @param array<string, mixed> $value
	 * @param array<string, mixed> $patch
	 * @return array<string, mixed>
	 */
	private static function withKind( array $value, array $patch ): array {
		if ( array_key_exists( 'kind', $patch ) && $patch['kind'] !== null ) {
			$value['kind'] = $patch['kind'];
		}
		if ( ( $value['kind'] ?? '' ) === '' ) {
			unset( $value['kind'] );
		}

		return $value;
	}

	/**
	 * Done fields follow the status: done sets done_on (today, unless it was
	 * already done), done_at (#820: the time, likewise) and done_by ($doneBy
	 * when given, else the caller when the task is newly done); any other
	 * status clears them.
	 *
	 * @param array<string, mixed>      $value
	 * @param array<string, mixed>|null $before
	 * @return array<string, mixed>
	 */
	private static function taskDoneFields( array $value, ?array $before, string $doneBy, string $caller ): array {
		if ( ( $value['status'] ?? 'open' ) === 'done' ) {
			$before           = $before ?? [];
			$doneOn           = (string) ( $before['done_on'] ?? '' );
			$wasDone          = ( $before['status'] ?? '' ) === 'done' && self::isDate( $doneOn );
			$value['done_on'] = $wasDone ? $doneOn : self::today();
			$doneAt           = (string) ( $before['done_at'] ?? '' );
			if ( $wasDone && $doneAt !== '' ) {
				$value['done_at'] = $doneAt;
			} elseif ( ! $wasDone ) {
				$value['done_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
			}
			if ( $doneBy !== '' ) {
				$value['done_by'] = $doneBy;
			} elseif ( ! $wasDone ) {
				$value['done_by'] = $caller;
			}

			return $value;
		}

		$value['done_on'] = null;
		unset( $value['done_by'], $value['done_at'] );

		return $value;
	}

	/**
	 * Record what got done under the next free key of the day, and clear
	 * the in-flight item it finishes.
	 *
	 * #885: $memoryId is the memory that already records this work (the
	 * Merge log's), kept on the item as `memory_id` so StoreMemory does not
	 * mirror it into a second one. It is not a tool input.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function done( int $artifactId, array $input, int $memoryId = 0 ) {
		$stream = self::text( $input['stream'] ?? '', self::MAX_NAME );
		$title  = self::text( $input['title'] ?? '', self::MAX_TITLE );
		if ( $stream === '' || $title === '' ) {
			return self::invalid( __( 'Done needs a stream (the work stream, such as a project) and a title.', 'tomatio' ) );
		}
		$links = self::links( $input['links'] ?? [] );
		if ( $links instanceof WP_Error ) {
			return $links;
		}
		$date = isset( $input['date'] ) ? (string) $input['date'] : self::today();
		if ( ! self::isDate( $date ) ) {
			return self::invalid( __( 'date is a day as YYYY-MM-DD.', 'tomatio' ) );
		}
		$kind = self::kindOf( $input );
		if ( $kind instanceof WP_Error ) {
			return $kind;
		}

		$input = self::withKey( $artifactId, 'inflight', $input, 'clear_inflight' );
		if ( $input instanceof WP_Error ) {
			return $input;
		}
		$clear = null;
		if ( isset( $input['clear_inflight'] ) && $input['clear_inflight'] !== '' ) {
			$clear = is_string( $input['clear_inflight'] ) ? StoreData::normalizeKey( $input['clear_inflight'] ) : null;
			if ( $clear === null ) {
				return self::invalid( __( 'clear_inflight is the key of an in-flight item.', 'tomatio' ) );
			}
		}

		$by    = self::by( $artifactId );
		$value = [
			'stream' => $stream,
			'title'  => $title,
			'detail' => self::text( $input['detail'] ?? '', self::MAX_TEXT, true ),
			'links'  => $links,
			'by'     => self::text( $input['by'] ?? '', self::MAX_NAME ) !== '' ? self::text( $input['by'], self::MAX_NAME ) : self::name( (int) get_current_user_id() ),
			'date'   => $date,
		];
		$value = self::withKind( $value, [ 'kind' => $kind ] );
		if ( $memoryId > 0 ) {
			$value[ StoreMemory::RECORDED_AS ] = $memoryId;
		}

		$stored = self::allocate( $artifactId, 'done', $date, $value, $by );
		if ( $stored instanceof WP_Error ) {
			return $stored;
		}

		$out = self::landed( $artifactId, $stored, $by );
		if ( $clear !== null ) {
			$out['cleared'] = StoreData::delete( $artifactId, 'inflight', $clear, null, $by );
		}

		return $out;
	}

	/**
	 * Set an in-flight item (by its slug), or clear it.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function inflight( int $artifactId, array $input ) {
		$input = self::withKey( $artifactId, 'inflight', $input, 'slug' );
		if ( $input instanceof WP_Error ) {
			return $input;
		}
		$slug = self::given( $input, 'slug' ) && is_string( $input['slug'] ) ? self::slug( $input['slug'] ) : null;
		$by   = self::by( $artifactId );

		if ( ! empty( $input['clear'] ) ) {
			$key = isset( $input['slug'] ) && is_string( $input['slug'] ) ? StoreData::normalizeKey( $input['slug'] ) : null;
			if ( $key === null ) {
				return self::invalid( __( 'Clearing needs the slug of the in-flight item.', 'tomatio' ) );
			}
			$cleared = StoreData::delete( $artifactId, 'inflight', $key, null, $by );
			if ( $cleared ) {
				self::touch( $artifactId, $by );
			}

			return [ 'key' => $key, 'cleared' => $cleared ];
		}

		if ( self::given( $input, 'slug' ) && $slug === null ) {
			return self::invalid( __( 'slug is lower-case letters, digits and hyphens, up to 64.', 'tomatio' ) );
		}

		$patch = [];
		// #824: waiting_on is who the work waits on; the page lists it in Now when that is the person.
		foreach ( [ 'title' => self::MAX_TITLE, 'detail' => self::MAX_TEXT, 'who' => self::MAX_NAME, 'waiting_on' => self::MAX_NAME ] as $field => $max ) {
			if ( array_key_exists( $field, $input ) ) {
				$patch[ $field ] = self::text( $input[ $field ], $max, $field === 'detail' );
			}
		}
		if ( array_key_exists( 'links', $input ) ) {
			$links = self::links( $input['links'] );
			if ( $links instanceof WP_Error ) {
				return $links;
			}
			$patch['links'] = $links;
		}
		if ( array_key_exists( 'order', $input ) ) {
			if ( ! is_numeric( $input['order'] ) ) {
				return self::invalid( __( 'order is a number.', 'tomatio' ) );
			}
			$patch['order'] = (int) $input['order'];
		}
		if ( ( $patch['title'] ?? null ) === '' ) {
			unset( $patch['title'] ); // A title is never blanked.
		}
		$kind = self::kindOf( $input );
		if ( $kind instanceof WP_Error ) {
			return $kind;
		}
		if ( $kind !== null ) {
			$patch['kind'] = $kind;
		}

		$stored = self::busy();
		for ( $try = 0; $try < 3; $try++ ) {
			$existing = $slug !== null ? StoreData::get( $artifactId, 'inflight', $slug ) : null;
			if ( $existing === null ) {
				if ( ( $patch['title'] ?? '' ) === '' ) {
					return self::invalid( __( 'A new in-flight item needs a title.', 'tomatio' ) );
				}
				$key     = $slug ?? self::freeSlug( $artifactId, 'inflight', (string) $patch['title'] );
				$value   = [
					'title'  => $patch['title'],
					'detail' => $patch['detail'] ?? '',
					'who'    => ( $patch['who'] ?? '' ) !== '' ? $patch['who'] : self::name( (int) get_current_user_id() ),
					'links'  => $patch['links'] ?? [],
					'order'  => $patch['order'] ?? self::nextOrder( $artifactId, 'inflight' ),
				];
				$version = 0;
			} else {
				$key     = (string) $slug;
				$value   = is_array( $existing['value'] ) && ! array_is_list( $existing['value'] ) ? array_merge( $existing['value'], $patch ) : $patch;
				$version = (int) $existing['version'];
			}

			$value  = self::withKind( $value, $patch );
			if ( array_key_exists( 'waiting_on', $patch ) ) {
				$value['waiting_on'] = $patch['waiting_on'];
			}
			if ( ( $value['waiting_on'] ?? '' ) === '' ) {
				unset( $value['waiting_on'] );
			}
			$stored = StoreData::set( $artifactId, 'inflight', $key, $value, $by, null, $version );
			if ( ! self::isConflict( $stored ) ) {
				return self::landed( $artifactId, $stored, $by );
			}
		}

		return $stored;
	}

	/**
	 * Record a decision under the next free key of the day.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function decided( int $artifactId, int $owner, array $input ) {
		$title = self::text( $input['title'] ?? '', self::MAX_TITLE );
		if ( $title === '' ) {
			return self::invalid( __( 'A decision needs a title.', 'tomatio' ) );
		}
		$date = isset( $input['date'] ) ? (string) $input['date'] : self::today();
		if ( ! self::isDate( $date ) ) {
			return self::invalid( __( 'date is a day as YYYY-MM-DD.', 'tomatio' ) );
		}
		$kind = self::kindOf( $input );
		if ( $kind instanceof WP_Error ) {
			return $kind;
		}

		$by     = self::by( $artifactId );
		$byName = self::text( $input['by'] ?? '', self::MAX_NAME );
		$value  = [
			'title'  => $title,
			'detail' => self::text( $input['detail'] ?? '', self::MAX_TEXT, true ),
			'by'     => $byName !== '' ? $byName : self::name( $owner ),
			'date'   => $date,
		];
		$value  = self::withKind( $value, [ 'kind' => $kind ] );

		$stored = self::allocate( $artifactId, 'decisions', $date, $value, $by );

		return $stored instanceof WP_Error ? $stored : self::landed( $artifactId, $stored, $by );
	}

	/**
	 * #828: keep a release document: its status (planning, released when
	 * tagged, deployed when live), note and items, one at a time (`item`,
	 * matched by url, else title) or all at once (`items`). Released and
	 * deployed stamp their gate (`gates.released` / `gates.deployed`) with
	 * today and the caller once. `open_next` opens the next version as
	 * planning when it has no document yet.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function release( int $artifactId, array $input ) {
		$version = self::releaseKey( $input['version'] ?? null );
		if ( $version === null ) {
			return self::invalid( __( 'version is a release number, such as 1.9.11.', 'tomatio' ) );
		}
		$next = null;
		if ( self::given( $input, 'open_next' ) ) {
			$next = self::releaseKey( $input['open_next'] );
			if ( $next === null || version_compare( $next, $version ) <= 0 ) {
				return self::invalid( __( 'open_next is the version after this one, such as 1.9.12.', 'tomatio' ) );
			}
		}
		if ( isset( $input['status'] ) && ! in_array( $input['status'], self::RELEASE_STATUSES, true ) ) {
			return self::invalid( __( 'status is one of planning, released (tagged) or deployed (live on the brains).', 'tomatio' ) );
		}
		$items = null;
		if ( array_key_exists( 'items', $input ) ) {
			$items = [];
			foreach ( is_array( $input['items'] ) ? $input['items'] : [] as $raw ) {
				$item = self::releaseItem( $raw );
				if ( $item instanceof WP_Error ) {
					return $item;
				}
				$items[] = $item + [ 'state' => 'proposed' ];
			}
		}
		$one = null;
		if ( isset( $input['item'] ) ) {
			$one = self::releaseItem( $input['item'] );
			if ( $one instanceof WP_Error ) {
				return $one;
			}
		}

		$by     = self::by( $artifactId );
		$caller = self::name( (int) get_current_user_id() );
		$stored = self::busy();
		for ( $try = 0; $try < 3; $try++ ) {
			$existing = StoreData::get( $artifactId, 'release', $version );
			$value    = $existing !== null && is_array( $existing['value'] ) && ! array_is_list( $existing['value'] ) ? $existing['value'] : self::newRelease( $version );

			if ( isset( $input['status'] ) ) {
				$value['status'] = $input['status'];
			}
			if ( array_key_exists( 'note', $input ) ) {
				$value['note'] = self::text( $input['note'], self::MAX_TEXT, true );
			}
			$list = is_array( $value['items'] ?? null ) ? array_values( $value['items'] ) : [];
			if ( $items !== null ) {
				$list = $items;
			}
			if ( $one !== null ) {
				$list = self::withReleaseItem( $list, $one, ! empty( $input['item']['remove'] ) );
			}
			$value['items'] = $list;

			$gates = is_array( $value['gates'] ?? null ) && ! array_is_list( $value['gates'] ) ? $value['gates'] : [];
			$stamp = [ 'on' => self::today(), 'by' => $caller ];
			if ( in_array( $value['status'] ?? 'planning', [ 'released', 'deployed' ], true ) && ! isset( $gates['released'] ) ) {
				$gates['released'] = $stamp;
			}
			if ( ( $value['status'] ?? '' ) === 'deployed' && ! isset( $gates['deployed'] ) ) {
				$gates['deployed'] = $stamp;
			}
			$value['gates'] = $gates === [] ? new \stdClass() : $gates;

			$stored = StoreData::set( $artifactId, 'release', $version, $value, $by, null, $existing !== null ? (int) $existing['version'] : 0 );
			if ( ! self::isConflict( $stored ) ) {
				break;
			}
		}

		$out = self::landed( $artifactId, $stored, $by );
		if ( $out instanceof WP_Error || $next === null ) {
			return $out;
		}
		if ( StoreData::get( $artifactId, 'release', $next ) === null ) {
			$opened = StoreData::set( $artifactId, 'release', $next, self::newRelease( $next ), $by, null, 0 );
			if ( $opened instanceof WP_Error && ! self::isConflict( $opened ) ) {
				return $opened;
			}
		}
		$out['next'] = $next;

		return $out;
	}

	/**
	 * #885: the version work merged now goes into: the lowest planning
	 * release newer than every release already tagged or shipped, else the
	 * patch after the newest of those (1.9.12 gives 1.9.13), which
	 * release() then opens as planning. Null when the TíoDay keeps no
	 * releases at all.
	 */
	public static function nextRelease( int $artifactId ): ?string {
		$planning = [];
		$past     = [];
		foreach ( StoreData::list( $artifactId, 'release' ) as $doc ) {
			$value   = is_array( $doc['value'] ) && ! array_is_list( $doc['value'] ) ? $doc['value'] : [];
			$version = self::releaseKey( is_string( $value['version'] ?? null ) && $value['version'] !== '' ? $value['version'] : (string) $doc['key'] );
			if ( $version === null ) {
				continue;
			}
			if ( ( $value['status'] ?? 'planning' ) === 'planning' ) {
				$planning[] = $version;
			} else {
				$past[] = $version;
			}
		}
		$byVersion = static fn ( string $a, string $b ): int => (int) version_compare( $a, $b );
		usort( $past, $byVersion );
		$newest   = $past !== [] ? $past[ count( $past ) - 1 ] : null;
		$planning = array_values( array_filter( $planning, static fn ( string $v ): bool => $newest === null || version_compare( $v, $newest ) > 0 ) );
		usort( $planning, $byVersion );
		if ( $planning !== [] ) {
			return $planning[0];
		}
		if ( $newest === null ) {
			return null;
		}

		$parts = explode( '.', (string) preg_replace( '/-.*$/', '', $newest ) );
		$last           = count( $parts ) - 1;
		$parts[ $last ] = (string) ( (int) $parts[ $last ] + 1 );

		return self::releaseKey( implode( '.', $parts ) );
	}

	/** @param mixed $version */
	private static function releaseKey( $version ): ?string {
		$version = is_string( $version ) ? ltrim( trim( $version ), 'vV' ) : '';

		return preg_match( '/^\d+\.\d+(\.\d+)?(-[0-9A-Za-z.]+)?$/', $version ) === 1 ? StoreData::normalizeKey( $version ) : null;
	}

	/** @return array<string, mixed> */
	private static function newRelease( string $version ): array {
		return [
			'version' => $version,
			'status'  => 'planning',
			'note'    => '',
			'gates'   => new \stdClass(),
			'items'   => [],
		];
	}

	/**
	 * One release item: {title, url?, label?, state?, for?}; only the
	 * fields given, so a change keeps the rest.
	 *
	 * @param mixed $raw
	 * @return array<string, string>|WP_Error
	 */
	private static function releaseItem( $raw ) {
		if ( ! is_array( $raw ) ) {
			return self::invalid( __( 'A release item is {title, url, label, state, for}.', 'tomatio' ) );
		}
		$title = self::text( $raw['title'] ?? '', self::MAX_TITLE );
		$url   = is_string( $raw['url'] ?? null ) ? trim( $raw['url'] ) : '';
		if ( $title === '' && $url === '' ) {
			return self::invalid( __( 'A release item needs a title (or, to change or remove one, its url).', 'tomatio' ) );
		}
		if ( $url !== '' ) {
			$links = self::links( [ [ 'label' => 'link', 'url' => $url ] ] );
			if ( $links instanceof WP_Error ) {
				return $links;
			}
		}
		$state = is_string( $raw['state'] ?? null ) ? $raw['state'] : '';
		if ( $state !== '' && ! in_array( $state, self::RELEASE_STATES, true ) ) {
			return self::invalid( __( 'A release item\'s state is one of merged, in dev-wip, in progress, proposed or held.', 'tomatio' ) );
		}

		return array_filter(
			[
				'title' => $title,
				'url'   => $url,
				'label' => self::text( $raw['label'] ?? '', self::MAX_NAME ),
				'state' => $state,
				'for'   => self::text( $raw['for'] ?? '', self::MAX_NAME ),
			],
			static fn ( string $v ): bool => $v !== ''
		);
	}

	/**
	 * $list with $item added, changed (same url, else same title) or removed.
	 *
	 * @param array<int, mixed>     $list
	 * @param array<string, string> $item
	 * @return array<int, mixed>
	 */
	private static function withReleaseItem( array $list, array $item, bool $remove ): array {
		$same = static function ( $old ) use ( $item ): bool {
			if ( ! is_array( $old ) ) {
				return false;
			}
			if ( ( $item['url'] ?? '' ) !== '' && ( $old['url'] ?? '' ) !== '' ) {
				return $old['url'] === $item['url'];
			}

			return ( $item['title'] ?? '' ) !== '' && strtolower( (string) ( $old['title'] ?? '' ) ) === strtolower( $item['title'] );
		};
		foreach ( $list as $i => $old ) {
			if ( $same( $old ) ) {
				if ( $remove ) {
					unset( $list[ $i ] );
				} else {
					$list[ $i ] = array_merge( is_array( $old ) ? $old : [], $item );
				}

				return array_values( $list );
			}
		}
		if ( ! $remove && ( $item['title'] ?? '' ) !== '' ) {
			$list[] = $item + [ 'state' => 'proposed' ];
		}

		return array_values( $list );
	}

	/**
	 * Answer a comment: a new comment on the same item (or on `on`).
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function reply( int $artifactId, array $input ) {
		$text = self::text( $input['text'] ?? '', self::MAX_TEXT, true );
		if ( $text === '' ) {
			return self::invalid( __( 'A reply needs text.', 'tomatio' ) );
		}

		$on = null;
		if ( isset( $input['comment'] ) && is_string( $input['comment'] ) && $input['comment'] !== '' ) {
			$key      = StoreData::normalizeKey( $input['comment'] );
			$original = $key !== null ? StoreData::get( $artifactId, 'comments', $key ) : null;
			if ( $original === null || ! is_array( $original['value'] ) ) {
				return new WP_Error( 'ab_tioday_comment_not_found', __( 'No comment with that key on this TíoDay (tomatio-tioday-get lists them).', 'tomatio' ), [ 'status' => 404 ] );
			}
			$on = (string) ( $original['value']['on'] ?? 'page' );
		} elseif ( isset( $input['on'] ) && is_string( $input['on'] ) ) {
			$on = $input['on'];
			// #809: "T12" is the task's t:<key>, so the page shows the reply on it.
			if ( TioDayIds::isId( $on ) ) {
				$item = TioDayIds::find( $artifactId, $on );
				if ( $item === null ) {
					/* translators: %s: the short ID. */
					return new WP_Error( 'ab_tioday_item_not_found', sprintf( __( 'Nothing on this TíoDay has the ID %s (tomatio-tioday-get lists them).', 'tomatio' ), (string) TioDayIds::normalize( $on ) ), [ 'status' => 404 ] );
				}
				$on = $item['ref'];
			}
		}

		if ( $on === null || preg_match( '/^(page|[tdcf]:[A-Za-z0-9][A-Za-z0-9._ -]{0,127})$/', $on ) !== 1 ) {
			return self::invalid( __( 'Say which comment you answer (comment: its key), or what you comment on (on: "page", a short ID such as T12, "t:<task>", "d:<done>", "c:<decision>" or "f:<in flight>").', 'tomatio' ) );
		}

		$by     = self::by( $artifactId );
		$byName = self::text( $input['by'] ?? '', self::MAX_NAME );
		$value  = [
			'on'   => $on,
			'text' => $text,
			'by'   => $byName !== '' ? $byName : self::name( (int) get_current_user_id() ),
			'at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
		];

		$stored = self::busy();
		for ( $try = 0; $try < 3; $try++ ) {
			$stored = StoreData::set( $artifactId, 'comments', StoreData::generateKey(), $value, $by, null, 0 );
			if ( ! self::isConflict( $stored ) ) {
				break;
			}
		}

		return $stored instanceof WP_Error ? $stored : self::landed( $artifactId, $stored, $by );
	}

	/**
	 * Write $value under the next free `<date>-NN` of $collection: start
	 * past the highest number of the day, and on a key a parallel session
	 * took first (create-only, expected_version 0) move to the next.
	 *
	 * $from is the first number to try (default: past the day's highest).
	 *
	 * @param array<string, mixed>                                                 $value
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null} $by
	 * @return array<string, mixed>|WP_Error
	 */
	public static function allocate( int $artifactId, string $collection, string $date, array $value, array $by, ?int $from = null ) {
		$next = 1;
		foreach ( StoreData::list( $artifactId, $collection ) as $doc ) {
			if ( preg_match( '/^' . preg_quote( $date, '/' ) . '-(\d{2,})$/', (string) $doc['key'], $m ) === 1 ) {
				$next = max( $next, (int) $m[1] + 1 );
			}
		}
		$next = $from !== null ? max( 1, $from ) : $next;

		for ( $n = $next; $n <= self::MAX_PER_DAY; $n++ ) {
			$stored = StoreData::set( $artifactId, $collection, sprintf( '%s-%02d', $date, $n ), $value, $by, null, 0 );
			if ( ! self::isConflict( $stored ) ) {
				return $stored;
			}
		}

		return new WP_Error( 'ab_tioday_day_full', __( 'That day already has 99 entries.', 'tomatio' ), [ 'status' => 409 ] );
	}

	/**
	 * A stored document as the tools return it, after refreshing meta/page.
	 *
	 * @param array<string, mixed>|WP_Error                                    $stored
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null} $by
	 * @return array<string, mixed>|WP_Error
	 */
	private static function landed( int $artifactId, $stored, array $by ) {
		if ( $stored instanceof WP_Error ) {
			return $stored;
		}

		self::touch( $artifactId, $by );

		$id = is_array( $stored['value'] ) && is_string( $stored['value'][ TioDayIds::FIELD ] ?? null ) ? TioDayIds::normalize( $stored['value'][ TioDayIds::FIELD ] ) : null;

		return [
			'key'     => (string) $stored['key'],
			'id'      => $id,
			'value'   => $stored['value'],
			'version' => (int) $stored['version'],
		];
	}

	/**
	 * meta/page: updated_at and updated_by, merged into what is there, so
	 * no field the page keeps is ever dropped. Best effort: a parallel
	 * update of the same document wins.
	 *
	 * @param array{anonymous:bool,type:string,id:int|null,name:string|null} $by
	 */
	private static function touch( int $artifactId, array $by ): void {
		$now      = [ 'updated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'updated_by' => self::name( (int) get_current_user_id() ) ];
		$existing = StoreData::get( $artifactId, 'meta', 'page' );
		if ( $existing === null ) {
			$owner = (int) get_post_meta( $artifactId, HomeWorkspace::META_OWNER, true );
			$user  = get_userdata( $owner );
			StoreData::set( $artifactId, 'meta', 'page', [ 'owner' => self::name( $owner ), 'owner_full' => $user instanceof \WP_User ? (string) $user->display_name : '', 'owner_login' => $user instanceof \WP_User ? (string) $user->user_login : '' ] + $now, $by, null, 0 );

			return;
		}

		$value = is_array( $existing['value'] ) && ! array_is_list( $existing['value'] ) ? array_merge( $existing['value'], $now ) : $now;
		StoreData::set( $artifactId, 'meta', 'page', $value, $by, null, (int) $existing['version'] );
	}

	// -----------------------------------------------------------------
	// Shapes
	// -----------------------------------------------------------------

	/**
	 * The caller's attribution, exactly as artifact-store-set records it.
	 *
	 * @return array{anonymous:bool,type:string,id:int|null,name:string|null}
	 */
	private static function by( int $artifactId ): array {
		return StoreData::attributionFromUser( (int) get_current_user_id(), $artifactId );
	}

	/**
	 * Plain text, no markup, capped at $max characters.
	 *
	 * @param mixed $value
	 */
	public static function text( $value, int $max, bool $multiline = false ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = $multiline ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
		$text = trim( $text );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * Links as [{label, url}], https only; a missing label is the host.
	 *
	 * @param mixed $links
	 * @return array<int, array{label:string, url:string}>|WP_Error
	 */
	public static function links( $links ) {
		if ( $links === null || $links === '' ) {
			return [];
		}
		if ( is_string( $links ) ) {
			$decoded = json_decode( $links, true );
			$links   = is_array( $decoded ) ? $decoded : null;
		}
		if ( ! is_array( $links ) || ! array_is_list( $links ) ) {
			return self::invalid( __( 'links is a list of {label, url}.', 'tomatio' ) );
		}
		if ( count( $links ) > self::MAX_LINKS ) {
			/* translators: %d: most links an item may have. */
			return self::invalid( sprintf( __( 'At most %d links.', 'tomatio' ), self::MAX_LINKS ) );
		}

		$out = [];
		foreach ( $links as $link ) {
			$url  = is_array( $link ) && is_string( $link['url'] ?? null ) ? trim( $link['url'] ) : '';
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( ! str_starts_with( strtolower( $url ), 'https://' ) || $host === '' || strlen( $url ) > 2000 || esc_url_raw( $url, [ 'https' ] ) === '' ) {
				/* translators: %s: the link that was refused. */
				return self::invalid( sprintf( __( 'Links must be https URLs: %s is not.', 'tomatio' ), $url !== '' ? $url : wp_json_encode( $link ) ) );
			}
			$label = self::text( is_array( $link ) ? ( $link['label'] ?? '' ) : '', self::MAX_NAME );
			$out[] = [ 'label' => $label !== '' ? $label : $host, 'url' => esc_url_raw( $url, [ 'https' ] ) ];
		}

		return $out;
	}

	/** A slug as given (lower-cased), or null when it is not one. */
	private static function slug( string $slug ): ?string {
		$slug = strtolower( trim( $slug ) );

		return preg_match( self::SLUG_PATTERN, $slug ) === 1 ? $slug : null;
	}

	/** A free slug made from a title: "ship-the-brief", then "ship-the-brief-2". */
	private static function freeSlug( int $artifactId, string $collection, string $title ): string {
		$base = substr( trim( (string) preg_replace( '/[^a-z0-9-]+/', '-', sanitize_title( $title ) ), '-' ), 0, 56 );
		$base = trim( $base, '-' );
		if ( $base === '' || preg_match( self::SLUG_PATTERN, $base ) !== 1 ) {
			$base = $collection === 'tasks' ? 'task' : 'work';
		}

		$slug = $base;
		for ( $n = 2; StoreData::get( $artifactId, $collection, $slug ) !== null && $n < 100; $n++ ) {
			$slug = $base . '-' . $n;
		}

		return $slug;
	}

	private static function nextOrder( int $artifactId, string $collection ): int {
		$max = 0;
		foreach ( StoreData::list( $artifactId, $collection ) as $doc ) {
			if ( is_array( $doc['value'] ) && is_numeric( $doc['value']['order'] ?? null ) ) {
				$max = max( $max, (int) $doc['value']['order'] );
			}
		}

		return $max + 1;
	}

	/** Today in the brain's time zone, as YYYY-MM-DD. */
	public static function today(): string {
		return (string) wp_date( 'Y-m-d' );
	}

	private static function isDate( string $date ): bool {
		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) === 1 && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * @param mixed $stored
	 */
	private static function isConflict( $stored ): bool {
		return $stored instanceof WP_Error && $stored->get_error_code() === 'ab_store_version_conflict';
	}

	/**
	 * Whether $input carries a non-empty $field.
	 *
	 * @param array<string, mixed> $input
	 */
	private static function given( array $input, string $field ): bool {
		return isset( $input[ $field ] ) && ( ! is_string( $input[ $field ] ) || trim( $input[ $field ] ) !== '' );
	}

	private static function busy(): WP_Error {
		return new WP_Error( 'ab_store_version_conflict', __( 'The TíoDay changed while this was being written. Try again.', 'tomatio' ), [ 'status' => 409 ] );
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'ab_tioday_invalid', $message, [ 'status' => 400 ] );
	}
}
