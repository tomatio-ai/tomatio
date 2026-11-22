<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Guide;

use AgentBrain\Modules\Admin\DateFormat;
use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\Ui;

/**
 * #861: TomaTío > Help, the manual in wp-admin: its pages, a search, and
 * links between pages that stay on this screen. Everything on it is public
 * documentation, so anyone who reaches TomaTío's menu may read it.
 */
final class HelpPage {

	public const SLUG       = 'agent-brain-help';
	public const CAPABILITY = 'read';

	public static function register(): void {
		add_action('admin_menu', [self::class, 'addMenu']);
	}

	public static function addMenu(): void {
		add_submenu_page(Menu::PARENT, __('Help', 'tomatio'), __('Help', 'tomatio'), self::CAPABILITY, self::SLUG, [self::class, 'render']);
	}

	/** The Help screen at one page, and optionally a section of it. */
	public static function url(string $page = '', string $anchor = ''): string {
		$url = admin_url('admin.php?page=' . self::SLUG . ($page !== '' && $page !== 'README' ? '&doc=' . rawurlencode($page) : ''));

		return $anchor !== '' ? $url . '#' . rawurlencode($anchor) : $url;
	}

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to view this page.', 'tomatio'), 403);
		}

		$manual = Manual::current();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation of public documentation.
		$query = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
		$name  = isset($_GET['doc']) ? sanitize_key(wp_unslash((string) $_GET['doc'])) : '';
		// phpcs:enable
		$name = $name === '' || $name === 'readme' ? 'README' : $name;

		$status = $manual['source'] === 'tomatio.ai'
			/* translators: 1: manual version, 2: date it was fetched. */
			? sprintf(esc_html__('The manual for TomaTío %1$s, from tomatio.ai (fetched %2$s)', 'tomatio'), esc_html($manual['version']), esc_html(DateFormat::text(gmdate('Y-m-d H:i:s', (int) $manual['fetched_at']))))
			: esc_html__('The core pages of the manual, bundled with this version. The rest loads from tomatio.ai once a day.', 'tomatio');

		$actions = '<a class="button" href="' . esc_url(Manual::DOCS_URL) . '" target="_blank" rel="noopener">' . esc_html__('Open on tomatio.ai', 'tomatio') . '</a>';
		$about   = '<p>' . esc_html__('This is TomaTío\'s own manual, not your team\'s knowledge: it is not stored as memory and is not part of an export. Your AI reads the same pages through the tomatio-help tool.', 'tomatio') . '</p>';

		echo '<div class="wrap ab-admin ab-admin-help">';
		echo Ui::head(__('Help', 'tomatio'), $status, $actions, $about); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

		echo '<form method="get" class="ab-help-search" role="search">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />';
		echo '<label class="screen-reader-text" for="ab-help-q">' . esc_html__('Search the manual', 'tomatio') . '</label>';
		echo '<input type="search" id="ab-help-q" name="q" value="' . esc_attr($query) . '" placeholder="' . esc_attr__('For example: move this brain to hosting', 'tomatio') . '" /> ';
		echo '<button type="submit" class="button">' . esc_html__('Search', 'tomatio') . '</button></form>';

		echo '<div class="ab-help">';
		self::renderNav($manual['pages'], $query === '' ? $name : '');
		echo '<div class="ab-help-body">';
		if ($query !== '') {
			self::renderResults($query, $manual['pages']);
		} else {
			self::renderPage($name, $manual['pages']);
		}
		echo '</div></div></div>';
	}

	/**
	 * @param array<int, array{name:string, title:string, url:string, html:string}> $pages
	 */
	private static function renderNav(array $pages, string $current): void {
		echo '<nav class="ab-help-nav" aria-label="' . esc_attr__('Manual pages', 'tomatio') . '"><ul>';
		foreach ($pages as $page) {
			$here = $page['name'] === $current;
			echo '<li><a href="' . esc_url(self::url($page['name'])) . '"' . ($here ? ' aria-current="page"' : '') . '>' . esc_html($page['name'] === 'README' ? __('Contents', 'tomatio') : $page['title']) . '</a></li>';
		}
		echo '</ul></nav>';
	}

	/**
	 * @param array<int, array{name:string, title:string, url:string, html:string}> $pages
	 */
	private static function renderPage(string $name, array $pages): void {
		$page = Manual::page($name);
		if ($page === null) {
			$link = ' <a href="' . esc_url(Manual::DOCS_URL . ($name !== 'README' ? '/' . $name : '')) . '" target="_blank" rel="noopener">' . esc_html__('Read it on tomatio.ai', 'tomatio') . '</a>';
			$note = Ui::callout('info', __('This page is not here yet.', 'tomatio'), esc_html__('This brain has only the core pages so far.', 'tomatio') . $link);
			echo $note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.

			return;
		}

		echo '<article class="ab-help-page">' . wp_kses_post(self::localLinks($page['html'], $pages)) . '</article>';
	}

	/**
	 * @param array<int, array{name:string, title:string, url:string, html:string}> $pages
	 */
	private static function renderResults(string $query, array $pages): void {
		$hits = Manual::search($query, 8);
		/* translators: %s: what was searched for. */
		echo '<h2>' . esc_html(sprintf(__('Results for "%s"', 'tomatio'), $query)) . '</h2>';
		if ($hits === []) {
			echo '<p>' . esc_html__('Nothing in the manual matches. Try other words, or pick a page.', 'tomatio') . '</p>';

			return;
		}

		$names = array_column($pages, 'name');
		echo '<ol class="ab-help-results">';
		foreach ($hits as $hit) {
			$anchor = (string) (explode('#', $hit['url'], 2)[1] ?? '');
			$href   = in_array($hit['page'], $names, true) ? self::url($hit['page'], $anchor) : $hit['url'];
			$label  = $hit['section'] !== '' && $hit['section'] !== $hit['title'] ? $hit['title'] . ' › ' . $hit['section'] : $hit['title'];
			echo '<li><a href="' . esc_url($href) . '"><strong>' . esc_html($label) . '</strong></a>'
				. '<p class="description">' . esc_html(Manual::clip((string) preg_replace('/\s+/', ' ', str_replace(['**', '`', '#'], '', $hit['text'])), 280)) . '</p></li>';
		}
		echo '</ol>';
	}

	/**
	 * Links to manual pages this brain has stay on this screen; every other
	 * link opens in a new tab. The page's h1 becomes an h2 under the
	 * screen's own title.
	 *
	 * @param array<int, array{name:string, title:string, url:string, html:string}> $pages
	 */
	public static function localLinks(string $html, array $pages): string {
		$names = array_column($pages, 'name');
		$html  = (string) preg_replace('/<(\/?)h1\b/i', '<$1h2', $html);

		return (string) preg_replace_callback('/<a\b([^>]*?)href="([^"]*)"([^>]*)>/i', static function (array $m) use ($names): string {
			$href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
			if (str_starts_with($href, '#')) {
				return $m[0];
			}

			if (preg_match('~^https://tomatio\.ai/docs(?:/([a-z0-9-]+))?/?(?:#(.*))?$~', $href, $doc) === 1) {
				$name = ($doc[1] ?? '') !== '' ? $doc[1] : 'README';
				if (in_array($name, $names, true)) {
					return '<a' . $m[1] . 'href="' . esc_url(self::url($name, (string) ($doc[2] ?? ''))) . '"' . $m[3] . '>';
				}
			}

			return '<a' . $m[1] . 'href="' . esc_url($href) . '" target="_blank" rel="noopener"' . $m[3] . '>';
		}, $html);
	}
}
