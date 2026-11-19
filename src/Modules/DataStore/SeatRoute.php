<?php

declare(strict_types=1);

namespace AgentBrain\Modules\DataStore;

use AgentBrain\Modules\Sharing\ShareRoute;

/**
 * #469: the seat endpoint of a validated share link (StoreSeats):
 *
 *   POST    /share/{id}/{token}/_seat   {name}  -> 201 {seat, secret, path}: claims a name (create-only)
 *   GET     /share/{id}/{token}/_seat           -> {seat}: the seat the X-Tomatio-Seat header proves, or null
 *   OPTIONS /share/{id}/{token}/_seat           -> CORS preflight
 *   GET     /share/{id}/{token}/_seats          -> {seats: [name, ...]}: every seat's name, in claim order (#501)
 *
 * Answers only while the page store and seats are on; otherwise the uniform
 * 404. GET with ?open=1 counts an open of the seat's personal link (the
 * client sends it once per page load). Claims are limited per address and
 * per artifact. The secret is in the claim response only: it is never
 * stored (a hash is), logged or audited.
 *
 * #501: _seats is for pages that show everyone (a leaderboard listing
 * players before their first win). It gives names only, no secrets, hashes,
 * link ids or opens, to anyone holding the link: the same audience that
 * already reads the scores.
 */
final class SeatRoute {

	public const PATH = '_seat';
	public const LIST = '_seats';

	/** Claims per address hash per artifact per hour, and per artifact per hour. */
	public const CLAIMS_PER_IP       = 10;
	public const CLAIMS_PER_ARTIFACT = 60;

	/**
	 * @return array{artifact_id:int,token:string,list:bool}|null
	 */
	public static function parsePath( string $uri ): ?array {
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( preg_match( '#^/share/(\d+)/([^/]+)/(' . preg_quote( self::PATH, '#' ) . '|' . preg_quote( self::LIST, '#' ) . ')$#', $path, $m ) !== 1 ) {
			return null;
		}

		return [
			'artifact_id' => (int) $m[1],
			'token'       => rawurldecode( $m[2] ),
			'list'        => $m[3] === self::LIST,
		];
	}

	/**
	 * @param array{artifact_id:int,token:string,list?:bool} $route Validated route context.
	 * @param array<string, mixed>                          $link  The validated share link row.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public static function respond( array $route, array $link, string $method, string $uri, ?string $body, ?string $secret, string $ipHash, ?int $now = null ): array {
		$now        = $now ?? time();
		$method     = strtoupper( $method );
		$artifactId = $route['artifact_id'];
		$headers    = StoreRoute::headers();

		if ( ! StoreMode::isActive( $artifactId ) || ! StoreMode::seatsOn( $artifactId ) ) {
			return ShareRoute::notFound();
		}

		if ( $method === 'OPTIONS' ) {
			$headers['Access-Control-Allow-Methods'] = 'GET, HEAD, POST';
			$headers['Access-Control-Allow-Headers'] = 'Content-Type, ' . StoreSeats::HEADER;
			$headers['Access-Control-Max-Age']       = '600';

			return [ 'status' => 204, 'headers' => $headers, 'body' => '' ];
		}

		if ( ! empty( $route['list'] ) ) {
			if ( $method !== 'GET' && $method !== 'HEAD' ) {
				return self::json( 405, [ 'error' => 'method_not_allowed' ], $headers + [ 'Allow' => 'GET' ] );
			}
			if ( ! StoreRate::allow( $ipHash !== '' ? $ipHash : str_repeat( '0', 64 ), (int) $link['id'], StoreRate::KIND_READ, $now ) ) {
				return self::json( 429, [ 'error' => 'rate_limited', 'retry_after' => 60 ], $headers + [ 'Retry-After' => '60' ] );
			}

			return self::json( 200, [ 'seats' => StoreSeats::names( $artifactId ) ], $headers );
		}

		if ( $method === 'GET' || $method === 'HEAD' ) {
			if ( ! StoreRate::allow( $ipHash !== '' ? $ipHash : str_repeat( '0', 64 ), (int) $link['id'], StoreRate::KIND_READ, $now ) ) {
				return self::json( 429, [ 'error' => 'rate_limited', 'retry_after' => 60 ], $headers + [ 'Retry-After' => '60' ] );
			}

			$seat = $secret !== null && $secret !== '' ? StoreSeats::verify( $artifactId, $secret ) : null;

			$query = [];
			wp_parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
			if ( $seat !== null && ( $query['open'] ?? '' ) === '1' ) {
				StoreSeats::touch( $artifactId, $seat['name'], $now );
			}

			return self::json( 200, [ 'seat' => $seat['name'] ?? null ], $headers );
		}

		if ( $method !== 'POST' ) {
			return self::json( 405, [ 'error' => 'method_not_allowed' ], $headers + [ 'Allow' => 'GET, POST' ] );
		}

		$buckets = [
			[ 'bucket' => hash( 'sha256', $artifactId . '|_seat|ip:' . $ipHash . '|3600' ), 'max' => self::CLAIMS_PER_IP, 'seconds' => 3600 ],
			[ 'bucket' => hash( 'sha256', $artifactId . '|_seat|all|3600' ), 'max' => self::CLAIMS_PER_ARTIFACT, 'seconds' => 3600 ],
		];
		$wait    = StoreLimits::retryAfter( $buckets, $now );
		if ( $wait > 0 || ! StoreRate::allow( $ipHash !== '' ? $ipHash : str_repeat( '0', 64 ), (int) $link['id'], StoreRate::KIND_WRITE, $now ) ) {
			$wait = max( $wait, 60 );

			return self::json( 429, [ 'error' => 'rate_limited', 'retry_after' => $wait ], $headers + [ 'Retry-After' => (string) $wait ] );
		}

		if ( $body === null ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- no WP filesystem API for php://input; read-only.
			$body = (string) file_get_contents( 'php://input' );
		}
		$decoded = json_decode( $body, true );
		$name    = is_array( $decoded ) && isset( $decoded['name'] ) && is_string( $decoded['name'] ) ? $decoded['name'] : '';

		$claimed = StoreSeats::claim( $artifactId, $name, (int) $link['id'], $now );
		if ( is_wp_error( $claimed ) ) {
			$data   = $claimed->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			if ( $claimed->get_error_code() !== 'seat_name_invalid' ) {
				// Taken or full: counts against the budget, so names cannot be probed for free.
				StoreLimits::record( $buckets, $now );
			}

			return self::json( $status, [ 'error' => $claimed->get_error_code(), 'message' => $claimed->get_error_message() ], $headers );
		}

		StoreLimits::record( $buckets, $now );

		return self::json(
			201,
			[
				'seat'   => $claimed['name'],
				'secret' => $claimed['secret'],
				'path'   => self::personalPath( $artifactId, $route['token'], $claimed['name'], $claimed['secret'] ),
			],
			$headers
		);
	}

	/**
	 * A seat's personal link below the share host: the name in the query
	 * (it dresses the page), the secret in the fragment (it never reaches a
	 * server or an access log).
	 */
	public static function personalPath( int $artifactId, string $token, string $name, string $secret ): string {
		return '/share/' . $artifactId . '/' . rawurlencode( $token ) . '/?seat=' . rawurlencode( $name ) . '#seat=' . rawurlencode( $secret );
	}

	/**
	 * @param array<string, mixed>  $data
	 * @param array<string, string> $headers
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private static function json( int $status, array $data, array $headers ): array {
		return [ 'status' => $status, 'headers' => $headers, 'body' => (string) wp_json_encode( $data ) ];
	}
}
