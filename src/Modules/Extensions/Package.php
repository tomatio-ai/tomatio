<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Extensions;

use AgentBrain\Modules\Artifacts\HtmlFiles;

/**
 * #606: an artifact extension package the plugin ships in
 * plugin/tomatio/extensions/<name>/, read and verified. The manifest is
 * `tomatio-extension.json`, in the contract 1 shape of
 * docs/rfc/artifact-extensions.md (#722), so the same folder can later be
 * cut into its own repository and installed like any other extension.
 *
 * Every file the manifest lists must be there with its SHA-256; a missing
 * file or a wrong hash refuses the whole package. A file may say
 * `bundled_from`: a path inside the plugin it is read from instead (so the
 * zip carries the brand icon once, #788). Files the manifest does not list
 * are never served.
 *
 * Plain PHP, no WordPress: a load error is a string.
 */
final class Package {

	public const MANIFEST = 'tomatio-extension.json';
	public const CONTRACT = 1;

	private const ID_PATTERN      = '/^[a-z][a-z0-9-]*(\.[a-z0-9-]+)+$/';
	private const VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';
	private const BINDINGS        = [ 'person', 'brain', 'any' ];

	/**
	 * @param array<string, mixed>  $manifest
	 * @param array<string, string> $files path => bytes, the entry first
	 */
	private function __construct( private array $manifest, private array $files ) {
	}

	/** The package shipped in the plugin's extensions/$name folder. */
	public static function bundled( string $name ): self|string {
		if ( preg_match( '/^[a-z0-9-]+$/', $name ) !== 1 ) {
			return 'Invalid package name.';
		}

		return self::load( self::bundledDir( $name ), self::pluginDir() );
	}

	/** The folder of the package shipped as extensions/$name (#818: TioDayRefresh fingerprints its manifest). */
	public static function bundledDir( string $name ): string {
		return self::pluginDir() . '/extensions/' . $name;
	}

	/**
	 * Read and verify the package in $dir. `bundled_from` paths resolve
	 * against $root (the plugin folder); without a root they are refused.
	 */
	public static function load( string $dir, string $root = '' ): self|string {
		$raw = is_readable( $dir . '/' . self::MANIFEST ) ? file_get_contents( $dir . '/' . self::MANIFEST ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside the plugin.
		$manifest = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $manifest ) ) {
			return 'The package has no readable ' . self::MANIFEST . '.';
		}

		$problem = self::checkManifest( $manifest );
		if ( $problem !== '' ) {
			return $problem;
		}

		$files = [];
		foreach ( $manifest['files'] as $file ) {
			$path = (string) $file['path'];
			$from = isset( $file['bundled_from'] ) ? (string) $file['bundled_from'] : '';
			if ( $from !== '' && ( $root === '' || ! self::isInside( $from ) ) ) {
				return 'File ' . $path . ': bundled_from must be a path inside the plugin.';
			}

			$source = $from !== '' ? $root . '/' . $from : $dir . '/' . $path;
			$bytes  = is_readable( $source ) ? file_get_contents( $source ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file inside the plugin.
			if ( ! is_string( $bytes ) ) {
				return 'File ' . $path . ' is missing.';
			}
			if ( ! hash_equals( strtolower( (string) $file['sha256'] ), hash( 'sha256', $bytes ) ) ) {
				return 'File ' . $path . ' does not match its SHA-256 in the manifest.';
			}
			$files[ $path ] = $bytes;
		}

		$entry = (string) $manifest['entry'];

		return new self( $manifest, [ $entry => $files[ $entry ] ] + $files );
	}

	/**
	 * The first reason $manifest is not a contract 1 manifest, or ''.
	 *
	 * @param array<string, mixed> $manifest
	 */
	public static function checkManifest( array $manifest ): string {
		if ( ( $manifest['contract'] ?? null ) !== self::CONTRACT ) {
			return 'The manifest targets another contract; this plugin reads contract ' . self::CONTRACT . '.';
		}
		if ( ! is_string( $manifest['id'] ?? null ) || preg_match( self::ID_PATTERN, $manifest['id'] ) !== 1 ) {
			return 'The manifest id must look like publisher.name (lower case).';
		}
		if ( ! is_string( $manifest['version'] ?? null ) || preg_match( self::VERSION_PATTERN, $manifest['version'] ) !== 1 ) {
			return 'The manifest version must be major.minor.patch.';
		}
		if ( ! is_string( $manifest['name'] ?? null ) || trim( $manifest['name'] ) === '' ) {
			return 'The manifest needs a name.';
		}
		if ( ! is_array( $manifest['files'] ?? null ) || $manifest['files'] === [] ) {
			return 'The manifest lists no files.';
		}

		$paths = [];
		foreach ( $manifest['files'] as $file ) {
			$path = is_array( $file ) && is_string( $file['path'] ?? null ) ? $file['path'] : '';
			if ( ! HtmlFiles::isValidPath( $path ) || isset( $paths[ strtolower( $path ) ] ) ) {
				return 'The manifest lists an invalid or repeated file path.';
			}
			if ( ! is_string( $file['sha256'] ?? null ) || preg_match( '/^[0-9a-f]{64}$/i', $file['sha256'] ) !== 1 ) {
				return 'File ' . $path . ' needs a sha256.';
			}
			$paths[ strtolower( $path ) ] = true;
		}

		if ( ! is_string( $manifest['entry'] ?? null ) || ! isset( $paths[ strtolower( $manifest['entry'] ) ] ) ) {
			return 'The manifest entry must be one of its files.';
		}
		// Contract 1, phase 0: a bundled page runs as a plain HTML page, so it
		// may not ask for more than one (no sandbox flags, no outside hosts).
		foreach ( [ 'sandbox', 'connect_hosts', 'script_hosts' ] as $capability ) {
			if ( ( $manifest['capabilities'][ $capability ] ?? [] ) !== [] ) {
				return 'Capability ' . $capability . ' needs the extension install flow (#722), which is not built yet.';
			}
		}
		if ( ! is_array( $manifest['store']['collections'] ?? null ) ) {
			return 'The manifest needs store.collections (an empty object for none).';
		}
		if ( ! in_array( $manifest['binding']['per'] ?? null, self::BINDINGS, true ) ) {
			return 'The manifest binding.per must be person, brain or any.';
		}

		return '';
	}

	public function id(): string {
		return (string) $this->manifest['id'];
	}

	public function name(): string {
		return (string) $this->manifest['name'];
	}

	public function version(): string {
		return (string) $this->manifest['version'];
	}

	/** @return array<string, mixed> */
	public function collections(): array {
		return (array) $this->manifest['store']['collections'];
	}

	/** @return array<string, mixed> */
	public function binding(): array {
		return (array) $this->manifest['binding'];
	}

	/**
	 * The files as HtmlFiles::put() takes them (base64, the entry first).
	 *
	 * @return array<int, array{path:string, content:string, encoding:string}>
	 */
	public function htmlFiles(): array {
		$out = [];
		foreach ( $this->files as $path => $bytes ) {
			$out[] = [ 'path' => $path, 'content' => base64_encode( $bytes ), 'encoding' => 'base64' ];
		}

		return $out;
	}

	private static function isInside( string $path ): bool {
		return HtmlFiles::isValidPath( $path );
	}

	private static function pluginDir(): string {
		return defined( 'AGENT_BRAIN_PLUGIN_DIR' ) ? (string) AGENT_BRAIN_PLUGIN_DIR : dirname( __DIR__, 3 );
	}
}
