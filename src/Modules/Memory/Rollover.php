<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Memory;

/**
 * MEM-4: hourly cron that rolls expired working memory into an episode per
 * (agent_id, scope_id) group, links the episode to its sources (MEM-3), and
 * marks the sources rolled_up. Idempotent: a second run with nothing newly
 * expired creates nothing, because rolled_up rows never reappear in
 * MemoryRepository::maintenanceExpiredWorking().
 */
class Rollover {

	public const CRON_HOOK = 'agent_brain_memory_rollover';

	/** Hard cap so a runaway group can never produce an unbounded digest. */
	private const DIGEST_MAX_CHARS = 4000;
	private const DIGEST_MAX_ROWS  = 200;

	/** Per-note cap, in characters, when several notes share one episode. */
	private const NOTE_MAX_CHARS = 200;

	public static function run(): void {
		( new self() )->rollover( new MemoryRepository() );
	}

	public function rollover( MemoryRepository $repository ): void {
		global $wpdb;

		$expired = $repository->maintenanceExpiredWorking();
		if ( $expired === [] ) {
			return;
		}

		$groups = [];
		foreach ( $expired as $row ) {
			$key = ( (int) $row['agent_id'] ) . ':' . ( (int) $row['scope_id'] );
			$groups[ $key ][] = $row;
		}

		foreach ( $groups as $rows => $groupRows ) {
			[ $agentId, $scopeId ] = array_map( 'intval', explode( ':', (string) $rows ) );
			$this->rolloverGroup( $repository, $agentId, $scopeId, $groupRows );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function rolloverGroup( MemoryRepository $repository, int $agentId, int $scopeId, array $rows ): void {
		$sourceIds = array_map( static fn( array $row ): int => (int) $row['id'], $rows );

		/**
		 * agent_brain/rollover_compress filter (contracts.md): ( ?string $episode,
		 * array $workingRows, int $agentId, int $scopeId ) => episode text or
		 * null. null falls back to buildDigest() below. Wave-2 AI compression
		 * hooks in here without touching this class.
		 */
		$episode = apply_filters( 'agent_brain/rollover_compress', null, $rows, $agentId, $scopeId );
		if ( ! is_string( $episode ) || trim( $episode ) === '' ) {
			$episode = $this->buildDigest( $rows );
		}

		$importance = $this->averageImportance( $rows );

		// Ponytail note: no manual SQL transaction here on purpose — it would
		// nest inside (and prematurely commit) the outer test-harness
		// transaction, and MVP scope accepts "insert episode, link, mark
		// rolled_up" as three idempotent-on-retry steps rather than one
		// atomic unit. A crash between steps leaves the source rows still
		// 'active', so the next run reprocesses them (worst case: an extra
		// episode with an overlapping source set) rather than losing data.
		try {
			$episodeId = $repository->insert( [
				'agent_id'   => $agentId,
				'scope_id'   => $scopeId,
				'type'       => 'episode',
				'content'    => $episode,
				'importance' => $importance,
				'status'     => 'active',
				'source_ids' => $sourceIds,
			] );

			$repository->link( 'memory', $episodeId, $sourceIds );
			$repository->maintenanceMarkRolledUp( $sourceIds );
		} catch ( \Throwable $e ) {
			do_action( 'agent_brain/audit', [
				'actor_id' => $agentId,
				'event'    => 'memory.rollover_failed',
				'status'   => 'error',
				'meta'     => [ 'scope_id' => $scopeId, 'source_count' => count( $sourceIds ) ],
			] );

			throw $e;
		}
	}

	/**
	 * Deterministic, length-bounded built-in fallback digest used when no
	 * `agent_brain/rollover_compress` filter is attached (Wave 2 adds real
	 * AI compression via that filter).
	 *
	 * The episode is read by people (Overview, review screens) and searched by
	 * agents, so it is plain text: a single note stays as written, several
	 * become a bullet list. No header or importance markers (the episode row
	 * carries importance), and truncation never splits a multibyte character.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function buildDigest( array $rows ): string {
		$rows  = array_slice( $rows, 0, self::DIGEST_MAX_ROWS );
		$notes = array_values( array_filter( array_map(
			static fn( array $row ): string => trim( (string) preg_replace( '/\s+/u', ' ', (string) $row['content'] ) ),
			$rows
		), static fn( string $note ): bool => $note !== '' ) );

		if ( $notes === [] ) {
			return '(empty working notes)';
		}

		if ( count( $notes ) === 1 ) {
			$digest = $notes[0];
		} else {
			$digest = implode( "\n", array_map(
				static fn( string $note ): string => '- ' . ( mb_strlen( $note ) > self::NOTE_MAX_CHARS ? mb_substr( $note, 0, self::NOTE_MAX_CHARS ) . '…' : $note ),
				$notes
			) );
		}

		if ( strlen( $digest ) > self::DIGEST_MAX_CHARS ) {
			// The bound is on bytes; mb_strcut() cuts on a character boundary
			// and leaves room for the multibyte '…'.
			$ellipsis = '…';
			$digest   = mb_strcut( $digest, 0, self::DIGEST_MAX_CHARS - strlen( $ellipsis ), 'UTF-8' ) . $ellipsis;
		}

		return $digest;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function averageImportance( array $rows ): int {
		if ( $rows === [] ) {
			return 50;
		}

		$sum = array_sum( array_map( static fn( array $row ): int => (int) $row['importance'], $rows ) );

		return (int) round( $sum / count( $rows ) );
	}
}
