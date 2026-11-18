<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Integrations;

/**
 * #990: the services a brain can connect, read from folders under
 * extensions/<name>/integration.json. A third service is another folder.
 * No change to this class, and nothing here is loaded from an uploaded
 * archive: contract 1 does not approve outbound hosts, so a pasted key
 * is only ever sent to a host this plugin ships.
 *
 * Plain PHP. A bad file is a string in problems(), not an exception.
 */
final class Catalog {

	public const FILE = 'integration.json';

	/** @var array<string, array<string, mixed>> */
	private array $services = [];

	/** @var array<int, string> */
	private array $problems = [];

	public function __construct( ?string $root = null ) {
		$dir = $root ?? self::pluginDir() . '/extensions';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = scandir( $dir );
		if ( $entries === false ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' || ! is_dir( $dir . '/' . $entry ) ) {
				continue;
			}
			$path = $dir . '/' . $entry . '/' . self::FILE;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside the plugin.
			$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( ! is_array( $decoded ) ) {
				$this->problems[] = $entry . ': integration.json is not readable.';
				continue;
			}
			$spec = self::check( $decoded );
			if ( is_string( $spec ) ) {
				$this->problems[] = $entry . ': ' . $spec;
				continue;
			}
			$this->services[ $spec['id'] ] = $spec;
		}
	}

	/**
	 * @return array<string, array<string, mixed>> id => spec
	 */
	public function services(): array {
		return $this->services;
	}

	/** @return array<string, mixed>|null */
	public function get( string $id ): ?array {
		return $this->services[ $id ] ?? null;
	}

	/** @return array<int, string> */
	public function problems(): array {
		return $this->problems;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>|string
	 */
	public static function check( array $raw ) {
		$id = is_string( $raw['id'] ?? null ) ? $raw['id'] : '';
		if ( preg_match( '/^[a-z][a-z0-9-]{0,31}$/', $id ) !== 1 ) {
			return 'id must be lower-case letters, digits and hyphens, up to 32.';
		}
		$name = is_string( $raw['name'] ?? null ) ? trim( $raw['name'] ) : '';
		if ( $name === '' || strlen( $name ) > 40 ) {
			return 'name is required, at most 40 characters.';
		}
		$hosts = $raw['hosts'] ?? null;
		if ( ! is_array( $hosts ) || $hosts === [] ) {
			return 'hosts must list the services the key may be sent to.';
		}
		$allowed = [];
		foreach ( $hosts as $host ) {
			if ( ! is_string( $host ) || preg_match( '/^[a-z0-9.-]+$/', $host ) !== 1 || self::isIp( $host ) ) {
				return 'hosts must be domain names, not addresses.';
			}
			$allowed[] = strtolower( $host );
		}

		$token = is_array( $raw['token'] ?? null ) ? $raw['token'] : [];
		$label = is_string( $token['label'] ?? null ) ? trim( $token['label'] ) : '';
		$help  = is_string( $token['help'] ?? null ) ? trim( $token['help'] ) : '';
		if ( $label === '' ) {
			return 'token.label is required.';
		}

		$oauth = null;
		if ( isset( $raw['oauth'] ) ) {
			if ( ! is_array( $raw['oauth'] ) ) {
				return 'oauth must be an object.';
			}
			$authorize = self::https( (string) ( $raw['oauth']['authorize'] ?? '' ), $allowed );
			$tokenUrl  = self::https( (string) ( $raw['oauth']['token'] ?? '' ), $allowed );
			if ( $authorize === '' || $tokenUrl === '' ) {
				return 'oauth URLs must be https on a listed host.';
			}
			$exchange = ( $raw['oauth']['exchange'] ?? '' ) === 'basic' ? 'basic' : 'form';
			$extra    = is_array( $raw['oauth']['extra'] ?? null ) ? $raw['oauth']['extra'] : [];
			$clean    = [];
			foreach ( $extra as $key => $value ) {
				if ( is_string( $key ) && ( is_string( $value ) || is_int( $value ) ) && preg_match( '/^[a-z0-9_]{1,32}$/', $key ) === 1 ) {
					$clean[ $key ] = (string) $value;
				}
			}
			$oauth = [
				'authorize' => $authorize,
				'token'     => $tokenUrl,
				'scopes'    => is_string( $raw['oauth']['scopes'] ?? null ) ? $raw['oauth']['scopes'] : '',
				'extra'     => $clean,
				'exchange'  => $exchange,
			];
		}

		$test = is_array( $raw['test'] ?? null ) ? $raw['test'] : [];
		$method = strtoupper( (string) ( $test['method'] ?? 'GET' ) );
		if ( ! in_array( $method, [ 'GET', 'POST' ], true ) ) {
			return 'test.method is GET or POST.';
		}
		$url = self::https( (string) ( $test['url'] ?? '' ), $allowed );
		if ( $url === '' ) {
			return 'test.url must be https on a listed host.';
		}
		$headers = [];
		foreach ( is_array( $test['headers'] ?? null ) ? $test['headers'] : [] as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && preg_match( '/^[A-Za-z0-9-]{1,40}$/', $key ) === 1 ) {
				$headers[ $key ] = $value;
			}
		}
		$body = null;
		if ( isset( $test['body'] ) ) {
			if ( ! is_array( $test['body'] ) ) {
				return 'test.body must be an object.';
			}
			$body = $test['body'];
		}
		$path = is_string( $test['name_path'] ?? null ) ? $test['name_path'] : '';
		if ( $path !== '' && preg_match( '/^[A-Za-z0-9_.]+$/', $path ) !== 1 ) {
			return 'test.name_path may only use letters, digits, dots and underscores.';
		}

		$limits = is_array( $raw['limits'] ?? null ) ? $raw['limits'] : [];
		$per    = (int) ( $limits['per_minute'] ?? 30 );
		$per    = max( 1, min( 600, $per ) );
		$back   = [];
		foreach ( is_array( $limits['backoff'] ?? null ) ? $limits['backoff'] : [ 60, 300, 900 ] as $seconds ) {
			$back[] = max( 1, min( 86400, (int) $seconds ) );
		}
		if ( $back === [] ) {
			$back = [ 60 ];
		}

		return [
			'id'          => $id,
			'name'        => $name,
			'blurb'       => is_string( $raw['blurb'] ?? null ) ? trim( $raw['blurb'] ) : '',
			'hosts'       => $allowed,
			'token_label' => $label,
			'token_help'  => $help,
			'oauth'       => $oauth,
			'test'        => [
				'method'    => $method,
				'url'       => $url,
				'headers'   => $headers,
				'body'      => $body,
				'name_path' => $path,
			],
			'per_minute'  => $per,
			'backoff'     => $back,
		];
	}

	/** @param array<int, string> $hosts */
	public static function https( string $url, array $hosts ): string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( $host === '' || self::isIp( $host ) || ! in_array( $host, $hosts, true ) ) {
			return '';
		}
		if ( isset( $parts['port'] ) && (int) $parts['port'] !== 443 ) {
			return '';
		}

		return $url;
	}

	private static function isIp( string $host ): bool {
		return filter_var( $host, FILTER_VALIDATE_IP ) !== false;
	}

	private static function pluginDir(): string {
		return defined( 'AGENT_BRAIN_PLUGIN_DIR' ) ? (string) AGENT_BRAIN_PLUGIN_DIR : dirname( __DIR__, 3 );
	}
}
