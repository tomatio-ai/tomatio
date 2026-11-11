<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\Artifacts\ArtifactIndex;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\DataStore\StoreData;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\Extensions\Package;
use AgentBrain\Modules\Extensions\PageMaker;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Sharing\MembersAddress;
use AgentBrain\Modules\Sharing\ShareLinks;
use WP_Error;

/**
 * #891 (note 643, step 1): a team's backlog. It is an HTML page made from
 * the bundled package extensions/backlog (`tomatio.backlog`) in the
 * project's scope, and everything lives in its page store:
 *
 *  - items/<slug>:   one backlog item (Item::FIELDS), with its B short ID
 *  - comments/<key>: {on: B12, text, by, at, to?, reply_to?}
 *  - log/<time>-<r>: {at, by, for?, on, what, from, to, reason?}, append only
 *  - meta/project:   {name, scope_id, now_cut, next_cut, members, agents, people, updated_at, updated_by}
 *  - meta/ids:       the short-ID counter (TioDayIds)
 *  - integrations/<source>: one document per source a team turns on (step 2)
 *  - seen/<login>:   {log: last log key seen, at}, per person and agent
 *
 * Built in core but shaped like an extension (C184): the schemas and the
 * page are the package's, this module only reads and writes them, and it
 * moves to the extension contract with v2.0 with the data unchanged.
 *
 * Who may use it: reading needs read on the artifact (its scope); writing
 * needs that and either manage rights (lead, curator, manager) or write
 * on its scope. What a writer may change is Project's business.
 */
final class Backlog {

	public const PACKAGE      = 'backlog';
	public const EXTENSION_ID = 'tomatio.backlog';

	/** The B short IDs of items (TioDayIds::OTHER_LETTERS). */
	public const LETTERS = [ 'items' => 'B' ];

	/** Collections the backlog outgrows the store's default caps in. */
	public const CAPS = [
		'items'    => [ 'docs' => 3000, 'bytes' => 12582912 ],
		'log'      => [ 'docs' => 50000, 'bytes' => 33554432 ],
		'comments' => [ 'docs' => 10000, 'bytes' => 12582912 ],
	];

	public static function register(): void {
		add_filter( 'tomatio/short_id_letters', [ self::class, 'letters' ], 10, 2 );
		add_filter( 'tomatio/store_caps', [ self::class, 'caps' ], 10, 3 );
	}

	/**
	 * tomatio/short_id_letters: a backlog's items are numbered B1, B2, …
	 *
	 * @param mixed $letters
	 * @return mixed
	 */
	public static function letters( $letters, int $artifactId ) {
		return self::isBacklog( $artifactId ) ? self::LETTERS : $letters;
	}

	/**
	 * tomatio/store_caps: larger caps for a backlog's items, log and comments.
	 *
	 * @param mixed $caps
	 * @return mixed
	 */
	public static function caps( $caps, int $artifactId, string $collection ) {
		return isset( self::CAPS[ $collection ] ) && self::isBacklog( $artifactId ) ? self::CAPS[ $collection ] : $caps;
	}

	public static function isBacklog( int $artifactId ): bool {
		return $artifactId > 0 && (string) get_post_meta( $artifactId, PageMaker::META_FROM, true ) === self::EXTENSION_ID;
	}

	/**
	 * Every live backlog on this brain, newest first.
	 *
	 * @return array<int, int>
	 */
	public static function all(): array {
		$ids = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => [ 'publish', 'private', 'draft', 'pending' ],
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_key'       => PageMaker::META_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one exact lookup over a handful of pages.
				'meta_value'     => self::EXTENSION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one exact lookup over a handful of pages.
			]
		);

		return array_values( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => ! ArtifactIndex::isArchived( $id ) ) );
	}

	/**
	 * Make a backlog in $scopeId, led by $leadId, from the bundled package.
	 * $slug becomes its members address (/a/<slug>) when it is free.
	 *
	 * @return int|WP_Error
	 */
	public static function make( string $title, int $leadId, int $scopeId, string $slug = '' ) {
		$package = Package::bundled( self::PACKAGE );
		if ( is_string( $package ) ) {
			return new WP_Error( 'ab_backlog_package', 'The bundled backlog package cannot be used: ' . $package, [ 'status' => 500 ] );
		}
		if ( ! ( new Scopes() )->canWrite( $leadId, $scopeId ) ) {
			return new WP_Error( 'ab_backlog_scope', __( 'The lead agent cannot write to that scope.', 'tomatio' ), [ 'status' => 409 ] );
		}

		$id = PageMaker::make( $package, $title !== '' ? $title : $package->name(), $leadId, $scopeId );
		if ( $id instanceof WP_Error ) {
			return $id;
		}

		$slug = sanitize_title( $slug );
		if ( $slug !== '' && preg_match( '/^[a-z][a-z0-9-]*$/', $slug ) === 1 && MembersAddress::resolve( $slug )['artifact_id'] === 0 ) {
			wp_update_post( [ 'ID' => $id, 'post_name' => $slug ] );
		}

		StoreData::set(
			$id,
			'meta',
			'project',
			Project::newProject( $title !== '' ? $title : $package->name(), $scopeId ),
			StoreData::attributionFromUser( $leadId, $id )
		);

		\AgentBrain\Core\Hooks::action(
			'audit',
			[
				'actor_id'    => (int) get_current_user_id(),
				'event'       => 'backlog.made',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $id,
				'meta'        => [ 'scope' => $scopeId, 'lead_agent' => $leadId ],
			]
		);

		return $id;
	}

	/** The scope a backlog lives in. */
	public static function scopeOf( int $artifactId ): int {
		return (int) get_post_meta( $artifactId, Cpts::META_SCOPE_ID, true );
	}

	/**
	 * Which backlog a call is about: $artifactId when given (it must be a
	 * backlog the caller may read), else the only backlog the caller may
	 * read.
	 *
	 * @return int|WP_Error
	 */
	public static function resolve( int $artifactId = 0, ?int $actorId = null ) {
		$actorId = $actorId ?? (int) get_current_user_id();
		if ( $artifactId > 0 ) {
			if ( ! self::isBacklog( $artifactId ) || ArtifactIndex::isArchived( $artifactId ) ) {
				return new WP_Error( 'ab_backlog_not_found', __( 'That artifact is not a backlog.', 'tomatio' ), [ 'status' => 404 ] );
			}
			if ( ! self::canRead( $actorId, $artifactId ) ) {
				return new WP_Error( 'ab_backlog_not_found', __( 'That artifact is not a backlog you can read.', 'tomatio' ), [ 'status' => 404 ] );
			}

			return $artifactId;
		}

		$mine = array_values( array_filter( self::all(), static fn ( int $id ): bool => self::canRead( $actorId, $id ) ) );
		if ( $mine === [] ) {
			return new WP_Error( 'ab_backlog_none', __( 'There is no backlog you can read on this brain. A curator makes one with `wp tomatio backlog create`.', 'tomatio' ), [ 'status' => 404 ] );
		}
		if ( count( $mine ) > 1 ) {
			return new WP_Error(
				'ab_backlog_ambiguous',
				/* translators: %s: comma-separated artifact ids. */
				sprintf( __( 'You can read several backlogs (artifacts %s). Pass artifact_id to choose one.', 'tomatio' ), implode( ', ', $mine ) ),
				[ 'status' => 409 ]
			);
		}

		return $mine[0];
	}

	/**
	 * Read follows the backlog's scope, as for memories: read on that
	 * scope, or managing the page (curator, lead, manager). Not the
	 * artifact's authoring audience (read_post), which also admits whoever
	 * owns one of its sections.
	 */
	public static function canRead( int $actorId, int $artifactId ): bool {
		if ( $actorId <= 0 ) {
			return false;
		}

		return ShareLinks::canManage( $actorId, $artifactId ) || ( new Scopes() )->canRead( $actorId, self::scopeOf( $artifactId ) );
	}

	public static function canWrite( int $actorId, int $artifactId ): bool {
		if ( ! self::canRead( $actorId, $artifactId ) ) {
			return false;
		}

		return ShareLinks::canManage( $actorId, $artifactId ) || ( new Scopes() )->canWrite( $actorId, self::scopeOf( $artifactId ) );
	}

	/**
	 * The permission rule of the project tools: the backlog is found and the
	 * account the call runs as may read it (and, for a write, write it). The
	 * refusal says why; a caller who may not use MCP at all gets false.
	 *
	 * @param mixed $input
	 * @return bool|WP_Error
	 */
	public static function check( $input, bool $write ) {
		$userId = (int) get_current_user_id();
		if ( $userId <= 0 || ! user_can( $userId, Capabilities::CAP_USE_MCP ) ) {
			return false;
		}

		$actor  = $write ? TioDay::writerId() : $userId;
		$target = self::resolve( is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0, $actor );
		if ( $target instanceof WP_Error ) {
			return $target;
		}
		if ( ! $write || self::canWrite( $actor, $target ) ) {
			return true;
		}

		return new WP_Error( 'ab_forbidden', __( 'You can read this backlog but not change it: that needs write on its scope, or being its lead, a curator or a manager.', 'tomatio' ), [ 'status' => 403 ] );
	}
}
