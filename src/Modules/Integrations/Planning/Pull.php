<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Planning;

use AgentBrain\Modules\Integrations\Catalog;
use AgentBrain\Modules\Integrations\Client;
use AgentBrain\Modules\Integrations\Context\Fetch;
use AgentBrain\Modules\Integrations\Store;

/**
 * #992: one read of the Linear team or Notion database a planning sync
 * points at. The sender is injected so tests never leave the machine.
 * A partial read is marked truncated and must not unlink what it did
 * not see.
 */
final class Pull {

	public const MAX_CALLS = 24;

	/**
	 * @param array<string, string> $mapping
	 * @param callable              $send
	 * @return array{records: array<int, array<string, mixed>>, projects: array<string, string>, cycles: array<string, string>, states: array<string, string>, types: array<string, string>, team_id: string, truncated: bool}|string
	 */
	public static function read( string $service, string $kind, string $locator, string $releaseVia, array $mapping, callable $send ): array|string {
		if ( $locator === '' ) {
			return 'Paste a team, project or database link or id.';
		}
		$calls = 0;
		if ( $service === 'linear' ) {
			return self::linear( $kind, $locator, $releaseVia, $send, $calls );
		}
		if ( $kind !== 'database' ) {
			return 'Planning sync reads a Notion database, not a page.';
		}

		return self::notion( $locator, $mapping, $send, $calls );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{records: array<int, array<string, mixed>>, projects: array<string, string>, cycles: array<string, string>, states: array<string, string>, types: array<string, string>, team_id: string, truncated: bool}|string
	 */
	public static function live( string $service, array $row ): array|string {
		$spec = ( new Catalog() )->get( $service );
		if ( $spec === null ) {
			return 'That integration is not installed.';
		}
		$token = Store::bearer( $service );
		if ( $token === null || $token === '' ) {
			return (string) $spec['name'] . ' is not connected.';
		}
		$mapping = Rules::mapping( $service, is_array( $row['mapping'] ?? null ) ? $row['mapping'] : [] );

		return self::read(
			$service,
			(string) ( $row['target_kind'] ?? '' ),
			(string) ( $row['target'] ?? '' ),
			(string) ( $row['release_via'] ?? 'project' ),
			$mapping,
			static fn ( string $method, string $url, ?array $body ): array|string => self::dispatch( $service, $method, $url, $body )
		);
	}

	/**
	 * One outbound call. The key stays in the header. A refused host or a
	 * limit comes back as a string, never as a request that left the machine.
	 *
	 * @param array<string, mixed>|null $body
	 * @return array{status: int, body: string}|string
	 */
	public static function dispatch( string $service, string $method, string $url, ?array $body ): array|string {
		$spec = ( new Catalog() )->get( $service );
		if ( $spec === null ) {
			return 'That integration is not installed.';
		}
		$token = Store::bearer( $service );
		if ( $token === null || $token === '' ) {
			return (string) $spec['name'] . ' is not connected.';
		}
		$hosts = is_array( $spec['hosts'] ?? null ) ? $spec['hosts'] : [];
		if ( Catalog::https( $url, $hosts ) === '' ) {
			return 'Refused: that address is not this service.';
		}
		$wait = $method === 'GET' || self::isRead( $body ) ? Store::noteRead( $service ) : 0;
		if ( $wait > 0 ) {
			return (string) $spec['name'] . ' asked us to slow down. Try again in ' . $wait . ' seconds.';
		}
		$headers = Fetch::headers( $service, $token );
		$encoded = null;
		if ( $body !== null ) {
			$headers['Content-Type'] = 'application/json';
			$json                    = function_exists( 'wp_json_encode' ) ? wp_json_encode( $body ) : json_encode( $body );
			$encoded                 = is_string( $json ) ? $json : '{}';
		}
		$sent = Client::send( $method, $url, $headers, $encoded );

		return [
			'status' => (int) $sent['status'],
			'body'   => (string) $sent['body'],
		];
	}

	/** @param array<string, mixed>|null $body */
	private static function isRead( ?array $body ): bool {
		if ( $body === null ) {
			return true;
		}
		$query = strtolower( (string) ( $body['query'] ?? '' ) );

		return $query === '' || ! str_contains( $query, 'mutation' );
	}

	/**
	 * @param callable $send
	 * @return array{records: array<int, array<string, mixed>>, projects: array<string, string>, cycles: array<string, string>, states: array<string, string>, types: array<string, string>, team_id: string, truncated: bool}|string
	 */
	private static function linear( string $kind, string $locator, string $releaseVia, callable $send, int &$calls ): array|string {
		$project = $kind === 'project';
		$records = [];
		$after   = null;
		$more    = true;
		$truncated = false;
		while ( $more ) {
			if ( $calls >= self::MAX_CALLS ) {
				$truncated = true;
				break;
			}
			$page = self::call( Remote::linearIssues( $locator, $after, $project ), $send, $calls );
			if ( is_string( $page ) ) {
				return $page;
			}
			$parsed  = Remote::linearPage( $page, $releaseVia );
			$records = array_merge( $records, $parsed['records'] );
			$more    = $parsed['more'] && $parsed['cursor'] !== '';
			$after   = $parsed['cursor'];
			if ( $parsed['more'] && $parsed['cursor'] === '' ) {
				$truncated = true;
				$more      = false;
			}
		}

		$meta = [ 'projects' => [], 'cycles' => [], 'states' => [], 'team_id' => '' ];
		if ( ! $project && $calls < self::MAX_CALLS ) {
			$raw = self::call( Remote::linearMeta( $locator ), $send, $calls );
			if ( is_array( $raw ) ) {
				$meta = Remote::linearMaps( $raw );
			}
		}

		return [
			'records'   => $records,
			'projects'  => $meta['projects'],
			'cycles'    => $meta['cycles'],
			'states'    => $meta['states'],
			'types'     => [],
			'team_id'   => $meta['team_id'] !== '' ? $meta['team_id'] : ( $project ? '' : $locator ),
			'truncated' => $truncated,
		];
	}

	/**
	 * @param array<string, string> $mapping
	 * @param callable              $send
	 * @return array{records: array<int, array<string, mixed>>, projects: array<string, string>, cycles: array<string, string>, states: array<string, string>, types: array<string, string>, team_id: string, truncated: bool}|string
	 */
	private static function notion( string $locator, array $mapping, callable $send, int &$calls ): array|string {
		$types   = [];
		$options = [];
		$schema  = self::call( Remote::notionDatabase( $locator ), $send, $calls );
		if ( is_array( $schema ) ) {
			$types   = Remote::notionTypes( $schema );
			$options = Remote::notionOptions( $schema, (string) ( $mapping['status'] ?? 'Status' ) );
		} elseif ( is_string( $schema ) ) {
			return $schema;
		}

		$records   = [];
		$cursor    = null;
		$more      = true;
		$truncated = false;
		while ( $more ) {
			if ( $calls >= self::MAX_CALLS ) {
				$truncated = true;
				break;
			}
			$page = self::call( Remote::notionQuery( $locator, $cursor ), $send, $calls );
			if ( is_string( $page ) ) {
				return $page;
			}
			$parsed  = Remote::notionPage( $page, $mapping );
			$records = array_merge( $records, $parsed['records'] );
			$more    = $parsed['more'] && $parsed['cursor'] !== '';
			$cursor  = $parsed['cursor'] !== '' ? $parsed['cursor'] : null;
			if ( $parsed['more'] && $parsed['cursor'] === '' ) {
				$truncated = true;
				$more      = false;
			}
		}

		return [
			'records'   => $records,
			'projects'  => [],
			'cycles'    => [],
			'states'    => $options,
			'types'     => $types,
			'team_id'   => '',
			'truncated' => $truncated,
		];
	}

	/**
	 * @param array{method: string, url: string, body: array<string, mixed>|null} $request
	 * @param callable $send
	 * @return array<string, mixed>|string
	 */
	private static function call( array $request, callable $send, int &$calls ): array|string {
		++$calls;
		$sent = $send( $request['method'], $request['url'], $request['body'] );
		if ( is_string( $sent ) ) {
			return $sent;
		}
		if ( ! is_array( $sent ) ) {
			return 'The service did not answer.';
		}
		$status = (int) ( $sent['status'] ?? 0 );
		if ( $status < 200 || $status >= 300 ) {
			return 'The service answered ' . $status . '.';
		}
		$decoded = json_decode( (string) ( $sent['body'] ?? '' ), true );

		return is_array( $decoded ) ? $decoded : 'The service answer was not readable.';
	}
}
