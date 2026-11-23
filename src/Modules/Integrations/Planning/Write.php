<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

/**
 * #992: write requests for Linear and Notion. A request this class
 * builds is a create or an update, or it is refused. Nothing here
 * deletes, archives or unlinks a row on the service.
 */
final class Write {

	/**
	 * @param array<int, array<string, mixed>> $actions
	 * @param array<string, mixed>             $target id, release_via, projects, cycles, states, mapping, types
	 * @return array<int, array{method: string, url: string, body: array<string, mixed>, action: int}>
	 */
	public static function requests( string $service, array $actions, array $target ): array {
		$out = [];
		foreach ( $actions as $index => $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$op = (string) ( $action['op'] ?? '' );
			if ( $op !== 'create_service' && $op !== 'update_service' ) {
				continue;
			}
			$request = $service === 'linear'
				? self::linear( $op, $action, $target )
				: self::notion( $op, $action, $target );
			if ( $request === null || self::forbidden( $request ) ) {
				continue;
			}
			$request['action'] = $index;
			$out[]             = $request;
		}

		return $out;
	}

	/**
	 * @param array{method?: string, url?: string, body?: mixed} $request
	 */
	public static function forbidden( array $request ): bool {
		$method = strtoupper( (string) ( $request['method'] ?? '' ) );
		if ( $method === 'DELETE' || $method === '' ) {
			return true;
		}
		$url = strtolower( (string) ( $request['url'] ?? '' ) );
		if ( str_contains( $url, 'api.linear.app' ) === false && str_contains( $url, 'api.notion.com' ) === false ) {
			return true;
		}
		$json = json_encode( $request['body'] ?? [] );
		$text = strtolower( is_string( $json ) ? $json : '' );
		foreach ( [ 'issuedelete', 'issuearchive', 'projectdelete', 'archived":true', '"archived":true' ] as $needle ) {
			if ( str_contains( $text, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * B232: the request that makes a Linear project for a release the
	 * team does not have yet. Null when there is no team to put it in.
	 *
	 * @param array<string, mixed> $target
	 * @return array{method: string, url: string, body: array<string, mixed>}|null
	 */
	public static function project( string $name, array $target ): ?array {
		$name = trim( $name );
		$team = (string) ( $target['team_id'] ?? '' );
		if ( $team === '' && (string) ( $target['kind'] ?? 'team' ) !== 'project' ) {
			$team = (string) ( $target['id'] ?? '' );
		}
		if ( $name === '' || $team === '' ) {
			return null;
		}

		return [
			'method' => 'POST',
			'url'    => 'https://api.linear.app/graphql',
			'body'   => [
				'query'     => 'mutation ($input: ProjectCreateInput!) { projectCreate(input: $input) { success project { id name } } }',
				'variables' => [ 'input' => [ 'name' => $name, 'teamIds' => [ $team ] ] ],
			],
		];
	}

	/**
	 * B236: the request that makes a new Notion backlog database under a
	 * page the connection can see. Its columns are the default field
	 * names (Rules::defaults), Status offers the statuses sync writes,
	 * and Release one option per release on the board. Null when the
	 * page id is not one.
	 *
	 * @param array<int, string> $releases
	 * @return array{method: string, url: string, body: array<string, mixed>}|null
	 */
	public static function notionDatabase( string $parentPage, string $title, array $releases ): ?array {
		$page = strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', $parentPage ) );
		if ( strlen( $page ) !== 32 ) {
			return null;
		}
		$title = trim( (string) preg_replace( '/\s+/', ' ', $title ) );
		if ( $title === '' ) {
			$title = 'TomaTío backlog';
		}
		$names    = Rules::defaults( 'notion' );
		$statuses = [];
		foreach ( array_values( array_unique( Rules::TO_SERVICE ) ) as $status ) {
			$statuses[] = [ 'name' => $status ];
		}
		$options = [];
		foreach ( $releases as $release ) {
			// A select option cannot hold a comma, and Notion takes up to 100.
			$release = trim( str_replace( ',', ' ', (string) $release ) );
			if ( $release !== '' && ! isset( $options[ $release ] ) && count( $options ) < 100 ) {
				$options[ $release ] = [ 'name' => $release ];
			}
		}

		return [
			'method' => 'POST',
			'url'    => 'https://api.notion.com/v1/databases',
			'body'   => [
				'parent'     => [ 'type' => 'page_id', 'page_id' => $page ],
				'title'      => [ [ 'type' => 'text', 'text' => [ 'content' => $title ] ] ],
				'properties' => [
					$names['title']   => [ 'title' => new \stdClass() ],
					$names['detail']  => [ 'rich_text' => new \stdClass() ],
					$names['status']  => [ 'select' => [ 'options' => $statuses ] ],
					$names['owner']   => [ 'rich_text' => new \stdClass() ],
					$names['rank']    => [ 'rich_text' => new \stdClass() ],
					$names['release'] => [ 'select' => [ 'options' => array_values( $options ) ] ],
				],
			],
		];
	}

	/**
	 * B236: the bare id and link of the database a create made.
	 *
	 * @return array{id: string, url: string}
	 */
	public static function createdDatabase( string $body ): array {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || ( $decoded['object'] ?? '' ) !== 'database' || ! is_string( $decoded['id'] ?? null ) ) {
			return [ 'id' => '', 'url' => '' ];
		}
		$id  = strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', $decoded['id'] ) );
		$url = is_string( $decoded['url'] ?? null ) && str_starts_with( $decoded['url'], 'https://' ) ? $decoded['url'] : '';

		return strlen( $id ) === 32 ? [ 'id' => $id, 'url' => $url ] : [ 'id' => '', 'url' => '' ];
	}

	/**
	 * The id of the project a projectCreate made, or ''.
	 */
	public static function createdProject( string $body ): string {
		$decoded = json_decode( $body, true );
		$data    = is_array( $decoded ) && is_array( $decoded['data'] ?? null ) ? $decoded['data'] : [];
		$made    = is_array( $data['projectCreate'] ?? null ) ? $data['projectCreate'] : [];
		$project = is_array( $made['project'] ?? null ) ? $made['project'] : [];

		return is_string( $project['id'] ?? null ) ? $project['id'] : '';
	}

	/**
	 * The id a create returned, or '' when the answer is not a create.
	 */
	public static function createdId( string $service, string $body ): string {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		if ( $service === 'linear' ) {
			$data = is_array( $decoded['data'] ?? null ) ? $decoded['data'] : [];
			$made = is_array( $data['issueCreate'] ?? null ) ? $data['issueCreate'] : [];
			$issue = is_array( $made['issue'] ?? null ) ? $made['issue'] : [];

			return is_string( $issue['id'] ?? null ) ? $issue['id'] : '';
		}

		return is_string( $decoded['id'] ?? null ) ? $decoded['id'] : '';
	}

	/**
	 * @param array<string, mixed> $action
	 * @param array<string, mixed> $target
	 * @return array{method: string, url: string, body: array<string, mixed>}|null
	 */
	private static function linear( string $op, array $action, array $target ): ?array {
		$fields = is_array( $action['fields'] ?? null ) ? $action['fields'] : [];
		$input  = [];
		if ( $op === 'create_service' ) {
			$team = (string) ( $target['id'] ?? '' );
			if ( $team === '' || (string) ( $target['kind'] ?? 'team' ) === 'project' ) {
				$team = (string) ( $target['team_id'] ?? $target['id'] ?? '' );
			}
			if ( $team === '' ) {
				return null;
			}
			$input['teamId'] = $team;
			$input['title']  = (string) ( $fields['title'] ?? $action['title'] ?? '' );
			if ( $input['title'] === '' ) {
				return null;
			}
		}
		if ( isset( $fields['title'] ) && $op !== 'create_service' ) {
			$input['title'] = (string) $fields['title'];
		}
		if ( isset( $fields['detail'] ) ) {
			$input['description'] = (string) $fields['detail'];
		}
		$release = (string) ( $fields['release'] ?? '' );
		if ( $release !== '' ) {
			$via = ( $target['release_via'] ?? '' ) === 'cycle' ? 'cycles' : 'projects';
			$map = is_array( $target[ $via ] ?? null ) ? $target[ $via ] : [];
			$id  = self::lookup( $map, $release );
			if ( $id !== '' ) {
				$input[ $via === 'cycles' ? 'cycleId' : 'projectId' ] = $id;
			}
		}
		// B237: status goes on update too, not only on create.
		if ( isset( $fields['status'] ) ) {
			$states = is_array( $target['states'] ?? null ) ? $target['states'] : [];
			$state  = $states === [] ? '' : self::lookup( $states, Rules::serviceName( (string) $fields['status'], $states ) );
			if ( $state !== '' ) {
				$input['stateId'] = $state;
			}
		}
		if ( $input === [] ) {
			return null;
		}
		$mutation = $op === 'create_service'
			? 'mutation ($input: IssueCreateInput!) { issueCreate(input: $input) { success issue { id url } } }'
			: 'mutation ($id: String!, $input: IssueUpdateInput!) { issueUpdate(id: $id, input: $input) { success issue { id } } }';
		$vars = $op === 'create_service'
			? [ 'input' => $input ]
			: [ 'id' => (string) ( $action['remote_id'] ?? '' ), 'input' => $input ];
		if ( $op !== 'create_service' && $vars['id'] === '' ) {
			return null;
		}

		return [
			'method' => 'POST',
			'url'    => 'https://api.linear.app/graphql',
			'body'   => [ 'query' => $mutation, 'variables' => $vars ],
		];
	}

	/**
	 * @param array<string, mixed> $action
	 * @param array<string, mixed> $target
	 * @return array{method: string, url: string, body: array<string, mixed>}|null
	 */
	private static function notion( string $op, array $action, array $target ): ?array {
		$database = (string) ( $target['id'] ?? '' );
		$fields   = is_array( $action['fields'] ?? null ) ? $action['fields'] : [];
		$mapping  = is_array( $target['mapping'] ?? null ) ? $target['mapping'] : Rules::defaults( 'notion' );
		$types    = is_array( $target['types'] ?? null ) ? $target['types'] : [];
		$states   = is_array( $target['states'] ?? null ) ? $target['states'] : [];
		$props    = self::notionProperties( $fields, $mapping, $types, $states );
		if ( $props === [] ) {
			return null;
		}
		if ( $op === 'create_service' ) {
			if ( $database === '' ) {
				return null;
			}

			return [
				'method' => 'POST',
				'url'    => 'https://api.notion.com/v1/pages',
				'body'   => [
					'parent'     => [ 'database_id' => $database ],
					'properties' => $props,
				],
			];
		}
		$id = (string) ( $action['remote_id'] ?? '' );
		if ( $id === '' ) {
			return null;
		}

		return [
			'method' => 'PATCH',
			'url'    => 'https://api.notion.com/v1/pages/' . $id,
			'body'   => [ 'properties' => $props ],
		];
	}

	/**
	 * @param array<string, mixed>  $fields
	 * @param array<string, mixed>  $mapping
	 * @param array<string, mixed>  $types
	 * @param array<int|string, mixed> $states The status property's options, when known.
	 * @return array<string, mixed>
	 */
	private static function notionProperties( array $fields, array $mapping, array $types, array $states ): array {
		$props = [];
		$pairs = [
			'title'   => (string) ( $fields['title'] ?? '' ),
			'detail'  => (string) ( $fields['detail'] ?? '' ),
			'release' => (string) ( $fields['release'] ?? '' ),
			'rank'    => (string) ( $fields['rank'] ?? '' ),
			'owner'   => (string) ( $fields['owner'] ?? '' ),
		];
		// B237: status goes on update too, named as the database names it.
		if ( isset( $fields['status'] ) ) {
			$pairs['status'] = Rules::serviceName( (string) $fields['status'], $states );
			$statusName      = is_string( $mapping['status'] ?? null ) ? $mapping['status'] : '';
			if ( $pairs['status'] === '' && ( $types[ $statusName ] ?? '' ) !== 'status' ) {
				// A select (or text) property takes a new name; only a status property refuses one.
				$pairs['status'] = Rules::toService( (string) $fields['status'] );
			}
		}
		foreach ( $pairs as $field => $value ) {
			if ( $value === '' && $field !== 'title' ) {
				continue;
			}
			if ( $field === 'title' && $value === '' ) {
				continue;
			}
			$name = is_string( $mapping[ $field ] ?? null ) ? $mapping[ $field ] : '';
			if ( $name === '' ) {
				continue;
			}
			$type = is_string( $types[ $name ] ?? null ) ? $types[ $name ] : ( $field === 'title' ? 'title' : 'rich_text' );
			$props[ $name ] = self::notionValue( $type, $value );
		}

		return $props;
	}

	/** @return array<string, mixed> */
	private static function notionValue( string $type, string $value ): array {
		if ( $type === 'title' ) {
			return [ 'title' => self::notionText( $value ) ];
		}
		if ( $type === 'status' || $type === 'select' ) {
			return [ $type => [ 'name' => $value ] ];
		}

		return [ 'rich_text' => self::notionText( $value ) ];
	}

	/** Notion's limit for one text object, counted in UTF-16 code units as Notion counts. */
	public const NOTION_TEXT_MAX = 2000;

	/** Notion's limit on text objects in one rich text value. */
	public const NOTION_PARTS_MAX = 100;

	/**
	 * Text as Notion rich text: one text object per 2,000 UTF-16 code units
	 * (a longer one is refused with HTTP 400), at most 100 of them. Reading
	 * joins the parts again (Remote::plain), so the text comes back whole.
	 *
	 * @return list<array{text: array{content: string}}>
	 */
	public static function notionText( string $value ): array {
		$parts = [];
		$chunk = '';
		$units = 0;
		$chars = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( is_array( $chars ) ? $chars : str_split( $value ) as $char ) {
			$size = strlen( $char ) === 4 ? 2 : 1;
			if ( $units + $size > self::NOTION_TEXT_MAX ) {
				$parts[] = [ 'text' => [ 'content' => $chunk ] ];
				if ( count( $parts ) === self::NOTION_PARTS_MAX ) {
					return $parts;
				}
				$chunk = '';
				$units = 0;
			}
			$chunk .= $char;
			$units += $size;
		}
		$parts[] = [ 'text' => [ 'content' => $chunk ] ];

		return $parts;
	}

	/**
	 * Why a write failed, in words a person can act on: the item, the service
	 * and the service's own message (Notion's "message", Linear's first error).
	 *
	 * @param array{status: int, body: mixed} $sent
	 */
	public static function failure( string $service, array $sent, string $title ): string {
		$name = $service === 'linear' ? 'Linear' : 'Notion';
		$body = $sent['body'];
		if ( is_string( $body ) ) {
			$decoded = json_decode( $body, true );
			$body    = is_array( $decoded ) ? $decoded : [];
		}
		$message = '';
		if ( is_array( $body ) ) {
			if ( is_string( $body['message'] ?? null ) ) {
				$message = $body['message'];
			} elseif ( is_array( $body['errors'][0] ?? null ) && is_string( $body['errors'][0]['message'] ?? null ) ) {
				$message = $body['errors'][0]['message'];
			}
		}
		$message = trim( (string) preg_replace( '/\s+/', ' ', $message ) );
		if ( strlen( $message ) > 240 ) {
			$message = substr( $message, 0, 237 ) . '...';
		}
		$title = trim( $title );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $title ) > 60 ) {
			$title = mb_substr( $title, 0, 59 ) . '…';
		}

		return ( $title !== '' ? '“' . $title . '”: ' : '' ) . $name . ' answered ' . (int) $sent['status'] . ( $message !== '' ? ': ' . $message : '' ) . '.';
	}

	/** @param array<mixed> $map */
	public static function lookup( array $map, string $name ): string {
		if ( $name === '' ) {
			return '';
		}
		if ( isset( $map[ $name ] ) && is_string( $map[ $name ] ) ) {
			return $map[ $name ];
		}
		$want = strtolower( $name );
		foreach ( $map as $key => $id ) {
			if ( is_string( $key ) && is_string( $id ) && strtolower( $key ) === $want ) {
				return $id;
			}
		}

		return '';
	}
}
