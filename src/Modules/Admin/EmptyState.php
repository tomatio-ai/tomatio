<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * One empty-state pattern for every TomaTío list or page that can be
 * empty (issue #80): say what the thing is, why it is empty right now, and
 * offer exactly one next step. Never a bare "No X found.".
 */
final class EmptyState {

	/**
	 * @param array{label: string, url: string}|null $action
	 */
	public static function html(string $what, string $why, ?array $action = null): string {
		$out  = '<div class="ab-empty-state">';
		$out .= '<p class="ab-empty-what"><strong>' . esc_html($what) . '</strong></p>';
		$out .= '<p class="ab-empty-why">' . esc_html($why) . '</p>';

		if ($action !== null && $action['label'] !== '' && $action['url'] !== '') {
			$out .= '<p class="ab-empty-action"><a class="button button-primary" href="' . esc_url($action['url']) . '">'
				. esc_html($action['label']) . '</a></p>';
		}

		return $out . '</div>';
	}

	/**
	 * @param array{label: string, url: string}|null $action
	 */
	public static function render(string $what, string $why, ?array $action = null): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value it emits.
		echo self::html($what, $why, $action);
	}
}
