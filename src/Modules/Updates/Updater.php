<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Updates;

/**
 * Built-in updates for self-hosted copies (#353): offers new releases from
 * TomaTío's GitHub Releases in WordPress's own Updates screen, and refuses a
 * download whose SHA-256 does not match the release's .sha256 file.
 *
 * WordPress asks for updates of a plugin whose `Update URI` header points
 * off WordPress.org through the `update_plugins_{hostname}` filter, so this
 * only runs while the header names the GitHub repository. The WordPress.org
 * build drops that header, and the updater steps aside by itself: nobody is
 * offered the same update twice.
 */
final class Updater {

	public const REPO          = 'tomatio-ai/tomatio';
	public const UPDATE_URI    = 'https://github.com/' . self::REPO;
	public const SLUG          = 'agent-brain';
	// The release zip is tomatio-<version>.zip since 1.9.10 (#773); the folder inside stays the slug.
	public const ZIP_PREFIX    = 'tomatio';
	public const TRANSIENT     = 'agent_brain_update_release';
	public const CACHE_TTL     = 12 * HOUR_IN_SECONDS;
	public const FAILURE_TTL   = HOUR_IN_SECONDS;
	private const API          = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
	private const DOWNLOAD     = 'https://github.com/' . self::REPO . '/releases/download/';

	/**
	 * Whether the updater runs for a plugin whose header carries $updateUri.
	 * Off when the header does not name our repository (the WordPress.org
	 * build), when AGENT_BRAIN_DISABLE_UPDATER is true, or by the
	 * `agent_brain/updater_enabled` filter.
	 */
	public static function enabledFor( string $updateUri ): bool {
		if ( untrailingslashit( trim( $updateUri ) ) !== self::UPDATE_URI ) {
			return false;
		}
		if ( defined( 'AGENT_BRAIN_DISABLE_UPDATER' ) && AGENT_BRAIN_DISABLE_UPDATER ) {
			return false;
		}

		return (bool) apply_filters( 'agent_brain/updater_enabled', true );
	}

	/** The `Update URI` header of the installed plugin file. */
	public static function headerUpdateUri(): string {
		if ( ! defined( 'AGENT_BRAIN_PLUGIN_FILE' ) ) {
			return '';
		}
		$data = get_file_data( AGENT_BRAIN_PLUGIN_FILE, [ 'UpdateURI' => 'Update URI' ] );

		return (string) ( $data['UpdateURI'] ?? '' );
	}

	/**
	 * `update_plugins_github.com`: the latest release for our plugin, or the
	 * value unchanged for any other plugin. WordPress compares the version
	 * and offers the update when it is newer.
	 *
	 * @param mixed $update
	 * @param mixed $pluginData
	 * @param mixed $pluginFile
	 * @return mixed
	 */
	public static function offer( $update, $pluginData, $pluginFile ) {
		if ( ! is_array( $pluginData ) || untrailingslashit( (string) ( $pluginData['UpdateURI'] ?? '' ) ) !== self::UPDATE_URI ) {
			return $update;
		}
		$release = self::latestRelease();
		if ( $release === null ) {
			return $update;
		}

		return [
			'id'           => self::UPDATE_URI,
			'slug'         => self::SLUG,
			'plugin'       => (string) $pluginFile,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => (string) ( $pluginData['RequiresWP'] ?? '' ),
			'requires_php' => (string) ( $pluginData['RequiresPHP'] ?? '' ),
		];
	}

	/**
	 * The latest stable release, cached: version, page url, zip and checksum
	 * urls, notes and date. Null when GitHub cannot be reached or the release
	 * lacks its zip or checksum; a failure is remembered for an hour so the
	 * admin is not slowed down by retries.
	 *
	 * @return array{version: string, url: string, package: string, checksum: string, notes: string, published: string}|null
	 */
	public static function latestRelease(): ?array {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return isset( $cached['version'] ) ? $cached : null;
		}

		$release = self::fetchRelease();
		set_site_transient( self::TRANSIENT, $release ?? [ 'failed' => time() ], $release === null ? self::FAILURE_TTL : self::CACHE_TTL );

		return $release;
	}

	/**
	 * @return array{version: string, url: string, package: string, checksum: string, notes: string, published: string}|null
	 */
	private static function fetchRelease(): ?array {
		$response = wp_safe_remote_get( self::API, self::requestArgs( 10, [ 'Accept' => 'application/vnd.github+json' ] ) );
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! empty( $body['draft'] ) || ! empty( $body['prerelease'] ) ) {
			return null;
		}

		$version = ltrim( (string) ( $body['tag_name'] ?? '' ), 'v' );
		if ( preg_match( '/^\d+\.\d+\.\d+$/', $version ) !== 1 ) {
			return null;
		}

		$assets = [];
		foreach ( (array) ( $body['assets'] ?? [] ) as $asset ) {
			if ( is_array( $asset ) && isset( $asset['name'], $asset['browser_download_url'] ) ) {
				$assets[ (string) $asset['name'] ] = (string) $asset['browser_download_url'];
			}
		}
		// Releases before 1.9.10 named the zip after the slug (agent-brain-<version>.zip).
		$zip = self::ZIP_PREFIX . '-' . $version . '.zip';
		if ( ! isset( $assets[ $zip ] ) ) {
			$zip = self::SLUG . '-' . $version . '.zip';
		}
		$package  = $assets[ $zip ] ?? '';
		$checksum = $assets[ $zip . '.sha256' ] ?? '';
		if ( ! self::isOurDownload( $package ) || ! self::isOurDownload( $checksum ) ) {
			return null;
		}

		return [
			'version'   => $version,
			'url'       => (string) ( $body['html_url'] ?? self::UPDATE_URI . '/releases' ),
			'package'   => $package,
			'checksum'  => $checksum,
			'notes'     => (string) ( $body['body'] ?? '' ),
			'published' => (string) ( $body['published_at'] ?? '' ),
		];
	}

	/** A release asset of our repository, over HTTPS. */
	private static function isOurDownload( string $url ): bool {
		return str_starts_with( $url, self::DOWNLOAD );
	}

	/**
	 * `upgrader_pre_download`: for our release zip, download it and its
	 * .sha256 file and hand WordPress the zip only when the hashes match.
	 * Any other package goes through untouched.
	 *
	 * @param mixed $reply
	 * @param mixed $package
	 * @return mixed A local file path, a WP_Error, or $reply.
	 */
	public static function verifiedDownload( $reply, $package ) {
		if ( $reply !== false || ! is_string( $package ) || ! self::isOurDownload( $package ) || ! str_ends_with( $package, '.zip' ) ) {
			return $reply;
		}

		$expected = self::expectedHash( $package . '.sha256' );
		if ( $expected === null ) {
			return new \WP_Error( 'agent_brain_update_checksum_missing', __( 'TomaTío could not read the checksum for this update, so it was not installed. Try again later, or download the release and check it by hand.', 'agent-brain' ) );
		}

		$response = wp_safe_remote_get( $package, self::requestArgs( 300 ) );
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return new \WP_Error( 'agent_brain_update_download_failed', __( 'TomaTío could not download this update. Try again later.', 'agent-brain' ) );
		}
		$zip = (string) wp_remote_retrieve_body( $response );

		if ( ! hash_equals( $expected, hash( 'sha256', $zip ) ) ) {
			return new \WP_Error( 'agent_brain_update_checksum', __( 'The downloaded update does not match its published checksum, so TomaTío did not install it. Nothing was changed.', 'agent-brain' ) );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = wp_tempnam( basename( $package ) );
		if ( ! $file || file_put_contents( $file, $zip ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file for the upgrader, as download_url() writes.
			return new \WP_Error( 'agent_brain_update_download_failed', __( 'TomaTío could not save this update. Check that the uploads folder is writable.', 'agent-brain' ) );
		}

		return $file;
	}

	/**
	 * Arguments for every request to GitHub. The user agent names the plugin
	 * only: WordPress's default one carries the site's address, which GitHub
	 * does not need to see.
	 *
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	private static function requestArgs( int $timeout, array $headers = [] ): array {
		return [
			'timeout'    => $timeout,
			'user-agent' => 'TomaTio/' . AGENT_BRAIN_VERSION,
			'headers'    => $headers,
		];
	}

	/** The lowercase SHA-256 from a sha256sum line ("<hex>  <file>"), or null. */
	private static function expectedHash( string $url ): ?string {
		$response = wp_safe_remote_get( $url, self::requestArgs( 30 ) );
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		return preg_match( '/^\s*([0-9a-fA-F]{64})\b/', (string) wp_remote_retrieve_body( $response ), $m ) === 1 ? strtolower( $m[1] ) : null;
	}

	/**
	 * `plugins_api`: the "View details" panel for our plugin, from the
	 * cached release; anything else goes through untouched.
	 *
	 * @param mixed $result
	 * @param mixed $action
	 * @param mixed $args
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}
		$release = self::latestRelease();
		if ( $release === null ) {
			return $result;
		}

		return (object) [
			'name'          => 'TomaTío',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'homepage'      => self::UPDATE_URI,
			'download_link' => $release['package'],
			'last_updated'  => $release['published'],
			'sections'      => [
				'changelog' => wpautop( esc_html( $release['notes'] !== '' ? $release['notes'] : __( 'See the release page for what changed.', 'agent-brain' ) ) ),
			],
		];
	}
}
