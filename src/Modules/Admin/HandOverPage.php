<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\HandOver;

/**
 * #850 (B18): the "Hand over" screen for a person or an agent who leaves.
 * It lists what they own (Identity\HandOver::inventory), lets a curator or
 * administrator pick the new owner and what happens to the leaver's own
 * agents, and runs the hand over in one confirmed step. Reached from the
 * agent's detail on the Agents page and from a person's profile; it has no
 * menu row of its own and highlights Agents.
 */
final class HandOverPage {

	public const SLUG = 'agent-brain-hand-over';

	public const ACTION = 'ab_hand_over';

	/** Transient prefix for the last result, per curator, shown once. */
	private const RESULT = 'ab_hand_over_result_';

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addPage']);
		add_action('admin_menu', [self::class, 'hideRow'], 1000);
		add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
		add_action('edit_user_profile', [self::class, 'renderProfileLink']);
	}

	public static function addPage(): void {
		$label = __('Hand over', 'tomatio');
		$hook  = add_submenu_page(Menu::PARENT, $label, $label, Capabilities::CAP_MANAGE, self::SLUG, [self::class, 'render']);
		if (is_string($hook) && $hook !== '') {
			// Without a visible menu row WordPress cannot find the page's title.
			add_action('load-' . $hook, static function () use ($label): void {
				$GLOBALS['title'] = $label;
			});
		}
	}

	public static function hideRow(): void {
		global $submenu;

		if (! isset($submenu[Menu::PARENT]) || ! is_array($submenu[Menu::PARENT])) {
			return;
		}

		foreach ($submenu[Menu::PARENT] as $i => $item) {
			if ((string) ($item[2] ?? '') === self::SLUG) {
				$submenu[Menu::PARENT][$i][4] = trim((string) ($item[4] ?? '') . ' ' . MenuGroups::HIDDEN_CLASS); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- our own row.
			}
		}
	}

	public static function url(int $userId): string {
		return add_query_arg(['page' => self::SLUG, 'user' => (string) $userId], admin_url('admin.php'));
	}

	public static function nonceAction(int $userId): string {
		return self::ACTION . '_' . $userId;
	}

	/**
	 * A "Hand over" link for a person's profile (edit_user_profile), shown
	 * to curators and administrators only.
	 */
	public static function renderProfileLink(\WP_User $user): void {
		if (! HandOver::canHandOver(get_current_user_id()) || (int) $user->ID === get_current_user_id()) {
			return;
		}

		echo '<h2>' . esc_html__('When this person leaves', 'tomatio') . '</h2>'
			. '<p>' . esc_html__('Move what they own in the brain to someone else and revoke their access, in one step.', 'tomatio')
			. ' <a class="button" href="' . esc_url(self::url((int) $user->ID)) . '">' . esc_html__('Hand over', 'tomatio') . '</a></p>';
	}

	public static function render(): void {
		if (! current_user_can(Capabilities::CAP_MANAGE)) {
			wp_die(esc_html__('You do not have permission to hand someone over.', 'tomatio'), 403);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selection, no state change.
		$userId = isset($_GET['user']) ? absint(wp_unslash((string) $_GET['user'])) : 0;

		echo self::html(get_current_user_id(), $userId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes what it emits.
	}

	/**
	 * The whole screen for $actorId looking at $userId. Public for its test.
	 */
	public static function html(int $actorId, int $userId): string {
		$about = '<p>' . esc_html__('When someone leaves the team, what they own goes quiet: documents they lead, sections they own, handoffs waiting for them, knowledge they wrote or proposed. Hand over moves all of it to a new owner and revokes their access in the same step: scope grants, approval, application passwords, AI connections, unused registration keys and open sign-ins. An agent who leaves is also suspended.', 'tomatio') . '</p>'
			. '<p>' . esc_html__('Agents they issued as their own AIs are suspended, and what those agents own moves too. If the new owner is a person, you can move the agents to them instead. Their own TíoDay stays with them. The new owner gets a handoff listing what they inherited, and every step is in Activity.', 'tomatio') . '</p>'
			. '<p>' . esc_html__('Their WordPress account stays. To stop them signing in with their password, remove the account under Users.', 'tomatio') . '</p>';

		$out = '<div class="wrap ab-admin ab-admin-hand-over">' . Ui::head(__('Hand over', 'tomatio'), '', '', $about);

		$result = get_transient(self::RESULT . $actorId);
		if (is_array($result) && (int) ($result['from']['id'] ?? 0) === $userId) {
			delete_transient(self::RESULT . $actorId);
			$out .= self::resultHtml($result);
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only error code from our own redirect.
		$error = isset($_GET['ab_error']) ? sanitize_text_field(wp_unslash((string) $_GET['ab_error'])) : '';
		if ($error !== '') {
			$out .= Ui::callout('bad', __('Nothing was handed over.', 'tomatio'), esc_html($error));
		}

		if (! HandOver::canHandOver($actorId)) {
			return $out . Ui::callout('warn', __('Only curators and administrators can hand someone over.', 'tomatio')) . '</div>';
		}

		$inventory = HandOver::inventory($userId);
		if ($inventory instanceof \WP_Error) {
			return $out . Ui::callout('info', __('Choose who is leaving.', 'tomatio'), esc_html__('Open a person\'s profile or an agent on the Agents page and click Hand over.', 'tomatio')) . '</div>';
		}
		if ($userId === $actorId) {
			return $out . Ui::callout('warn', __('You cannot hand yourself over.', 'tomatio'), esc_html__('Ask another curator.', 'tomatio')) . '</div>';
		}

		$principal = $inventory['principal'];
		$out      .= '<h2>' . esc_html((string) $principal['name']) . ' ' . Ui::tag($principal['kind'] === 'agent' ? __('Agent', 'tomatio') : __('Person', 'tomatio'), 'type') . '</h2>';
		$out      .= Ui::card(__('What they own', 'tomatio'), self::itemsHtml($inventory['items'], $userId));

		if ($inventory['own_agents'] !== []) {
			$list = '<ul>';
			foreach ($inventory['own_agents'] as $agent) {
				$list .= '<li>' . esc_html((string) $agent['name']) . ' <span class="ab-muted">(' . esc_html((string) $agent['status']) . ')</span></li>';
			}
			$out .= Ui::card(__('Their own agents', 'tomatio'), $list . '</ul>');
		}
		if ($inventory['kept'] !== []) {
			$list = '<ul>';
			foreach ($inventory['kept'] as $page) {
				$list .= '<li>' . esc_html((string) $page['title']) . '</li>';
			}
			$out .= Ui::card(__('Stays with them', 'tomatio'), '<p>' . esc_html__('Their own pages, such as their TíoDay, are private to them and are not moved.', 'tomatio') . '</p>' . $list . '</ul>');
		}

		$access = $inventory['access'];
		$out   .= Ui::card(__('Access that will be revoked', 'tomatio'), Ui::facts([
			__('Reads', 'tomatio')             => esc_html(self::scopeNames($access['read_scopes'])),
			__('Writes', 'tomatio')            => esc_html(self::scopeNames($access['write_scopes'])),
			__('Approval', 'tomatio')          => esc_html($access['approval'] ? __('Yes', 'tomatio') : __('No', 'tomatio')),
			__('App passwords', 'tomatio')     => esc_html(number_format_i18n((int) $access['app_passwords'])),
			__('AI connections', 'tomatio')    => esc_html(number_format_i18n((int) $access['connections'])),
			__('Registration keys', 'tomatio') => esc_html(number_format_i18n((int) $access['registration_keys'])),
		]));

		return $out . self::formHtml($userId, $inventory) . '</div>';
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 */
	private static function itemsHtml(array $items, int $leaverId): string {
		if ($items === []) {
			return '<p>' . esc_html__('Nothing. Hand over still revokes their access.', 'tomatio') . '</p>';
		}

		$labels = HandOver::kindLabels();
		$html   = '<table class="widefat striped"><thead><tr><th>' . esc_html__('What', 'tomatio') . '</th><th>' . esc_html__('Item', 'tomatio') . '</th><th>' . esc_html__('Held by', 'tomatio') . '</th></tr></thead><tbody>';
		foreach ($items as $item) {
			$holder = (int) $item['holder'];
			$by     = $holder === $leaverId ? __('them', 'tomatio') : self::name($holder);
			$html  .= '<tr><td>' . esc_html($labels[(string) $item['kind']] ?? (string) $item['kind']) . '</td>'
				. '<td>' . esc_html((string) $item['title']) . ' <span class="ab-muted">#' . esc_html((string) $item['id']) . '</span></td>'
				. '<td>' . esc_html($by) . '</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * @param array<string, mixed> $inventory
	 */
	private static function formHtml(int $userId, array $inventory): string {
		$candidates = HandOver::candidates($userId);
		if ($candidates === []) {
			return Ui::callout('warn', __('Nobody can take over yet.', 'tomatio'), esc_html__('The new owner must be another active agent, a curator or an administrator.', 'tomatio'));
		}

		$options = '<option value="">' . esc_html__('Choose…', 'tomatio') . '</option>';
		foreach ($candidates as $candidate) {
			$kind     = $candidate['kind'] === 'agent' ? __('agent', 'tomatio') : __('person', 'tomatio');
			$options .= '<option value="' . esc_attr((string) $candidate['id']) . '">' . esc_html($candidate['name'] . ' (' . $kind . ')') . '</option>';
		}

		$agents = '';
		if ($inventory['own_agents'] !== []) {
			$agents = '<p><label><input type="radio" name="agents" value="' . esc_attr(HandOver::AGENTS_SUSPEND) . '" checked /> '
				. esc_html__('Suspend their agents, and move what those agents own too', 'tomatio') . '</label><br />'
				. '<label><input type="radio" name="agents" value="' . esc_attr(HandOver::AGENTS_MOVE) . '" /> '
				. esc_html__('Move their agents to the new owner (a person only); the agents keep what they own', 'tomatio') . '</label></p>';
		}

		$scopes = self::scopeGrantsHtml($inventory);

		/* translators: %s: name of the person or agent who leaves */
		$happens = sprintf(__('Hand over %s: what they own moves to the new owner, and their scopes, app passwords and AI connections are revoked now', 'tomatio'), (string) $inventory['principal']['name']);
		$confirm = Confirm::message($happens, __('Moved items can be handed back one by one; revoked access has to be granted again', 'tomatio'));

		$form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . Confirm::attr($confirm) . '>'
			. '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />'
			. '<input type="hidden" name="user_id" value="' . esc_attr((string) $userId) . '" />'
			. wp_nonce_field(self::nonceAction($userId), '_wpnonce', false, false)
			. '<p><label for="ab-hand-over-to">' . esc_html__('New owner', 'tomatio') . '</label> '
			. '<select id="ab-hand-over-to" name="new_owner_id" required>' . $options . '</select></p>'
			. $agents
			. $scopes
			. '<p><button type="submit" class="button button-primary">' . esc_html__('Hand over and revoke access', 'tomatio') . '</button></p>'
			. '</form>';

		return Ui::card(__('Hand over to', 'tomatio'), $form);
	}

	/**
	 * B222: the option to grant the new owner the scopes of what they
	 * inherit, off by default, with the plan listed before it runs.
	 *
	 * @param array<string, mixed> $inventory
	 */
	private static function scopeGrantsHtml(array $inventory): string {
		$plans   = (array) ($inventory['scope_grants'] ?? []);
		$suspend = (array) ($plans[HandOver::AGENTS_SUSPEND] ?? []);
		$move    = (array) ($plans[HandOver::AGENTS_MOVE] ?? []);
		if ($suspend === [] && $move === []) {
			return '<p class="ab-muted">' . esc_html__('What they own has no scope to pass on. Grant the new owner scopes on Scopes if they need more.', 'tomatio') . '</p>';
		}

		$html = '<p><label><input type="checkbox" name="grant_scopes" value="1" /> '
			. esc_html__('Also grant the new owner the scopes of what they inherit', 'tomatio') . '</label></p>'
			. '<p class="ab-muted">' . esc_html__('Off unless you tick it. Each scope is granted as they hold it now, never more:', 'tomatio') . '</p>'
			. self::scopeListHtml($suspend);

		if ($inventory['own_agents'] !== [] && $move !== $suspend) {
			$html .= '<p class="ab-muted">' . esc_html__('If their agents move to the new owner, what those agents own stays with them, so only:', 'tomatio') . '</p>'
				. ($move === [] ? '<p class="ab-muted">' . esc_html__('No scopes.', 'tomatio') . '</p>' : self::scopeListHtml($move));
		}

		return $html;
	}

	/**
	 * @param array<int, array<string, mixed>> $grants
	 */
	private static function scopeListHtml(array $grants): string {
		$html = '<ul class="ab-hand-over-scopes">';
		foreach ($grants as $grant) {
			$html .= '<li>' . esc_html((string) $grant['name']) . ': ' . esc_html(HandOver::listsLabel((bool) $grant['read'], (bool) $grant['write'])) . '</li>';
		}

		return $html . '</ul>';
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private static function resultHtml(array $result): string {
		$moved = count((array) ($result['moved'] ?? []));
		/* translators: 1: number of items, 2: new owner's name */
		$text = sprintf(_n('%1$s item moved to %2$s.', '%1$s items moved to %2$s.', $moved, 'tomatio'), number_format_i18n($moved), (string) ($result['to']['name'] ?? ''));
		$text .= ' ' . __('Their access is revoked, and the new owner has a handoff listing what they inherited.', 'tomatio');

		$scopes = count((array) ($result['scopes_granted'] ?? []));
		if ($scopes > 0) {
			/* translators: %s: number of scopes */
			$text .= ' ' . sprintf(_n('The new owner was granted %s scope.', 'The new owner was granted %s scopes.', $scopes, 'tomatio'), number_format_i18n($scopes));
		}

		return Ui::callout('ok', __('Handed over.', 'tomatio'), esc_html($text));
	}

	/**
	 * @param array<int, int> $scopeIds
	 */
	private static function scopeNames(array $scopeIds): string {
		if ($scopeIds === []) {
			return '—';
		}

		$names = [];
		foreach ($scopeIds as $id) {
			$term    = get_term($id);
			$names[] = $term instanceof \WP_Term ? $term->name : '#' . $id;
		}

		return implode(', ', $names);
	}

	private static function name(int $userId): string {
		$user = get_userdata($userId);

		return $user instanceof \WP_User ? (string) $user->display_name : '#' . $userId;
	}

	public static function handle(): void {
		if (! current_user_can(Capabilities::CAP_MANAGE)) {
			wp_die(esc_html__('You do not have permission to do this.', 'tomatio'), 403);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() below, bound to the leaver.
		$userId  = isset($_POST['user_id']) ? absint(wp_unslash((string) $_POST['user_id'])) : 0;
		$ownerId = isset($_POST['new_owner_id']) ? absint(wp_unslash((string) $_POST['new_owner_id'])) : 0;
		$agents  = isset($_POST['agents']) ? sanitize_key(wp_unslash((string) $_POST['agents'])) : HandOver::AGENTS_SUSPEND;
		$grant   = isset($_POST['grant_scopes']) && (string) wp_unslash((string) $_POST['grant_scopes']) === '1';
		// phpcs:enable
		check_admin_referer(self::nonceAction($userId));

		$actorId = get_current_user_id();
		$result  = HandOver::run($actorId, $userId, $ownerId, $agents, $grant);
		$args    = ['page' => self::SLUG, 'user' => (string) $userId];
		if ($result instanceof \WP_Error) {
			$args['ab_error'] = $result->get_error_message();
		} else {
			set_transient(self::RESULT . $actorId, $result, 10 * MINUTE_IN_SECONDS);
		}

		wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('admin.php')));
		exit;
	}
}
