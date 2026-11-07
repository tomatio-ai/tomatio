<?php

declare(strict_types=1);

namespace AgentBrain\Modules\MergeLog;

use AgentBrain\Modules\Admin\SettingsHub;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;

/**
 * Settings > Merge log (#870): add a repository whose merges are saved in
 * the brain, or remove one. Adding shows the webhook address and secret
 * once; the secret is never shown again.
 */
final class SettingsTab {

	public const ACTION = 'ab_merge_log';
	private const SHOW  = 'ab_merge_log_new_';

	public static function register(): void {
		add_action('admin_post_' . self::ACTION, [self::class, 'handleSave']);
	}

	public static function renderBody(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag set by our own redirect.
		$status = isset($_GET['ab_merge_log']) ? sanitize_key(wp_unslash((string) $_GET['ab_merge_log'])) : '';
		$new    = get_transient(self::SHOW . get_current_user_id());
		if (is_array($new)) {
			delete_transient(self::SHOW . get_current_user_id());
			echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__('Added. In the repository\'s settings, add a webhook for pull request events with this address, content type application/json and this secret. The secret is shown only now.', 'tomatio') . '</p>'
				. '<p>' . esc_html__('Address:', 'tomatio') . ' <code>' . esc_html(Repos::url((string) $new[0])) . '</code><br />'
				. esc_html__('Secret:', 'tomatio') . ' <code>' . esc_html((string) $new[1]) . '</code></p></div>';
		} elseif ($status === 'removed') {
			echo '<div class="notice notice-success inline" role="status"><p>' . esc_html__('Removed. Delete the webhook in the repository too.', 'tomatio') . '</p></div>';
		} elseif ($status !== '') {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html__('Nothing was added: give the repository as owner/name, and pick a scope the agent can write to.', 'tomatio') . '</p></div>';
		}

		$scopes = self::scopes();
		echo '<section class="ab-ui-card">';

		$repos = Repos::all();
		if ($repos === []) {
			echo '<p>' . esc_html__('No repository reports its merges yet.', 'tomatio') . '</p>';
		} else {
			// Its own form, so Enter in the add form never presses a Remove button.
			echo self::formStart() . '<ul class="ab-merge-log-repos">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- formStart() escapes.
			foreach ($repos as $id => $repo) {
				$agent = get_userdata((int) $repo['agent_id']);
				echo '<li><strong>' . esc_html($repo['repo']) . '</strong> · '
					. esc_html(sprintf(
						/* translators: 1: scope name, 2: agent name. */
						__('saved in %1$s as %2$s', 'tomatio'),
						$scopes[(int) $repo['scope_id']] ?? '?',
						$agent instanceof \WP_User ? $agent->display_name : '?'
					))
					. ($repo['token'] !== '' ? ' · ' . esc_html__('with a read token', 'tomatio') : '')
					. '<br /><code>' . esc_html(Repos::url((string) $id)) . '</code> '
					. '<button type="submit" class="button button-link-delete" name="remove" value="' . esc_attr((string) $id) . '">' . esc_html(sprintf(
						/* translators: %s: owner/repository. */
						__('Remove %s', 'tomatio'),
						$repo['repo']
					)) . '</button></li>';
			}
			echo '</ul></form>';
		}

		$scopeOptions = '';
		foreach ($scopes as $id => $name) {
			$scopeOptions .= '<option value="' . esc_attr((string) $id) . '">' . esc_html($name) . '</option>';
		}
		$agentOptions = '';
		foreach (get_users(['role' => Capabilities::ROLE_AGENT, 'orderby' => 'display_name', 'number' => 200]) as $user) {
			$agentOptions .= '<option value="' . esc_attr((string) $user->ID) . '">' . esc_html($user->display_name) . '</option>';
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui and formStart() escape what they emit; the options are escaped above.
		echo self::formStart() . '<h3>' . esc_html__('Add a repository', 'tomatio') . '</h3>';
		echo Ui::setting(__('Repository', 'tomatio'), esc_html__('owner/name, as in its address on Forgejo, Gitea or GitHub.', 'tomatio'), '<input type="text" class="regular-text" id="ab-merge-log-repo" name="repo" placeholder="team/project" />', 'ab-merge-log-repo');
		echo Ui::setting(__('Save in scope', 'tomatio'), esc_html__('Who can read the merge memories.', 'tomatio'), '<select id="ab-merge-log-scope" name="scope_id">' . $scopeOptions . '</select>', 'ab-merge-log-scope');
		echo Ui::setting(__('Save as agent', 'tomatio'), esc_html__('The agent the memories are written by. It must be able to write to that scope.', 'tomatio'), '<select id="ab-merge-log-agent" name="agent_id">' . $agentOptions . '</select>', 'ab-merge-log-agent');
		echo Ui::setting(__('Read token (private repositories)', 'tomatio'), esc_html__('Optional. A token that can read the repository, so the brain can see which files a pull request changed. Stored encrypted.', 'tomatio'), '<input type="password" class="regular-text" id="ab-merge-log-token" name="token" autocomplete="off" />', 'ab-merge-log-token', '<p>' . esc_html__('Only merged pull requests that add a changelog.d/ fragment are saved, one memory each: what was merged, by whom, and a link. Label a pull request no-brain-log to leave it out.', 'tomatio') . '</p>');
		echo Ui::saveBar('add', __('Add repository', 'tomatio'));
		// phpcs:enable
		echo '</form></section>';
	}

	public static function handleSave(): void {
		if (! current_user_can(SettingsHub::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage TomaTío settings.', 'tomatio'), 403);
		}
		check_admin_referer(self::ACTION);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$result = self::save(wp_unslash($_POST));

		wp_safe_redirect(SettingsHub::url(SettingsHub::TAB_MERGE_LOG, $result !== '' ? ['ab_merge_log' => $result] : []));
		exit;
	}

	/**
	 * Adds or removes a repository. Returns a status key: '' after an add
	 * (the address and secret wait in a transient), 'removed' or an error.
	 *
	 * @param array<string, mixed> $post Unslashed form data.
	 */
	public static function save(array $post): string {
		if (isset($post['remove']) && is_string($post['remove'])) {
			$id = sanitize_key($post['remove']);
			if (Repos::remove($id)) {
				self::note('merge_log.removed', $id);
			}

			return 'removed';
		}

		$repo    = Repos::normalize(is_string($post['repo'] ?? null) ? $post['repo'] : '');
		$scopeId = (int) ($post['scope_id'] ?? 0);
		$agentId = (int) ($post['agent_id'] ?? 0);
		$token   = is_string($post['token'] ?? null) ? trim($post['token']) : '';
		$agent   = get_userdata($agentId);
		if ($repo === '' || ! $agent instanceof \WP_User || ! in_array(Capabilities::ROLE_AGENT, (array) $agent->roles, true) || ! (new Scopes())->canWrite($agentId, $scopeId)) {
			return 'invalid';
		}

		[$id, $secret] = Repos::add($repo, $scopeId, $agentId, $token);
		set_transient(self::SHOW . get_current_user_id(), [$id, $secret], 10 * MINUTE_IN_SECONDS);
		self::note('merge_log.added', $id);

		return '';
	}

	/** A form posting to this tab's action; its nonce field has no id, so two forms never share one. */
	private static function formStart(): string {
		return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::ACTION)) . '" />'
			. '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
	}

	/** @return array<int, string> scope id => name */
	private static function scopes(): array {
		$terms = get_terms(['taxonomy' => Scopes::TAXONOMY, 'hide_empty' => false]);
		$out   = [];
		foreach (is_array($terms) ? $terms : [] as $term) {
			if ($term instanceof \WP_Term) {
				$out[$term->term_id] = html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');
			}
		}

		return $out;
	}

	private static function note(string $event, string $id): void {
		\AgentBrain\Core\Hooks::action('audit', [
			'actor_id'    => get_current_user_id(),
			'event'       => $event,
			'status'      => 'ok',
			'object_type' => 'setting',
			'object_id'   => $id,
		]);
	}
}
