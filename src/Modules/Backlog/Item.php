<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Backlog;

use AgentBrain\Modules\DataStore\TioDay;

/**
 * #891: the shape of a backlog item, items/<slug> (615 section 622 plus
 * note 643's additions in section 648):
 *
 *   {id: B12, kind, title, detail, need?, urgency, rank, status, owner?,
 *    assignee?, links, prompt?, added_by, added_at, decided?,
 *    sources: {"forgejo:team/project": {id, url, main?, first_read,
 *              synced_at, updated_at, snapshot}, ...},
 *    tioday?: "T151", release?, area?, tags, parent?: "B3", aliases,
 *    flags: [{what, detail, at}], placed?: "import"}
 *
 * Snapshot fields (sources.*.snapshot) are written only by their source.
 * The rest are TomaTío's. Plain PHP apart from TioDay's text helpers.
 */
final class Item {

	public const KINDS     = [ 'idea', 'need', 'bug', 'feature', 'chore', 'epic' ];
	public const STATUSES  = [ 'inbox', 'ready', 'building', 'shipped', 'declined', 'parked', 'archived' ];
	public const URGENCIES = [ 'now', 'soon', 'later', 'someday' ];
	public const FLAGS     = [ 'release_mismatch', 'closed_unshipped', 'closed_unfixed', 'source_clash', 'both_changed', 'deleted_in_tool', 'stale', 'unmapped' ];

	/** Statuses that sit in the ranked list (the rest are the Inbox, or off the list). */
	public const RANKED = [ 'ready', 'building' ];

	/** What only a person decides (615 section 624): agents propose these in a comment. */
	public const PEOPLE_ONLY = [ 'ready', 'declined', 'parked', 'archived', 'inbox' ];

	public const MAX_TITLE   = 200;
	public const MAX_DETAIL  = 2000;
	public const MAX_PROMPT  = 4000;
	public const MAX_TAGS    = 20;
	public const MAX_ALIASES = 20;
	public const MAX_FLAGS   = 20;

	/**
	 * A new item from what a person or agent logs.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|string The item, or why it can't be one.
	 */
	public static function fromInput( array $input, string $addedBy, string $now ) {
		$title = TioDay::text( $input['title'] ?? '', self::MAX_TITLE );
		if ( $title === '' ) {
			return __( 'An item needs a title.', 'tomatio' );
		}
		$kind = isset( $input['kind'] ) && is_string( $input['kind'] ) && $input['kind'] !== '' ? $input['kind'] : 'idea';
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			/* translators: %s: the allowed kinds. */
			return sprintf( __( 'kind is one of: %s.', 'tomatio' ), implode( ', ', self::KINDS ) );
		}
		$urgency = isset( $input['urgency'] ) && is_string( $input['urgency'] ) && $input['urgency'] !== '' ? $input['urgency'] : 'later';
		if ( ! in_array( $urgency, self::URGENCIES, true ) ) {
			/* translators: %s: the allowed urgencies. */
			return sprintf( __( 'urgency is one of: %s.', 'tomatio' ), implode( ', ', self::URGENCIES ) );
		}
		$links = TioDay::links( $input['links'] ?? [] );
		if ( $links instanceof \WP_Error ) {
			return $links->get_error_message();
		}

		$item = [
			'kind'     => $kind,
			'title'    => $title,
			'detail'   => TioDay::text( $input['detail'] ?? '', self::MAX_DETAIL, true ),
			'urgency'  => $urgency,
			'rank'     => null,
			'status'   => 'inbox',
			'links'    => $links,
			'added_by' => $addedBy,
			'added_at' => $now,
			'sources'  => new \stdClass(),
			'tags'     => self::words( $input['tags'] ?? [], self::MAX_TAGS ),
			'aliases'  => [],
			'flags'    => [],
		];
		if ( $kind === 'need' && is_array( $input['need'] ?? null ) ) {
			$item['need'] = self::need( $input['need'] );
		}
		foreach ( [ 'area', 'release', 'owner' ] as $field ) {
			$value = TioDay::text( $input[ $field ] ?? '', TioDay::MAX_NAME );
			if ( $value !== '' ) {
				$item[ $field ] = $value;
			}
		}

		return $item;
	}

	/**
	 * A short line per item for lists (project-get, the report).
	 *
	 * @param array<string, mixed> $doc     A stored items document.
	 * @param array<string, mixed> $project meta/project
	 * @return array<string, mixed>
	 */
	public static function summary( array $doc, array $project ): array {
		$v      = is_array( $doc['value'] ) ? $doc['value'] : [];
		$status = (string) ( $v['status'] ?? 'inbox' );
		$rank   = is_string( $v['rank'] ?? null ) ? $v['rank'] : null;

		return array_filter(
			[
				'id'       => $v['id'] ?? null,
				'key'      => (string) $doc['key'],
				'title'    => (string) ( $v['title'] ?? '' ),
				'kind'     => $v['kind'] ?? null,
				'status'   => $status,
				'band'     => in_array( $status, self::RANKED, true ) ? Rank::band( $rank, $project ) : null,
				'rank'     => $rank,
				'urgency'  => $v['urgency'] ?? null,
				'owner'    => $v['owner'] ?? null,
				'assignee' => $v['assignee'] ?? null,
				'release'  => $v['release'] ?? null,
				'area'     => $v['area'] ?? null,
				'parent'   => $v['parent'] ?? null,
				'sources'  => self::badges( $v ),
				'flags'    => array_values( array_map( static fn ( $f ): string => is_array( $f ) ? (string) ( $f['what'] ?? '' ) : '', is_array( $v['flags'] ?? null ) ? $v['flags'] : [] ) ),
				'version'  => (int) ( $doc['version'] ?? 0 ),
			],
			static fn ( $x ): bool => $x !== null && $x !== [] && $x !== ''
		);
	}

	/**
	 * Each source as one badge: "forgejo:team/project#807 open".
	 *
	 * @param array<string, mixed> $item
	 * @return array<int, string>
	 */
	public static function badges( array $item ): array {
		$out = [];
		foreach ( is_array( $item['sources'] ?? null ) ? $item['sources'] : [] as $name => $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$state = is_array( $source['snapshot'] ?? null ) ? (string) ( $source['snapshot']['state'] ?? $source['snapshot']['status'] ?? '' ) : '';
			$out[] = trim( $name . ( str_starts_with( (string) $name, 'notion:' ) ? '/' : '#' ) . ( $source['id'] ?? '' ) . ' ' . $state );
		}

		return $out;
	}

	/**
	 * A flag for a person: what a source couldn't decide.
	 *
	 * @return array{what:string, detail:string, at:string}
	 */
	public static function flag( string $what, string $detail, string $now ): array {
		return [ 'what' => in_array( $what, self::FLAGS, true ) ? $what : 'source_clash', 'detail' => TioDay::text( $detail, 300 ), 'at' => $now ];
	}

	/**
	 * $item with $flag added, unless the same flag is already there.
	 *
	 * @param array<string, mixed>               $item
	 * @param array{what:string, detail:string, at:string} $flag
	 * @return array<string, mixed>
	 */
	public static function withFlag( array $item, array $flag ): array {
		$flags = is_array( $item['flags'] ?? null ) ? $item['flags'] : [];
		foreach ( $flags as $old ) {
			if ( is_array( $old ) && ( $old['what'] ?? '' ) === $flag['what'] && ( $old['detail'] ?? '' ) === $flag['detail'] ) {
				return $item;
			}
		}
		$flags[]       = $flag;
		$item['flags'] = array_slice( $flags, -self::MAX_FLAGS );

		return $item;
	}

	/**
	 * $item with $alias added to its aliases (what "What is AB-84?" finds).
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	public static function withAlias( array $item, string $alias ): array {
		$alias   = TioDay::text( $alias, 80 );
		$aliases = is_array( $item['aliases'] ?? null ) ? $item['aliases'] : [];
		if ( $alias !== '' && ! in_array( $alias, $aliases, true ) ) {
			$aliases[] = $alias;
		}
		$item['aliases'] = array_slice( $aliases, 0, self::MAX_ALIASES );

		return $item;
	}

	/**
	 * Whether $ref names $item: its B ID, its key, or one of its aliases
	 * ("#807", "AB-84", "T151"), ignoring case.
	 *
	 * @param array<string, mixed> $doc A stored items document.
	 */
	public static function matches( array $doc, string $ref ): bool {
		$ref = strtolower( trim( $ref ) );
		if ( $ref === '' ) {
			return false;
		}
		$v = is_array( $doc['value'] ) ? $doc['value'] : [];
		if ( strtolower( (string) ( $v['id'] ?? '' ) ) === $ref || strtolower( (string) $doc['key'] ) === $ref ) {
			return true;
		}
		foreach ( is_array( $v['aliases'] ?? null ) ? $v['aliases'] : [] as $alias ) {
			if ( is_string( $alias ) && strtolower( $alias ) === $ref ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $need
	 * @return array<string, mixed>
	 */
	private static function need( $need ): array {
		$evidence = [];
		foreach ( array_slice( is_array( $need['evidence'] ?? null ) ? $need['evidence'] : [], 0, 10 ) as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$row = array_filter(
				[
					'quote' => TioDay::text( $e['quote'] ?? '', 500 ),
					'who'   => TioDay::text( $e['who'] ?? '', TioDay::MAX_NAME ),
					'link'  => is_string( $e['link'] ?? null ) && str_starts_with( $e['link'], 'https://' ) ? esc_url_raw( $e['link'] ) : '',
				]
			);
			if ( $row !== [] ) {
				$evidence[] = $row;
			}
		}

		return [
			'who'      => TioDay::text( $need['who'] ?? '', TioDay::MAX_NAME ),
			'hurts'    => TioDay::text( $need['hurts'] ?? '', 500 ),
			'evidence' => $evidence,
		];
	}

	/**
	 * Free words (tags): trimmed, unique, at most $max of 40 characters.
	 *
	 * @param mixed $words
	 * @return array<int, string>
	 */
	public static function words( $words, int $max ): array {
		$words = is_string( $words ) ? explode( ',', $words ) : ( is_array( $words ) ? $words : [] );
		$out   = [];
		foreach ( $words as $w ) {
			$w = is_string( $w ) ? TioDay::text( $w, 40 ) : '';
			if ( $w !== '' && ! in_array( $w, $out, true ) ) {
				$out[] = $w;
			}
		}

		return array_slice( $out, 0, $max );
	}
}
