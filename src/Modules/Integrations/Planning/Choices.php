<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

use AgentBrain\Modules\Backlog\Backlog;

/**
 * What planning sync can point at, and the default a curator approves:
 * the service's Linear teams or Notion databases, and this brain's Magic
 * Backlog boards. With one of each, the default is that pair and the
 * first dry run is prepared on its own right after connecting. Nothing is
 * written to either side until a person approves that dry run.
 */
final class Choices {

	private const LINEAR_URL = 'https://api.linear.app/graphql';

	private const LINEAR_TEAMS = '{ teams(first: 50) { nodes { id name key } } }';

	private const NOTION_SEARCH = 'https://api.notion.com/v1/search';

	private const CACHE = 'ab_plan_choices_';

	private const CACHE_FOR = 600;

	private const PAGES = 'ab_plan_pages_';

	/**
	 * Linear teams or Notion databases, id => name. A string says why the
	 * list could not be read. Kept for ten minutes so the settings page
	 * does not call the service on every load.
	 *
	 * @return array<string, string>|string
	 */
	public static function remote( string $service, bool $fresh = false ): array|string {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE . $service );
			if ( is_array( $cached ) ) {
				return self::strings( $cached );
			}
		}
		if ( $service === 'linear' ) {
			$sent = Pull::dispatch( 'linear', 'POST', self::LINEAR_URL, [ 'query' => self::LINEAR_TEAMS ] );
		} elseif ( $service === 'notion' ) {
			$sent = Pull::dispatch( 'notion', 'POST', self::NOTION_SEARCH, [ 'filter' => [ 'property' => 'object', 'value' => 'database' ], 'page_size' => 50 ] );
		} else {
			return 'That integration has no planning sync.';
		}
		if ( is_string( $sent ) ) {
			return $sent;
		}
		if ( $sent['status'] < 200 || $sent['status'] >= 300 ) {
			return 'The service answered ' . $sent['status'] . '.';
		}
		$decoded = json_decode( $sent['body'], true );
		if ( ! is_array( $decoded ) ) {
			return 'The service answer was not readable.';
		}
		$list = $service === 'linear' ? self::linearTeams( $decoded ) : self::notionDatabases( $decoded );
		set_transient( self::CACHE . $service, $list, self::CACHE_FOR );

		return $list;
	}

	public static function forget( string $service ): void {
		delete_transient( self::CACHE . $service );
		delete_transient( self::PAGES . $service );
	}

	/**
	 * B236: the Notion pages this connection can see, id => title, to hold
	 * a new backlog database. A string says why the list could not be read.
	 *
	 * @return array<string, string>|string
	 */
	public static function pages( bool $fresh = false ): array|string {
		if ( ! $fresh ) {
			$cached = get_transient( self::PAGES . 'notion' );
			if ( is_array( $cached ) ) {
				return self::strings( $cached );
			}
		}
		$sent = Pull::dispatch( 'notion', 'POST', self::NOTION_SEARCH, [ 'filter' => [ 'property' => 'object', 'value' => 'page' ], 'page_size' => 50 ] );
		if ( is_string( $sent ) ) {
			return $sent;
		}
		if ( $sent['status'] < 200 || $sent['status'] >= 300 ) {
			return 'The service answered ' . $sent['status'] . '.';
		}
		$decoded = json_decode( $sent['body'], true );
		if ( ! is_array( $decoded ) ) {
			return 'The service answer was not readable.';
		}
		$list = self::notionPages( $decoded );
		set_transient( self::PAGES . 'notion', $list, self::CACHE_FOR );

		return $list;
	}

	/**
	 * Pages from a Notion search, by bare id. A row of a database is left
	 * out: a backlog goes under a page, not inside another table.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, string>
	 */
	public static function notionPages( array $payload ): array {
		$results = is_array( $payload['results'] ?? null ) ? $payload['results'] : [];
		$out     = [];
		foreach ( $results as $row ) {
			if ( ! is_array( $row ) || ( $row['object'] ?? '' ) !== 'page' || ! is_string( $row['id'] ?? null ) || ! empty( $row['archived'] ) || ! empty( $row['in_trash'] ) ) {
				continue;
			}
			$parent = is_array( $row['parent'] ?? null ) ? $row['parent'] : [];
			if ( ( $parent['type'] ?? '' ) === 'database_id' ) {
				continue;
			}
			$title = '';
			foreach ( is_array( $row['properties'] ?? null ) ? $row['properties'] : [] as $prop ) {
				if ( ! is_array( $prop ) || ( $prop['type'] ?? '' ) !== 'title' ) {
					continue;
				}
				foreach ( is_array( $prop['title'] ?? null ) ? $prop['title'] : [] as $part ) {
					if ( is_array( $part ) && is_string( $part['plain_text'] ?? null ) ) {
						$title .= $part['plain_text'];
					}
				}
			}
			$id = strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', $row['id'] ) );
			if ( strlen( $id ) !== 32 ) {
				continue;
			}
			$out[ $id ] = self::label( $title !== '' ? $title : 'Untitled page' );
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );

		return $out;
	}

	/**
	 * B236: what to do when the connection sees no page to put the
	 * backlog under.
	 */
	public static function noPages(): string {
		return 'This connection sees no Notion page yet. In Notion, open a page, ••• > Connections, add this connection, then come back and click Check again.';
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, string>
	 */
	public static function linearTeams( array $payload ): array {
		$data  = is_array( $payload['data'] ?? null ) ? $payload['data'] : [];
		$teams = is_array( $data['teams'] ?? null ) ? $data['teams'] : [];
		$nodes = is_array( $teams['nodes'] ?? null ) ? $teams['nodes'] : [];
		$out   = [];
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) || ! is_string( $node['name'] ?? null ) ) {
				continue;
			}
			$out[ strtolower( $node['id'] ) ] = self::label( $node['name'] );
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );

		return $out;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, string>
	 */
	public static function notionDatabases( array $payload ): array {
		$results = is_array( $payload['results'] ?? null ) ? $payload['results'] : [];
		$out     = [];
		foreach ( $results as $row ) {
			if ( ! is_array( $row ) || ( $row['object'] ?? '' ) !== 'database' || ! is_string( $row['id'] ?? null ) ) {
				continue;
			}
			$title = '';
			foreach ( is_array( $row['title'] ?? null ) ? $row['title'] : [] as $part ) {
				if ( is_array( $part ) && is_string( $part['plain_text'] ?? null ) ) {
					$title .= $part['plain_text'];
				}
			}
			$id         = strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', $row['id'] ) );
			$out[ $id ] = self::label( $title !== '' ? $title : 'Untitled database' );
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );

		return $out;
	}

	/**
	 * Magic Backlog boards this person can write, id => title.
	 *
	 * @return array<int, string>
	 */
	public static function boards( int $actorId ): array {
		$out = [];
		foreach ( Backlog::all() as $id ) {
			if ( $actorId > 0 && ! Backlog::canWrite( $actorId, $id ) ) {
				continue;
			}
			// The stored title: get_the_title() puts "Private: " in front of a private board.
			$title      = get_post_field( 'post_title', $id );
			$out[ $id ] = self::label( is_string( $title ) && $title !== '' ? $title : 'Magic Backlog ' . $id );
		}

		return $out;
	}

	/**
	 * The option to preselect: the one already saved, the only one, or
	 * the one whose name matches a hint (a board title, the site name).
	 * Empty when there is no clear choice.
	 *
	 * @param array<int|string, string> $options
	 * @param array<int, string>        $hints
	 */
	public static function pick( array $options, string $current, array $hints = [] ): string {
		if ( $current !== '' && array_key_exists( $current, $options ) ) {
			return $current;
		}
		if ( count( $options ) === 1 ) {
			return (string) array_key_first( $options );
		}
		$found = '';
		foreach ( $options as $id => $name ) {
			$needle = self::plain( $name );
			if ( $needle === '' ) {
				continue;
			}
			foreach ( $hints as $hint ) {
				$hay = self::plain( $hint );
				if ( $hay !== '' && ( str_contains( $hay, $needle ) || str_contains( $needle, $hay ) ) ) {
					if ( $found !== '' && $found !== (string) $id ) {
						return ''; // two names match: no clear default.
					}
					$found = (string) $id;
				}
			}
		}

		return $found;
	}

	/**
	 * The suggested pair for this service, with the lists behind it.
	 *
	 * @return array{target: string, board: int, remote: array<string, string>|string, boards: array<int, string>}
	 */
	public static function suggest( string $service, int $actorId, bool $fresh = false ): array {
		$row    = Library::row( $service );
		$remote = self::remote( $service, $fresh );
		$boards = self::boards( $actorId );
		$board  = (int) self::pick( $boards, (int) $row['backlog_id'] > 0 ? (string) (int) $row['backlog_id'] : '', is_array( $remote ) ? array_values( $remote ) : [] );
		$hints  = array_filter( [ $board > 0 ? (string) ( $boards[ $board ] ?? '' ) : '', (string) get_bloginfo( 'name' ) ] );
		$target = is_array( $remote ) ? self::pick( $remote, (string) $row['target'], array_values( $hints ) ) : '';

		return [ 'target' => $target, 'board' => $board, 'remote' => $remote, 'boards' => $boards ];
	}

	/**
	 * Right after a service connects: when it has exactly one team (or
	 * database) and the brain exactly one board, save that pair and
	 * prepare the dry run, so the card waits only for approval. Returns
	 * the words to add to the connect notice, or '' when it did nothing.
	 */
	public static function autoSetup( string $service, int $actorId ): string {
		if ( $service !== 'linear' && $service !== 'notion' ) {
			return '';
		}
		$row = Library::row( $service );
		if ( (string) $row['target'] !== '' || ! empty( $row['live'] ) ) {
			return '';
		}
		$suggest = self::suggest( $service, $actorId, true );
		if ( ! is_array( $suggest['remote'] ) || count( $suggest['remote'] ) !== 1 || count( $suggest['boards'] ) !== 1 ) {
			return '';
		}
		$row['target']      = $suggest['target'];
		$row['target_kind'] = $service === 'linear' ? 'team' : 'database';
		$row['backlog_id']  = $suggest['board'];
		$row['fingerprint'] = '';
		Library::save( $service, $row );
		$result = Apply::preview( $service, $actorId );
		$pair   = sprintf(
			/* translators: 1: Magic Backlog board title, 2: Linear team or Notion database name. */
			__( 'Planning sync is set up for “%1$s” with “%2$s”.', 'tomatio' ),
			(string) $suggest['boards'][ $suggest['board'] ],
			(string) $suggest['remote'][ $suggest['target'] ]
		);

		return $result['ok']
			? $pair . ' ' . __( 'Its dry run is ready below. Nothing is written until you approve it.', 'tomatio' )
			: $pair . ' ' . __( 'The dry run did not finish; run it again below.', 'tomatio' );
	}

	/**
	 * @param array<mixed> $raw
	 * @return array<string, string>
	 */
	private static function strings( array $raw ): array {
		$out = [];
		foreach ( $raw as $id => $name ) {
			if ( is_string( $id ) && is_string( $name ) ) {
				$out[ $id ] = $name;
			}
		}

		return $out;
	}

	private static function label( string $name ): string {
		$name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 80 ) : substr( $name, 0, 80 );
	}

	private static function plain( string $text ): string {
		$text = function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
		$text = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', ' ', $text ) );
		$text = trim( (string) preg_replace( '/\b(magic|backlog|board|team|the)\b/', ' ', $text ) );

		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}
}
