<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

/**
 * #990: build the one read that tests a connection, and turn the answer
 * into plain words. The key goes in the Authorization header only, never
 * in the URL, and never into the words shown to a person.
 */
final class Probe {

	/**
	 * @param array<string, mixed> $spec
	 * @return array{method: string, url: string, headers: array<string, string>, body: ?string}
	 */
	public static function request( array $spec, string $token ): array {
		$test    = is_array( $spec['test'] ?? null ) ? $spec['test'] : [];
		$headers = is_array( $test['headers'] ?? null ) ? $test['headers'] : [];
		$out     = [ 'Accept' => 'application/json' ];
		foreach ( $headers as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && strcasecmp( $key, 'Authorization' ) !== 0 ) {
				$out[ $key ] = $value;
			}
		}
		$out['Authorization'] = 'Bearer ' . $token;
		$body = null;
		if ( is_array( $test['body'] ?? null ) ) {
			$encoded = json_encode( $test['body'] );
			$body    = is_string( $encoded ) ? $encoded : '{}';
			$out['Content-Type'] = $out['Content-Type'] ?? 'application/json';
		}

		return [
			'method'  => (string) ( $test['method'] ?? 'GET' ),
			'url'     => (string) ( $test['url'] ?? '' ),
			'headers' => $out,
			'body'    => $body,
		];
	}

	/**
	 * @param array<string, mixed> $spec
	 * @param array<string, string> $headers response headers, lower-case keys
	 * @return array{ok: bool, words: string, account: string, limited: bool, retry_after: int}
	 */
	public static function words( array $spec, int $status, string $body, array $headers, string $token ): array {
		$name = (string) ( $spec['name'] ?? 'The service' );
		$none = [ 'ok' => false, 'words' => '', 'account' => '', 'limited' => false, 'retry_after' => 0 ];

		if ( $status === 0 ) {
			$none['words'] = $name . ' did not answer. Try again in a minute.';

			return $none;
		}
		if ( $status === 429 ) {
			$wait = self::retryAfter( $headers );
			$none['limited']     = true;
			$none['retry_after'] = $wait;
			$none['words']       = $wait > 0
				? $name . ' asked us to slow down. Trying again in ' . self::span( $wait ) . '.'
				: $name . ' asked us to slow down. Trying again shortly.';

			return $none;
		}
		if ( $status === 401 || $status === 403 ) {
			$none['words'] = $name . ' refused the key. Check it and try again.';

			return $none;
		}
		if ( $status < 200 || $status >= 300 ) {
			$none['words'] = $name . ' did not accept the connection (HTTP ' . $status . ').';

			return $none;
		}

		$account = self::nameAt( $body, (string) ( $spec['test']['name_path'] ?? '' ) );
		$account = self::redact( $account, $token );
		if ( $account === '' ) {
			$none['ok']    = true;
			$none['words'] = 'Connected to ' . $name . '.';

			return $none;
		}

		return [
			'ok'          => true,
			'words'       => 'Connected to ' . $name . ' as ' . $account . '.',
			'account'     => $account,
			'limited'     => false,
			'retry_after' => 0,
		];
	}

	/** @param array<string, string> $headers */
	public static function retryAfter( array $headers ): int {
		$raw = $headers['retry-after'] ?? '';
		if ( $raw === '' || ! ctype_digit( $raw ) ) {
			return 0;
		}

		return min( 86400, (int) $raw );
	}

	public static function redact( string $value, string $secret ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		if ( $secret !== '' && str_contains( $value, $secret ) ) {
			return '';
		}
		if ( strlen( $value ) > 80 || preg_match( '/[\r\n<>]/', $value ) === 1 ) {
			return '';
		}

		return $value;
	}

	private static function nameAt( string $body, string $path ): string {
		if ( $path === '' ) {
			return '';
		}
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		$cursor = $decoded;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $part, $cursor ) ) {
				return '';
			}
			$cursor = $cursor[ $part ];
		}

		return is_string( $cursor ) ? $cursor : '';
	}

	private static function span( int $seconds ): string {
		if ( $seconds < 90 ) {
			return $seconds . ' seconds';
		}
		$minutes = (int) ceil( $seconds / 60 );

		return $minutes . ' minutes';
	}
}
