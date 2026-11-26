<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

/**
 * #992: turn a Linear or Notion answer into planning records, and build
 * the read requests. A request here is a read. Writes live in Write.
 */
final class Remote {

	private const LINEAR = 'https://api.linear.app/graphql';

	private const NOTION = 'https://api.notion.com/v1';

	private const ISSUES = 'query ($id: String!, $after: String) { team(id: $id) { issues(first: 50, after: $after) { nodes { id identifier title description url updatedAt state { name } assignee { name } project { id name } cycle { id name } } pageInfo { hasNextPage endCursor } } } }';

	private const PROJECT_ISSUES = 'query ($id: String!, $after: String) { project(id: $id) { name issues(first: 50, after: $after) { nodes { id identifier title description url updatedAt state { name } assignee { name } project { id name } cycle { id name } } pageInfo { hasNextPage endCursor } } } }';

	private const TEAM_META = 'query ($id: String!) { team(id: $id) { id projects { nodes { id name } } cycles { nodes { id name } } states { nodes { id name } } } }';

	/**
	 * @return array{method: string, url: string, body: array<string, mixed>}
	 */
	public static function linearIssues( string $id, ?string $after, bool $project ): array {
		return [
			'method' => 'POST',
			'url'    => self::LINEAR,
			'body'   => [
				'query'     => $project ? self::PROJECT_ISSUES : self::ISSUES,
				'variables' => [ 'id' => $id, 'after' => $after ],
			],
		];
	}

	/**
	 * @return array{method: string, url: string, body: array<string, mixed>}
	 */
	public static function linearMeta( string $teamId ): array {
		return [
			'method' => 'POST',
			'url'    => self::LINEAR,
			'body'   => [
				'query'     => self::TEAM_META,
				'variables' => [ 'id' => $teamId ],
			],
		];
	}

	/**
	 * @return array{method: string, url: string, body: array<string, mixed>|null}
	 */
	public static function notionDatabase( string $id ): array {
		return [
			'method' => 'GET',
			'url'    => self::NOTION . '/databases/' . $id,
			'body'   => null,
		];
	}

	/**
	 * @return array{method: string, url: string, body: array<string, mixed>}
	 */
	public static function notionQuery( string $id, ?string $cursor ): array {
		$body = [ 'page_size' => 100 ];
		if ( $cursor !== null && $cursor !== '' ) {
			$body['start_cursor'] = $cursor;
		}

		return [
			'method' => 'POST',
			'url'    => self::NOTION . '/databases/' . $id . '/query',
			'body'   => $body,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{records: array<int, array<string, mixed>>, cursor: string, more: bool}
	 */
	public static function linearPage( array $payload, string $releaseVia ): array {
		$data  = is_array( $payload['data'] ?? null ) ? $payload['data'] : [];
		$node  = is_array( $data['team'] ?? null ) ? $data['team'] : ( is_array( $data['project'] ?? null ) ? $data['project'] : [] );
		$issues = is_array( $node['issues'] ?? null ) ? $node['issues'] : [];
		$nodes  = is_array( $issues['nodes'] ?? null ) ? $issues['nodes'] : [];
		$info   = is_array( $issues['pageInfo'] ?? null ) ? $issues['pageInfo'] : [];
		$out    = [];
		foreach ( $nodes as $nodeRow ) {
			if ( ! is_array( $nodeRow ) ) {
				continue;
			}
			$parsed = self::linearIssue( $nodeRow, $releaseVia );
			if ( $parsed !== null ) {
				$out[] = $parsed;
			}
		}

		return [
			'records' => $out,
			'cursor'  => is_string( $info['endCursor'] ?? null ) ? $info['endCursor'] : '',
			'more'    => ! empty( $info['hasNextPage'] ),
		];
	}

	/**
	 * @param array<string, mixed> $issue
	 * @return array<string, mixed>|null
	 */
	public static function linearIssue( array $issue, string $releaseVia ): ?array {
		$id = is_string( $issue['id'] ?? null ) ? $issue['id'] : '';
		if ( $id === '' ) {
			return null;
		}
		$state    = is_array( $issue['state'] ?? null ) ? (string) ( $issue['state']['name'] ?? '' ) : '';
		$mapped   = Rules::toBoard( $state );
		$assignee = is_array( $issue['assignee'] ?? null ) ? (string) ( $issue['assignee']['name'] ?? '' ) : '';
		$project  = is_array( $issue['project'] ?? null ) ? $issue['project'] : [];
		$cycle    = is_array( $issue['cycle'] ?? null ) ? $issue['cycle'] : [];
		$release  = $releaseVia === 'cycle'
			? (string) ( $cycle['name'] ?? '' )
			: (string) ( $project['name'] ?? '' );

		return [
			'id'               => $id,
			'title'            => is_string( $issue['title'] ?? null ) ? $issue['title'] : '',
			'detail'           => is_string( $issue['description'] ?? null ) ? $issue['description'] : '',
			'status'           => $mapped,
			'unmapped_status'  => $state !== '' && $mapped === '',
			'owner'            => $assignee,
			'release'          => $release,
			'updated_at'       => is_string( $issue['updatedAt'] ?? null ) ? $issue['updatedAt'] : '',
			'url'              => is_string( $issue['url'] ?? null ) ? $issue['url'] : '',
			'alias'            => is_string( $issue['identifier'] ?? null ) ? $issue['identifier'] : '',
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{projects: array<string, string>, cycles: array<string, string>, states: array<string, string>, team_id: string}
	 */
	public static function linearMaps( array $payload ): array {
		$data = is_array( $payload['data'] ?? null ) ? $payload['data'] : [];
		$team = is_array( $data['team'] ?? null ) ? $data['team'] : [];

		return [
			'projects' => self::nameMap( $team['projects'] ?? null ),
			'cycles'   => self::nameMap( $team['cycles'] ?? null ),
			'states'   => self::nameMap( $team['states'] ?? null ),
			'team_id'  => is_string( $team['id'] ?? null ) ? $team['id'] : '',
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, string> property name => type
	 */
	public static function notionTypes( array $payload ): array {
		$props = is_array( $payload['properties'] ?? null ) ? $payload['properties'] : [];
		$out   = [];
		foreach ( $props as $name => $prop ) {
			if ( is_string( $name ) && is_array( $prop ) && is_string( $prop['type'] ?? null ) ) {
				$out[ $name ] = $prop['type'];
			}
		}

		return $out;
	}

	/**
	 * B237: the option names of a status or select property, so a status
	 * written on an update names an option the database has.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, string> option name => option name
	 */
	public static function notionOptions( array $payload, string $name ): array {
		$props = is_array( $payload['properties'] ?? null ) ? $payload['properties'] : [];
		$prop  = $name !== '' && is_array( $props[ $name ] ?? null ) ? $props[ $name ] : [];
		$type  = is_string( $prop['type'] ?? null ) ? $prop['type'] : '';
		if ( $type !== 'status' && $type !== 'select' ) {
			return [];
		}
		$config = is_array( $prop[ $type ] ?? null ) ? $prop[ $type ] : [];
		$out    = [];
		foreach ( is_array( $config['options'] ?? null ) ? $config['options'] : [] as $option ) {
			if ( is_array( $option ) && is_string( $option['name'] ?? null ) && $option['name'] !== '' ) {
				$out[ $option['name'] ] = $option['name'];
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, string> $mapping
	 * @return array{records: array<int, array<string, mixed>>, cursor: string, more: bool}
	 */
	public static function notionPage( array $payload, array $mapping ): array {
		$results = is_array( $payload['results'] ?? null ) ? $payload['results'] : [];
		$out     = [];
		foreach ( $results as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$parsed = self::notionRow( $page, $mapping );
			if ( $parsed !== null ) {
				$out[] = $parsed;
			}
		}

		return [
			'records' => $out,
			'cursor'  => is_string( $payload['next_cursor'] ?? null ) ? $payload['next_cursor'] : '',
			'more'    => ! empty( $payload['has_more'] ),
		];
	}

	/**
	 * @param array<string, mixed>  $page
	 * @param array<string, string> $mapping
	 * @return array<string, mixed>|null
	 */
	public static function notionRow( array $page, array $mapping ): ?array {
		$id = is_string( $page['id'] ?? null ) ? strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', $page['id'] ) ) : '';
		if ( strlen( $id ) < 32 ) {
			return null;
		}
		$id = substr( $id, -32 );
		$props  = is_array( $page['properties'] ?? null ) ? $page['properties'] : [];
		$status = self::notionText( $props, (string) ( $mapping['status'] ?? 'Status' ) );
		$mapped = Rules::toBoard( $status );

		return [
			'id'              => $id,
			'title'           => self::notionText( $props, (string) ( $mapping['title'] ?? 'Name' ) ),
			'detail'          => self::notionText( $props, (string) ( $mapping['detail'] ?? 'Detail' ) ),
			'status'          => $mapped,
			'unmapped_status' => $status !== '' && $mapped === '',
			'owner'           => self::notionText( $props, (string) ( $mapping['owner'] ?? 'Owner' ) ),
			'rank'            => self::notionText( $props, (string) ( $mapping['rank'] ?? 'Rank' ) ),
			'release'         => self::notionText( $props, (string) ( $mapping['release'] ?? 'Release' ) ),
			'updated_at'      => is_string( $page['last_edited_time'] ?? null ) ? $page['last_edited_time'] : '',
			'url'             => is_string( $page['url'] ?? null ) ? $page['url'] : '',
			'alias'           => '',
		];
	}

	/**
	 * @param array<string, mixed> $props
	 */
	public static function notionText( array $props, string $name ): string {
		if ( $name === '' || ! isset( $props[ $name ] ) || ! is_array( $props[ $name ] ) ) {
			return '';
		}
		$prop = $props[ $name ];
		$type = is_string( $prop['type'] ?? null ) ? $prop['type'] : '';
		if ( $type === 'title' || $type === 'rich_text' ) {
			return self::plain( is_array( $prop[ $type ] ?? null ) ? $prop[ $type ] : [] );
		}
		if ( $type === 'status' || $type === 'select' ) {
			$picked = is_array( $prop[ $type ] ?? null ) ? $prop[ $type ] : [];

			return is_string( $picked['name'] ?? null ) ? $picked['name'] : '';
		}
		if ( $type === 'people' ) {
			$names = [];
			foreach ( is_array( $prop['people'] ?? null ) ? $prop['people'] : [] as $person ) {
				if ( is_array( $person ) && is_string( $person['name'] ?? null ) && $person['name'] !== '' ) {
					$names[] = $person['name'];
				}
			}

			return implode( ', ', $names );
		}

		return '';
	}

	/** @param mixed $connection
	 * @return array<string, string>
	 */
	private static function nameMap( $connection ): array {
		$nodes = is_array( $connection ) && is_array( $connection['nodes'] ?? null ) ? $connection['nodes'] : [];
		$out   = [];
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && is_string( $node['name'] ?? null ) && is_string( $node['id'] ?? null ) && $node['name'] !== '' ) {
				$out[ $node['name'] ] = $node['id'];
			}
		}

		return $out;
	}

	/** @param array<int, mixed> $parts */
	private static function plain( array $parts ): string {
		$text = '';
		foreach ( $parts as $part ) {
			if ( is_array( $part ) && is_string( $part['plain_text'] ?? null ) ) {
				$text .= $part['plain_text'];
			}
		}

		return $text;
	}
}
