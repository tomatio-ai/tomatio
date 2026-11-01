<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Forget;

use AgentBrain\Modules\Identity\AgentProposals;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Portability\Controller;
use AgentBrain\Modules\Portability\ExportStore;
use AgentBrain\Modules\Portability\PageIndex;
use AgentBrain\Modules\Portability\Principals;
use WP_Error;

/**
 * FGT-1/FGT-2/FGT-3 and PCP v0.3 SPEC 8.9: provable erasure across every
 * store this plugin knows about. Three kinds:
 *
 *  - subject: everything whose content matches a subject string, in every
 *    scope (Matcher), plus what other modules report through
 *    `agent_brain/forget_collect` and pending agent proposals;
 *  - pages: pages named by path or PCP id (memory rows, knowledge and
 *    procedure posts);
 *  - principal: what a former principal wrote, or had written on their
 *    behalf (memory rows by them or via them, posts they authored or
 *    proposed, artifacts and sections, their pending agent proposals).
 *
 * With include_derived (the default) every memory row derived from a
 * target, transitively, goes too. A dry run collects and returns counts
 * per store and touches nothing.
 *
 * Order of operations is load-bearing (SPEC 8.9.2):
 *  1. collect every target;
 *  2. write the erase.request audit entry SYNCHRONOUSLY via
 *     `agent_brain/audit_write`; anything other than `true` aborts with
 *     nothing deleted;
 *  3. record tombstones of the erased pages, delete owned stores, delegate
 *     external ones via `agent_brain/forget_delete`, delete every held
 *     export archive, then fire `agent_brain/forget_propagate`;
 *  4. check for residue the same way the targets were found, plus the
 *     remaining archives and the propagation outcomes collected via
 *     `agent_brain/forget_propagation_result`;
 *  5. write the receipt and the erase.complete audit entry.
 *
 * Nothing readable about the targets is stored: no subject, paths or
 * unkeyed hash. A subject receipt keeps only a keyed digest (DigestKey).
 */
final class Forgetter {

	public const KINDS = ['subject', 'pages', 'principal'];

	public const EVENT_REQUEST  = 'erase.request';
	public const EVENT_COMPLETE = 'erase.complete';

	/** The events earlier versions wrote; still read for receipts' audit ids. */
	public const LEGACY_EVENTS = ['forget.requested', 'forget.completed'];

	private const STORE_PROPOSALS = 'agent_proposals';

	public function __construct(
		private MemoryRepository $memory = new MemoryRepository(),
		private ExportStore $exports = new ExportStore()
	) {
	}

	/**
	 * Subject erasure, the original forget.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function forget( string $subject, int $curatorId ) {
		return $this->erase( [ 'kind' => 'subject', 'subject' => $subject ], $curatorId );
	}

	/**
	 * @param array<string, mixed> $request {kind, subject?, paths?, ids?, principal?, include_derived?, dry_run?}
	 * @return array<string, mixed>|WP_Error The receipt, or for a dry run {dry_run: true, would_erase: {store: count}}.
	 */
	public function erase( array $request, int $curatorId ) {
		if ( ! user_can( $curatorId, Capabilities::CAP_MANAGE ) ) {
			return new WP_Error( 'ab_forbidden', __( 'You do not have permission to run forget.', 'agent-brain' ) );
		}

		$target = self::target( $request );
		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$requestedAt = gmdate( 'Y-m-d H:i:s' );
		$targets     = $this->collect( $target );

		if ( ! empty( $request['dry_run'] ) ) {
			return [ 'dry_run' => true, 'kind' => $target['kind'], 'would_erase' => self::targetCounts( $targets ) ];
		}

		$receiptId   = wp_generate_uuid4();
		$targetCount = array_sum( self::targetCounts( $targets ) );

		// FGT-2: synchronous audit write BEFORE any deletion. A non-true
		// return (missing listener, validation failure, insert failure)
		// aborts the whole run: nothing is deleted.
		$audited = apply_filters(
			'agent_brain/audit_write',
			false,
			[
				'actor_id'    => $curatorId,
				'event'       => self::EVENT_REQUEST,
				'status'      => 'ok',
				'object_type' => 'ab_forget_receipt',
				'object_id'   => $receiptId,
				'meta'        => [ 'kind' => $target['kind'], 'target_count' => $targetCount ],
			]
		);

		if ( $audited !== true ) {
			return new WP_Error(
				'ab_audit_failed',
				__( 'Audit write failed; aborting forget without deleting anything.', 'agent-brain' )
			);
		}

		Tombstones::record( $receiptId, $this->tombstonePages( $targets ) );

		$counts = [
			'ab_memory' => self::deleteMemoryRows( $targets['memory_ids'] ),
			'posts'     => self::deletePosts( $targets['post_ids'] ),
		];

		foreach ( $targets['external'] as $store => $ids ) {
			do_action( 'agent_brain/forget_delete', $store, $ids );
			$counts[ $store ] = count( $ids );
		}

		if ( $targets['proposals'] !== [] ) {
			$counts[ self::STORE_PROPOSALS ] = AgentProposals::remove( $targets['proposals'] );
		}

		// privacy/F1 (PCP SPEC 8.9.2 item 3): a completed erasure invalidates
		// every held export archive made before it, since any may hold a target.
		$counts['exports'] = $this->exports->deleteAll();

		do_action( 'agent_brain/forget_propagate', $target['subject'] ?? '', $receiptId );

		$propagation = apply_filters( 'agent_brain/forget_propagation_result', [], $receiptId );

		$residue                = $this->residue( $target, $targets );
		$residue['propagation'] = $propagation;

		$receipt = [
			'id'              => $receiptId,
			'kind'            => $target['kind'],
			'subject_digest'  => $target['kind'] === 'subject' ? DigestKey::subjectDigest( (string) $target['subject'] ) : null,
			'digest_key_id'   => $target['kind'] === 'subject' ? DigestKey::id() : null,
			'include_derived' => $target['include_derived'],
			'requested_at'    => $requestedAt,
			'created'         => gmdate( 'Y-m-d H:i:s' ),
			'curator_id'      => $curatorId,
			'counts'          => $counts,
			'residue'         => $residue,
			'status'          => self::status( $residue, $propagation ),
		];

		ReceiptRepository::insert( $receipt );

		apply_filters(
			'agent_brain/audit_write',
			false,
			[
				'actor_id'    => $curatorId,
				'event'       => self::EVENT_COMPLETE,
				'status'      => $receipt['status'] === ReceiptRepository::STATUS_RESIDUE_FOUND ? 'error' : 'ok',
				'object_type' => 'ab_forget_receipt',
				'object_id'   => $receiptId,
				'meta'        => [ 'kind' => $target['kind'], 'counts' => $counts, 'residue' => $residue, 'deleted' => self::total( $counts ) ],
			]
		);

		return $receipt;
	}

	/**
	 * Checks and normalizes a request.
	 *
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>|WP_Error {kind, include_derived, and subject, pages ({kind, object_id} each) or principal and user_id}
	 */
	public static function target( array $request ) {
		$kind           = (string) ( $request['kind'] ?? 'subject' );
		$includeDerived = ! array_key_exists( 'include_derived', $request ) || (bool) $request['include_derived'];

		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return self::invalid( __( 'Unknown erasure kind. Use subject, pages or principal.', 'agent-brain' ) );
		}

		if ( $kind === 'subject' ) {
			$subject = trim( (string) ( $request['subject'] ?? '' ) );
			if ( $subject === '' ) {
				return new WP_Error( 'ab_invalid_subject', __( 'Subject must not be empty.', 'agent-brain' ) );
			}

			return [ 'kind' => 'subject', 'subject' => $subject, 'include_derived' => $includeDerived ];
		}

		if ( $kind === 'pages' ) {
			$names = array_merge(
				array_map( 'strval', (array) ( $request['paths'] ?? [] ) ),
				array_map( 'strval', (array) ( $request['ids'] ?? [] ) )
			);
			$names = array_values( array_unique( array_filter( array_map( 'trim', $names ), static fn ( string $n ): bool => $n !== '' ) ) );
			if ( $names === [] ) {
				return self::invalid( __( 'Name at least one page by path or id.', 'agent-brain' ) );
			}

			$found   = [];
			$missing = [];
			foreach ( $names as $name ) {
				$page = self::findPage( $name );
				if ( $page === null ) {
					$missing[] = $name;
				} else {
					$found[ $page['kind'] . ':' . $page['object_id'] ] = $page;
				}
			}

			if ( $missing !== [] ) {
				return new WP_Error(
					'ab_not_found',
					/* translators: %d: number of pages not found. */
					sprintf( _n( '%d named page does not exist; nothing was erased.', '%d named pages do not exist; nothing was erased.', count( $missing ), 'agent-brain' ), count( $missing ) ),
					[ 'status' => 404 ]
				);
			}

			return [ 'kind' => 'pages', 'pages' => array_values( $found ), 'include_derived' => $includeDerived ];
		}

		$principal = trim( (string) ( $request['principal'] ?? '' ) );
		$userId    = null;
		foreach ( Principals::all() as $row ) {
			if ( $row['principal_id'] === $principal && $row['user_id'] > 0 ) {
				$userId = $row['user_id'];
				break;
			}
		}

		if ( $userId === null ) {
			return new WP_Error( 'ab_not_found', __( 'No such principal on this brain.', 'agent-brain' ), [ 'status' => 404 ] );
		}
		if ( $principal === Controller::ensure() ) {
			return self::invalid( __( 'The controller cannot be erased as a principal.', 'agent-brain' ) );
		}

		return [ 'kind' => 'principal', 'principal' => $principal, 'user_id' => $userId, 'include_derived' => $includeDerived ];
	}

	/**
	 * Every target of a request.
	 *
	 * @param array<string, mixed> $target From target().
	 * @return array{memory_ids:int[], post_ids:int[], external:array<string, array<int, int|string>>, proposals:string[]}
	 */
	public function collect( array $target ): array {
		$memoryIds = [];
		$postIds   = [];
		$external  = [];
		$proposals = [];

		if ( $target['kind'] === 'subject' ) {
			$owned     = Matcher::collect( $target['subject'], $this->memory, $target['include_derived'] );
			$memoryIds = $owned['memory_ids'];
			$postIds   = $owned['post_ids'];
			$external  = self::groupByStore( apply_filters( 'agent_brain/forget_collect', [], $target['subject'] ) );
			$proposals = self::proposalsMatching( $target['subject'] );
		} elseif ( $target['kind'] === 'pages' ) {
			foreach ( $target['pages'] as $page ) {
				if ( $page['kind'] === PageIndex::KIND_MEMORY ) {
					$memoryIds[] = $page['object_id'];
				} else {
					$postIds[] = $page['object_id'];
				}
			}
			$postIds = array_merge( $postIds, Matcher::revisionsOf( $postIds ) );
		} else {
			$memoryIds = Matcher::memoryIdsBy( $target['user_id'] );
			$postIds   = Matcher::postIdsBy( $target['user_id'] );
			$proposals = self::proposalsBy( $target['user_id'] );
		}

		if ( $target['kind'] !== 'subject' && $target['include_derived'] ) {
			[ $memoryIds, $postIds ] = Matcher::withDerived( $memoryIds, $postIds, $this->memory );
		}

		return [
			'memory_ids' => array_values( array_unique( array_map( 'intval', $memoryIds ) ) ),
			'post_ids'   => array_values( array_unique( array_map( 'intval', $postIds ) ) ),
			'external'   => $external,
			'proposals'  => array_values( array_unique( $proposals ) ),
		];
	}

	/**
	 * Resolves a page by PCP id or path (with or without ".md").
	 *
	 * @return array{kind:string, object_id:int}|null
	 */
	public static function findPage( string $name ): ?array {
		$table = PageIndex::table();
		$path  = strtolower( (string) preg_replace( '/\.md$/', '', ltrim( $name, '/' ) ) );

		$rows = \AgentBrain\Core\Db::getResults(
			"SELECT object_kind, object_id FROM {$table} WHERE pcp_id = %s OR path_lower = %s LIMIT 1",
			[ $name, $path ]
		);

		if ( ! isset( $rows[0] ) ) {
			return null;
		}

		return [ 'kind' => (string) $rows[0]['object_kind'], 'object_id' => (int) $rows[0]['object_id'] ];
	}

	/**
	 * @param array{memory_ids:int[], post_ids:int[], external:array<string, array<int, int|string>>, proposals:string[]} $targets
	 * @return array<string, int>
	 */
	private static function targetCounts( array $targets ): array {
		$counts = [
			'ab_memory'           => count( $targets['memory_ids'] ),
			'posts'               => count( $targets['post_ids'] ),
			self::STORE_PROPOSALS => count( $targets['proposals'] ),
		];

		foreach ( $targets['external'] as $store => $ids ) {
			$counts[ $store ] = count( $ids );
		}

		return $counts;
	}

	/**
	 * The ids, paths and bodies of the pages about to be erased, for the
	 * tombstones. Read before anything is deleted.
	 *
	 * @param array{memory_ids:int[], post_ids:int[]} $targets
	 * @return array<int, array{id: ?string, path: ?string, body: ?string}>
	 */
	private function tombstonePages( array $targets ): array {
		$pages = [];

		foreach ( array_chunk( $targets['memory_ids'], 500 ) as $chunk ) {
			$in   = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = \AgentBrain\Core\Db::getResults(
				'SELECT m.content, i.pcp_id, i.path FROM ' . MemoryRepository::memoryTable() . ' m LEFT JOIN ' . PageIndex::table()
					. " i ON i.object_kind = 'memory' AND i.object_id = m.id WHERE m.id IN ({$in})",
				$chunk
			);
			foreach ( $rows as $row ) {
				$pages[] = [ 'id' => $row['pcp_id'] ?? null, 'path' => $row['path'] ?? null, 'body' => (string) $row['content'] ];
			}
		}

		foreach ( $targets['post_ids'] as $postId ) {
			$post = get_post( $postId );
			if ( ! $post instanceof \WP_Post || ! isset( PageIndex::POST_KINDS[ $post->post_type ] ) ) {
				continue;
			}

			$index   = PageIndex::get( PageIndex::POST_KINDS[ $post->post_type ][0], $postId );
			$pages[] = [ 'id' => $index['pcp_id'] ?? null, 'path' => $index['path'] ?? null, 'body' => (string) $post->post_content ];
		}

		return $pages;
	}

	/**
	 * Residue: the same collection run again, per store, and the archives
	 * still held.
	 *
	 * @param array<string, mixed>                                                                                          $target
	 * @param array{memory_ids:int[], post_ids:int[], external:array<string, array<int, int|string>>, proposals:string[]} $targets
	 * @return array<string, mixed>
	 */
	private function residue( array $target, array $targets ): array {
		if ( $target['kind'] === 'subject' ) {
			$residue = Matcher::residueCounts( $target['subject'] );

			foreach ( self::groupByStore( apply_filters( 'agent_brain/forget_collect', [], $target['subject'] ) ) as $store => $ids ) {
				$residue[ $store ] = count( $ids );
			}
			foreach ( array_keys( $targets['external'] ) as $store ) {
				$residue[ $store ] = $residue[ $store ] ?? 0;
			}

			$residue[ self::STORE_PROPOSALS ] = count( self::proposalsMatching( $target['subject'] ) );
			$residue['exports']               = $this->exports->residueCount( $target['subject'] );

			return $residue;
		}

		if ( $target['kind'] === 'principal' ) {
			// Anything by the principal now is residue, whether or not it was a target.
			$again = $this->collect( $target );

			return [
				'memory'              => count( $again['memory_ids'] ),
				'posts'               => count( $again['post_ids'] ),
				self::STORE_PROPOSALS => count( $again['proposals'] ),
				'exports'             => count( $this->exports->all() ),
			];
		}

		return [
			'memory'  => count( Matcher::existingMemoryIds( $targets['memory_ids'] ) ),
			'posts'   => count( Matcher::existingPostIds( $targets['post_ids'] ) ),
			'exports' => count( $this->exports->all() ),
		];
	}

	/**
	 * complete, pending_propagation (no residue, a store deletes later) or
	 * residue_found (residue, or a store failed).
	 *
	 * @param array<string, mixed> $residue
	 * @param mixed                $propagation
	 */
	private static function status( array $residue, $propagation ): string {
		foreach ( $residue as $store => $count ) {
			if ( $store !== 'propagation' && is_int( $count ) && $count > 0 ) {
				return ReceiptRepository::STATUS_RESIDUE_FOUND;
			}
		}

		if ( self::hasPropagationFailure( $propagation ) ) {
			return ReceiptRepository::STATUS_RESIDUE_FOUND;
		}

		if ( is_array( $propagation ) ) {
			foreach ( $propagation as $entry ) {
				if ( is_array( $entry ) && ! empty( $entry['pending'] ) ) {
					return ReceiptRepository::STATUS_PENDING;
				}
			}
		}

		return ReceiptRepository::STATUS_COMPLETE;
	}

	/**
	 * @param array<string, int> $counts
	 */
	private static function total( array $counts ): int {
		return array_sum( array_filter( $counts, 'is_int' ) );
	}

	/**
	 * Pending agent proposals whose name or role contains the subject
	 * (case-insensitive phrase).
	 *
	 * @return array<int, string>
	 */
	private static function proposalsMatching( string $subject ): array {
		$needle = mb_strtolower( $subject );
		$ids    = [];

		foreach ( AgentProposals::pending() as $id => $proposal ) {
			if ( mb_strpos( mb_strtolower( $proposal['name'] . "\n" . $proposal['role'] ), $needle ) !== false ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Pending agent proposals made by the user, or by one of their agents for them.
	 *
	 * @return array<int, string>
	 */
	private static function proposalsBy( int $userId ): array {
		$ids = [];

		foreach ( AgentProposals::pending() as $id => $proposal ) {
			if ( $proposal['person_id'] === $userId || $proposal['acting_id'] === $userId ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param mixed $targets Whatever the `agent_brain/forget_collect` filter returned.
	 * @return array<string, array<int, int|string>>
	 */
	private static function groupByStore( $targets ): array {
		$byStore = [];

		if ( ! is_array( $targets ) ) {
			return $byStore;
		}

		foreach ( $targets as $target ) {
			if ( ! is_array( $target ) || ! isset( $target['store'], $target['id'] ) ) {
				continue;
			}

			$byStore[ (string) $target['store'] ][] = $target['id'];
		}

		foreach ( $byStore as $store => $ids ) {
			$byStore[ $store ] = array_values( array_unique( $ids ) );
		}

		return $byStore;
	}

	/**
	 * @param int[] $ids
	 */
	private static function deleteMemoryRows( array $ids ): int {
		if ( $ids === [] ) {
			return 0;
		}

		global $wpdb;

		$memoryTable = MemoryRepository::memoryTable();
		$linksTable  = MemoryRepository::linksTable();
		$deleted     = 0;

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Db::prepare() is the enforcement point.
			$wpdb->query( \AgentBrain\Core\Db::prepare(
				"DELETE FROM {$linksTable} WHERE ( derived_kind = %s AND derived_id IN ({$placeholders}) ) OR source_id IN ({$placeholders})",
				array_merge( [ 'memory' ], $chunk, $chunk )
			) );

			$wpdb->query( \AgentBrain\Core\Db::prepare(
				"DELETE FROM {$memoryTable} WHERE id IN ({$placeholders})",
				$chunk
			) );
			// phpcs:enable

			$deleted += (int) $wpdb->rows_affected;
			PageIndex::removeMemory( $chunk );
		}

		return $deleted;
	}

	/**
	 * Whether any propagation outcome collected via
	 * `agent_brain/forget_propagation_result` reports a failure (e.g.
	 * `{ "success": false, "reason": "backup_delete_failed" }`, or any
	 * entry carrying a non-empty `reason` with no explicit `success` key).
	 * Fail closed: a malformed/unrecognised entry shape is not treated as
	 * success.
	 *
	 * @param mixed $propagation Whatever the filter returned.
	 */
	private static function hasPropagationFailure( $propagation ): bool {
		if ( ! is_array( $propagation ) ) {
			return false;
		}

		foreach ( $propagation as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( array_key_exists( 'success', $entry ) ) {
				if ( $entry['success'] === false ) {
					return true;
				}

				continue;
			}

			if ( isset( $entry['reason'] ) && $entry['reason'] !== '' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int[] $ids
	 */
	private static function deletePosts( array $ids ): int {
		$deleted = 0;

		foreach ( $ids as $id ) {
			if ( get_post( $id ) === null ) {
				continue; // A revision already removed with its parent.
			}
			if ( wp_delete_post( $id, true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'ab_invalid_input', $message, [ 'status' => 400 ] );
	}
}
