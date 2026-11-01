<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\Review\GrantTaxonomy;
use AgentBrain\Modules\Review\InboxItems;
use AgentBrain\Modules\Sharing\EmbedPolicy;
use AgentBrain\Modules\Sharing\ShareLinks;

/**
 * Grants (#751): what is public right now instead of the taxonomy screen with
 * its one term. Share links that still open and items carrying the public
 * grant, each with who shared it and a way to take it back. The Grants menu
 * row stays the taxonomy screen, which redirects here.
 */
final class GrantsPage {

	public const SLUG = 'agent-brain-grants';

	public const ACTION_REVOKE_LINK = 'agent_brain_grants_revoke_link';

	public const ACTION_REMOVE_GRANT = 'agent_brain_grants_remove';

	private const TYPES = ['ab_knowledge', 'ab_procedure', 'ab_artifact', 'post'];

	public static function register(): void {
		if (! function_exists('add_action')) {
			return;
		}

		add_action('admin_menu', [self::class, 'addPage']);
		add_action('admin_menu', [self::class, 'hideRow'], 1000);
		add_action('load-edit-tags.php', [self::class, 'redirectTaxonomyScreen']);
		add_action('admin_post_' . self::ACTION_REVOKE_LINK, [self::class, 'handleRevokeLink']);
		add_action('admin_post_' . self::ACTION_REMOVE_GRANT, [self::class, 'handleRemoveGrant']);
	}

	public static function addPage(): void {
		$label = __('Grants', 'agent-brain');
		$hook  = add_submenu_page(Menu::PARENT, $label, $label, Capabilities::CAP_PUBLISH, self::SLUG, [self::class, 'render']);
		if (is_string($hook) && $hook !== '') {
			// Without a visible menu row WordPress cannot find the page's title.
			add_action('load-' . $hook, static function () use ($label): void {
				$GLOBALS['title'] = $label;
			});
		}
	}

	/** The row stays (WordPress only opens pages that have one) and is hidden; Grants leads to it. */
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

	public static function redirectTaxonomyScreen(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing, no state change.
		$args     = array_map('strval', array_keys((array) $_GET));
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing, no state change.
		$taxonomy = isset($_GET['taxonomy']) ? sanitize_key((string) wp_unslash($_GET['taxonomy'])) : '';

		if ($taxonomy !== GrantTaxonomy::TAXONOMY || array_diff($args, ['taxonomy', 'post_type']) !== []) {
			return;
		}

		wp_safe_redirect(self::url());
		exit;
	}

	/**
	 * @param array<string, string|int> $args
	 */
	public static function url(array $args = []): string {
		return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
	}

	public static function render(): void {
		if (! current_user_can(Capabilities::CAP_PUBLISH)) {
			wp_die(esc_html__('You do not have permission to view Grants.', 'agent-brain'), 403);
		}

		$actor = get_current_user_id();
		$links = ShareLinks::live($actor);
		$items = self::grantedItems($actor);
		$about = '<p>' . esc_html__('A public grant is the permission that lets an approved item be published outside the brain, as a share link or a public post. Only people who can publish (curators and administrators) can give it; agents never can.', 'agent-brain') . '</p>';

		$head  = Ui::head(
			__('Public grants', 'agent-brain'),
			esc_html__('Who may publish outside the brain:', 'agent-brain') . ' <b>' . esc_html__('curators and administrators', 'agent-brain') . '</b>',
			'',
			$about
		);

		echo '<div class="wrap ab-admin ab-admin-grants">';
		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		self::renderNotice();

		if (GrantTaxonomy::termId() === null) {
			$restore = '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . GrantsScreen::ACTION_RESTORE), GrantsScreen::ACTION_RESTORE)) . '">' . esc_html__('Restore the public grant', 'agent-brain') . '</a>';
			echo Ui::callout('warn', __('The public grant is missing.', 'agent-brain'), __('Nothing can be published outside the brain without it. It is created when TomaTío is activated.', 'agent-brain'), $restore); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		}

		if ($links === [] && $items === []) {
			EmptyState::render(
				__('Nothing is public yet.', 'agent-brain'),
				__('When a curator shares a fact or a document outside the brain (a share link or a public post), it shows up here with who shared it and how to take it back.', 'agent-brain')
			);
			echo '</div>';

			return;
		}

		if ($links !== []) {
			echo Ui::card(self::countTitle(__('Share links that open', 'agent-brain'), count($links)), self::linksHtml($links), 'ab-grants'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		}
		if ($items !== []) {
			echo Ui::card(self::countTitle(__('Items with the public grant', 'agent-brain'), count($items)), self::itemsHtml($items), 'ab-grants'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		}
		echo '</div>';
	}

	private static function typeLabel(string $postType): string {
		switch ($postType) {
			case 'ab_knowledge':
				return __('Knowledge', 'agent-brain');
			case 'ab_procedure':
				return __('Procedure', 'agent-brain');
			case 'ab_artifact':
				return __('Document', 'agent-brain');
			default:
				return __('Post', 'agent-brain');
		}
	}

	private static function countTitle(string $title, int $count): string {
		return $title . ' (' . number_format_i18n($count) . ')';
	}

	private static function renderNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash.
		$notice = isset($_GET['ab_notice']) ? sanitize_key(wp_unslash((string) $_GET['ab_notice'])) : '';
		$text   = [
			'revoked' => __('The link no longer opens.', 'agent-brain'),
			'removed' => __('The public grant was taken off the item.', 'agent-brain'),
			'error'   => __('That did not work. It may already be gone.', 'agent-brain'),
		];
		if (isset($text[$notice])) {
			echo Ui::callout($notice === 'error' ? 'bad' : 'ok', $text[$notice]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes what it emits.
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $links
	 */
	private static function linksHtml(array $links): string {
		$out = '<ul class="ab-grants-list">';
		foreach ($links as $link) {
			$id       = (int) $link['id'];
			$docId    = (int) $link['artifact_id'];
			$doc      = get_post($docId);
			$title    = $doc instanceof \WP_Post ? $doc->post_title : '';
			$title    = $title !== '' ? $title : __('(no title)', 'agent-brain');
			$kind     = $link['kind'] === EmbedPolicy::KIND_EMBED
				? __('Embed', 'agent-brain')
				: ($link['privacy'] === ShareLinks::PRIVACY_PUBLIC ? __('Public link', 'agent-brain') : __('Private link', 'agent-brain'));
			$tags     = Ui::tag($kind, 'info')
				. ($link['viewer_mode'] === ShareLinks::VIEWER_MEMBERS ? ' ' . Ui::tag(__('Members only', 'agent-brain')) : '')
				. (empty($link['approved']) ? ' ' . Ui::tag(__('Waiting for approval', 'agent-brain'), 'warn') : '');
			$opened   = (int) $link['open_count'];
			$meta     = sprintf(
				/* translators: 1: person, 2: how long ago, 3: expiry date */
				esc_html__('Shared by %1$s %2$s · open until %3$s', 'agent-brain'),
				esc_html(InboxItems::who((int) $link['created_by'])['name']),
				DateFormat::ago((string) $link['created_at']),
				DateFormat::html((string) $link['expires_at'])
			) . ' · ' . esc_html($opened > 0
				/* translators: %s: number of times */
				? sprintf(_n('opened %s time', 'opened %s times', $opened, 'agent-brain'), number_format_i18n($opened))
				: __('not opened yet', 'agent-brain'));
			$confirm  = Confirm::message(__('The link stops opening for everyone who has it', 'agent-brain'));

			$out .= '<li><div class="ab-grants-what"><a href="' . esc_url((string) get_edit_post_link($docId, 'raw')) . '">' . esc_html($title) . '</a> ' . $tags
				. '<span class="ab-ui-row-meta">' . $meta . '</span></div>'
				. '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . Confirm::attr($confirm) . '>'
				. '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_REVOKE_LINK) . '" />'
				. '<input type="hidden" name="link_id" value="' . esc_attr((string) $id) . '" />'
				. '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::ACTION_REVOKE_LINK . '_' . $id)) . '" />'
				. '<button type="submit" class="button button-small">' . esc_html__('Take it back', 'agent-brain') . '</button></form></li>';
		}

		return $out . '</ul>';
	}

	/**
	 * @param array<int, \WP_Post> $items
	 */
	private static function itemsHtml(array $items): string {
		$out = '<ul class="ab-grants-list">';
		foreach ($items as $post) {
			$id      = (int) $post->ID;
			$type    = self::typeLabel($post->post_type);
			$live    = $post->post_status === 'publish';
			$title   = $post->post_title !== '' ? $post->post_title : __('(no title)', 'agent-brain');
			$tags    = Ui::tag($type, 'type') . ' ' . ($live ? Ui::tag(__('Published', 'agent-brain'), 'ok') : Ui::tag(__('Not published', 'agent-brain')));
			$meta    = sprintf(
				/* translators: %s: how long ago */
				esc_html__('Changed %s', 'agent-brain'),
				DateFormat::ago($post->post_modified_gmt)
			);
			$confirm = Confirm::message(
				$live ? __('The item stays published, but it can no longer be published again until a curator gives the grant back', 'agent-brain') : __('The item can no longer be published outside the brain', 'agent-brain'),
				__('A curator can give the grant back on the item', 'agent-brain')
			);

			$out .= '<li><div class="ab-grants-what"><a href="' . esc_url((string) get_edit_post_link($id, 'raw')) . '">' . esc_html($title) . '</a> ' . $tags
				. '<span class="ab-ui-row-meta">' . $meta . '</span></div>'
				. '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . Confirm::attr($confirm) . '>'
				. '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_REMOVE_GRANT) . '" />'
				. '<input type="hidden" name="post_id" value="' . esc_attr((string) $id) . '" />'
				. '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(self::ACTION_REMOVE_GRANT . '_' . $id)) . '" />'
				. '<button type="submit" class="button button-small">' . esc_html__('Remove the grant', 'agent-brain') . '</button></form></li>';
		}

		return $out . '</ul>';
	}

	/**
	 * Items carrying the public grant that $actor can see, newest change first.
	 *
	 * @return array<int, \WP_Post>
	 */
	public static function grantedItems(int $actor): array {
		$termId = GrantTaxonomy::termId();
		if ($termId === null) {
			return [];
		}

		$posts = get_posts([
			'post_type'        => self::TYPES,
			'post_status'      => 'any',
			'posts_per_page'   => 200,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one term, admin-only screen.
			'tax_query'        => [['taxonomy' => GrantTaxonomy::TAXONOMY, 'field' => 'term_id', 'terms' => [$termId]]],
		]);
		$visible = ScopeVisibility::filterPostIds($actor, array_map(static fn(\WP_Post $p): int => (int) $p->ID, $posts));

		return array_values(array_filter($posts, static fn(\WP_Post $p): bool => in_array((int) $p->ID, $visible, true)));
	}

	public static function handleRevokeLink(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in processRevokeLink().
		wp_safe_redirect(self::processRevokeLink(wp_unslash($_POST)));
		exit;
	}

	public static function handleRemoveGrant(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in processRemoveGrant().
		wp_safe_redirect(self::processRemoveGrant(wp_unslash($_POST)));
		exit;
	}

	/**
	 * @param array<string, mixed> $post
	 */
	public static function processRevokeLink(array $post): string {
		$id = isset($post['link_id']) ? absint($post['link_id']) : 0;
		self::authorise($post, self::ACTION_REVOKE_LINK . '_' . $id);

		$result = ShareLinks::revoke($id, get_current_user_id());

		return self::url(['ab_notice' => $result === true ? 'revoked' : 'error']);
	}

	/**
	 * @param array<string, mixed> $post
	 */
	public static function processRemoveGrant(array $post): string {
		$id = isset($post['post_id']) ? absint($post['post_id']) : 0;
		self::authorise($post, self::ACTION_REMOVE_GRANT . '_' . $id);

		$termId = GrantTaxonomy::termId();
		if ($termId === null || ! GrantTaxonomy::hasPublicGrant($id) || ! current_user_can('edit_post', $id)) {
			return self::url(['ab_notice' => 'error']);
		}

		$result = wp_remove_object_terms($id, [$termId], GrantTaxonomy::TAXONOMY);
		do_action('agent_brain/audit', [
			'actor_id'    => get_current_user_id(),
			'event'       => 'grant.removed',
			'status'      => $result === true ? 'ok' : 'error',
			'object_type' => 'post',
			'object_id'   => (string) $id,
		]);

		return self::url(['ab_notice' => $result === true ? 'removed' : 'error']);
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function authorise(array $post, string $nonceAction): void {
		if (! current_user_can(Capabilities::CAP_PUBLISH)) {
			wp_die(esc_html__('You do not have permission to change grants.', 'agent-brain'), 403);
		}

		$nonce = isset($post['_wpnonce']) && is_string($post['_wpnonce']) ? $post['_wpnonce'] : '';
		if (wp_verify_nonce($nonce, $nonceAction) === false) {
			wp_die(esc_html__('The link you followed has expired. Please go back and try again.', 'agent-brain'), 403);
		}
	}
}
