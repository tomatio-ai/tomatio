<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Compression;

use AgentBrain\Core\Untrusted;

/**
 * Compression provider for any OpenAI-compatible chat completions endpoint
 * (POST {base URL}/chat/completions with a Bearer key): OpenAI, Cloudflare
 * Workers AI / AI Gateway, OpenRouter, or a local server such as Ollama.
 * Synchronous, and it must produce output that passes OutputSchema::validate()
 * exactly like WpAiClientProvider (CMP-3).
 *
 * Security: the URL is re-checked by UrlGuard immediately before every
 * request and the connection is pinned to the validated addresses with
 * CURLOPT_RESOLVE where the curl transport is in use, redirects are never
 * followed, and neither the key nor the response body is ever put into an
 * error message, log line or audit row.
 */
final class OpenAiCompatibleProvider implements ProviderInterface {

	private const TIMEOUT_SECONDS = 60;
	private const MAX_BYTES       = 1048576;

	public function id(): string {
		return 'openai_compatible';
	}

	public function isAsync(): bool {
		return false;
	}

	public function compress( array $rows, array $existingFacts, int $agentId, int $scopeId ): array|null|\WP_Error {
		try {
			$prompt = PromptBuilder::build( $rows, $existingFacts, $agentId, $scopeId );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'ab_compression_prompt_build_failed', $e->getMessage() );
		}

		$body = self::chat(
			[
				[ 'role' => 'system', 'content' => Untrusted::preamble() ],
				[ 'role' => 'user', 'content' => $prompt ],
			],
			true
		);

		if ( $body instanceof \WP_Error ) {
			return $body;
		}

		$content = self::content( $body );
		if ( $content === null ) {
			return new \WP_Error( 'ab_compression_endpoint_bad_response', 'The endpoint response had no message content.' );
		}

		$decoded = json_decode( self::stripFence( $content ), true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'ab_compression_endpoint_bad_json', 'The endpoint returned output that was not valid JSON.' );
		}

		return $decoded;
	}

	/**
	 * Cheap round trip used by the Test connection button.
	 *
	 * @return true|\WP_Error
	 */
	public static function testConnection(): bool|\WP_Error {
		$body = self::chat( [ [ 'role' => 'user', 'content' => 'Reply with the single word OK.' ] ], false );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}

		return self::content( $body ) !== null
			? true
			: new \WP_Error( 'ab_compression_endpoint_bad_response', 'The endpoint answered but the response was not an OpenAI chat completion.' );
	}

	/**
	 * Full chat completions URL for a base URL.
	 */
	public static function completionsUrl( string $baseUrl ): string {
		$base = rtrim( $baseUrl, '/' );

		return substr( $base, -17 ) === '/chat/completions' ? $base : $base . '/chat/completions';
	}

	/**
	 * @param array<string, mixed> $body Decoded chat completion.
	 */
	public static function content( array $body ): ?string {
		$content = $body['choices'][0]['message']['content'] ?? null;

		return is_string( $content ) && $content !== '' ? $content : null;
	}

	/**
	 * Models often wrap JSON in a Markdown code fence even when told not to.
	 */
	public static function stripFence( string $text ): string {
		$text = trim( $text );

		if ( preg_match( '/^```[a-zA-Z0-9]*\s*\R(.*?)\R?```$/s', $text, $m ) === 1 ) {
			return trim( $m[1] );
		}

		return $text;
	}

	/**
	 * @param array<int, array{role: string, content: string}> $messages
	 * @return array<string, mixed>|\WP_Error Decoded response body.
	 */
	private static function chat( array $messages, bool $jsonMode ): array|\WP_Error {
		$url   = Endpoint::baseUrl();
		$model = Endpoint::model();
		if ( $url === '' || $model === '' ) {
			return new \WP_Error( 'ab_compression_endpoint_unconfigured', 'The OpenAI-compatible endpoint is not configured.' );
		}

		$key = Endpoint::apiKey();
		if ( $key instanceof \WP_Error ) {
			return $key;
		}

		$allowLocal = Endpoint::allowLocal();
		$inspected  = UrlGuard::inspect( $url, $allowLocal );
		if ( $inspected['error'] !== null ) {
			return new \WP_Error( 'ab_compression_endpoint_url_rejected', 'The endpoint URL was refused: ' . $inspected['error'] . '.' );
		}

		$payload = [ 'model' => $model, 'messages' => $messages, 'temperature' => 0 ];
		if ( $jsonMode ) {
			$payload['response_format'] = [ 'type' => 'json_object' ];
		}

		$response = self::post( $url, $inspected['ips'], $key, $payload );

		// Not every compatible server supports response_format; retry once without it.
		if ( $jsonMode && ! $response instanceof \WP_Error && in_array( (int) wp_remote_retrieve_response_code( $response ), [ 400, 422 ], true ) ) {
			unset( $payload['response_format'] );
			$response = self::post( $url, $inspected['ips'], $key, $payload );
		}

		unset( $key );

		if ( $response instanceof \WP_Error ) {
			return new \WP_Error( 'ab_compression_endpoint_transport', 'Could not reach the endpoint: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code === 401 || $code === 403 ) {
			return new \WP_Error( 'ab_compression_endpoint_auth', sprintf( 'The endpoint rejected the API key (HTTP %d).', $code ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'ab_compression_endpoint_http', sprintf( 'The endpoint returned HTTP %d.', $code ) );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded )
			? $decoded
			: new \WP_Error( 'ab_compression_endpoint_bad_json', 'The endpoint response was not valid JSON.' );
	}

	/**
	 * @param array<int, string>   $ips
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function post( string $baseUrl, array $ips, string $key, array $payload ): array|\WP_Error {
		$url     = self::completionsUrl( $baseUrl );
		$headers = [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ];
		if ( $key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$pin = null;
		if ( $ips !== [] && defined( 'CURLOPT_RESOLVE' ) ) {
			$host  = (string) parse_url( $url, PHP_URL_HOST );
			$port  = parse_url( $url, PHP_URL_PORT );
			$port  = is_int( $port ) ? $port : ( strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) === 'http' ? 80 : 443 );
			$entry = sprintf( '%s:%d:%s', $host, $port, implode( ',', $ips ) );
			$pin   = static function ( $handle ) use ( $entry ): void {
				curl_setopt( $handle, CURLOPT_RESOLVE, [ $entry ] );
			};
			add_action( 'http_api_curl', $pin, 10, 1 );
		}

		try {
			return wp_remote_post( $url, [
				'headers'             => $headers,
				'body'                => wp_json_encode( $payload ),
				'timeout'             => self::TIMEOUT_SECONDS,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => self::MAX_BYTES,
			] );
		} finally {
			if ( $pin !== null ) {
				remove_action( 'http_api_curl', $pin, 10 );
			}
		}
	}
}
