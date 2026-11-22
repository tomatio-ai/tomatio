<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations\Context;

use AgentBrain\Modules\Integrations\Catalog;
use AgentBrain\Modules\Integrations\Client;
use AgentBrain\Modules\Integrations\Store;

/**
 * #991: one read of a picked source. The sender is injected so tests
 * never leave the machine. A partial read is marked truncated and must
 * not be used to delete copies.
 */
final class Fetch {

	public const MAX_CALLS = 24;

	public const NOTION_VERSION = '2022-06-28';

	/**
	 * @param callable $send
	 * @return array{items: array<int, array{id: string, title: string, url: string, text: string}>, truncated: bool}|string
	 */
	public static function read( string $service, string $kind, string $locator, callable $send ): array|string {
		if ( ! Read::kindOk( $service, $kind ) ) {
			return 'That is not a kind this service can read.';
		}
		if ( $locator === '' ) {
			return 'Paste a page, database, team or project link or id.';
		}
		$calls = 0;
		if ( $service === Read::LINEAR ) {
			return self::linear( $kind, $locator, $send, $calls );
		}
		if ( $kind === 'database' ) {
			return self::database( $locator, $send, $calls );
		}

		return self::pages( $kind, $locator, $send, $calls );
	}

	/**
	 * The live read. The key stays in the Authorization header, and only
	 * a host this plugin ships is called.
	 *
	 * @return array{items: array<int, array{id: string, title: string, url: string, text: string}>, truncated: bool}|string
	 */
	public static function live( string $service, string $kind, string $locator ): array|string {
		$spec = ( new Catalog() )->get( $service );
		if ( $spec === null ) {
			return 'That integration is not installed.';
		}
		$token = Store::bearer( $service );
		if ( $token === null || $token === '' ) {
			return (string) $spec['name'] . ' is not connected.';
		}
		$hosts = is_array( $spec['hosts'] ?? null ) ? $spec['hosts'] : [];

		return self::read(
			$service,
			$kind,
			$locator,
			static function ( string $method, string $url, ?array $body ) use ( $service, $token, $hosts, $spec ) {
				if ( Catalog::https( $url, $hosts ) === '' ) {
					return 'Refused: that address is not this service.';
				}
				$wait = Store::noteRead( $service );
				if ( $wait > 0 ) {
					return (string) $spec['name'] . ' asked us to slow down. Try again in ' . $wait . ' seconds.';
				}
				$headers = self::headers( $service, $token );
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
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function headers( string $service, string $token ): array {
		$headers = [
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $token,
		];
		if ( $service === Read::NOTION ) {
			$headers['Notion-Version'] = self::NOTION_VERSION;
		}

		return $headers;
	}

	/**
	 * @param callable $send
	 * @return array{items: array<int, array{id: string, title: string, url: string, text: string}>, truncated: bool}|string
	 */
	private static function pages( string $kind, string $locator, callable $send, int &$calls ): array|string {
		$page = self::onePage( $locator, $send, $calls );
		if ( is_string( $page ) ) {
			return $page;
		}
		$items     = [ $page['item'] ];
		$truncated = $page['truncated'];
		if ( $kind === 'section' ) {
			$seen = 0;
			foreach ( $page['children'] as $childId ) {
				if ( $calls >= self::MAX_CALLS ) {
					$truncated = true;
					break;
				}
				$child = self::onePage( $childId, $send, $calls );
				if ( is_string( $child ) ) {
					return $child;
				}
				$items[] = $child['item'];
				$truncated = $truncated || $child['truncated'];
				++$seen;
			}
			if ( $seen < count( $page['children'] ) ) {
				$truncated = true;
			}
		}

		return [ 'items' => self::dedupe( $items ), 'truncated' => $truncated ];
	}

	/**
	 * @param callable $send
	 * @return array{item: array{id: string, title: string, url: string, text: string}, children: array<int, string>, truncated: bool}|string
	 */
	private static function onePage( string $id, callable $send, int &$calls ): array|string {
		$page = self::call( Read::NOTION, $send, Read::notionPageRequest( $id ), $calls );
		if ( is_string( $page ) ) {
			return $page;
		}
		$blocks    = [];
		$cursor    = null;
		$truncated = false;
		do {
			if ( $calls >= self::MAX_CALLS ) {
				$truncated = true;
				break;
			}
			$chunk = self::call( Read::NOTION, $send, Read::notionBlocksRequest( $id, $cursor ), $calls );
			if ( is_string( $chunk ) ) {
				return $chunk;
			}
			foreach ( is_array( $chunk['results'] ?? null ) ? $chunk['results'] : [] as $block ) {
				if ( is_array( $block ) ) {
					$blocks[] = $block;
				}
			}
			$cursor = ! empty( $chunk['has_more'] ) && is_string( $chunk['next_cursor'] ?? null ) && $chunk['next_cursor'] !== ''
				? $chunk['next_cursor']
				: null;
			if ( $cursor !== null && $calls >= self::MAX_CALLS ) {
				$truncated = true;
				$cursor    = null;
			}
		} while ( $cursor !== null );

		return [
			'item'      => Read::notionPage( $page, $blocks ),
			'children'  => Read::childPageIds( $blocks ),
			'truncated' => $truncated,
		];
	}

	/**
	 * @param callable $send
	 * @return array{items: array<int, array{id: string, title: string, url: string, text: string}>, truncated: bool}|string
	 */
	private static function database( string $locator, callable $send, int &$calls ): array|string {
		$items     = [];
		$cursor    = null;
		$truncated = false;
		do {
			if ( $calls >= self::MAX_CALLS ) {
				$truncated = true;
				break;
			}
			$chunk = self::call( Read::NOTION, $send, Read::notionQueryRequest( $locator, $cursor ), $calls );
			if ( is_string( $chunk ) ) {
				return $chunk;
			}
			$parsed = Read::notionDatabase( $chunk );
			$items  = array_merge( $items, $parsed['items'] );
			$cursor = $parsed['cursor'];
			if ( $cursor !== null && $calls >= self::MAX_CALLS ) {
				$truncated = true;
				$cursor    = null;
			}
		} while ( $cursor !== null );

		return [ 'items' => self::dedupe( $items ), 'truncated' => $truncated ];
	}

	/**
	 * @param callable $send
	 * @return array{items: array<int, array{id: string, title: string, url: string, text: string}>, truncated: bool}|string
	 */
	private static function linear( string $kind, string $locator, callable $send, int &$calls ): array|string {
		$items     = [];
		$cursor    = null;
		$truncated = false;
		do {
			if ( $calls >= self::MAX_CALLS ) {
				$truncated = true;
				break;
			}
			$chunk = self::call( Read::LINEAR, $send, Read::linearRequest( $kind, $locator, $cursor ), $calls );
			if ( is_string( $chunk ) ) {
				return $chunk;
			}
			if ( isset( $chunk['errors'] ) && ! isset( $chunk['data'] ) ) {
				return 'Linear did not accept that read.';
			}
			$parsed = Read::linearIssues( $chunk );
			$items  = array_merge( $items, $parsed['items'] );
			$cursor = $parsed['cursor'];
			if ( $cursor !== null && $calls >= self::MAX_CALLS ) {
				$truncated = true;
				$cursor    = null;
			}
		} while ( $cursor !== null );

		return [ 'items' => self::dedupe( $items ), 'truncated' => $truncated ];
	}

	/**
	 * @param callable $send
	 * @param array{method: string, url: string, body: ?array<string, mixed>} $request
	 * @return array<string, mixed>|string
	 */
	private static function call( string $service, callable $send, array $request, int &$calls ): array|string {
		if ( ! Read::isRead( $request ) ) {
			return 'Refused: that is not a read.';
		}
		if ( $calls >= self::MAX_CALLS ) {
			return 'too-many';
		}
		++$calls;
		$sent = $send( $request['method'], $request['url'], $request['body'] );
		if ( is_string( $sent ) ) {
			return $sent;
		}
		$status = (int) ( $sent['status'] ?? 0 );
		if ( $status < 200 || $status >= 300 ) {
			return Read::httpWords( $service, $status );
		}
		$decoded = json_decode( (string) ( $sent['body'] ?? '' ), true );
		if ( ! is_array( $decoded ) ) {
			return ( $service === Read::LINEAR ? 'Linear' : 'Notion' ) . ' answered something that is not JSON.';
		}
		if ( ( $decoded['object'] ?? '' ) === 'error' ) {
			return Read::httpWords( $service, 400 );
		}

		return $decoded;
	}

	/**
	 * @param array<int, array{id: string, title: string, url: string, text: string}> $items
	 * @return array<int, array{id: string, title: string, url: string, text: string}>
	 */
	private static function dedupe( array $items ): array {
		$out = [];
		foreach ( $items as $item ) {
			if ( $item['id'] === '' ) {
				continue;
			}
			$out[ $item['id'] ] = $item;
		}

		return array_values( $out );
	}
}
