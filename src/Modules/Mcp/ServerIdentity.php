<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

use AgentBrain\Modules\Admin\BrandIcon;
use AgentBrain\Modules\DataStore\TioDay;
use AgentBrain\Modules\Identity\Scopes;
use AgentBrain\Modules\Review\ReviewChat;
use WP\McpSchema\Common\Protocol\DTO\InitializeResult;

/**
 * Who this MCP server is, for clients that display it: the MCP 2025-11-25
 * `serverInfo` fields `title`, `websiteUrl` and `icons`, which the adapter
 * leaves empty. Without them a client such as claude.ai has only the server
 * name ("agent-brain-mcp") and no icon to show for the connector.
 *
 *  - title: the brand name (`tomatio/brand_name`, default the site title).
 *  - websiteUrl: the site's home page (the brain's public home page on a
 *    dedicated site).
 *  - description: what this brain is for (Settings > Onboarding), or a
 *    short default from the brand name (#778).
 *  - icons: the Site Icon at 512 and 192 px when set, else the brain's
 *    monogram from ConnectorIcon (#778; never TomaTío's mark, since the
 *    connector is the team's brain); `tomatio/mcp_icons` can replace
 *    the list.
 *
 *  ChatGPT shows none of these: its app name, description and icon are
 *  what the person types in its "Create app" form, so the Connect page
 *  hands them over to copy (ConnectPage, #778).
 *
 *  - instructions, first: what this brain is and when to use it (#580).
 *    An AI app with many connectors picks one per question from what each
 *    says about itself; with only tool names to go on, Claude searched
 *    every other connector and never called the brain. So the instructions
 *    open with the brain's purpose (Settings > Onboarding, "What this brain
 *    is for", or a default from the brand name), the scopes this connection
 *    can read, and when to call context-briefing. Hosts and the demo can
 *    replace it with `tomatio/mcp_instructions_intro` ( string $intro,
 *    int $userId ); an empty string leaves it out.
 *
 *  - instructions, next: when the person behind the connection has a
 *    TíoDay this connection may keep, a few lines on keeping it current
 *    with the tioday tools (#654, DataStore\TioDay::instructions()).
 *    Nothing when there is none.
 *
 *  - instructions, next: when the person behind the connection is a
 *    curator and suggested facts or review requests are waiting, one
 *    paragraph on reviewing them in chat (#658, Review\ReviewChat::
 *    instructions()). Nothing otherwise.
 *
 *  - instructions, then: the tool-list lifecycle contract (#250). The effective
 *    tool list is recomputed per request from the caller's live
 *    permissions, but the stateless HTTP transport has no outbound channel,
 *    so the server cannot push `notifications/tools/list_changed` and
 *    truthfully keeps `capabilities.tools.listChanged` at false. The only
 *    way a connected client learns about a mid-session change (a role or
 *    lead grant, a settings toggle such as `ab_agent_create_enabled`, a
 *    deploy that changes the tool surface) is a reconnect — a fresh
 *    `initialize`/`tools/list` — so the initialize instructions say exactly
 *    that, and promise that a re-listed tool is always callable.
 */
final class ServerIdentity {

	/** Settings > Onboarding: one line on what this brain is for. */
	public const OPTION_PURPOSE = 'agent_brain_purpose';
	public const PURPOSE_MAX    = 300;

	/** Scope names listed in the intro, at most. */
	private const INTRO_SCOPES = 12;

	public static function register(): void {
		if (! function_exists('add_filter')) {
			return;
		}

		add_filter('mcp_adapter_initialize_response', [self::class, 'decorate'], 10, 2);
	}

	/**
	 * @param mixed $result
	 * @param mixed $server
	 * @return mixed
	 */
	public static function decorate($result, $server) {
		if (! $result instanceof InitializeResult || ! is_object($server) || ! method_exists($server, 'get_server_id') || $server->get_server_id() !== AdapterBridge::SERVER_ID) {
			return $result;
		}

		$data = $result->toArray();
		$info = is_array($data['serverInfo'] ?? null) ? $data['serverInfo'] : [];

		$info['title']       = self::brandName();
		$info['description'] = self::description();
		$info['websiteUrl']  = home_url('/');
		$info['icons']       = self::icons();

		$data['serverInfo'] = $info;

		$base = is_string($data['instructions'] ?? null) ? $data['instructions'] : '';
		if (trim($base) !== '') {
			$base .= ' ';
		}
		$intro                = self::intro(get_current_user_id());
		$logging              = self::logging(get_current_user_id());
		$intro                = $intro !== '' && $logging !== '' ? $intro . "\n\n" . $logging : $intro . $logging;
		$tioday               = TioDay::instructions();
		$review               = ReviewChat::instructions();
		$data['instructions'] = ($intro !== '' ? $intro . "\n\n" : '') . ($tioday !== '' ? $tioday . "\n\n" : '') . ($review !== '' ? $review . "\n\n" : '') . $base . self::TOOL_LIST_INSTRUCTIONS;

		return InitializeResult::fromArray($data);
	}

	public static function brandName(): string {
		return BrandIcon::name();
	}

	/**
	 * One line on what this brain is, for serverInfo and the Connect page:
	 * what the team wrote it is for, or a default from the brand name.
	 */
	public static function description(): string {
		$purpose = self::purpose();

		/* translators: %s: the brain's name. */
		return $purpose !== '' ? $purpose : sprintf(__('%s: shared memory, decisions, knowledge and documents. A TomaTío brain.', 'tomatio'), self::brandName());
	}

	/** What this brain is for, as the team wrote it (one line), or ''. */
	public static function purpose(): string {
		$purpose = get_option(self::OPTION_PURPOSE, '');

		return is_string($purpose) ? trim($purpose) : '';
	}

	public static function savePurpose(string $purpose): void {
		$purpose = trim((string) preg_replace('/\s+/u', ' ', sanitize_text_field($purpose)));
		if (function_exists('mb_substr')) {
			$purpose = mb_substr($purpose, 0, self::PURPOSE_MAX);
		}

		update_option(self::OPTION_PURPOSE, $purpose, false);
	}

	/**
	 * The opening of the initialize instructions for $userId's connection:
	 * what this brain is, the scopes it can read, and when to use it (#580).
	 */
	public static function intro(int $userId): string {
		$name    = self::brandName();
		$purpose = self::purpose();
		/* translators: %s: the brain's name. */
		$text = $purpose !== '' ? $purpose : sprintf(__('%s is this team\'s TomaTío brain: its shared memory, decisions, knowledge and documents.', 'tomatio'), $name);
		/* translators: %s: the brain's name. */
		$text .= ' ' . sprintf(__('Use this connector whenever the user asks about %s, this team, its projects, people or past decisions, or about anything that may have been saved here: call context-briefing first, then memory-search.', 'tomatio'), $name);

		$scopes = self::scopeNames($userId);
		if ($scopes !== []) {
			/* translators: %s: comma-separated scope names. */
			$text .= ' ' . sprintf(__('Scopes you can read: %s.', 'tomatio'), implode(', ', $scopes));
		}

		$filtered = \AgentBrain\Core\Hooks::filter('mcp_instructions_intro', $text, $userId);

		return is_string($filtered) ? trim($filtered) : $text;
	}

	/**
	 * T199: for a connection that can write, finished work is saved without
	 * being asked, worded so a later search finds it. Empty for read-only
	 * connections.
	 */
	public static function logging(int $userId): string {
		if ($userId <= 0 || (new Scopes())->writeScopeIds($userId) === []) {
			return '';
		}

		return __('Log what lands: when a merge, release, fix or decision is done, save it with memory-write before you finish, even if nobody asked. One memory per outcome, with the issue or pull request number and who decided, and search_keys holding the words people will search for later (the feature\'s plain name, and how the change could be described, such as redesign, cleanup or rename).', 'tomatio');
	}

	/** @return array<int, string> */
	private static function scopeNames(int $userId): array {
		if ($userId <= 0) {
			return [];
		}

		$names = [];
		foreach (array_slice((new Scopes())->readScopeIds($userId), 0, self::INTRO_SCOPES) as $scopeId) {
			$term = get_term($scopeId, Scopes::TAXONOMY);
			if ($term instanceof \WP_Term) {
				$names[] = html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');
			}
		}

		return $names;
	}

	/**
	 * Appended to the initialize `instructions` (#250): the effective tool
	 * list tracks the connection's permissions, cannot be pushed over the
	 * stateless HTTP transport, and a reconnect is the refresh mechanism.
	 * Kept as one constant so tests pin the exact wording clients see.
	 */
	private const TOOL_LIST_INSTRUCTIONS = 'The advertised tool list reflects your current permissions and is recomputed on every request. It changes when a curator grants or revokes a role or lead, when a setting such as agent artifact creation is toggled, or when the brain is updated. This server has no channel to push list changes, so reconnect (or issue a fresh tools/list after reconnecting) to refresh it: every tool tools/list advertises is callable for your current permissions, and a tool you cannot use answers with an explanation instead of disappearing.';

	/** The first (largest) icon's address, to download for an app's own icon field. */
	public static function iconUrl(): string {
		$icons = self::icons();

		return isset($icons[0]) ? $icons[0]['src'] : '';
	}

	/**
	 * @return array<int, array{src:string, mimeType?:string, sizes?:string[]}>
	 */
	public static function icons(): array {
		if (has_site_icon()) {
			$icons = [];
			foreach ([512, 192] as $size) {
				$url  = (string) get_site_icon_url($size);
				$type = wp_check_filetype((string) wp_parse_url($url, PHP_URL_PATH));
				if ($url !== '') {
					$icons[] = array_filter([
						'src'      => $url,
						'mimeType' => is_string($type['type']) ? $type['type'] : null,
						'sizes'    => [$size . 'x' . $size],
					]);
				}
			}
		} else {
			$icons = ConnectorIcon::icons();
		}

		$filtered = \AgentBrain\Core\Hooks::filter('mcp_icons', $icons);

		return is_array($filtered) ? array_values($filtered) : $icons;
	}
}
