<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

/**
 * #990: the OAuth authorize URL and the token-exchange request. The
 * client secret is sent only to the service's token URL, never put in
 * the authorize URL or in anything shown to a person.
 */
final class Exchange {

	/**
	 * @param array<string, mixed> $spec
	 */
	public static function authorizeUrl( array $spec, string $clientId, string $redirect, string $state ): string {
		$oauth = is_array( $spec['oauth'] ?? null ) ? $spec['oauth'] : [];
		$query = [
			'response_type' => 'code',
			'client_id'     => $clientId,
			'redirect_uri'  => $redirect,
			'state'         => $state,
		];
		$scopes = (string) ( $oauth['scopes'] ?? '' );
		if ( $scopes !== '' ) {
			$query['scope'] = $scopes;
		}
		foreach ( is_array( $oauth['extra'] ?? null ) ? $oauth['extra'] : [] as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && ! isset( $query[ $key ] ) ) {
				$query[ $key ] = $value;
			}
		}

		return (string) ( $oauth['authorize'] ?? '' ) . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array{method: string, url: string, headers: array<string, string>, body: string}
	 */
	public static function tokenRequest( array $spec, string $code, string $clientId, string $clientSecret, string $redirect ): array {
		$oauth    = is_array( $spec['oauth'] ?? null ) ? $spec['oauth'] : [];
		$url      = (string) ( $oauth['token'] ?? '' );
		$exchange = (string) ( $oauth['exchange'] ?? 'form' );
		if ( $exchange === 'basic' ) {
			$payload = json_encode( [
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => $redirect,
			] );

			return [
				'method'  => 'POST',
				'url'     => $url,
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( $clientId . ':' . $clientSecret ),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				],
				'body'    => is_string( $payload ) ? $payload : '{}',
			];
		}

		return [
			'method'  => 'POST',
			'url'     => $url,
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			],
			'body'    => http_build_query( [
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $redirect,
				'client_id'     => $clientId,
				'client_secret' => $clientSecret,
			], '', '&', PHP_QUERY_RFC3986 ),
		];
	}

	public static function accessToken( string $body ): string {
		$decoded = json_decode( $body, true );
		$token   = is_array( $decoded ) && is_string( $decoded['access_token'] ?? null ) ? $decoded['access_token'] : '';

		return strlen( $token ) >= 8 && strlen( $token ) <= 4096 ? $token : '';
	}

	public static function refreshToken( string $body ): string {
		$decoded = json_decode( $body, true );
		$token   = is_array( $decoded ) && is_string( $decoded['refresh_token'] ?? null ) ? $decoded['refresh_token'] : '';

		return strlen( $token ) <= 4096 ? $token : '';
	}

	public static function expiresIn( string $body, int $now ): int {
		$decoded = json_decode( $body, true );
		$seconds = is_array( $decoded ) && is_numeric( $decoded['expires_in'] ?? null ) ? (int) $decoded['expires_in'] : 0;

		return $seconds > 0 ? $now + $seconds : 0;
	}
}
