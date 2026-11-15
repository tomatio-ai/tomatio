<?php

declare(strict_types=1);

namespace AgentBrain\Modules\BrainRead;

use AgentBrain\Core\Text;
use AgentBrain\Core\Untrusted;
use AgentBrain\Modules\Recall\Recall;

/**
 * ART-25 (#165): result shaping for the brain-read endpoints. Pure (no
 * WordPress beyond guarded optional functions) so the prompt-injection and
 * provenance wrapping behaviour is unit-testable.
 *
 * Every item goes out with the content three ways, mirroring how MCP
 * memory-search returns results:
 *
 * - excerpt: plain text, <= 200 chars, Gutenberg markup stripped the same
 *   way Recall strips it (Recall::memoryRowToCandidate / postRowToCandidate
 *   are private; the same rules are applied here).
 * - text: the full plain-text content.
 * - data: the content wrapped in Core\Untrusted::wrap()'s <memory_data>
 *   provenance envelope — stored brain content is DATA, never instructions,
 *   and the wrap HTML-escapes the body so an embedded </memory_data> or
 *   fake-preamble payload cannot break out.
 *
 * Authorship attribution reuses Recall::resolveKnowledgeAgentId() so the
 * knowledge/procedure author answer can never diverge from memory-search.
 */
final class Items {

	public const EXCERPT_MAX = 200;

	/**
	 * @param array<string, mixed> $row A raw ab_memory row (as MemoryScopedSearch returns).
	 * @return array<string, mixed>
	 */
	public static function fromMemoryRow( array $row ): array {
		$id      = (int) ( $row['id'] ?? 0 );
		$content = (string) ( $row['content'] ?? '' );
		$plain   = str_contains( $content, '<!-- wp:' ) ? self::plainText( $content ) : $content;

		return self::shape(
			'memory',
			$id,
			(int) ( $row['scope_id'] ?? 0 ),
			(float) ( $row['relevance'] ?? 0.0 ),
			(string) ( $row['created'] ?? '' ),
			(int) ( $row['importance'] ?? 0 ),
			null,
			$plain,
			[
				'source'        => (string) ( $row['type'] ?? 'memory' ),
				'agent_id'      => (int) ( $row['agent_id'] ?? 0 ),
				'memory_id'     => $id,
				'agent_written' => (bool) ( (int) ( $row['agent_written'] ?? 1 ) ),
				'client'        => isset( $row['client'] ) && is_string( $row['client'] ) && $row['client'] !== '' ? $row['client'] : null,
			]
		);
	}

	/**
	 * @param array<string, mixed> $row An ab_knowledge / ab_procedure post row (as KnowledgeSearch returns).
	 * @return array<string, mixed>
	 */
	public static function fromPostRow( array $row ): array {
		$kind    = ( (string) ( $row['post_type'] ?? '' ) ) === 'ab_procedure' ? 'procedure' : 'knowledge';
		$id      = (int) ( $row['ID'] ?? 0 );
		$title   = (string) ( $row['post_title'] ?? '' );
		$content = (string) ( $row['post_content'] ?? '' );
		$plain   = trim( $title . ': ' . self::plainText( $content ) );

		return self::shape(
			$kind,
			$id,
			(int) ( $row['scope_id'] ?? 0 ),
			(float) ( $row['relevance'] ?? 0.0 ),
			(string) ( $row['post_date_gmt'] ?? '' ),
			70,
			$title !== '' ? $title : null,
			$plain,
			[
				'source'        => $kind,
				'agent_id'      => Recall::resolveKnowledgeAgentId( $id, (int) ( $row['post_author'] ?? 0 ) ),
				'memory_id'     => $id,
				'agent_written' => false,
				'approved_by'   => self::approvedBy( $id ),
			]
		);
	}

	/**
	 * @param array{source?:string, agent_id?:int, memory_id?:int, agent_written?:bool, client?:?string, approved_by?:?string} $provenance
	 * @return array<string, mixed>
	 */
	private static function shape( string $kind, int $id, int $scopeId, float $relevance, string $created, int $importance, ?string $title, string $plain, array $provenance ): array {
		// The wrap attributes are escaped by Untrusted::wrap(); the body is
		// HTML-escaped there too, so stored content can never forge a closing
		// tag or attribute. text/excerpt stay raw plain text — the JSON
		// encoder is the only framing they get, which is exactly the
		// memory-search contract.
		$wrapProvenance = [
			'source'        => (string) ( $provenance['source'] ?? $kind ),
			'agent_id'      => (int) ( $provenance['agent_id'] ?? 0 ),
			'memory_id'     => $id,
			'agent_written' => (bool) ( $provenance['agent_written'] ?? true ),
		];
		if ( array_key_exists( 'client', $provenance ) && is_string( $provenance['client'] ) && $provenance['client'] !== '' ) {
			$wrapProvenance['client'] = $provenance['client'];
		}
		if ( array_key_exists( 'approved_by', $provenance ) && is_string( $provenance['approved_by'] ) && $provenance['approved_by'] !== '' ) {
			$wrapProvenance['approved_by'] = $provenance['approved_by'];
		}

		return [
			'kind'       => $kind,
			'id'         => $id,
			'scope_id'   => $scopeId,
			'relevance'  => round( $relevance, 6 ),
			'created'    => $created,
			'importance' => $importance,
			'title'      => $title,
			'excerpt'    => self::excerpt( $plain ),
			'text'       => $plain,
			'data'       => Untrusted::wrap( $plain, $wrapProvenance ),
			'provenance' => [
				'source'        => $wrapProvenance['source'],
				'agent_id'      => $wrapProvenance['agent_id'],
				'memory_id'     => $id,
				'agent_written' => $wrapProvenance['agent_written'],
				'client'        => $wrapProvenance['client'] ?? null,
				'approved_by'   => $wrapProvenance['approved_by'] ?? null,
			],
		];
	}

	/**
	 * Result-shaping helper: strip Gutenberg block markup down to readable
	 * plain text, the same extraction Recall uses; guarded so the no-WP unit
	 * bootstrap keeps running against plain fixture content.
	 */
	private static function plainText( string $content ): string {
		if ( ! function_exists( 'wp_strip_all_tags' ) ) {
			return $content;
		}

		return Text::plainTextFromBlocks( $content );
	}

	/**
	 * Who approved this knowledge, so a reader sees a person's decision
	 * behind the text (docs/rfc/people-write-through-agents.md); mirrors
	 * Briefing::approvedBy(). Null when unknown.
	 */
	private static function approvedBy( int $postId ): ?string {
		if ( $postId <= 0 || ! function_exists( 'get_post_meta' ) || ! function_exists( 'get_userdata' ) ) {
			return null;
		}

		$reviewer = get_userdata( (int) get_post_meta( $postId, \AgentBrain\Modules\Review\ReviewActions::META_REVIEWED_BY, true ) );

		return $reviewer instanceof \WP_User ? (string) $reviewer->display_name : null;
	}

	private static function excerpt( string $text ): string {
		$normalized = preg_replace( '/\s+/', ' ', $text );
		$normalized = trim( $normalized ?? $text );

		if ( mb_strlen( $normalized ) <= self::EXCERPT_MAX ) {
			return $normalized;
		}

		return mb_substr( $normalized, 0, self::EXCERPT_MAX - 1 ) . '…';
	}
}
