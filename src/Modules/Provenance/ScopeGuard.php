<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Provenance;

use AgentBrain\Core\ActionContext;
use AgentBrain\Core\Db;
use AgentBrain\Core\Hooks;
use AgentBrain\Modules\Admin\ScopeVisibility;
use AgentBrain\Modules\Artifacts\Cpts as ArtifactCpts;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Memory\MemoryRepository;

/**
 * Restricted sources stay restricted (derived-data guard).
 *
 * The scope permission on a source does not stop its content from leaving:
 * an agent that may read a restricted scope can write what it read into a
 * scope more people can read: a summary of a leadership-only plan, written
 * into the whole team's scope. This guard closes that route for agent writes.
 *
 * At write time it gathers the scopes of everything the agent built on:
 *  - every item its MCP session has read so far (ActionContext::sessionReads(),
 *    which unlike the per-write read set is NOT cleared by a write, so an
 *    unrelated write in between does not launder a restricted read), and
 *  - every brain item the write cites.
 *
 * A source scope S widens the target scope T when someone who can read T
 * cannot read S. Curators and administrators see every scope, so they never
 * make a difference either way; only scope grants (ab_read_scopes) count.
 *
 * The site setting OPTION picks what happens to such a write:
 *  - 'review' (default): it is stored but held for a curator (memory rows get
 *    status `proposed`, so no agent reads it until a curator keeps it);
 *  - 'block': it is refused, and the agent is told which scopes it may use;
 *  - 'off': no check.
 *
 * Where it runs (#845):
 *  - memory-write: a held row is stored with status `proposed`; the
 *    compression runner only reads `active` rows, so nothing derived from a
 *    held row (facts, episodes, the knowledge proposals ProposalService
 *    makes from facts) exists until a curator keeps it;
 *  - section-update: a held draft carries META_HOLD; it cannot be published
 *    in one step and only a curator may approve it (which clears the hold),
 *    so an assembled artifact, and the knowledge proposal made from it, is
 *    never built on an uncleared hold;
 *  - comment-convert to a review note (a knowledge proposal): the proposal
 *    carries META_HOLD, shows the reason in the Review inbox and is never
 *    auto-approved (Review\AutoApprove).
 *
 * Identifiers only: the guard never reads or copies content. When action
 * context is switched off for the site (ActionContext::enabled()), the guard
 * still checks cited items but cannot see reads.
 */
final class ScopeGuard {

	public const OPTION = 'ab_scope_guard';

	/** Filter name (fired as tomatio/scope_guard_mode through Core\Hooks). */
	public const FILTER_MODE = 'scope_guard_mode';

	/** Post meta on a held section or knowledge proposal: the source scope ids. */
	public const META_HOLD = 'ab_scope_hold';

	public const MODES = [ 'review', 'block', 'off' ];

	/** Option holding why each held memory row was held: memory id => source scope ids. */
	public const HOLDS_OPTION = 'ab_scope_holds';

	public const EVENT_HELD    = 'provenance.scope_hold';
	public const EVENT_BLOCKED = 'provenance.scope_block';

	/** Cap on remembered hold reasons; the oldest are dropped first. */
	private const MAX_HOLDS = 500;

	/** @var array<int, int[]>|null scope id => reader user ids, per request. */
	private static ?array $readers = null;

	public static function mode(): string {
		$mode = 'review';
		if ( function_exists( 'get_option' ) ) {
			$mode = (string) get_option( self::OPTION, 'review' );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$mode = (string) Hooks::filter( self::FILTER_MODE, $mode );
		}

		return in_array( $mode, self::MODES, true ) ? $mode : 'review';
	}

	/**
	 * The source scopes that would widen if content built on them were
	 * written into $targetScopeId. [] when the write is fine (or the guard is
	 * off).
	 *
	 * @param array<int, array{kind:string, id:int, version?:int|null}> $cites
	 * @return int[]
	 */
	public static function widenedSources( int $targetScopeId, array $cites = [] ): array {
		if ( self::mode() === 'off' || $targetScopeId <= 0 ) {
			return [];
		}

		$items = array_merge( ActionContext::sessionReads(), $cites );

		return self::widening( $targetScopeId, self::scopesOf( $items ), self::readerMap() );
	}

	/**
	 * Pure core of the check, kept free of WordPress for unit tests: the
	 * source scopes whose readers do not include every reader of the target.
	 *
	 * @param int[]              $sourceScopeIds
	 * @param array<int, int[]>  $readers scope id => reader user ids
	 * @return int[]
	 */
	public static function widening( int $targetScopeId, array $sourceScopeIds, array $readers ): array {
		$target = array_flip( $readers[ $targetScopeId ] ?? [] );
		$out    = [];

		foreach ( array_unique( array_map( 'intval', $sourceScopeIds ) ) as $sourceId ) {
			if ( $sourceId <= 0 || $sourceId === $targetScopeId ) {
				continue;
			}
			$source = array_flip( $readers[ $sourceId ] ?? [] );
			foreach ( $target as $userId => $_ ) {
				if ( ! isset( $source[ $userId ] ) ) {
					$out[] = $sourceId;
					break;
				}
			}
		}

		sort( $out );

		return $out;
	}

	/**
	 * The scope ids of the given brain items. Unknown or scope-less items are
	 * skipped: they cannot be more restricted than the brain itself.
	 *
	 * @param array<int, array{kind:string, id:int}> $items
	 * @return int[]
	 */
	public static function scopesOf( array $items ): array {
		$memoryIds = [];
		$postIds   = [];

		foreach ( $items as $item ) {
			$kind = (string) $item['kind'];
			$id   = (int) $item['id'];
			if ( $id <= 0 ) {
				continue;
			}
			if ( $kind === 'memory' ) {
				$memoryIds[ $id ] = $id;
			} elseif ( in_array( $kind, [ 'knowledge', 'procedure', 'section', 'artifact' ], true ) ) {
				$postIds[ $id ] = $kind;
			}
		}

		$scopes = [];

		if ( $memoryIds !== [] ) {
			$ids  = array_values( $memoryIds );
			$rows = Db::getResults(
				'SELECT DISTINCT scope_id FROM ' . MemoryRepository::memoryTable()
				. ' WHERE id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				$ids
			);
			foreach ( $rows as $row ) {
				$scopes[] = (int) $row['scope_id'];
			}
		}

		foreach ( $postIds as $id => $kind ) {
			if ( $kind === 'knowledge' || $kind === 'procedure' ) {
				$terms = function_exists( 'wp_get_post_terms' ) ? wp_get_post_terms( $id, Scopes::TAXONOMY, [ 'fields' => 'ids' ] ) : [];
				if ( is_array( $terms ) ) {
					foreach ( $terms as $termId ) {
						$scopes[] = (int) $termId;
					}
				}
				continue;
			}

			$artifactId = $kind === 'section' && function_exists( 'wp_get_post_parent_id' ) ? (int) wp_get_post_parent_id( $id ) : $id;
			$scopeId    = $artifactId > 0 && function_exists( 'get_post_meta' ) ? (int) get_post_meta( $artifactId, ArtifactCpts::META_SCOPE_ID, true ) : 0;
			if ( $scopeId > 0 ) {
				$scopes[] = $scopeId;
			}
		}

		return array_values( array_unique( array_filter( $scopes, static fn ( int $s ): bool => $s > 0 ) ) );
	}

	/**
	 * Every scope's readers from scope grants (ab_read_scopes user meta), read
	 * once per request.
	 *
	 * @return array<int, int[]>
	 */
	public static function readerMap(): array {
		if ( self::$readers !== null ) {
			return self::$readers;
		}

		global $wpdb;
		self::$readers = [];
		if ( ! isset( $wpdb->usermeta ) ) {
			return self::$readers;
		}

		$rows = Db::getResults(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
			[ Scopes::META_READ_SCOPES ]
		);

		foreach ( $rows as $row ) {
			$value = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $row['meta_value'] ) : null;
			if ( ! is_array( $value ) ) {
				continue;
			}
			foreach ( $value as $scopeId ) {
				if ( is_int( $scopeId ) || ( is_string( $scopeId ) && ctype_digit( $scopeId ) ) ) {
					self::$readers[ (int) $scopeId ][] = (int) $row['user_id'];
				}
			}
		}

		return self::$readers;
	}

	/**
	 * Scope names for messages, "Name (id)". Only called with scopes the agent read itself.
	 *
	 * @param int[] $scopeIds
	 */
	public static function labels( array $scopeIds ): string {
		$names = [];
		foreach ( $scopeIds as $id ) {
			$term    = function_exists( 'get_term' ) ? get_term( (int) $id, Scopes::TAXONOMY ) : null;
			$names[] = $term instanceof \WP_Term ? sprintf( '%s (scope_id %d)', $term->name, (int) $id ) : sprintf( 'scope_id %d', (int) $id );
		}

		return implode( ', ', $names );
	}

	/**
	 * Remembers why a memory row was held, for the Review inbox.
	 *
	 * @param int[] $sourceScopeIds
	 */
	public static function rememberHold( int $memoryId, array $sourceScopeIds ): void {
		if ( $memoryId <= 0 || ! function_exists( 'get_option' ) ) {
			return;
		}

		$holds = get_option( self::HOLDS_OPTION, [] );
		$holds = is_array( $holds ) ? $holds : [];

		$holds[ $memoryId ] = array_values( array_map( 'intval', $sourceScopeIds ) );
		if ( count( $holds ) > self::MAX_HOLDS ) {
			$holds = array_slice( $holds, - self::MAX_HOLDS, null, true );
		}

		update_option( self::HOLDS_OPTION, $holds, false );
	}

	/** @return int[] the source scopes a held memory row was built from, [] if it was not held. */
	public static function holdFor( int $memoryId ): array {
		$holds = function_exists( 'get_option' ) ? get_option( self::HOLDS_OPTION, [] ) : [];

		return is_array( $holds ) && isset( $holds[ $memoryId ] ) && is_array( $holds[ $memoryId ] )
			? array_values( array_map( 'intval', $holds[ $memoryId ] ) )
			: [];
	}

	public static function forgetHold( int $memoryId ): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		$holds = get_option( self::HOLDS_OPTION, [] );
		if ( is_array( $holds ) && isset( $holds[ $memoryId ] ) ) {
			unset( $holds[ $memoryId ] );
			update_option( self::HOLDS_OPTION, $holds, false );
		}
	}

	/**
	 * Names a curator may see: scope names are not secret to someone who reviews this scope.
	 *
	 * @param int[] $scopeIds
	 */
	public static function curatorLabels( int $curatorId, array $scopeIds ): string {
		$names = [];
		foreach ( $scopeIds as $id ) {
			if ( ! ScopeVisibility::canSeeScope( $curatorId, (int) $id ) ) {
				$names[] = __( 'a scope you cannot see', 'tomatio' );
				continue;
			}
			$term    = function_exists( 'get_term' ) ? get_term( (int) $id, Scopes::TAXONOMY ) : null;
			$names[] = $term instanceof \WP_Term ? $term->name : '#' . (int) $id;
		}

		return implode( ', ', array_unique( $names ) );
	}

	/** The scope a section is written into: its artifact's scope, 0 if none. */
	public static function sectionScope( int $sectionId ): int {
		if ( $sectionId <= 0 || ! function_exists( 'wp_get_post_parent_id' ) ) {
			return 0;
		}

		$artifactId = (int) wp_get_post_parent_id( $sectionId );

		return $artifactId > 0 ? (int) get_post_meta( $artifactId, ArtifactCpts::META_SCOPE_ID, true ) : 0;
	}

	/**
	 * Marks a section or knowledge proposal as held, adding to any earlier hold.
	 *
	 * @param int[] $sourceScopeIds
	 */
	public static function holdPost( int $postId, array $sourceScopeIds ): void {
		if ( $postId <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$merged = array_values( array_unique( array_merge( self::postHold( $postId ), array_map( 'intval', $sourceScopeIds ) ) ) );
		sort( $merged );
		update_post_meta( $postId, self::META_HOLD, $merged );
	}

	/** @return int[] the source scopes a post is held for, [] if it is not held. */
	public static function postHold( int $postId ): array {
		if ( $postId <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return [];
		}

		$hold = get_post_meta( $postId, self::META_HOLD, true );

		return is_array( $hold ) ? array_values( array_filter( array_map( 'intval', $hold ) ) ) : [];
	}

	public static function clearPostHold( int $postId ): void {
		if ( $postId > 0 && function_exists( 'delete_post_meta' ) ) {
			delete_post_meta( $postId, self::META_HOLD );
		}
	}

	/**
	 * Audits a hold or a refusal (identifiers only).
	 *
	 * @param int[] $sourceScopeIds
	 */
	public static function audit( int $actorId, string $event, string $objectType, string $objectId, int $scopeId, array $sourceScopeIds ): void {
		Hooks::action( 'audit', [
			'actor_id'    => $actorId,
			'event'       => $event,
			'status'      => $event === self::EVENT_BLOCKED ? 'denied' : 'ok',
			'object_type' => $objectType,
			'object_id'   => $objectId,
			'meta'        => [ 'scope_id' => $scopeId, 'source_scopes' => array_values( $sourceScopeIds ) ],
		] );
	}

	/**
	 * The refusal an agent gets in block mode.
	 *
	 * @param int[] $sourceScopeIds
	 */
	public static function refusal( array $sourceScopeIds, int $targetScopeId ): \WP_Error {
		return new \WP_Error( 'ab_scope_widening', sprintf(
			/* translators: 1: source scope names, 2: target scope id */
			__( 'Not saved: this session read %1$s, which fewer people can read than scope_id %2$d. Write it to one of those scopes instead, or ask a curator.', 'tomatio' ),
			self::labels( $sourceScopeIds ),
			$targetScopeId
		), [ 'status' => 403 ] );
	}

	/**
	 * The note an agent gets when its write is held.
	 *
	 * @param int[] $sourceScopeIds
	 */
	public static function heldNote( array $sourceScopeIds ): string {
		return sprintf(
			/* translators: %s: source scope names */
			__( 'Saved but held for a curator: this session read %s, which fewer people can read than this scope. Only a curator can approve it.', 'tomatio' ),
			self::labels( $sourceScopeIds )
		);
	}

	/** Tests only. */
	public static function reset(): void {
		self::$readers = null;
	}
}
