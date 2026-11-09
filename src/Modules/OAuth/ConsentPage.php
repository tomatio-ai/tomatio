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
 *  - `tomatio/brand_name` (string, default "TomaTío")
 *  - `tomatio/brand_tagline` (string, default "")
 *  - `tomatio/brand_logo_url` (string, default the Site Icon, else
 *    TomaTío's mark; #590), drawn by the `.ab-mark` rule
 *  - `tomatio/favicon_tags` (string; the Site Icon's tags win)
 *  - `tomatio/consent_head` (action, echo extra <style>/<link> tags,
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
	 *   delegate?: bool,
	 *   new_agent?: array{name:string, selected:bool, classes:array<int, array{value:string, label:string, selected:bool}>, read:array<int, array{id:int, name:string, checked:bool}>, write:array<int, array{id:int, name:string, checked:bool}>}|null,
	 *   notice?: string
	 * } $view
	 */
	public static function render(array $view): string {
		$brand   = self::brandName();
		$tagline = self::brandTagline();
		$client  = $view['client_name'] !== '' ? $view['client_name'] : $view['verified_host'];

		$html  = self::head(sprintf(
			/* translators: %s: client name */
			__('Connect %s', 'tomatio'),
			$client
		));
		$html .= '<main class="ab-consent"><div class="ab-card">';
		$html .= self::brandRow($brand, $tagline);

		$html .= '<h1>' . sprintf(
			/* translators: 1: client name, 2: site name */
			esc_html__('Connect %1$s to %2$s', 'tomatio'),
			'<span class="ab-em">' . esc_html($client) . '</span>',
			'<span class="ab-em">' . esc_html($view['site_name']) . '</span>'
		) . '</h1>';

		$html .= '<p class="ab-verified"><span class="ab-dot" aria-hidden="true"></span>'
			. sprintf(
				/* translators: %s: verified domain */
				esc_html__('Request verified from %s', 'tomatio'),
				'<strong>' . esc_html($view['verified_host']) . '</strong>'
			)
			. '</p>';

		$html .= '<p class="ab-user">' . sprintf(
			/* translators: %s: user display name */
			esc_html__('Signed in as %s', 'tomatio'),
			'<strong>' . esc_html($view['user_name']) . '</strong>'
		) . ' &middot; <a href="' . esc_url($view['switch_url']) . '">' . esc_html__('Not you?', 'tomatio') . '</a></p>';

		$options  = $view['connect_as'] ?? [];
		$newAgent = $view['new_agent'] ?? null;

		if (($view['notice'] ?? '') !== '') {
			$html .= '<p class="ab-error" role="alert">' . esc_html((string) $view['notice']) . '</p>';
		}

		$html .= '<form method="post" action="' . esc_url($view['action_url']) . '">';
		$html .= $options !== [] || $newAgent !== null ? self::connectAsPicker($client, $options, $newAgent) : self::grantsList($client, $view['read_scopes'], $view['write_scopes'], $view['switchable'] ?? []);
		if (! empty($view['delegate'])) {
			$html .= self::delegateChoice($client);
		}
		$html .= $view['nonce_field']; // Built by wp_nonce_field( ..., false ), already escaped.
		foreach ($view['hidden'] as $key => $value) {
			$html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
		}
		$html .= '<div class="ab-actions">'
			. '<button class="ab-btn ab-btn-primary" type="submit" name="ab_oauth_decision" value="approve">' . esc_html__('Allow access', 'tomatio') . '</button>'
			. '<button class="ab-btn ab-btn-ghost" type="submit" name="ab_oauth_decision" value="deny">' . esc_html__('Cancel', 'tomatio') . '</button>'
			. '</div></form>';

		$html .= '<p class="ab-fine">' . esc_html__('Every request is recorded in the audit log. You can revoke access at any time from', 'tomatio')
			. ' <a href="' . esc_url($view['revoke_url']) . '">' . esc_html__('your profile', 'tomatio') . '</a>.</p>';

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
	 * With $newAgent (#807) the picker also offers "Connect as a new agent",
	 * before "Me", with the new agent's name, class and scopes.
	 *
	 * @param array<int, array{id:int, name:string, description:string, read_scopes:string[], write_scopes:string[], kind?:string, detail?:string, owner?:string, recommended?:bool, writes_via?:string}> $options
	 * @param array{name:string, selected:bool, classes:array<int, array{value:string, label:string, selected:bool}>, read:array<int, array{id:int, name:string, checked:bool}>, write:array<int, array{id:int, name:string, checked:bool}>}|null $newAgent
	 */
	private static function connectAsPicker(string $client, array $options, ?array $newAgent = null): string {
		$options = array_values($options);
		$agents  = count(array_filter($options, static fn (array $option): bool => ($option['kind'] ?? 'agent') !== 'self'));
		$checked = 0;
		foreach ($options as $i => $option) {
			if (! empty($option['recommended'])) {
				$checked = $i;
				break;
			}
		}
		if ($newAgent !== null && $newAgent['selected']) {
			$checked = -1;
		}

		if ($newAgent !== null) {
			$hint = $agents === 0
				/* translators: %s: client name */
				? sprintf(__('Choose who %s works as on this brain: a new agent, or you.', 'tomatio'), $client)
				/* translators: %s: client name */
				: sprintf(__('Choose who %s works as on this brain: one of its agents, a new agent, or you.', 'tomatio'), $client);
		} else {
			$hint = $agents === 1
				/* translators: 1: client name, 2: the agent's name */
				? sprintf(__('Choose who %1$s works as on this brain: the agent %2$s, or you.', 'tomatio'), $client, $options[0]['name'])
				/* translators: %s: client name */
				: sprintf(__('Choose who %s works as on this brain: one of its agents, or you.', 'tomatio'), $client);
		}

		$html = '<h2>' . sprintf(
			/* translators: %s: client name */
			esc_html__('Connect %s as', 'tomatio'),
			esc_html($client)
		) . '</h2><p class="ab-hint">' . esc_html($hint) . ' ' . esc_html__('No agent password needed.', 'tomatio') . '</p>';

		$html .= '<div class="ab-options">';
		if ($newAgent !== null && $agents > 0) {
			$html .= '<p class="ab-group">' . esc_html__('Connect as an existing agent', 'tomatio') . '</p>';
		}
		$newShown = false;
		foreach ($options as $i => $option) {
			$self = ($option['kind'] ?? 'agent') === 'self';
			if ($self && $newAgent !== null && ! $newShown) {
				$html    .= self::newAgentOption($client, $newAgent);
				$newShown = true;
			}
			$html .= '<label class="ab-option' . ($self ? ' ab-option-self' : '') . '"><input type="radio" name="' . esc_attr(Delegation::FIELD) . '" value="' . esc_attr((string) $option['id']) . '"' . ($i === $checked ? ' checked' : '') . '>'
				. '<span class="ab-option-body"><span class="ab-option-name">' . esc_html($option['name']);
			if (! empty($option['recommended'])) {
				$html .= ' <span class="ab-badge">' . esc_html__('Recommended', 'tomatio') . '</span>';
			}
			$html .= '</span>';
			if (($option['detail'] ?? '') !== '' && $option['detail'] !== $option['name']) {
				$html .= '<span class="ab-option-id">' . esc_html($option['detail']) . '</span>';
			}
			if (($option['owner'] ?? '') !== '') {
				/* translators: %s: the person the agent works for, or "you" */
				$html .= '<span class="ab-option-desc">' . esc_html(sprintf(__('Works for %s', 'tomatio'), $option['owner'])) . '</span>';
			}
			if ($option['description'] !== '') {
				$html .= '<span class="ab-option-desc">' . esc_html($option['description']) . '</span>';
			}
			$html .= '<span class="ab-option-means">' . esc_html(self::meaning($client, $option)) . '</span>'
				. '<span class="ab-option-scopes">' . self::scopeSummary($option['read_scopes'], $option['write_scopes']) . '</span></span></label>';
		}
		if ($newAgent !== null && ! $newShown) {
			$html .= self::newAgentOption($client, $newAgent);
		}

		return $html . '</div>';
	}

	/**
	 * #807: "Connect as a new agent", with the fields it needs. The fields
	 * are folded away until the option is picked (where :has() is
	 * supported; elsewhere they stay visible). It says plainly what
	 * approving makes. The server re-checks every value (NewAgent).
	 *
	 * @param array{name:string, selected:bool, classes:array<int, array{value:string, label:string, selected:bool}>, read:array<int, array{id:int, name:string, checked:bool}>, write:array<int, array{id:int, name:string, checked:bool}>} $form
	 */
	private static function newAgentOption(string $client, array $form): string {
		$html = '<div class="ab-option-new"><label class="ab-option-head"><input type="radio" name="' . esc_attr(Delegation::FIELD) . '" value="' . esc_attr(NewAgent::CHOICE) . '"' . ($form['selected'] ? ' checked' : '') . '>'
			. '<span class="ab-option-body"><span class="ab-option-name">' . esc_html__('Connect as a new agent', 'tomatio') . '</span>'
			. '<span class="ab-option-means">' . esc_html(sprintf(
				/* translators: %s: client name */
				__('Makes a new agent for %s and connects it. Use this for each bot you connect: every bot gets its own agent, scopes and audit trail.', 'tomatio'),
				$client
			)) . '</span></span></label>';

		$html .= '<div class="ab-new-fields">'
			. '<label class="ab-new-label" for="ab-new-agent-name">' . esc_html__('Agent name', 'tomatio') . '</label>'
			. '<input type="text" id="ab-new-agent-name" name="' . esc_attr(NewAgent::FIELD_NAME) . '" value="' . esc_attr($form['name']) . '" maxlength="60" autocomplete="off" placeholder="' . esc_attr__('For example the bot\'s name', 'tomatio') . '">'
			. '<label class="ab-new-label" for="ab-new-agent-class">' . esc_html__('Class', 'tomatio') . '</label>'
			. '<select id="ab-new-agent-class" name="' . esc_attr(NewAgent::FIELD_CLASS) . '">';
		foreach ($form['classes'] as $class) {
			$html .= '<option value="' . esc_attr($class['value']) . '"' . ($class['selected'] ? ' selected' : '') . '>' . esc_html($class['label']) . '</option>';
		}
		$html .= '</select>';

		$html .= self::scopeBoxes(__('Reads', 'tomatio'), NewAgent::FIELD_READ, $form['read'], __('You read no scopes, so it would see no memory.', 'tomatio'));
		$html .= self::scopeBoxes(__('Saves to', 'tomatio'), NewAgent::FIELD_WRITE, $form['write'], __('You save to no scopes, so it could not save memories.', 'tomatio'));

		$html .= '<p class="ab-new-what">' . esc_html__('Allowing access adds this agent to the brain now, with only the scopes ticked here, which are all yours. Like every agent it drafts and proposes: a person still approves knowledge and publishing. An observer only reads. You can suspend or remove the agent at any time on the Agents page.', 'tomatio') . '</p>';

		return $html . '</div></div>';
	}

	/**
	 * @param array<int, array{id:int, name:string, checked:bool}> $choices
	 */
	private static function scopeBoxes(string $legend, string $field, array $choices, string $none): string {
		$html = '<fieldset><legend>' . esc_html($legend) . '</legend>';
		if ($choices === []) {
			return $html . '<span class="ab-option-desc">' . esc_html($none) . '</span></fieldset>';
		}
		foreach ($choices as $choice) {
			$html .= '<label class="ab-check"><input type="checkbox" name="' . esc_attr($field) . '[]" value="' . esc_attr((string) $choice['id']) . '"' . ($choice['checked'] ? ' checked' : '') . '> ' . esc_html($choice['name']) . '</label>';
		}

		return $html . '</fieldset>';
	}

	/**
	 * #629: the opt-in for delegated authority, unticked. Shown only when the
	 * brain allows it (DelegatedAuthority::allowed()); applies to this one
	 * connection.
	 */
	private static function delegateChoice(string $client): string {
		return '<div class="ab-delegate"><label><input type="checkbox" name="' . esc_attr(DelegatedAuthority::FIELD) . '" value="1" aria-describedby="ab-delegate-help"> '
			. '<span class="ab-delegate-label">' . esc_html__('Let this AI act with my rights when I ask (create, edit and publish without review)', 'tomatio') . '</span></label>'
			. '<p class="ab-delegate-help" id="ab-delegate-help">' . esc_html(sprintf(
				/* translators: %s: client name */
				__('Off unless you tick it, and only for this connection. When you ask it to, %s can create pages, publish document changes and approve page versions in one step, with your rights, instead of leaving drafts for someone to review. It can never do more than you can yourself, artifacts locked as "Review required" still go through review, and every such change is logged as made for you. Without your request it keeps drafting for review. You can turn this off at any time from your profile, without reconnecting.', 'tomatio'),
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
			return sprintf(__('%s works as this agent, within its scopes: it saves memories and drafts as the agent, and its work is logged under the agent.', 'tomatio'), $client);
		}

		$via = (string) ($option['writes_via'] ?? '');

		return $via !== ''
			/* translators: 1: client name, 2: the person's default agent */
			? sprintf(__('%1$s reads with your access; what it saves goes in as your agent %2$s, logged as done through you.', 'tomatio'), $client, $via)
			/* translators: %s: client name */
			: sprintf(__('%s reads with your access but cannot save memories: choose a default agent on your profile first.', 'tomatio'), $client);
	}

	/**
	 * @param string[] $read
	 * @param string[] $write
	 */
	private static function scopeSummary(array $read, array $write): string {
		if ($read === [] && $write === []) {
			return esc_html__('No memory scopes yet', 'tomatio');
		}

		$parts = [];
		if ($read !== []) {
			$parts[] = esc_html__('Reads', 'tomatio') . ' ' . self::scopeChips($read);
		}
		if ($write !== []) {
			$parts[] = esc_html__('writes', 'tomatio') . ' ' . self::scopeChips($write);
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
			esc_html__('%s will be able to', 'tomatio'),
			esc_html($client)
		) . '</h2><ul class="ab-grants">';

		if ($read === [] && $write === []) {
			$html .= '<li>' . esc_html__('Nothing yet: your account has no memory scopes, so it will see no memory until a curator grants access.', 'tomatio') . '</li>';
		} else {
			if ($read !== []) {
				$html .= '<li>' . esc_html__('Read and search memory in', 'tomatio') . ' ' . self::scopeChips($read) . '</li>';
			}
			if ($write !== []) {
				$html .= '<li>' . esc_html__('Save new memory in', 'tomatio') . ' ' . self::scopeChips($write) . '</li>';
			}
			$html .= '<li>' . esc_html__('Read approved knowledge and work on documents assigned to you', 'tomatio') . '</li>';
		}

		if ($switchable !== []) {
			$html .= '<li>' . esc_html__('Act as your agents when the task calls for it:', 'tomatio') . ' ' . self::scopeChips($switchable) . '</li>';
		}

		return $html . '</ul>';
	}

	public static function renderError(string $message): string {
		$html  = self::head(__('Authorization request failed', 'tomatio'));
		$html .= '<main class="ab-consent"><div class="ab-card">';
		$html .= self::brandRow(self::brandName(), self::brandTagline());
		$html .= '<h1>' . esc_html__('This request could not be authorized', 'tomatio') . '</h1>';
		$html .= '<p class="ab-error">' . esc_html($message) . '</p>';
		$html .= '<p class="ab-fine">' . esc_html__('Go back to the app you came from and try connecting again.', 'tomatio') . '</p>';
		$html .= '</div></main></body></html>';

		return $html;
	}

	private static function brandName(): string {
		$name = function_exists('apply_filters') ? \AgentBrain\Core\Hooks::filter('brand_name', 'TomaTío') : 'TomaTío';

		return is_string($name) && $name !== '' ? $name : 'TomaTío';
	}

	private static function brandTagline(): string {
		$tagline = function_exists('apply_filters') ? \AgentBrain\Core\Hooks::filter('brand_tagline', '') : '';

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
			\AgentBrain\Core\Hooks::action('consent_head');
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
.ab-option:has(input[type=radio]:checked){border-color:var(--ab-accent);box-shadow:0 0 0 1px var(--ab-accent)}
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
.ab-group{margin:4px 0 0;font-size:13px;font-weight:600;color:var(--ab-muted)}
.ab-option-new{border:1px solid var(--ab-border);border-radius:12px;padding:12px 14px}
.ab-option-new:has(> .ab-option-head input:checked){border-color:var(--ab-accent);box-shadow:0 0 0 1px var(--ab-accent)}
.ab-option-head{display:flex;gap:12px;align-items:flex-start;cursor:pointer}
.ab-option-head input{margin-top:4px;accent-color:var(--ab-accent)}
.ab-new-fields{display:flex;flex-direction:column;gap:6px;margin:12px 0 0 26px;font-size:14px}
.ab-new-fields input[type=text],.ab-new-fields select{font:inherit;width:100%;padding:8px 10px;border:1px solid var(--ab-border);border-radius:8px;background:var(--ab-card);color:var(--ab-text)}
.ab-new-fields fieldset{border:0;padding:0;margin:6px 0 0;min-width:0}
.ab-new-fields legend,.ab-new-label{font-weight:600;padding:0}
.ab-check{display:inline-flex;gap:6px;align-items:center;margin:4px 14px 0 0;font-size:13px;cursor:pointer}
.ab-check input{accent-color:var(--ab-accent)}
.ab-new-what{margin:6px 0 0;font-size:13px;color:var(--ab-muted)}
@supports selector(:has(*)){.ab-option-new:not(:has(> .ab-option-head input:checked)) .ab-new-fields{display:none}}
CSS;
	}
}
