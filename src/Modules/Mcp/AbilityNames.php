<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

/**
 * The exact 17 ability names from the Wave 3 binding contract (plus the
 * Artifact fix wave's section-create addition)
 * (the design contracts "MCP surface"), `namespace/name`
 * form. Single source of truth consumed by both Abilities::register() and
 * AdapterBridge::registerServer()'s `tools` list, so the two can never
 * silently drift apart.
 */
final class AbilityNames {

	public const CONTEXT_BRIEFING        = 'agent-brain/context-briefing';
	public const MEMORY_SEARCH           = 'agent-brain/memory-search';
	public const MEMORY_GET              = 'agent-brain/memory-get';
	public const MEMORY_WRITE            = 'agent-brain/memory-write';
	public const KNOWLEDGE_READ          = 'agent-brain/knowledge-read';
	public const HANDOFF_REQUEST_REVIEW  = 'agent-brain/handoff-request-review';
	public const ARTIFACT_CREATE         = 'agent-brain/artifact-create';
	public const ARTIFACT_GET_OUTLINE    = 'agent-brain/artifact-get-outline';
	public const ARTIFACT_REORDER        = 'agent-brain/artifact-reorder';
	public const SECTION_ASSIGN          = 'agent-brain/section-assign';
	public const SECTION_CREATE          = 'agent-brain/section-create';
	public const SECTION_CLAIM           = 'agent-brain/section-claim';
	public const SECTION_RELEASE         = 'agent-brain/section-release';
	public const SECTION_GET             = 'agent-brain/section-get';
	public const SECTION_UPDATE          = 'agent-brain/section-update';
	public const SECTION_SUBMIT          = 'agent-brain/section-submit';
	public const ARTIFACT_COMPILE        = 'agent-brain/artifact-compile';

	/**
	 * Multi-agent authoring pipeline (outline -> draft -> revise -> assemble)
	 * fix wave, 2026-09-27 (the design contracts "Multi-agent
	 * authoring pipeline + handoff fixes").
	 */
	public const ARTIFACT_SET_OUTLINE    = 'agent-brain/artifact-set-outline';
	public const ARTIFACT_STATUS         = 'agent-brain/artifact-status';
	public const SECTION_REVIEW          = 'agent-brain/section-review';
	public const ARTIFACT_ASSEMBLE       = 'agent-brain/artifact-assemble';
	public const HANDOFF_READ            = 'agent-brain/handoff-read';
	public const ACT_AS                  = 'agent-brain/act-as';
	public const AGENT_PROPOSE           = 'agent-brain/agent-propose';

	/**
	 * Registration by key (docs/rfc/agent-id-fqdn.md section 6). Kept apart
	 * from ALL: these are the only tools a registration key can reach.
	 */
	public const AGENT_REGISTER = 'agent-brain/agent-register';
	public const AGENT_WHOAMI   = 'agent-brain/agent-whoami';

	/** @var array<int, string> */
	public const REGISTRATION = [
		self::AGENT_REGISTER,
		self::AGENT_WHOAMI,
	];

	/**
	 * Rename aliases (#184): during the alias window every canonical
	 * `agent-brain/<verb>` ability is ALSO registered under the folded
	 * `tomatio/<verb>` spelling. Both names are the same ability
	 * implementation (same execute/permission callbacks registered twice),
	 * so a grant on one spelling covers the other and no migration is ever
	 * needed. `canonicalFor()` is the alias→canonical map: the audit log
	 * records the canonical name no matter which spelling was called.
	 */
	public const CANONICAL_PREFIX = 'agent-brain/';
	public const ALIAS_PREFIX     = 'tomatio/';

	public static function aliasFor(string $name): ?string {
		if (str_starts_with($name, self::CANONICAL_PREFIX)) {
			return self::ALIAS_PREFIX . substr($name, strlen(self::CANONICAL_PREFIX));
		}

		return null;
	}

	public static function canonicalFor(string $name): string {
		if (str_starts_with($name, self::ALIAS_PREFIX)) {
			return self::CANONICAL_PREFIX . substr($name, strlen(self::ALIAS_PREFIX));
		}

		return $name;
	}

	/**
	 * Every canonical ability name plus its alias, canonical first — the
	 * tools the MCP server registers during the alias window, so either
	 * spelling can be called (#184, #225). tools/list shows only the
	 * `tomatio-` spelling (#637, see isListedToolName()).
	 *
	 * @return array<int, string>
	 */
	public static function allWithAliases(): array {
		$with = [];
		foreach (array_merge(self::ALL, self::REGISTRATION) as $name) {
			$with[] = $name;
			$alias  = self::aliasFor($name);
			if ($alias !== null) {
				$with[] = $alias;
			}
		}

		return $with;
	}

	/**
	 * Whether a tool name shows in tools/list (#637). Every tool used to be
	 * listed twice, as `agent-brain-<verb>` and `tomatio-<verb>`; now only
	 * the `tomatio-` spelling is listed. The `agent-brain-` spelling stays
	 * registered and callable, so a client or script that still uses it
	 * keeps working.
	 */
	public static function isListedToolName(string $toolName): bool {
		return ! str_starts_with($toolName, str_replace('/', '-', self::CANONICAL_PREFIX));
	}

	/** Artifact share links (Sharing module). */
	public const ARTIFACT_SHARE_CREATE    = 'agent-brain/artifact-share-create';
	public const ARTIFACT_SHARE_REVOKE    = 'agent-brain/artifact-share-revoke';
	public const ARTIFACT_SHARE_LIST      = 'agent-brain/artifact-share-list';
	/** Anonymous per-day view analytics of an artifact's share pages (#221, Sharing module). */
	public const ARTIFACT_SHARE_ANALYTICS = 'agent-brain/artifact-share-analytics';

	/** The artifact's permanent members address (#219, Sharing module; read-only). */
	public const ARTIFACT_MEMBERS_ADDRESS_GET = 'agent-brain/artifact-members-address-get';

	/** Publish, unpublish and archive (curators only, gated by ab_publish). */
	public const ARTIFACT_PUBLISH        = 'agent-brain/artifact-publish';
	public const ARTIFACT_UNPUBLISH      = 'agent-brain/artifact-unpublish';
	public const ARTIFACT_ARCHIVE        = 'agent-brain/artifact-archive';
	/** HTML artifacts (ART-22): a set of static files with index.html as the entry point. */
	public const ARTIFACT_HTML_PUT       = 'agent-brain/artifact-html-put';
	public const ARTIFACT_HTML_GET       = 'agent-brain/artifact-html-get';
	public const ARTIFACT_HTML_APPROVE   = 'agent-brain/artifact-html-approve';

	/** Version history (#220): one list per artifact covering sections and HTML. */
	public const ARTIFACT_VERSION_LIST     = 'agent-brain/artifact-version-list';
	public const ARTIFACT_VERSION_GET      = 'agent-brain/artifact-version-get';
	public const ARTIFACT_VERSION_COMPARE  = 'agent-brain/artifact-version-compare';
	public const ARTIFACT_VERSION_RESTORE  = 'agent-brain/artifact-version-restore';

	/** Comment threads on an artifact version (ART-28, #168). */
	public const COMMENT_LIST            = 'agent-brain/comment-list';
	public const COMMENT_ADD             = 'agent-brain/comment-add';
	public const COMMENT_REPLY           = 'agent-brain/comment-reply';
	public const COMMENT_RESOLVE         = 'agent-brain/comment-resolve';
	public const COMMENT_CONVERT         = 'agent-brain/comment-convert';

	/** Per-artifact shared data store (ART-23, DataStore module). */
	public const ARTIFACT_STORE_GET      = 'agent-brain/artifact-store-get';
	public const ARTIFACT_STORE_SET      = 'agent-brain/artifact-store-set';
	public const ARTIFACT_STORE_DELETE   = 'agent-brain/artifact-store-delete';
	public const ARTIFACT_STORE_CONFIG   = 'agent-brain/artifact-store-config';
	public const ARTIFACT_STORE_HISTORY  = 'agent-brain/artifact-store-history';
	public const ARTIFACT_STORE_RESTORE  = 'agent-brain/artifact-store-restore';
	public const ARTIFACT_STORE_UNDO_LINK = 'agent-brain/artifact-store-undo-link';

	/** Asset uploads per artifact (Uploads module, ART-27). */
	public const ARTIFACT_UPLOAD_PUT     = 'agent-brain/artifact-upload-put';
	public const ARTIFACT_UPLOAD_LIST    = 'agent-brain/artifact-upload-list';
	public const ARTIFACT_UPLOAD_DELETE  = 'agent-brain/artifact-upload-delete';

	/** Ask a question about an artifact, answered with citations (ART-26, #166). */
	public const ASK                     = 'agent-brain/ask';

	/** Outside source records (PROV-3, #172): register and read where an item came from outside the brain. */
	public const SOURCE_REGISTER         = 'agent-brain/source-register';
	public const SOURCE_GET              = 'agent-brain/source-get';

	/**
	 * PROV-5 (#174): block-level blame — per-block author (actor, via,
	 * client, version, time) carried forward until the block changes.
	 */
	public const SECTION_BLAME           = 'agent-brain/section-blame';
	public const KNOWLEDGE_BLAME         = 'agent-brain/knowledge-blame';

	/** The brain's link back to the team's own app or site (#449, Admin module; ab_manage only). */
	public const BRAIN_APP_LINK_GET      = 'agent-brain/brain-app-link-get';
	public const BRAIN_APP_LINK_SET      = 'agent-brain/brain-app-link-set';

	/** Find artifacts by title: the ones the caller may read, newest first (#627). */
	public const ARTIFACT_LIST           = 'agent-brain/artifact-list';

	/**
	 * The person's TíoDay (#654, DataStore module): read it, and keep its
	 * tasks, in-flight work, done items, decisions and comments current.
	 */
	public const TIODAY_GET              = 'agent-brain/tioday-get';
	public const TIODAY_TASK             = 'agent-brain/tioday-task';
	public const TIODAY_DONE             = 'agent-brain/tioday-done';
	public const TIODAY_INFLIGHT         = 'agent-brain/tioday-inflight';
	public const TIODAY_DECIDED          = 'agent-brain/tioday-decided';
	public const TIODAY_REPLY            = 'agent-brain/tioday-reply';

	/**
	 * Review in chat (#658, Review module; curators only): read the queue,
	 * decide a suggested fact, close a review request without sharing.
	 */
	public const REVIEW_QUEUE            = 'agent-brain/review-queue';
	public const REVIEW_DECIDE           = 'agent-brain/review-decide';
	public const HANDOFF_DISMISS         = 'agent-brain/handoff-dismiss';

	/** @var array<int, string> */
	public const ALL = [
		self::CONTEXT_BRIEFING,
		self::MEMORY_SEARCH,
		self::MEMORY_GET,
		self::MEMORY_WRITE,
		self::KNOWLEDGE_READ,
		self::HANDOFF_REQUEST_REVIEW,
		self::ARTIFACT_CREATE,
		self::ARTIFACT_GET_OUTLINE,
		self::ARTIFACT_REORDER,
		self::SECTION_ASSIGN,
		self::SECTION_CREATE,
		self::SECTION_CLAIM,
		self::SECTION_RELEASE,
		self::SECTION_GET,
		self::SECTION_UPDATE,
		self::SECTION_SUBMIT,
		self::ARTIFACT_COMPILE,
		self::ARTIFACT_SET_OUTLINE,
		self::ARTIFACT_STATUS,
		self::SECTION_REVIEW,
		self::ARTIFACT_ASSEMBLE,
		self::HANDOFF_READ,
		self::ACT_AS,
		self::AGENT_PROPOSE,
		self::ARTIFACT_SHARE_CREATE,
		self::ARTIFACT_SHARE_REVOKE,
		self::ARTIFACT_SHARE_LIST,
		self::ARTIFACT_SHARE_ANALYTICS,
		self::ARTIFACT_MEMBERS_ADDRESS_GET,
		self::ARTIFACT_PUBLISH,
		self::ARTIFACT_UNPUBLISH,
		self::ARTIFACT_ARCHIVE,
		self::ARTIFACT_HTML_PUT,
		self::ARTIFACT_HTML_GET,
		self::ARTIFACT_HTML_APPROVE,
		self::ARTIFACT_VERSION_LIST,
		self::ARTIFACT_VERSION_GET,
		self::ARTIFACT_VERSION_COMPARE,
		self::ARTIFACT_VERSION_RESTORE,
		self::COMMENT_LIST,
		self::COMMENT_ADD,
		self::COMMENT_REPLY,
		self::COMMENT_RESOLVE,
		self::COMMENT_CONVERT,
		self::ARTIFACT_STORE_GET,
		self::ARTIFACT_STORE_SET,
		self::ARTIFACT_STORE_DELETE,
		self::ARTIFACT_STORE_CONFIG,
		self::ARTIFACT_STORE_HISTORY,
		self::ARTIFACT_STORE_RESTORE,
		self::ARTIFACT_STORE_UNDO_LINK,
		self::ARTIFACT_UPLOAD_PUT,
		self::ARTIFACT_UPLOAD_LIST,
		self::ARTIFACT_UPLOAD_DELETE,
		self::ASK,
		self::SOURCE_REGISTER,
		self::SOURCE_GET,
		self::SECTION_BLAME,
		self::KNOWLEDGE_BLAME,
		self::BRAIN_APP_LINK_GET,
		self::BRAIN_APP_LINK_SET,
		self::ARTIFACT_LIST,
		self::TIODAY_GET,
		self::TIODAY_TASK,
		self::TIODAY_DONE,
		self::TIODAY_INFLIGHT,
		self::TIODAY_DECIDED,
		self::TIODAY_REPLY,
		self::REVIEW_QUEUE,
		self::REVIEW_DECIDE,
		self::HANDOFF_DISMISS,
	];

	/**
	 * Register an ability under its canonical name and, during the #184
	 * rename alias window, also under its folded `tomatio/` alias — one
	 * ability implementation under two names, so a grant on either spelling
	 * covers the other. Every module's ability registration funnels through
	 * this helper (Mcp\Abilities, Sharing\ShareAbilities,
	 * AgentRegistration\RegistrationAbilities) so no ability misses the alias.
	 * It also adds the people-facing title and the read-only/destructive
	 * hints from ToolHints (#637), so no ability misses those either.
	 *
	 * @param array<string, mixed> $args
	 */
	public static function registerOnce(string $name, array $args): void {
		$args = ToolHints::apply($name, $args);

		if (! function_exists('wp_has_ability') || ! wp_has_ability($name)) {
			wp_register_ability($name, $args);
		}

		$alias = self::aliasFor($name);
		if ($alias !== null && (! function_exists('wp_has_ability') || ! wp_has_ability($alias))) {
			wp_register_ability($alias, $args);
		}
	}

	/** Write abilities per the Wave 3 contract: wrapped in Core\WriteLimiter. */
	public const WRITE_ABILITIES = [
		self::MEMORY_WRITE,
		self::HANDOFF_REQUEST_REVIEW,
		self::ARTIFACT_CREATE,
		self::ARTIFACT_REORDER,
		self::SECTION_ASSIGN,
		self::SECTION_CREATE,
		self::SECTION_CLAIM,
		self::SECTION_RELEASE,
		self::SECTION_UPDATE,
		self::SECTION_SUBMIT,
		self::ARTIFACT_COMPILE,
		self::ARTIFACT_SET_OUTLINE,
		self::SECTION_REVIEW,
		self::ARTIFACT_ASSEMBLE,
		self::AGENT_PROPOSE,
		self::ARTIFACT_SHARE_CREATE,
		self::ARTIFACT_SHARE_REVOKE,
		self::ARTIFACT_PUBLISH,
		self::ARTIFACT_UNPUBLISH,
		self::ARTIFACT_ARCHIVE,
		self::ARTIFACT_HTML_PUT,
		self::ARTIFACT_HTML_APPROVE,
		self::ARTIFACT_VERSION_RESTORE,
		self::COMMENT_ADD,
		self::COMMENT_REPLY,
		self::COMMENT_RESOLVE,
		self::COMMENT_CONVERT,
		self::ARTIFACT_STORE_SET,
		self::ARTIFACT_STORE_DELETE,
		self::ARTIFACT_STORE_CONFIG,
		self::ARTIFACT_STORE_RESTORE,
		self::ARTIFACT_STORE_UNDO_LINK,
		self::ARTIFACT_UPLOAD_PUT,
		self::ARTIFACT_UPLOAD_DELETE,
		self::SOURCE_REGISTER,
		self::BRAIN_APP_LINK_SET,
		self::TIODAY_TASK,
		self::TIODAY_DONE,
		self::TIODAY_INFLIGHT,
		self::TIODAY_DECIDED,
		self::TIODAY_REPLY,
		self::REVIEW_DECIDE,
		self::HANDOFF_DISMISS,
	];
}
