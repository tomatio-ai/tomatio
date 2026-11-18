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
		if ( $op === 'create_service' && isset( $fields['status'] ) ) {
			$state = self::lookup( is_array( $target['states'] ?? null ) ? $target['states'] : [], Rules::toService( (string) $fields['status'] ) );
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
		$props    = self::notionProperties( $fields, $mapping, $types, $op === 'create_service' );
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
	 * @return array<string, mixed>
	 */
	private static function notionProperties( array $fields, array $mapping, array $types, bool $create ): array {
		$props = [];
		$pairs = [
			'title'   => (string) ( $fields['title'] ?? '' ),
			'detail'  => (string) ( $fields['detail'] ?? '' ),
			'release' => (string) ( $fields['release'] ?? '' ),
			'rank'    => (string) ( $fields['rank'] ?? '' ),
			'owner'   => (string) ( $fields['owner'] ?? '' ),
		];
		if ( $create && isset( $fields['status'] ) ) {
			$pairs['status'] = Rules::toService( (string) $fields['status'] );
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
			return [ 'title' => [ [ 'text' => [ 'content' => $value ] ] ] ];
		}
		if ( $type === 'status' || $type === 'select' ) {
			return [ $type => [ 'name' => $value ] ];
		}

		return [ 'rich_text' => [ [ 'text' => [ 'content' => $value ] ] ] ];
	}

	/** @param array<mixed> $map */
	private static function lookup( array $map, string $name ): string {
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
