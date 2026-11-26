<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Context;

/**
 * #991: pure read plan for Notion and Linear. No HTTP, no database.
 * A request this class builds is a read, or it is refused. The first
 * read is a count; nothing here stores a copy.
 */
final class Read {

	public const NOTION = 'notion';

	public const LINEAR = 'linear';

	/** @var array<string, array<int, string>> */
	public const KINDS = [
		self::NOTION => [ 'page', 'database', 'section' ],
		self::LINEAR => [ 'team', 'project' ],
	];

	private const NOTION_HOST = 'api.notion.com';

	private const LINEAR_HOST = 'api.linear.app';

	private const LINEAR_TEAM = 'query ($id: String!, $after: String) { team(id: $id) { issues(first: 50, after: $after) { nodes { id identifier title description url updatedAt } pageInfo { hasNextPage endCursor } } } }';

	private const LINEAR_PROJECT = 'query ($id: String!, $after: String) { project(id: $id) { issues(first: 50, after: $after) { nodes { id identifier title description url updatedAt } pageInfo { hasNextPage endCursor } } } }';

	public static function kindOk( string $service, string $kind ): bool {
		return in_array( $kind, self::KINDS[ $service ] ?? [], true );
	}

	/**
	 * A Notion id (32 hex) or a Linear id (UUID), taken from a paste or a link.
	 */
	public static function locator( string $service, string $raw ): string {
		$raw = trim( $raw );
		if ( $service === self::NOTION ) {
			return self::notionId( $raw );
		}
		if ( $service === self::LINEAR ) {
			return self::linearId( $raw );
		}

		return '';
	}

	public static function notionId( string $raw ): string {
		$hex = strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', $raw ) );
		if ( strlen( $hex ) < 32 ) {
			return '';
		}

		return substr( $hex, -32 );
	}

	public static function linearId( string $raw ): string {
		if ( preg_match( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $raw, $m ) !== 1 ) {
			return '';
		}

		return strtolower( $m[0] );
	}

	/**
	 * @return array{method: string, url: string, body: ?array<string, mixed>}
	 */
	public static function notionPageRequest( string $id ): array {
		return [
			'method' => 'GET',
			'url'    => 'https://' . self::NOTION_HOST . '/v1/pages/' . $id,
			'body'   => null,
		];
	}

	/**
	 * @return array{method: string, url: string, body: ?array<string, mixed>}
	 */
	public static function notionBlocksRequest( string $id, ?string $cursor ): array {
		$url = 'https://' . self::NOTION_HOST . '/v1/blocks/' . $id . '/children?page_size=100';
		if ( $cursor !== null && $cursor !== '' ) {
			$url .= '&start_cursor=' . rawurlencode( $cursor );
		}

		return [
			'method' => 'GET',
			'url'    => $url,
			'body'   => null,
		];
	}

	/**
	 * @return array{method: string, url: string, body: ?array<string, mixed>}
	 */
	public static function notionQueryRequest( string $id, ?string $cursor ): array {
		$body = [ 'page_size' => 100 ];
		if ( $cursor !== null && $cursor !== '' ) {
			$body['start_cursor'] = $cursor;
		}

		return [
			'method' => 'POST',
			'url'    => 'https://' . self::NOTION_HOST . '/v1/databases/' . $id . '/query',
			'body'   => $body,
		];
	}

	/**
	 * @return array{method: string, url: string, body: ?array<string, mixed>}
	 */
	public static function linearRequest( string $kind, string $id, ?string $cursor ): array {
		$vars = [ 'id' => $id ];
		if ( $cursor !== null && $cursor !== '' ) {
			$vars['after'] = $cursor;
		}

		return [
			'method' => 'POST',
			'url'    => 'https://' . self::LINEAR_HOST . '/graphql',
			'body'   => [
				'query'     => $kind === 'project' ? self::LINEAR_PROJECT : self::LINEAR_TEAM,
				'variables' => $vars,
			],
		];
	}

	/**
	 * A read this feature is allowed to send. Anything else, including a
	 * write to the service, is false.
	 *
	 * @param array{method?: string, url?: string, body?: mixed} $request
	 */
	public static function isRead( array $request ): bool {
		$method = strtoupper( (string) ( $request['method'] ?? '' ) );
		$url    = (string) ( $request['url'] ?? '' );
		$parts  = parse_url( $url );
		if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $host, [ self::NOTION_HOST, self::LINEAR_HOST ], true ) ) {
			return false;
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( $method === 'GET' ) {
			return str_starts_with( $path, '/v1/pages/' ) || str_starts_with( $path, '/v1/blocks/' );
		}
		if ( $method !== 'POST' ) {
			return false;
		}
		if ( $host === self::NOTION_HOST ) {
			return preg_match( '#^/v1/databases/[a-f0-9]{32}/query$#', $path ) === 1;
		}
		if ( $path !== '/graphql' ) {
			return false;
		}
		$body  = $request['body'] ?? null;
		$query = is_array( $body ) ? (string) ( $body['query'] ?? '' ) : '';
		$trim  = ltrim( $query );
		if ( $trim === '' || ! str_starts_with( strtolower( $trim ), 'query' ) ) {
			return false;
		}

		return preg_match( '/\\bmutation\\b/i', $query ) !== 1;
	}

	/**
	 * @return array<int, array{method: string, url: string, body: ?array<string, mixed>}>
	 */
	public static function sampleRequests(): array {
		$id  = '0123456789abcdef0123456789abcdef';
		$uid = '11111111-1111-1111-1111-111111111111';

		return [
			self::notionPageRequest( $id ),
			self::notionBlocksRequest( $id, null ),
			self::notionBlocksRequest( $id, 'cursor' ),
			self::notionQueryRequest( $id, null ),
			self::notionQueryRequest( $id, 'cursor' ),
			self::linearRequest( 'team', $uid, null ),
			self::linearRequest( 'project', $uid, 'cursor' ),
		];
	}

	/**
	 * @param array<string, mixed> $page
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array{id: string, title: string, url: string, text: string}
	 */
	public static function notionPage( array $page, array $blocks ): array {
		$id    = self::notionId( (string) ( $page['id'] ?? '' ) );
		$title = self::pageTitle( $page );
		$bits  = [];
		foreach ( $blocks as $block ) {
			$line = self::blockText( $block );
			if ( $line !== '' ) {
				$bits[] = $line;
			}
		}

		return [
			'id'    => $id,
			'title' => $title,
			'url'   => self::notionUrl( $page, $id ),
			'text'  => self::clip( trim( $title . ( $bits === [] ? '' : "\n" . implode( "\n", $bits ) ) ) ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, string>
	 */
	public static function childPageIds( array $blocks ): array {
		$ids = [];
		foreach ( $blocks as $block ) {
			if ( ( $block['type'] ?? '' ) !== 'child_page' ) {
				continue;
			}
			$id = self::notionId( (string) ( $block['id'] ?? '' ) );
			if ( $id !== '' ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array{items: array<int, array{id: string, title: string, url: string, text: string}>, cursor: ?string}
	 */
	public static function notionDatabase( array $query ): array {
		$items = [];
		foreach ( is_array( $query['results'] ?? null ) ? $query['results'] : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$item = self::notionRow( $row );
			if ( $item['id'] !== '' && ( $item['title'] !== '' || $item['text'] !== '' ) ) {
				$items[] = $item;
			}
		}
		$cursor = null;
		if ( ! empty( $query['has_more'] ) && is_string( $query['next_cursor'] ?? null ) && $query['next_cursor'] !== '' ) {
			$cursor = $query['next_cursor'];
		}

		return [ 'items' => $items, 'cursor' => $cursor ];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array{items: array<int, array{id: string, title: string, url: string, text: string}>, cursor: ?string}
	 */
	public static function linearIssues( array $body ): array {
		$data   = is_array( $body['data'] ?? null ) ? $body['data'] : [];
		$node   = is_array( $data['team'] ?? null ) ? $data['team'] : ( is_array( $data['project'] ?? null ) ? $data['project'] : [] );
		$issues = is_array( $node['issues'] ?? null ) ? $node['issues'] : [];
		$items  = [];
		foreach ( is_array( $issues['nodes'] ?? null ) ? $issues['nodes'] : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$item = self::linearIssue( $row );
			if ( $item['id'] !== '' && ( $item['title'] !== '' || $item['text'] !== '' ) ) {
				$items[] = $item;
			}
		}
		$page   = is_array( $issues['pageInfo'] ?? null ) ? $issues['pageInfo'] : [];
		$cursor = null;
		if ( ! empty( $page['hasNextPage'] ) && is_string( $page['endCursor'] ?? null ) && $page['endCursor'] !== '' ) {
			$cursor = $page['endCursor'];
		}

		return [ 'items' => $items, 'cursor' => $cursor ];
	}

	public static function hash( string $text ): string {
		return hash( 'sha256', $text );
	}

	/**
	 * The line an agent sees. The link and the read date are first, so a
	 * short excerpt still cites the source.
	 *
	 * @param array{id?: string, title?: string, url?: string, text?: string} $item
	 */
	public static function cite( array $item, string $readAt ): string {
		$url   = (string) ( $item['url'] ?? '' );
		$date  = substr( $readAt, 0, 10 );
		$title = trim( (string) ( $item['title'] ?? '' ) );
		$text  = trim( (string) ( $item['text'] ?? '' ) );
		$head  = $url . ' read ' . $date . '.';
		if ( $title !== '' ) {
			$head .= ' ' . $title . '.';
		}
		$body = trim( $head . ( $text !== '' ? ' ' . $text : '' ) );

		return self::clip( $body );
	}

	/**
	 * A count, the scope name, and a few titles. Not a stored copy.
	 *
	 * @param array<int, array{title?: string}> $items
	 * @return array{count: int, scope: string, titles: array<int, string>, truncated: bool, stored: bool}
	 */
	public static function summary( array $items, string $scopeName, bool $truncated ): array {
		$titles = [];
		foreach ( $items as $item ) {
			if ( count( $titles ) >= 5 ) {
				break;
			}
			$title = trim( (string) ( $item['title'] ?? '' ) );
			if ( $title !== '' ) {
				$titles[] = $title;
			}
		}

		return [
			'count'     => count( $items ),
			'scope'     => $scopeName,
			'titles'    => $titles,
			'truncated' => $truncated,
			'stored'    => false,
		];
	}

	/**
	 * @param array{count: int, scope: string, truncated?: bool} $summary
	 */
	public static function previewWords( array $summary, string $service ): string {
		$n    = (int) $summary['count'];
		$what = $service === self::LINEAR
			? ( $n === 1 ? 'issue' : 'issues' )
			: ( $n === 1 ? 'page' : 'pages' );
		$line = $n . ' ' . $what . ' in ' . (string) $summary['scope'] . '.';
		if ( ! empty( $summary['truncated'] ) ) {
			$line .= ' There are more; this read stopped early and will not remove anything.';
		}

		return $line . ' Nothing stored yet.';
	}

	/**
	 * Store is allowed only after a preview, and only into the scope that
	 * preview named. A posted scope is ignored.
	 *
	 * @param array<string, mixed> $pick
	 * @return array<string, mixed>|null
	 */
	public static function storeTarget( array $pick ): ?array {
		if ( ( $pick['status'] ?? '' ) !== 'preview' ) {
			return null;
		}
		if ( (int) ( $pick['scope_id'] ?? 0 ) <= 0 ) {
			return null;
		}
		if ( ! is_array( $pick['summary'] ?? null ) ) {
			return null;
		}

		return $pick;
	}

	/**
	 * What a refresh should write and what it should drop. A truncated
	 * read never drops: an item we did not see may still be there.
	 *
	 * @param array<int, array{id: string, title: string, url: string, text: string}> $items
	 * @param array<string, array<string, mixed>> $copies
	 * @return array{upsert: array<int, array<string, mixed>>, remove: array<int, array<string, mixed>>}
	 */
	public static function diff( array $items, array $copies, int $scopeId, bool $complete ): array {
		$seen   = [];
		$upsert = [];
		foreach ( $items as $item ) {
			$id = $item['id'];
			if ( $id === '' ) {
				continue;
			}
			$seen[ $id ] = true;
			$prev        = is_array( $copies[ $id ] ?? null ) ? $copies[ $id ] : [];
			$hash        = self::hash( $item['text'] );
			$upsert[]    = [
				'id'        => $id,
				'item'      => $item,
				'scope_id'  => $scopeId,
				'memory_id' => (int) ( $prev['memory_id'] ?? 0 ),
				'source_id' => (int) ( $prev['source_id'] ?? 0 ),
				'hash'      => $hash,
				'changed'   => $prev === [] || (string) ( $prev['hash'] ?? '' ) !== $hash,
			];
		}
		$remove = [];
		if ( $complete ) {
			foreach ( $copies as $id => $copy ) {
				if ( isset( $seen[ $id ] ) || ! is_array( $copy ) ) {
					continue;
				}
				$remove[] = [
					'id'        => (string) $id,
					'scope_id'  => $scopeId,
					'memory_id' => (int) ( $copy['memory_id'] ?? 0 ),
				];
			}
		}

		return [ 'upsert' => $upsert, 'remove' => $remove ];
	}

	/**
	 * Copies an agent may see: only those in a scope it reads.
	 *
	 * @param array<int, array<string, mixed>> $copies
	 * @param array<int, int> $allowedScopeIds
	 * @return array<int, array<string, mixed>>
	 */
	public static function visible( array $copies, array $allowedScopeIds ): array {
		$allowed = [];
		foreach ( $allowedScopeIds as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$allowed[ $id ] = true;
			}
		}
		$out = [];
		foreach ( $copies as $copy ) {
			$scope = (int) ( $copy['scope_id'] ?? 0 );
			if ( $scope > 0 && isset( $allowed[ $scope ] ) ) {
				$out[] = $copy;
			}
		}

		return $out;
	}

	public static function httpWords( string $service, int $status ): string {
		$name = $service === self::LINEAR ? 'Linear' : 'Notion';
		if ( $status === 0 ) {
			return $name . ' did not answer. Try again in a minute.';
		}
		if ( $status === 401 || $status === 403 ) {
			return $name . ' refused the key. Check it, and that what you picked is shared with the integration.';
		}
		if ( $status === 404 ) {
			return $name . ' could not find that page, database, team or project.';
		}
		if ( $status === 429 ) {
			return $name . ' asked us to slow down. Try again in a minute.';
		}

		return $name . ' did not accept the read (HTTP ' . $status . ').';
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id: string, title: string, url: string, text: string}
	 */
	private static function notionRow( array $row ): array {
		$id    = self::notionId( (string) ( $row['id'] ?? '' ) );
		$title = self::pageTitle( $row );
		$bits  = [];
		foreach ( is_array( $row['properties'] ?? null ) ? $row['properties'] : [] as $prop ) {
			if ( ! is_array( $prop ) ) {
				continue;
			}
			$line = self::propertyText( $prop );
			if ( $line !== '' ) {
				$bits[] = $line;
			}
		}

		return [
			'id'    => $id,
			'title' => $title,
			'url'   => self::notionUrl( $row, $id ),
			'text'  => self::clip( trim( implode( "\n", $bits ) ) ),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id: string, title: string, url: string, text: string}
	 */
	private static function linearIssue( array $row ): array {
		$id    = trim( (string) ( $row['id'] ?? '' ) );
		$title = trim( (string) ( $row['title'] ?? '' ) );
		$ident = trim( (string) ( $row['identifier'] ?? '' ) );
		if ( $ident !== '' && $title !== '' ) {
			$title = $ident . ' ' . $title;
		} elseif ( $ident !== '' ) {
			$title = $ident;
		}
		$desc = trim( (string) ( $row['description'] ?? '' ) );
		$url  = (string) ( $row['url'] ?? '' );
		if ( ! self::linearUrlOk( $url ) ) {
			$url = $ident !== '' ? 'https://linear.app/issue/' . rawurlencode( $ident ) : '';
		}

		return [
			'id'    => $id,
			'title' => $title,
			'url'   => $url,
			'text'  => self::clip( trim( $title . ( $desc !== '' ? "\n" . $desc : '' ) ) ),
		];
	}

	/**
	 * @param array<string, mixed> $page
	 */
	private static function pageTitle( array $page ): string {
		$props = is_array( $page['properties'] ?? null ) ? $page['properties'] : [];
		foreach ( $props as $prop ) {
			if ( is_array( $prop ) && ( $prop['type'] ?? '' ) === 'title' ) {
				return self::rich( is_array( $prop['title'] ?? null ) ? $prop['title'] : [] );
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $prop
	 */
	private static function propertyText( array $prop ): string {
		$type = (string) ( $prop['type'] ?? '' );
		if ( $type === 'title' || $type === 'rich_text' ) {
			$list = $prop[ $type ] ?? null;

			return self::rich( is_array( $list ) ? $list : [] );
		}
		if ( $type === 'select' || $type === 'status' ) {
			$node = $prop[ $type ] ?? null;

			return is_array( $node ) ? trim( (string) ( $node['name'] ?? '' ) ) : '';
		}
		if ( $type === 'multi_select' ) {
			$names = [];
			foreach ( is_array( $prop['multi_select'] ?? null ) ? $prop['multi_select'] : [] as $node ) {
				if ( is_array( $node ) && trim( (string) ( $node['name'] ?? '' ) ) !== '' ) {
					$names[] = trim( (string) $node['name'] );
				}
			}

			return implode( ', ', $names );
		}
		if ( $type === 'number' && isset( $prop['number'] ) && ( is_int( $prop['number'] ) || is_float( $prop['number'] ) ) ) {
			return (string) $prop['number'];
		}
		if ( $type === 'url' || $type === 'email' ) {
			return is_string( $prop[ $type ] ?? null ) ? trim( $prop[ $type ] ) : '';
		}
		if ( $type === 'date' ) {
			$node = $prop['date'] ?? null;

			return is_array( $node ) ? trim( (string) ( $node['start'] ?? '' ) ) : '';
		}
		if ( $type === 'checkbox' ) {
			return ! empty( $prop['checkbox'] ) ? 'yes' : '';
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function blockText( array $block ): string {
		$type = (string) ( $block['type'] ?? '' );
		if ( $type === 'child_page' ) {
			$node = $block['child_page'] ?? null;

			return is_array( $node ) ? trim( (string) ( $node['title'] ?? '' ) ) : '';
		}
		$payload = $block[ $type ] ?? null;
		if ( ! is_array( $payload ) ) {
			return '';
		}
		if ( isset( $payload['rich_text'] ) && is_array( $payload['rich_text'] ) ) {
			return self::rich( $payload['rich_text'] );
		}

		return '';
	}

	/**
	 * @param array<int, mixed> $parts
	 */
	private static function rich( array $parts ): string {
		$out = '';
		foreach ( $parts as $part ) {
			if ( is_array( $part ) && is_string( $part['plain_text'] ?? null ) ) {
				$out .= $part['plain_text'];
			}
		}

		return trim( $out );
	}

	/**
	 * @param array<string, mixed> $page
	 */
	private static function notionUrl( array $page, string $id ): string {
		$url = (string) ( $page['url'] ?? '' );
		if ( self::notionUrlOk( $url ) ) {
			return $url;
		}

		return $id !== '' ? 'https://www.notion.so/' . $id : '';
	}

	private static function notionUrlOk( string $url ): bool {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		return $host === 'www.notion.so' || $host === 'notion.so' || str_ends_with( $host, '.notion.site' );
	}

	private static function linearUrlOk( string $url ): bool {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		return $host === 'linear.app' || str_ends_with( $host, '.linear.app' );
	}

	private static function clip( string $text ): string {
		if ( mb_strlen( $text ) <= 8000 ) {
			return $text;
		}

		return mb_substr( $text, 0, 7999 ) . '…';
	}
}
