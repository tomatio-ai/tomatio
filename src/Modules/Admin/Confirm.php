<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

/**
 * The one confirmation pattern for destructive admin actions (issue #81).
 *
 * Every revoke / delete / disconnect form in wp-admin carries a
 * `data-ab-confirm` attribute built here; a single footer script turns it
 * into a browser confirm() before the form submits. (Never name a form
 * field `confirm`: it shadows window.confirm inside inline handlers, which
 * is what silently disabled the old Forget prompt.)
 *
 * Wording style, always in this order:
 *   1. What happens, in the present tense and plain words.
 *   2. Whether it can be undone ("This cannot be undone." or
 *      "Create a new key to restore it.").
 *
 * Irreversible, high-blast-radius actions (Forget) use a typed
 * confirmation on the page instead; scope deletion uses a dedicated
 * confirmation page that lists what it affects. Both still count as a
 * confirm step for the guard test (ConfirmPatternTest).
 */
final class Confirm {

	public const ATTR = 'data-ab-confirm';

	private static bool $used = false;

	/**
	 * Compose the standard wording.
	 *
	 * @param string $happens What the action does ("Revoke this agent: its credentials are removed").
	 * @param string $undo    How to undo it, or '' when it cannot be undone.
	 */
	public static function message(string $happens, string $undo = ''): string {
		$happens = rtrim($happens, ". \t\n") . '.';

		return $undo === ''
			? $happens . ' ' . __('This cannot be undone.', 'agent-brain')
			: $happens . ' ' . rtrim($undo, ". \t\n") . '.';
	}

	/**
	 * The attribute string (leading space, escaped) to put on a `<form>` tag.
	 */
	public static function attr(string $message): string {
		self::$used = true;

		if (function_exists('add_action') && ! has_action('admin_print_footer_scripts', [self::class, 'printScript'])) {
			add_action('admin_print_footer_scripts', [self::class, 'printScript']);
		}

		return ' ' . self::ATTR . '="' . esc_attr($message) . '"';
	}

	public static function printScript(): void {
		if (! self::$used) {
			return;
		}

		$attr = esc_js(self::ATTR);
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $attr is the esc_js()'d constant attribute name.
		echo '<script>document.querySelectorAll("form[' . $attr . ']").forEach(function(f){'
			. 'f.addEventListener("submit",function(e){if(!window.confirm(f.getAttribute("' . $attr . '"))){e.preventDefault();}});'
			. '});'
			// A submit button that carries its own message (several buttons share one form).
			. 'document.querySelectorAll("button[' . $attr . '],input[type=submit][' . $attr . ']").forEach(function(b){'
			. 'b.addEventListener("click",function(e){if(!window.confirm(b.getAttribute("' . $attr . '"))){e.preventDefault();}});'
			. '});</script>';
		// phpcs:enable
	}
}
