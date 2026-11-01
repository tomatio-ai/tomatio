<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Core\AuthorizedBy;
use AgentBrain\Modules\Admin\HomeWorkspace;
use AgentBrain\Modules\AgentId\AgentMeta;
use AgentBrain\Modules\Artifacts\ArtifactIndex;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;
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
 *  - tasks/<slug>:            {title, why, how, links, group, who, status, added, done_on, done_by?, order}
 *  - inflight/<slug>:         {title, detail, who, links, order}, deleted when the work lands
 *  - done/<YYYY-MM-DD-NN>:    {stream, title, detail, links, by, date}
 *  - decisions/<YYYY-MM-DD-NN>: {title, detail, by, date}
 *  - comments/<random>:       {on, text, by, at}
 *  - meta/page:               {owner, owner_full, ..., updated_at, updated_by}, merged, never trimmed
 *
 * Whose TíoDay: the person behind the connection (a delegated or
 * role-switching connection's person, or the signed-in person), or, for a
 * pure agent with no person behind it, the TíoDay it leads. Who may write
 * it: whoever artifact-store-set allows (ShareLinks::canManage: the lead,
 * a curator or a manager), checked for the account the write runs as; a
 * person's own plain connection writes as their default agent
 * (RoleSwitching::ROUTED_WRITES), the same as memory-write. The owner may
 * also read their own.
 */
final class TioDay {

	public const GROUPS   = [ 'now', 'week', 'later', 'waiting' ];
	public const STATUSES = [ 'open', 'waiting', 'done' ];

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
	 * TíoDay), else the person's, else (a pure agent) the one it leads.
	 *
	 * @return array{id:int, owner:int}|WP_Error
	 */
	public static function resolve( int $artifactId = 0 ) {
		if ( $artifactId > 0 ) {
			$owner = (int) get_post_meta( $artifactId, HomeWorkspace::META_OWNER, true );
			if ( $owner <= 0 || ! self::isLive( $artifactId ) ) {
				return new WP_Error( 'ab_tioday_not_found', __( 'That artifact is not a TíoDay (a page pinned to a person).', 'agent-brain' ), [ 'status' => 404 ] );
			}

			return [ 'id' => $artifactId, 'owner' => $owner ];
		}

		$person = self::person();
		if ( $person > 0 ) {
			$id = self::forPerson( $person );
			if ( $id <= 0 ) {
				return new WP_Error(
					'ab_tioday_none',
					/* translators: %s: the person's name. */
					sprintf( __( '%s has no TíoDay on this brain. Nothing to record.', 'agent-brain' ), self::name( $person ) ),
					[ 'status' => 404 ]
				);
			}

			return [ 'id' => $id, 'owner' => $person ];
		}

		// A pure agent with nobody behind it: the TíoDay it leads.
		$agentId = (int) get_current_user_id();
		$led     = $agentId > 0 ? self::candidates( [ [ 'key' => Cpts::META_LEAD_AGENT, 'value' => (string) $agentId ], [ 'key' => HomeWorkspace::META_OWNER, 'compare' => 'EXISTS' ] ] ) : [];
		if ( $led === [] ) {
			return new WP_Error( 'ab_tioday_none', __( 'No person is behind this connection and this agent leads no TíoDay. Connect as the person, or pass the artifact_id of a TíoDay you lead.', 'agent-brain' ), [ 'status' => 404 ] );
		}

		if ( count( $led ) > 1 ) {
			$issuer = (int) get_user_meta( $agentId, AgentMeta::ISSUED_BY, true );
			$mine   = array_values( array_filter( $led, static fn ( \WP_Post $post ): bool => $issuer > 0 && (int) get_post_meta( (int) $post->ID, HomeWorkspace::META_OWNER, true ) === $issuer ) );
			if ( $mine === [] ) {
				return new WP_Error(
					'ab_tioday_ambiguous',
					/* translators: %s: comma-separated artifact ids. */
					sprintf( __( 'This agent leads several TíoDays (artifacts %s). Pass artifact_id to choose one.', 'agent-brain' ), implode( ', ', array_map( static fn ( \WP_Post $post ): string => (string) $post->ID, $led ) ) ),
					[ 'status' => 409 ]
				);
			}
			$led = $mine;
		}

		$id = self::pick( $led );

		return [ 'id' => $id, 'owner' => (int) get_post_meta( $id, HomeWorkspace::META_OWNER, true ) ];
	}

	/**
	 * Whether the current caller may use this TíoDay tool: the TíoDay is
	 * found, and the account the call runs as may manage the artifact (the
	 * artifact-store-set rule). A read is also open to the TíoDay's owner.
	 * The refusal says why (over MCP, tools/call shows it), so a tool never
	 * just fails; a caller who may not use MCP at all gets a bare false,
	 * like every other tool.
	 *
	 * @param mixed $input
	 * @return bool|WP_Error
	 */
	public static function check( $input, bool $write ) {
		$userId = (int) get_current_user_id();
		if ( $userId <= 0 || ! user_can( $userId, \AgentBrain\Modules\Identity\Capabilities::CAP_USE_MCP ) ) {
			return false;
		}

		$target = self::resolve( is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0 );
		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$actor = $write ? self::writerId() : $userId;
		if ( ShareLinks::canManage( $actor, $target['id'] ) ) {
			return true;
		}
		if ( ! $write && $target['owner'] === self::person() ) {
			return true;
		}

		$lead = (int) get_post_meta( $target['id'], Cpts::META_LEAD_AGENT, true );

		return new WP_Error(
			'ab_forbidden',
			sprintf(
				/* translators: 1: the owner's name, 2: the lead agent's name, 3: the caller's name. */
				__( 'Only %2$s (the lead of %1$s\'s TíoDay), a curator or a manager can change it, and you are %3$s. Act as %2$s to keep it.', 'agent-brain' ),
				self::name( $target['owner'] ),
				$lead > 0 ? self::name( $lead ) : __( 'its lead', 'agent-brain' ),
				self::name( $actor )
			),
			[ 'status' => 403 ]
		);
	}

	/**
	 * The lines the MCP initialize instructions and context-briefing add
	 * when the person behind this connection has a TíoDay this connection
	 * may keep; '' otherwise.
	 */
	public static function instructions(): string {
		$target = self::resolve();
		if ( $target instanceof WP_Error || self::check( [], true ) !== true ) {
			return '';
		}

		$name = self::name( $target['owner'] );

		return sprintf(
			/* translators: 1: the person's name, 2: the TíoDay's link. */
			__( 'TíoDay: %1$s has a TíoDay, a private page of what is theirs to do and what got done: %2$s. Keep it current as you work. When you finish work, record it with tomatio-tioday-done. When you start something long, add it with tomatio-tioday-inflight, and clear it when it lands. When you need %1$s (a decision, an approval, or a step only they can take), add a task with tomatio-tioday-task. Record their decisions with tomatio-tioday-decided. Read new comments with tomatio-tioday-get and answer the ones meant for you with tomatio-tioday-reply. If a turn changed nothing worth recording, skip it.', 'agent-brain' ),
			$name,
			self::url( $target['id'] )
		);
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
			return __( 'someone', 'agent-brain' );
		}

		$first = trim( (string) $user->first_name );

		return $first !== '' ? $first : (string) $user->display_name;
	}

	/**
	 * @param array<int, array<string, string>> $meta
	 * @return array<int, \WP_Post>
	 */
	private static function candidates( array $meta ): array {
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
			return self::invalid( __( 'date is a day as YYYY-MM-DD.', 'agent-brain' ) );
		}

		$sinceTs = $since !== null && trim( $since ) !== '' ? strtotime( $since ) : time() - self::COMMENT_DAYS * DAY_IN_SECONDS;
		if ( $sinceTs === false ) {
			return self::invalid( __( 'since is a time, such as 2026-10-29T09:00:00Z.', 'agent-brain' ) );
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

		$meta = StoreData::get( $artifactId, 'meta', 'page' );

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
		];
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
				return self::invalid( __( 'group is one of now, week, later or waiting.', 'agent-brain' ) );
			}
			$patch['group'] = $input['group'];
		}
		if ( array_key_exists( 'status', $input ) ) {
			if ( ! in_array( $input['status'], self::STATUSES, true ) ) {
				return self::invalid( __( 'status is one of open, waiting or done.', 'agent-brain' ) );
			}
			$patch['status'] = $input['status'];
		}
		if ( array_key_exists( 'order', $input ) ) {
			if ( ! is_numeric( $input['order'] ) ) {
				return self::invalid( __( 'order is a number.', 'agent-brain' ) );
			}
			$patch['order'] = (int) $input['order'];
		}
		if ( ! empty( $input['close'] ) ) {
			$patch['status'] = 'done';
		}
		if ( ( $patch['title'] ?? null ) === '' ) {
			unset( $patch['title'] ); // A title is never blanked.
		}
		if ( self::given( $input, 'slug' ) && $slug === null ) {
			return self::invalid( __( 'slug is lower-case letters, digits and hyphens, up to 64.', 'agent-brain' ) );
		}

		$doneBy = isset( $input['done_by'] ) ? self::text( $input['done_by'], self::MAX_NAME ) : '';
		$stored = self::busy();

		for ( $try = 0; $try < 3; $try++ ) {
			$existing = $slug !== null ? StoreData::get( $artifactId, 'tasks', $slug ) : null;

			if ( $existing === null ) {
				if ( ( $patch['title'] ?? '' ) === '' ) {
					return self::invalid( __( 'A new task needs a title.', 'agent-brain' ) );
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
	 * Done fields follow the status: done sets done_on (today, unless it was
	 * already done) and done_by ($doneBy when given, else the caller when
	 * the task is newly done); any other status clears them.
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
			if ( $doneBy !== '' ) {
				$value['done_by'] = $doneBy;
			} elseif ( ! $wasDone ) {
				$value['done_by'] = $caller;
			}

			return $value;
		}

		$value['done_on'] = null;
		unset( $value['done_by'] );

		return $value;
	}

	/**
	 * Record what got done under the next free key of the day, and clear
	 * the in-flight item it finishes.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function done( int $artifactId, array $input ) {
		$stream = self::text( $input['stream'] ?? '', self::MAX_NAME );
		$title  = self::text( $input['title'] ?? '', self::MAX_TITLE );
		if ( $stream === '' || $title === '' ) {
			return self::invalid( __( 'Done needs a stream (the work stream, such as a project) and a title.', 'agent-brain' ) );
		}
		$links = self::links( $input['links'] ?? [] );
		if ( $links instanceof WP_Error ) {
			return $links;
		}
		$date = isset( $input['date'] ) ? (string) $input['date'] : self::today();
		if ( ! self::isDate( $date ) ) {
			return self::invalid( __( 'date is a day as YYYY-MM-DD.', 'agent-brain' ) );
		}

		$clear = null;
		if ( isset( $input['clear_inflight'] ) && $input['clear_inflight'] !== '' ) {
			$clear = is_string( $input['clear_inflight'] ) ? StoreData::normalizeKey( $input['clear_inflight'] ) : null;
			if ( $clear === null ) {
				return self::invalid( __( 'clear_inflight is the key of an in-flight item.', 'agent-brain' ) );
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
		$slug = self::given( $input, 'slug' ) && is_string( $input['slug'] ) ? self::slug( $input['slug'] ) : null;
		$by   = self::by( $artifactId );

		if ( ! empty( $input['clear'] ) ) {
			$key = isset( $input['slug'] ) && is_string( $input['slug'] ) ? StoreData::normalizeKey( $input['slug'] ) : null;
			if ( $key === null ) {
				return self::invalid( __( 'Clearing needs the slug of the in-flight item.', 'agent-brain' ) );
			}
			$cleared = StoreData::delete( $artifactId, 'inflight', $key, null, $by );
			if ( $cleared ) {
				self::touch( $artifactId, $by );
			}

			return [ 'key' => $key, 'cleared' => $cleared ];
		}

		if ( self::given( $input, 'slug' ) && $slug === null ) {
			return self::invalid( __( 'slug is lower-case letters, digits and hyphens, up to 64.', 'agent-brain' ) );
		}

		$patch = [];
		foreach ( [ 'title' => self::MAX_TITLE, 'detail' => self::MAX_TEXT, 'who' => self::MAX_NAME ] as $field => $max ) {
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
				return self::invalid( __( 'order is a number.', 'agent-brain' ) );
			}
			$patch['order'] = (int) $input['order'];
		}
		if ( ( $patch['title'] ?? null ) === '' ) {
			unset( $patch['title'] ); // A title is never blanked.
		}

		$stored = self::busy();
		for ( $try = 0; $try < 3; $try++ ) {
			$existing = $slug !== null ? StoreData::get( $artifactId, 'inflight', $slug ) : null;
			if ( $existing === null ) {
				if ( ( $patch['title'] ?? '' ) === '' ) {
					return self::invalid( __( 'A new in-flight item needs a title.', 'agent-brain' ) );
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
			return self::invalid( __( 'A decision needs a title.', 'agent-brain' ) );
		}
		$date = isset( $input['date'] ) ? (string) $input['date'] : self::today();
		if ( ! self::isDate( $date ) ) {
			return self::invalid( __( 'date is a day as YYYY-MM-DD.', 'agent-brain' ) );
		}

		$by     = self::by( $artifactId );
		$byName = self::text( $input['by'] ?? '', self::MAX_NAME );
		$value  = [
			'title'  => $title,
			'detail' => self::text( $input['detail'] ?? '', self::MAX_TEXT, true ),
			'by'     => $byName !== '' ? $byName : self::name( $owner ),
			'date'   => $date,
		];

		$stored = self::allocate( $artifactId, 'decisions', $date, $value, $by );

		return $stored instanceof WP_Error ? $stored : self::landed( $artifactId, $stored, $by );
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
			return self::invalid( __( 'A reply needs text.', 'agent-brain' ) );
		}

		$on = null;
		if ( isset( $input['comment'] ) && is_string( $input['comment'] ) && $input['comment'] !== '' ) {
			$key      = StoreData::normalizeKey( $input['comment'] );
			$original = $key !== null ? StoreData::get( $artifactId, 'comments', $key ) : null;
			if ( $original === null || ! is_array( $original['value'] ) ) {
				return new WP_Error( 'ab_tioday_comment_not_found', __( 'No comment with that key on this TíoDay (tomatio-tioday-get lists them).', 'agent-brain' ), [ 'status' => 404 ] );
			}
			$on = (string) ( $original['value']['on'] ?? 'page' );
		} elseif ( isset( $input['on'] ) && is_string( $input['on'] ) ) {
			$on = $input['on'];
		}

		if ( $on === null || preg_match( '/^(page|[tdcf]:[A-Za-z0-9][A-Za-z0-9._ -]{0,127})$/', $on ) !== 1 ) {
			return self::invalid( __( 'Say which comment you answer (comment: its key), or what you comment on (on: "page", "t:<task>", "d:<done>", "c:<decision>" or "f:<in flight>").', 'agent-brain' ) );
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

		return new WP_Error( 'ab_tioday_day_full', __( 'That day already has 99 entries.', 'agent-brain' ), [ 'status' => 409 ] );
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

		return [
			'key'     => (string) $stored['key'],
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
			StoreData::set( $artifactId, 'meta', 'page', [ 'owner' => self::name( $owner ), 'owner_full' => $user instanceof \WP_User ? (string) $user->display_name : '' ] + $now, $by, null, 0 );

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
			return self::invalid( __( 'links is a list of {label, url}.', 'agent-brain' ) );
		}
		if ( count( $links ) > self::MAX_LINKS ) {
			/* translators: %d: most links an item may have. */
			return self::invalid( sprintf( __( 'At most %d links.', 'agent-brain' ), self::MAX_LINKS ) );
		}

		$out = [];
		foreach ( $links as $link ) {
			$url  = is_array( $link ) && is_string( $link['url'] ?? null ) ? trim( $link['url'] ) : '';
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			if ( ! str_starts_with( strtolower( $url ), 'https://' ) || $host === '' || strlen( $url ) > 2000 || esc_url_raw( $url, [ 'https' ] ) === '' ) {
				/* translators: %s: the link that was refused. */
				return self::invalid( sprintf( __( 'Links must be https URLs: %s is not.', 'agent-brain' ), $url !== '' ? $url : wp_json_encode( $link ) ) );
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
		return new WP_Error( 'ab_store_version_conflict', __( 'The TíoDay changed while this was being written. Try again.', 'agent-brain' ), [ 'status' => 409 ] );
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'ab_tioday_invalid', $message, [ 'status' => 400 ] );
	}
}
