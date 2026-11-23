<?php

declare(strict_types=1);

namespace AgentBrain\Modules\AskBrain;

use AgentBrain\Modules\Compression\Endpoint;
use AgentBrain\Modules\Compression\UrlGuard;

/**
 * ART-26 (#166): ask provider over the owner's already-configured
 * OpenAI-compatible chat endpoint — the same configuration, URL guard, DNS
 * pinning and no-redirect posture as Compression\OpenAiCompatibleProvider
 * (plain chat completion, no JSON mode; token counts from `usage`). The
 * API key is used only in the request Authorization header and is never put
 * into an error message, log line, or audit row.
 */
final class OpenAiEndpointProvider implements AnswerProvider {

	private const TIMEOUT_SECONDS = 60;
	private const MAX_BYTES       = 1048576;

	public function id(): string {
		return 'openai_compatible';
	}

	/**
	 * @return array{text:string, prompt_tokens:int, completion_tokens:int, model:string}|\WP_Error
	 */
	public function answer( string $system, string $user ): array|\WP_Error {
		$url   = Endpoint::baseUrl();
		$model = Endpoint::model();
		if ( $url === '' || $model === '' ) {
			return new \WP_Error( 'ab_ask_endpoint_unconfigured', 'The OpenAI-compatible endpoint is not configured.' );
		}

		$key = Endpoint::apiKey();
		if ( $key instanceof \WP_Error ) {
			return new \WP_Error( 'ab_ask_endpoint_key', 'The stored endpoint API key could not be read.' );
		}

		$allowLocal = Endpoint::allowLocal();
		$inspected  = UrlGuard::inspect( $url, $allowLocal );
		if ( $inspected['error'] !== null ) {
			return new \WP_Error( 'ab_ask_endpoint_url_rejected', 'The endpoint URL was refused.' );
		}

		$response = self::post( $url, $inspected['ips'], $key, $model, [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user', 'content' => $user ],
		] );

		unset( $key );

		if ( $response instanceof \WP_Error ) {
			return new \WP_Error( 'ab_ask_endpoint_transport', 'Could not reach the AI endpoint.' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code === 401 || $code === 403 ) {
			return new \WP_Error( 'ab_ask_endpoint_auth', sprintf( 'The endpoint rejected the API key (HTTP %d).', $code ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'ab_ask_endpoint_http', sprintf( 'The endpoint returned HTTP %d.', $code ) );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'ab_ask_endpoint_bad_json', 'The endpoint response was not valid JSON.' );
		}

		$content = $decoded['choices'][0]['message']['content'] ?? null;
		if ( ! is_string( $content ) || trim( $content ) === '' ) {
			return new \WP_Error( 'ab_ask_endpoint_bad_response', 'The endpoint response had no message content.' );
		}

		return [
			'text'              => trim( $content ),
			'prompt_tokens'     => (int) ( $decoded['usage']['prompt_tokens'] ?? 0 ),
			'completion_tokens' => (int) ( $decoded['usage']['completion_tokens'] ?? 0 ),
			'model'             => $model,
		];
	}

	/**
	 * @param array<int, string>                                    $ips
	 * @param array<int, array{role:string, content:string}> $messages
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function post( string $baseUrl, array $ips, string $key, string $model, array $messages ): array|\WP_Error {
		$url     = \AgentBrain\Modules\Compression\OpenAiCompatibleProvider::completionsUrl( $baseUrl );
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
				'body'                => wp_json_encode( [
					'model'       => $model,
					'messages'    => $messages,
					'temperature' => 0,
				] ),
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
