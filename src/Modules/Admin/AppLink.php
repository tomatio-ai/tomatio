<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Identity\Capabilities;
use WP_Error;

/**
 * #449: a link from the brain back to the team's own app or site ("Back to
 * Cumulus.aero"). An http(s) URL plus an optional label (default: "Back to"
 * and the brand name). Shown, when set, on the dedicated home page, the
 * sign-in page and the wp-admin toolbar.
 *
 * Set in Settings > Site or with the brain-app-link-set ability, by people
 * holding ab_manage (administrators and managers). Every change writes an
 * audit entry. Not part of any export: brand settings are not exported.
 */
final class AppLink {

	public const OPTION      = 'agent_brain_app_link';
	public const ACTION      = 'agent_brain_save_app_link';
	public const AUDIT_EVENT = 'app_link.updated';

	public const MAX_URL   = 2048;
	public const MAX_LABEL = 80;

	public static function register(): void {
		add_filter('login_site_html_link', [self::class, 'loginLink']);
		add_action('admin_bar_menu', [self::class, 'adminBar'], 40);
		add_action('admin_post_' . self::ACTION, [self::class, 'handleSave']);
	}

	public static function canManage(int $actorId): bool {
		return $actorId > 0 && user_can($actorId, Capabilities::CAP_MANAGE);
	}

	/**
	 * The stored link, or null when none is set (or the stored URL no
	 * longer passes validation).
	 *
	 * @return array{url:string, label:string, custom_label:bool}|null
	 */
	public static function get(): ?array {
		$stored = get_option(self::OPTION, []);
		if (! is_array($stored)) {
			return null;
		}

		$url = is_string($stored['url'] ?? null) ? $stored['url'] : '';
		if (self::validUrl($url) === null) {
			return null;
		}

		$label = is_string($stored['label'] ?? null) ? $stored['label'] : '';

		return [
			'url'          => $url,
			'label'        => $label !== '' ? $label : self::defaultLabel($url),
			'custom_label' => $label !== '',
		];
	}

	/**
	 * Set (or, with an empty URL, clear) the link. Validates, saves and
	 * audits; the caller has already checked canManage().
	 *
	 * @return array{url:string, label:string, custom_label:bool}|null|WP_Error The new link, null when cleared.
	 */
	public static function set(int $actorId, string $url, string $label = '', string $via = 'settings') {
		$url   = trim($url);
		$label = trim(sanitize_text_field($label));

		if ($url === '') {
			$before = self::get();
			delete_option(self::OPTION);
			if ($before !== null) {
				self::audit($actorId, $via, $before, null);
			}

			return null;
		}

		$clean = self::validUrl($url);
		if ($clean === null) {
			return new WP_Error('ab_invalid_input', __('The app link must be a full http:// or https:// address.', 'agent-brain'), ['status' => 422, 'field' => 'url']);
		}

		if (mb_strlen($label) > self::MAX_LABEL) {
			/* translators: %d: maximum number of characters. */
			return new WP_Error('ab_invalid_input', sprintf(__('The app link label can be at most %d characters.', 'agent-brain'), self::MAX_LABEL), ['status' => 422, 'field' => 'label']);
		}

		$before = self::get();
		update_option(self::OPTION, ['url' => $clean, 'label' => $label], false);
		$after = self::get();
		if ($after !== $before) {
			self::audit($actorId, $via, $before, $after);
		}

		return $after;
	}

	/**
	 * The URL when it is a well-formed http(s) address, else null.
	 */
	public static function validUrl(string $url): ?string {
		if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1) {
			return null;
		}

		$parts  = wp_parse_url($url);
		$scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
		if (! in_array($scheme, ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
			return null;
		}

		$clean = esc_url_raw($url, ['http', 'https']);

		return $clean !== '' ? $clean : null;
	}

	/** "Back to" plus the brand name (the site title), or the link's host. */
	public static function defaultLabel(string $url): string {
		$site = trim(html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8'));
		$name = trim((string) apply_filters('agent_brain/brand_name', $site));
		if ($name === '') {
			$name = (string) wp_parse_url($url, PHP_URL_HOST);
		}

		/* translators: %s: the team's site or app name. */
		return sprintf(__('Back to %s', 'agent-brain'), $name);
	}

	/**
	 * The link as an escaped anchor, or '' when none is set.
	 */
	public static function anchor(string $class = 'ab-app-link'): string {
		$link = self::get();
		if ($link === null) {
			return '';
		}

		return '<a class="' . esc_attr($class) . '" href="' . esc_url($link['url'], ['http', 'https']) . '" rel="noopener">' . esc_html($link['label']) . '</a>';
	}

	/**
	 * Sign-in page: the app link next to WordPress's "Go to site" link.
	 *
	 * @param string $html
	 */
	public static function loginLink($html): string {
		$anchor = self::anchor();

		return $anchor === '' ? (string) $html : (string) $html . '<br>' . $anchor;
	}

	public static function adminBar(\WP_Admin_Bar $bar): void {
		$link = self::get();
		if ($link === null) {
			return;
		}

		$bar->add_node([
			'id'    => 'ab-app-link',
			'title' => esc_html($link['label']),
			'href'  => esc_url($link['url'], ['http', 'https']),
			'meta'  => ['rel' => 'noopener'],
		]);
	}

	/**
	 * Settings > Site tab: the app link form (SettingsHub already checked the capability).
	 */
	public static function renderBody(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only result flags set by our own redirect.
		if (isset($_GET['ab_app_link_saved'])) {
			echo Notices::html('success', __('App link saved.', 'agent-brain')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notices::html() escapes.
		}
		if (isset($_GET['ab_app_link_error'])) {
			$message = sanitize_key(wp_unslash((string) $_GET['ab_app_link_error'])) === 'label'
				/* translators: %d: maximum number of characters. */
				? sprintf(__('The app link label can be at most %d characters.', 'agent-brain'), self::MAX_LABEL)
				: __('The app link must be a full http:// or https:// address.', 'agent-brain');
			echo Notices::html('error', $message); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notices::html() escapes.
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$stored = get_option(self::OPTION, []);
		$stored = is_array($stored) ? $stored : [];
		$link   = self::get();

		$label = is_string($stored['label'] ?? null) ? $stored['label'] : '';

		echo '<section class="ab-ui-card"><h3 class="ab-ui-kicker">' . esc_html__('Link back to your app', 'agent-brain') . '</h3>';
		echo '<p class="description">' . esc_html__('A link from the brain to your team\'s own app or site, shown on the home page, the sign-in page and the admin toolbar. Leave the address empty for none.', 'agent-brain') . '</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::ACTION);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		echo Ui::setting(
			__('Address', 'agent-brain'),
			$link !== null ? '<code>' . esc_html($link['url']) . '</code>' : esc_html__('None: no link is shown.', 'agent-brain'),
			'<input type="url" name="ab_app_link_url" id="ab-app-link-url" class="regular-text" placeholder="https://" value="' . esc_attr($link['url'] ?? '') . '" />',
			'ab-app-link-url'
		);
		echo Ui::setting(
			__('Label', 'agent-brain'),
			$label !== ''
				? '<b>' . esc_html($label) . '</b>'
				/* translators: %s: the default label, such as "Back to Acme" */
				: esc_html(sprintf(__('Default: "%s".', 'agent-brain'), self::defaultLabel(''))),
			'<input type="text" name="ab_app_link_label" id="ab-app-link-label" class="regular-text" maxlength="' . esc_attr((string) self::MAX_LABEL) . '" placeholder="' . esc_attr(self::defaultLabel('')) . '" value="' . esc_attr($label) . '" />',
			'ab-app-link-label'
		);
		echo Ui::saveBar('ab_app_link_submit', __('Save app link', 'agent-brain'));
		// phpcs:enable
		echo '</form></section>';
	}

	public static function handleSave(): void {
		if (! current_user_can(SettingsHub::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage TomaTío settings.', 'agent-brain'), 403);
		}

		check_admin_referer(self::ACTION);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- set() validates the URL and sanitizes the label.
		$result = self::saveFromRequest((array) wp_unslash($_POST), get_current_user_id());
		$field  = $result instanceof WP_Error ? (array) $result->get_error_data() : [];
		$args   = $result instanceof WP_Error ? ['ab_app_link_error' => ($field['field'] ?? '') === 'label' ? 'label' : 'url'] : ['ab_app_link_saved' => '1'];

		wp_safe_redirect(SettingsHub::url(SettingsHub::TAB_SITE, $args));
		exit;
	}

	/**
	 * The Settings form's save, without the redirect (testable).
	 *
	 * @param array<string, mixed> $post Unslashed request fields.
	 * @return array{url:string, label:string, custom_label:bool}|null|WP_Error
	 */
	public static function saveFromRequest(array $post, int $actorId) {
		if (! self::canManage($actorId)) {
			return new WP_Error('ab_forbidden', __('Only administrators and managers can change the app link.', 'agent-brain'), ['status' => 403]);
		}

		$url   = is_string($post['ab_app_link_url'] ?? null) ? $post['ab_app_link_url'] : '';
		$label = is_string($post['ab_app_link_label'] ?? null) ? $post['ab_app_link_label'] : '';

		return self::set($actorId, $url, $label, 'settings');
	}

	/**
	 * @param array{url:string, label:string, custom_label:bool}|null $before
	 * @param array{url:string, label:string, custom_label:bool}|null $after
	 */
	private static function audit(int $actorId, string $via, ?array $before, ?array $after): void {
		do_action('agent_brain/audit', [
			'actor_id'    => $actorId,
			'event'       => self::AUDIT_EVENT,
			'status'      => 'ok',
			'object_type' => 'option',
			'object_id'   => self::OPTION,
			'meta'        => [
				'via'      => $via,
				'cleared'  => $after === null,
				'url'      => $after['url'] ?? '',
				'label'    => $after !== null && $after['custom_label'] ? $after['label'] : '',
				'previous' => $before['url'] ?? '',
			],
		]);
	}
}
