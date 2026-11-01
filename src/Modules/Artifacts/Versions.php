<?php

declare(strict_types=1);

namespace AgentBrain\Modules\Artifacts;

use AgentBrain\Core\Text;
use AgentBrain\Modules\Identity\Capabilities;

/**
 * #220: ONE version history per artifact covering both kinds of content.
 *
 * Section artifacts only ever store their CURRENT content (ab_version is a
 * counter, not a log), and the HTML kind already snapshots every upload in
 * its own `ab_html_version` rows. This class adds the missing half for
 * sections — an append-only snapshot taken at every section SUBMIT (the
 * moment a version becomes reviewable, including the pending ones you can
 * today only approve blind) — and unifies both kinds in one per-artifact
 * list that wp-admin and MCP read.
 *
 * Storage: one `ab_version_history` postmeta ROW per version (non-unique
 * meta, one JSON object each), mirroring HtmlFiles' own version rows. A
 * section row snapshots {section_id, section_version, title, content}
 * inline; an HTML row only REFERENCES HtmlFiles' version number — the file
 * bytes stay in the `ab_html_version` rows and are read back through
 * HtmlFiles::version(). That means a history row can outlive the file set
 * it points at: HtmlFiles::prune() drops unapproved versions past the keep
 * cap, so reads of a pruned HTML version return the row metadata with an
 * empty file list instead of failing (the row itself is never rewritten or
 * deleted).
 *
 * Sequence numbers (`seq`) are 1-based per artifact and assigned under a
 * MySQL named lock scoped to the artifact, the same pattern
 * ArtifactService::appendToOutlineAtomic() uses: plain read-count-then-write
 * on wp_postmeta has no atomicity of its own.
 */
final class Versions {

	/**
	 * Not a Cpts:: constant — this key is internal to this class's own
	 * persistence, like Stages::META_REVIEW_LOG_CORRUPT.
	 */
	private const META = 'ab_version_history';

	/**
	 * Same writer-provenance key ArtifactService records on every
	 * updateSection() (its META_LAST_WRITER_CLIENT is private to that unit's
	 * file boundary; the literal is shared deliberately).
	 */
	private const META_LAST_WRITER_CLIENT = 'ab_last_writer_client';

	/** Retained history rows per artifact; the oldest are dropped past this. */
	public const KEEP_ROWS = 200;

	/**
	 * Line cap per side of a section text compare, so a pathological section
	 * cannot make the LCS quadratic blow up unbounded (200x200 is trivial).
	 */
	private const COMPARE_MAX_LINES = 200;

	// ------------------------------------------------------------------
	// Recording
	// ------------------------------------------------------------------

	/**
	 * Snapshot a section submission. Skipped (returns null) when the section's
	 * latest snapshot already carries the same section_version: a resubmit
	 * without an intervening content change creates no new version, so the
	 * history — a version list, not an event log — stays quiet. The note
	 * defaults to the most recent review-log entry's notes: that is the
	 * "what the reviewer asked for" context a resubmission answers.
	 *
	 * @return int|null The new row's seq, or null when skipped.
	 */
	public static function recordSection( int $artifactId, int $sectionId, int $actorId, string $note = '' ): ?int {
		$section = get_post( $sectionId );
		if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE || (int) $section->post_parent !== $artifactId ) {
			return null;
		}

		$sectionVersion = self::sectionVersion( $sectionId );
		$latest         = self::latestSectionRow( $artifactId, $sectionId );
		if ( $latest !== null && (int) $latest['section_version'] === $sectionVersion ) {
			return null;
		}

		if ( $note === '' ) {
			$log  = Stages::reviewLog( $sectionId );
			$last = $log === [] ? null : $log[ count( $log ) - 1 ];
			if ( $last !== null ) {
				$note = (string) $last['notes'];
			}
		}

		return self::append(
			$artifactId,
			[
				'kind'            => 'section',
				'section_id'      => $sectionId,
				'section_version' => $sectionVersion,
				'title'           => (string) $section->post_title,
				'content'         => (string) $section->post_content,
				'actor'           => $actorId,
				'client'          => self::writerClient( $sectionId ),
				'note'            => substr( $note, 0, 500 ),
			]
		);
	}

	/**
	 * Record an HTML upload. The files themselves are NOT copied — the row
	 * references HtmlFiles' own version number.
	 *
	 * @return int|null The new row's seq.
	 */
	public static function recordHtml( int $artifactId, int $actorId, int $htmlVersion, string $note = '' ): ?int {
		if ( $htmlVersion <= 0 ) {
			return null;
		}

		return self::append(
			$artifactId,
			[
				'kind'        => 'html',
				'html_version' => $htmlVersion,
				'actor'       => $actorId,
				'client'      => null,
				'note'        => substr( $note, 0, 200 ),
			]
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function append( int $artifactId, array $row ): ?int {
		global $wpdb;

		$lockName = 'ab_history_seq_' . $artifactId;
		$acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lockName ) );

		if ( $acquired !== 1 ) {
			return null;
		}

		try {
			$row['seq'] = count( self::rows( $artifactId ) ) + 1;
			$row['at']  = gmdate( 'Y-m-d H:i:s' );
			add_post_meta( $artifactId, self::META, wp_slash( (string) wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) ), false );
			self::prune( $artifactId );

			return (int) $row['seq'];
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lockName ) );
		}
	}

	/** Drop the oldest rows past the cap (append-only history, bounded). */
	private static function prune( int $artifactId ): void {
		$rows = self::rows( $artifactId );
		$excess = count( $rows ) - self::KEEP_ROWS;
		if ( $excess <= 0 ) {
			return;
		}

		foreach ( array_slice( $rows, 0, $excess ) as $row ) {
			delete_post_meta( $artifactId, self::META, wp_slash( (string) wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) ) );
		}
	}

	// ------------------------------------------------------------------
	// Reading (all gated: anyone who may read the artifact may read history)
	// ------------------------------------------------------------------

	/**
	 * Every version, oldest first, WITHOUT the heavy payloads: section
	 * content and HTML file bodies are omitted. Each row carries:
	 * {seq, kind, at, actor, client, note, section_id?, section_version?,
	 * title?, html_version?, current?:bool, approved?:bool, served?:bool}.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function list( int $actorId, int $artifactId ) {
		$denied = self::guardRead( $actorId, $artifactId );
		if ( $denied instanceof \WP_Error ) {
			return $denied;
		}

		$out = [];
		foreach ( self::rows( $artifactId ) as $row ) {
			$out[] = self::decorate( $artifactId, $row, false );
		}

		return $out;
	}

	/**
	 * One full version: everything list() returns plus the section snapshot
	 * content, or the resolved HTML file set (listing with type/size; empty
	 * when the referenced HtmlFiles version has been pruned).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( int $actorId, int $artifactId, int $seq ) {
		$denied = self::guardRead( $actorId, $artifactId );
		if ( $denied instanceof \WP_Error ) {
			return $denied;
		}

		$row = self::rowBySeq( $artifactId, $seq );
		if ( $row === null ) {
			return self::notFound();
		}

		$sectionDenied = self::guardSectionRead( $actorId, $row );
		if ( $sectionDenied instanceof \WP_Error ) {
			return $sectionDenied;
		}

		return self::decorate( $artifactId, $row, true );
	}

	/**
	 * The snapshot a section had at $version (the most recently recorded row
	 * carrying that section_version), or null.
	 *
	 * @return array{section_id:int,section_version:int,title:string,content:string,at:string,actor:int}|null
	 */
	public static function sectionSnapshot( int $artifactId, int $sectionId, int $version ): ?array {
		$found = null;
		foreach ( self::rows( $artifactId ) as $row ) {
			if (
				( $row['kind'] ?? '' ) === 'section'
				&& (int) ( $row['section_id'] ?? 0 ) === $sectionId
				&& (int) ( $row['section_version'] ?? 0 ) === $version
			) {
				$found = $row;
			}
		}

		if ( $found === null ) {
			return null;
		}

		return [
			'section_id'      => $sectionId,
			'section_version' => $version,
			'title'           => (string) ( $found['title'] ?? '' ),
			'content'         => (string) ( $found['content'] ?? '' ),
			'at'              => (string) ( $found['at'] ?? '' ),
			'actor'           => (int) ( $found['actor'] ?? 0 ),
		];
	}

	// ------------------------------------------------------------------
	// Compare
	// ------------------------------------------------------------------

	/**
	 * Compare two versions of the SAME kind:
	 *  - section/section: a line-based text diff of the two snapshots'
	 *    plain text (Text::plainTextFromBlocks(), the same extraction used
	 *    everywhere else), each side bounded to COMPARE_MAX_LINES.
	 *  - html/html: which files were added, removed, or changed (byte
	 *    comparison of the stored file payloads).
	 * Cross-kind compares are refused (422).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function compare( int $actorId, int $artifactId, int $fromSeq, int $toSeq ) {
		$denied = self::guardRead( $actorId, $artifactId );
		if ( $denied instanceof \WP_Error ) {
			return $denied;
		}

		$from = self::rowBySeq( $artifactId, $fromSeq );
		$to   = self::rowBySeq( $artifactId, $toSeq );
		if ( $from === null || $to === null ) {
			return self::notFound();
		}

		if ( ( $from['kind'] ?? '' ) !== ( $to['kind'] ?? '' ) ) {
			return new \WP_Error(
				'ab_version_compare_mismatch',
				__( 'These two versions have different kinds and cannot be compared.', 'agent-brain' ),
				[ 'status' => 422 ]
			);
		}

		$sectionDenied = self::guardSectionRead( $actorId, $from );
		if ( $sectionDenied instanceof \WP_Error ) {
			return $sectionDenied;
		}

		if ( $from['kind'] === 'html' ) {
			return self::compareHtml( $artifactId, $from, $to );
		}

		return self::compareSections( $from, $to );
	}

	/**
	 * @param array<string, mixed> $from
	 * @param array<string, mixed> $to
	 * @return array<string, mixed>
	 */
	private static function compareSections( array $from, array $to ): array {
		$fromLines = self::diffableLines( (string) ( $from['content'] ?? '' ) );
		$toLines   = self::diffableLines( (string) ( $to['content'] ?? '' ) );

		return [
			'kind'           => 'section',
			'section_id'     => (int) ( $from['section_id'] ?? 0 ),
			'from_version'   => (int) ( $from['section_version'] ?? 0 ),
			'to_version'     => (int) ( $to['section_version'] ?? 0 ),
			'truncated'      => count( $fromLines ) >= self::COMPARE_MAX_LINES || count( $toLines ) >= self::COMPARE_MAX_LINES,
			'lines'          => self::lineDiff( $fromLines, $toLines ),
			'identical'      => $fromLines === $toLines,
		];
	}

	/**
	 * @param array<string, mixed> $from
	 * @param array<string, mixed> $to
	 * @return array<string, mixed>
	 */
	private static function compareHtml( int $artifactId, array $from, array $to ): array {
		$fromFiles = self::htmlFiles( $artifactId, (int) ( $from['html_version'] ?? 0 ) );
		$toFiles   = self::htmlFiles( $artifactId, (int) ( $to['html_version'] ?? 0 ) );

		$added   = [];
		$removed = [];
		$changed = [];

		foreach ( $toFiles as $path => $file ) {
			if ( ! isset( $fromFiles[ $path ] ) ) {
				$added[] = $path;
			} elseif ( HtmlFiles::bytes( $fromFiles[ $path ] ) !== HtmlFiles::bytes( $file ) ) {
				$changed[] = $path;
			}
		}
		foreach ( $fromFiles as $path => $file ) {
			if ( ! isset( $toFiles[ $path ] ) ) {
				$removed[] = $path;
			}
		}
		sort( $added );
		sort( $removed );
		sort( $changed );

		return [
			'kind'         => 'html',
			'from_version' => (int) ( $from['html_version'] ?? 0 ),
			'to_version'   => (int) ( $to['html_version'] ?? 0 ),
			'added'        => $added,
			'removed'      => $removed,
			'changed'      => $changed,
			'identical'    => $added === [] && $removed === [] && $changed === [],
		];
	}

	/**
	 * @return array<string, array{type:string,enc:string,data:string,size:int}>
	 */
	private static function htmlFiles( int $artifactId, int $htmlVersion ): array {
		$row = HtmlFiles::version( $artifactId, $htmlVersion );

		return $row !== null ? $row['files'] : [];
	}

	/**
	 * Plain-text lines of a section snapshot, bounded for the LCS diff.
	 *
	 * @return string[]
	 */
	private static function diffableLines( string $content ): array {
		$text = trim( Text::plainTextFromBlocks( $content ) );
		if ( $text === '' ) {
			return [];
		}

		$lines = preg_split( '/\n+/', $text ) ?: [];

		return array_slice( array_map( 'trim', $lines ), 0, self::COMPARE_MAX_LINES );
	}

	/**
	 * Line-based LCS diff. Output entries: {type: 'same'|'added'|'removed',
	 * line: string} — 'added'/'removed' pairs are what changed between from
	 * and to; 'same' lines are unchanged context (kept so a reader can
	 * locate the change). Pure PHP (no Text_Diff dependency) so it runs in
	 * every context, including wp-admin screens where wp-diff.php may not
	 * be loaded.
	 *
	 * @param string[] $fromLines
	 * @param string[] $toLines
	 * @return array<int, array{type:string,line:string}>
	 */
	private static function lineDiff( array $fromLines, array $toLines ): array {
		$n = count( $fromLines );
		$m = count( $toLines );

		// LCS lengths table (classic DP; both sides are bounded).
		$dp = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$dp[ $i ][ $j ] = $fromLines[ $i ] === $toLines[ $j ]
					? $dp[ $i + 1 ][ $j + 1 ] + 1
					: max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
			}
		}

		$out = [];
		$i   = 0;
		$j   = 0;
		while ( $i < $n && $j < $m ) {
			if ( $fromLines[ $i ] === $toLines[ $j ] ) {
				$out[] = [ 'type' => 'same', 'line' => $fromLines[ $i ] ];
				$i++;
				$j++;
			} elseif ( $dp[ $i + 1 ][ $j ] >= $dp[ $i ][ $j + 1 ] ) {
				$out[] = [ 'type' => 'removed', 'line' => $fromLines[ $i ] ];
				$i++;
			} else {
				$out[] = [ 'type' => 'added', 'line' => $toLines[ $j ] ];
				$j++;
			}
		}
		while ( $i < $n ) {
			$out[] = [ 'type' => 'removed', 'line' => $fromLines[ $i ] ];
			$i++;
		}
		while ( $j < $m ) {
			$out[] = [ 'type' => 'added', 'line' => $toLines[ $j ] ];
			$j++;
		}

		return $out;
	}

	// ------------------------------------------------------------------
	// Restore
	// ------------------------------------------------------------------

	/**
	 * Make an old version the new latest one — by creating a NEW version
	 * through the same path an ordinary write takes, never by overwriting:
	 *  - section rows go through ArtifactService::restoreSectionVersion()
	 *    (CAS version bump, section back to `submitted` so it must be
	 *    approved again, audit event);
	 *  - html rows go through HtmlFiles::restore() (a new HtmlFiles version
	 *    with the old file set; the approved/served version is untouched).
	 * Both enforce the approval-role gate themselves (curator, lead, or a
	 * manager-agent holding the #252 scoped approval grant; HTML restore
	 * additionally refuses a pure agent, exactly like HtmlFiles::approve()).
	 *
	 * @return array{seq:int, result:array<string, mixed>}|\WP_Error
	 */
	public static function restore( int $actorId, int $artifactId, int $seq, string $note = '' ) {
		$post = get_post( $artifactId );
		if ( ! $post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return self::notFound();
		}

		$row = self::rowBySeq( $artifactId, $seq );
		if ( $row === null ) {
			return self::notFound();
		}

		if ( $row['kind'] === 'html' ) {
			$result = HtmlFiles::restore( $artifactId, $actorId, (int) $row['html_version'], $note );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}

			return [ 'seq' => (int) $result['seq'], 'result' => $result ];
		}

		$sectionId = (int) $row['section_id'];
		$section   = get_post( $sectionId );
		if ( ! $section || $section->post_type !== Cpts::SECTION_POST_TYPE || (int) $section->post_parent !== $artifactId ) {
			return self::notFound();
		}

		$result = ( new ArtifactService() )->restoreSectionVersion( $actorId, $sectionId, (string) $row['content'], (int) $row['section_version'], $note );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return [ 'seq' => (int) $result['seq'], 'result' => $result ];
	}

	// ------------------------------------------------------------------
	// Row internals
	// ------------------------------------------------------------------

	/**
	 * Raw decoded rows, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( int $artifactId ): array {
		$out = [];
		foreach ( get_post_meta( $artifactId, self::META, false ) as $raw ) {
			$row = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $row ) && isset( $row['seq'], $row['kind'] ) ) {
				$out[] = $row;
			}
		}
		usort(
			$out,
			static fn ( array $a, array $b ): int => (int) $a['seq'] <=> (int) $b['seq']
		);

		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function rowBySeq( int $artifactId, int $seq ): ?array {
		foreach ( self::rows( $artifactId ) as $row ) {
			if ( (int) $row['seq'] === $seq ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function latestSectionRow( int $artifactId, int $sectionId ): ?array {
		$found = null;
		foreach ( self::rows( $artifactId ) as $row ) {
			if ( ( $row['kind'] ?? '' ) === 'section' && (int) ( $row['section_id'] ?? 0 ) === $sectionId ) {
				$found = $row;
			}
		}

		return $found;
	}

	private static function sectionVersion( int $sectionId ): int {
		$raw = get_post_meta( $sectionId, Cpts::META_VERSION, true );

		return $raw === '' ? 1 : (int) $raw;
	}

	private static function writerClient( int $sectionId ): ?string {
		$raw = get_post_meta( $sectionId, self::META_LAST_WRITER_CLIENT, true );

		return is_string( $raw ) && $raw !== '' ? $raw : null;
	}

	/**
	 * Add the read-facing fields: the viewer-facing identity of the row plus
	 * live status flags (is this the section's current version / the approved
	 * or served HTML version right now?).
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function decorate( int $artifactId, array $row, bool $full ): array {
		$out = [
			'seq'     => (int) $row['seq'],
			'kind'    => (string) $row['kind'],
			'at'      => (string) ( $row['at'] ?? '' ),
			'actor'   => (int) ( $row['actor'] ?? 0 ),
			'client'  => isset( $row['client'] ) && is_string( $row['client'] ) ? $row['client'] : null,
			'note'    => (string) ( $row['note'] ?? '' ),
		];

		if ( $row['kind'] === 'section' ) {
			$sectionId          = (int) ( $row['section_id'] ?? 0 );
			$sectionVersion     = (int) ( $row['section_version'] ?? 0 );
			$out['section_id']      = $sectionId;
			$out['section_version'] = $sectionVersion;
			$out['title']           = (string) ( $row['title'] ?? '' );
			$out['current']         = self::sectionVersion( $sectionId ) === $sectionVersion && Stages::sectionState( $sectionId ) !== Cpts::SECTION_STATE_DRAFTING;
			$out['approved']        = Stages::sectionState( $sectionId ) === Cpts::SECTION_STATE_APPROVED && $out['current'];
			if ( $full ) {
				$out['content'] = (string) ( $row['content'] ?? '' );
			}
		} else {
			$htmlVersion        = (int) ( $row['html_version'] ?? 0 );
			$approvedNumber     = HtmlFiles::approvedNumber( $artifactId );
			$out['html_version'] = $htmlVersion;
			$out['approved']     = $approvedNumber === $htmlVersion && $approvedNumber > 0;
			$out['served']       = $out['approved'] || ( HtmlFiles::mode( $artifactId ) === HtmlFiles::MODE_LATEST && HtmlFiles::latestNumber( $artifactId ) === $htmlVersion );
			$out['current']      = HtmlFiles::latestNumber( $artifactId ) === $htmlVersion;
			if ( $full ) {
				$files = self::htmlFiles( $artifactId, $htmlVersion );
				$out['files']   = HtmlFiles::listing( $files );
				$out['pruned']  = $htmlVersion > 0 && $files === [] && ! self::htmlVersionExists( $artifactId, $htmlVersion );
			}
		}

		return $out;
	}

	private static function htmlVersionExists( int $artifactId, int $htmlVersion ): bool {
		return HtmlFiles::version( $artifactId, $htmlVersion ) !== null;
	}

	// ------------------------------------------------------------------
	// Guards
	// ------------------------------------------------------------------

	/**
	 * "Anyone who may read the artifact may view the history" — the artifact
	 * read_post meta-cap (curator, lead, any section owner, or a #252
	 * manager-grant holder), identical on wp-admin and MCP.
	 *
	 * @return true|\WP_Error
	 */
	private static function guardRead( int $actorId, int $artifactId ) {
		$post = get_post( $artifactId );
		if ( ! $post || $post->post_type !== Cpts::ARTIFACT_POST_TYPE ) {
			return self::notFound();
		}

		if ( ! user_can( $actorId, 'read_post', $artifactId ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * A section row's CONTENT additionally requires the per-section read
	 * gate (owner, curator, lead, or #252 grant) — the artifact-level read
	 * gate alone would hand one section owner's snapshot content to every
	 * other section owner of the artifact, which CrossAgentLeakTest pins as
	 * a leak. Applies to full reads (get) and compares; the metadata-only
	 * list stays at the artifact read gate.
	 *
	 * @param array<string, mixed> $row
	 * @return true|\WP_Error
	 */
	private static function guardSectionRead( int $actorId, array $row ) {
		if ( ( $row['kind'] ?? '' ) !== 'section' ) {
			return true;
		}

		if ( ! ObjectAccess::canReadSection( $actorId, (int) ( $row['section_id'] ?? 0 ) ) ) {
			return new \WP_Error( 'ab_forbidden', __( 'You do not have permission to do this.', 'agent-brain' ), [ 'status' => 403 ] );
		}

		return true;
	}

	private static function notFound(): \WP_Error {
		return new \WP_Error( 'ab_version_not_found', __( 'That version does not exist.', 'agent-brain' ), [ 'status' => 404 ] );
	}

	/**
	 * Whether the actor holds approval-grade rights on ANY artifact
	 * (curator, manager, lead of at least one artifact, or holder of the #252
	 * approval grant). Discovery-time approximation for the restore ability,
	 * which cannot be evaluated against a concrete artifact without an id.
	 */
	public static function canRestoreAny( int $actorId ): bool {
		if ( $actorId <= 0 ) {
			return false;
		}

		if ( ObjectAccess::userIsCurator( $actorId ) || user_can( $actorId, Capabilities::CAP_MANAGE ) ) {
			return true;
		}

		if ( ( new \AgentBrain\Modules\Identity\ApprovalGrants() )->hasGrant( $actorId ) ) {
			return true;
		}

		$led = get_posts(
			[
				'post_type'      => Cpts::ARTIFACT_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => Cpts::META_LEAD_AGENT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $actorId, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		return $led !== [];
	}

	/**
	 * Discovery-time approximation for the read abilities: may this user read
	 * ANY artifact's history (curator/manager, lead of something, or owner of
	 * some section)?
	 */
	public static function canReadAny( int $actorId ): bool {
		if ( $actorId <= 0 ) {
			return false;
		}

		if ( self::canRestoreAny( $actorId ) ) {
			return true;
		}

		$owned = get_posts(
			[
				'post_type'      => Cpts::SECTION_POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => Cpts::META_SECTION_OWNER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $actorId, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		return $owned !== [];
	}
}
