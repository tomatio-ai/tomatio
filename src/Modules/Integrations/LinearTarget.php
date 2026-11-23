<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

/**
 * B231: what people paste for a Linear team or project, turned into the id
 * Linear's API needs. A UUID is used as it is. A team key (TOM), an issue
 * identifier (TOM-12), a team link (linear.app/org/team/TOM/...), a project
 * link (linear.app/org/project/name-1a2b3c4d5e6f) or a team or project name
 * is looked up with one read through the stored sign-in.
 */
final class LinearTarget {

	private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

	private const LOOKUP = 'query { teams(first: 100) { nodes { id key name } } projects(first: 100) { nodes { id name slugId } } }';

	/**
	 * What the paste looks like, before any lookup.
	 *
	 * @return array{type: string, value: string} type: uuid, key, project, name or '' (empty paste)
	 */
	public static function parse( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return [ 'type' => '', 'value' => '' ];
		}
		if ( preg_match( self::UUID, $raw, $m ) === 1 ) {
			return [ 'type' => 'uuid', 'value' => strtolower( $m[0] ) ];
		}
		if ( preg_match( '#linear\.app/[^/\s]+/project/([^/?\#\s]+)#i', $raw, $m ) === 1 ) {
			$slug = (string) preg_replace( '/^.*-/', '', $m[1] );

			return [ 'type' => 'project', 'value' => strtolower( $slug ) ];
		}
		if ( preg_match( '#linear\.app/[^/\s]+/team/([A-Za-z0-9]+)#i', $raw, $m ) === 1 ) {
			return [ 'type' => 'key', 'value' => strtoupper( $m[1] ) ];
		}
		if ( preg_match( '#linear\.app/[^/\s]+/issue/([A-Za-z][A-Za-z0-9]*)-\d+#i', $raw, $m ) === 1 ) {
			return [ 'type' => 'key', 'value' => strtoupper( $m[1] ) ];
		}
		if ( preg_match( '/^(?:[A-Za-z0-9]+-)*([A-Za-z][A-Za-z0-9]{0,6})-\d+$/', $raw, $m ) === 1 ) {
			return [ 'type' => 'key', 'value' => strtoupper( $m[1] ) ];
		}
		if ( preg_match( '/^[A-Za-z][A-Za-z0-9]{0,6}$/', $raw ) === 1 ) {
			return [ 'type' => 'key', 'value' => strtoupper( $raw ) ];
		}

		return [ 'type' => 'name', 'value' => $raw ];
	}

	/**
	 * @param callable $send fn(string $method, string $url, array $body): array{status: int, body: string}|string
	 * @return array{id: string, kind: string, name: string, error: string} kind: team or project
	 */
	public static function resolve( string $raw, string $kind, callable $send ): array {
		$parsed = self::parse( $raw );
		if ( $parsed['type'] === '' ) {
			return self::fail( 'Paste the Linear team key (like TOM) or a link to the team or project.' );
		}
		if ( $parsed['type'] === 'uuid' ) {
			return [ 'id' => $parsed['value'], 'kind' => $kind === 'project' ? 'project' : 'team', 'name' => '', 'error' => '' ];
		}
		$sent = $send( 'POST', 'https://api.linear.app/graphql', [ 'query' => self::LOOKUP ] );
		if ( is_string( $sent ) ) {
			return self::fail( $sent );
		}
		if ( (int) $sent['status'] < 200 || (int) $sent['status'] >= 300 ) {
			return self::fail( 'Linear did not answer the lookup (HTTP ' . (int) $sent['status'] . '). Nothing was saved.' );
		}
		$data = json_decode( (string) $sent['body'], true );
		$data = is_array( $data ) && is_array( $data['data'] ?? null ) ? $data['data'] : [];

		return self::match( $parsed, $raw, self::nodes( $data['teams'] ?? null ), self::nodes( $data['projects'] ?? null ) );
	}

	/**
	 * @param array{type: string, value: string}    $parsed
	 * @param array<int, array<string, mixed>>      $teams
	 * @param array<int, array<string, mixed>>      $projects
	 * @return array{id: string, kind: string, name: string, error: string}
	 */
	public static function match( array $parsed, string $raw, array $teams, array $projects ): array {
		$want = self::lower( $parsed['value'] );
		if ( $parsed['type'] === 'key' || $parsed['type'] === 'name' ) {
			foreach ( $teams as $team ) {
				if ( self::lower( (string) ( $team['key'] ?? '' ) ) === $want || self::lower( (string) ( $team['name'] ?? '' ) ) === $want ) {
					return self::found( $team, 'team' );
				}
			}
		}
		foreach ( $projects as $project ) {
			$hit = $parsed['type'] === 'project'
				? self::lower( (string) ( $project['slugId'] ?? '' ) ) === $want
				: self::lower( (string) ( $project['name'] ?? '' ) ) === $want;
			if ( $hit ) {
				return self::found( $project, 'project' );
			}
		}
		$keys = array_values( array_filter( array_map( static fn ( array $t ): string => (string) ( $t['key'] ?? '' ), $teams ) ) );
		$hint = $keys === [] ? 'the team key (like TOM)' : 'a team key (' . implode( ', ', array_slice( $keys, 0, 5 ) ) . ')';

		return self::fail( 'Linear has no team or project matching "' . trim( $raw ) . '". Paste ' . $hint . ' or a link to the team or project. Nothing was saved.' );
	}

	/**
	 * @param mixed $list
	 * @return array<int, array<string, mixed>>
	 */
	private static function nodes( $list ): array {
		$nodes = is_array( $list ) && is_array( $list['nodes'] ?? null ) ? $list['nodes'] : [];

		return array_values( array_filter( $nodes, static fn ( $n ): bool => is_array( $n ) && is_string( $n['id'] ?? null ) && $n['id'] !== '' ) );
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array{id: string, kind: string, name: string, error: string}
	 */
	private static function found( array $node, string $kind ): array {
		return [ 'id' => strtolower( (string) $node['id'] ), 'kind' => $kind, 'name' => (string) ( $node['name'] ?? '' ), 'error' => '' ];
	}

	/** @return array{id: string, kind: string, name: string, error: string} */
	private static function fail( string $why ): array {
		return [ 'id' => '', 'kind' => '', 'name' => '', 'error' => $why ];
	}

	private static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $text ) ) : strtolower( trim( $text ) );
	}
}
