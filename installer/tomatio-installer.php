<?php
/**
 * Plugin Name:       TomaTío Installer
 * Plugin URI:        https://tomatio.ai/self-host
 * Description:       Installs TomaTío from its GitHub releases when the full zip is too big to upload. Checks the download against the release's SHA-256 checksum, installs TomaTío, then removes itself.
 * Version:           1.0.0
 * Requires at least: 7.1
 * Requires PHP:      8.3
 * Update URI:        false
 * Author:            Dennis Kittrell, Roger Gentry
 * Author URI:        https://tomatio.ai
 * License:           GPL-2.0-or-later
 * Text Domain:       tomatio
 *
 * The full TomaTío zip can be larger than a host's upload limit (PHP's
 * default upload_max_filesize is 2 MB). This plugin is a few kilobytes, so it
 * always uploads. It downloads the latest stable release from
 * github.com/tomatio-ai/tomatio, the same place and the same checksum check
 * as TomaTío's own updater (src/Modules/Updates/Updater.php), shows the
 * version and address before it installs anything, and deletes itself once
 * TomaTío is in place (B220).
 *
 * @package TomaTioInstaller
 */

declare(strict_types=1);

namespace TomaTio\Installer;

if (! defined('ABSPATH')) {
	exit;
}

final class Installer {

	public const VERSION  = '1.0.0';
	public const REPO     = 'tomatio-ai/tomatio';
	public const API      = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
	public const DOWNLOAD = 'https://github.com/' . self::REPO . '/releases/download/';
	public const TARGET   = 'tomatio/tomatio.php';
	public const PAGE     = 'tomatio-installer';
	public const ACTION   = 'tomatio_installer_install';
	public const REDIRECT = 'tomatio_installer_redirect';
	public const NOTICE   = 'tomatio_installer_error_';

	public static function boot(string $file): void {
		register_activation_hook($file, [self::class, 'activated']);
		add_action('admin_menu', [self::class, 'menu']);
		add_action('admin_init', [self::class, 'redirectAfterActivation']);
		add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
		add_filter('plugin_action_links_' . plugin_basename($file), [self::class, 'actionLinks']);
	}

	public static function activated(): void {
		set_transient(self::REDIRECT, get_current_user_id(), MINUTE_IN_SECONDS);
	}

	/** Right after activation, open the installer page (once, for the person who activated it). */
	public static function redirectAfterActivation(): void {
		$user = get_transient(self::REDIRECT);
		if ($user === false) {
			return;
		}
		delete_transient(self::REDIRECT);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads whether this is a bulk activation, as core's own redirects do.
		if ((int) $user !== get_current_user_id() || isset($_GET['activate-multi']) || wp_doing_ajax()) {
			return;
		}
		wp_safe_redirect(self::pageUrl());
		exit;
	}

	public static function menu(): void {
		add_plugins_page(
			__('Install TomaTío', 'tomatio'),
			__('Install TomaTío', 'tomatio'),
			'install_plugins',
			self::PAGE,
			[self::class, 'render']
		);
	}

	/**
	 * @param array<int|string, string> $links
	 * @return array<int|string, string>
	 */
	public static function actionLinks(array $links): array {
		array_unshift($links, '<a href="' . esc_url(self::pageUrl()) . '">' . esc_html__('Install TomaTío', 'tomatio') . '</a>');

		return $links;
	}

	public static function pageUrl(): string {
		return admin_url('plugins.php?page=' . self::PAGE);
	}

	/**
	 * The latest stable release, or null when GitHub cannot be read or the
	 * release lacks a zip and checksum served from our repository.
	 *
	 * @return array{version: string, url: string, package: string, checksum: string, size: int}|null
	 */
	public static function latest(): ?array {
		$response = wp_safe_remote_get(self::API, self::requestArgs(15, ['Accept' => 'application/vnd.github+json']));
		if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
			return null;
		}
		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		if (! is_array($body) || ! empty($body['draft']) || ! empty($body['prerelease'])) {
			return null;
		}
		$version = ltrim((string) ($body['tag_name'] ?? ''), 'v');
		if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
			return null;
		}

		$zip    = 'tomatio-' . $version . '.zip';
		$assets = [];
		foreach ((array) ($body['assets'] ?? []) as $asset) {
			if (is_array($asset) && isset($asset['name'], $asset['browser_download_url'])) {
				$assets[(string) $asset['name']] = $asset;
			}
		}
		$package  = (string) ($assets[$zip]['browser_download_url'] ?? '');
		$checksum = (string) ($assets[$zip . '.sha256']['browser_download_url'] ?? '');
		if (! self::isOurDownload($package) || ! self::isOurDownload($checksum)) {
			return null;
		}

		return [
			'version'  => $version,
			'url'      => (string) ($body['html_url'] ?? 'https://github.com/' . self::REPO . '/releases'),
			'package'  => $package,
			'checksum' => $checksum,
			'size'     => (int) ($assets[$zip]['size'] ?? 0),
		];
	}

	/** A release asset of our repository, over HTTPS. */
	public static function isOurDownload(string $url): bool {
		return str_starts_with($url, self::DOWNLOAD) && ! str_contains($url, '..');
	}

	/**
	 * Downloads the release zip and its .sha256 file and returns the path of
	 * a temporary copy of the zip, only when the two match.
	 *
	 * @param array{package: string, checksum: string} $release
	 * @return string|\WP_Error
	 */
	public static function download(array $release) {
		if (! self::isOurDownload($release['package']) || ! self::isOurDownload($release['checksum'])) {
			return new \WP_Error('tomatio_installer_source', __('That download is not a TomaTío release on GitHub, so nothing was installed.', 'tomatio'));
		}

		$sum = wp_safe_remote_get($release['checksum'], self::requestArgs(30));
		if (is_wp_error($sum) || (int) wp_remote_retrieve_response_code($sum) !== 200
			|| preg_match('/^\s*([0-9a-fA-F]{64})\b/', (string) wp_remote_retrieve_body($sum), $m) !== 1) {
			return new \WP_Error('tomatio_installer_checksum_missing', __('The installer could not read the release checksum, so nothing was installed. Try again in a few minutes.', 'tomatio'));
		}
		$expected = strtolower($m[1]);

		$response = wp_safe_remote_get($release['package'], self::requestArgs(300));
		if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
			return new \WP_Error('tomatio_installer_download', __('The installer could not download TomaTío from GitHub. Try again in a few minutes.', 'tomatio'));
		}
		$zip = (string) wp_remote_retrieve_body($response);
		if (! hash_equals($expected, hash('sha256', $zip))) {
			return new \WP_Error('tomatio_installer_checksum', __('The download does not match the checksum published with the release, so nothing was installed.', 'tomatio'));
		}

		if (! function_exists('wp_tempnam')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = wp_tempnam(basename($release['package']));
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file for the upgrader, as download_url() writes.
		if (! $file || file_put_contents($file, $zip) === false) {
			return new \WP_Error('tomatio_installer_save', __('The installer could not save the download. Check that the uploads folder is writable.', 'tomatio'));
		}

		return $file;
	}

	/** @return true|\WP_Error */
	public static function install(string $file) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader($skin);
		$result   = $upgrader->install($file);
		if (is_wp_error($result)) {
			return $result;
		}
		if ($skin->get_errors()->has_errors()) {
			return $skin->get_errors();
		}
		if ($result !== true) {
			return new \WP_Error('tomatio_installer_install', __('WordPress could not install TomaTío. If your server asks for FTP details to install plugins, install TomaTío with WP-CLI instead (see the install guide).', 'tomatio'));
		}

		return true;
	}

	public static function installed(): bool {
		return file_exists(WP_PLUGIN_DIR . '/' . self::TARGET);
	}

	/** admin-post: install (unless already there), remove this plugin, then let WordPress activate TomaTío. */
	public static function handle(): void {
		if (! current_user_can('install_plugins') || ! current_user_can('activate_plugins')) {
			wp_die(esc_html__('You are not allowed to install plugins on this site.', 'tomatio'), 403);
		}
		check_admin_referer(self::ACTION);

		if (! self::installed()) {
			$release = self::latest();
			$result  = $release === null
				? new \WP_Error('tomatio_installer_release', __('The installer could not read the latest TomaTío release from GitHub. Try again in a few minutes.', 'tomatio'))
				: self::download($release);
			if (is_string($result)) {
				$file   = $result;
				$result = self::install($file);
				wp_delete_file($file);
			}
			if (is_wp_error($result)) {
				set_transient(self::NOTICE . get_current_user_id(), $result->get_error_message(), 10 * MINUTE_IN_SECONDS);
				wp_safe_redirect(self::pageUrl());
				exit;
			}
		}

		self::removeSelf();
		// Activation goes through WordPress's own screen, in a fresh request,
		// so TomaTío starts exactly as it does after a normal upload.
		wp_safe_redirect(admin_url('plugins.php?action=activate&plugin=' . rawurlencode(self::TARGET) . '&_wpnonce=' . wp_create_nonce('activate-plugin_' . self::TARGET)));
		exit;
	}

	/** Deactivate and delete this plugin. If the files cannot be deleted, it stays, inactive. */
	public static function removeSelf(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$self = plugin_basename(__FILE__);
		deactivate_plugins($self, true);
		// delete_plugins() shows WordPress's FTP form when it cannot write
		// files directly; then the installer just stays, inactive.
		if (get_filesystem_method() === 'direct') {
			delete_plugins([$self]);
		}
	}

	public static function render(): void {
		if (! current_user_can('install_plugins')) {
			return;
		}
		$key   = self::NOTICE . get_current_user_id();
		$error = get_transient($key);
		delete_transient($key);

		echo '<div class="wrap"><h1>' . esc_html__('Install TomaTío', 'tomatio') . '</h1>';
		if (is_string($error) && $error !== '') {
			echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
		}
		echo '<p>' . esc_html__('This small plugin downloads TomaTío from its releases on GitHub, checks the download against the SHA-256 checksum published with the release, installs TomaTío and then removes itself.', 'tomatio') . '</p>';

		if (self::installed()) {
			echo '<p>' . esc_html__('TomaTío is already installed on this site.', 'tomatio') . '</p>';
			self::form(__('Activate TomaTío and remove this installer', 'tomatio'));
			echo '</div>';
			return;
		}

		$release = self::latest();
		if ($release === null) {
			echo '<div class="notice notice-warning"><p>' . esc_html__('The installer could not read the latest release from GitHub. Try again in a few minutes, or download the zip from the releases page and install it with WP-CLI.', 'tomatio') . '</p></div>';
			echo '<p><a href="' . esc_url('https://github.com/' . self::REPO . '/releases/latest') . '">' . esc_html__('TomaTío releases on GitHub', 'tomatio') . '</a></p></div>';
			return;
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__('Version', 'tomatio') . '</th><td><strong>' . esc_html($release['version']) . '</strong> (<a href="' . esc_url($release['url']) . '">' . esc_html__('release notes', 'tomatio') . '</a>)</td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Download', 'tomatio') . '</th><td><code>' . esc_html($release['package']) . '</code>';
		if ($release['size'] > 0) {
			echo ' (' . esc_html(size_format($release['size'], 1)) . ')';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Checksum', 'tomatio') . '</th><td><code>' . esc_html($release['checksum']) . '</code></td></tr>';
		echo '</tbody></table>';
		/* translators: %s: TomaTío version, such as 1.10.2. */
		self::form(sprintf(__('Download and install TomaTío %s', 'tomatio'), $release['version']));
		echo '</div>';
	}

	private static function form(string $label): void {
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
		wp_nonce_field(self::ACTION);
		submit_button($label, 'primary', 'submit', false);
		echo '</form>';
	}

	/**
	 * The user agent names the installer only: WordPress's default one
	 * carries the site's address, which GitHub does not need to see.
	 *
	 * @param array<string, string> $headers
	 * @return array<string, mixed>
	 */
	private static function requestArgs(int $timeout, array $headers = []): array {
		return [
			'timeout'    => $timeout,
			'user-agent' => 'TomaTio-Installer/' . self::VERSION,
			'headers'    => $headers,
		];
	}
}

Installer::boot(__FILE__);
