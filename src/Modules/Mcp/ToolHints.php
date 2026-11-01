<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Mcp;

/**
 * What each tool is called for people, and what it does to the brain
 * (#637). Clients such as claude.ai show the title and sort tools into
 * read-only and write/delete for their "Always allow / Needs approval"
 * settings, using the MCP hints the adapter maps from an ability's
 * `meta.annotations` (`readonly`, `destructive`, `idempotent`).
 *
 * In these titles a "page" is an HTML artifact, a "document" a section
 * artifact, and a page's "data" its page store. Ability names, grants,
 * the audit log and REST are unchanged.
 */
final class ToolHints {

	/** Only reads. */
	private const READ = 'read';
	/** Adds or changes something that can be undone or restored. */
	private const WRITE = 'write';
	/** Deletes, revokes, archives or takes something away. */
	private const DESTRUCTIVE = 'destructive';

	/**
	 * Canonical ability name => [title, kind, idempotent].
	 *
	 * @var array<string, array{0: string, 1: string, 2: bool}>
	 */
	private const HINTS = [
		AbilityNames::CONTEXT_BRIEFING             => ['Start here: what this brain knows', self::READ, true],
		AbilityNames::MEMORY_SEARCH                => ['Search the brain\'s memory', self::READ, true],
		AbilityNames::MEMORY_GET                   => ['Read a memory', self::READ, true],
		AbilityNames::MEMORY_WRITE                 => ['Save a memory', self::WRITE, false],
		AbilityNames::KNOWLEDGE_READ               => ['Read the brain\'s knowledge and procedures', self::READ, true],
		AbilityNames::HANDOFF_REQUEST_REVIEW       => ['Ask a person to review work', self::WRITE, false],
		AbilityNames::HANDOFF_READ                 => ['Read a review request', self::READ, true],
		AbilityNames::ASK                          => ['Ask the brain a question', self::READ, true],
		AbilityNames::SOURCE_REGISTER              => ['Record where something came from', self::WRITE, false],
		AbilityNames::SOURCE_GET                   => ['Read a source record', self::READ, true],
		AbilityNames::KNOWLEDGE_BLAME              => ['See who wrote each part of a knowledge entry', self::READ, true],

		AbilityNames::ACT_AS                       => ['Choose who to act as', self::WRITE, true],
		AbilityNames::AGENT_PROPOSE                => ['Propose a new agent', self::WRITE, false],
		AbilityNames::AGENT_REGISTER               => ['Register this agent', self::WRITE, false],
		AbilityNames::AGENT_WHOAMI                 => ['Who am I connected as', self::READ, true],

		AbilityNames::ARTIFACT_LIST                => ['Find pages and documents', self::READ, true],
		AbilityNames::ARTIFACT_CREATE              => ['Create a page or document', self::WRITE, false],
		AbilityNames::ARTIFACT_STATUS              => ['See where a page or document stands', self::READ, true],
		AbilityNames::ARTIFACT_PUBLISH             => ['Make a page or document public', self::WRITE, true],
		AbilityNames::ARTIFACT_UNPUBLISH           => ['Make a page or document private again', self::DESTRUCTIVE, true],
		AbilityNames::ARTIFACT_ARCHIVE             => ['Archive a page or document', self::DESTRUCTIVE, true],
		AbilityNames::ARTIFACT_MEMBERS_ADDRESS_GET => ['Get the members-only link', self::READ, true],

		AbilityNames::ARTIFACT_HTML_GET            => ['Read a page', self::READ, true],
		AbilityNames::ARTIFACT_HTML_PUT            => ['Change a page\'s files', self::WRITE, false],
		AbilityNames::ARTIFACT_HTML_APPROVE        => ['Make a page version live', self::WRITE, true],

		AbilityNames::ARTIFACT_VERSION_LIST        => ['See the versions of a page or document', self::READ, true],
		AbilityNames::ARTIFACT_VERSION_GET         => ['Read an earlier version', self::READ, true],
		AbilityNames::ARTIFACT_VERSION_COMPARE     => ['Compare two versions', self::READ, true],
		AbilityNames::ARTIFACT_VERSION_RESTORE     => ['Bring back an earlier version', self::WRITE, false],

		AbilityNames::ARTIFACT_STORE_GET           => ['Read a page\'s data', self::READ, true],
		AbilityNames::ARTIFACT_STORE_SET           => ['Add or change a record in a page\'s data', self::WRITE, false],
		AbilityNames::ARTIFACT_STORE_DELETE        => ['Delete a record from a page\'s data', self::DESTRUCTIVE, true],
		AbilityNames::ARTIFACT_STORE_CONFIG        => ['Set the rules for a page\'s data', self::WRITE, true],
		AbilityNames::ARTIFACT_STORE_HISTORY       => ['See the history of a record in a page\'s data', self::READ, true],
		AbilityNames::ARTIFACT_STORE_RESTORE       => ['Bring back an earlier version of a record', self::WRITE, false],
		AbilityNames::ARTIFACT_STORE_UNDO_LINK     => ['Undo the changes made through one share link', self::DESTRUCTIVE, true],

		AbilityNames::ARTIFACT_UPLOAD_PUT          => ['Add a file or image to a page', self::WRITE, false],
		AbilityNames::ARTIFACT_UPLOAD_LIST         => ['See a page\'s files and images', self::READ, true],
		AbilityNames::ARTIFACT_UPLOAD_DELETE       => ['Delete a file from a page', self::DESTRUCTIVE, true],

		AbilityNames::ARTIFACT_SHARE_CREATE        => ['Make a share link', self::WRITE, false],
		AbilityNames::ARTIFACT_SHARE_REVOKE        => ['Turn off a share link', self::DESTRUCTIVE, true],
		AbilityNames::ARTIFACT_SHARE_LIST          => ['See the share links of a page or document', self::READ, true],
		AbilityNames::ARTIFACT_SHARE_ANALYTICS     => ['See how often share links were opened', self::READ, true],

		AbilityNames::ARTIFACT_GET_OUTLINE         => ['See a document\'s outline', self::READ, true],
		AbilityNames::ARTIFACT_SET_OUTLINE         => ['Set a document\'s outline', self::WRITE, false],
		AbilityNames::ARTIFACT_REORDER             => ['Reorder a document\'s sections', self::WRITE, true],
		AbilityNames::ARTIFACT_COMPILE             => ['Turn a document into a draft post', self::WRITE, false],
		AbilityNames::ARTIFACT_ASSEMBLE            => ['Put a finished document together', self::WRITE, false],
		AbilityNames::SECTION_CREATE               => ['Add a section to a document', self::WRITE, false],
		AbilityNames::SECTION_ASSIGN               => ['Assign part of a document to an agent', self::WRITE, true],
		AbilityNames::SECTION_CLAIM                => ['Start editing part of a document', self::WRITE, false],
		AbilityNames::SECTION_RELEASE              => ['Stop editing part of a document', self::WRITE, false],
		AbilityNames::SECTION_GET                  => ['Read part of a document', self::READ, true],
		AbilityNames::SECTION_UPDATE               => ['Edit part of a document', self::WRITE, false],
		AbilityNames::SECTION_SUBMIT               => ['Send part of a document for review', self::WRITE, false],
		AbilityNames::SECTION_REVIEW               => ['Approve or return part of a document', self::WRITE, false],
		AbilityNames::SECTION_BLAME                => ['See who wrote each part of a document section', self::READ, true],

		AbilityNames::COMMENT_LIST                 => ['Read comments', self::READ, true],
		AbilityNames::COMMENT_ADD                  => ['Add a comment', self::WRITE, false],
		AbilityNames::COMMENT_REPLY                => ['Reply to a comment', self::WRITE, false],
		AbilityNames::COMMENT_RESOLVE              => ['Mark a comment resolved', self::WRITE, true],
		AbilityNames::COMMENT_CONVERT              => ['Turn a comment into a task or review note', self::WRITE, false],

		AbilityNames::BRAIN_APP_LINK_GET           => ['Read the link to your team\'s app', self::READ, true],
		AbilityNames::BRAIN_APP_LINK_SET           => ['Set the link to your team\'s app', self::WRITE, true],

		// #654: the person's TíoDay. Clearing an in-flight item is the
		// normal end of a piece of work and is kept in the store history,
		// so none of these asks for approval as a delete would.
		AbilityNames::TIODAY_GET                   => ['Read my TíoDay', self::READ, true],
		AbilityNames::TIODAY_TASK                  => ['Add or update a task for me', self::WRITE, true],
		AbilityNames::TIODAY_DONE                  => ['Record what got done', self::WRITE, false],
		AbilityNames::TIODAY_INFLIGHT              => ['Mark work in flight', self::WRITE, true],
		AbilityNames::TIODAY_DECIDED               => ['Record a decision', self::WRITE, false],
		AbilityNames::TIODAY_REPLY                 => ['Reply to a comment on my TíoDay', self::WRITE, false],

		// #658: review in chat. Saying "not needed" or closing a review
		// request deletes nothing (the memories are kept), so neither is
		// marked destructive; the person's yes in chat is the approval.
		AbilityNames::REVIEW_QUEUE                 => ['See what waits for review', self::READ, true],
		AbilityNames::REVIEW_DECIDE                => ['Make a suggested fact official, or not', self::WRITE, false],
		AbilityNames::HANDOFF_DISMISS              => ['Close a review request without sharing', self::WRITE, true],
	];

	/**
	 * #674: claude.ai starts a new connector with every tool on "Needs
	 * approval" and gives servers no way to change that, so Connect an AI
	 * tells people to set everything to Always allow except these, as the
	 * Nautilus connector is set: deletes, turning things off, and
	 * replacing a page or a record with other content.
	 */
	public const ASK_FIRST = [
		AbilityNames::ARTIFACT_ARCHIVE,
		AbilityNames::ARTIFACT_UNPUBLISH,
		AbilityNames::ARTIFACT_SHARE_REVOKE,
		AbilityNames::ARTIFACT_STORE_DELETE,
		AbilityNames::ARTIFACT_UPLOAD_DELETE,
		AbilityNames::ARTIFACT_HTML_PUT,
		AbilityNames::ARTIFACT_VERSION_RESTORE,
		AbilityNames::ARTIFACT_STORE_RESTORE,
	];

	/** #674: left off in Claude; a page's data rules are set when it is built. */
	public const BLOCKED = [
		AbilityNames::ARTIFACT_STORE_CONFIG,
	];

	/** The people-facing title, or the name itself when it has no entry. */
	public static function title(string $name): string {
		return self::HINTS[AbilityNames::canonicalFor($name)][0] ?? $name;
	}

	/** Whether a canonical ability name has an entry (tests pin full coverage). */
	public static function has(string $name): bool {
		return isset(self::HINTS[AbilityNames::canonicalFor($name)]);
	}

	/**
	 * The ability's registration args with the people-facing title as its
	 * label and the MCP hints in `meta.annotations`, merged over whatever
	 * the module passed. An unknown name is returned unchanged.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function apply(string $name, array $args): array {
		$hint = self::HINTS[AbilityNames::canonicalFor($name)] ?? null;
		if ($hint === null) {
			return $args;
		}

		[$title, $kind, $idempotent] = $hint;

		$meta        = isset($args['meta']) && is_array($args['meta']) ? $args['meta'] : [];
		$annotations = isset($meta['annotations']) && is_array($meta['annotations']) ? $meta['annotations'] : [];

		$meta['annotations'] = array_merge($annotations, [
			'readonly'    => $kind === self::READ,
			'destructive' => $kind === self::DESTRUCTIVE,
			'idempotent'  => $idempotent,
		]);

		$args['label'] = $title;
		$args['meta']  = $meta;

		return $args;
	}
}
