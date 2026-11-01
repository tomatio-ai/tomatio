<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Sharing\AppIcon;

/**
 * The brain's own mark when it has no Site Icon: a monogram, the first
 * letter of its name in white on its accent colour. A brain belongs to its
 * team, so its pages (home, sign-in, consent, favicons) carry its name, not
 * TomaTío's mark; TomaTío's mark stays for TomaTío's own surfaces (the admin
 * menu, the MCP server icons) and the credit line. A Site Icon always wins.
 *
 * The monogram is an SVG (no GD needed, sharp at every size); with GD a PNG
 * is added for browsers and devices that want one (iOS Home Screen).
 */
final class BrandIcon {

	public const DEFAULT_ACCENT = '#4d7c0f';

	/** The brand name: the site title through `agent_brain/brand_name`. */
	public static function name(): string {
		// The stored title is HTML-escaped (sanitize_option); decode so callers escape once.
		$site = trim(html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8'));

		return (string) apply_filters('agent_brain/brand_name', $site !== '' ? $site : 'TomaTío');
	}

	/** The accent (six-digit hex) through `agent_brain/brand_accent`, lower case. */
	public static function accent(): string {
		$accent = (string) apply_filters('agent_brain/brand_accent', self::DEFAULT_ACCENT);

		return strtolower(preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1 ? $accent : self::DEFAULT_ACCENT);
	}

	public static function svg(string $name, string $accent): string {
		$letter = htmlspecialchars(AppIcon::initial($name), ENT_XML1 | ENT_QUOTES, 'UTF-8');

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
			. '<title>' . htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</title>'
			. '<rect width="64" height="64" rx="14" fill="' . $accent . '"/>'
			. '<text x="32" y="44" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif" font-size="34" font-weight="700" fill="#fff">' . $letter . '</text>'
			. '</svg>';
	}

	/** The monogram as a data: URI (SVG). */
	public static function uri(): string {
		return 'data:image/svg+xml;base64,' . base64_encode(self::svg(self::name(), self::accent()));
	}

	/** The monogram as PNG bytes of $size, or null without GD. */
	public static function png(int $size): ?string {
		return AppIcon::monogram(self::name(), self::accent(), $size);
	}

	/**
	 * The monogram's favicon set: SVG, a 32 px PNG and the 180 px
	 * apple-touch-icon (the PNGs only with GD), as data: URIs.
	 */
	public static function faviconTags(): string {
		$tags = '<link rel="icon" type="image/svg+xml" href="' . esc_url(self::uri(), ['data']) . '" />';
		$png  = self::png(32);
		if ($png !== null) {
			$tags .= '<link rel="icon" type="image/png" sizes="32x32" href="' . esc_url('data:image/png;base64,' . base64_encode($png), ['data']) . '" />';
		}
		$touch = self::png(180);
		if ($touch !== null) {
			$tags .= '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url('data:image/png;base64,' . base64_encode($touch), ['data']) . '" />';
		}

		return $tags;
	}
}
