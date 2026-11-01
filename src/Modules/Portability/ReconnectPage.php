<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Portability;

use AgentBrain\Modules\Admin\Notices;

/**
 * #551: the Reconnect section of the Export and import page, for after a
 * migration or a move: this brain's connector address, the people to
 * invite (one email field each, one button), every unbound agent's new key
 * in one download, and the share links to create again at this address.
 * Keys and links are handed over once: a key file is downloaded, never
 * shown or kept; a new share address or a failed invitation's link is
 * shown once to the administrator who made it.
 */
final class ReconnectPage {

	public const INVITE_ACTION = 'agent_brain_reconnect_invite';
	public const KEYS_ACTION   = 'agent_brain_reconnect_keys';
	public const SHARE_ACTION  = 'agent_brain_reconnect_share';
	public const SEAT_ACTION   = 'agent_brain_reconnect_seat';

	private const ONCE = 'ab_pcp_reconnect_once_';

	public static function register(): void {
		add_action('admin_post_' . self::INVITE_ACTION, [self::class, 'handleInvite']);
		add_action('admin_post_' . self::KEYS_ACTION, [self::class, 'handleKeys']);
		add_action('admin_post_' . self::SHARE_ACTION, [self::class, 'handleShare']);
		add_action('admin_post_' . self::SEAT_ACTION, [self::class, 'handleSeat']);
	}

	public static function render(): void {
		$list    = Reconnect::checklist();
		$people  = array_values(array_filter($list['people'], static fn (array $p): bool => ! $p['bound']));
		$agents  = array_values(array_filter($list['agents'], static fn (array $p): bool => ! $p['bound']));
		$pending = $people !== [] || $agents !== [] || $list['shares'] !== [] || $list['seats'] !== [];

		echo '<h2 id="reconnect">' . esc_html__('Reconnect', 'agent-brain') . '</h2>';
		echo '<p>' . esc_html__('After a migration or a move, people and agents come back without passwords or keys, every AI connector has to be added again at this address, and share links have to be made again here. This is what is left.', 'agent-brain') . '</p>';

		self::renderOnce();

		echo '<p><strong>' . esc_html__('Connector address', 'agent-brain') . '</strong> <code>' . esc_html($list['connector']) . '</code> '
			. '<a href="' . esc_url($list['connect_page']) . '">' . esc_html__('How to connect each AI', 'agent-brain') . '</a></p>';

		if (! $pending) {
			echo '<p>' . esc_html__('Everyone is back: every person and agent is bound and no share link waits to be made again.', 'agent-brain') . '</p>';

			return;
		}

		if ($people !== []) {
			echo '<h3>' . esc_html__('Invite people', 'agent-brain') . '</h3>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			wp_nonce_field(self::INVITE_ACTION);
			echo '<input type="hidden" name="action" value="' . esc_attr(self::INVITE_ACTION) . '" />';
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Person', 'agent-brain') . '</th><th scope="col">' . esc_html__('Email address (empty: not now)', 'agent-brain') . '</th></tr></thead><tbody>';
			foreach ($people as $person) {
				$field = 'ab-invite-' . md5($person['id']);
				echo '<tr><td><label for="' . esc_attr($field) . '"><code>' . esc_html($person['id']) . '</code> ' . esc_html($person['name']) . '</label></td>'
					. '<td><input type="email" id="' . esc_attr($field) . '" name="invites[' . esc_attr($person['id']) . ']" class="regular-text" value="" /></td></tr>';
			}
			echo '</tbody></table>';
			submit_button(__('Send invitations', 'agent-brain'), 'primary', 'submit_invites', false);
			echo '</form>';
		}

		if ($agents !== []) {
			echo '<h3>' . esc_html__('Give agents new keys', 'agent-brain') . '</h3>';
			echo '<p>' . esc_html(sprintf(
				/* translators: 1: number of agents, 2: their principal ids. */
				_n('%1$d agent has no key yet: %2$s.', '%1$d agents have no key yet: %2$s.', count($agents), 'agent-brain'),
				count($agents),
				implode(', ', array_column($agents, 'id'))
			)) . ' ' . esc_html__('One click makes a new application password for each and downloads them in one file, with the connector address. The keys are not shown or kept anywhere else.', 'agent-brain') . '</p>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			wp_nonce_field(self::KEYS_ACTION);
			echo '<input type="hidden" name="action" value="' . esc_attr(self::KEYS_ACTION) . '" />';
			submit_button(__('Make keys and download them', 'agent-brain'), 'secondary', 'submit_keys', false);
			echo '</form>';
		}

		if ($list['shares'] !== []) {
			echo '<h3>' . esc_html__('Share links to make again', 'agent-brain') . '</h3>';
			echo '<p>' . esc_html__('These links were live on the old brain. Their addresses do not work here; make a new link for the ones still needed and send it again.', 'agent-brain') . '</p>';
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Artifact', 'agent-brain') . '</th><th scope="col">' . esc_html__('Link', 'agent-brain') . '</th><th scope="col">' . esc_html__('Was valid until', 'agent-brain') . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__('Actions', 'agent-brain') . '</span></th></tr></thead><tbody>';
			foreach ($list['shares'] as $share) {
				echo '<tr><td>' . esc_html($share['title'] !== '' ? $share['title'] : '#' . $share['artifact']) . '</td>'
					. '<td>' . esc_html($share['kind'] . ', ' . $share['privacy'] . ', ' . $share['viewer_mode']) . '</td>'
					. '<td>' . esc_html($share['expires_at']) . '</td><td>';
				echo '<form method="post" style="display:inline" action="' . esc_url(admin_url('admin-post.php')) . '">';
				wp_nonce_field(self::SHARE_ACTION . '_' . $share['kept']);
				echo '<input type="hidden" name="action" value="' . esc_attr(self::SHARE_ACTION) . '" />';
				echo '<input type="hidden" name="kept" value="' . esc_attr((string) $share['kept']) . '" />';
				echo '<button type="submit" name="do" value="create" class="button">' . esc_html__('Make a new link', 'agent-brain') . '</button> ';
				echo '<button type="submit" name="do" value="drop" class="button-link">' . esc_html__('Not needed', 'agent-brain') . '</button>';
				echo '</form></td></tr>';
			}
			echo '</tbody></table>';
		}

		if ($list['seats'] !== []) {
			echo '<h3>' . esc_html__('Seats to claim back', 'agent-brain') . '</h3>';
			echo '<p>' . esc_html__('These names were claimed on the old brain and are still reserved here. Each viewer\'s secret is in their browser under the old address, so nobody is in their seat yet. A viewer who kept their personal link signs back in by opening the new share link with the "#seat=..." part of their old link after it. Or release the name, and they claim it again with a new secret.', 'agent-brain') . '</p>';
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Artifact', 'agent-brain') . '</th><th scope="col">' . esc_html__('Seat', 'agent-brain') . '</th><th scope="col">' . esc_html__('Opens', 'agent-brain') . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__('Actions', 'agent-brain') . '</span></th></tr></thead><tbody>';
			foreach ($list['seats'] as $seat) {
				$nonce = self::SEAT_ACTION . '_' . $seat['artifact'] . '_' . md5($seat['name']);
				echo '<tr><td>' . esc_html($seat['title'] !== '' ? $seat['title'] : '#' . $seat['artifact']) . '</td>'
					. '<td>' . esc_html($seat['name']) . '</td><td>' . esc_html(number_format_i18n($seat['opens'])) . '</td><td>';
				echo '<form method="post" style="display:inline" action="' . esc_url(admin_url('admin-post.php')) . '">';
				wp_nonce_field($nonce);
				echo '<input type="hidden" name="action" value="' . esc_attr(self::SEAT_ACTION) . '" />';
				echo '<input type="hidden" name="artifact" value="' . esc_attr((string) $seat['artifact']) . '" />';
				echo '<input type="hidden" name="seat" value="' . esc_attr($seat['name']) . '" />';
				echo '<button type="submit" class="button">' . esc_html__('Release the name', 'agent-brain') . '</button>';
				echo '</form></td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	public static function handleSeat(): void {
		self::guard();
		$artifact = isset($_POST['artifact']) ? absint(wp_unslash($_POST['artifact'])) : 0;
		$name     = isset($_POST['seat']) ? sanitize_text_field(wp_unslash($_POST['seat'])) : '';
		check_admin_referer(self::SEAT_ACTION . '_' . $artifact . '_' . md5($name));

		if (Reconnect::releaseSeat($artifact, $name, get_current_user_id())) {
			Notices::flash('success', __('The name is free again: its viewer can claim it with a new secret.', 'agent-brain'));
		} else {
			Notices::flash('error', __('That seat is not here any more.', 'agent-brain'));
		}

		self::back();
	}

	public static function handleInvite(): void {
		self::guard();
		check_admin_referer(self::INVITE_ACTION);

		$invites = [];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
		foreach ((array) ($_POST['invites'] ?? []) as $principal => $email) {
			$email = sanitize_email(wp_unslash((string) $email));
			if ($email !== '') {
				$invites[sanitize_text_field((string) $principal)] = $email;
			}
		}

		$results = Reconnect::invite($invites, get_current_user_id());
		$links   = [];
		$failed  = [];
		foreach ($results as $principal => $result) {
			if (! $result['ok']) {
				$failed[] = $principal . ': ' . (string) ($result['error'] ?? '');
			} elseif (empty($result['mailed'])) {
				$links[] = $principal . ' ' . (string) ($result['link'] ?? '');
			}
		}

		$sent = count($results) - count($failed) - count($links);
		Notices::flash($failed === [] ? 'success' : 'warning', sprintf(
			/* translators: 1: invitations mailed, 2: problems. */
			__('%1$d invitations mailed. %2$s', 'agent-brain'),
			$sent,
			implode(' ', $failed)
		));
		if ($links !== []) {
			set_transient(self::ONCE . get_current_user_id(), ['label' => __('The mail failed for these people. Pass each link on yourself; each works once:', 'agent-brain'), 'lines' => $links], 300);
		}

		self::back();
	}

	public static function handleKeys(): void {
		self::guard();
		check_admin_referer(self::KEYS_ACTION);

		$keys = Reconnect::keys(null, get_current_user_id());
		if ($keys === []) {
			Notices::flash('info', __('Every agent has a key already.', 'agent-brain'));
			self::back();
		}

		nocache_headers();
		header('Content-Type: text/plain; charset=utf-8');
		header('Content-Disposition: attachment; filename="agent-keys-' . gmdate('Ymd-His') . '.txt"');
		header('X-Content-Type-Options: nosniff');
		echo Reconnect::keyFile($keys); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a plain-text download, not HTML.
		exit;
	}

	public static function handleShare(): void {
		self::guard();
		$kept = isset($_POST['kept']) ? absint(wp_unslash($_POST['kept'])) : 0;
		check_admin_referer(self::SHARE_ACTION . '_' . $kept);

		if (isset($_POST['do']) && sanitize_key(wp_unslash($_POST['do'])) === 'drop') {
			Reconnect::forgetShare($kept);
			Notices::flash('success', __('That share link will not be made again.', 'agent-brain'));
			self::back();
		}

		$result = Reconnect::recreateShare($kept, get_current_user_id());
		if (is_wp_error($result)) {
			Notices::flash('error', $result->get_error_message());
		} else {
			set_transient(self::ONCE . get_current_user_id(), ['label' => __('The new share link. Copy it now; it is not shown again:', 'agent-brain'), 'lines' => [(string) $result['url']]], 300);
		}

		self::back();
	}

	/** A failed invitation's link or a new share address, once, to who made it. */
	private static function renderOnce(): void {
		$key  = self::ONCE . get_current_user_id();
		$once = get_transient($key);
		if (! is_array($once)) {
			return;
		}
		delete_transient($key);

		echo '<div class="notice notice-info ab-notice"><p>' . esc_html((string) $once['label']) . '</p>';
		foreach ((array) $once['lines'] as $line) {
			echo '<p><input type="text" class="large-text code" readonly value="' . esc_attr((string) $line) . '" /></p>';
		}
		echo '</div>';
	}

	private static function guard(): void {
		if (! Binding::canBind(get_current_user_id())) {
			wp_die(esc_html__('You do not have permission to do this.', 'agent-brain'), 403);
		}
	}

	private static function back(): void {
		wp_safe_redirect(add_query_arg(['page' => ExportPage::SLUG], admin_url('admin.php')) . '#reconnect');
		exit;
	}
}
