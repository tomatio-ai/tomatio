<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * The shared pieces of the admin redesign (#751), taken from the Review
 * inbox (#701): a page head that says what needs you, the page's
 * explanation behind "About this page", callouts that say what broke and
 * the one thing to do, filter chips, a facts list and the list-and-detail
 * layout. Styles are the .ab-ui-* rules in assets/admin.css; the empty
 * state stays EmptyState.
 *
 * Every method returns escaped HTML. Arguments named $html take markup the
 * caller built (buttons, forms) and are passed through wp_kses() with the
 * admin's own allow-list; plain-text arguments are escaped here.
 */
final class Ui {

	/** Inline markup allowed in a status line or a fact. */
	private const INLINE = [
		'a'      => ['href' => true, 'class' => true, 'title' => true],
		'b'      => [],
		'strong' => [],
		'em'     => [],
		'code'   => [],
		'span'   => ['class' => true, 'title' => true],
		'time'   => ['datetime' => true, 'title' => true],
	];

	/**
	 * The title, one line on how things stand (inline markup allowed), the
	 * page's main actions on the right, and its explanation folded away
	 * below. The explanation is closed by default: people come here to get
	 * something done, and the words stay one click away.
	 */
	public static function head(string $title, string $status = '', string $actionsHtml = '', string $aboutHtml = ''): string {
		$out  = '<div class="ab-ui-head"><h1>' . esc_html($title) . '</h1>';
		$out .= $status !== '' ? '<p class="ab-ui-status">' . wp_kses($status, self::INLINE) . '</p>' : '';
		$out .= $actionsHtml !== '' ? '<div class="ab-ui-tools">' . self::kses($actionsHtml) . '</div>' : '';
		$out .= '</div>';

		if ($aboutHtml !== '') {
			$out .= '<details class="ab-ui-about"><summary>' . esc_html__('About this page', 'agent-brain') . '</summary>'
				. '<div class="ab-ui-about-body">' . self::kses($aboutHtml) . '</div></details>';
		}

		return $out;
	}

	/**
	 * A problem says what broke, what it means and the one thing to do;
	 * good news is one quiet line. $tone is bad, warn, ok or info.
	 */
	public static function callout(string $tone, string $title, string $text = '', string $actionsHtml = ''): string {
		$tone = in_array($tone, ['bad', 'warn', 'ok', 'info'], true) ? $tone : 'info';
		$role = $tone === 'bad' ? 'alert' : 'status';

		$out  = '<div class="ab-ui-callout ab-ui-callout--' . $tone . '" role="' . $role . '"><span class="ab-ui-dot" aria-hidden="true"></span><div>';
		$out .= '<strong>' . esc_html($title) . '</strong>';
		$out .= $text !== '' ? ' ' . wp_kses($text, self::INLINE) : '';
		$out .= $actionsHtml !== '' ? '<div class="ab-ui-actions-row">' . self::kses($actionsHtml) . '</div>' : '';

		return $out . '</div></div>';
	}

	/**
	 * Filter chips as links, so they work without JavaScript.
	 *
	 * @param array<int, array{label: string, url: string, count?: int|null, current?: bool}> $chips
	 */
	public static function chips(array $chips, string $label): string {
		$out = '<ul class="ab-ui-chips" aria-label="' . esc_attr($label) . '">';
		foreach ($chips as $chip) {
			$current = ! empty($chip['current']);
			$out    .= '<li><a class="ab-ui-chip" href="' . esc_url($chip['url']) . '"' . ($current ? ' aria-current="true"' : '') . '>'
				. esc_html($chip['label'])
				. (isset($chip['count']) ? ' <span class="ab-ui-n">' . esc_html(number_format_i18n((int) $chip['count'])) . '</span>' : '')
				. '</a></li>';
		}

		return $out . '</ul>';
	}

	/**
	 * Label and value pairs. Values allow inline markup (a link, a tag).
	 *
	 * @param array<string, string> $rows label => value
	 */
	public static function facts(array $rows): string {
		$out = '<dl class="ab-ui-facts">';
		foreach ($rows as $label => $value) {
			$out .= '<dt>' . esc_html((string) $label) . '</dt><dd>' . wp_kses($value, self::INLINE) . '</dd>';
		}

		return $out . '</dl>';
	}

	/**
	 * A small coloured label. $tone is one of '', ok, new, info, warn, bad, type.
	 */
	public static function tag(string $text, string $tone = ''): string {
		$tone = in_array($tone, ['ok', 'new', 'info', 'warn', 'bad', 'type'], true) ? ' ab-ui-tag--' . $tone : '';

		return '<span class="ab-ui-tag' . $tone . '">' . esc_html($text) . '</span>';
	}

	/**
	 * A titled box. $title may be empty. $level is the title's heading level
	 * (3 inside a section that already has an h2, such as a Settings tab).
	 */
	public static function card(string $title, string $bodyHtml, string $class = '', int $level = 2): string {
		$h = 'h' . max(2, min(4, $level));

		return '<section class="ab-ui-card' . ($class !== '' ? ' ' . esc_attr($class) : '') . '">'
			. ($title !== '' ? '<' . $h . ' class="ab-ui-kicker">' . esc_html($title) . '</' . $h . '>' : '')
			. self::kses($bodyHtml) . '</section>';
	}

	/**
	 * A side list of sections (the Settings tabs), as links so it works
	 * without JavaScript. A section that needs attention gets a dot; its
	 * tone is bad or warn.
	 *
	 * @param array<int, array{label: string, url: string, current?: bool, tone?: string}> $items
	 */
	public static function sideNav(array $items, string $label): string {
		$out = '<nav class="ab-ui-sidenav" aria-label="' . esc_attr($label) . '"><ul>';
		foreach ($items as $item) {
			$tone = $item['tone'] ?? '';
			$dot  = '';
			if ($tone === 'bad' || $tone === 'warn') {
				$dot = '<span class="ab-ui-dot ab-ui-dot--' . $tone . '"></span><span class="screen-reader-text">'
					. esc_html($tone === 'bad' ? __('(needs fixing)', 'agent-brain') : __('(has a warning)', 'agent-brain')) . '</span>';
			}
			$out .= '<li><a href="' . esc_url($item['url']) . '"' . (! empty($item['current']) ? ' aria-current="page"' : '') . '>'
				. '<span>' . esc_html($item['label']) . '</span>' . $dot . '</a></li>';
		}

		return $out . '</ul></nav>';
	}

	/**
	 * One setting: what it does, what it is set to now in plain words
	 * (inline markup allowed), and the control. $for is the control's id,
	 * so the title labels it; leave it empty when the control carries its
	 * own labels. $helpHtml is the longer explanation, folded under "More".
	 * A wide row puts the control under the words (text areas, long fields).
	 */
	public static function setting(string $title, string $now, string $controlHtml, string $for = '', string $helpHtml = '', bool $wide = false): string {
		$out  = '<div class="ab-ui-set' . ($wide ? ' ab-ui-set--wide' : '') . '"><div class="ab-ui-set-text">';
		$out .= $for !== ''
			? '<label class="ab-ui-set-title" for="' . esc_attr($for) . '">' . esc_html($title) . '</label>'
			: '<span class="ab-ui-set-title">' . esc_html($title) . '</span>';
		$out .= $now !== '' ? '<span class="ab-ui-set-now">' . wp_kses($now, self::INLINE) . '</span>' : '';
		$out .= $helpHtml !== '' ? '<details class="ab-ui-set-more"><summary>' . esc_html__('More', 'agent-brain') . '</summary><div>' . self::kses($helpHtml) . '</div></details>' : '';
		$out .= '</div>';
		$out .= $controlHtml !== '' ? '<div class="ab-ui-set-control">' . self::kses($controlHtml) . '</div>' : '';

		return $out . '</div>';
	}

	/**
	 * An on/off switch: a real checkbox (value 1), drawn as a switch. Label
	 * it with a setting title (setting()'s $for) or $ariaLabel.
	 */
	public static function toggle(string $name, string $id, bool $on, bool $disabled = false, string $ariaLabel = ''): string {
		return '<span class="ab-ui-switch"><input type="checkbox" role="switch" name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" value="1"'
			. ($on ? ' checked="checked"' : '') . ($disabled ? ' disabled="disabled"' : '')
			. ($ariaLabel !== '' ? ' aria-label="' . esc_attr($ariaLabel) . '"' : '') . ' /><i></i></span>';
	}

	/**
	 * The one Save bar at the end of a settings form. Without JavaScript it
	 * is always there; assets/settings.js hides it until something changed
	 * and counts the changes. Discard is a reset button, so it works either
	 * way. $name is the submit button's name.
	 */
	public static function saveBar(string $name, string $label = ''): string {
		return '<div class="ab-ui-savebar" data-one="' . esc_attr__('1 change not saved', 'agent-brain') . '"'
			/* translators: %d: number of changed settings */
			. ' data-many="' . esc_attr__('%d changes not saved', 'agent-brain') . '">'
			. '<span class="ab-ui-savebar-msg" aria-live="polite"></span>'
			. '<button type="reset" class="button ab-ui-savebar-discard">' . esc_html__('Discard', 'agent-brain') . '</button>'
			. '<button type="submit" name="' . esc_attr($name) . '" value="1" class="button button-primary">' . esc_html($label !== '' ? $label : __('Save', 'agent-brain')) . '</button>'
			. '</div>';
	}

	/**
	 * The list-and-detail layout of the Review inbox: rows on the left, the
	 * selected one on the right. Rows are links, so it works without
	 * JavaScript; assets/admin-ui.js adds j and k to move and / to search.
	 * Below 960px one side shows at a time: the list, or the detail with a
	 * Back link once the person picked a row ($picked).
	 *
	 * @param string $rowsHtml Rows from row().
	 */
	public static function split(string $label, string $rowsHtml, string $detailHtml, bool $picked, string $belowListHtml = ''): string {
		return '<div class="ab-ui-split' . ($picked ? ' ab-ui-split--picked' : '') . '" data-ab-split>'
			. '<div class="ab-ui-split-list"><ul class="ab-ui-list" aria-label="' . esc_attr($label) . '">' . self::kses($rowsHtml) . '</ul>' . self::kses($belowListHtml) . '</div>'
			. '<div class="ab-ui-split-detail">' . self::kses($detailHtml) . '</div></div>';
	}

	/**
	 * One row of a split() list: a link with a title and a meta line
	 * (inline markup allowed, such as a tag() and a <time>).
	 */
	public static function row(string $url, string $title, string $metaHtml, bool $current): string {
		return '<li class="ab-ui-row"><a class="ab-ui-row-link" href="' . esc_url($url) . '"' . ($current ? ' aria-current="true"' : '') . '>'
			. '<span class="ab-ui-row-title">' . esc_html($title) . '</span>'
			. ($metaHtml !== '' ? '<span class="ab-ui-row-meta">' . wp_kses($metaHtml, self::INLINE) . '</span>' : '')
			. '</a></li>';
	}

	/**
	 * The right side of a split(): a kicker, the item, and its actions at
	 * the bottom. $backUrl is the Back link narrow screens show.
	 */
	public static function detail(string $kicker, string $bodyHtml, string $actionsHtml, string $backUrl): string {
		return '<div class="ab-ui-detail"><div class="ab-ui-detail-body">'
			. '<a class="ab-ui-back" href="' . esc_url($backUrl) . '">&larr; ' . esc_html__('Back to the list', 'agent-brain') . '</a>'
			. ($kicker !== '' ? '<p class="ab-ui-kicker">' . esc_html($kicker) . '</p>' : '')
			. self::kses($bodyHtml) . '</div>'
			. ($actionsHtml !== '' ? '<div class="ab-ui-detail-actions">' . self::kses($actionsHtml) . '</div>' : '')
			. '</div>';
	}

	/**
	 * Markup the caller built: forms, buttons, tables. Allows what the
	 * admin's post content allows plus form controls.
	 */
	private static function kses(string $html): string {
		static $allowed = null;
		if ($allowed === null) {
			$allowed = wp_kses_allowed_html('post');
			$attrs   = ['type' => true, 'name' => true, 'value' => true, 'id' => true, 'class' => true, 'form' => true, 'aria-label' => true, 'aria-describedby' => true, 'disabled' => true, 'role' => true, 'data-*' => true];
			$field   = ['placeholder' => true, 'maxlength' => true, 'required' => true, 'readonly' => true, 'autocomplete' => true, 'spellcheck' => true];
			foreach ([
				'form'     => ['method' => true, 'action' => true, 'class' => true, 'id' => true, 'data-*' => true],
				'input'    => $attrs + $field + ['checked' => true, 'min' => true, 'max' => true, 'step' => true],
				'textarea' => $attrs + $field + ['rows' => true, 'cols' => true],
				'button'   => $attrs,
				'select'   => $attrs + ['required' => true],
				'option'   => ['value' => true, 'selected' => true],
				'label'    => ['for' => true, 'class' => true, 'id' => true],
				'time'     => ['datetime' => true, 'title' => true],
			] as $tag => $tagAttrs) {
				$allowed[$tag] = array_merge($allowed[$tag] ?? [], $tagAttrs);
			}
		}

		return wp_kses($html, $allowed);
	}
}
