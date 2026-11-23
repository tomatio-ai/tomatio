<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

/**
 * #990: one outbound call. Redirects are not followed, so a service
 * cannot bounce the key to another host. The body is kept only long
 * enough to read a name or an access token; it is not logged.
 */
final class Client {

	/**
	 * @param array<string, string> $headers
	 * @return array{status: int, body: string, headers: array<string, string>}
	 */
	public static function send( string $method, string $url, array $headers, ?string $body ): array {
		$empty = [ 'status' => 0, 'body' => '', 'headers' => [] ];
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return $empty;
		}
		$response = wp_remote_request( $url, [
			'method'             => $method,
			'headers'            => $headers,
			'body'               => $body,
			'timeout'            => 15,
			'redirection'        => 0,
			'reject_unsafe_urls' => true,
		] );
		if ( is_wp_error( $response ) ) {
			return $empty;
		}
		$raw     = wp_remote_retrieve_headers( $response );
		$lower   = [];
		$asArray = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;
		foreach ( $asArray as $key => $value ) {
			$lower[ strtolower( (string) $key ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}
		$text = wp_remote_retrieve_body( $response );

		return [
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => substr( $text, 0, 65536 ),
			'headers' => $lower,
		];
	}
}
