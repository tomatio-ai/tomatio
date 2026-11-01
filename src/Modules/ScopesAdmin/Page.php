<?php

declare(strict_types=1);

namespace AgentBrain\Modules\ScopesAdmin;

use AgentBrain\Core\Db;
use AgentBrain\Modules\Admin\EmptyState;
use AgentBrain\Modules\Admin\Menu;
use AgentBrain\Modules\Admin\Ui;
use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Artifacts\Cpts as ArtifactCpts;
use AgentBrain\Modules\Memory\MemoryRepository;
use AgentBrain\Modules\Review\InboxItems;

/**
 * The "Scopes" admin screen. Capability: ab_grant_scopes, the same
 * capability Scopes::grant()/revoke() enforce, so the screen and the service
 * can never disagree. Since #751 it is one grid: scopes down the side,
 * agents across the top, and in each cell what the agent may do there,
 * changed with a click and undone from the notice. All grants and revokes go through the Scopes service
 * (which audits them); term create/rename/delete write their own audit
 * events.
 */
final class Page {

	public const CAPABILITY = Capabilities::CAP_GRANT_SCOPES;
	public const SLUG       = 'agent-brain-scopes';

	public const ACTION_CREATE = 'agent_brain_scope_create';
	public const ACTION_RENAME = 'agent_brain_scope_rename';
	public const ACTION_DELETE = 'agent_brain_scope_delete';
	public const ACTION_GRANT  = 'agent_brain_scope_grant';
	public const ACTION_SET    = 'agent_brain_scope_set';

	/** What an agent may do in a scope, in the order a grid cell steps through them. */
	public const LEVELS = ['none', 'read', 'write'];

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addMenu']);
		add_action('admin_post_' . self::ACTION_CREATE, [self::class, 'handleCreate']);
		add_action('admin_post_' . self::ACTION_RENAME, [self::class, 'handleRename']);
		add_action('admin_post_' . self::ACTION_DELETE, [self::class, 'handleDelete']);
		add_action('admin_post_' . self::ACTION_GRANT, [self::class, 'handleGrant']);
		add_action('admin_post_' . self::ACTION_SET, [self::class, 'handleSet']);
	}

	public static function addMenu(): void {
		add_submenu_page(
			Menu::PARENT,
			__('Scopes', 'agent-brain'),
			__('Scopes', 'agent-brain'),
			self::CAPABILITY,
			self::SLUG,
			[self::class, 'render']
		);
	}

	public static function render(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage Scopes.', 'agent-brain'), 403);
		}

		$terms  = self::terms();
		$agents = self::agentNames();
		/* translators: 1: number of scopes, 2: number of agents */
		$status = sprintf(esc_html__('%1$s · %2$s', 'agent-brain'), '<b>' . esc_html(sprintf(_n('%s scope', '%s scopes', count($terms), 'agent-brain'), number_format_i18n(count($terms)))) . '</b>', esc_html(sprintf(_n('%s agent', '%s agents', count($agents), 'agent-brain'), number_format_i18n(count($agents)))));
		$about  = '<p>' . esc_html__('A scope is a named partition of memory and knowledge. An agent can only read the scopes in its read list and write the scopes in its write list.', 'agent-brain') . '</p>'
			. '<p>' . esc_html__('Click a cell to step it through —, Reads and Writes. Each change is saved at once and can be undone. Writing includes reading. Delete sits under Rename, and only for a scope that holds nothing.', 'agent-brain') . '</p>';
		$actions = '<a class="button button-primary" href="#ab-scope-new">' . esc_html__('Add a scope', 'agent-brain') . '</a>';

		echo '<div class="wrap ab-admin ab-admin-scopes">' . Ui::head(__('Scopes', 'agent-brain'), $status, $actions, $about); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		self::renderNotice();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector, no state change.
		$confirmId = isset($_GET['confirm_delete']) ? absint(wp_unslash($_GET['confirm_delete'])) : 0;
		if ($confirmId > 0) {
			self::renderDeleteConfirmation($confirmId);
			echo '</div>';

			return;
		}

		self::renderCreateForm($terms === []);
		self::renderGrid($terms, $agents);

		echo '</div>';
	}

	// ---------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------

	private static function renderCreateForm(bool $open): void {
		echo '<details id="ab-scope-new" class="ab-ui-card ab-ui-new"' . ($open ? ' open' : '') . '><summary>' . esc_html__('Add a scope', 'agent-brain') . '</summary>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::ACTION_CREATE);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_CREATE) . '" />';
		echo '<p><label for="ab_scope_name">' . esc_html__('Name', 'agent-brain') . '</label> '
			. '<input type="text" id="ab_scope_name" name="name" class="regular-text" required maxlength="100" /> ';
		submit_button(__('Add scope', 'agent-brain'), 'primary', 'submit', false);
		echo '</p></form></details>';
	}

	/**
	 * Scopes down the side, agents across the top, and in each cell what
	 * that agent may do there. A cell is a one-button form that moves it to
	 * the next level (— → Reads → Writes → —).
	 *
	 * @param array<int, \WP_Term> $terms
	 * @param array<int, string>   $agents
	 */
	private static function renderGrid(array $terms, array $agents): void {
		if ($terms === []) {
			$empty = EmptyState::html(
				__('No scopes yet', 'agent-brain'),
				__('Add one, then give agents access to it: an agent with no scopes can neither read nor write memory.', 'agent-brain'),
				null
			);
			echo $empty; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EmptyState escapes.

			return;
		}

		$held  = self::agentScopes();
		$nonce = wp_create_nonce(self::ACTION_SET);
		$words = ['none' => '—', 'read' => __('Reads', 'agent-brain'), 'write' => __('Writes', 'agent-brain')];

		echo '<div class="ab-ui-card ab-scope-card"><div class="ab-table-wrap"><table class="ab-scope-grid"><thead><tr><th scope="col">' . esc_html__('Scope', 'agent-brain') . '</th>';
		foreach (array_keys($agents) as $agentId) {
			$who = InboxItems::who($agentId);
			echo '<th scope="col">' . esc_html($who['name']);
			if ($who['person'] !== '') {
				/* translators: %s: first name of the person the agent works for. Tío is TomaTío's word for a person's AI agent. */
				echo '<small>' . esc_html(sprintf(__('%s\'s Tío', 'agent-brain'), explode(' ', trim($who['person']))[0])) . '</small>';
			}
			echo '</th>';
		}
		echo '<th scope="col"><span class="ab-ui-sr">' . esc_html__('Actions', 'agent-brain') . '</span></th></tr></thead><tbody>';

		foreach ($terms as $term) {
			$id       = (int) $term->term_id;
			$memories = self::memoryCount($id);
			$posts    = self::postCount($id);

			echo '<tr><th scope="row"><strong>' . esc_html($term->name) . '</strong><small>'
				. esc_html(self::holdsWords($memories, $posts))
				. '</small></th>';

			foreach (array_keys($agents) as $agentId) {
				$level = self::levelOf($held[$agentId] ?? ['read' => [], 'write' => []], $id);
				$next  = self::LEVELS[(array_search($level, self::LEVELS, true) + 1) % count(self::LEVELS)];
				$label = sprintf(
					/* translators: 1: agent, 2: scope, 3: what it may do now, 4: what a click changes it to */
					__('%1$s in %2$s: %3$s. Change to %4$s.', 'agent-brain'),
					InboxItems::who($agentId)['name'],
					$term->name,
					$level === 'none' ? __('no access', 'agent-brain') : $words[$level],
					$next === 'none' ? __('no access', 'agent-brain') : $words[$next]
				);

				echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
					. '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SET) . '" />'
					. '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />'
					. '<input type="hidden" name="agent_id" value="' . esc_attr((string) $agentId) . '" />'
					. '<input type="hidden" name="scope_id" value="' . esc_attr((string) $id) . '" />'
					. '<input type="hidden" name="level" value="' . esc_attr($next) . '" />'
					. '<button type="submit" class="ab-scope-cell ab-scope-cell--' . esc_attr($level) . '" aria-label="' . esc_attr($label) . '" title="' . esc_attr($label) . '">' . esc_html($words[$level]) . '</button>'
					. '</form></td>';
			}

			echo '<td>' . self::renameMenu($term, $memories + $posts === 0) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renameMenu() escapes.
		}

		echo '</tbody></table></div></div>';
	}

	/**
	 * "3 memories · 2 in Library", or "empty".
	 */
	private static function holdsWords(int $memories, int $posts): string {
		$parts = [];
		if ($memories > 0) {
			/* translators: %s: number of memories */
			$parts[] = sprintf(_n('%s memory', '%s memories', $memories, 'agent-brain'), number_format_i18n($memories));
		}
		if ($posts > 0) {
			/* translators: %s: number of knowledge, procedure and document posts */
			$parts[] = sprintf(__('%s in Library', 'agent-brain'), number_format_i18n($posts));
		}

		return $parts === [] ? __('empty', 'agent-brain') : implode(' · ', $parts);
	}

	/**
	 * Rename, and Delete for a scope that holds nothing, behind one button.
	 */
	private static function renameMenu(\WP_Term $term, bool $empty): string {
		$id   = (int) $term->term_id;
		$out  = '<details class="ab-ui-menu"><summary class="button button-small">' . esc_html__('Rename', 'agent-brain') . '</summary><div class="ab-ui-menu-list">';
		$out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
			. wp_nonce_field(self::ACTION_RENAME . '_' . $id, '_wpnonce', true, false)
			. '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RENAME) . '" />'
			. '<input type="hidden" name="scope_id" value="' . esc_attr((string) $id) . '" />'
			. '<label for="ab_scope_rename_' . esc_attr((string) $id) . '">' . esc_html__('New name', 'agent-brain') . '</label>'
			. '<input type="text" id="ab_scope_rename_' . esc_attr((string) $id) . '" name="name" value="' . esc_attr($term->name) . '" maxlength="100" />'
			. '<button type="submit" class="button button-primary">' . esc_html__('Rename', 'agent-brain') . '</button></form>';
		if ($empty) {
			$out .= '<a class="button button-link-delete" href="' . esc_url(self::url(['confirm_delete' => $id])) . '">' . esc_html__('Delete', 'agent-brain') . '</a>';
		}

		return $out . '</div></details>';
	}

	private static function renderDeleteConfirmation(int $scopeId): void {
		$term = get_term($scopeId, Scopes::TAXONOMY);
		$back = add_query_arg(['page' => self::SLUG], admin_url('admin.php'));

		if (! $term instanceof \WP_Term) {
			echo '<p class="ab-empty">' . esc_html__('That scope no longer exists.', 'agent-brain') . '</p>';
			echo '<p><a href="' . esc_url($back) . '">' . esc_html__('Back to Scopes', 'agent-brain') . '</a></p>';

			return;
		}

		$holders  = Holders::invert(self::agentScopes())[$scopeId] ?? ['read' => [], 'write' => []];
		$agents   = self::agentNames();
		$heldBy   = Holders::agents($holders);
		$memories = self::memoryCount($scopeId);
		$posts    = self::postCount($scopeId);

		echo '<h2>' . esc_html(sprintf(
			/* translators: %s: scope name */
			__('Delete scope "%s"?', 'agent-brain'),
			$term->name
		)) . '</h2>';

		if ($memories > 0) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html(sprintf(
				/* translators: %d: number of memories */
				_n(
					'%d memory is stored in this scope, so it cannot be deleted. Move or delete the memory first (Memories screen, or Forget).',
					'%d memories are stored in this scope, so it cannot be deleted. Move or delete the memories first (Memories screen, or Forget).',
					$memories,
					'agent-brain'
				),
				$memories
			)) . '</p></div>';
			echo '<p><a href="' . esc_url($back) . '">' . esc_html__('Back to Scopes', 'agent-brain') . '</a></p>';

			return;
		}

		if ($posts > 0) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html(sprintf(
				/* translators: %d: number of posts */
				_n(
					'%d knowledge, procedure or artifact post uses this scope, so it cannot be deleted. Reassign or delete that post first.',
					'%d knowledge, procedure or artifact posts use this scope, so it cannot be deleted. Reassign or delete those posts first.',
					$posts,
					'agent-brain'
				),
				$posts
			)) . '</p></div>';
			echo '<p><a href="' . esc_url($back) . '">' . esc_html__('Back to Scopes', 'agent-brain') . '</a></p>';

			return;
		}

		if ($heldBy !== []) {
			echo '<div class="notice notice-warning inline" role="status"><p>' . esc_html(sprintf(
				/* translators: %s: comma separated agent names */
				__('These agents currently hold this scope and will lose access: %s.', 'agent-brain'),
				self::names($heldBy, $agents)
			)) . '</p></div>';
		} else {
			echo '<p>' . esc_html__('No agent holds this scope.', 'agent-brain') . '</p>';
		}

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::ACTION_DELETE . '_' . $scopeId);
		echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_DELETE) . '" />';
		echo '<input type="hidden" name="scope_id" value="' . esc_attr((string) $scopeId) . '" />';
		echo '<input type="hidden" name="confirm" value="1" />';
		submit_button(__('Revoke all access and delete scope', 'agent-brain'), 'delete', 'submit', false);
		echo ' <a href="' . esc_url($back) . '">' . esc_html__('Cancel', 'agent-brain') . '</a>';
		echo '</form>';
	}

	private static function renderNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash code.
		$code = isset($_GET['ab_notice']) ? sanitize_key(wp_unslash($_GET['ab_notice'])) : '';

		$messages = [
			'created' => ['success', __('Scope added.', 'agent-brain')],
			'renamed' => ['success', __('Scope renamed.', 'agent-brain')],
			'deleted' => ['success', __('Scope deleted and all access revoked.', 'agent-brain')],
			'granted' => ['success', __('Access granted.', 'agent-brain')],
			'revoked' => ['success', __('Access revoked.', 'agent-brain')],
			'exists'  => ['error', __('A scope with that name already exists.', 'agent-brain')],
			'invalid' => ['error', __('That request was not valid.', 'agent-brain')],
			'inuse'   => ['error', __('That scope still has memories and cannot be deleted.', 'agent-brain')],
			'not_held' => ['warning', __('Agent did not hold this access.', 'agent-brain')],
			'error'   => ['error', __('That change could not be completed.', 'agent-brain')],
		];

		if ($code === 'set') {
			self::renderSetNotice();

			return;
		}

		if (isset($messages[$code])) {
			echo '<div class="notice notice-' . esc_attr($messages[$code][0]) . ' is-dismissible" role="' . ('error' === $messages[$code][0] ? 'alert' : 'status') . '"><p>' . esc_html($messages[$code][1]) . '</p></div>';
		}
	}

	/**
	 * "builder now writes Engineering", with Undo: a form that sets the
	 * cell back to what it was.
	 */
	private static function renderSetNotice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash details.
		$agentId = isset($_GET['agent']) ? absint(wp_unslash($_GET['agent'])) : 0;
		$scopeId = isset($_GET['scope']) ? absint(wp_unslash($_GET['scope'])) : 0;
		$from    = isset($_GET['from']) ? sanitize_key(wp_unslash((string) $_GET['from'])) : '';
		// phpcs:enable
		$term = get_term($scopeId, Scopes::TAXONOMY);
		if ($agentId <= 0 || ! $term instanceof \WP_Term || ! in_array($from, self::LEVELS, true)) {
			return;
		}

		$scopes = new Scopes();
		$level  = self::levelOf(['read' => $scopes->readScopeIds($agentId), 'write' => $scopes->writeScopeIds($agentId)], $scopeId);
		$name   = InboxItems::who($agentId)['name'];
		$text   = [
			/* translators: 1: agent, 2: scope */
			'none'  => __('%1$s no longer has access to %2$s.', 'agent-brain'),
			/* translators: 1: agent, 2: scope */
			'read'  => __('%1$s now reads %2$s.', 'agent-brain'),
			/* translators: 1: agent, 2: scope */
			'write' => __('%1$s now writes %2$s.', 'agent-brain'),
		];

		$undo = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SET) . '" />'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::ACTION_SET)) . '" />'
			. '<input type="hidden" name="agent_id" value="' . esc_attr((string) $agentId) . '" />'
			. '<input type="hidden" name="scope_id" value="' . esc_attr((string) $scopeId) . '" />'
			. '<input type="hidden" name="level" value="' . esc_attr($from) . '" />'
			. '<button type="submit" class="button button-small">' . esc_html__('Undo', 'agent-brain') . '</button></form>';

		echo Ui::callout('ok', sprintf($text[$level], $name, $term->name), '', $undo); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
	}

	/**
	 * @param array{read: array<int, int>, write: array<int, int>} $held
	 */
	private static function levelOf(array $held, int $scopeId): string {
		if (in_array($scopeId, $held['write'], true)) {
			return 'write';
		}

		return in_array($scopeId, $held['read'], true) ? 'read' : 'none';
	}

	// ---------------------------------------------------------------
	// Actions
	// ---------------------------------------------------------------

	public static function handleCreate(): void {
		self::finish(self::processCreate(self::post()));
	}

	public static function handleRename(): void {
		self::finish(self::processRename(self::post()));
	}

	public static function handleDelete(): void {
		self::finish(self::processDelete(self::post()));
	}

	public static function handleGrant(): void {
		self::finish(self::processGrant(self::post()));
	}

	public static function handleSet(): void {
		self::finish(self::processSet(self::post()));
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function post(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every process*() verifies the nonce.
		return (array) wp_unslash($_POST);
	}

	private static function finish(string $url): void {
		wp_safe_redirect($url);
		exit;
	}

	/**
	 * @param array<string, mixed> $post
	 */
	public static function processCreate(array $post): string {
		self::authorise($post, self::ACTION_CREATE);

		$name = self::cleanName($post['name'] ?? '');
		if ($name === '') {
			return self::url(['ab_notice' => 'invalid']);
		}

		$result = wp_insert_term($name, Scopes::TAXONOMY);
		if (is_wp_error($result)) {
			return self::url(['ab_notice' => $result->get_error_code() === 'term_exists' ? 'exists' : 'error']);
		}

		self::audit('scope.created', 'ok', (int) $result['term_id'], ['name' => $name]);

		return self::url(['ab_notice' => 'created']);
	}

	/**
	 * @param array<string, mixed> $post
	 */
	public static function processRename(array $post): string {
		$id = isset($post['scope_id']) ? absint($post['scope_id']) : 0;
		self::authorise($post, self::ACTION_RENAME . '_' . $id);

		$name = self::cleanName($post['name'] ?? '');
		$term = get_term($id, Scopes::TAXONOMY);
		if ($name === '' || ! $term instanceof \WP_Term) {
			return self::url(['ab_notice' => 'invalid']);
		}

		$args = ['name' => $name];
		// The slug follows the name unless it was customised (differs from
		// what the old name would have generated).
		if ($term->slug === sanitize_title($term->name)) {
			$args['slug'] = sanitize_title($name);
		}

		$result = wp_update_term($id, Scopes::TAXONOMY, $args);
		if (is_wp_error($result)) {
			return self::url(['ab_notice' => $result->get_error_code() === 'duplicate_term_slug' ? 'exists' : 'error']);
		}

		self::audit('scope.renamed', 'ok', $id, ['name' => $name]);

		return self::url(['ab_notice' => 'renamed']);
	}

	/**
	 * Revokes the scope from every holder first, then deletes the term. If
	 * any revoke fails the term is kept, so a scope is never deleted while an
	 * agent's meta still points at it.
	 *
	 * @param array<string, mixed> $post
	 */
	public static function processDelete(array $post): string {
		$id = isset($post['scope_id']) ? absint($post['scope_id']) : 0;
		self::authorise($post, self::ACTION_DELETE . '_' . $id);

		if (! get_term($id, Scopes::TAXONOMY) instanceof \WP_Term) {
			return self::url(['ab_notice' => 'invalid']);
		}

		$holders = Holders::invert(self::agentScopes())[$id] ?? ['read' => [], 'write' => []];

		// Deleting always needs an explicit confirmation step, which also
		// lists the agents that will lose access.
		if (! isset($post['confirm']) || (string) $post['confirm'] !== '1') {
			return self::url(['confirm_delete' => $id]);
		}

		if (self::memoryCount($id) > 0 || self::postCount($id) > 0) {
			return self::url(['ab_notice' => 'inuse']);
		}

		$scopes = new Scopes();
		$actor  = (int) get_current_user_id();

		foreach (['read', 'write'] as $list) {
			foreach ($holders[$list] as $agentId) {
				if (! $scopes->revoke($actor, $agentId, $list, $id)) {
					self::audit('scope.deleted', 'error', $id, ['reason' => 'revoke_failed', 'agent_id' => $agentId]);

					return self::url(['ab_notice' => 'error']);
				}
			}
		}

		$deleted = wp_delete_term($id, Scopes::TAXONOMY);
		$ok      = $deleted === true;

		self::audit('scope.deleted', $ok ? 'ok' : 'error', $id, ['revoked_agents' => Holders::agents($holders)]);

		return self::url(['ab_notice' => $ok ? 'deleted' : 'error']);
	}

	/**
	 * @param array<string, mixed> $post
	 */
	public static function processGrant(array $post): string {
		self::authorise($post, self::ACTION_GRANT);

		$agentId = isset($post['agent_id']) ? absint($post['agent_id']) : 0;
		$scopeId = isset($post['scope_id']) ? absint($post['scope_id']) : 0;
		$list    = isset($post['list']) && is_string($post['list']) ? $post['list'] : '';
		$mode    = isset($post['mode']) && is_string($post['mode']) ? $post['mode'] : '';
		$agent   = $agentId > 0 ? get_userdata($agentId) : false;

		if (! in_array($list, ['read', 'write'], true) || ! in_array($mode, ['grant', 'revoke'], true)
			|| ! $agent instanceof \WP_User || ! in_array(Capabilities::ROLE_AGENT, (array) $agent->roles, true)) {
			return self::url(['ab_notice' => 'invalid']);
		}

		$scopes = new Scopes();
		$actor  = (int) get_current_user_id();

		if ($mode === 'revoke') {
			$held = $list === 'read' ? $scopes->readScopeIds($agentId) : $scopes->writeScopeIds($agentId);
			if (! in_array($scopeId, $held, true)) {
				// Nothing to revoke: say so, and do not write an ok audit row.
				return self::url(['ab_notice' => 'not_held']);
			}
		}

		$ok = $mode === 'grant'
			? $scopes->grant($actor, $agentId, $list, $scopeId)
			: $scopes->revoke($actor, $agentId, $list, $scopeId);

		return self::url(['ab_notice' => $ok ? ($mode === 'grant' ? 'granted' : 'revoked') : 'error']);
	}

	/**
	 * Set what one agent may do in one scope (#751's grid): none, read, or
	 * write (which includes read). Grants and revokes go through the Scopes
	 * service, which audits each.
	 *
	 * @param array<string, mixed> $post
	 */
	public static function processSet(array $post): string {
		self::authorise($post, self::ACTION_SET);

		$agentId = isset($post['agent_id']) ? absint($post['agent_id']) : 0;
		$scopeId = isset($post['scope_id']) ? absint($post['scope_id']) : 0;
		$level   = isset($post['level']) && is_string($post['level']) ? $post['level'] : '';
		$agent   = $agentId > 0 ? get_userdata($agentId) : false;

		if (! in_array($level, self::LEVELS, true) || ! get_term($scopeId, Scopes::TAXONOMY) instanceof \WP_Term
			|| ! $agent instanceof \WP_User || ! in_array(Capabilities::ROLE_AGENT, (array) $agent->roles, true)) {
			return self::url(['ab_notice' => 'invalid']);
		}

		$scopes = new Scopes();
		$actor  = (int) get_current_user_id();
		$held   = ['read' => $scopes->readScopeIds($agentId), 'write' => $scopes->writeScopeIds($agentId)];
		$from   = self::levelOf($held, $scopeId);
		$want   = ['read' => $level !== 'none', 'write' => $level === 'write'];

		$ok = true;
		foreach ($want as $list => $wanted) {
			$has = in_array($scopeId, $held[$list], true);
			if ($wanted && ! $has) {
				$ok = $scopes->grant($actor, $agentId, $list, $scopeId) && $ok;
			} elseif (! $wanted && $has) {
				$ok = $scopes->revoke($actor, $agentId, $list, $scopeId) && $ok;
			}
		}

		return self::url($ok ? ['ab_notice' => 'set', 'agent' => $agentId, 'scope' => $scopeId, 'from' => $from] : ['ab_notice' => 'error']);
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function authorise(array $post, string $nonceAction): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to manage Scopes.', 'agent-brain'), 403);
		}

		$nonce = isset($post['_wpnonce']) && is_string($post['_wpnonce']) ? $post['_wpnonce'] : '';
		if (wp_verify_nonce($nonce, $nonceAction) === false) {
			wp_die(esc_html__('The link you followed has expired. Please go back and try again.', 'agent-brain'), 403);
		}
	}

	/**
	 * @param mixed $value
	 */
	private static function cleanName($value): string {
		return is_string($value) ? substr(trim(sanitize_text_field($value)), 0, 100) : '';
	}

	/**
	 * @param array<string, mixed> $meta
	 */
	private static function audit(string $event, string $status, int $id, array $meta): void {
		do_action('agent_brain/audit', [
			'actor_id'    => (int) get_current_user_id(),
			'event'       => $event,
			'status'      => $status,
			'object_type' => Scopes::TAXONOMY,
			'object_id'   => (string) $id,
			'meta'        => $meta,
		]);
	}

	/**
	 * @param array<string, scalar> $args
	 */
	private static function url(array $args): string {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	// ---------------------------------------------------------------
	// Lookups
	// ---------------------------------------------------------------

	/**
	 * @return array<int, \WP_Term>
	 */
	private static function terms(): array {
		$terms = get_terms(['taxonomy' => Scopes::TAXONOMY, 'hide_empty' => false, 'orderby' => 'name']);

		return is_array($terms) ? array_values(array_filter($terms, static fn ($t): bool => $t instanceof \WP_Term)) : [];
	}

	/**
	 * @return array<int, string> agent user id => "Display (login)"
	 */
	private static function agentNames(): array {
		$out = [];

		foreach (get_users(['role' => Capabilities::ROLE_AGENT, 'orderby' => 'display_name']) as $user) {
			$out[(int) $user->ID] = $user->display_name . ' (' . $user->user_login . ')';
		}

		return $out;
	}

	/**
	 * @return array<int, array{read: array<int, int>, write: array<int, int>}>
	 */
	private static function agentScopes(): array {
		$scopes = new Scopes();
		$out    = [];

		foreach (array_keys(self::agentNames()) as $agentId) {
			$out[$agentId] = [
				'read'  => $scopes->readScopeIds($agentId),
				'write' => $scopes->writeScopeIds($agentId),
			];
		}

		return $out;
	}

	/**
	 * @param array<int, int>    $agentIds
	 * @param array<int, string> $names
	 */
	private static function names(array $agentIds, array $names): string {
		if ($agentIds === []) {
			return __('(none)', 'agent-brain');
		}

		return implode(', ', array_map(static fn (int $id): string => $names[$id] ?? ('#' . $id), $agentIds));
	}

	private static function memoryCount(int $scopeId): int {
		$rows = Db::getResults('SELECT COUNT(*) AS c FROM ' . MemoryRepository::memoryTable() . ' WHERE scope_id = %d', [$scopeId]);

		return (int) ($rows[0]['c'] ?? 0);
	}

	/**
	 * Posts (any type or status) that carry the scope term, or whose
	 * ab_scope_id meta (artifacts) points at it.
	 */
	private static function postCount(int $scopeId): int {
		$base = [
			'post_type'              => 'any',
			'post_status'            => 'any',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		$byTerm = get_posts($base + [
			'tax_query' => [
				[
					'taxonomy'         => Scopes::TAXONOMY,
					'field'            => 'term_id',
					'terms'            => [$scopeId],
					'include_children' => false,
				],
			],
		]);
		$byMeta = get_posts($base + [
			'meta_query' => [
				[
					'key'     => ArtifactCpts::META_SCOPE_ID,
					'value'   => (string) $scopeId,
					'compare' => '=',
				],
			],
		]);

		return count(array_unique(array_map('intval', array_merge($byTerm, $byMeta))));
	}
}
