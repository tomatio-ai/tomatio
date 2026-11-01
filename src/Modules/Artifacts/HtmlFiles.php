<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Modules\Identity\Capabilities;
use AgentBrain\Modules\OAuth\DelegatedAuthority;

/**
 * ART-22 (#138): the HTML kind of artifact. Its content is a set of files
 * (index.html is the entry point) stored as versions in post meta, one meta
 * row per version; a version is approved by the lead or a curator, like a
 * section, and share links serve the approved version (or the latest one if
 * a curator chose that). The files are never rendered on the main site:
 * Sharing\HtmlSite serves them on the preview host only.
 *
 * Only a fixed list of static file types is accepted, chosen by extension;
 * nothing the server could execute (PHP and the like) can be stored.
 */
final class HtmlFiles {

	public const META_KIND     = 'ab_artifact_kind';
	public const META_VERSION  = 'ab_html_version';
	public const META_APPROVED = 'ab_html_approved';
	public const META_MODE     = 'ab_html_mode';
	// #469: '0' turns off reloading open share pages when a new version is served; absent means on.
	public const META_AUTO_RELOAD = 'ab_html_auto_reload';

	public const KIND_HTML = 'html';

	public const MODE_APPROVED = 'approved';
	public const MODE_LATEST   = 'latest';

	public const ENTRY = 'index.html';

	/** Retained versions per artifact; older unapproved ones are pruned. */
	public const KEEP_VERSIONS = 30;

	/** #682: a whole-file upload may not cut a file of at least this size to under a tenth of it, unless forced. */
	public const SHRINK_MIN_BYTES = 4096;

	/** @var array<string, array{type:string,text:bool}> extension => content type. */
	public const TYPES = [
		'html' => [ 'type' => 'text/html; charset=utf-8', 'text' => true ],
		'css'  => [ 'type' => 'text/css; charset=utf-8', 'text' => true ],
		'js'   => [ 'type' => 'text/javascript; charset=utf-8', 'text' => true ],
		'json' => [ 'type' => 'application/json; charset=utf-8', 'text' => true ],
		'txt'  => [ 'type' => 'text/plain; charset=utf-8', 'text' => true ],
		'svg'  => [ 'type' => 'image/svg+xml', 'text' => true ],
		'png'  => [ 'type' => 'image/png', 'text' => false ],
		'jpg'  => [ 'type' => 'image/jpeg', 'text' => false ],
		'jpeg' => [ 'type' => 'image/jpeg', 'text' => false ],
		'gif'  => [ 'type' => 'image/gif', 'text' => false ],
		'webp' => [ 'type' => 'image/webp', 'text' => false ],
		'ico'  => [ 'type' => 'image/x-icon', 'text' => false ],
		'woff2' => [ 'type' => 'font/woff2', 'text' => false ],
	];

	/**
	 * @return array{max_files:int,max_total_bytes:int,max_file_bytes:int}
	 */
	public static function limits(): array {
		$defaults = [ 'max_files' => 50, 'max_total_bytes' => 5 * 1024 * 1024, 'max_file_bytes' => 2 * 1024 * 1024 ];
		$limits   = function_exists( 'apply_filters' ) ? apply_filters( 'agent_brain/html_limits', $defaults ) : $defaults;
		$limits   = is_array( $limits ) ? $limits : $defaults;

		return [
			'max_files'       => max( 1, (int) ( $limits['max_files'] ?? $defaults['max_files'] ) ),
			'max_total_bytes' => max( 1024, (int) ( $limits['max_total_bytes'] ?? $defaults['max_total_bytes'] ) ),
			'max_file_bytes'  => max( 1024, (int) ( $limits['max_file_bytes'] ?? $defaults['max_file_bytes'] ) ),
		];
	}

	/**
	 * A relative path of lowercase-insensitive safe segments, no leading
	 * slash, no "." or ".." segment, at most 5 deep and 120 characters.
	 */
	public static function isValidPath( string $path ): bool {
		if ( $path === '' || strlen( $path ) > 120 || preg_match( '#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+){0,4}$#', $path ) !== 1 ) {
			return false;
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( $segment === '.' || $segment === '..' || $segment[0] === '.' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Content type for a path by its (last) extension, or null when the type
	 * is not on the list.
	 */
	public static function typeFor( string $path ): ?string {
		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return self::TYPES[ $ext ]['type'] ?? null;
	}

	public static function isTextPath( string $path ): bool {
		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		return self::TYPES[ $ext ]['text'] ?? false;
	}

	/**
	 * Validate one uploaded file and return its stored form.
	 *
	 * @param array<string, mixed> $file path, content, optional content_type, optional encoding (utf8|base64).
	 * @return array{path:string,type:string,enc:string,data:string,size:int}|\WP_Error
	 */
	public static function normalizeFile( array $file ) {
		$path = (string) ( $file['path'] ?? '' );
		if ( ! self::isValidPath( $path ) ) {
			return self::error( 'ab_html_bad_path', 'Invalid file path: ' . substr( preg_replace( '/[^\x20-\x7e]/', '?', $path ) ?? '', 0, 60 ) . '. Use relative paths of letters, digits, dot, dash and underscore, up to 5 levels, with no leading dot.' );
		}

		$type = self::typeFor( $path );
		if ( $type === null ) {
			return self::error( 'ab_html_bad_type', 'File type not allowed: ' . $path . '. Allowed extensions: ' . implode( ', ', array_keys( self::TYPES ) ) . '.' );
		}

		$declared = strtolower( trim( explode( ';', (string) ( $file['content_type'] ?? '' ) )[0] ) );
		if ( $declared !== '' && $declared !== strtolower( trim( explode( ';', $type )[0] ) ) && ! ( $declared === 'application/javascript' && substr( $type, 0, 15 ) === 'text/javascript' ) ) {
			return self::error( 'ab_html_type_mismatch', 'content_type ' . $declared . ' does not match the extension of ' . $path . '.' );
		}

		$content  = (string) ( $file['content'] ?? '' );
		$isText   = self::isTextPath( $path );
		$encoding = strtolower( (string) ( $file['encoding'] ?? ( $isText ? 'utf8' : 'base64' ) ) );
		if ( ! in_array( $encoding, [ 'utf8', 'base64' ], true ) || ( ! $isText && $encoding !== 'base64' ) ) {
			return self::error( 'ab_html_bad_encoding', 'Use encoding base64 for binary files and utf8 or base64 for text: ' . $path . '.' );
		}

		if ( $encoding === 'base64' ) {
			$raw = base64_decode( $content, true );
			if ( $raw === false ) {
				return self::error( 'ab_html_bad_encoding', 'Content of ' . $path . ' is not valid base64.' );
			}
		} else {
			$raw = $content;
		}

		if ( $isText && ( preg_match( '//u', $raw ) !== 1 || strpos( $raw, "\0" ) !== false ) ) {
			return self::error( 'ab_html_bad_encoding', 'Content of ' . $path . ' is not valid UTF-8 text.' );
		}

		if ( ! $isText && ! self::binaryMatches( $path, $raw ) ) {
			return self::error( 'ab_html_type_mismatch', 'Content of ' . $path . ' does not look like that image or font type.' );
		}

		$limits = self::limits();
		if ( strlen( $raw ) > $limits['max_file_bytes'] ) {
			return self::error( 'ab_html_too_large', $path . ' is larger than ' . $limits['max_file_bytes'] . ' bytes.' );
		}

		return [
			'path' => $path,
			'type' => $type,
			'enc'  => $isText ? 'utf8' : 'base64',
			'data' => $isText ? $raw : base64_encode( $raw ),
			'size' => strlen( $raw ),
		];
	}

	/**
	 * Magic-number check, so a file named .png cannot be anything else.
	 */
	private static function binaryMatches( string $path, string $raw ): bool {
		$ext   = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$magic = [
			'png'   => "\x89PNG",
			'jpg'   => "\xFF\xD8\xFF",
			'jpeg'  => "\xFF\xD8\xFF",
			'gif'   => 'GIF8',
			'webp'  => 'RIFF',
			'ico'   => "\x00\x00\x01\x00",
			'woff2' => 'wOF2',
		];

		return isset( $magic[ $ext ] ) && strncmp( $raw, $magic[ $ext ], strlen( $magic[ $ext ] ) ) === 0;
	}

	/**
	 * Check a complete file set against the limits and the entry point.
	 *
	 * @param array<string, array{size:int}> $files path => stored file.
	 * @return true|\WP_Error
	 */
	public static function validateSet( array $files ) {
		$limits = self::limits();

		if ( ! isset( $files[ self::ENTRY ] ) ) {
			return self::error( 'ab_html_no_entry', 'A version needs ' . self::ENTRY . ' as its entry point.' );
		}

		if ( count( $files ) > $limits['max_files'] ) {
			return self::error( 'ab_html_too_many', 'At most ' . $limits['max_files'] . ' files per version.' );
		}

		$total = 0;
		$seen  = [];
		foreach ( $files as $path => $file ) {
			$total += (int) $file['size'];
			$low    = strtolower( (string) $path );
			if ( isset( $seen[ $low ] ) ) {
				return self::error( 'ab_html_duplicate', 'Paths that differ only in case are not allowed: ' . $path . '.' );
			}
			$seen[ $low ] = true;
		}

		if ( $total > $limits['max_total_bytes'] ) {
			return self::error( 'ab_html_too_large', 'A version may be at most ' . $limits['max_total_bytes'] . ' bytes in total.' );
		}

		return true;
	}

	public static function isHtml( int $artifactId ): bool {
		return $artifactId > 0 && get_post_meta( $artifactId, self::META_KIND, true ) === self::KIND_HTML;
	}

	/**
	 * Lead, curator or manager: the same people who manage share links;
	 * since #632 also the person behind the lead agent.
	 */
	public static function canEdit( int $actorId, int $artifactId ): bool {
		if ( $actorId <= 0 ) {
			return false;
		}

		$post = get_post( $artifactId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
			return false;
		}

		return ObjectAccess::userIsLead( $actorId, $artifactId )
			|| ObjectAccess::userIsBehindLead( $actorId, $artifactId )
			|| ObjectAccess::userIsCurator( $actorId )
			|| user_can( $actorId, Capabilities::CAP_MANAGE );
	}

	public static function canSetMode( int $actorId ): bool {
		return $actorId > 0 && ( ObjectAccess::userIsCurator( $actorId ) || user_can( $actorId, Capabilities::CAP_MANAGE ) );
	}

	public static function register(): void {
		add_action( 'init', [ self::class, 'registerMeta' ], 21 );
	}

	/**
	 * Files, versions and approval are written only through this class: keep
	 * the generic meta APIs (Custom Fields box, REST) out of them.
	 */
	public static function registerMeta(): void {
		foreach ( [ self::META_KIND, self::META_VERSION, self::META_APPROVED, self::META_MODE, self::META_AUTO_RELOAD ] as $key ) {
			register_post_meta(
				Cpts::ARTIFACT_POST_TYPE,
				$key,
				[
					'type'          => 'string',
					'single'        => $key !== self::META_VERSION,
					'show_in_rest'  => false,
					'auth_callback' => '__return_false',
				]
			);
		}
	}

	/**
	 * @return array<int, array{version:int,created_at:string,created_by:int,note:string,files:array<string, array{type:string,enc:string,data:string,size:int}>}>
	 */
	public static function versions( int $artifactId ): array {
		$out = [];
		foreach ( get_post_meta( $artifactId, self::META_VERSION, false ) as $raw ) {
			$row = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $row ) && isset( $row['version'], $row['files'] ) && is_array( $row['files'] ) ) {
				$out[ (int) $row['version'] ] = [
					'version'    => (int) $row['version'],
					'created_at' => (string) ( $row['created_at'] ?? '' ),
					'created_by' => (int) ( $row['created_by'] ?? 0 ),
					'note'       => (string) ( $row['note'] ?? '' ),
					'files'      => $row['files'],
				];
			}
		}
		ksort( $out );

		return array_values( $out );
	}

	/**
	 * @return array{version:int,created_at:string,created_by:int,note:string,files:array<string, array{type:string,enc:string,data:string,size:int}>}|null
	 */
	public static function version( int $artifactId, int $version ): ?array {
		foreach ( self::versions( $artifactId ) as $row ) {
			if ( $row['version'] === $version ) {
				return $row;
			}
		}

		return null;
	}

	public static function latestNumber( int $artifactId ): int {
		$versions = self::versions( $artifactId );

		return $versions === [] ? 0 : $versions[ count( $versions ) - 1 ]['version'];
	}

	public static function approvedNumber( int $artifactId ): int {
		return (int) get_post_meta( $artifactId, self::META_APPROVED, true );
	}

	public static function mode( int $artifactId ): string {
		return get_post_meta( $artifactId, self::META_MODE, true ) === self::MODE_LATEST ? self::MODE_LATEST : self::MODE_APPROVED;
	}

	/**
	 * #469: the number of the version share links serve (0 when none), without
	 * loading its files: the update signal open pages poll.
	 */
	public static function servedNumber( int $artifactId ): int {
		return self::mode( $artifactId ) === self::MODE_LATEST ? self::latestNumber( $artifactId ) : self::approvedNumber( $artifactId );
	}

	/**
	 * #469: whether open share pages reload when a new version is served
	 * ("Reload open pages when a new version is approved"). On unless turned
	 * off, so every HTML artifact gets it without page code.
	 */
	public static function autoReload( int $artifactId ): bool {
		return get_post_meta( $artifactId, self::META_AUTO_RELOAD, true ) !== '0';
	}

	/**
	 * Same people as approve(): a person (lead, curator or manager), never
	 * an agent on its own authority.
	 *
	 * @return true|\WP_Error
	 */
	public static function setAutoReload( int $artifactId, int $actorId, bool $on ) {
		if ( ! self::canEdit( $actorId, $artifactId ) || Capabilities::isPureAgent( $actorId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( self::autoReload( $artifactId ) === $on ) {
			return true;
		}

		update_post_meta( $artifactId, self::META_AUTO_RELOAD, $on ? '1' : '0' );
		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => $on ? 'artifact_html_auto_reload_on' : 'artifact_html_auto_reload_off',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [],
			]
		);

		return true;
	}

	/**
	 * The version share links serve, or null (nothing approved yet).
	 *
	 * @return array{version:int,created_at:string,created_by:int,note:string,files:array<string, array{type:string,enc:string,data:string,size:int}>}|null
	 */
	public static function servedVersion( int $artifactId ): ?array {
		$number = self::mode( $artifactId ) === self::MODE_LATEST ? self::latestNumber( $artifactId ) : self::approvedNumber( $artifactId );

		return $number > 0 ? self::version( $artifactId, $number ) : null;
	}

	/**
	 * Upload or replace files: the new version is the latest one with the
	 * given files added or replaced and the removed paths dropped. The first
	 * put turns the artifact into an HTML artifact (refused when it already
	 * has sections).
	 *
	 * #682: $edits then change part of a text file (see applyEdits()), so an
	 * agent never has to resend a whole page. With $allowShrink off (the MCP
	 * ability, unless force=true), a file that would shrink by more than 90%
	 * is refused: the slip that once replaced a 78 KB page with a placeholder.
	 *
	 * @param array<int, array<string, mixed>> $files
	 * @param array<int, string>               $remove
	 * @param array<int, array<string, mixed>> $edits
	 * @return array{version:int,files:array<int, array{path:string,type:string,size:int}>,warnings?:array<int, string>}|\WP_Error
	 */
	public static function put( int $artifactId, int $actorId, array $files, array $remove = [], string $note = '', array $edits = [], bool $allowShrink = true ) {
		if ( ! self::canEdit( $actorId, $artifactId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( ! self::isHtml( $artifactId ) ) {
			$sections = get_posts( [ 'post_type' => Cpts::SECTION_POST_TYPE, 'post_parent' => $artifactId, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ] );
			if ( $sections !== [] ) {
				return self::error( 'ab_html_has_sections', 'This artifact is made of sections; HTML files need an artifact of their own.', 409 );
			}
		}

		$latest = self::latestNumber( $artifactId );
		$base   = $latest > 0 ? ( self::version( $artifactId, $latest )['files'] ?? [] ) : [];

		foreach ( $remove as $path ) {
			unset( $base[ (string) $path ] );
		}

		foreach ( $files as $file ) {
			$stored = self::normalizeFile( $file );
			if ( $stored instanceof \WP_Error ) {
				return $stored;
			}
			// A path that differs only in case replaces the old one.
			foreach ( array_keys( $base ) as $existing ) {
				if ( strtolower( (string) $existing ) === strtolower( $stored['path'] ) ) {
					$old = (int) $base[ $existing ]['size'];
					if ( ! $allowShrink && $old >= self::SHRINK_MIN_BYTES && $stored['size'] * 10 < $old ) {
						return self::error( 'ab_html_shrink', $stored['path'] . ' would shrink from ' . $old . ' to ' . $stored['size'] . ' bytes, more than 90%. To change part of a file, send edits instead of the whole file; to really replace it, send force=true.', 409 );
					}
					unset( $base[ $existing ] );
				}
			}
			$base[ $stored['path'] ] = [ 'type' => $stored['type'], 'enc' => $stored['enc'], 'data' => $stored['data'], 'size' => $stored['size'] ];
		}

		$base = self::applyEdits( $base, $edits );
		if ( $base instanceof \WP_Error ) {
			return $base;
		}

		$valid = self::validateSet( $base );
		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}

		ksort( $base );
		$number = $latest + 1;
		$row    = [
			'version'    => $number,
			'created_at' => gmdate( 'c' ),
			'created_by' => $actorId,
			'note'       => substr( $note, 0, 200 ),
			'files'      => $base,
		];

		add_post_meta( $artifactId, self::META_VERSION, wp_slash( (string) wp_json_encode( $row ) ), false );
		update_post_meta( $artifactId, self::META_KIND, self::KIND_HTML );
		self::prune( $artifactId );

		// #220: every upload is a version of the artifact's unified history
		// (the row references this HtmlFiles version; the bytes stay here).
		Versions::recordHtml( $artifactId, $actorId, $number, $note );

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'artifact_html_put',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'version' => $number, 'files' => count( $base ), 'edits' => count( $edits ) ],
			]
		);

		$result   = [ 'version' => $number, 'files' => self::listing( $base ) ];
		$warnings = self::iconWarnings( $base );

		return $warnings === [] ? $result : $result + [ 'warnings' => $warnings ];
	}

	/**
	 * #682: apply edits, in order, to the text files of a version. Each edit
	 * names a path, the exact text to find and the text to put in its place
	 * (at=replace, the default) or before or after it (at=before|after). The
	 * find text must occur exactly once, unless all=true changes every
	 * occurrence, so an edit never lands somewhere it was not meant to.
	 *
	 * @param array<string, array{type:string,enc:string,data:string,size:int}> $base  stored files by path
	 * @param array<int, mixed>                                                 $edits
	 * @return array<string, array{type:string,enc:string,data:string,size:int}>|\WP_Error
	 */
	public static function applyEdits( array $base, array $edits ) {
		foreach ( array_values( $edits ) as $i => $edit ) {
			$edit = is_array( $edit ) ? $edit : [];
			$n    = 'Edit ' . ( $i + 1 ) . ': ';
			$path = (string) ( $edit['path'] ?? '' );
			$find = (string) ( $edit['find'] ?? '' );
			$text = (string) ( $edit['text'] ?? '' );
			$at   = (string) ( $edit['at'] ?? 'replace' );
			$all  = ! empty( $edit['all'] );

			if ( ! isset( $base[ $path ] ) ) {
				return self::error( 'ab_html_edit_no_file', $n . substr( preg_replace( '/[^\x20-\x7e]/', '?', $path ) ?? '', 0, 120 ) . ' is not a file of the latest version.' );
			}
			if ( ! self::isTextPath( $path ) ) {
				return self::error( 'ab_html_edit_binary', $n . $path . ' is not a text file; upload it whole in files.' );
			}
			if ( $find === '' ) {
				return self::error( 'ab_html_edit_no_find', $n . 'find is empty. Give the exact text to change, copied from artifact-html-get.' );
			}
			if ( ! in_array( $at, [ 'replace', 'before', 'after' ], true ) ) {
				return self::error( 'ab_html_edit_bad_at', $n . 'at must be replace, before or after.' );
			}

			$raw   = self::bytes( $base[ $path ] );
			$count = substr_count( $raw, $find );
			if ( $count === 0 ) {
				return self::error( 'ab_html_edit_not_found', $n . 'the find text is not in ' . $path . '. Read the file with artifact-html-get and copy the text exactly, spaces and line breaks included.' );
			}
			if ( $count > 1 && ! $all ) {
				return self::error( 'ab_html_edit_ambiguous', $n . 'the find text occurs ' . $count . ' times in ' . $path . '. Add surrounding text so it occurs once, or set all=true to change every occurrence.' );
			}

			$with   = $at === 'before' ? $text . $find : ( $at === 'after' ? $find . $text : $text );
			$raw    = $all ? str_replace( $find, $with, $raw ) : substr_replace( $raw, $with, (int) strpos( $raw, $find ), strlen( $find ) );
			$stored = self::normalizeFile( [ 'path' => $path, 'content' => $raw, 'encoding' => 'utf8' ] );
			if ( $stored instanceof \WP_Error ) {
				return $stored;
			}
			$base[ $path ] = [ 'type' => $stored['type'], 'enc' => $stored['enc'], 'data' => $stored['data'], 'size' => $stored['size'] ];
		}

		return $base;
	}

	/**
	 * #608: an HTML artifact embeds its favicon and the images it shows as
	 * data: URIs, so they appear on every link (members address, public and
	 * private share links), in exports and offline. Advice only: the upload
	 * is kept either way, and the agent is told what to embed.
	 *
	 * @param array<string, array{enc:string,data:string}> $files stored files by path
	 * @return array<int, string>
	 */
	public static function iconWarnings( array $files ): array {
		if ( ! isset( $files[ self::ENTRY ] ) ) {
			return [];
		}

		$html     = self::bytes( $files[ self::ENTRY ] );
		$warnings = [];
		$icons    = [];

		if ( preg_match_all( '#<link\b[^>]*>#i', $html, $links ) ) {
			foreach ( $links[0] as $tag ) {
				if ( preg_match( '#\brel\s*=\s*(["\'])([^"\']*)\1#i', $tag, $rel ) !== 1 || preg_match( '#(^|\s)icon(\s|$)#i', $rel[2] ) !== 1 ) {
					continue;
				}
				$icons[] = preg_match( '#\bhref\s*=\s*(["\'])(.*?)\1#is', $tag, $href ) === 1 ? trim( $href[2] ) : '';
			}
		}

		if ( $icons === [] ) {
			$warnings[] = 'index.html has no favicon. Add <link rel="icon" href="data:image/png;base64,..."> with the brand mark embedded.';
		}
		foreach ( $icons as $href ) {
			if ( stripos( $href, 'data:' ) !== 0 ) {
				$warnings[] = 'The favicon ' . substr( $href, 0, 80 ) . ' is a separate file. Embed it in index.html as a data: URI so it shows on every link, in exports and offline.';
			}
		}

		if ( preg_match_all( '#<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1#is', $html, $images ) ) {
			$shown = [];
			foreach ( $images[2] as $src ) {
				$path = ltrim( (string) preg_replace( '#^\./#', '', (string) strtok( trim( $src ), '?#' ) ), '/' );
				if ( $path !== '' && isset( $files[ $path ] ) ) {
					$shown[ $path ] = true;
				}
			}
			if ( $shown !== [] ) {
				$warnings[] = 'Embed images as data: URIs instead of separate files, the brand mark first: ' . implode( ', ', array_keys( $shown ) ) . '.';
			}
		}

		return $warnings;
	}

	/**
	 * Approve a version (lead or curator), or switch to serving the latest
	 * one (curator or manager only). Approving publishes what share links
	 * serve, so a pure agent identity may not do it: the lead writes drafts,
	 * a person (curator/manager, or someone acting via the agent) approves
	 * (#230).
	 *
	 * #629: an agent on a connection with delegated authority may also
	 * approve with the rights of the person it acts for (including
	 * mode=latest when that person may set it). On an artifact locked as
	 * "Review required" (ReviewLock) no agent approves, not even via a
	 * person: a person approves it themselves.
	 *
	 * @return array{approved_version:int,mode:string}|\WP_Error
	 */
	public static function approve( int $artifactId, int $actorId, int $version, string $mode = self::MODE_APPROVED ) {
		$person  = self::delegatedPerson( $actorId );
		$ownEdit = self::canEdit( $actorId, $artifactId );
		if ( ! self::isHtml( $artifactId ) || ( ! $ownEdit && ! ( $person > 0 && self::canEdit( $person, $artifactId ) ) ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( ReviewLock::isLocked( $artifactId ) && self::isAgentUser( $actorId ) ) {
			return new \WP_Error( 'ab_review_required', __( 'This page is locked as "Review required": an AI cannot approve its versions, even when the person asks. A person approves it themselves, for example on the artifact screen.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( Capabilities::isPureAgent( $actorId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'An agent cannot approve its own version: a curator or manager must approve it.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( ! in_array( $mode, [ self::MODE_APPROVED, self::MODE_LATEST ], true ) ) {
			return self::error( 'ab_html_bad_mode', 'mode must be approved or latest.' );
		}

		$ownMode = self::canSetMode( $actorId );
		if ( $mode === self::MODE_LATEST && ! $ownMode && ! ( $person > 0 && self::canSetMode( $person ) ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'Only a curator can publish the latest version without approval.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		// Delegated authority was used when the agent's own rights would not do.
		$delegated = $person > 0 && ( ! $ownEdit || ( $mode === self::MODE_LATEST && ! $ownMode ) );

		if ( $version > 0 ) {
			if ( self::version( $artifactId, $version ) === null ) {
				return self::error( 'ab_html_no_version', 'That version does not exist.', 404 );
			}
			update_post_meta( $artifactId, self::META_APPROVED, (string) $version );
		}

		if ( $mode === self::MODE_LATEST ) {
			update_post_meta( $artifactId, self::META_MODE, self::MODE_LATEST );
		} else {
			delete_post_meta( $artifactId, self::META_MODE );
		}

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'artifact_html_approved',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'version' => $version, 'mode' => $mode ] + ( $delegated ? [ 'via' => $person, 'delegated_authority' => true ] : [] ),
			]
		);

		if ( $delegated ) {
			DelegatedAuthority::record( $actorId, $person, 'html_approve', 'artifact', (string) $artifactId, [ 'version' => $version, 'mode' => $mode ] );
		}

		return [ 'approved_version' => self::approvedNumber( $artifactId ), 'mode' => self::mode( $artifactId ) ];
	}

	/**
	 * #629: the person an agent may approve for under delegated authority
	 * (OAuth\DelegatedAuthority::personFor()), or 0. Never for a person
	 * acting as themselves.
	 */
	public static function delegatedPerson( int $actorId ): int {
		return self::isAgentUser( $actorId ) ? DelegatedAuthority::personFor( $actorId ) : 0;
	}

	private static function isAgentUser( int $userId ): bool {
		$user = $userId > 0 ? get_userdata( $userId ) : false;

		return $user instanceof \WP_User && in_array( Capabilities::ROLE_AGENT, (array) $user->roles, true );
	}

	/**
	 * #220 restore (HTML artifacts): re-publish an old version's file set as
	 * a NEW version — nothing is overwritten and nothing starts being served
	 * by itself: the approved version and the serve mode are untouched, so
	 * the restored version waits for artifact-html-approve exactly like a
	 * fresh upload. The gate mirrors approve(): lead/curator/manager may
	 * restore, but a pure agent on its own authority may not (#230 — a
	 * person approves what gets served). Every restore is audited; viewing
	 * history never is.
	 *
	 * @return array{version:int,seq:int|null,files:array<int, array{path:string,type:string,size:int}>}|\WP_Error
	 */
	public static function restore( int $artifactId, int $actorId, int $version, string $note = '' ) {
		if ( ! self::canEdit( $actorId, $artifactId ) || ! self::isHtml( $artifactId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		if ( Capabilities::isPureAgent( $actorId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'An agent cannot restore a version on its own authority: a curator or manager must approve it.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		$row = self::version( $artifactId, $version );
		if ( $row === null ) {
			return self::error( 'ab_html_no_version', 'That version does not exist.', 404 );
		}

		$latest = self::latestNumber( $artifactId );
		$number = $latest + 1;
		$newRow = [
			'version'    => $number,
			'created_at' => gmdate( 'c' ),
			'created_by' => $actorId,
			'note'       => substr(
				trim( $note ) !== ''
					? sprintf( /* translators: 1: restored-from version, 2: caller note. */ __( 'Restore of version %1$d: %2$s', 'agent-brain' ), $version, $note )
					: sprintf( /* translators: %d: restored-from version. */ __( 'Restore of version %d', 'agent-brain' ), $version ),
				0,
				200
			),
			'files'      => $row['files'],
		];

		add_post_meta( $artifactId, self::META_VERSION, wp_slash( (string) wp_json_encode( $newRow ) ), false );
		self::prune( $artifactId );

		// #220: the restore is itself a new entry of the unified history.
		$seq = Versions::recordHtml( $artifactId, $actorId, $number, $newRow['note'] );

		do_action(
			'agent_brain/audit',
			[
				'actor_id'    => $actorId,
				'event'       => 'artifact_html_restore',
				'status'      => 'ok',
				'object_type' => 'artifact',
				'object_id'   => (string) $artifactId,
				'meta'        => [ 'from_version' => $version, 'version' => $number ],
			]
		);

		return [ 'version' => $number, 'seq' => $seq, 'files' => self::listing( $row['files'] ) ];
	}

	/**
	 * Drop the oldest versions over the cap; the approved one is never dropped.
	 */
	private static function prune( int $artifactId ): void {
		$raws   = [];
		foreach ( get_post_meta( $artifactId, self::META_VERSION, false ) as $raw ) {
			$row = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $row ) && isset( $row['version'] ) ) {
				$raws[ (int) $row['version'] ] = $raw;
			}
		}
		ksort( $raws );

		$excess   = count( $raws ) - self::KEEP_VERSIONS;
		$approved = self::approvedNumber( $artifactId );
		foreach ( $raws as $number => $raw ) {
			if ( $excess <= 0 ) {
				break;
			}
			if ( $number === $approved ) {
				continue;
			}
			// The stored string, byte for byte, so the row is matched exactly.
			delete_post_meta( $artifactId, self::META_VERSION, wp_slash( $raw ) );
			--$excess;
		}
	}

	/**
	 * @param array<string, array{type:string,size:int}> $files
	 * @return array<int, array{path:string,type:string,size:int}>
	 */
	public static function listing( array $files ): array {
		$out = [];
		foreach ( $files as $path => $file ) {
			$out[] = [ 'path' => (string) $path, 'type' => (string) $file['type'], 'size' => (int) $file['size'] ];
		}

		return $out;
	}

	/**
	 * Raw bytes of a stored file.
	 *
	 * @param array{enc:string,data:string} $file
	 */
	public static function bytes( array $file ): string {
		if ( $file['enc'] === 'base64' ) {
			$raw = base64_decode( $file['data'], true );

			return $raw === false ? '' : $raw;
		}

		return $file['data'];
	}

	private static function error( string $code, string $message, int $status = 422 ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
