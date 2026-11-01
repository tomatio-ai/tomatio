<?php

declare(strict_types=1);

namespace AgentBrain\Modules\OAuth;

use AgentBrain\Modules\Admin\BrandChrome;
use AgentBrain\Modules\Admin\HomePage;

/**
 * The human-facing OAuth consent page (ID-5): a self-contained HTML document
 * (no wp-admin CSS), so it renders the same on any theme or host.
 *
 * Pure rendering: AuthorizeController builds the view and echoes the result,
 * which keeps this directly testable. Every value is escaped here.
 *
 * The verified domain (the host of client_id, which Cimd fetched the client
 * metadata from) leads; the client's self-declared name is shown after it,
 * because any client can call itself anything.
 *
 * Branding hooks:
 *  - `agent_brain/brand_name` (string, default "TomaTío")
 *  - `agent_brain/brand_tagline` (string, default "")
 *  - `agent_brain/brand_logo_url` (string, default the Site Icon, else
 *    TomaTío's mark; #590), drawn by the `.ab-mark` rule
 *  - `agent_brain/favicon_tags` (string; the Site Icon's tags win)
 *  - `agent_brain/consent_head` (action, echo extra <style>/<link> tags,
 *    e.g. to override the --ab-* CSS variables or `.ab-mark`; printed after
 *    the page's own, so they win)
 */
final class ConsentPage {

	/**
	 * @param array{
	 *   site_name: string,
	 *   client_name: string,
	 *   verified_host: string,
	 *   user_name: string,
	 *   switch_url: string,
	 *   revoke_url: string,
	 *   read_scopes: string[],
	 *   write_scopes: string[],
	 *   action_url: string,
	 *   nonce_field: string,
	 *   hidden: array<string, string>,
	 *   connect_as?: array<int, array{id:int, name:string, description:string, read_scopes:string[], write_scopes:string[]}>,
	 *   switchable?: string[],
	 *   delegate?: bool
	 * } $view
	 */
	public static function render(array $view): string {
		$brand   = self::brandName();
		$tagline = self::brandTagline();
		$client  = $view['client_name'] !== '' ? $view['client_name'] : $view['verified_host'];

		$html  = self::head(sprintf(
			/* translators: %s: client name */
			__('Connect %s', 'agent-brain'),
			$client
		));
		$html .= '<main class="ab-consent"><div class="ab-card">';
		$html .= self::brandRow($brand, $tagline);

		$html .= '<h1>' . sprintf(
			/* translators: 1: client name, 2: site name */
			esc_html__('Connect %1$s to %2$s', 'agent-brain'),
			'<span class="ab-em">' . esc_html($client) . '</span>',
			'<span class="ab-em">' . esc_html($view['site_name']) . '</span>'
		) . '</h1>';

		$html .= '<p class="ab-verified"><span class="ab-dot" aria-hidden="true"></span>'
			. sprintf(
				/* translators: %s: verified domain */
				esc_html__('Request verified from %s', 'agent-brain'),
				'<strong>' . esc_html($view['verified_host']) . '</strong>'
			)
			. '</p>';

		$html .= '<p class="ab-user">' . sprintf(
			/* translators: %s: user display name */
			esc_html__('Signed in as %s', 'agent-brain'),
			'<strong>' . esc_html($view['user_name']) . '</strong>'
		) . ' &middot; <a href="' . esc_url($view['switch_url']) . '">' . esc_html__('Not you?', 'agent-brain') . '</a></p>';

		$options = $view['connect_as'] ?? [];

		$html .= '<form method="post" action="' . esc_url($view['action_url']) . '">';
		$html .= $options !== [] ? self::connectAsPicker($client, $options) : self::grantsList($client, $view['read_scopes'], $view['write_scopes'], $view['switchable'] ?? []);
		if (! empty($view['delegate'])) {
			$html .= self::delegateChoice($client);
		}
		$html .= $view['nonce_field']; // Built by wp_nonce_field( ..., false ), already escaped.
		foreach ($view['hidden'] as $key => $value) {
			$html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
		}
		$html .= '<div class="ab-actions">'
			. '<button class="ab-btn ab-btn-primary" type="submit" name="ab_oauth_decision" value="approve">' . esc_html__('Allow access', 'agent-brain') . '</button>'
			. '<button class="ab-btn ab-btn-ghost" type="submit" name="ab_oauth_decision" value="deny">' . esc_html__('Cancel', 'agent-brain') . '</button>'
			. '</div></form>';

		$html .= '<p class="ab-fine">' . esc_html__('Every request is recorded in the audit log. You can revoke access at any time from', 'agent-brain')
			. ' <a href="' . esc_url($view['revoke_url']) . '">' . esc_html__('your profile', 'agent-brain') . '</a>.</p>';

		$html .= '</div></main></body></html>';

		return $html;
	}

	/**
	 * "Connect this AI as": the agents the signed-in manager may delegate to,
	 * then themselves (ConnectChoices). Each option says who it is, whose it
	 * is and what picking it means for the client; the recommended one comes
	 * first and is preselected (else the first). The server re-checks the
	 * choice (Delegation::canActAs); this is only the picker.
	 *
	 * @param array<int, array{id:int, name:string, description:string, read_scopes:string[], write_scopes:string[], kind?:string, detail?:string, owner?:string, recommended?:bool, writes_via?:string}> $options
	 */
	private static function connectAsPicker(string $client, array $options): string {
		$options = array_values($options);
		$agents  = count(array_filter($options, static fn (array $option): bool => ($option['kind'] ?? 'agent') !== 'self'));
		$checked = 0;
		foreach ($options as $i => $option) {
			if (! empty($option['recommended'])) {
				$checked = $i;
				break;
			}
		}

		$hint = $agents === 1
			/* translators: 1: client name, 2: the agent's name */
			? sprintf(__('Choose who %1$s works as on this brain: the agent %2$s, or you.', 'agent-brain'), $client, $options[0]['name'])
			/* translators: %s: client name */
			: sprintf(__('Choose who %s works as on this brain: one of its agents, or you.', 'agent-brain'), $client);

		$html = '<h2>' . sprintf(
			/* translators: %s: client name */
			esc_html__('Connect %s as', 'agent-brain'),
			esc_html($client)
		) . '</h2><p class="ab-hint">' . esc_html($hint) . ' ' . esc_html__('No agent password needed.', 'agent-brain') . '</p>';

		$html .= '<div class="ab-options">';
		foreach ($options as $i => $option) {
			$self = ($option['kind'] ?? 'agent') === 'self';
			$html .= '<label class="ab-option' . ($self ? ' ab-option-self' : '') . '"><input type="radio" name="' . esc_attr(Delegation::FIELD) . '" value="' . esc_attr((string) $option['id']) . '"' . ($i === $checked ? ' checked' : '') . '>'
				. '<span class="ab-option-body"><span class="ab-option-name">' . esc_html($option['name']);
			if (! empty($option['recommended'])) {
				$html .= ' <span class="ab-badge">' . esc_html__('Recommended', 'agent-brain') . '</span>';
			}
			$html .= '</span>';
			if (($option['detail'] ?? '') !== '' && $option['detail'] !== $option['name']) {
				$html .= '<span class="ab-option-id">' . esc_html($option['detail']) . '</span>';
			}
			if (($option['owner'] ?? '') !== '') {
				/* translators: %s: the person the agent works for, or "you" */
				$html .= '<span class="ab-option-desc">' . esc_html(sprintf(__('Works for %s', 'agent-brain'), $option['owner'])) . '</span>';
			}
			if ($option['description'] !== '') {
				$html .= '<span class="ab-option-desc">' . esc_html($option['description']) . '</span>';
			}
			$html .= '<span class="ab-option-means">' . esc_html(self::meaning($client, $option)) . '</span>'
				. '<span class="ab-option-scopes">' . self::scopeSummary($option['read_scopes'], $option['write_scopes']) . '</span></span></label>';
		}

		return $html . '</div>';
	}

	/**
	 * #629: the opt-in for delegated authority, unticked. Shown only when the
	 * brain allows it (DelegatedAuthority::allowed()); applies to this one
	 * connection.
	 */
	private static function delegateChoice(string $client): string {
		return '<div class="ab-delegate"><label><input type="checkbox" name="' . esc_attr(DelegatedAuthority::FIELD) . '" value="1" aria-describedby="ab-delegate-help"> '
			. '<span class="ab-delegate-label">' . esc_html__('Let this AI act with my rights when I ask (create, edit and publish without review)', 'agent-brain') . '</span></label>'
			. '<p class="ab-delegate-help" id="ab-delegate-help">' . esc_html(sprintf(
				/* translators: %s: client name */
				__('Off unless you tick it, and only for this connection. When you ask it to, %s can create pages, publish document changes and approve page versions in one step, with your rights, instead of leaving drafts for someone to review. It can never do more than you can yourself, artifacts locked as "Review required" still go through review, and every such change is logged as made for you. Without your request it keeps drafting for review. You can turn this off at any time from your profile, without reconnecting.', 'agent-brain'),
				$client
			)) . '</p></div>';
	}

	/**
	 * What picking an option means for the client, in one sentence.
	 *
	 * @param array{kind?:string, writes_via?:string} $option
	 */
	private static function meaning(string $client, array $option): string {
		if (($option['kind'] ?? 'agent') !== 'self') {
			/* translators: %s: client name */
			return sprintf(__('%s works as this agent, within its scopes: it saves memories and drafts as the agent, and its work is logged under the agent.', 'agent-brain'), $client);
		}

		$via = (string) ($option['writes_via'] ?? '');

		return $via !== ''
			/* translators: 1: client name, 2: the person's default agent */
			? sprintf(__('%1$s reads with your access; what it saves goes in as your agent %2$s, logged as done through you.', 'agent-brain'), $client, $via)
			/* translators: %s: client name */
			: sprintf(__('%s reads with your access but cannot save memories: choose a default agent on your profile first.', 'agent-brain'), $client);
	}

	/**
	 * @param string[] $read
	 * @param string[] $write
	 */
	private static function scopeSummary(array $read, array $write): string {
		if ($read === [] && $write === []) {
			return esc_html__('No memory scopes yet', 'agent-brain');
		}

		$parts = [];
		if ($read !== []) {
			$parts[] = esc_html__('Reads', 'agent-brain') . ' ' . self::scopeChips($read);
		}
		if ($write !== []) {
			$parts[] = esc_html__('writes', 'agent-brain') . ' ' . self::scopeChips($write);
		}

		return implode(' &middot; ', $parts);
	}

	/**
	 * What the client will be able to do when there is only one account to
	 * connect as (the signed-in user).
	 *
	 * @param string[] $read
	 * @param string[] $write
	 * @param string[] $switchable agents the client may act as (role switching)
	 */
	private static function grantsList(string $client, array $read, array $write, array $switchable = []): string {
		$html = '<h2>' . sprintf(
			/* translators: %s: client name */
			esc_html__('%s will be able to', 'agent-brain'),
			esc_html($client)
		) . '</h2><ul class="ab-grants">';

		if ($read === [] && $write === []) {
			$html .= '<li>' . esc_html__('Nothing yet: your account has no memory scopes, so it will see no memory until a curator grants access.', 'agent-brain') . '</li>';
		} else {
			if ($read !== []) {
				$html .= '<li>' . esc_html__('Read and search memory in', 'agent-brain') . ' ' . self::scopeChips($read) . '</li>';
			}
			if ($write !== []) {
				$html .= '<li>' . esc_html__('Save new memory in', 'agent-brain') . ' ' . self::scopeChips($write) . '</li>';
			}
			$html .= '<li>' . esc_html__('Read approved knowledge and work on documents assigned to you', 'agent-brain') . '</li>';
		}

		if ($switchable !== []) {
			$html .= '<li>' . esc_html__('Act as your agents when the task calls for it:', 'agent-brain') . ' ' . self::scopeChips($switchable) . '</li>';
		}

		return $html . '</ul>';
	}

	public static function renderError(string $message): string {
		$html  = self::head(__('Authorization request failed', 'agent-brain'));
		$html .= '<main class="ab-consent"><div class="ab-card">';
		$html .= self::brandRow(self::brandName(), self::brandTagline());
		$html .= '<h1>' . esc_html__('This request could not be authorized', 'agent-brain') . '</h1>';
		$html .= '<p class="ab-error">' . esc_html($message) . '</p>';
		$html .= '<p class="ab-fine">' . esc_html__('Go back to the app you came from and try connecting again.', 'agent-brain') . '</p>';
		$html .= '</div></main></body></html>';

		return $html;
	}

	private static function brandName(): string {
		$name = function_exists('apply_filters') ? apply_filters('agent_brain/brand_name', 'TomaTío') : 'TomaTío';

		return is_string($name) && $name !== '' ? $name : 'TomaTío';
	}

	private static function brandTagline(): string {
		$tagline = function_exists('apply_filters') ? apply_filters('agent_brain/brand_tagline', '') : '';

		return is_string($tagline) ? $tagline : '';
	}

	private static function brandRow(string $brand, string $tagline): string {
		$html = '<div class="ab-brand"><span class="ab-mark" aria-hidden="true"></span><span class="ab-brand-name">' . esc_html($brand) . '</span>';
		if ($tagline !== '') {
			$html .= '<span class="ab-brand-tagline">' . esc_html($tagline) . '</span>';
		}

		return $html . '</div>';
	}

	/**
	 * @param string[] $scopes
	 */
	private static function scopeChips(array $scopes): string {
		$chips = array_map(static fn (string $scope): string => '<span class="ab-chip">' . esc_html($scope) . '</span>', $scopes);

		return implode(' ', $chips);
	}

	private static function head(string $title): string {
		$extra = '';
		if (function_exists('do_action')) {
			ob_start();
			do_action('agent_brain/consent_head');
			$extra = (string) ob_get_clean();
		}

		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex">'
			. '<title>' . esc_html($title) . '</title>'
			. self::iconTags()
			. '<style>' . self::css() . self::markCss() . '</style>'
			. $extra
			. '</head><body>';
	}

	/** #590: the Site Icon's favicon tags, else the brain's monogram. */
	private static function iconTags(): string {
		if (! function_exists('has_site_icon')) {
			return '';
		}
		if (has_site_icon()) {
			ob_start();
			wp_site_icon();

			return (string) ob_get_clean();
		}

		return BrandChrome::faviconTags();
	}

	/**
	 * #590: the brain's logo in the brand row (the Site Icon, else its
	 * monogram). Drawn by CSS, so a host's `.ab-mark` rule in consent_head, which
	 * comes later, still replaces it.
	 */
	private static function markCss(): string {
		if (! function_exists('has_site_icon')) {
			return '';
		}

		$logo = esc_url_raw(HomePage::logo(), ['https', 'http', 'data']);
		if ($logo === '') {
			return '';
		}

		return '.ab-mark{background:url("' . str_replace(['"', '\\'], ['%22', '%5C'], $logo) . '") center/contain no-repeat}';
	}

	private static function css(): string {
		return <<<'CSS'
:root{--ab-accent:#2271b1;--ab-accent-contrast:#fff;--ab-bg:#f0f2f5;--ab-card:#fff;--ab-text:#1d2327;--ab-muted:#5c6370;--ab-border:#e2e4e7;--ab-chip:#f0f2f5;--ab-ok:#1a7f37;--ab-radius:16px;--ab-font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",sans-serif;--ab-display:var(--ab-font)}
@media (prefers-color-scheme:dark){:root{--ab-bg:#0d0f12;--ab-card:#16191d;--ab-text:#e8eaed;--ab-muted:#9aa0a6;--ab-border:#2a2f36;--ab-chip:#22272e;--ab-ok:#3fb950}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--ab-bg);color:var(--ab-text);font:16px/1.5 var(--ab-font);padding:24px 16px}
.ab-consent{width:100%;max-width:480px}
.ab-card{background:var(--ab-card);border:1px solid var(--ab-border);border-radius:var(--ab-radius);padding:32px;box-shadow:0 20px 50px -20px rgba(0,0,0,.25)}
.ab-brand{display:flex;align-items:center;gap:10px;margin-bottom:28px;flex-wrap:wrap}
.ab-mark{width:28px;height:28px;border-radius:8px;background:var(--ab-accent);display:inline-block}
.ab-brand-name{font-family:var(--ab-display);font-weight:700;font-size:18px}
.ab-brand-tagline{color:var(--ab-muted);font-size:14px}
h1{font-family:var(--ab-display);font-size:24px;line-height:1.25;margin:0 0 16px}
.ab-em{color:var(--ab-accent)}
h2{font-size:15px;margin:24px 0 8px;color:var(--ab-muted);font-weight:600}
.ab-verified,.ab-user{margin:4px 0;font-size:14px;color:var(--ab-muted)}
.ab-verified strong,.ab-user strong{color:var(--ab-text)}
.ab-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--ab-ok);margin-right:8px;vertical-align:middle}
.ab-user a,.ab-fine a{color:var(--ab-accent)}
.ab-grants{list-style:none;padding:0;margin:0}
.ab-grants li{padding:10px 0 10px 28px;border-top:1px solid var(--ab-border);position:relative;font-size:15px}
.ab-grants li:first-child{border-top:0}
.ab-grants li::before{content:"";position:absolute;left:4px;top:17px;width:10px;height:6px;border-left:2px solid var(--ab-ok);border-bottom:2px solid var(--ab-ok);transform:rotate(-45deg)}
.ab-chip{display:inline-block;background:var(--ab-chip);border-radius:999px;padding:1px 10px;font-size:13px;margin:2px 2px 0 0}
.ab-actions{display:flex;flex-direction:column;gap:10px;margin-top:28px}
.ab-btn{font:inherit;font-weight:600;border-radius:10px;padding:12px 16px;cursor:pointer;border:1px solid transparent}
.ab-btn-primary{background:var(--ab-accent);color:var(--ab-accent-contrast)}
.ab-btn-primary:hover{filter:brightness(1.08)}
.ab-btn-ghost{background:transparent;color:var(--ab-text);border-color:var(--ab-border)}
.ab-btn:focus-visible{outline:3px solid var(--ab-accent);outline-offset:2px}
.ab-fine{margin:20px 0 0;font-size:13px;color:var(--ab-muted)}
.ab-error{padding:12px 14px;border-radius:10px;background:var(--ab-chip);font-size:14px}
.ab-hint{margin:0 0 12px;font-size:14px;color:var(--ab-muted)}
.ab-options{display:flex;flex-direction:column;gap:8px}
.ab-option{display:flex;gap:12px;align-items:flex-start;border:1px solid var(--ab-border);border-radius:12px;padding:12px 14px;cursor:pointer}
.ab-option:has(input:checked){border-color:var(--ab-accent);box-shadow:0 0 0 1px var(--ab-accent)}
.ab-option input{margin-top:4px;accent-color:var(--ab-accent)}
.ab-option-body{display:flex;flex-direction:column;gap:2px}
.ab-option-name{font-weight:600}
.ab-option-desc,.ab-option-scopes{font-size:13px;color:var(--ab-muted)}
.ab-option-id{font-size:12px;color:var(--ab-muted);word-break:break-all}
.ab-option-means{font-size:13px}
.ab-badge{display:inline-block;margin-left:6px;padding:0 8px;border-radius:999px;font-size:12px;font-weight:600;background:var(--ab-accent);color:var(--ab-accent-contrast);vertical-align:2px}
.ab-option-self{border-style:dashed}
.ab-delegate{margin-top:20px;border:1px solid var(--ab-border);border-radius:12px;padding:12px 14px}
.ab-delegate label{display:flex;gap:10px;align-items:flex-start;font-weight:600;cursor:pointer}
.ab-delegate input{margin-top:4px;accent-color:var(--ab-accent)}
.ab-delegate-help{margin:6px 0 0 26px;font-size:13px;color:var(--ab-muted)}
CSS;
	}
}
