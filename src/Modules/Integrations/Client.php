<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

/**
 * #990: one outbound call. Redirects are not followed, so a service
 * cannot bounce the key to another host. The body is kept only long
 * enough to read a name or an access token; it is not logged.
 */
final class Client {

	/** Enough for a name, a token or an error. */
	public const MAX_BODY = 65536;

	/**
	 * @param array<string, string> $headers
	 * @param int                   $max Bytes of the answer kept; a planning
	 *                                   page of 100 Notion rows is larger than MAX_BODY.
	 * @return array{status: int, body: string, headers: array<string, string>}
	 */
	public static function send( string $method, string $url, array $headers, ?string $body, int $max = self::MAX_BODY ): array {
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
			'body'    => self::cap( $text, $max ),
			'headers' => $lower,
		];
	}

	/** The first $max bytes of an answer. A cut answer is no longer valid JSON. */
	public static function cap( string $text, int $max ): string {
		return strlen( $text ) > $max ? substr( $text, 0, max( 0, $max ) ) : $text;
	}
}
