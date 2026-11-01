<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Review\HandoffQueue;

/**
 * TomaTío wording and details for the handoff, knowledge and procedure
 * add/edit screens (issues #77, #79, #92). These types no longer support
 * the native "Custom Fields" box; instead a read-only "TomaTío details"
 * box shows from/to/status (handoffs) or scope/status (knowledge, procedures),
 * the "Post updated." notices say what the item is, and the screen help
 * explains the screen.
 *
 * Wave 6 (#77, #79): the box is also where the scope (knowledge, procedures)
 * and the receiving agent (open handoffs) are edited, and the native Publish
 * box is replaced by a "Save draft" box, because status here changes by
 * approval and never by publishing. Every posted value is re-validated
 * server-side: nonce, edit capability, and an allow-list of scopes.
 */
final class DetailsBox {

	public const BOX_ID      = 'ab_details';
	public const SAVE_BOX    = 'ab_save';
	public const NONCE       = 'ab_details_nonce';
	public const ACTION      = 'ab_details_save';
	public const FIELD_SCOPE = 'ab_details_scope';
	public const FIELD_TO    = 'ab_details_to';
	public const ERROR_ARG   = 'ab_details_error';

	/** @var array<string, array{singular: string, plural: string}> */
	private const TYPES = [
		'ab_handoff'   => ['singular' => 'Handoff', 'plural' => 'Handoffs'],
		'ab_knowledge' => ['singular' => 'Knowledge item', 'plural' => 'Knowledge items'],
		'ab_procedure' => ['singular' => 'Procedure', 'plural' => 'Procedures'],
	];

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('add_meta_boxes', [self::class, 'addBox']);
		add_filter('post_updated_messages', [self::class, 'updatedMessages']);
		add_filter('bulk_post_updated_messages', [self::class, 'bulkMessages'], 10, 2);
		add_action('load-post.php', [self::class, 'addHelp']);
		add_action('load-post-new.php', [self::class, 'addHelp']);
		foreach (self::postTypes() as $type) {
			add_action('save_post_' . $type, [self::class, 'save'], 10, 2);
		}
		add_filter('redirect_post_location', [self::class, 'redirectWithError'], 10, 2);
		add_action('admin_notices', [self::class, 'errorNotice']);
	}

	/** @return string[] */
	public static function postTypes(): array {
		return array_keys(self::TYPES);
	}

	public static function addBox(string $postType): void {
		if (! isset(self::TYPES[$postType]) || ! function_exists('add_meta_box')) {
			return;
		}

		add_meta_box(
			self::BOX_ID,
			__('TomaTío details', 'agent-brain'),
			[self::class, 'renderBox'],
			$postType,
			'side',
			'high'
		);

		// Status changes by approval, not by publishing: swap the native
		// Publish box for a plain save box that says so.
		remove_meta_box('submitdiv', $postType, 'side');
		add_meta_box(
			self::SAVE_BOX,
			__('Save', 'agent-brain'),
			[self::class, 'renderSaveBox'],
			$postType,
			'side',
			'core'
		);
	}

	public static function renderSaveBox(\WP_Post $post): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- saveBoxHtml() escapes every value it emits.
		echo self::saveBoxHtml($post);
	}

	public static function saveBoxHtml(\WP_Post $post): string {
		$isDraft = in_array($post->post_status, ['auto-draft', 'draft', ''], true);
		$status  = $isDraft ? 'draft' : $post->post_status;
		$label   = $isDraft ? __('Save draft', 'agent-brain') : __('Save changes', 'agent-brain');

		// Carry the status through unchanged: saving never publishes.
		$html  = '<div class="ab-save-box">';
		$html .= '<input type="hidden" name="post_status" value="' . esc_attr($status) . '" />';
		$html .= '<input type="hidden" name="hidden_post_status" value="' . esc_attr($status) . '" />';
		$html .= '<p class="description">' . esc_html__('Saving keeps the current status. An item becomes available to agents when a curator approves it in the Review Queue; it is not published.', 'agent-brain') . '</p>';
		$html .= '<p><button type="submit" name="save" value="Save" class="button button-primary">' . esc_html($label) . '</button></p>';

		$trash = ($post->ID > 0 && function_exists('get_delete_post_link') && current_user_can('delete_post', $post->ID)) ? get_delete_post_link($post->ID) : '';
		if (is_string($trash) && $trash !== '') {
			$html .= '<p><a class="submitdelete deletion" href="' . esc_url($trash) . '">' . esc_html__('Move to Trash', 'agent-brain') . '</a></p>';
		}

		return $html . '</div>';
	}

	public static function renderBox(\WP_Post $post): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- boxHtml() escapes every value it emits.
		echo self::boxHtml($post);
	}

	public static function boxHtml(\WP_Post $post): string {
		$isHandoff = $post->post_type === 'ab_handoff';
		$rows      = $isHandoff
			? [
				__('From', 'agent-brain')   => esc_html(ListScreens::handoffFrom($post->ID)),
				__('To', 'agent-brain')     => self::toControl($post),
				__('Status', 'agent-brain') => esc_html(ListScreens::handoffStatusLabel($post->ID)),
			]
			: [
				__('Scope', 'agent-brain')  => self::scopeControl($post),
				__('Status', 'agent-brain') => esc_html(self::postStatusLabel($post)),
			];

		$html = wp_nonce_field(self::ACTION, self::NONCE, false, false) . '<dl class="ab-details-box">';
		foreach ($rows as $label => $valueHtml) {
			$html .= '<dt><strong>' . esc_html((string) $label) . '</strong></dt><dd>' . $valueHtml . '</dd>';
		}
		$html .= '</dl>';

		$hint = $isHandoff
			? __('Agents set who a handoff is from. Choose who it is for here while it is open. A curator decides it in the Review Queue.', 'agent-brain')
			: __('The scope decides which agents can read this item. Status changes when a proposal is approved in the Review Queue, not when you save.', 'agent-brain');

		return $html . '<p class="description">' . esc_html($hint) . '</p>';
	}

	/**
	 * Scopes the user may put on an item: the scopes they can read, or every
	 * scope for people who manage TomaTío or grant scopes and hold none
	 * (#552: an item never moves out of its editor's own view).
	 *
	 * @return array<int, string> term id => name
	 */
	public static function selectableScopes(int $userId): array {
		$terms = get_terms(['taxonomy' => Scopes::TAXONOMY, 'hide_empty' => false, 'orderby' => 'name']);
		if (! is_array($terms)) {
			return [];
		}

		$readable = (new Scopes())->readScopeIds($userId);
		$all      = $readable === [] && (user_can($userId, Capabilities::CAP_MANAGE) || user_can($userId, Capabilities::CAP_GRANT_SCOPES));

		$out = [];
		foreach ($terms as $term) {
			if ($term instanceof \WP_Term && ($all || in_array((int) $term->term_id, $readable, true))) {
				$out[(int) $term->term_id] = $term->name;
			}
		}

		return $out;
	}

	/** @return int[] */
	private static function currentScopeIds(int $postId): array {
		$ids = function_exists('wp_get_post_terms') ? wp_get_post_terms($postId, Scopes::TAXONOMY, ['fields' => 'ids']) : [];

		return is_array($ids) ? array_map('intval', $ids) : [];
	}

	/** "Not set" is only valid while the item is not yet approved (private) or published. */
	public static function scopeMayBeUnset(string $status): bool {
		return ! in_array($status, ['private', 'publish'], true);
	}

	private static function scopeControl(\WP_Post $post): string {
		$current = self::currentScopeIds($post->ID);
		$options = self::selectableScopes(get_current_user_id());

		// Never hide the scope an item already has, even if this user could not pick it.
		foreach ($current as $id) {
			if (! isset($options[$id])) {
				$term = get_term($id, Scopes::TAXONOMY);
				if ($term instanceof \WP_Term) {
					$options[$id] = $term->name;
				}
			}
		}

		if ($post->ID > 0 && ! current_user_can('edit_post', $post->ID)) {
			return esc_html($current !== [] ? implode(', ', array_map(static fn (int $i): string => $options[$i] ?? '', $current)) : __('Not set', 'agent-brain'));
		}

		$html = '<label class="screen-reader-text" for="' . esc_attr(self::FIELD_SCOPE) . '">' . esc_html__('Scope', 'agent-brain') . '</label>'
			. '<select name="' . esc_attr(self::FIELD_SCOPE) . '" id="' . esc_attr(self::FIELD_SCOPE) . '">';
		if ($current === [] || self::scopeMayBeUnset($post->post_status)) {
			$html .= '<option value="0"' . selected($current === [], true, false) . '>' . esc_html__('Not set', 'agent-brain') . '</option>';
		}
		foreach ($options as $id => $name) {
			$html .= '<option value="' . esc_attr((string) $id) . '"' . selected(in_array($id, $current, true), true, false) . '>' . esc_html($name) . '</option>';
		}

		return $html . '</select>';
	}

	private static function toControl(\WP_Post $post): string {
		$isOpen = $post->ID <= 0 || HandoffQueue::status($post->ID) === null;
		if (! $isOpen || ($post->ID > 0 && ! current_user_can('edit_post', $post->ID))) {
			return esc_html(ListScreens::handoffTo($post->ID));
		}

		$current = (int) get_post_meta($post->ID, HandoffQueue::META_TO, true);
		$agents  = self::agentOptions();
		if ($current > 0 && ! isset($agents[$current])) {
			$agents[$current] = ListScreens::handoffTo($post->ID);
		}

		$html = '<label class="screen-reader-text" for="' . esc_attr(self::FIELD_TO) . '">' . esc_html__('To', 'agent-brain') . '</label>'
			. '<select name="' . esc_attr(self::FIELD_TO) . '" id="' . esc_attr(self::FIELD_TO) . '">'
			. '<option value="0"' . selected($current, 0, false) . '>' . esc_html__('Any curator', 'agent-brain') . '</option>';
		foreach ($agents as $id => $name) {
			$html .= '<option value="' . esc_attr((string) $id) . '"' . selected($current, $id, false) . '>' . esc_html($name) . '</option>';
		}

		return $html . '</select>';
	}

	/** @return array<int, string> agent user id => display name */
	private static function agentOptions(): array {
		$out = [];
		foreach (get_users(['role' => Capabilities::ROLE_AGENT, 'orderby' => 'display_name']) as $user) {
			$out[(int) $user->ID] = $user->display_name . ' (' . $user->user_login . ')';
		}

		return $out;
	}

	/**
	 * Save the scope / receiving agent. Every value is validated here; an
	 * invalid one leaves the stored value untouched and raises a notice.
	 */
	public static function save(int $postId, \WP_Post $post): void {
		if (wp_is_post_autosave($postId) || wp_is_post_revision($postId) || ! isset(self::TYPES[$post->post_type])) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just below.
		$nonce = isset($_POST[self::NONCE]) ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE])) : '';
		if ($nonce === '' || ! wp_verify_nonce($nonce, self::ACTION) || ! current_user_can('edit_post', $postId)) {
			return;
		}

		delete_transient(self::errorKey());
		$ok = true;
		if ($post->post_type === 'ab_handoff') {
			if (isset($_POST[self::FIELD_TO])) {
				$ok = self::saveTo($postId, absint(wp_unslash((string) $_POST[self::FIELD_TO])));
			}
		} elseif (isset($_POST[self::FIELD_SCOPE])) {
			$ok = self::saveScope($postId, $post->post_status, absint(wp_unslash((string) $_POST[self::FIELD_SCOPE])));
		}
		// phpcs:enable

		if (! $ok) {
			set_transient(self::errorKey(), $post->post_type, 60);
		}
	}

	private static function saveScope(int $postId, string $status, int $scopeId): bool {
		$current = self::currentScopeIds($postId);

		if ($scopeId === 0) {
			// Unsetting is only valid for an item that is not approved yet.
			if ($current === []) {
				return true;
			}
			if (! self::scopeMayBeUnset($status)) {
				return false;
			}

			return ! is_wp_error(wp_set_object_terms($postId, [], Scopes::TAXONOMY, false));
		}
		if ($current === [$scopeId]) {
			return true;
		}
		if (! isset(self::selectableScopes(get_current_user_id())[$scopeId])) {
			return false;
		}

		return ! is_wp_error(wp_set_object_terms($postId, [$scopeId], Scopes::TAXONOMY, false));
	}

	private static function saveTo(int $postId, int $toId): bool {
		if (HandoffQueue::status($postId) !== null) {
			return false; // Only open handoffs can be re-addressed.
		}
		if ($toId === 0) {
			delete_post_meta($postId, HandoffQueue::META_TO);

			return true;
		}
		if (! isset(self::agentOptions()[$toId])) {
			return false;
		}

		update_post_meta($postId, HandoffQueue::META_TO, $toId);

		return true;
	}

	private static function errorKey(): string {
		return 'ab_details_err_' . get_current_user_id();
	}

	public static function redirectWithError(string $location, int $postId): string {
		if (get_transient(self::errorKey()) !== false && isset(self::TYPES[(string) get_post_type($postId)])) {
			return add_query_arg(self::ERROR_ARG, '1', $location);
		}

		return $location;
	}

	public static function errorNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
		if (! isset($_GET[self::ERROR_ARG])) {
			return;
		}

		delete_transient(self::errorKey());
		echo '<div class="notice notice-error is-dismissible" role="alert"><p>'
			. esc_html__('The scope or recipient was not changed: choose one of the options offered, and an approved item must keep a scope.', 'agent-brain')
			. '</p></div>';
	}

	private static function postStatusLabel(\WP_Post $post): string {
		$status = get_post_status_object($post->post_status);

		return $status instanceof \stdClass ? (string) $status->label : $post->post_status;
	}

	/**
	 * @param array<string, array<int|string, string>> $messages
	 * @return array<string, array<int|string, string>>
	 */
	public static function updatedMessages(array $messages): array {
		foreach (self::TYPES as $type => $names) {
			$name = $names['singular'];

			$messages[$type] = [
				0  => '',
				/* translators: %s: item name, e.g. "Handoff". */
				1  => sprintf(__('%s saved.', 'agent-brain'), $name),
				/* translators: %s: item name. */
				2  => sprintf(__('%s detail updated.', 'agent-brain'), $name),
				/* translators: %s: item name. */
				3  => sprintf(__('%s detail removed.', 'agent-brain'), $name),
				/* translators: %s: item name. */
				4  => sprintf(__('%s saved.', 'agent-brain'), $name),
				/* translators: %s: item name. */
				5  => sprintf(__('%s restored to an earlier revision.', 'agent-brain'), $name),
				/* translators: %s: item name. */
				6  => sprintf(__('%s saved.', 'agent-brain'), $name),
				/* translators: %s: item name. */
				7  => sprintf(__('%s saved.', 'agent-brain'), $name),
				/* translators: %s: item name. */
				8  => sprintf(__('%s submitted.', 'agent-brain'), $name),
				/* translators: %s: item name. */
				9  => sprintf(__('%s scheduled.', 'agent-brain'), $name),
				/* translators: %s: item name. */
				10 => sprintf(__('%s draft saved.', 'agent-brain'), $name),
			];
		}

		return $messages;
	}

	/**
	 * @param array<string, array<string, string>> $messages
	 * @param array<string, int> $counts
	 * @return array<string, array<string, string>>
	 */
	public static function bulkMessages(array $messages, array $counts): array {
		foreach (array_keys(self::TYPES) as $type) {
			$messages[$type] = [
				'updated'   => sprintf(_n('%s item updated.', '%s items updated.', (int) ($counts['updated'] ?? 0), 'agent-brain'), number_format_i18n((int) ($counts['updated'] ?? 0))),
				'locked'    => sprintf(_n('%s item not updated, somebody is editing it.', '%s items not updated, somebody is editing them.', (int) ($counts['locked'] ?? 0), 'agent-brain'), number_format_i18n((int) ($counts['locked'] ?? 0))),
				'deleted'   => sprintf(_n('%s item permanently deleted.', '%s items permanently deleted.', (int) ($counts['deleted'] ?? 0), 'agent-brain'), number_format_i18n((int) ($counts['deleted'] ?? 0))),
				'trashed'   => sprintf(_n('%s item moved to the Trash.', '%s items moved to the Trash.', (int) ($counts['trashed'] ?? 0), 'agent-brain'), number_format_i18n((int) ($counts['trashed'] ?? 0))),
				'untrashed' => sprintf(_n('%s item restored from the Trash.', '%s items restored from the Trash.', (int) ($counts['untrashed'] ?? 0), 'agent-brain'), number_format_i18n((int) ($counts['untrashed'] ?? 0))),
			];
		}

		return $messages;
	}

	public static function addHelp(): void {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (! $screen instanceof \WP_Screen || ! isset(self::TYPES[$screen->post_type])) {
			return;
		}

		$names = self::TYPES[$screen->post_type];
		$body  = $screen->post_type === 'ab_handoff'
			? __('A handoff asks a curator to share memory with another agent. Agents create handoffs; add one here only to leave a note for the curators. The TomaTío details box shows who it is from and for, and whether it is open, approved or dismissed. Decide open handoffs in the Review Queue.', 'agent-brain')
			: __('Approved items agents can read. Agents propose items for the Review Queue; adding one here writes it directly. Choose the scope in the TomaTío details box; it decides which agents can read the item. Saving does not publish: an item becomes available when a curator approves it.', 'agent-brain');

		$screen->add_help_tab([
			'id'      => 'agent-brain-details',
			/* translators: %s: item name, e.g. "Handoff". */
			'title'   => sprintf(__('About this %s', 'agent-brain'), strtolower($names['singular'])),
			'content' => '<p>' . esc_html($body) . '</p>',
		]);
	}
}
