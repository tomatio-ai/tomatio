<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Sharing;

use AgentBrain\Modules\Artifacts\HtmlFiles;
use AgentBrain\Modules\Mcp\AbilityNames;
use AgentBrain\Modules\Mcp\Module as McpModule;
use WP_Error;

/**
 * MCP abilities for HTML artifacts (ART-22, #138): artifact-html-put,
 * artifact-html-get and artifact-html-approve. Authority is the artifact's
 * lead, a curator or an ab_manage person, as for share links.
 */
final class HtmlAbilities {

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_HTML_PUT,
			[
				'label'               => __( 'Upload files to an HTML artifact', 'agent-brain' ),
				'description'         => __( 'Upload or replace the files of an HTML artifact (a plain static page like GitHub Pages: index.html plus optional CSS, JS and image files; JavaScript runs in an isolated sandbox on the preview host). Each call makes a new version from the latest one: the given files are added or replaced, the paths in remove are dropped and the edits are applied; earlier versions are kept. To change part of a page, send edits, never the whole file again: each edit names a text file (path), the exact text to find (read it first with artifact-html-get and its path) and the text to put in its place (at=replace, the default) or before or after it (at=before or after). The find text must occur exactly once in the file, or set all=true to change every occurrence. A whole-file upload that would shrink an existing file of 4 KB or more by over 90% is refused unless force=true. Paths are relative (for example css/site.css), content is UTF-8 text, or base64 with encoding=base64 (required for images). Allowed types: html, css, js, json, txt, svg, png, jpg, gif, webp, ico, woff2. Nothing executable on the server is accepted. A version is shown through share links only after artifact-html-approve. An artifact that already has sections cannot become an HTML artifact. The sandbox has no browser storage: to keep state between visits or share it between viewers, load _tomatio.js (window.tomatio.store) once a curator turns on the page store with artifact-store-config. Embed the brand mark and the favicon in index.html as data: URIs (or inline SVG), never as separate image files, and keep the mark when you redesign: embedded icons show on every link, in exports and offline. The result lists warnings when index.html has no favicon or shows separate image files. Every page gets the brain design, the stylesheet the brain\'s documents use, ahead of your own CSS: use its classes and tokens instead of copying colours and fonts, so the page looks like the rest of the brain and follows its accent, light and dark. Classes: ab-page (reading column; ab-page--wide), ab-brand (leave it empty and the brain\'s mark and name fill it in), ab-kicker, ab-lede, ab-meta, ab-card (ab-card--accent, ab-card--milestone), ab-grid, ab-facts (children with ab-k and ab-v), ab-callout (--success, --warning, --danger, --decision), ab-status pills (--done, --in-progress, --blocked, --decision, --milestone, --idea), ab-table (wraps a table), ab-toc, ab-footer. Tokens: var(--ab-bg), --ab-surface, --ab-fg, --ab-muted, --ab-line, --ab-accent, --ab-accent-soft, --ab-accent-ink, --ab-radius, --ab-shadow, --ab-font-body, --ab-font-mono. A page with no CSS of its own also gets headings, lists, quotes and tables styled; with CSS of your own, put class="ab-doc" on body for that. <meta name="tomatio-design" content="off"> leaves a page alone. A page with no scripts of its own works as a document: the people who may edit it (curators, managers, the lead agent\'s person) get an Edit button on its members address and change its headings, paragraphs, list items, quotes and table cells in place; each save is a new version, live at once. So for a document, write the text into index.html and add no script; a page with scripts is an app and gets no editor.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'files'       => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'path'         => [ 'type' => 'string', 'description' => 'Relative path, for example index.html or img/logo.png.' ],
									'content'      => [ 'type' => 'string' ],
									'content_type' => [ 'type' => 'string', 'description' => 'Optional; must match the extension.' ],
									'encoding'     => [ 'type' => 'string', 'enum' => [ 'utf8', 'base64' ] ],
								],
								'required'   => [ 'path', 'content' ],
							],
						],
						'remove'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'edits'       => [
							'type'        => 'array',
							'description' => 'Changes to part of a text file of the latest version, applied in order after files and remove.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'path' => [ 'type' => 'string', 'description' => 'A text file of the latest version, for example index.html.' ],
									'find' => [ 'type' => 'string', 'description' => 'The exact text to find, spaces and line breaks included; it must occur once unless all is true.' ],
									'text' => [ 'type' => 'string', 'description' => 'The new text: replaces find, or goes before or after it.' ],
									'at'   => [ 'type' => 'string', 'enum' => [ 'replace', 'before', 'after' ] ],
									'all'  => [ 'type' => 'boolean', 'description' => 'Change every occurrence of find.' ],
								],
								'required'   => [ 'path', 'find', 'text' ],
							],
						],
						'force'       => [ 'type' => 'boolean', 'description' => 'Allow a whole-file upload that shrinks an existing file by more than 90%.' ],
						'note'        => [ 'type' => 'string', 'maxLength' => 200 ],
					],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'version'  => [ 'type' => 'integer' ],
						'files'    => [ 'type' => 'array' ],
						'warnings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
					'required'   => [ 'version', 'files' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter(
						static fn () => HtmlFiles::put(
							(int) ( $input['artifact_id'] ?? 0 ),
							ShareAbilities::actorId(),
							is_array( $input['files'] ?? null ) ? $input['files'] : [],
							is_array( $input['remove'] ?? null ) ? array_map( 'strval', $input['remove'] ) : [],
							(string) ( $input['note'] ?? '' ),
							is_array( $input['edits'] ?? null ) ? $input['edits'] : [],
							! empty( $input['force'] )
						)
					);
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_HTML_GET,
			[
				'label'               => __( 'Read an HTML artifact', 'agent-brain' ),
				'description'         => __( 'List the versions of an HTML artifact and the files of one version (default: the latest) with the approved version and what share links serve. Pass path to read one file (text as UTF-8, images and fonts as base64).', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'version'     => [ 'type' => 'integer', 'minimum' => 1 ],
						'path'        => [ 'type' => 'string' ],
					],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'versions'         => [ 'type' => 'array' ],
						'approved_version' => [ 'type' => 'integer' ],
						'mode'             => [ 'type' => 'string' ],
						'auto_reload'      => [ 'type' => 'boolean' ],
						'version'          => [ 'type' => 'integer' ],
						'files'            => [ 'type' => 'array' ],
						'file'             => [ 'type' => 'object' ],
					],
					'required'   => [ 'versions', 'approved_version', 'mode' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ),
				'execute_callback'    => static function ( $input ) {
					return self::get( is_array( $input ) ? $input : [] );
				},
			]
		);

		ShareAbilities::registerOnce(
			AbilityNames::ARTIFACT_HTML_APPROVE,
			[
				'label'               => __( 'Approve a version of an HTML artifact', 'agent-brain' ),
				'description'         => __( 'Approve a version of an HTML artifact: share links then serve it. May be called by a curator or manager, or by the artifact lead with a person acting via it; an agent on its own authority cannot approve — it uploads drafts (artifact-html-put) for a person to approve. On a connection with delegated authority (the person ticked "act with my rights" when connecting), you approve with that person\'s rights, mode=latest included when they may set it: when the person explicitly asked for the change, their request is the approval, so do not ask again; without such a request, leave the version for them to approve. A page locked as "Review required" cannot be approved by an AI at all. With mode=latest (curators only) share links serve the newest version without approval; mode=approved (default) goes back to the approved one. auto_reload (on unless turned off) reloads pages that are open on share links when the served version changes, at their next visibility change, never while in use.', 'agent-brain' ),
				'category'            => McpModule::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'artifact_id' => [ 'type' => 'integer', 'minimum' => 1 ],
						'version'     => [ 'type' => 'integer', 'minimum' => 1 ],
						'mode'        => [ 'type' => 'string', 'enum' => [ HtmlFiles::MODE_APPROVED, HtmlFiles::MODE_LATEST ] ],
						'auto_reload' => [ 'type' => 'boolean', 'description' => 'Reload open pages when a new version is approved (default on).' ],
					],
					'required'   => [ 'artifact_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'approved_version' => [ 'type' => 'integer' ],
						'mode'             => [ 'type' => 'string' ],
						'auto_reload'      => [ 'type' => 'boolean' ],
					],
					'required'   => [ 'approved_version', 'mode' ],
				],
				'permission_callback' => static fn ( $input ): bool => self::can( $input ) || self::canViaPerson( $input ),
				'execute_callback'    => static function ( $input ) {
					$input = is_array( $input ) ? $input : [];

					return ShareAbilities::withLimiter(
						static function () use ( $input ) {
							$artifactId = (int) ( $input['artifact_id'] ?? 0 );
							$approved   = HtmlFiles::approve(
								$artifactId,
								ShareAbilities::actorId(),
								(int) ( $input['version'] ?? 0 ),
								(string) ( $input['mode'] ?? HtmlFiles::MODE_APPROVED )
							);
							if ( $approved instanceof WP_Error ) {
								return $approved;
							}

							if ( isset( $input['auto_reload'] ) ) {
								// #629: an agent approving with delegated authority sets it as its person.
								$actor = ShareAbilities::actorId();
								if ( ! HtmlFiles::canEdit( $actor, $artifactId ) && HtmlFiles::delegatedPerson( $actor ) > 0 ) {
									$actor = HtmlFiles::delegatedPerson( $actor );
								}
								$set = HtmlFiles::setAutoReload( $artifactId, $actor, (bool) $input['auto_reload'] );
								if ( $set instanceof WP_Error ) {
									return $set;
								}
							}

							return $approved + [ 'auto_reload' => HtmlFiles::autoReload( $artifactId ) ];
						}
					);
				},
			]
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get( array $input ) {
		$artifactId = (int) ( $input['artifact_id'] ?? 0 );
		if ( ! HtmlFiles::canEdit( ShareAbilities::actorId(), $artifactId ) || ! HtmlFiles::isHtml( $artifactId ) ) {
			return new WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		$versions = HtmlFiles::versions( $artifactId );
		$number   = (int) ( $input['version'] ?? 0 ) ?: HtmlFiles::latestNumber( $artifactId );
		$row      = HtmlFiles::version( $artifactId, $number );
		if ( $row === null ) {
			return new WP_Error( 'ab_html_no_version', __( 'That version does not exist.', 'agent-brain' ), [ 'status' => 404 ] );
		}

		$out = [
			'versions'         => array_map(
				static fn ( array $v ): array => [ 'version' => $v['version'], 'created_at' => $v['created_at'], 'created_by' => $v['created_by'], 'note' => $v['note'], 'files' => count( $v['files'] ) ],
				$versions
			),
			'approved_version' => HtmlFiles::approvedNumber( $artifactId ),
			'mode'             => HtmlFiles::mode( $artifactId ),
			'auto_reload'      => HtmlFiles::autoReload( $artifactId ),
			'version'          => $number,
			'files'            => HtmlFiles::listing( $row['files'] ),
		];

		$path = (string) ( $input['path'] ?? '' );
		if ( $path !== '' ) {
			if ( ! isset( $row['files'][ $path ] ) ) {
				return new WP_Error( 'ab_not_found', __( 'File not found.', 'agent-brain' ), [ 'status' => 404 ] );
			}
			$file        = $row['files'][ $path ];
			$out['file'] = [ 'path' => $path, 'type' => $file['type'], 'encoding' => $file['enc'], 'content' => $file['data'], 'size' => $file['size'] ];
		}

		return $out;
	}

	/**
	 * #629: artifact-html-approve also lets an agent through whose
	 * connection has delegated authority from a person who may manage the
	 * page; HtmlFiles::approve() checks the rest (the lock, mode=latest).
	 *
	 * @param mixed $input
	 */
	private static function canViaPerson( $input ): bool {
		if ( ! ShareAbilities::canUseMcp() ) {
			return false;
		}

		$person = HtmlFiles::delegatedPerson( ShareAbilities::actorId() );
		if ( $person <= 0 ) {
			return false;
		}

		$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;

		return $artifactId > 0 ? HtmlFiles::canEdit( $person, $artifactId ) : ShareLinks::canManageAny( $person );
	}

	/**
	 * @param mixed $input
	 */
	private static function can( $input ): bool {
		if ( ! ShareAbilities::canUseMcp() ) {
			return false;
		}

		$artifactId = is_array( $input ) ? (int) ( $input['artifact_id'] ?? 0 ) : 0;

		// Discovery passes no artifact_id: approximate with "may manage any".
		return $artifactId > 0
			? HtmlFiles::canEdit( ShareAbilities::actorId(), $artifactId )
			: ShareLinks::canManageAny( ShareAbilities::actorId() );
	}
}
