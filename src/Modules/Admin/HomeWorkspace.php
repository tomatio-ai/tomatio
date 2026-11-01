<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Admin;

use AgentBrain\Modules\Artifacts\ArtifactIndex;
use AgentBrain\Modules\Artifacts\Cpts;
use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Sharing\MembersAddress;
use AgentBrain\Modules\Sharing\ShareLinks;

/**
 * The home page of a dedicated brain for someone who is signed in: the
 * team's space, like a Notion or Confluence space home. A greeting, quick
 * links into the brain, the person's own TíoDay pinned on top, and every
 * artifact they may read, grouped by scope, newest first. Visitors who are
 * not signed in still get HomePage's public welcome; nothing here is
 * rendered for them.
 *
 * Each artifact links to its members address (/a/{slug}). An artifact can
 * name a better link in post meta `ab_home_link` (https only), for example
 * the private share link of a page that keeps data, which the members
 * address cannot serve yet (#610). `ab_home_owner` (a user id) pins an
 * artifact for that person as theirs; TíoDay uses it.
 *
 * #645: an artifact with a live public share link is marked Public (a
 * globe) and gets a "Copy public link" button with that link's full URL
 * (the newest one, ShareLinks::publicUrlsFor); its title still opens the
 * members address. Artifacts without one render as before.
 *
 * #680: every signed-in person, not only those who manage the brain, gets
 * the brain's MCP address (and the role-switching address when that is on)
 * with a copy button, to connect a new client without hunting for it. An
 * address only, never a token.
 */
final class HomeWorkspace {

	public const META_LINK  = 'ab_home_link';
	public const META_OWNER = 'ab_home_owner';

	/**
	 * @return array{stats:array<int, array{label:string, count:int, url:string}>, user:string, greeting:string, review:int, overview_url:string, review_url:string, connect_url:string, mcp_url:string, roles_url:string, logout_url:string, pinned:array<int, array<string, mixed>>, groups:array<int, array{scope:string, items:array<int, array<string, mixed>>}>}
	 */
	public static function view(int $userId): array {
		$user    = get_userdata($userId);
		$items   = [];
		$pinned  = [];
		$scopes  = [];
		$posts   = ArtifactIndex::readable($userId);
		$public  = ShareLinks::publicUrlsFor(array_map(static fn (\WP_Post $post): int => (int) $post->ID, $posts));

		foreach ($posts as $post) {
			$id   = (int) $post->ID;
			$item = self::item($post, $public[$id] ?? '');
			if ((int) get_post_meta($id, self::META_OWNER, true) === $userId) {
				$pinned[] = $item;
				continue;
			}
			$items[] = $item;
		}

		foreach ($items as $item) {
			$scopes[$item['scope']][] = $item;
		}

		$groups = [];
		foreach ($scopes as $scope => $list) {
			$groups[] = ['scope' => (string) $scope, 'items' => $list];
		}

		$hour     = (int) current_time('G');
		$greeting = $hour < 12 ? __('Good morning', 'agent-brain') : ($hour < 18 ? __('Good afternoon', 'agent-brain') : __('Good evening', 'agent-brain'));
		$badges   = Menu::badgeCounts();

		$manage = user_can($userId, \AgentBrain\Modules\Identity\Capabilities::CAP_MANAGE);
		$agents = count(get_users(['role' => \AgentBrain\Modules\Identity\Capabilities::ROLE_AGENT, 'fields' => 'ID']));
		$stats  = [
			['label' => __('Memories', 'agent-brain'), 'count' => OverviewData::memoryCountFor($userId), 'url' => $manage ? admin_url('admin.php?page=' . \AgentBrain\Modules\MemoriesAdmin\Page::SLUG) : ''],
			['label' => __('Knowledge', 'agent-brain'), 'count' => self::approvedCount('ab_knowledge', $userId, $manage), 'url' => $manage ? admin_url('edit.php?post_type=ab_knowledge') : ''],
			['label' => __('Procedures', 'agent-brain'), 'count' => self::approvedCount('ab_procedure', $userId, $manage), 'url' => $manage ? admin_url('edit.php?post_type=ab_procedure') : ''],
			['label' => __('Agents', 'agent-brain'), 'count' => $agents, 'url' => $manage ? admin_url('admin.php?page=' . AgentsPage::SLUG) : ''],
		];

		return [
			'stats'        => $stats,
			'user'         => $user instanceof \WP_User ? (trim((string) $user->first_name) !== '' ? trim((string) $user->first_name) : (string) $user->display_name) : '',
			'greeting'     => $greeting,
			'review'       => $badges['review'],
			'overview_url' => admin_url('admin.php?page=' . Menu::PARENT),
			'review_url'   => admin_url('admin.php?page=' . \AgentBrain\Modules\Review\ReviewQueuePage::SLUG),
			'connect_url'  => user_can($userId, ConnectPage::CAPABILITY) ? admin_url('admin.php?page=' . ConnectPage::SLUG) : '',
			'mcp_url'      => ConnectPage::mcpUrl(),
			'roles_url'    => \AgentBrain\Modules\OAuth\RoleSwitching::enabled() ? \AgentBrain\Modules\OAuth\RoleSwitching::rolesUrl() : '',
			'logout_url'   => wp_logout_url(home_url('/')),
			'pinned'       => $pinned,
			'groups'       => $groups,
		];
	}

	/**
	 * Approved knowledge or procedures (status private) the person may read:
	 * every one for someone who manages the brain, otherwise those in their
	 * read scopes. A count only, never content.
	 */
	private static function approvedCount(string $postType, int $userId, bool $manage): int {
		if ($manage) {
			$counts = wp_count_posts($postType);

			return isset($counts->private) ? (int) $counts->private : 0;
		}

		$scopes = array_values(array_filter(array_map('intval', (new Scopes())->readScopeIds($userId))));
		if ($scopes === []) {
			return 0;
		}

		$query = new \WP_Query([
			'post_type'      => $postType,
			'post_status'    => 'private',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => [['taxonomy' => Scopes::TAXONOMY, 'field' => 'term_id', 'terms' => $scopes]], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a count of the reader's scopes.
		]);

		return (int) $query->found_posts;
	}

	/**
	 * @return array{id:int, title:string, url:string, kind:string, lead:string, scope:string, updated:string, public_url:string}
	 */
	private static function item(\WP_Post $post, string $publicUrl = ''): array {
		$id      = (int) $post->ID;
		$isHtml  = HtmlFiles::isHtml($id);
		$updated = ArtifactIndex::updated($post);

		$link = (string) get_post_meta($id, self::META_LINK, true);
		$url  = str_starts_with($link, 'https://') ? $link : MembersAddress::url($id);

		$scopeId = (int) get_post_meta($id, Cpts::META_SCOPE_ID, true);
		$term    = $scopeId > 0 ? get_term($scopeId, Scopes::TAXONOMY) : null;
		$lead    = get_userdata((int) get_post_meta($id, Cpts::META_LEAD_AGENT, true));

		return [
			'id'      => $id,
			'title'   => html_entity_decode((string) $post->post_title, ENT_QUOTES, 'UTF-8'),
			'url'     => $url,
			'kind'    => $isHtml ? __('Page', 'agent-brain') : __('Document', 'agent-brain'),
			'lead'    => $lead instanceof \WP_User ? self::shortName((string) $lead->display_name) : '',
			'scope'   => $term instanceof \WP_Term ? html_entity_decode($term->name, ENT_QUOTES, 'UTF-8') : __('Other', 'agent-brain'),
			'updated' => $updated,
			// #645: the newest live public share link, '' when there is none.
			'public_url' => $publicUrl,
		];
	}

	/**
	 * @param array<string, mixed> $view  self::view()
	 * @param array<string, mixed> $brand HomePage::view() (name, logo, icons, accent)
	 */
	public static function render(array $view, array $brand): string {
		$name = esc_html((string) $brand['name']);
		$logo = esc_url((string) $brand['logo'], ['https', 'http', 'data']);
		$who  = (string) $view['user'];

		$html = '<!DOCTYPE html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
			/* translators: %s: site or brand name. */
			. '<title>' . esc_html(sprintf(__('%s brain', 'agent-brain'), (string) $brand['name'])) . '</title>'
			. (string) $brand['icons'] . '<style>' . self::css() . '</style></head><body><div class="wrap">';

		$html .= '<header><a class="brand" href="' . esc_url(home_url('/')) . '"><img src="' . $logo . '" alt="">' . $name . '</a>'
			. '<nav>' . ($who !== '' ? '<span class="me">' . esc_html($who) . '</span>' : '')
			. '<a href="' . esc_url((string) $view['overview_url']) . '">' . esc_html__('Open the brain', 'agent-brain') . '</a>'
			. '<a href="' . esc_url((string) $view['logout_url']) . '">' . esc_html__('Sign out', 'agent-brain') . '</a></nav></header>';

		/* translators: 1: greeting such as "Good morning", 2: the person's name. */
		$hello = $who !== '' ? sprintf(__('%1$s, %2$s', 'agent-brain'), (string) $view['greeting'], $who) : (string) $view['greeting'];
		$html .= '<h1>' . esc_html($hello) . '</h1>'
			/* translators: %s: site or brand name. */
			. '<p class="lede">' . esc_html(sprintf(__('%s at a glance: what the brain remembers and knows, who works in it, and the pages and documents it shares.', 'agent-brain'), (string) $brand['name'])) . '</p>';

		$review = (int) $view['review'];
		$html  .= '<div class="quick">'
			. self::tile((string) $view['overview_url'], __('Open the brain', 'agent-brain'), __('Memory, knowledge and agents', 'agent-brain'))
			/* translators: %d: number of proposals waiting. */
			. self::tile((string) $view['review_url'], __('Review Queue', 'agent-brain'), $review > 0 ? sprintf(_n('%d waiting for you', '%d waiting for you', $review, 'agent-brain'), $review) : __('Nothing waiting', 'agent-brain'), $review > 0)
			. ((string) $view['connect_url'] !== '' ? self::tile((string) $view['connect_url'], __('Connect an AI', 'agent-brain'), __('claude.ai, Claude Code and other clients', 'agent-brain')) : '')
			. '</div>';

		$html .= self::connection($view);

		$html .= '<div class="stats">';
		foreach ($view['stats'] as $stat) {
			$cell   = '<span class="k">' . esc_html((string) $stat['label']) . '</span><span class="v">' . esc_html(number_format_i18n((int) $stat['count'])) . '</span>';
			$html  .= (string) $stat['url'] !== ''
				? '<a class="stat" href="' . esc_url((string) $stat['url']) . '">' . $cell . '</a>'
				: '<div class="stat">' . $cell . '</div>';
		}
		$html .= '</div>';

		if ($view['pinned'] !== []) {
			$html .= '<h2>' . esc_html__('Yours', 'agent-brain') . '</h2><ul class="list">';
			foreach ($view['pinned'] as $item) {
				$html .= self::row($item, true);
			}
			$html .= '</ul>';
		}

		if ($view['groups'] === [] && $view['pinned'] === []) {
			$html .= '<p class="empty">' . esc_html__('Nothing to show yet. Pages and documents your agents write appear here.', 'agent-brain') . '</p>';
		}
		if ($view['groups'] !== []) {
			$html .= '<p class="label">' . esc_html__('Pages and documents, by scope, newest first', 'agent-brain') . '</p>';
		}
		foreach ($view['groups'] as $group) {
			$html .= '<h2>' . esc_html((string) $group['scope']) . '<span class="count">' . count($group['items']) . '</span></h2><ul class="list">';
			foreach ($group['items'] as $item) {
				$html .= self::row($item, false);
			}
			$html .= '</ul>';
		}

		$html .= '<footer>'
			/* translators: %s: site or brand name. */
			. esc_html(sprintf(__('%s · a private TomaTío. Only people signed in to this brain see this page.', 'agent-brain'), (string) $brand['name']))
			. '</footer></div>' . self::copyScript() . '</body></html>';

		return $html;
	}

	/**
	 * #680: the person's own connection, the MCP address with a copy button,
	 * and the role-switching address when role switching is on.
	 *
	 * @param array<string, mixed> $view
	 */
	private static function connection(array $view): string {
		$status = __('Connection address copied', 'agent-brain');
		$html   = '<section class="mcp" aria-labelledby="ab-mcp"><p class="mcp-h" id="ab-mcp">' . esc_html__('Connect your AI', 'agent-brain') . '</p>'
			. '<p class="mcp-p">' . esc_html__('Add this address as a custom connector in claude.ai, Claude Desktop or any MCP client, then sign in when it asks.', 'agent-brain') . '</p>'
			. self::address((string) $view['mcp_url'], $status);
		if ((string) ($view['roles_url'] ?? '') !== '') {
			$html .= '<p class="mcp-p">' . esc_html__('Or let your AI switch between agents per task (connects as you):', 'agent-brain') . '</p>'
				. self::address((string) $view['roles_url'], $status);
		}

		return $html . '</section>';
	}

	private static function address(string $url, string $status): string {
		return '<div class="addr"><code>' . esc_html($url) . '</code>'
			. '<button type="button" class="copy mcp" data-ab-copy="' . esc_url($url, ['https', 'http']) . '" data-ab-status="' . esc_attr($status) . '">'
			. self::glyph('link') . '<span class="cl">' . esc_html__('Copy', 'agent-brain') . '</span></button></div>';
	}

	private static function tile(string $url, string $title, string $sub, bool $alert = false): string {
		return '<a class="tile' . ($alert ? ' alert' : '') . '" href="' . esc_url($url) . '"><b>' . esc_html($title) . '</b><span>' . esc_html($sub) . '</span></a>';
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function row(array $item, bool $pinned): string {
		$meta = array_filter([self::ago((string) $item['updated']), (string) $item['kind'], (string) $item['lead']]);
		if ($pinned) {
			array_unshift($meta, (string) $item['scope']);
		}

		$public = (string) ($item['public_url'] ?? '');
		$marker = $public !== ''
			? '<span class="pub">' . self::glyph('globe') . esc_html__('Public', 'agent-brain') . '</span>'
			: '';
		$link   = '<a href="' . esc_url((string) $item['url'], ['https', 'http']) . '">'
			. self::icon($item['kind'] === __('Page', 'agent-brain'))
			. '<span class="t">' . esc_html((string) $item['title']) . '</span>'
			. $marker
			. '<span class="m">' . esc_html(implode(' · ', $meta)) . '</span></a>';

		if ($public === '') {
			return '<li>' . $link . '</li>';
		}

		return '<li class="has-pub">' . $link
			. '<button type="button" class="copy" data-ab-copy="' . esc_url($public, ['https', 'http']) . '">'
			. self::glyph('link')
			. '<span class="cl">' . esc_html__('Copy public link', 'agent-brain') . '</span>'
			/* translators: %s: page or document title, read after "Copy public link" by screen readers. */
			. '<span class="sr">' . esc_html(sprintf(__(': %s', 'agent-brain'), (string) $item['title'])) . '</span>'
			. '</button></li>';
	}

	/**
	 * #645: the copy buttons. The Clipboard API where the page is a secure
	 * context, a hidden textarea otherwise; the button says "Copied" for a
	 * moment and the status line tells screen readers (the button's own
	 * data-ab-status, or "Public link copied").
	 */
	private static function copyScript(): string {
		$copied = wp_json_encode(__('Copied', 'agent-brain'));
		$status = wp_json_encode(__('Public link copied', 'agent-brain'));

		return '<p class="sr" id="ab-copy-status" role="status" aria-live="polite"></p><script>(function(){'
			. 'var s=document.getElementById("ab-copy-status");'
			. 'document.querySelectorAll("button[data-ab-copy]").forEach(function(b){b.addEventListener("click",function(){'
			. 'var u=b.getAttribute("data-ab-copy"),l=b.querySelector(".cl"),t0=l.textContent,m=b.getAttribute("data-ab-status")||' . $status . ';'
			. 'function done(){b.classList.add("done");l.textContent=' . $copied . ';s.textContent=m;setTimeout(function(){b.classList.remove("done");l.textContent=t0;s.textContent="";},1600);}'
			. 'function fallback(){var t=document.createElement("textarea");t.value=u;t.setAttribute("readonly","");t.style.position="fixed";t.style.opacity="0";document.body.appendChild(t);t.select();var ok=false;try{ok=document.execCommand("copy");}catch(e){}document.body.removeChild(t);b.focus();if(ok){done();}}'
			. 'if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(u).then(done,fallback);}else{fallback();}'
			. '});});})();</script>';
	}

	/**
	 * Small inline icons for the public marker (a globe) and the copy
	 * button (a link), decorative: the text next to them names them.
	 */
	private static function glyph(string $name): string {
		$paths = $name === 'globe'
			? '<circle cx="10" cy="10" r="7.25"/><path d="M2.75 10h14.5"/><path d="M10 2.75c1.9 2 2.9 4.45 2.9 7.25s-1 5.25-2.9 7.25c-1.9-2-2.9-4.45-2.9-7.25s1-5.25 2.9-7.25z"/>'
			: '<path d="M8.6 11.4a3.1 3.1 0 0 0 4.4 0l2.5-2.5a3.1 3.1 0 0 0-4.4-4.4l-1.1 1.1"/><path d="M11.4 8.6a3.1 3.1 0 0 0-4.4 0l-2.5 2.5a3.1 3.1 0 0 0 4.4 4.4l1.1-1.1"/>';

		return '<svg class="gl" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
	}

	/**
	 * A page (an HTML artifact, an app in a window) or a document (a
	 * written artifact), drawn so neither reads as a checkbox.
	 */
	private static function icon(bool $page): string {
		$paths = $page
			? '<rect x="2.75" y="3.75" width="14.5" height="12.5" rx="2"/><path d="M2.75 7.5h14.5"/><circle cx="5.25" cy="5.6" r=".55" fill="currentColor" stroke="none"/><circle cx="7.25" cy="5.6" r=".55" fill="currentColor" stroke="none"/>'
			: '<path d="M5.25 2.75h6.25l3.25 3.25v11.25h-9.5z"/><path d="M11.5 2.75V6h3.25"/><path d="M7.5 10h5M7.5 12.75h5M7.5 15.25h3"/>';

		return '<svg class="ico ' . ($page ? 'page' : 'doc') . '" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
	}

	/** An agent's FQDN shortened to its name (dennis-ai-6aae48f5.agnt.example.com is "dennis-ai"); people keep theirs. */
	private static function shortName(string $name): string {
		$parsed = \AgentBrain\Modules\AgentId\Fqdn::parse($name);

		return $parsed !== null ? $parsed['name'] : $name;
	}

	private static function ago(string $mysqlGmt): string {
		$at = strtotime($mysqlGmt . ' UTC');
		if ($at === false) {
			return '';
		}

		/* translators: %s: a human time difference such as "3 hours". */
		return sprintf(__('%s ago', 'agent-brain'), human_time_diff($at, time()));
	}

	private static function css(): string {
		$light = '--bg:#f6f7f9;--surface:#fff;--fg:#16181d;--muted:#5d6370;--line:#dde1e8;--accent:#16a34a;--accent-ink:#15803d;--accent-soft:#dcfce7;--event:#b4364a;--event-soft:#fbe7ea';
		$dark  = '--bg:#111318;--surface:#191c23;--fg:#e8eaf0;--muted:#9aa1ae;--line:#2c313b;--accent:#4ade80;--accent-ink:#4ade80;--accent-soft:#12301d;--event:#f08a9a;--event-soft:#3a1c22';

		return ':root{' . $light . '}@media (prefers-color-scheme:dark){:root{' . $dark . ';color-scheme:dark}}'
			. '*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--fg)}'
			. 'body{font:15px/1.55 system-ui,-apple-system,"Segoe UI","Helvetica Neue",Arial,sans-serif;-webkit-font-smoothing:antialiased}'
			. '.wrap{max-width:900px;margin:0 auto;padding:28px max(16px,env(safe-area-inset-left,0px)) 64px}'
			. 'header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:36px}'
			. '.brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:17px;color:var(--fg);text-decoration:none}.brand img{width:32px;height:32px;border-radius:7px}'
			. 'nav{display:flex;align-items:center;gap:16px;font-size:14px}nav a{color:var(--muted);text-decoration:none}nav a:hover{color:var(--fg)}'
			. '.me{font:500 12px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--muted)}'
			. 'h1{font-weight:700;font-size:clamp(28px,5vw,40px);line-height:1.1;letter-spacing:-.01em;margin:0 0 10px}'
			. '.lede{color:var(--muted);margin:0 0 24px;max-width:64ch}'
			. '.quick{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;background:var(--line);border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:8px}'
			. '.tile{background:var(--surface);padding:14px 16px;text-decoration:none;color:var(--fg);display:flex;flex-direction:column;gap:2px}'
			. '.tile b{font-weight:600}.tile span{font-size:13px;color:var(--muted)}.tile:hover b{color:var(--accent-ink)}'
			. '.tile.alert span{color:var(--event)}'
			. '.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1px;background:var(--line);border:1px solid var(--line);border-radius:10px;overflow:hidden;margin:12px 0 8px}'
			. '.stat{background:var(--surface);padding:12px 16px;display:flex;flex-direction:column;color:var(--fg);text-decoration:none}'
			. '.stat .k{font-size:11.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}'
			. '.stat .v{font-weight:600;font-size:22px;font-variant-numeric:tabular-nums}'
			. 'a.stat:hover .v{color:var(--accent-ink)}'
			. '.label{font-size:11.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin:34px 0 -20px}'
			. 'h2{display:flex;align-items:baseline;gap:10px;font-size:21px;font-weight:600;margin:34px 0 6px;padding-top:14px;border-top:2px solid var(--fg)}'
			. 'h2 .count{margin-left:auto;font:500 12px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--muted)}'
			. 'ul.list{list-style:none;margin:0;padding:0}'
			. 'ul.list a{display:flex;align-items:center;gap:12px;min-height:42px;padding:6px 10px;margin:0 -10px;border-radius:8px;color:var(--fg);text-decoration:none}'
			. 'ul.list a:hover{background:var(--surface)}'
			. '.ico{flex:none;width:20px;height:20px;color:var(--muted)}.ico.page{color:var(--accent-ink)}'
			. '.t{flex:1;min-width:0;font-size:16px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
			. '.m{flex:none;font:500 11.5px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--muted);max-width:55%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
			. '.empty{color:var(--muted)}'
			. self::publicCss()
			. '.mcp{margin:12px 0 8px;padding:14px 16px;background:var(--surface);border:1px solid var(--line);border-radius:10px}'
			. '.mcp-h{margin:0;font-weight:600}.mcp-p{margin:2px 0 8px;font-size:13px;color:var(--muted)}'
			. '.addr{display:flex;align-items:center;gap:8px;margin-bottom:6px}'
			. '.addr code{flex:1;min-width:0;padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);font:500 13px ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}'
			. 'footer{margin-top:40px;font-size:13px;color:var(--muted);border-top:1px solid var(--line);padding-top:16px}'
			. '@media (max-width:640px){.quick{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.m{display:none}}';
	}

	/**
	 * #645: the public marker and the copy button, phone width included
	 * (#680: the copy button also serves the connection addresses).
	 */
	private static function publicCss(): string {
		return 'li.has-pub{display:flex;align-items:center;gap:8px}li.has-pub>a{flex:1;min-width:0;margin-right:0}'
			. '.pub{flex:none;display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;background:var(--accent-soft);color:var(--accent-ink);font-size:12px;font-weight:600}'
			. '.gl{flex:none;width:14px;height:14px}'
			. '.copy{flex:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:34px;padding:6px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--fg);font-family:inherit;font-size:13px;font-weight:500;line-height:1;cursor:pointer}'
			. '.copy:hover{border-color:var(--accent-ink);color:var(--accent-ink)}.copy:focus-visible{outline:2px solid var(--accent);outline-offset:2px}'
			. '.copy.done{border-color:var(--accent-ink);color:var(--accent-ink)}'
			. '.sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}'
			. '@media (max-width:640px){.copy{min-width:40px;min-height:40px;padding:6px 10px}.copy:not(.done) .cl{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}}';
	}
}
